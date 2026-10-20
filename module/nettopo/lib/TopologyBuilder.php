<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Builds the graph (nodes = hosts / unregistered neighbors, edges = links) from:
**  - LLDP / CDP neighbor tables collected by the "Network Topology ... by SNMP" templates;
**  - host tags "topology.link";
**  - links of existing Zabbix network maps;
**  - trigger dependencies between hosts.
** Node status comes from active problems; link status from interface operational status,
** lost neighbors (LLD) and problems tagged with the interface name.
**/

namespace Modules\NetTopo\Lib;

use API,
	CSeverityHelper,
	CSettingsHelper,
	Manager;

class TopologyBuilder {

	public const SOURCES = ['lldp', 'cdp', 'tags', 'deps'];

	public const TAG_LINK = 'topology.link';
	public const TAG_TYPE = 'topology.type';
	public const TAG_LEVEL = 'topology.level';

	public const DEVICE_TYPES = ['router', 'switch', 'firewall', 'server', 'ap', 'olt', 'cpe', 'phone', 'cloud',
		'storage', 'ups', 'printer', 'camera', 'device'
	];

	private const NEIGHBOR_PROTOCOLS = ['lldp', 'cdp'];

	/**
	 * Default item keys of interface items ({IFINDEX} = SNMP index), in order of preference.
	 * They match the official Zabbix templates ("Network interfaces by SNMP" and vendor templates).
	 */
	private const PORT_ITEM_KEYS = [
		'status' => ['net.if.status[ifOperStatus.{IFINDEX}]', 'net.if.status[{IFINDEX}]'],
		'in' => ['net.if.in[ifHCInOctets.{IFINDEX}]', 'net.if.in[ifInOctets.{IFINDEX}]', 'net.if.in[{IFINDEX}]'],
		'out' => ['net.if.out[ifHCOutOctets.{IFINDEX}]', 'net.if.out[ifOutOctets.{IFINDEX}]', 'net.if.out[{IFINDEX}]'],
		'speed' => ['net.if.speed[ifHighSpeed.{IFINDEX}]', 'net.if.speed[ifSpeed.{IFINDEX}]', 'net.if.speed[{IFINDEX}]']
	];

	private array $options;

	/** Hosts shown as nodes: hostid => host data. */
	private array $hosts = [];

	/** Hosts selected by the filter: hostid => true. */
	private array $scope = [];

	private array $ext_nodes = [];
	private array $edges = [];
	private array $warnings = [];
	private ?HostIndex $index = null;

	/** hostid => list of problems. */
	private array $host_problems = [];

	/** hostid => normalized port name => list of problems. */
	private array $port_problems = [];

	/** hostid => capabilities advertised by the host (as seen by its neighbors). */
	private array $host_caps = [];

	/** hostid => true for scope hosts that have the neighbor item but no (valid) value yet. */
	private array $hosts_without_neighbor_data = [];

	/**
	 * @param array $options
	 *        string[] groupids   Host groups (subgroups are included).
	 *        string[] hostids    Additional hosts.
	 *        string[] mapids     Maps to import links (and hosts) from.
	 *        string[] sources    Enabled link sources, subset of self::SOURCES.
	 *        bool     traffic    Read traffic counters of the link ports.
	 *        int      max_hosts  Hard limit of hosts in scope.
	 */
	public function __construct(array $options) {
		$this->options = $options + [
			'groupids' => [],
			'hostids' => [],
			'mapids' => [],
			'sources' => ['lldp', 'cdp', 'tags'],
			'traffic' => true,
			'max_hosts' => 2000
		];
	}

	public function build(): array {
		$map_links = $this->options['mapids'] ? $this->loadMapLinks() : [];

		$this->loadScopeHosts(array_keys($map_links['hostids'] ?? []));

		if (!$this->scope) {
			return $this->result();
		}

		$reports = [];

		foreach (self::NEIGHBOR_PROTOCOLS as $proto) {
			if (in_array($proto, $this->options['sources'], true)) {
				$reports = array_merge($reports, $this->collectNeighborReports($proto));
			}
		}

		if ($reports) {
			$this->matchReports($reports);
			$this->addNeighborEdges($reports);
		}

		if (in_array('tags', $this->options['sources'], true)) {
			$this->addTagEdges();
		}

		if ($map_links) {
			$this->addMapEdges($map_links);
		}

		if (in_array('deps', $this->options['sources'], true)) {
			$this->addDependencyEdges();
		}

		$this->loadMissingHosts();
		$this->loadProblems();
		$this->resolvePorts();

		if ($this->hosts_without_neighbor_data) {
			$this->warnings[] = I18n::t('%1$s hosts have the LLDP/CDP template but no neighbor data yet: %2$s',
				count($this->hosts_without_neighbor_data),
				implode(', ', array_slice(array_map(fn($hostid) => $this->hosts[$hostid]['name'],
					array_keys($this->hosts_without_neighbor_data)
				), 0, 5)).(count($this->hosts_without_neighbor_data) > 5 ? ', ...' : '')
			);
		}

		if (!$this->edges) {
			$this->warnings[] = I18n::t('No links found. Link the template "Network Topology LLDP by SNMP" to your devices, use host tags or select maps in the filter.');
		}

		return $this->result();
	}

