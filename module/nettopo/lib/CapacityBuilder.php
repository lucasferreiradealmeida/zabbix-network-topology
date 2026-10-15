<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Capacity planning: groups the interfaces discovered by "Template Capacity Planning" by circuit
** (item tag "circuit"), completes each circuit with the shared links of its devices (uplinks, trunks),
** using LLDP/CDP to know which way is "up", and points out the bottleneck segment.
**
** Segments, from the client to the core:
**   acesso        last mile: CPE ports and the distribution/core ports dedicated to the circuit;
**   distribuicao  aggregation: shared uplinks of the POP and of the regional aggregation towards the core,
**                 and the core interface at the other end of them (core LAN);
**   backbone      core: the core trunks.
**
** Logical structure of the path (hops): CLIENT - POP (client port, uplink) - regional aggregation - CORE (LAN
** interface, trunks).
**
** The bottleneck is chosen by four criteria (planning document): highest relative utilization, lowest free
** capacity, shortest time to saturation and highest impact on the chain (circuits sharing the segment).
** The segment that is worst in most criteria wins; ties go to the highest utilization, then to the segment
** closest to the client.
**
** Analysis: situation of each circuit (bands 60/80/90 %, role limits and forecast),
** monthly growth, the capacity recommended to stay at the target until the end of the planning horizon, and the
** simulation of an upgrade on every segment of the path.
**/

namespace Modules\NetTopo\Lib;

use API,
	CSettingsHelper,
	Manager;

class CapacityBuilder {

	public const SEGMENTS = ['acesso', 'distribuicao', 'backbone'];

	public const ROLES = ['cpe', 'distribuicao', 'backbone'];

	/** Criteria of the bottleneck, in order of importance. */
	public const CRITERIA = ['util', 'free', 'days', 'impact'];

	/** Hops of the path, from the client to the core. */
	public const HOPS = ['cpe', 'pop_client', 'core_client', 'pop_uplink', 'agg_uplink', 'core_lan', 'core_trunk'];

	private const ROLE_LEVEL = ['cpe' => 0, 'distribuicao' => 1, 'backbone' => 2];

	private const ROLE_LABELS = [
		'backbone' => 'backbone',
		'distribuição' => 'distribuicao',
		'distribuicao' => 'distribuicao',
		'cpe' => 'cpe'
	];

	/** Item key (without parameters) => metric. */
	public const KEYS = [
		'capacity.if.in' => 'in',
		'capacity.if.out' => 'out',
		'capacity.if.speed' => 'speed',
		'capacity.if.status' => 'status',
		'capacity.if.bw' => 'bw',
		'capacity.if.util' => 'util',
		'capacity.if.util.p95' => 'p95',
		'capacity.if.util.peak' => 'peak',
		'capacity.if.util.forecast' => 'forecast',
		'capacity.if.days_left' => 'days',
		'capacity.if.util.avg' => 'avg',
		'capacity.if.growth' => 'growth'
	];

	/** Metrics needed for the lists; the detail of one circuit reads all of them. */
	private const LIST_METRICS = ['status', 'speed', 'bw', 'util', 'p95', 'days', 'avg', 'growth'];

	/** "days" values: 3650 and above = no upward trend. */
	public const DAYS_NONE = 3650;

	/** Utilization bands of the planning document (%): logical capacity map and situation of the circuits. */
	public const BAND_CRITICAL = 90;
	public const BAND_HIGH = 80;
	public const BAND_MEDIUM = 60;

	/** Situation of a circuit, from the worst. */
	public const SITUATIONS = ['critical', 'high', 'medium', 'low', 'nodata'];

	/** Standard capacities offered by the upgrade recommendation and the simulation (bit/s). */
	public const STEPS = [10e6, 20e6, 50e6, 100e6, 200e6, 300e6, 500e6, 1e9, 2e9, 2.5e9, 5e9, 10e9, 20e9, 25e9, 40e9,
		50e9, 100e9, 200e9, 400e9, 800e9
	];

	/**
	 * Utilization wanted at the end of the planning horizon (%): circuit ports ("acesso", any device) and shared
	 * links of each segment. Defaults = attention limits of the role templates.
	 */
	public const DEFAULT_TARGETS = ['acesso' => 80, 'distribuicao' => 70, 'backbone' => 60];

	/** Planning horizon of the upgrade recommendation (days). */
	public const DEFAULT_HORIZON = 180;

	/** Macro with the contracted bandwidth of a circuit (context = circuit ID), in Mbps. */
	public const CONTRACT_MACRO = '{$CAPACITY.BW.MBPS}';

	private array $options;

	/** hostid => ['hostid', 'name', 'role'] for every host with the capacity template. */
	private array $hosts = [];

	/** interface id (hostid:snmpindex) => interface. */
	private array $interfaces = [];

	/** hostid => normalized port name => interface id. */
	private array $by_port = [];

	/** hostid => list of links [port, remote hostid|null, remote port, remote name]. */
	private array $links = [];

	/** interface id => number of circuits that use it (impact on the chain). */
	private array $usage = [];

	/** circuit ID => contracted bandwidth in bit/s. */
	private array $contracts = [];

	private array $warnings = [];

	/** Planning settings: ['targets' => segment => %, 'horizon' => days]. */
	private array $planning;

	/**
	 * @param array $options
	 *        string[] groupids   Show circuits that touch hosts of these groups (subgroups included).
	 *        string[] hostids    ... or these hosts.
	 *        string   search     Part of the circuit ID.
	 *        string   circuit    Exact circuit ID (detail of one circuit: all metrics are read).
	 *        int      max_hosts  Hard limit of hosts with the capacity template.
	 *        array    planning   Module settings "capacity" (targets, horizon), see planning().
	 *        float    simulate   Detail of one circuit: capacity to simulate (bit/s; 0 = the recommended one).
	 */
	public function __construct(array $options) {
		$this->options = $options + [
			'groupids' => [],
			'hostids' => [],
			'search' => '',
			'circuit' => '',
			'max_hosts' => 2000,
			'planning' => [],
			'simulate' => 0
		];

		$this->planning = self::planning($this->options['planning']);
	}

	/**
	 * Planning settings with defaults and limits: targets 10..100 % by segment, horizon 1..1095 days.
	 */
	public static function planning($settings): array {
		$settings = is_array($settings) ? $settings : [];
		$targets = [];

		foreach (self::DEFAULT_TARGETS as $segment => $default) {
			$value = $settings['targets'][$segment] ?? $default;
			$targets[$segment] = is_numeric($value) ? max(10, min(100, (int) $value)) : $default;
		}

		$horizon = $settings['horizon'] ?? self::DEFAULT_HORIZON;

		return [
			'targets' => $targets,
			'horizon' => is_numeric($horizon) ? max(1, min(1095, (int) $horizon)) : self::DEFAULT_HORIZON
		];
	}

	public function build(): array {
		$this->loadHosts();

		if (!$this->hosts) {
			return $this->result([], []);
		}

		$this->loadInterfaces();
		$this->loadLinks();

		// Every circuit is traced, even for the detail of one: the impact of a segment is the number of
		// circuits that go through it.
		$traced = [];

		foreach ($this->circuitIds() as $cid) {
			$traced[$cid] = $this->tracePath($cid);

			foreach (array_keys($traced[$cid]['interfaces']) as $id) {
				$this->usage[$id] = ($this->usage[$id] ?? 0) + 1;
			}
		}

		$in_filter = $this->hostsInFilter();
		$circuits = [];

		foreach ($traced as $cid => $circuit) {
			$cid = (string) $cid;

			if ($this->options['circuit'] !== '' && $cid !== (string) $this->options['circuit']) {
				continue;
			}

			if ($this->options['search'] !== '' && mb_stripos($cid, $this->options['search']) === false) {
				continue;
			}

			if ($in_filter !== null && !array_intersect(array_keys($circuit['hostids']), $in_filter)) {
				continue;
			}

			$circuits[$cid] = $circuit;
		}

		// Interfaces listed in the "Interfaces" view: the ones of the hosts in the filter (none for one circuit).
		$listed = [];

		if ($this->options['circuit'] === '') {
			$in_filter_set = $in_filter !== null ? array_flip($in_filter) : null;

			foreach ($this->interfaces as $id => $interface) {
				if ($in_filter_set === null || array_key_exists($interface['hostid'], $in_filter_set)) {
					$listed[$id] = true;
				}
			}
		}

		$needed = $listed;

		foreach ($circuits as $circuit) {
			foreach ($circuit['interfaces'] as $id => $segment) {
				$needed[$id] = true;
			}
		}

		$this->loadValues(array_keys($needed),
			$this->options['circuit'] !== '' ? array_values(self::KEYS) : self::LIST_METRICS
		);
		$this->loadProblems(array_keys($needed));
		$this->loadContracts(array_keys($circuits), $circuits);

		if ($this->options['circuit'] === '') {
			$this->checkHostMacros();
		}

		foreach ($circuits as $cid => &$circuit) {
			$this->evaluate($circuit);
		}
		unset($circuit);

		// Detail of one circuit: upgrade simulation (the capacity asked for, the recommended one or the next one).
		if ($this->options['circuit'] !== '') {
			foreach ($circuits as &$circuit) {
				$circuit['simulation'] = $this->simulation($circuit, (float) ($this->options['simulate'] ?? 0));
			}
			unset($circuit);
		}

		return $this->result($circuits, array_keys($listed));
	}