	/**
	 * Only the physical links (LLDP, CDP and "topology.link" tags) between the selected hosts and their
	 * neighbors, without problems, port status or traffic. Used by the capacity planning page.
	 *
	 * @return array  list of ['source_hostid', 'target_hostid' (null = not a Zabbix host), 'source_port',
	 *                'target_port', 'target_name' (neighbor name), 'lost']
	 */
	public function buildLinks(): array {
		$this->loadScopeHosts([]);

		if (!$this->scope) {
			return [];
		}

		$reports = [];

		foreach (self::NEIGHBOR_PROTOCOLS as $proto) {
			$reports = array_merge($reports, $this->collectNeighborReports($proto));
		}

		if ($reports) {
			$this->matchReports($reports);
			$this->addNeighborEdges($reports);
		}

		$this->addTagEdges();
		$this->loadMissingHosts();

		$links = [];

		foreach ($this->edges as $edge) {
			$target_name = $edge['target_hostid'] !== null
				? ($this->hosts[$edge['target_hostid']]['name'] ?? '')
				: ($this->ext_nodes[$edge['target']]['name'] ?? '');

			$links[] = [
				'source_hostid' => $edge['source_hostid'],
				'target_hostid' => $edge['target_hostid'],
				'source_port' => $edge['source_port'],
				'target_port' => $edge['target_port'],
				'target_name' => $target_name,
				'lost' => (bool) $edge['lost']
			];
		}

		return $links;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Hosts
	 * ---------------------------------------------------------------------------------------------
	 */

	private static function hostOptions(): array {
		return [
			'output' => ['hostid', 'host', 'name', 'maintenance_status', 'maintenance_type'],
			'selectInterfaces' => ['ip', 'dns', 'useip', 'type', 'main', 'available'],
			'selectTags' => ['tag', 'value'],
			'selectInventory' => ['type', 'macaddress_a', 'macaddress_b'],
			'selectParentTemplates' => ['name'],
			'monitored_hosts' => true,
			'preservekeys' => true
		];
	}

	private function loadScopeHosts(array $map_hostids): void {
		$max = max(1, (int) $this->options['max_hosts']);
		$groupids = self::expandGroups($this->options['groupids']);
		$hostids = array_values(array_unique(array_merge($this->options['hostids'], $map_hostids)));
		$hosts = [];

		if (!$groupids && !$hostids) {
			$hosts = API::Host()->get(self::hostOptions() + ['limit' => $max + 1]);
		}
		else {
			if ($groupids) {
				$hosts = API::Host()->get(self::hostOptions() + ['groupids' => $groupids, 'limit' => $max + 1]);
			}

			if ($hostids) {
				$hosts += API::Host()->get(self::hostOptions() + ['hostids' => $hostids]);
			}
		}

		if (count($hosts) > $max) {
			$hosts = array_slice($hosts, 0, $max, true);
			$this->warnings[] = I18n::t('Only the first %1$s hosts are shown. Narrow down the filter.', $max);
		}

		foreach ($hosts as $hostid => $host) {
			$this->hosts[(string) $hostid] = $host;
			$this->scope[(string) $hostid] = true;
		}
	}

	/**
	 * Selected groups plus their subgroups ("Parent/Child").
	 */
	public static function expandGroups(array $groupids): array {
		if (!$groupids) {
			return [];
		}

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $groupids,
			'preservekeys' => true
		]);

		if (!$groups) {
			return [];
		}

		$subgroups = API::HostGroup()->get([
			'output' => ['groupid'],
			'search' => ['name' => array_map(static fn($group) => $group['name'].'/', array_values($groups))],
			'searchByAny' => true,
			'startSearch' => true,
			'preservekeys' => true
		]);