	/**
	 * Upgrade simulation of a circuit: its capacity today (contracted bandwidth, else its smallest own port), the
	 * standard capacities above it and the effect of the chosen one on each segment.
	 */
	private function simulation(array $circuit, float $wanted): ?array {
		$current = $circuit['contract'];

		if ($current === null) {
			foreach ($circuit['interfaces'] as $id => $segment) {
				$interface = $this->interfaces[$id];
				$bw = $interface['values']['bw'] ?? null;

				if (!$interface['shared'] && $bw !== null && $bw > 0) {
					$current = min($current ?? INF, $bw);
				}
			}
		}

		if ($current === null || !is_finite($current)) {
			return null;
		}

		$choices = self::stepsAbove($current);

		if (!$choices) {
			return null;
		}

		$upgrade = $circuit['upgrade'];
		$capacity = $wanted > $current * 1.001
			? $wanted
			: (($upgrade !== null && !$upgrade['shared'] && $upgrade['to'] !== null) ? $upgrade['to'] : $choices[0]);

		if (!in_array($capacity, $choices)) {
			$choices[] = $capacity;
			sort($choices);
		}

		return [
			'current' => $current,
			'capacity' => $capacity,
			'choices' => $choices,
			'segments' => $this->simulate($circuit, $capacity)
		];
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Loading
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Hosts with the capacity template and their role (item "capacity.segment").
	 */
	private function loadHosts(): void {
		$max = max(1, (int) $this->options['max_hosts']);

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'value_type'],
			'selectHosts' => ['hostid', 'name'],
			'filter' => ['key_' => 'capacity.segment'],
			'monitored' => true,
			'limit' => $max + 1
		]);

		if (count($items) > $max) {
			$items = array_slice($items, 0, $max);
			$this->warnings[] = I18n::t('Only the first %1$s hosts are shown. Narrow down the filter.', $max);
		}

		$values = self::lastValues($items);

		foreach ($items as $item) {
			$label = mb_strtolower(trim((string) ($values[$item['itemid']] ?? '')));

			$this->hosts[(string) $item['hostid']] = [
				'hostid' => (string) $item['hostid'],
				'name' => $item['hosts'][0]['name'] ?? $item['hostid'],
				'role' => self::ROLE_LABELS[$label] ?? ''
			];
		}
	}

	/**
	 * One entry per discovered interface (its utilization item carries the tags).
	 */
	private function loadInterfaces(): void {
		$use_discovery_data = version_compare(ZABBIX_VERSION, '7.4', '>=');
		$lost_status = defined('ZBX_LLD_STATUS_LOST') ? ZBX_LLD_STATUS_LOST : 1;

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_'],
			'hostids' => array_keys($this->hosts),
			'search' => ['key_' => 'capacity.if.util['],
			'startSearch' => true,
			'selectTags' => ['tag', 'value'],
			($use_discovery_data ? 'selectDiscoveryData' : 'selectItemDiscovery') => ['status'],
			'monitored' => true
		]);

		foreach ($items as $item) {
			if (!preg_match('/^capacity\.if\.util\[(\d+)\]$/', $item['key_'], $m)) {
				continue;
			}

			$discovery = $item['discoveryData'] ?? $item['itemDiscovery'] ?? [];

			// The interface lost its CID / keyword: it is no longer part of any circuit.
			if (($discovery['status'] ?? 0) == $lost_status) {
				continue;
			}

			$tags = [];

			foreach ($item['tags'] as $tag) {
				$tags[$tag['tag']] = $tag['value'];
			}

			$hostid = (string) $item['hostid'];
			$id = $hostid.':'.$m[1];
			$name = $tags['interface'] ?? ('if'.$m[1]);
			$role = $this->hosts[$hostid]['role'];

			if ($role === '' && array_key_exists($tags['segment'] ?? '', self::ROLE_LEVEL)) {
				$role = $tags['segment'];
				$this->hosts[$hostid]['role'] = $role;
			}

			$this->interfaces[$id] = [
				'id' => $id,
				'hostid' => $hostid,
				'snmpindex' => $m[1],
				'name' => $name,
				'cids' => self::splitIds($tags['circuit'] ?? ''),
				'cid_source' => $tags['cid_source'] ?? '',
				// Description of the interface (templates 1.3 and newer): names the client/site.
				'alias' => $tags['alias'] ?? '',
				'shared' => ($tags['link'] ?? '') === 'compartilhado',
				'items' => [],
				'values' => [],
				'errors' => [],
				'severity' => -1,
				'problems' => [],
				'forecast_alert' => false
			];

			$this->by_port[$hostid][PortName::normalize($name)] = $id;
		}
	}

	private function loadLinks(): void {
		$links = (new TopologyBuilder([
			'hostids' => array_keys($this->hosts),
			'sources' => ['lldp', 'cdp', 'tags'],
			'traffic' => false,
			'max_hosts' => max(1, (int) $this->options['max_hosts'])
		]))->buildLinks();

		foreach ($links as $link) {
			if ($link['lost'] || $link['source_hostid'] === null) {
				continue;
			}

			$this->links[$link['source_hostid']][] = [
				'port' => $link['source_port'],
				'remote_hostid' => $link['target_hostid'],
				'remote_port' => $link['target_port'],
				'remote_name' => $link['target_name']
			];

			if ($link['target_hostid'] !== null) {
				$this->links[$link['target_hostid']][] = [
					'port' => $link['target_port'],
					'remote_hostid' => $link['source_hostid'],
					'remote_port' => $link['source_port'],
					'remote_name' => $this->hosts[$link['source_hostid']]['name'] ?? ''
				];
			}
		}
	}

	/**
	 * @return string[]|null  hostids selected by the filter, null when there is no host filter.
	 */
	private function hostsInFilter(): ?array {
		if (!$this->options['groupids'] && !$this->options['hostids']) {
			return null;
		}

		$hostids = array_map('strval', $this->options['hostids']);

		if ($this->options['groupids']) {
			$hosts = API::Host()->get([
				'output' => [],
				'groupids' => TopologyBuilder::expandGroups($this->options['groupids']),
				'hostids' => array_keys($this->hosts),
				'preservekeys' => true
			]);
			$hostids = array_merge($hostids, array_map('strval', array_keys($hosts)));
		}

		return array_values(array_unique($hostids));
	}

	/**
	 * Last values (and errors) of the requested metrics of the interfaces.
	 */
	private function loadValues(array $interface_ids, array $metrics): void {
		$keys_by_host = [];

		foreach ($interface_ids as $id) {
			$interface = $this->interfaces[$id];

			foreach (self::KEYS as $key => $metric) {
				if (in_array($metric, $metrics, true)) {
					$keys_by_host[$interface['hostid']][$key.'['.$interface['snmpindex'].']'] = [$id, $metric];
				}
			}
		}

		if (!$keys_by_host) {
			return;
		}

		$all_keys = [];

		foreach ($keys_by_host as $keys) {
			$all_keys += $keys;
		}

		$items = [];

		foreach (array_chunk(array_keys($all_keys), 500) as $chunk) {
			$items = array_merge($items, API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'value_type', 'state', 'error'],
				'hostids' => array_keys($keys_by_host),
				'filter' => ['key_' => $chunk],
				'monitored' => true
			]));
		}

		$values = self::lastValues($items);

		foreach ($items as $item) {
			$hostid = (string) $item['hostid'];

			if (!isset($keys_by_host[$hostid][$item['key_']])) {
				continue;
			}

			[$id, $metric] = $keys_by_host[$hostid][$item['key_']];

			$this->interfaces[$id]['items'][$metric] = $item['itemid'];

			if ($item['state'] == ITEM_STATE_NOTSUPPORTED && $item['error'] !== '') {
				$this->interfaces[$id]['errors'][$metric] = $item['error'];
			}

			if (array_key_exists($item['itemid'], $values) && is_numeric($values[$item['itemid']])) {
				$this->interfaces[$id]['values'][$metric] = (float) $values[$item['itemid']];
			}
		}
	}

	/**
	 * Active capacity problems (trigger tag component=capacity) by interface.
	 */
	private function loadProblems(array $interface_ids): void {
		$hostids = [];

		foreach ($interface_ids as $id) {
			$hostids[$this->interfaces[$id]['hostid']] = true;
		}

		if (!$hostids) {
			return;
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity', 'name', 'clock'],
			'hostids' => array_keys($hostids),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'tags' => [['tag' => 'component', 'value' => 'capacity', 'operator' => TAG_OPERATOR_EQUAL]],
			'suppressed' => false,
			'selectTags' => ['tag', 'value']
		]);

		if (!$problems) {
			return;
		}

		$triggers = API::Trigger()->get([
			'output' => [],
			'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
			'selectHosts' => ['hostid'],
			'preservekeys' => true
		]);

		foreach ($problems as $problem) {
			$port = '';
			$is_forecast = false;

			foreach ($problem['tags'] as $tag) {
				if ($tag['tag'] === 'interface') {
					$port = PortName::normalize($tag['value']);
				}
				elseif ($tag['tag'] === 'capacity' && $tag['value'] === 'forecast') {
					$is_forecast = true;
				}
			}

			foreach ($triggers[$problem['objectid']]['hosts'] ?? [] as $host) {
				$id = $this->by_port[(string) $host['hostid']][$port] ?? null;

				if ($id === null) {
					continue;
				}

				$this->interfaces[$id]['problems'][] = [
					'eventid' => $problem['eventid'],
					'name' => $problem['name'],
					'severity' => (int) $problem['severity'],
					'clock' => (int) $problem['clock']
				];

				if ($is_forecast) {
					$this->interfaces[$id]['forecast_alert'] = true;
				}
				else {
					// The forecast is information, not a capacity problem of the interface.
					$this->interfaces[$id]['severity'] = max($this->interfaces[$id]['severity'], (int) $problem['severity']);
				}
			}
		}
	}

	/**
	 * Contracted bandwidth of the circuits: macro {$CAPACITY.BW.MBPS:"<circuit ID>"} on a host of the circuit or
	 * global (the host one wins, like in Zabbix).
	 */
	private function loadContracts(array $cids, array $circuits): void {
		if (!$cids) {
			return;
		}

		$wanted = array_flip(array_map('strval', $cids));
		$search = ['macro' => substr(self::CONTRACT_MACRO, 0, -1).':'];
		$global = [];
		$by_host = [];

		foreach (API::UserMacro()->get(['output' => ['macro', 'value'], 'globalmacro' => true, 'search' => $search])
				as $macro) {
			$cid = self::macroContext($macro['macro']);

			if ($cid !== null && array_key_exists($cid, $wanted) && is_numeric($macro['value'] ?? null)) {
				$global[$cid] = (float) $macro['value'];
			}
		}

		$hostids = [];

		foreach ($circuits as $circuit) {
			$hostids += $circuit['hostids'];
		}

		if ($hostids) {
			foreach (API::UserMacro()->get(['output' => ['macro', 'value', 'hostid'], 'hostids' => array_keys($hostids),
					'search' => $search]) as $macro) {
				$cid = self::macroContext($macro['macro']);

				if ($cid !== null && array_key_exists($cid, $wanted) && is_numeric($macro['value'] ?? null)) {
					$by_host[$cid][(string) $macro['hostid']] = (float) $macro['value'];
				}
			}
		}

		foreach ($circuits as $cid => $circuit) {
			$cid = (string) $cid;
			$mbps = null;

			// A host of the circuit with its own value; the CPE first, like the dedicated ports that use it.
			$hosts = array_keys($circuit['hostids']);
			usort($hosts, fn($a, $b) => ($this->level((string) $a) ?? 9) <=> ($this->level((string) $b) ?? 9));

			foreach ($hosts as $hostid) {
				if (isset($by_host[$cid][(string) $hostid])) {
					$mbps = $by_host[$cid][(string) $hostid];
					break;
				}
			}

			$mbps = $mbps ?? ($global[$cid] ?? null);

			if ($mbps !== null && $mbps > 0) {
				$this->contracts[$cid] = $mbps * 1000000;
			}
		}
	}

	/**
	 * Hosts with the macro {$CIRCUIT_ID} whose discovery did not give the circuit to any interface: usually
	 * {$CIRCUIT_ID.IF} is empty or names an interface that does not exist.
	 */
	private function checkHostMacros(): void {
		$macros = API::UserMacro()->get([
			'output' => ['hostid', 'value'],
			'hostids' => array_keys($this->hosts),
			'filter' => ['macro' => '{$CIRCUIT_ID}']
		]);

		$with_macro = [];

		foreach ($this->interfaces as $interface) {
			if ($interface['cid_source'] === 'macro') {
				$with_macro[$interface['hostid']] = true;
			}
		}

		foreach ($macros as $macro) {
			$hostid = (string) $macro['hostid'];
			$value = trim((string) ($macro['value'] ?? ''));

			if ($value !== '' && self::splitIds($value) && !array_key_exists($hostid, $with_macro)
					&& array_key_exists($hostid, $this->hosts)) {
				$this->warnings[] = I18n::t('%1$s has {$CIRCUIT_ID}=%2$s but no interface received it: set {$CIRCUIT_ID.IF} with the interface name (the discovery runs every {$CAPACITY.LLD.INTERVAL}).',
					$this->hosts[$hostid]['name'], $value
				);
			}
		}
	}

	/**
	 * Circuit ID in the context of {$CAPACITY.BW.MBPS:"NET-RJ-0001"} (regex contexts are ignored).
	 */
	private static function macroContext(string $macro): ?string {
		if (!preg_match('/^\{\$CAPACITY\.BW\.MBPS:\s*(?!regex:)"?(.*?)"?\s*\}$/', $macro, $m)) {
			return null;
		}

		$cid = strtoupper(trim(stripslashes($m[1])));

		return self::isValidId($cid) ? $cid : null;
	}

	/**
	 * @return array  itemid => last value
	 */
	private static function lastValues(array $items): array {
		if (!$items) {
			return [];
		}

		$request = [];

		foreach ($items as $item) {
			$request[] = ['itemid' => $item['itemid'], 'value_type' => $item['value_type']];
		}

		$period = timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		$values = [];

		foreach (Manager::History()->getLastValues($request, 1, $period) as $itemid => $rows) {
			if ($rows) {
				$values[$itemid] = $rows[0]['value'];
			}
		}

		return $values;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Circuit path
	 * ---------------------------------------------------------------------------------------------
	 */

	private function circuitIds(): array {
		$cids = [];

		foreach ($this->interfaces as $interface) {
			foreach ($interface['cids'] as $cid) {
				$cids[$cid] = true;
			}
		}

		// Keys made only of digits become integers in PHP arrays: back to strings.
		$cids = array_map('strval', array_keys($cids));
		natcasesort($cids);

		return array_values($cids);
	}

	private function role(string $hostid): string {
		return $this->hosts[$hostid]['role'] ?? '';
	}

	private function level(?string $hostid): ?int {
		return $hostid !== null ? (self::ROLE_LEVEL[$this->role($hostid)] ?? null) : null;
	}

	/**
	 * Links of a port of a host.
	 */
	private function linksOf(string $hostid, string $port): array {
		$result = [];

		foreach ($this->links[$hostid] ?? [] as $link) {
			if (PortName::equals($link['port'], $port)) {
				$result[] = $link;
			}
		}

		return $result;
	}

	/**
	 * Interfaces of the circuit (dedicated, plus shared links chosen by direction), their segment and hop.
	 */
	private function tracePath(string $cid): array {
		$circuit = [
			'cid' => $cid,
			'hostids' => [],
			'interfaces' => [],
			'hops' => [],
			'peers' => [],
			'suggestions' => [],
			'endpoints' => []
		];

		// 1) Interfaces with the circuit ID.
		foreach ($this->interfaces as $id => $interface) {
			if (in_array($cid, $interface['cids'], true)) {
				$circuit['hostids'][$interface['hostid']] = ['inferred' => false];
				$this->addDedicated($circuit, $id);
			}
		}

		// 2) The other end of the circuit's own links, seen by LLDP/CDP.
		foreach (array_keys($circuit['interfaces']) as $id) {
			$interface = $this->interfaces[$id];

			if ($interface['shared']) {
				continue;
			}

			foreach ($this->linksOf($interface['hostid'], $interface['name']) as $link) {
				$remote = $link['remote_hostid'];

				if ($remote === null || !array_key_exists($remote, $this->hosts)) {
					// Client device that is not monitored (or without the capacity template). Seen from a CPE port,
					// the neighbor is the provider side, not the client.
					if ($link['remote_name'] !== '' && $this->role($interface['hostid']) !== 'cpe') {
						$circuit['endpoints'][$link['remote_name']] = [
							'name' => $link['remote_name'],
							'port' => $link['remote_port'],
							'hostid' => $remote,
							'via' => $this->hosts[$interface['hostid']]['name'].' '.$interface['name']
						];
					}

					continue;
				}

				$remote_id = $this->by_port[$remote][PortName::normalize($link['remote_port'])] ?? null;
				$remote_tagged = $remote_id !== null && in_array($cid, $this->interfaces[$remote_id]['cids'], true);

				if (!$remote_tagged) {
					$circuit['suggestions'][] = I18n::t('%1$s %2$s is at the other end of %3$s %4$s (LLDP/CDP) and has no "CID:%5$s" in its description.',
						$this->hosts[$remote]['name'], $link['remote_port'], $this->hosts[$interface['hostid']]['name'],
						$interface['name'], $cid
					);
				}

				if (!array_key_exists($remote, $circuit['hostids'])) {
					$circuit['hostids'][$remote] = ['inferred' => true];
				}

				if ($remote_id !== null && !array_key_exists($remote_id, $circuit['interfaces'])
						&& !$this->interfaces[$remote_id]['shared']) {
					$this->addDedicated($circuit, $remote_id);
				}
				elseif ($remote_id === null) {
					// The other end is known by LLDP/CDP but its port is not measured: still a point of the path.
					$role = $this->role($remote);
					$circuit['peers'][] = [
						'hop' => $role === 'cpe' ? 'cpe' : ($role === 'backbone' ? 'core_client' : 'pop_client'),
						'host' => $this->hosts[$remote]['name'],
						'port' => $link['remote_port'],
						'via' => $id
					];
				}
			}
		}

		// 3) Shared links, from the client to the core: distribution uplinks, then core trunks.
		$this->addUplinks($circuit);
		$this->addTrunks($circuit);

		return $circuit;
	}

	private function addDedicated(array &$circuit, string $id): void {
		$role = $this->role($this->interfaces[$id]['hostid']);

		$circuit['interfaces'][$id] = $role === 'backbone' ? 'backbone' : 'acesso';
		$circuit['hops'][$id] = $role === 'cpe' ? 'cpe' : ($role === 'backbone' ? 'core_client' : 'pop_client');
	}

	/**
	 * Distribution devices: shared interfaces towards the core (by LLDP/CDP). When a device has no link to the
	 * core, its links to other distribution devices are followed (metro chains and rings), and when the
	 * direction is unknown all its shared interfaces count. The first devices are the POPs of the circuit; the
	 * ones reached through them are the regional aggregation. The core interface at the other end of an uplink
	 * (core LAN) belongs to the same link.
	 */
	private function addUplinks(array &$circuit): void {
		$queue = [];
		$visited = [];
		$pops = [];

		foreach (array_keys($circuit['hostids']) as $hostid) {
			if ($this->role((string) $hostid) === 'distribuicao') {
				$queue[] = (string) $hostid;
				$pops[(string) $hostid] = true;
			}
		}

		while ($queue && count($visited) < 50) {
			$hostid = array_shift($queue);

			if (array_key_exists($hostid, $visited)) {
				continue;
			}

			$visited[$hostid] = true;

			$up = [];
			$side = [];
			$unknown = [];

			foreach ($this->sharedInterfaces($hostid) as $id) {
				$known = false;

				foreach ($this->linksOf($hostid, $this->interfaces[$id]['name']) as $link) {
					$level = $this->level($link['remote_hostid']);

					if ($level === null) {
						continue;
					}

					$known = true;

					if ($level === self::ROLE_LEVEL['backbone']) {
						$up[$id][] = $link;
					}
					elseif ($level === self::ROLE_LEVEL['distribuicao'] && !array_key_exists($link['remote_hostid'], $visited)) {
						$side[$id][] = $link;
					}
				}

				if (!$known) {
					$unknown[$id] = [];
				}
			}

			$chosen = $up ?: ($side ?: $unknown);

			foreach ($chosen as $id => $next_links) {
				$circuit['interfaces'][$id] = 'distribuicao';
				$circuit['hops'][$id] = array_key_exists($hostid, $pops) ? 'pop_uplink' : 'agg_uplink';

				foreach ($next_links as $link) {
					$next = $link['remote_hostid'];

					if (!array_key_exists($next, $circuit['hostids'])) {
						$circuit['hostids'][$next] = ['inferred' => true];
					}

					if ($this->role($next) === 'distribuicao') {
						$queue[] = $next;

						continue;
					}

					// Core LAN: the core end of this uplink.
					$peer = $this->by_port[$next][PortName::normalize($link['remote_port'])] ?? null;

					if ($peer !== null) {
						if (!array_key_exists($peer, $circuit['interfaces'])) {
							$circuit['interfaces'][$peer] = 'distribuicao';
							$circuit['hops'][$peer] = 'core_lan';
						}
					}
					else {
						$circuit['peers'][] = [
							'hop' => 'core_lan',
							'host' => $this->hosts[$next]['name'] ?? $link['remote_name'],
							'port' => $link['remote_port'],
							'via' => $id
						];
					}
				}
			}

			if (!$chosen) {
				$hint = '';

				foreach ($this->links[$hostid] ?? [] as $link) {
					if (in_array($this->level($link['remote_hostid']),
							[self::ROLE_LEVEL['backbone'], self::ROLE_LEVEL['distribuicao']], true)) {
						$hint = ' '.I18n::t('By LLDP/CDP the uplink is %1$s (to %2$s %3$s).', $link['port'],
							$link['remote_name'], $link['remote_port']
						);
						break;
					}
				}

				$circuit['suggestions'][] = I18n::t('%1$s has no uplink marked as shared (UPLINK/TRONCO in the description): the distribution segment is not measured.',
					$this->hosts[$hostid]['name']
				).$hint;
			}
		}
	}

	/**
	 * Core devices: shared interfaces, except the ones facing distribution/CPE devices (those are core LAN).
	 */
	private function addTrunks(array &$circuit): void {
		foreach (array_keys($circuit['hostids']) as $hostid) {
			$hostid = (string) $hostid;

			if ($this->role($hostid) !== 'backbone') {
				continue;
			}

			foreach ($this->sharedInterfaces($hostid) as $id) {
				$faces_down = false;

				foreach ($this->linksOf($hostid, $this->interfaces[$id]['name']) as $link) {
					$level = $this->level($link['remote_hostid']);

					if ($level !== null && $level < self::ROLE_LEVEL['backbone']) {
						$faces_down = true;
					}
				}

				if (!$faces_down) {
					$circuit['interfaces'][$id] = 'backbone';
					$circuit['hops'][$id] = 'core_trunk';
				}
			}
		}
	}

	private function sharedInterfaces(string $hostid): array {
		$ids = [];

		foreach ($this->by_port[$hostid] ?? [] as $id) {
			if ($this->interfaces[$id]['shared']) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Bottleneck
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Utilization used for the comparison: p95, or the current value while there is no p95 yet.
	 *
	 * @return array|null  ['value' => float, 'kind' => 'p95'|'now']
	 */
	public static function load(array $interface): ?array {
		$values = $interface['values'];

		if (isset($values['p95']) && $values['p95'] >= 0 && !isset($interface['errors']['p95'])) {
			return ['value' => $values['p95'], 'kind' => 'p95'];
		}

		if (isset($values['util']) && $values['util'] >= 0 && !isset($interface['errors']['util'])) {
			return ['value' => $values['util'], 'kind' => 'now'];
		}

		return null;
	}

	public static function isDown(array $interface): bool {
		return isset($interface['values']['status']) && in_array((int) $interface['values']['status'], [2, 6, 7], true);
	}

	/**
	 * Free capacity of an interface: what is left of its capacity at its load (bit/s).
	 */
	public static function free(array $interface): ?float {
		$load = self::load($interface);
		$bw = $interface['values']['bw'] ?? null;

		if ($load === null || $bw === null || $bw <= 0) {
			return null;
		}

		return max(0.0, $bw * (1 - min(100, $load['value']) / 100));
	}

	/**
	 * Days until the critical limit (null = no upward trend or no data).
	 */
	public static function daysLeft(array $interface): ?float {
		$days = $interface['values']['days'] ?? null;

		return ($days !== null && $days >= 0 && $days < self::DAYS_NONE) ? $days : null;
	}

	/**
	 * Growth of the p95 over the forecast horizon, in percentage points (null = unknown).
	 */
	public static function trend(array $interface): ?float {
		$v = $interface['values'];

		return (isset($v['forecast'], $v['p95']) && $v['forecast'] >= 0) ? $v['forecast'] - $v['p95'] : null;
	}

	private function evaluate(array &$circuit): void {
		$segments = [];

		foreach (self::SEGMENTS as $segment) {
			$segments[$segment] = [
				'interfaces' => [],
				'load' => null,
				'kind' => null,
				'interface' => null,
				'severity' => -1,
				'down' => false,
				'util' => null,
				'p95' => null,
				'speed' => null,
				'bw' => null,
				'free' => null,
				'days' => null,
				'trend' => null,
				'impact' => 0,
				'fits' => null,
				'state' => 'nodata',
				// Planning (representative interface): average, growth in 30 days (p.p.), traffic at the load,
				// traffic expected at the end of the horizon, capacity needed to stay at the target, upgrade.
				'shared' => false,
				'avg' => null,
				'growth' => null,
				'demand' => null,
				'growth_bps' => null,
				'projected' => null,
				'target' => null,
				'need' => null,
				'upgrade' => null
			];
		}

		$contract = $this->contracts[$circuit['cid']] ?? null;

		// From the client to the core: on equal loads the point closest to the client represents the segment
		// (the POP uplink rather than the core end of the same link).
		$hop_order = array_flip(self::HOPS);
		uksort($circuit['interfaces'], static fn($a, $b) =>
			[$hop_order[$circuit['hops'][$a] ?? ''] ?? 99, $a] <=> [$hop_order[$circuit['hops'][$b] ?? ''] ?? 99, $b]
		);

		foreach ($circuit['interfaces'] as $id => $segment) {
			$interface = $this->interfaces[$id];
			$s = &$segments[$segment];
			$v = $interface['values'];
			$s['interfaces'][] = $id;
			$s['severity'] = max($s['severity'], $interface['severity']);

			// Only the circuit's own links make it "down"; a shared uplink/trunk down is a hint (redundancy).
			if (self::isDown($interface)) {
				if ($interface['shared']) {
					$circuit['suggestions'][] = I18n::t('Shared link down on the path: %1$s %2$s.',
						$this->hosts[$interface['hostid']]['name'], $interface['name']
					);
				}
				else {
					$s['down'] = true;
				}
			}

			$load = self::load($interface);

			// Half a point more is needed to replace an interface closer to the client.
			if ($load !== null && ($s['load'] === null || $load['value'] > $s['load'] + 0.5)) {
				$s['load'] = $load['value'];
				$s['kind'] = $load['kind'];
				$s['interface'] = $id;
				$s['speed'] = $v['speed'] ?? null;
				$s['bw'] = $v['bw'] ?? null;
			}

			if (isset($v['util']) && $v['util'] >= 0) {
				$s['util'] = max($s['util'] ?? 0, $v['util']);
			}

			if (isset($v['p95']) && $v['p95'] >= 0) {
				$s['p95'] = max($s['p95'] ?? 0, $v['p95']);
			}

			$free = self::free($interface);

			if ($free !== null) {
				$s['free'] = $s['free'] === null ? $free : min($s['free'], $free);
			}

			$days = self::daysLeft($interface);

			if ($days !== null) {
				$s['days'] = $s['days'] === null ? $days : min($s['days'], $days);
			}

			$trend = self::trend($interface);

			if ($trend !== null) {
				$s['trend'] = $s['trend'] === null ? $trend : max($s['trend'], $trend);
			}

			$s['impact'] = max($s['impact'], $this->usage[$id] ?? 1);

			// Room for the contracted bandwidth: a dedicated port must be at least that fast, a shared link must
			// have that much free.
			if ($contract !== null) {
				$fits = $interface['shared']
					? ($free !== null ? $free >= $contract : null)
					: (isset($v['speed']) && $v['speed'] > 0 ? $v['speed'] >= $contract : null);

				if ($fits !== null) {
					$s['fits'] = ($s['fits'] ?? true) && $fits;
				}
			}

			unset($s);

			if (isset($interface['errors']['util']) && !isset($interface['values']['util'])
					&& strpos($interface['errors']['util'], 'division by zero') !== false) {
				$circuit['suggestions'][] = I18n::t('%1$s %2$s: unknown capacity (port speed 0). Set {$CAPACITY.BW.MBPS:"%3$s"} with the bandwidth in Mbps.',
					$this->hosts[$interface['hostid']]['name'], $interface['name'],
					// Same context as the template: the first circuit ID of the interface, or its name.
					$interface['cids'] ? $interface['cids'][0] : $interface['name']
				);
			}
		}

		foreach ($segments as $name => &$s) {
			$s['state'] = $s['down']
				? 'down'
				: ($s['load'] === null
					? 'nodata'
					: ($s['severity'] >= TRIGGER_SEVERITY_HIGH ? 'critical'
						: ($s['severity'] >= TRIGGER_SEVERITY_WARNING ? 'warning' : 'ok')));

			$this->plan($s, $name);
		}
		unset($s);

		[$bottleneck, $criteria, $votes] = self::chooseBottleneck($segments);

		$forecast = null;

		foreach ($circuit['interfaces'] as $id => $segment) {
			$days = self::daysLeft($this->interfaces[$id]);

			if ($days !== null && ($forecast === null || $days < $forecast['days'])) {
				$forecast = ['days' => $days, 'interface' => $id, 'segment' => $segment, 'alert' => false];
			}
		}

		// "alert" = within the horizon of the forecast trigger ({$CAPACITY.FORECAST.DAYS}): a forecast problem.
		foreach (array_keys($circuit['interfaces']) as $id) {
			if ($forecast !== null && $this->interfaces[$id]['forecast_alert']) {
				$forecast['alert'] = true;
			}
		}

		$severity = -1;
		$down = false;

		foreach ($segments as $segment) {
			$severity = max($severity, $segment['severity']);
			$down = $down || $segment['down'];
		}

		// The same circuit ID must appear on the three roles (planning document).
		$coverage = array_fill_keys(self::ROLES, false);

		foreach ($circuit['interfaces'] as $id => $segment) {
			$role = $this->role($this->interfaces[$id]['hostid']);

			if ($role !== '' && in_array($circuit['cid'], $this->interfaces[$id]['cids'], true)) {
				$coverage[$role] = true;
			}
		}

		$circuit['segments'] = $segments;
		$circuit['bottleneck'] = $bottleneck;
		$circuit['criteria'] = $criteria;
		$circuit['votes'] = $votes;
		$circuit['load'] = $bottleneck !== null ? $segments[$bottleneck]['load'] : null;
		$circuit['capacity'] = $bottleneck !== null ? $segments[$bottleneck]['bw'] : null;
		$circuit['headroom'] = $bottleneck !== null ? $segments[$bottleneck]['free'] : null;
		$circuit['contract'] = $contract;
		$circuit['fits'] = self::fitsAll($segments);
		$circuit['coverage'] = $coverage;
		$circuit['forecast'] = $forecast;
		$circuit['severity'] = $severity;
		$circuit['down'] = $down;
		$circuit['suggestions'] = array_values(array_unique($circuit['suggestions']));
		$circuit['endpoints'] = array_values($circuit['endpoints']);

		// Analysis of the planning document: average, growth, situation, recommended capacity, client/site.
		$circuit['avg'] = $bottleneck !== null ? $segments[$bottleneck]['avg'] : null;
		$circuit['growth'] = $bottleneck !== null ? $segments[$bottleneck]['growth'] : null;
		$circuit['situation'] = self::situation($circuit);
		$circuit['risk'] = self::riskScore($circuit);
		$circuit['upgrade'] = $this->recommend($circuit);
		$circuit += $this->clientInfo($circuit);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Analysis and upgrades
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Planning figures of a segment, from its representative interface: traffic at the load (p95), traffic
	 * expected at the end of the horizon by the growth trend, and the capacity needed to stay at the target.
	 * Circuit ports (dedicated, any device) use the target of the last mile; shared links the one of their segment.
	 */
	private function plan(array &$s, string $segment): void {
		if ($s['interface'] === null) {
			return;
		}

		$interface = $this->interfaces[$s['interface']];
		$v = $interface['values'];

		$s['shared'] = $interface['shared'];
		$s['target'] = $this->planning['targets'][$interface['shared'] ? $segment : 'acesso'];
		$s['avg'] = isset($v['avg']) && $v['avg'] >= 0 && !isset($interface['errors']['avg']) ? $v['avg'] : null;
		$s['growth'] = isset($v['growth']) && !isset($interface['errors']['growth']) ? $v['growth'] : null;

		if ($s['load'] === null || $s['bw'] === null || $s['bw'] <= 0) {
			return;
		}

		$s['demand'] = $s['bw'] * min($s['load'], 1000) / 100;
		$s['growth_bps'] = $s['growth'] !== null ? $s['bw'] * $s['growth'] / 100 : null;
		$s['projected'] = $s['demand'] + max(0.0, $s['growth_bps'] ?? 0.0) * $this->planning['horizon'] / 30;
		$s['need'] = $s['projected'] * 100 / $s['target'];

		if ($s['need'] > $s['bw'] * 1.001) {
			$step = self::stepFor($s['need'], $s['bw']);
			$s['upgrade'] = ['to' => $step, 'beyond' => $step === null];
		}
	}

	/**
	 * Smallest standard capacity that is at least $need and more than $current (null = beyond the largest).
	 */
	public static function stepFor(float $need, float $current): ?float {
		foreach (self::STEPS as $step) {
			if ($step >= $need && $step > $current * 1.001) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * Standard capacities above the current one (choices of the simulation).
	 */
	public static function stepsAbove(float $current, int $count = 8): array {
		return array_slice(array_values(array_filter(self::STEPS,
			static fn(float $step): bool => $step > $current * 1.001
		)), 0, $count);
	}

	/**
	 * Situation of a circuit (bands of the planning document, plus the role limits of the triggers and the
	 * forecast): critical = link down, critical limit of its role, 90 % or saturating within 30 days; high =
	 * attention limit, 80 % or saturating within 60 days; medium = 60 %; low = below.
	 */
	public static function situation(array $circuit): string {
		if ($circuit['down']) {
			return 'critical';
		}

		if ($circuit['load'] === null) {
			return 'nodata';
		}

		$load = $circuit['load'];
		$days = $circuit['forecast']['days'] ?? null;

		if ($circuit['severity'] >= TRIGGER_SEVERITY_HIGH || $load >= self::BAND_CRITICAL
				|| ($days !== null && $days <= 30)) {
			return 'critical';
		}

		if ($circuit['severity'] >= TRIGGER_SEVERITY_WARNING || $load >= self::BAND_HIGH
				|| ($days !== null && $days <= 60)) {
			return 'high';
		}

		return $load >= self::BAND_MEDIUM ? 'medium' : 'low';
	}

	/**
	 * Order of the ranking: link down first, then the highest of the load and the closeness of the saturation
	 * (saturating in 0 days counts as 100 %, in 120 days as 40 %).
	 */
	public static function riskScore(array $circuit): float {
		if ($circuit['down']) {
			return 1000.0;
		}

		$days = $circuit['forecast']['days'] ?? null;

		return max($circuit['load'] ?? -1.0, $days !== null ? 100 - $days / 2 : -1.0);
	}

	/**
	 * Band of a utilization on the logical capacity map: critical (> 90 %), high (80-90), medium (60-80), low.
	 */
	public static function band(?float $load): string {
		if ($load === null) {
			return 'nodata';
		}

		return $load >= self::BAND_CRITICAL ? 'critical'
			: ($load >= self::BAND_HIGH ? 'high' : ($load >= self::BAND_MEDIUM ? 'medium' : 'low'));
	}

	/**
	 * Recommended capacity: the segment that needs more capacity to stay at its target until the end of the
	 * horizon (the bottleneck first). For the circuit's own ports it is a new contracted bandwidth; the shared
	 * links on the path are checked first, as if the client used all of it ("check before the upgrade").
	 */
	private function recommend(array $circuit): ?array {
		$needs = [];

		foreach ($circuit['segments'] as $name => $s) {
			if ($s['upgrade'] !== null) {
				$needs[$name] = $s['need'] / $s['bw'];
			}
		}

		if (!$needs) {
			return null;
		}

		arsort($needs);
		$segment = array_key_exists((string) $circuit['bottleneck'], $needs) ? $circuit['bottleneck'] : array_key_first($needs);
		$s = $circuit['segments'][$segment];
		$interface = $this->interfaces[$s['interface']];
		$speed = $interface['values']['speed'] ?? null;

		$recommendation = [
			'segment' => $segment,
			'interface' => $s['interface'],
			'shared' => $interface['shared'],
			'from' => $s['bw'],
			'to' => $s['upgrade']['to'],
			'beyond' => $s['upgrade']['beyond'],
			'need' => $s['need'],
			'projected' => $s['projected'],
			'target' => $s['target'],
			'impact' => $s['impact'],
			'others' => array_values(array_diff(array_keys($needs), [$segment])),
			// A new contracted bandwidth above the speed of the port needs a faster port.
			'port_limit' => !$interface['shared'] && $s['upgrade']['to'] !== null && $speed !== null && $speed > 0
				&& $speed < $s['upgrade']['to'],
			'check' => []
		];

		// Shared links of the path that would be above their target with the client using all of it.
		if (!$interface['shared'] && $s['upgrade']['to'] !== null) {
			foreach ($this->simulate($circuit, $s['upgrade']['to']) as $row) {
				if ($row['kind'] === 'shared' && $row['after']['state'] !== 'ok') {
					$recommendation['check'][$row['segment']] = $row['after']['util'];
				}
			}
		}

		return $recommendation;
	}

	/**
	 * What happens on the path if the circuit gets $capacity (bit/s): its own ports get the new capacity (never
	 * less than they have) and keep today's traffic; the shared links carry the extra traffic of the worst case,
	 * the client using all of the new capacity.
	 *
	 * @return array  one row per segment and kind ("own" ports of the circuit, "shared" links), the worst interface
	 *                after the change: ['segment', 'kind', 'interface', 'before' => figures, 'after' => figures],
	 *                figures = ['capacity', 'demand', 'util', 'free', 'state', 'target', 'interface', 'port_limit']
	 */
	public function simulate(array $circuit, float $capacity): array {
		// Today's traffic of the circuit: the busiest of its own ports.
		$own = null;

		foreach ($circuit['interfaces'] as $id => $segment) {
			$interface = $this->interfaces[$id];
			$load = self::load($interface);
			$bw = $interface['values']['bw'] ?? null;

			if (!$interface['shared'] && $load !== null && $bw !== null && $bw > 0) {
				$own = max($own ?? 0.0, $bw * min($load['value'], 1000) / 100);
			}
		}

		$extra = max(0.0, $capacity - ($own ?? 0.0));
		$rows = [];

		foreach (self::SEGMENTS as $segment) {
			foreach (['own', 'shared'] as $kind) {
				$row = null;

				foreach ($circuit['segments'][$segment]['interfaces'] as $id) {
					$interface = $this->interfaces[$id];

					if ($interface['shared'] !== ($kind === 'shared')) {
						continue;
					}

					$load = self::load($interface);
					$bw = $interface['values']['bw'] ?? null;

					if ($load === null || $bw === null || $bw <= 0) {
						continue;
					}

					$demand = $bw * min($load['value'], 1000) / 100;
					$speed = $interface['values']['speed'] ?? null;
					$target = $this->planning['targets'][$kind === 'shared' ? $segment : 'acesso'];
					$before = $this->figures($bw, $demand, $target, $id, false);

					if ($kind === 'shared') {
						$after = $this->figures($bw, $demand + $extra, $target, $id, false);
					}
					else {
						$new_bw = max($bw, $capacity);
						$after = $this->figures($new_bw, $demand, $target, $id,
							$speed !== null && $speed > 0 && $speed < $new_bw
						);
					}

					// The worst interface after the change represents the segment.
					if ($row === null || $after['util'] > $row['after']['util']) {
						$row = ['segment' => $segment, 'kind' => $kind, 'interface' => $id, 'before' => $before,
							'after' => $after
						];
					}
				}

				if ($row !== null) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}

	private function figures(float $capacity, float $demand, int $target, ?string $id, bool $port_limit): array {
		$util = $demand * 100 / $capacity;

		return [
			'capacity' => $capacity,
			'demand' => $demand,
			'util' => $util,
			'free' => $capacity - $demand,
			'state' => $util > 100 ? 'over' : ($util >= self::BAND_CRITICAL ? 'critical' : ($util > $target ? 'warning' : 'ok')),
			'target' => $target,
			'interface' => $id,
			'port_limit' => $port_limit
		];
	}

	/**
	 * Client and site of a circuit: the description of the provider-side port names the site ("CID:NET-TO-0001
	 * Escola Centro"); the client is the CPE host or the LLDP/CDP neighbor on the client port.
	 */
	private function clientInfo(array $circuit): array {
		$site = '';

		foreach ([['pop_client', 'core_client'], ['cpe']] as $hops) {
			foreach ($circuit['hops'] as $id => $hop) {
				$interface = $this->interfaces[$id];

				if (in_array($hop, $hops, true) && in_array($circuit['cid'], $interface['cids'], true)) {
					$site = self::siteFromAlias($interface['alias'], $interface['cids']);

					if ($site !== '') {
						break 2;
					}
				}
			}
		}

		$client = '';

		foreach (array_keys($circuit['hostids']) as $hostid) {
			if ($this->role((string) $hostid) === 'cpe') {
				$client = $this->hosts[(string) $hostid]['name'];
				break;
			}
		}

		if ($client === '' && $circuit['endpoints']) {
			$client = $circuit['endpoints'][0]['name'];
		}

		return ['site' => $site, 'client' => $client];
	}

	/**
	 * What is left of an interface description without the circuit IDs and the words about the port itself.
	 */
	public static function siteFromAlias(string $alias, array $cids): string {
		$text = $alias;

		foreach ($cids as $cid) {
			$text = str_ireplace($cid, ' ', $text);
		}

		$text = preg_replace('/\b(?:CID|CIRCUITO|CIRCUIT|CKT)\b\s*[:=#]?/iu', ' ', $text);
		$text = preg_replace('/\b(?:WAN|LAN|CPE|UPLINK|TRONCO|TRUNK|LINK|ACESSO|ACCESS|CLIENTE|CLIENT|DEDICADO|DEDICATED)\b/iu',
			' ', $text
		);
		$text = trim(preg_replace('/\s+/u', ' ', $text), " \t-_:;,.|/#=()[]");

		return mb_substr($text, 0, 60);
	}

	/**
	 * @return bool|null  false = some segment cannot carry the contracted bandwidth; null = unknown.
	 */
	private static function fitsAll(array $segments): ?bool {
		$result = null;

		foreach ($segments as $segment) {
			if ($segment['fits'] === false) {
				return false;
			}

			if ($segment['fits'] === true) {
				$result = true;
			}
		}

		return $result;
	}

	/**
	 * The four criteria: highest utilization, lowest free capacity, shortest time to saturation, highest impact
	 * (circuits sharing the segment). Each criterion votes for the worst segment(s); a criterion where all
	 * segments are equal does not vote.
	 *
	 * @return array  [bottleneck segment|null, criterion => winning segments, segment => votes]
	 */
	public static function chooseBottleneck(array $segments): array {
		$candidates = array_filter($segments, static fn(array $s): bool => $s['load'] !== null);
		$criteria = array_fill_keys(self::CRITERIA, []);
		$votes = array_fill_keys(self::SEGMENTS, 0);

		if (!$candidates) {
			return [null, $criteria, $votes];
		}

		$pick = static function(string $field, bool $highest, float $tolerance, bool $relative = false)
				use ($candidates): array {
			$values = [];

			foreach ($candidates as $segment => $s) {
				if ($s[$field] !== null) {
					$values[$segment] = (float) $s[$field];
				}
			}

			if (!$values) {
				return [];
			}

			$best = $highest ? max($values) : min($values);
			$margin = $relative ? abs($best) * $tolerance : $tolerance;
			$winners = array_keys(array_filter($values, static fn(float $v): bool => abs($v - $best) <= $margin));

			// Everybody equal: this criterion does not tell the segments apart.
			if (count($candidates) > 1 && count($winners) == count($candidates)) {
				return [];
			}

			return $winners;
		};

		$criteria['util'] = $pick('load', true, 0.5);
		$criteria['free'] = $pick('free', false, 0.01, true);
		$criteria['days'] = $pick('days', false, 0.5);
		$criteria['impact'] = $pick('impact', true, 0);

		foreach ($criteria as $winners) {
			foreach ($winners as $segment) {
				$votes[$segment]++;
			}
		}

		$bottleneck = null;

		foreach (self::SEGMENTS as $segment) {
			if (!array_key_exists($segment, $candidates)) {
				continue;
			}

			if ($bottleneck === null
					|| $votes[$segment] > $votes[$bottleneck]
					|| ($votes[$segment] == $votes[$bottleneck]
						&& $candidates[$segment]['load'] > $candidates[$bottleneck]['load'] + 0.5)) {
				$bottleneck = $segment;
			}
		}

		return [$bottleneck, $criteria, $votes];
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Output
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Circuit on the topology map: its hosts and the edges that touch one of its interfaces.
	 *
	 * @param array $topology  Result of TopologyBuilder::build().
	 *
	 * @return array|null  ['cid', 'hostids', 'edges', 'bottleneck', 'load'] or null if the circuit does not exist.
	 */
	public static function onMap(string $cid, array $topology, int $max_hosts): ?array {
		$capacity = (new self(['circuit' => $cid, 'max_hosts' => $max_hosts]))->build();
		$circuit = $capacity['circuits'][$cid] ?? null;

		if ($circuit === null) {
			return null;
		}

		$ports = [];

		foreach (array_keys($circuit['interfaces']) as $id) {
			$interface = $capacity['interfaces'][$id];
			$ports[$interface['hostid']][] = $interface['name'];
		}

		$on_port = static function(string $node, string $port) use ($ports): bool {
			if ($node === '' || $node[0] !== 'h') {
				return false;
			}

			foreach ($ports[substr($node, 1)] ?? [] as $name) {
				if (PortName::equals($name, $port)) {
					return true;
				}
			}

			return false;
		};

		$edges = [];

		foreach ($topology['edges'] as $edge) {
			if ($on_port($edge['source'], $edge['source_port']) || $on_port($edge['target'], $edge['target_port'])) {
				$edges[] = $edge['id'];
			}
		}

		return [
			'cid' => $cid,
			'hostids' => array_map('strval', array_keys($circuit['hostids'])),
			'edges' => $edges,
			'bottleneck' => $circuit['bottleneck'],
			'load' => $circuit['load']
		];
	}

	public static function splitIds(string $text): array {
		$ids = [];

		foreach (preg_split('/[,;\s]+/', strtoupper($text)) as $id) {
			if (self::isValidId($id) && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Circuit IDs: letters, digits and . _ / - (the same rule as the discovery script of the template).
	 */
	public static function isValidId(string $cid): bool {
		return preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/', $cid) === 1;
	}

	private function result(array $circuits, array $listed): array {
		$used_by = [];

		foreach ($circuits as $circuit) {
			foreach ($circuit['interfaces'] as $id => $segment) {
				$used_by[$id][] = $circuit['cid'];
			}
		}

		$interfaces = [];
		$listed = array_flip($listed);

		foreach ($this->interfaces as $id => $interface) {
			if (array_key_exists($id, $listed) || array_key_exists($id, $used_by)) {
				$host = $this->hosts[$interface['hostid']];
				$serves = $used_by[$id] ?? [];
				$via_peer = false;

				// A core port facing a distribution device is the other end of an uplink that is measured on the
				// distribution side: it serves the same circuits.
				if (!$serves) {
					foreach ($this->linksOf($interface['hostid'], $interface['name']) as $link) {
						$peer = $link['remote_hostid'] !== null
							? ($this->by_port[$link['remote_hostid']][PortName::normalize($link['remote_port'])] ?? null)
							: null;

						if ($peer !== null && array_key_exists($peer, $used_by)) {
							$serves = $used_by[$peer];
							$via_peer = true;
						}
					}
				}

				$interfaces[$id] = $interface + [
					'host' => $host['name'],
					'role' => $host['role'],
					'circuits' => $serves,
					'circuits_via_peer' => $via_peer,
					'impact' => $this->usage[$id] ?? 0,
					'listed' => array_key_exists($id, $listed)
				];
			}
		}

		$hosts = [];

		foreach ($circuits as $circuit) {
			foreach (array_keys($circuit['hostids']) as $hostid) {
				$hosts[$hostid] = $this->hosts[$hostid];
			}
		}

		foreach ($interfaces as $interface) {
			$hosts[$interface['hostid']] = $this->hosts[$interface['hostid']];
		}

		$summary = ['circuits' => count($circuits), 'acesso' => 0, 'distribuicao' => 0, 'backbone' => 0,
			'alert' => 0, 'forecast' => 0, 'down' => 0, 'nodata' => 0, 'coverage' => 0, 'contract_risk' => 0,
			// Overview of the planning document.
			'avg_load' => null, 'over80' => 0, 'critical_alerts' => 0, 'free_chain' => null, 'upgrades' => 0,
			'situations' => array_fill_keys(self::SITUATIONS, 0)
		];
		$loads = [];

		foreach ($circuits as $circuit) {
			if ($circuit['bottleneck'] !== null) {
				$summary[$circuit['bottleneck']]++;
			}
			else {
				$summary['nodata']++;
			}

			$summary['alert'] += $circuit['severity'] >= TRIGGER_SEVERITY_WARNING ? 1 : 0;
			$summary['forecast'] += ($circuit['forecast']['alert'] ?? false) ? 1 : 0;
			$summary['down'] += $circuit['down'] ? 1 : 0;
			$summary['coverage'] += !in_array(false, $circuit['coverage'], true) ? 1 : 0;
			$summary['contract_risk'] += $circuit['fits'] === false ? 1 : 0;
			$summary['upgrades'] += $circuit['upgrade'] !== null ? 1 : 0;
			$summary['situations'][$circuit['situation']]++;

			if ($circuit['load'] !== null) {
				$loads[] = $circuit['load'];
				$summary['over80'] += $circuit['load'] >= self::BAND_HIGH ? 1 : 0;
			}
		}

		$summary['avg_load'] = $loads ? array_sum($loads) / count($loads) : null;

		// Critical alerts (capacity problems High or above) and free capacity of the chain: each interface of the
		// paths once.
		$events = [];

		foreach (array_keys($used_by) as $id) {
			$interface = $this->interfaces[$id];

			foreach ($interface['problems'] as $problem) {
				if ($problem['severity'] >= TRIGGER_SEVERITY_HIGH) {
					$events[$problem['eventid']] = true;
				}
			}

			$free = self::free($interface);

			if ($free !== null) {
				$summary['free_chain'] = ($summary['free_chain'] ?? 0.0) + $free;
			}
		}

		$summary['critical_alerts'] = count($events);

		if (!$this->hosts) {
			$this->warnings[] = I18n::t('No host has the capacity planning templates. Link "Template Capacity Planning - Backbone", "- Distribuicao" or "- CPE" to your devices.');
		}
		elseif (!$this->interfaces) {
			$this->warnings[] = I18n::t('No interface with a circuit ID yet. Write "CID:<ID>" in the description of the interfaces (UPLINK/TRONCO for shared links) and wait for the discovery.');
		}

		return [
			'circuits' => $circuits,
			'interfaces' => $interfaces,
			'hosts' => $hosts,
			'summary' => $summary,
			'planning' => $this->planning,
			'warnings' => array_values(array_unique($this->warnings))
		];
	}

	/**
	 * Logical capacity map: for each link of the topology, the load of the measured interface at either end (p95,
	 * else the current value), its band and the circuits that depend on it.
	 *
	 * @param array $topology  Result of TopologyBuilder::build().
	 *
	 * @return array  edge id => ['load' => float|null, 'kind' => 'p95'|'now'|null, 'band' => string,
	 *                'circuits' => string[] (the first 30), 'count' => int]
	 */
	public static function onMapAll(array $topology, int $max_hosts, array $planning = []): array {
		$builder = new self(['max_hosts' => $max_hosts, 'planning' => $planning]);
		$capacity = $builder->build();
		$result = [];

		foreach ($topology['edges'] as $edge) {
			$best = null;
			$circuits = [];

			foreach (['source', 'target'] as $end) {
				$node = (string) $edge[$end];

				if ($node === '' || $node[0] !== 'h') {
					continue;
				}

				$id = $builder->by_port[substr($node, 1)][PortName::normalize($edge[$end.'_port'] ?? '')] ?? null;

				if ($id === null || !array_key_exists($id, $capacity['interfaces'])) {
					continue;
				}

				$interface = $capacity['interfaces'][$id];
				$load = self::load($interface);

				if ($load !== null && ($best === null || $load['value'] > $best['value'])) {
					$best = $load;
				}

				$circuits = array_merge($circuits, $interface['circuits']);
			}

			if ($best === null && !$circuits) {
				continue;
			}

			$circuits = array_values(array_unique(array_map('strval', $circuits)));
			natcasesort($circuits);

			$result[$edge['id']] = [
				'load' => $best['value'] ?? null,
				'kind' => $best['kind'] ?? null,
				'band' => self::band($best['value'] ?? null),
				'circuits' => array_slice(array_values($circuits), 0, 30),
				'count' => count($circuits)
			];
		}

		return $result;
	}
}