		return array_map('strval', array_keys($groups + $subgroups));
	}

	/**
	 * Hosts referenced by links but outside the filter (neighbors of scope hosts).
	 */
	private function loadMissingHosts(): void {
		$missing = [];

		foreach ($this->edges as $edge) {
			foreach (['source', 'target'] as $end) {
				$hostid = $edge[$end.'_hostid'];

				if ($hostid !== null && !array_key_exists($hostid, $this->hosts)) {
					$missing[$hostid] = true;
				}
			}
		}

		if (!$missing) {
			return;
		}

		$hosts = API::Host()->get(self::hostOptions() + ['hostids' => array_keys($missing)]);

		foreach ($hosts as $hostid => $host) {
			$this->hosts[(string) $hostid] = $host;
		}

		// Links to hosts the user cannot see (or that are not monitored) are dropped.
		foreach ($this->edges as $id => $edge) {
			foreach (['source', 'target'] as $end) {
				if ($edge[$end.'_hostid'] !== null && !array_key_exists($edge[$end.'_hostid'], $this->hosts)) {
					unset($this->edges[$id]);
					break;
				}
			}
		}
	}

	private function getIndex(): HostIndex {
		if ($this->index === null) {
			$this->index = new HostIndex($this->hosts);
		}

		return $this->index;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * LLDP / CDP
	 * ---------------------------------------------------------------------------------------------
	 */

	private function collectNeighborReports(string $proto): array {
		$reports = [];
		$present = [];

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'lastvalue', 'lastclock'],
			'hostids' => array_keys($this->scope),
			'filter' => ['key_' => 'nettopo.'.$proto.'.neighbors'],
			'monitored' => true
		]);

		foreach ($items as $item) {
			$hostid = (string) $item['hostid'];
			$list = json_decode((string) $item['lastvalue'], true);

			if (!is_array($list)) {
				$this->hosts_without_neighbor_data[$hostid] = true;

				continue;
			}

			unset($this->hosts_without_neighbor_data[$hostid]);

			foreach ($list as $neighbor) {
				if (!is_array($neighbor) || !array_key_exists('k', $neighbor)) {
					continue;
				}

				$present[$hostid][(string) $neighbor['k']] = true;
				$reports[] = self::makeReport($proto, $hostid, $neighbor, false);
			}
		}

		// Neighbors no longer present: LLD entities marked as lost (kept for {$NETTOPO.LOST.KEEP}).
		$key_prefix = 'nettopo.'.$proto.'.neighbor[';
		$use_discovery_data = version_compare(ZABBIX_VERSION, '7.4', '>=');
		$lost_status = defined('ZBX_LLD_STATUS_LOST') ? ZBX_LLD_STATUS_LOST : 1;

		$lost_items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_'],
			'hostids' => array_keys($this->scope),
			'search' => ['key_' => $key_prefix],
			'startSearch' => true,
			'filter' => ['flags' => ZBX_FLAG_DISCOVERY_CREATED],
			'selectTags' => ['tag', 'value'],
			($use_discovery_data ? 'selectDiscoveryData' : 'selectItemDiscovery') => ['status', 'ts_delete'],
			'monitored' => true
		]);

		foreach ($lost_items as $item) {
			$discovery = $item['discoveryData'] ?? $item['itemDiscovery'] ?? [];

			if (!array_key_exists('status', $discovery) || $discovery['status'] != $lost_status) {
				continue;
			}

			$hostid = (string) $item['hostid'];
			$key = substr($item['key_'], strlen($key_prefix), -1);

			if (isset($present[$hostid][$key])) {
				continue;
			}

			$tags = [];

			foreach ($item['tags'] as $tag) {
				$tags[$tag['tag']] = $tag['value'];
			}

			$neighbor = [
				'k' => $key,
				'lp' => $tags['nt.local'] ?? '',
				'li' => $tags['nt.ifindex'] ?? '',
				'rn' => $tags['nt.remote'] ?? '',
				'rp' => $tags['nt.rport'] ?? '',
				'rc' => $tags['nt.rchassis'] ?? '',
				'ra' => ($tags['nt.rip'] ?? '') !== '' ? [$tags['nt.rip']] : []
			];

			$reports[] = self::makeReport($proto, $hostid, $neighbor, true, (int) ($discovery['ts_delete'] ?? 0));
		}

		return $reports;
	}

	private static function makeReport(string $proto, string $hostid, array $nb, bool $lost, int $ts_delete = 0): array {
		$str = static fn($v) => is_scalar($v) ? trim((string) $v) : '';

		$rp = $str($nb['rp'] ?? '');
		$rpd = $str($nb['rpd'] ?? '');
		$ra = [];

		foreach ((array) ($nb['ra'] ?? []) as $ip) {
			if (is_string($ip) && $ip !== '') {
				$ra[] = $ip;
			}
		}

		return [
			'proto' => $proto,
			'hostid' => $hostid,
			'key' => $str($nb['k'] ?? ''),
			'lp' => $str($nb['lp'] ?? ($nb['lpn'] ?? '')),
			'li' => $str($nb['li'] ?? ''),
			'lpid' => $str($nb['lpid'] ?? ''),
			'rn' => $str($nb['rn'] ?? ''),
			'rc' => $str($nb['rc'] ?? ''),
			'rp' => $rp,
			'rpd' => $rpd,
			// Human friendly remote port: a MAC address as port ID says little, prefer the description.
			'rport' => ($rpd !== '' && ($rp === '' || PortName::isMac($rp))) ? $rpd : $rp,
			'ra' => $ra,
			'rd' => $str($nb['rd'] ?? ''),
			'cap' => $str($nb['cap'] ?? ''),
			'lost' => $lost,
			'ts_delete' => $ts_delete,
			'remote_hostid' => null
		];
	}

	private function matchReports(array &$reports): void {
		$index = $this->getIndex();

		foreach ($reports as &$report) {
			$hostid = $index->find($report['rn'], $report['ra'], $report['rc']);

			if ($hostid !== null && $hostid !== $report['hostid']) {
				$report['remote_hostid'] = $hostid;

				if ($report['cap'] !== '') {
					$this->host_caps[$hostid] = $report['cap'];
				}
			}
		}
		unset($report);
	}

	/**
	 * Do two reports (one from each end) describe the same physical link?
	 */
	private static function sameLink(array $a, array $b): bool {
		foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
			foreach ([$x['rp'], $x['rpd']] as $remote_port) {
				if ($remote_port === '') {
					continue;
				}

				if (PortName::equals($remote_port, $y['lp']) || PortName::equals($remote_port, $y['lpid'])) {
					return true;
				}
			}
		}

		return false;
	}

	private static function sameLocalPort(array $a, array $b): bool {
		if ($a['li'] !== '' && $b['li'] !== '') {
			return $a['li'] === $b['li'];
		}

		return PortName::equals($a['lp'], $b['lp']);
	}

	/**
	 * Merges reports describing the same port (e.g. LLDP and CDP of the same neighbor).
	 */
	private static function dedupeSide(array $reports): array {
		$result = [];

		foreach ($reports as $report) {
			foreach ($result as &$existing) {
				if (self::sameLocalPort($existing, $report)) {
					$existing['protocols'][$report['proto']] = true;
					$existing['lost'] = $existing['lost'] && $report['lost'];

					foreach (['rport', 'rp', 'rpd', 'rd', 'rc', 'li', 'lpid'] as $field) {
						if ($existing[$field] === '' && $report[$field] !== '') {
							$existing[$field] = $report[$field];
						}
					}

					continue 2;
				}
			}
			unset($existing);

			$report['protocols'] = [$report['proto'] => true];
			$result[] = $report;
		}

		return $result;
	}

	private function addNeighborEdges(array $reports): void {
		$pairs = [];
		$external = [];

		foreach ($reports as $report) {
			if ($report['remote_hostid'] === null) {
				if ($report['rn'] !== '' || $report['rc'] !== '' || $report['ra']) {
					$external[self::externalKey($report)][$report['hostid']][] = $report;
				}

				continue;
			}

			if ((int) $report['hostid'] > (int) $report['remote_hostid']) {
				[$a, $b] = [$report['remote_hostid'], $report['hostid']];
			}
			else {
				[$a, $b] = [$report['hostid'], $report['remote_hostid']];
			}

			$side = $report['hostid'] === $a ? 'a' : 'b';
			$pairs[$a.'|'.$b][$side][] = $report;
		}

		// Host to host links: pair the report of each end.
		foreach ($pairs as $pair) {
			$left = self::dedupeSide($pair['a'] ?? []);
			$right = self::dedupeSide($pair['b'] ?? []);

			foreach ($left as $i => $l) {
				foreach ($right as $j => $r) {
					if (self::sameLink($l, $r)) {
						$this->addHostNeighborEdge($l, $r);
						unset($left[$i], $right[$j]);

						continue 2;
					}
				}
			}

			if (count($left) == 1 && count($right) == 1) {
				$this->addHostNeighborEdge(reset($left), reset($right));
				$left = [];
				$right = [];
			}

			foreach (array_merge($left, $right) as $report) {
				$this->addHostNeighborEdge($report, null);
			}
		}

		// Links to neighbors that are not Zabbix hosts.
		foreach ($external as $ext_key => $by_host) {
			$node_id = 'x'.substr(md5($ext_key), 0, 16);
			$all = array_merge(...array_values($by_host));
			$first = $all[0];

			$node = $this->ext_nodes[$node_id] ?? [
				'id' => $node_id,
				'kind' => 'ext',
				'name' => $first['rn'] !== '' ? $first['rn'] : ($first['rc'] !== '' ? $first['rc'] : $first['ra'][0]),
				'chassis' => '',
				'ip' => '',
				'desc' => '',
				'caps' => '',
				'protocols' => [],
				'seen_by' => [],
				'type' => 'device'
			];

			foreach ($all as $report) {
				foreach (['chassis' => 'rc', 'desc' => 'rd', 'caps' => 'cap'] as $field => $src) {
					if ($node[$field] === '' && $report[$src] !== '') {
						$node[$field] = $report[$src];
					}
				}

				if ($node['ip'] === '' && $report['ra']) {
					$node['ip'] = $report['ra'][0];
				}

				$node['protocols'][$report['proto']] = true;
			}

			$node['type'] = self::typeFromCaps($node['caps']) ?? 'device';

			foreach ($by_host as $hostid => $host_reports) {
				foreach (self::dedupeSide($host_reports) as $report) {
					$node['seen_by'][] = ['hostid' => (string) $hostid, 'port' => $report['lp']];

					$this->addEdge([
						'origin' => self::originOf($report['protocols']),
						'protocols' => array_keys($report['protocols']),
						'source' => 'h'.$hostid,
						'target' => $node_id,
						'source_hostid' => (string) $hostid,
						'target_hostid' => null,
						'source_port' => $report['lp'],
						'source_ifindex' => $report['li'],
						'target_port' => $report['rport'],
						'target_ifindex' => '',
						'lost' => $report['lost'],
						'ts_delete' => $report['ts_delete']
					]);
				}
			}

			$this->ext_nodes[$node_id] = $node;
		}
	}

	/**
	 * Stable identity of a neighbor that is not a Zabbix host: chassis MAC if known, else name/IP.
	 */
	private static function externalKey(array $report): string {
		$mac = PortName::normalizeMac($report['rc']);

		if ($mac !== '') {
			return 'mac:'.$mac;
		}

		if ($report['rc'] !== '') {
			return 'chassis:'.mb_strtolower($report['rc']);
		}

		return 'name:'.mb_strtolower($report['rn']).'|'.($report['ra'][0] ?? '');
	}

	private static function originOf(array $protocols): string {
		foreach (self::NEIGHBOR_PROTOCOLS as $proto) {
			if (array_key_exists($proto, $protocols)) {
				return $proto;
			}
		}

		return 'lldp';
	}

	private function addHostNeighborEdge(array $l, ?array $r): void {
		$protocols = $l['protocols'] + ($r['protocols'] ?? []);

		$this->addEdge([
			'origin' => self::originOf($protocols),
			'protocols' => array_keys($protocols),
			'source' => 'h'.$l['hostid'],
			'target' => 'h'.$l['remote_hostid'],
			'source_hostid' => $l['hostid'],
			'target_hostid' => $l['remote_hostid'],
			'source_port' => $l['lp'],
			'source_ifindex' => $l['li'],
			// The neighbor's own name for its port is the most accurate one.
			'target_port' => $r !== null ? $r['lp'] : $l['rport'],
			'target_ifindex' => $r !== null ? $r['li'] : '',
			// One end still sees the other: the link is not lost.
			'lost' => $l['lost'] && ($r === null || $r['lost']),
			'ts_delete' => max($l['ts_delete'], $r['ts_delete'] ?? 0)
		]);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Host tags, maps, dependencies
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Host tag "topology.link" = "NEIGHBOR | local port | remote port" (ports are optional).
	 * NEIGHBOR is a host name, visible name, IP address or alias of another host.
	 */
	private function addTagEdges(): void {
		foreach (array_keys($this->scope) as $hostid) {
			foreach ($this->hosts[$hostid]['tags'] as $tag) {
				if ($tag['tag'] !== self::TAG_LINK || trim($tag['value']) === '') {
					continue;
				}

				$parts = array_map('trim', explode('|', $tag['value'])) + ['', '', ''];
				[$neighbor, $local_port, $remote_port] = $parts;

				if ($neighbor === '') {
					continue;
				}

				$ips = filter_var($neighbor, FILTER_VALIDATE_IP) ? [$neighbor] : [];
				$remote_hostid = $this->getIndex()->find($neighbor, $ips);

				if ($remote_hostid === $hostid) {
					continue;
				}

				if ($remote_hostid !== null) {
					$target = 'h'.$remote_hostid;
				}
				else {
					$target = 'x'.substr(md5('tag:'.mb_strtolower($neighbor)), 0, 16);

					if (!array_key_exists($target, $this->ext_nodes)) {
						$this->ext_nodes[$target] = [
							'id' => $target,
							'kind' => 'ext',
							'name' => $neighbor,
							'chassis' => '',
							'ip' => $ips ? $ips[0] : '',
							'desc' => '',
							'caps' => '',
							'protocols' => ['tag' => true],
							'seen_by' => [],
							'type' => 'device'
						];
					}

					$this->ext_nodes[$target]['seen_by'][] = ['hostid' => $hostid, 'port' => $local_port];
				}

				$this->addEdge([
					'origin' => 'tag',
					'protocols' => ['tag'],
					'source' => 'h'.$hostid,
					'target' => $target,
					'source_hostid' => $hostid,
					'target_hostid' => $remote_hostid,
					'source_port' => $local_port,
					'source_ifindex' => '',
					'target_port' => $remote_port,
					'target_ifindex' => '',
					'lost' => false,
					'ts_delete' => 0
				]);
			}
		}
	}

	/**
	 * Loads selected maps: host-to-host links and the hosts placed on them.
	 */
	private function loadMapLinks(): array {
		$maps = API::Map()->get([
			'output' => ['sysmapid', 'name'],
			'sysmapids' => $this->options['mapids'],
			'selectSelements' => ['selementid', 'elementtype', 'elements'],
			'selectLinks' => API_OUTPUT_EXTEND
		]);

		$result = ['hostids' => [], 'links' => [], 'triggerids' => []];

		foreach ($maps as $map) {
			$selement_hosts = [];

			foreach ($map['selements'] as $selement) {
				if ($selement['elementtype'] == SYSMAP_ELEMENT_TYPE_HOST && $selement['elements']) {
					$hostid = (string) $selement['elements'][0]['hostid'];
					$selement_hosts[$selement['selementid']] = $hostid;
					$result['hostids'][$hostid] = true;
				}
			}

			foreach ($map['links'] as $link) {
				$h1 = $selement_hosts[$link['selementid1']] ?? null;
				$h2 = $selement_hosts[$link['selementid2']] ?? null;

				if ($h1 === null || $h2 === null || $h1 === $h2) {
					continue;
				}

				$triggerids = array_column($link['linktriggers'] ?? [], 'triggerid');

				foreach ($triggerids as $triggerid) {
					$result['triggerids'][$triggerid] = true;
				}

				$result['links'][] = [
					'mapname' => $map['name'],
					'h1' => $h1,
					'h2' => $h2,
					'label' => (string) ($link['label'] ?? ''),
					'triggerids' => $triggerids
				];
			}
		}

		return $result;
	}

	private function addMapEdges(array $map_links): void {
		$triggers = $map_links['triggerids']
			? API::Trigger()->get([
				'output' => ['triggerid', 'value', 'priority', 'description'],
				'triggerids' => array_keys($map_links['triggerids']),
				'expandDescription' => true,
				'monitored' => true,
				'preservekeys' => true
			])
			: [];

		foreach ($map_links['links'] as $link) {
			$status = $link['triggerids'] ? 'up' : 'unknown';
			$severity = -1;
			$problems = [];

			foreach ($link['triggerids'] as $triggerid) {
				if (!array_key_exists($triggerid, $triggers)) {
					continue;
				}

				$trigger = $triggers[$triggerid];

				if ($trigger['value'] == TRIGGER_VALUE_TRUE) {
					$status = 'down';
					$severity = max($severity, (int) $trigger['priority']);
					$problems[] = ['name' => $trigger['description'], 'severity' => (int) $trigger['priority']];
				}
			}

			// Labels with macros are only meaningful inside the map.
			$label = strpos($link['label'], '{') === false ? $link['label'] : '';

			$this->addEdge([
				'origin' => 'map',
				'protocols' => ['map'],
				'source' => 'h'.$link['h1'],
				'target' => 'h'.$link['h2'],
				'source_hostid' => $link['h1'],
				'target_hostid' => $link['h2'],
				'source_port' => '',
				'source_ifindex' => '',
				'target_port' => '',
				'target_ifindex' => '',
				'lost' => false,
				'ts_delete' => 0,
				'status' => $status,
				'severity' => $severity,
				'problems' => $problems,
				'label' => $label,
				'map' => $link['mapname']
			]);
		}
	}

	/**
	 * Trigger dependencies: "A depends on B" becomes a logical link B -> A (B is upstream).
	 */
	private function addDependencyEdges(): void {
		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'hostids' => array_keys($this->scope),
			'selectHosts' => ['hostid'],
			'selectDependencies' => ['triggerid'],
			'monitored' => true,
			'preservekeys' => true
		]);

		$dependencies = [];

		foreach ($triggers as $trigger) {
			foreach ($trigger['dependencies'] as $dependency) {
				$dependencies[$dependency['triggerid']] = true;
			}
		}

		if (!$dependencies) {
			return;
		}

		$masters = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => array_keys($dependencies),
			'selectHosts' => ['hostid'],
			'preservekeys' => true
		]);

		$pairs = [];

		foreach ($triggers as $trigger) {
			foreach ($trigger['dependencies'] as $dependency) {
				if (!array_key_exists($dependency['triggerid'], $masters)) {
					continue;
				}

				foreach ($trigger['hosts'] as $child) {
					foreach ($masters[$dependency['triggerid']]['hosts'] as $master) {
						if ($child['hostid'] != $master['hostid']) {
							$pairs[$master['hostid'].'|'.$child['hostid']] = [(string) $master['hostid'],
								(string) $child['hostid']
							];
						}
					}
				}
			}
		}

		foreach ($pairs as [$master, $child]) {
			$this->addEdge([
				'origin' => 'dep',
				'protocols' => ['dep'],
				'source' => 'h'.$master,
				'target' => 'h'.$child,
				'source_hostid' => $master,
				'target_hostid' => $child,
				'source_port' => '',
				'source_ifindex' => '',
				'target_port' => '',
				'target_ifindex' => '',
				'lost' => false,
				'ts_delete' => 0,
				'status' => 'logical'
			]);
		}
	}

	/**
	 * Adds an edge. A link found by several sources between the same two nodes is kept once:
	 * a port-less link (map, tag without ports, dependency) is merged into an existing one.
	 */
	private function addEdge(array $edge): void {
		$edge += [
			'status' => null,
			'severity' => -1,
			'problems' => [],
			'label' => '',
			'map' => '',
			'traffic' => null
		];

		foreach ($this->edges as &$existing) {
			$same_nodes = ($existing['source'] === $edge['source'] && $existing['target'] === $edge['target'])
				|| ($existing['source'] === $edge['target'] && $existing['target'] === $edge['source']);

			if (!$same_nodes) {
				continue;
			}

			$reversed = $existing['source'] !== $edge['source'];
			$edge_ports = $reversed
				? [$edge['target_port'], $edge['source_port']]
				: [$edge['source_port'], $edge['target_port']];

			$ports_match = ($edge_ports[0] === '' && $edge_ports[1] === '')
				|| (PortName::equals($existing['source_port'], $edge_ports[0])
					&& ($edge_ports[1] === '' || PortName::equals($existing['target_port'], $edge_ports[1])));

			if ($ports_match && $edge['origin'] !== 'dep') {
				$existing['protocols'] = array_values(array_unique(array_merge($existing['protocols'],
					$edge['protocols']
				)));

				if ($edge['origin'] === 'map') {
					$existing['map'] = $edge['map'];
					$existing['label'] = $existing['label'] !== '' ? $existing['label'] : $edge['label'];

					if ($edge['status'] === 'down') {
						$existing['problems'] = array_merge($existing['problems'], $edge['problems']);
						$existing['severity'] = max($existing['severity'], $edge['severity']);
						$existing['map_down'] = true;
					}
				}

				return;
			}
		}
		unset($existing);

		$ends = [$edge['source'].':'.PortName::normalize($edge['source_port']),
			$edge['target'].':'.PortName::normalize($edge['target_port'])
		];
		sort($ends);

		// Stable IDs let the page update links in place on refresh.
		$id = 'e'.substr(md5($edge['origin'].'|'.implode('|', $ends)), 0, 16);

		for ($suffix = 2; array_key_exists($id, $this->edges); $suffix++) {
			$id = 'e'.substr(md5($edge['origin'].'|'.implode('|', $ends).'|'.$suffix), 0, 16);
		}

		$edge['id'] = $id;
		$this->edges[$id] = $edge;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Problems and port status
	 * ---------------------------------------------------------------------------------------------
	 */

	private function loadProblems(): void {
		if (!$this->hosts) {
			return;
		}

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity', 'name', 'clock', 'acknowledged'],
			'hostids' => array_keys($this->hosts),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => false,
			'symptom' => false,
			'selectTags' => ['tag', 'value'],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
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
			if (!array_key_exists($problem['objectid'], $triggers)) {
				continue;
			}

			$entry = [
				'eventid' => $problem['eventid'],
				'name' => $problem['name'],
				'severity' => (int) $problem['severity'],
				'clock' => (int) $problem['clock'],
				'acknowledged' => (int) $problem['acknowledged']
			];

			$ports = [];

			foreach ($problem['tags'] as $tag) {
				if (in_array($tag['tag'], ['interface', 'nt.local'], true) && $tag['value'] !== '') {
					$ports[PortName::normalize($tag['value'])] = true;
				}
			}

			foreach ($triggers[$problem['objectid']]['hosts'] as $host) {
				$hostid = (string) $host['hostid'];

				if (!array_key_exists($hostid, $this->hosts)) {
					continue;
				}

				$this->host_problems[$hostid][] = $entry;

				foreach (array_keys($ports) as $port) {
					$this->port_problems[$hostid][$port][] = $entry;
				}
			}
		}
	}

	/**
	 * Operational status and traffic of the ports at both ends of each link.
	 */
	private function resolvePorts(): void {
		// Port requests: "hostid|ifindex|name" => [hostid, ifindex, name].
		$ports = [];

		foreach ($this->edges as $edge) {
			if (!in_array($edge['origin'], ['lldp', 'cdp', 'tag'], true)) {
				continue;
			}

			foreach (['source', 'target'] as $end) {
				$hostid = $edge[$end.'_hostid'];

				if ($hostid === null || ($edge[$end.'_ifindex'] === '' && $edge[$end.'_port'] === '')) {
					continue;
				}

				$ports[self::portKey($hostid, $edge[$end.'_ifindex'], $edge[$end.'_port'])] = [
					'hostid' => $hostid,
					'ifindex' => $edge[$end.'_ifindex'],
					'name' => $edge[$end.'_port']
				];
			}
		}

		$port_items = $ports ? $this->findPortItems($ports) : [];
		$values = $this->getLastValues($port_items);

		foreach ($this->edges as &$edge) {
			$ends = [];

			foreach (['source', 'target'] as $end) {
				$hostid = $edge[$end.'_hostid'];
				$port_key = $hostid !== null
					? self::portKey($hostid, $edge[$end.'_ifindex'], $edge[$end.'_port'])
					: null;
				$items = $port_key !== null ? ($port_items[$port_key] ?? []) : [];

				$ends[$end] = [
					'oper' => isset($items['status']) ? ($values[$items['status']['itemid']] ?? null) : null,
					'in' => isset($items['in']) ? self::toBps($values[$items['in']['itemid']] ?? null, $items['in']) : null,
					'out' => isset($items['out']) ? self::toBps($values[$items['out']['itemid']] ?? null, $items['out']) : null,
					'speed' => isset($items['speed'])
						? self::toBps($values[$items['speed']['itemid']] ?? null, $items['speed'])
						: null,
					'problems' => ($hostid !== null && $edge[$end.'_port'] !== '')
						? ($this->port_problems[$hostid][PortName::normalize($edge[$end.'_port'])] ?? [])
						: []
				];
			}

			// Problems tagged with the interface name of either end.
			foreach ($ends as $info) {
				foreach ($info['problems'] as $problem) {
					$edge['problems'][] = ['name' => $problem['name'], 'severity' => $problem['severity']];
					$edge['severity'] = max($edge['severity'], $problem['severity']);
				}
			}

			$edge['source_oper'] = $ends['source']['oper'] !== null ? (int) $ends['source']['oper'] : null;
			$edge['target_oper'] = $ends['target']['oper'] !== null ? (int) $ends['target']['oper'] : null;

			if ($edge['status'] === null) {
				$edge['status'] = self::linkStatus($edge);
			}

			// Traffic as seen from the source end ("in" = towards the source host).
			$t = null;

			if ($ends['source']['in'] !== null || $ends['source']['out'] !== null) {
				$t = ['in' => $ends['source']['in'], 'out' => $ends['source']['out'], 'speed' => $ends['source']['speed']];
			}
			elseif ($ends['target']['in'] !== null || $ends['target']['out'] !== null) {
				$t = ['in' => $ends['target']['out'], 'out' => $ends['target']['in'], 'speed' => $ends['target']['speed']];
			}

			if ($t !== null) {
				if ($t['speed'] === null) {
					$t['speed'] = $ends['target']['speed'] ?? null;
				}

				$t['util'] = ($t['speed'] !== null && $t['speed'] > 0)
					? round(max($t['in'] ?? 0, $t['out'] ?? 0) * 100 / $t['speed'], 1)
					: null;
			}

			$edge['traffic'] = $t;
		}
		unset($edge);
	}

	private static function linkStatus(array $edge): string {
		if ($edge['lost'] || !empty($edge['map_down'])) {
			return 'down';
		}

		$opers = array_filter([$edge['source_oper'], $edge['target_oper']], static fn($v) => $v !== null);

		foreach ($opers as $oper) {
			// down(2), notPresent(6), lowerLayerDown(7)
			if (in_array($oper, [2, 6, 7], true)) {
				return 'down';
			}
		}

		if (in_array(1, $opers, true)) {
			return 'up';
		}

		// A neighbor currently seen by LLDP/CDP means the link is up.
		if (in_array($edge['origin'], ['lldp', 'cdp'], true)) {
			return 'up';
		}

		return 'unknown';
	}

	private static function portKey(string $hostid, string $ifindex, string $name): string {
		return $hostid.'|'.$ifindex.'|'.PortName::normalize($name);
	}

	/**
	 * Finds status/in/out/speed items of the requested ports.
	 * 1) by item key built from the SNMP index (official templates);
	 * 2) for the remaining ports, by the item tag "interface" equal to the port name.
	 *
	 * @return array  port key => ['status' => item, 'in' => item, 'out' => item, 'speed' => item]
	 */
	private function findPortItems(array $ports): array {
		$want_traffic = (bool) $this->options['traffic'];
		$kinds = $want_traffic ? ['status', 'in', 'out', 'speed'] : ['status'];
		$result = [];

		// 1) Item keys by SNMP index.
		$key_lookup = [];

		foreach ($ports as $port_key => $port) {
			if ($port['ifindex'] === '' || !ctype_digit($port['ifindex'])) {
				continue;
			}

			foreach ($kinds as $kind) {
				foreach (self::PORT_ITEM_KEYS[$kind] as $priority => $pattern) {
					$key = str_replace('{IFINDEX}', $port['ifindex'], $pattern);
					$key_lookup[$port['hostid']][$key] = [$port_key, $kind, $priority];
				}
			}
		}

		if ($key_lookup) {
			$all_keys = [];

			foreach ($key_lookup as $keys) {
				$all_keys += $keys;
			}

			foreach (array_chunk(array_keys($all_keys), 500) as $keys_chunk) {
				$items = API::Item()->get([
					'output' => ['itemid', 'hostid', 'key_', 'value_type', 'units'],
					'hostids' => array_keys($key_lookup),
					'filter' => ['key_' => $keys_chunk],
					'monitored' => true
				]);

				foreach ($items as $item) {
					$hostid = (string) $item['hostid'];

					if (!isset($key_lookup[$hostid][$item['key_']])) {
						continue;
					}

					[$port_key, $kind, $priority] = $key_lookup[$hostid][$item['key_']];

					if (!isset($result[$port_key][$kind]) || $result[$port_key][$kind]['priority'] > $priority) {
						$result[$port_key][$kind] = $item + ['priority' => $priority];
					}
				}
			}
		}

		// 2) Item tag "interface" (templates with other keys, Zabbix agent interfaces...).
		$by_name = [];

		foreach ($ports as $port_key => $port) {
			if ($port['name'] !== '' && !isset($result[$port_key]['status'])) {
				$by_name[$port['hostid']][$port['name']] = $port_key;
			}
		}

		if ($by_name) {
			$names = [];

			foreach ($by_name as $host_names) {
				foreach (array_keys($host_names) as $name) {
					$names[$name] = true;
				}
			}

			foreach (array_chunk(array_keys($names), 100) as $names_chunk) {
				$tags = [];

				foreach ($names_chunk as $name) {
					$tags[] = ['tag' => 'interface', 'value' => $name, 'operator' => TAG_OPERATOR_EQUAL];
				}

				$items = API::Item()->get([
					'output' => ['itemid', 'hostid', 'key_', 'value_type', 'units'],
					'hostids' => array_keys($by_name),
					'evaltype' => TAG_EVAL_TYPE_OR,
					'tags' => $tags,
					'selectTags' => ['tag', 'value'],
					'monitored' => true
				]);

				foreach ($items as $item) {
					$kind = null;

					foreach ($kinds as $candidate) {
						if (strncmp($item['key_'], 'net.if.'.$candidate.'[', strlen('net.if.'.$candidate.'[')) == 0) {
							$kind = $candidate;
							break;
						}
					}

					if ($kind === null) {
						continue;
					}

					foreach ($item['tags'] as $tag) {
						$port_key = $tag['tag'] === 'interface'
							? ($by_name[(string) $item['hostid']][$tag['value']] ?? null)
							: null;

						if ($port_key !== null && !isset($result[$port_key][$kind])) {
							$result[$port_key][$kind] = $item + ['priority' => 99];
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @return array  itemid => last value
	 */
	private function getLastValues(array $port_items): array {
		$items = [];

		foreach ($port_items as $kinds) {
			foreach ($kinds as $item) {
				$items[$item['itemid']] = ['itemid' => $item['itemid'], 'value_type' => $item['value_type']];
			}
		}

		if (!$items) {
			return [];
		}

		$period = timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		$history = Manager::History()->getLastValues(array_values($items), 1, $period);
		$values = [];

		foreach ($history as $itemid => $rows) {
			if ($rows) {
				$values[$itemid] = $rows[0]['value'];
			}
		}

		return $values;
	}

	private static function toBps($value, array $item): ?float {
		if ($value === null || !is_numeric($value)) {
			return null;
		}

		$value = (float) $value;
		$units = $item['units'] ?? '';

		// ifSpeed is a 32-bit gauge: 4294967295 means "faster than that" (10G+), not a real speed.
		if (strncmp($item['key_'] ?? '', 'net.if.speed[ifSpeed.', 21) == 0 && $value >= 4294967295) {
			return null;
		}

		// Byte based counters are converted to bits.
		if ($units === 'B' || $units === 'Bps' || $units === 'B/s') {
			$value *= 8;
		}

		return $value;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Output
	 * ---------------------------------------------------------------------------------------------
	 */

	private function result(): array {
		$nodes = [];

		foreach ($this->hosts as $hostid => $host) {
			$problems = $this->host_problems[$hostid] ?? [];
			usort($problems, static fn($a, $b) => [$b['severity'], $b['clock']] <=> [$a['severity'], $a['clock']]);

			$nodes[] = [
				'id' => 'h'.$hostid,
				'kind' => 'host',
				'hostid' => (string) $hostid,
				'name' => $host['name'],
				'host' => $host['host'],
				'ip' => self::mainAddress($host['interfaces']),
				'type' => $this->deviceType($host),
				'level' => self::tagValue($host['tags'], self::TAG_LEVEL),
				'role' => self::roleFromTemplates($host['parentTemplates'] ?? []),
				'in_scope' => array_key_exists($hostid, $this->scope),
				'maintenance' => $host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON,
				'unavailable' => self::isUnavailable($host['interfaces']),
				'severity' => $problems ? max(array_column($problems, 'severity')) : -1,
				'problem_count' => count($problems),
				'problems' => array_slice($problems, 0, 5)
			];
		}

		foreach ($this->ext_nodes as $node) {
			$node['protocols'] = array_keys($node['protocols']);
			$node['seen_by'] = array_slice($node['seen_by'], 0, 10);

			foreach ($node['seen_by'] as &$seen) {
				$seen['name'] = $this->hosts[$seen['hostid']]['name'] ?? $seen['hostid'];
			}
			unset($seen);

			$nodes[] = $node;
		}

		$edges = [];
		$down = 0;

		foreach ($this->edges as $edge) {
			if ($edge['status'] === 'down') {
				$down++;
			}

			$edges[] = [
				'id' => $edge['id'],
				'source' => $edge['source'],
				'target' => $edge['target'],
				'origin' => $edge['origin'],
				'protocols' => $edge['protocols'],
				'source_port' => $edge['source_port'],
				'target_port' => $edge['target_port'],
				'status' => $edge['status'] ?? 'unknown',
				'lost' => $edge['lost'],
				'ts_delete' => $edge['ts_delete'],
				'severity' => $edge['severity'],
				'problems' => array_slice($edge['problems'], 0, 5),
				'source_oper' => $edge['source_oper'] ?? null,
				'target_oper' => $edge['target_oper'] ?? null,
				'traffic' => $edge['traffic'],
				'label' => $edge['label'],
				'map' => $edge['map']
			];
		}

		return [
			'nodes' => $nodes,
			'edges' => $edges,
			'warnings' => array_values(array_unique($this->warnings)),
			'stats' => [
				'hosts' => count($this->hosts),
				'neighbors' => count($this->ext_nodes),
				'links' => count($edges),
				'down' => $down
			],
			'time' => time()
		];
	}

	/**
	 * Role of the capacity planning templates (Backbone / Distribuicao / CPE): the "Layers" layout puts the
	 * backbone on top, distribution in the middle and CPEs at the bottom.
	 */
	private static function roleFromTemplates(array $templates): ?string {
		foreach ($templates as $template) {
			$name = mb_strtolower($template['name']);

			if (strpos($name, 'capacity planning - ') === false) {
				continue;
			}

			if (strpos($name, '- backbone') !== false) {
				return 'backbone';
			}

			if (strpos($name, '- distribui') !== false) {
				return 'distribuicao';
			}

			if (strpos($name, '- cpe') !== false) {
				return 'cpe';
			}
		}

		return null;
	}

	private static function mainAddress(array $interfaces): string {
		usort($interfaces, static fn($a, $b) =>
			[$b['main'], $b['type'] == INTERFACE_TYPE_SNMP] <=> [$a['main'], $a['type'] == INTERFACE_TYPE_SNMP]
		);

		foreach ($interfaces as $interface) {
			$address = $interface['useip'] == INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'];

			if ($address !== '') {
				return $address;
			}
		}

		return '';
	}

	private static function isUnavailable(array $interfaces): bool {
		if (!$interfaces) {
			return false;
		}

		foreach ($interfaces as $interface) {
			if ($interface['available'] != INTERFACE_AVAILABLE_FALSE) {
				return false;
			}
		}

		return true;
	}

	private static function tagValue(array $tags, string $name): ?string {
		foreach ($tags as $tag) {
			if ($tag['tag'] === $name && $tag['value'] !== '') {
				return $tag['value'];
			}
		}

		return null;
	}

	private static function typeFromCaps(string $caps): ?string {
		if ($caps === '') {
			return null;
		}

		$caps = explode(',', $caps);

		foreach (['router' => 'router', 'wlan-ap' => 'ap', 'telephone' => 'phone', 'bridge' => 'switch',
				'docsis' => 'cpe', 'station' => 'server'] as $cap => $type) {
			if (in_array($cap, $caps, true)) {
				return $type;
			}
		}

		return null;
	}

	private static function typeFromText(string $text): ?string {
		$text = mb_strtolower($text);
		$b = '(^|[^a-z])';
		$e = '\d*([^a-z]|$)';

		$rules = [
			'firewall' => $b.'(fw|firewall|fortigate|forti|pfsense|opnsense|paloalto|asa|checkpoint|sophos)'.$e,
			'olt' => $b.'olt'.$e,
			'cpe' => $b.'(cpe|onu|ont|modem)'.$e,
			'ap' => $b.'(ap|wap|wifi|wlan|unifi|aironet)'.$e,
			'switch' => $b.'(sw|swi|switch|asw|dsw|csw|agg|acc|swm|sfp)'.$e,
			'router' => $b.'(rt|rtr|router|pe|ce|bng|bras|core|gw|edge|asr|ccr|ne|mx|cr|bgp|mpls)'.$e,
			'server' => $b.'(srv|server|vm|esx|esxi|linux|windows|win|db|sql|app|web|proxy|dns)'.$e,
			'storage' => $b.'(nas|san|storage|stor)'.$e,
			'ups' => $b.'(ups|nobreak)'.$e,
			'printer' => $b.'(printer|prn|impressora)'.$e,
			'camera' => $b.'(cam|camera|nvr|dvr)'.$e
		];

		foreach ($rules as $type => $pattern) {
			if (preg_match('/'.$pattern.'/', $text)) {
				return $type;
			}
		}

		return null;
	}

	private function deviceType(array $host): string {
		$tag = self::tagValue($host['tags'], self::TAG_TYPE);

		if ($tag !== null && in_array(mb_strtolower($tag), self::DEVICE_TYPES, true)) {
			return mb_strtolower($tag);
		}

		if (is_array($host['inventory']) && ($host['inventory']['type'] ?? '') !== '') {
			$type = self::typeFromText($host['inventory']['type']);

			if ($type !== null) {
				return $type;
			}
		}

		// The capacity planning CPE profile says what the device is better than its LLDP capabilities (a CPE is
		// often a router).
		foreach ($host['parentTemplates'] ?? [] as $template) {
			if (preg_match('/capacity planning\s*-\s*cpe$/i', $template['name'])) {
				return 'cpe';
			}
		}

		$type = self::typeFromCaps($this->host_caps[$host['hostid']] ?? '');

		if ($type !== null) {
			return $type;
		}

		$type = self::typeFromText($host['host'].' '.$host['name']);

		if ($type !== null) {
			return $type;
		}

		foreach ($host['parentTemplates'] ?? [] as $template) {
			$type = self::typeFromText($template['name']);

			if ($type !== null) {
				return $type;
			}
		}

		foreach ($host['interfaces'] as $interface) {
			if ($interface['type'] == INTERFACE_TYPE_SNMP) {
				return 'router';
			}
		}

		return 'server';
	}

	/**
	 * Severity names and colors as configured in Administration > General > Trigger displaying options.
	 */
	public static function severities(): array {
		$result = [];

		for ($severity = TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity < TRIGGER_SEVERITY_COUNT; $severity++) {
			$result[] = [
				'severity' => $severity,
				'name' => CSeverityHelper::getName($severity),
				'color' => '#'.CSeverityHelper::getColor($severity)
			];
		}

		return $result;
	}
}
