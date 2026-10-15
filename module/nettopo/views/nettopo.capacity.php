<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix - page "Capacity planning".
**
** @var CView $this
** @var array $data
**/

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n;

$this->addCssFile($data['assets_path'].'/css/nettopo.css?v='.urlencode($data['version']));

$capacity = $data['capacity'];
$interfaces = $capacity['interfaces'];
$hosts = $capacity['hosts'];
$severities = array_column($data['severities'], null, 'severity');

$segment_names = [
	'acesso' => I18n::t('Last mile'),
	'distribuicao' => I18n::t('Distribution'),
	'backbone' => I18n::t('Backbone')
];
$segment_hints = [
	'acesso' => I18n::t('CPE / access'),
	'distribuicao' => I18n::t('Aggregation / metro'),
	'backbone' => I18n::t('Core / trunks')
];
$role_names = [
	'cpe' => 'CPE',
	'distribuicao' => I18n::t('Distribution'),
	'backbone' => I18n::t('Backbone'),
	'' => I18n::t('No role')
];

$base_url = (new CUrl('zabbix.php'))->setArgument('action', 'nettopo.capacity');

$fmt_pct = static function(?float $value): string {
	if ($value === null) {
		return '—';
	}

	return ($value < 10 ? number_format($value, 1) : number_format($value, 0)).'%';
};

$fmt_bps = static fn(?float $value): string => $value === null
	? '—'
	: convertUnits(['value' => $value, 'units' => 'bps']);

$fmt_days = static function(?float $days): string {
	if ($days === null) {
		return '—';
	}

	if ($days < 1 / 24) {
		return I18n::t('at the limit now');
	}

	if ($days < 1) {
		return I18n::t('in %1$s h', max(1, (int) round($days * 24)));
	}

	return I18n::t('in %1$s days', $days < 10 ? number_format($days, 1) : (int) round($days));
};

$severity_color = static fn(int $severity): ?string => $severity >= 0
	? ($severities[$severity]['color'] ?? null)
	: null;

$circuit_url = static fn(string $cid): string => (new CUrl('zabbix.php'))
	->setArgument('action', 'nettopo.capacity')
	->setArgument('circuit', $cid)
	->getUrl();

$map_url = static fn(string $cid): string => (new CUrl('zabbix.php'))
	->setArgument('action', 'nettopo.view')
	->setArgument('circuit', $cid)
	->setArgument('capacity', 1)
	->getUrl();

// Utilization bar: colored by the most severe capacity problem (Warning or above), green otherwise.
$bar = static function(?float $load, int $severity, bool $down = false) use ($severity_color, $fmt_pct): CDiv {
	$color = $severity >= TRIGGER_SEVERITY_WARNING ? $severity_color($severity) : null;
	$fill = (new CDiv())
		->addClass('nt-cap-bar-fill')
		->addStyle('width: '.($load === null ? 0 : min(100, max(0, $load))).'%;'.($color !== null ? ' background: '.$color.';' : ''));

	return (new CDiv([
		(new CDiv($fill))->addClass('nt-cap-bar-track'),
		(new CSpan($down ? 'DOWN' : $fmt_pct($load)))->addClass('nt-cap-bar-value')
	]))
		->addClass('nt-cap-bar')
		->addClass($down ? 'nt-cap-down' : null)
		->addClass($load === null ? 'nt-cap-nodata' : null);
};

$kind_label = static fn(?string $kind): string => $kind === 'p95' ? 'p95' : I18n::t('now');

// Points of the path (logical structure CORE (LAN) / UPLINK - POP - CLIENT).
$hop_names = [
	'cpe' => I18n::t('CPE WAN'),
	'pop_client' => I18n::t('POP port to the client'),
	'core_client' => I18n::t('core port to the client'),
	'pop_uplink' => I18n::t('POP uplink'),
	'agg_uplink' => I18n::t('regional aggregation'),
	'core_lan' => I18n::t('core LAN interface'),
	'core_trunk' => I18n::t('core trunk')
];

$criteria_names = [
	'util' => I18n::t('highest utilization'),
	'free' => I18n::t('lowest free capacity'),
	'days' => I18n::t('saturates first'),
	'impact' => I18n::t('most circuits affected')
];

$source_names = [
	'descricao' => I18n::t('interface description'),
	'netbox' => 'NetBox',
	'macro' => I18n::t('host macro {$CIRCUIT_ID}')
];

$state_names = [
	'ok' => I18n::t('OK'),
	'warning' => I18n::t('Attention'),
	'critical' => I18n::t('Critical'),
	'down' => I18n::t('Down'),
	'nodata' => I18n::t('No data')
];

$fmt_trend = static function(?float $points): string {
	if ($points === null) {
		return '—';
	}

	if (abs($points) < 1) {
		return I18n::t('stable');
	}

	// A step (new contracted bandwidth, traffic moved) makes a huge slope: more than 100 points is "100+".
	$shown = min(100, abs($points));

	return ($points > 0 ? '↑ +' : '↓ −').number_format($shown, $shown < 10 ? 1 : 0).($shown >= 100 ? '+' : '').' '
		.I18n::t('p.p.');
};

// Situation of a circuit (bands 60/80/90 %, role limits and forecast).
$situation_names = [
	'critical' => I18n::t('Critical'),
	'high' => I18n::t('High'),
	'medium' => I18n::t('Medium'),
	'low' => I18n::t('Normal'),
	'nodata' => I18n::t('No data')
];

$situation_tag = static fn(string $situation): CSpan => (new CSpan($situation_names[$situation] ?? $situation))
	->addClass('nt-cap-sit')
	->addClass('nt-cap-sit-'.$situation);

// Monthly growth of the p95: relative to today's p95 and in bit/s ("+7.2% a month (+70 Mbps)").
$fmt_growth = static function(?float $points, ?float $base, ?float $bw) use ($fmt_bps): string {
	if ($points === null) {
		return '—';
	}

	if (abs($points) < 0.5) {
		return I18n::t('stable');
	}

	$sign = $points > 0 ? '+' : '−';
	$bps = $bw !== null ? $sign.$fmt_bps(abs($points) * $bw / 100) : null;

	if ($base === null || $base < 1) {
		return $bps !== null ? I18n::t('%1$s a month', $bps) : '—';
	}

	// A step (new contracted bandwidth, traffic moved) makes a huge slope: "more than +999%".
	$relative = min(999, abs($points) / $base * 100);
	$main = $sign.number_format($relative, $relative < 10 ? 1 : 0).'%';
	$main = $relative >= 999 ? I18n::t('more than %1$s', $main) : $main;

	return $bps !== null ? I18n::t('%1$s a month (%2$s)', $main, $bps) : I18n::t('%1$s a month', $main);
};

// Client/site of a circuit: the description of the provider-side port, then the client device.
$client_cell = static function(array $circuit): array {
	$site = $circuit['site'] ?? '';
	$client = $circuit['client'] ?? '';

	if ($site === '' && $client === '') {
		return [(new CSpan('—'))->addClass('nt-cap-muted')];
	}

	return [
		$site !== '' ? $site : $client,
		$site !== '' && $client !== '' && mb_strtolower($client) !== mb_strtolower($site)
			? (new CDiv($client))->addClass('nt-cap-small')
			: null
	];
};

$votes_label = static fn(array $circuit): string => $circuit['bottleneck'] !== null
	? I18n::t('%1$s of %2$s criteria', $circuit['votes'][$circuit['bottleneck']], count(CapacityBuilder::CRITERIA))
	: '';

// "CID: CPE · DIS · BB" with the roles where the circuit ID is written.
$coverage_tag = static function(array $circuit): CSpan {
	$labels = ['cpe' => 'CPE', 'distribuicao' => 'DIS', 'backbone' => 'BB'];
	$items = [];

	foreach ($labels as $role => $label) {
		$items[] = (new CSpan($label))->addClass($circuit['coverage'][$role] ? 'nt-cap-cov-yes' : 'nt-cap-cov-no');
	}

	$missing = array_keys(array_filter($circuit['coverage'], static fn($v) => !$v));

	return (new CSpan(array_merge(['CID '], $items)))
		->addClass('nt-cap-cov')
		->setTitle($missing
			? I18n::t('The circuit ID is missing on: %1$s (found by LLDP/CDP or not monitored).',
				implode(', ', array_map(static fn($r) => $labels[$r], $missing)))
			: I18n::t('The circuit ID is written on the three roles.'));
};

$iface_label = static function(string $id) use ($interfaces): string {
	$interface = $interfaces[$id];

	return $interface['host'].' · '.$interface['name'];
};

$segment_cell = static function(array $circuit, string $segment) use ($bar, $interfaces, $iface_label,
		$kind_label): CCol {
	$s = $circuit['segments'][$segment];

	if (!$s['interfaces']) {
		return (new CCol((new CSpan('—'))->addClass('nt-cap-muted')))->addClass('nt-cap-segcell');
	}

	$detail = $s['interface'] !== null
		? $iface_label($s['interface'])
		: I18n::t('collecting data');

	if (count($s['interfaces']) > 1) {
		$detail .= ' '.I18n::t('(+%1$s)', count($s['interfaces']) - 1);
	}

	$cell = [
		$bar($s['load'], $s['severity'], $s['down']),
		(new CDiv([$detail, $s['kind'] !== null ? ' · '.$kind_label($s['kind']) : '']))->addClass('nt-cap-small')
	];

	return (new CCol($cell))
		->addClass('nt-cap-segcell')
		->addClass($circuit['bottleneck'] === $segment ? 'nt-cap-is-bottleneck' : null);
};

$bottleneck_badge = static function(array $circuit) use ($segment_names, $fmt_pct, $severity_color): CTag {
	if ($circuit['down']) {
		return (new CSpan(I18n::t('Link down')))->addClass('nt-cap-badge')->addClass('nt-cap-badge-down');
	}

	if ($circuit['bottleneck'] === null) {
		return (new CSpan(I18n::t('No data yet')))->addClass('nt-cap-badge')->addClass('nt-cap-badge-nodata');
	}

	$severity = $circuit['segments'][$circuit['bottleneck']]['severity'];
	$color = $severity >= TRIGGER_SEVERITY_WARNING ? $severity_color($severity) : null;

	return (new CSpan($segment_names[$circuit['bottleneck']].' '.$fmt_pct($circuit['load'])))
		->addClass('nt-cap-badge')
		->addStyle($color !== null ? 'background: '.$color.'; color: #fff;' : '');
};

$html_page = (new CHtmlPage())->setTitle(I18n::t('Capacity planning'));

/*
 * ---------------------------------------------------------------------------------------------------------
 * Detail of one circuit
 * ---------------------------------------------------------------------------------------------------------
 */
if ($data['circuit'] !== '') {
	$cid = $data['circuit'];
	$circuit = $capacity['circuits'][$cid] ?? null;

	$html_page->setTitle(I18n::t('Circuit %1$s', $cid));
	$html_page->setNavigation((new CList())->addItem(
		new CLink('← '.I18n::t('All circuits'), $base_url->getUrl())
	)->addClass('nt-cap-nav'));

	if ($circuit === null) {
		$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], I18n::t('Circuit %1$s not found (no interface with this ID in its description).', $cid)));
		$html_page->show();

		return;
	}

	// A link down on the path comes before any capacity consideration.
	if ($circuit['down']) {
		$down = [];

		foreach ($circuit['interfaces'] as $id => $segment) {
			if (CapacityBuilder::isDown($interfaces[$id])) {
				$down[] = $iface_label($id);
			}
		}

		$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [],
			I18n::t('Link down on the path: %1$s.', implode(', ', $down)), false
		));
	}

	// Verdict.
	if ($circuit['bottleneck'] !== null) {
		$s = $circuit['segments'][$circuit['bottleneck']];
		$won = [];

		foreach ($circuit['criteria'] as $criterion => $winners) {
			if (in_array($circuit['bottleneck'], $winners, true)) {
				$won[] = $criteria_names[$criterion];
			}
		}

		$verdict = [
			(new CSpan(I18n::t('Bottleneck: %1$s', mb_strtolower($segment_names[$circuit['bottleneck']]))))
				->addClass('nt-cap-verdict-main'),
			' — '.$iface_label($s['interface']).' ',
			I18n::t('at %1$s (%2$s)', $fmt_pct($s['load']), $kind_label($s['kind'])),
			$circuit['headroom'] !== null
				? ', '.I18n::t('%1$s free of %2$s', $fmt_bps($circuit['headroom']), $fmt_bps($circuit['capacity']))
				: '',
			'.',
			(new CDiv($won
				? I18n::t('Chosen by %1$s: %2$s.', $votes_label($circuit), implode(', ', $won))
				: I18n::t('Chosen by the highest utilization.')
			))->addClass('nt-cap-small')
		];

		if ($circuit['forecast'] !== null) {
			$verdict[] = ' '.I18n::t('Forecast: %1$s reaches its critical limit %2$s.',
				$iface_label($circuit['forecast']['interface']), $fmt_days($circuit['forecast']['days'])
			);
		}
	}
	else {
		$verdict = [I18n::t('No utilization data yet: the first values arrive after two polls of the interfaces.')];
	}

	$actions = (new CDiv([
		new CLink(I18n::t('Show on the topology map'), $map_url($cid))
	]))->addClass('nt-cap-actions');

	if ($data['can_create_dashboard']) {
		$actions->addItem(
			(new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'nettopo.capacity.dashboard')->getUrl()))
				->addVar('circuit', $cid)
				->addVar(CSRF_TOKEN_NAME, $data['dashboard_csrf'])
				->addItem((new CSubmit('dashboard', I18n::t('Create / update dashboard')))->addClass(ZBX_STYLE_BTN_ALT))
				->addClass('nt-cap-inline-form')
		);
	}

	$html_page->addItem(
		(new CDiv([
			(new CDiv($verdict))->addClass('nt-cap-verdict'),
			$actions
		]))->addClass('nt-cap-header')
	);

	// Contracted bandwidth and where the circuit ID is written.
	$role_labels = ['cpe' => 'CPE', 'distribuicao' => I18n::t('Distribution'), 'backbone' => I18n::t('Backbone')];
	$coverage = [];

	foreach ($circuit['coverage'] as $role => $present) {
		$coverage[] = (new CSpan(($present ? '✓ ' : '✗ ').$role_labels[$role]))
			->addClass($present ? 'nt-cap-cov-yes' : 'nt-cap-cov-no');
	}

	$client = $client_cell($circuit);

	$html_page->addItem((new CDiv([
		(new CDiv([
			(new CSpan(I18n::t('Situation')))->addClass('nt-cap-fact-label'),
			$situation_tag($circuit['situation'])
		]))->addClass('nt-cap-fact'),
		($circuit['site'] ?? '') !== '' || ($circuit['client'] ?? '') !== ''
			? (new CDiv([
				(new CSpan(I18n::t('Client / site')))->addClass('nt-cap-fact-label'),
				(new CSpan($client[0]))->addClass('nt-cap-strong'),
				$client[1] !== null ? (new CSpan(' · '.$circuit['client']))->addClass('nt-cap-muted') : null
			]))->addClass('nt-cap-fact')
			: null,
		(new CDiv([
			(new CSpan(I18n::t('Contracted bandwidth')))->addClass('nt-cap-fact-label'),
			$circuit['contract'] !== null
				? (new CSpan($fmt_bps($circuit['contract'])))->addClass('nt-cap-strong')
				: (new CSpan(I18n::t('not set')))
					->addClass('nt-cap-muted')
					->setHint(I18n::t('Set the macro {$CAPACITY.BW.MBPS:"%1$s"} (Mbps), global or on the CPE host.', $cid)),
			$circuit['fits'] === false
				? (new CSpan(I18n::t('some segment cannot carry it')))->addClass('nt-cap-tag')->addClass('nt-cap-tag-hot')
				: ($circuit['fits'] === true ? (new CSpan(I18n::t('every segment carries it')))->addClass('nt-cap-tag') : null)
		]))->addClass('nt-cap-fact'),
		(new CDiv([
			(new CSpan(I18n::t('Circuit ID on the three roles')))->addClass('nt-cap-fact-label'),
			(new CSpan($coverage))->addClass('nt-cap-cov-list')
		]))->addClass('nt-cap-fact')
	]))->addClass('nt-cap-facts'));

	// Logical structure: CLIENT - POP - UPLINK - regional aggregation - CORE (LAN) - trunks.
	$chain_steps = [
		[I18n::t('Client'), ['cpe']],
		['POP', ['pop_client', 'core_client']],
		[I18n::t('POP uplink'), ['pop_uplink']],
		[I18n::t('Regional aggregation'), ['agg_uplink']],
		[I18n::t('Core (LAN interface)'), ['core_lan']],
		[I18n::t('Core trunks'), ['core_trunk']]
	];
	$chain = (new CDiv())->addClass('nt-cap-chain');

	foreach ($chain_steps as [$title, $hops]) {
		$lines = [];

		foreach ($circuit['hops'] as $id => $hop) {
			if (in_array($hop, $hops, true)) {
				$load = CapacityBuilder::load($interfaces[$id]);
				$lines[] = (new CDiv([
					$interfaces[$id]['host'].' ',
					(new CSpan($interfaces[$id]['name']))->addClass('nt-cap-muted'),
					$load !== null ? ' · '.$fmt_pct($load['value']) : ''
				]))->addClass('nt-cap-chain-line');
			}
		}

		foreach ($circuit['peers'] as $peer) {
			if (in_array($peer['hop'], $hops, true)) {
				$lines[] = (new CDiv([
					$peer['host'].' ',
					(new CSpan($peer['port']))->addClass('nt-cap-muted'),
					' · ',
					(new CSpan(I18n::t('not monitored')))->addClass('nt-cap-muted')
				]))->addClass('nt-cap-chain-line');
			}
		}

		if ($hops === ['cpe'] && !$lines) {
			foreach ($circuit['endpoints'] as $endpoint) {
				$lines[] = (new CDiv([$endpoint['name'].' ',
					(new CSpan(I18n::t('(not monitored)')))->addClass('nt-cap-muted')
				]))->addClass('nt-cap-chain-line');
			}
		}

		if (!$lines && in_array($hops[0], ['agg_uplink', 'core_trunk', 'core_lan'], true)) {
			continue;
		}

		$chain->addItem((new CDiv([
			(new CDiv($title))->addClass('nt-cap-chain-title'),
			$lines ?: (new CDiv('—'))->addClass('nt-cap-muted')
		]))->addClass('nt-cap-chain-step'));
	}

	$html_page->addItem((new CDiv([
		(new CDiv(I18n::t('Path of the circuit')))->addClass('nt-cap-section-title'),
		$chain
	]))->addClass('nt-cap-section'));

	// Path: client -> last mile -> distribution -> backbone.
	$path = (new CDiv())->addClass('nt-cap-path');

	$client = [];

	foreach ($circuit['hostids'] as $hostid => $info) {
		if (($hosts[$hostid]['role'] ?? '') === 'cpe') {
			$client[] = (new CDiv([
				(new CSpan($hosts[$hostid]['name']))->addClass('nt-cap-strong'),
				(new CDiv(I18n::t('CPE monitored by Zabbix')))->addClass('nt-cap-small')
			]))->addClass('nt-cap-endpoint');
		}
	}

	foreach ($circuit['endpoints'] as $endpoint) {
		$client[] = (new CDiv([
			(new CSpan($endpoint['name']))->addClass('nt-cap-strong'),
			(new CDiv($endpoint['port'] !== '' ? I18n::t('port %1$s', $endpoint['port']) : ''))->addClass('nt-cap-small'),
			(new CDiv(I18n::t('via %1$s', $endpoint['via'])))->addClass('nt-cap-small')
		]))->addClass('nt-cap-endpoint');
	}

	$path->addItem(
		(new CDiv([
			(new CDiv(I18n::t('Client')))->addClass('nt-cap-card-title'),
			$client ?: (new CDiv(I18n::t('Not identified (no LLDP/CDP neighbor on the client port).')))->addClass('nt-cap-small')
		]))->addClass('nt-cap-card')->addClass('nt-cap-card-client')
	);

	foreach (CapacityBuilder::SEGMENTS as $segment) {
		$s = $circuit['segments'][$segment];
		$rows = [];

		foreach ($s['interfaces'] as $id) {
			$interface = $interfaces[$id];
			$load = CapacityBuilder::load($interface);
			$inferred = $circuit['hostids'][$interface['hostid']]['inferred'] ?? false;

			$rows[] = (new CDiv([
				(new CDiv([
					(new CSpan($interface['host']))->addClass('nt-cap-strong'),
					$inferred ? (new CSpan(I18n::t('found by LLDP/CDP')))->addClass('nt-cap-tag') : null
				])),
				(new CDiv([
					$interface['name'],
					' · ',
					$hop_names[$circuit['hops'][$id] ?? ''] ?? ($interface['shared'] ? I18n::t('shared') : I18n::t('dedicated')),
					' · ',
					$fmt_bps($interface['values']['bw'] ?? null)
				]))->addClass('nt-cap-small'),
				$bar($load['value'] ?? null, $interface['severity'], CapacityBuilder::isDown($interface))
			]))
				->addClass('nt-cap-iface')
				->addClass($s['interface'] === $id && $circuit['bottleneck'] === $segment ? 'nt-cap-iface-worst' : null);
		}

		$path->addItem((new CDiv([
			(new CDiv([
				$segment_names[$segment],
				(new CSpan($segment_hints[$segment]))->addClass('nt-cap-small')
			]))->addClass('nt-cap-card-title'),
			(new CDiv([
				(new CSpan($s['load'] !== null ? $fmt_pct($s['load']) : '—'))->addClass('nt-cap-card-load'),
				$s['kind'] !== null ? (new CSpan($kind_label($s['kind'])))->addClass('nt-cap-small') : null,
				$circuit['bottleneck'] === $segment
					? (new CSpan(I18n::t('BOTTLENECK')))->addClass('nt-cap-tag')->addClass('nt-cap-tag-hot')
					: null
			]))->addClass('nt-cap-card-head'),
			$rows ?: (new CDiv(I18n::t('Not measured on this circuit.')))->addClass('nt-cap-small')
		]))
			->addClass('nt-cap-card')
			->addClass($circuit['bottleneck'] === $segment ? 'nt-cap-card-bottleneck' : null)
			->addClass(!$rows ? 'nt-cap-card-empty' : null));
	}

	$html_page->addItem($path);

	if ($circuit['suggestions']) {
		$list = new CList();

		foreach ($circuit['suggestions'] as $suggestion) {
			$list->addItem($suggestion);
		}

		$html_page->addItem((new CDiv([
			(new CDiv(I18n::t('What to check')))->addClass('nt-cap-section-title'),
			$list
		]))->addClass('nt-cap-hints'));
	}

	// Segment by segment: the measures of the planning document and the four criteria of the bottleneck.
	$criteria_rows = ['util' => 'p95', 'free' => 'free', 'days' => 'days', 'impact' => 'impact'];
	$header = [I18n::t('Measure')];

	foreach (CapacityBuilder::SEGMENTS as $segment) {
		$header[] = (new CColHeader([
			$segment_names[$segment],
			$circuit['bottleneck'] === $segment
				? (new CSpan(I18n::t('BOTTLENECK')))->addClass('nt-cap-tag')->addClass('nt-cap-tag-hot')
				: null,
			(new CDiv($segment_hints[$segment]))->addClass('nt-cap-small')
		]))->addClass($circuit['bottleneck'] === $segment ? 'nt-cap-is-bottleneck' : null);
	}

	$compare = (new CTableInfo())->setHeader($header)->addClass('nt-cap-compare');

	// "55% · 1.1 Gbps": the utilization and the traffic it means.
	$with_bps = static function(?float $pct, ?float $bw) use ($fmt_pct, $fmt_bps): string {
		return $pct === null ? '—' : $fmt_pct($pct).($bw !== null && $bw > 0 ? ' · '.$fmt_bps($bw * $pct / 100) : '');
	};
	$cell = static function(string $segment, $content, ?string $criterion) use ($circuit): CCol {
		// The utilization criterion uses the p95, or the current value while there is no p95 yet.
		[$name, $kind] = array_pad(explode(':', (string) $criterion), 2, null);
		$worst = $criterion !== null && in_array($segment, $circuit['criteria'][$name], true)
			&& ($kind === null || $circuit['segments'][$segment]['kind'] === $kind);

		return (new CCol([$content, $worst ? (new CSpan(I18n::t('worst')))->addClass('nt-cap-worst') : null]))
			->addClass($circuit['bottleneck'] === $segment ? 'nt-cap-is-bottleneck' : null);
	};

	$measures = [
		[I18n::t('Nominal capacity (port)'), static fn(array $s) => $fmt_bps($s['speed']), null],
		[I18n::t('Capacity used in the calculation'), static fn(array $s) => $fmt_bps($s['bw']), null],
		[I18n::t('Current utilization'), static fn(array $s) => $with_bps($s['util'], $s['bw']), 'util:now'],
		[I18n::t('95th percentile'), static fn(array $s) => $with_bps($s['p95'], $s['bw']), 'util:p95'],
		[I18n::t('Average (30 days)'), static fn(array $s) => $fmt_pct($s['avg']), null],
		[I18n::t('Monthly growth (p95)'), static fn(array $s) => $fmt_growth($s['growth'], $s['load'], $s['bw']),
			null],
		[I18n::t('Free capacity'), static fn(array $s) => $fmt_bps($s['free']), 'free'],
		[I18n::t('Time to saturation'), static fn(array $s) => $s['days'] !== null
			? $fmt_days($s['days'])
			: ($s['interfaces'] && $s['load'] !== null ? I18n::t('no upward trend') : '—'), 'days'],
		[I18n::t('Circuits on the same segment (impact)'), static fn(array $s) => $s['interfaces'] ? (string) $s['impact'] : '—',
			'impact'],
		[I18n::t('Carries the contracted bandwidth'), static fn(array $s) => $s['fits'] === null
			? '—'
			: ($s['fits']
				? (new CSpan(I18n::t('yes')))->addClass(ZBX_STYLE_GREEN)
				: (new CSpan(I18n::t('no')))->addClass(ZBX_STYLE_RED)), null],
		[_('Status'), static fn(array $s) => $s['interfaces']
			? (new CSpan($state_names[$s['state']]))->addClass('nt-cap-state')->addClass('nt-cap-state-'.$s['state'])
			: (new CSpan(I18n::t('not measured')))->addClass('nt-cap-muted'), null]
	];

	foreach ($measures as [$label, $render, $criterion]) {
		$row = [(new CCol($label))->addClass('nt-cap-measure')];

		foreach (CapacityBuilder::SEGMENTS as $segment) {
			$row[] = $cell($segment, $render($circuit['segments'][$segment]), $criterion);
		}

		$compare->addRow($row);
	}

	$html_page->addItem((new CDiv([
		(new CDiv(I18n::t('Segment by segment')))->addClass('nt-cap-section-title'),
		(new CDiv(I18n::t('The bottleneck is the segment that is worst in most of the four criteria (marked "worst"): highest utilization, lowest free capacity, saturates first and most circuits affected. A tie goes to the highest utilization.')))
			->addClass('nt-cap-small'),
		$compare
	]))->addClass('nt-cap-section'));

	/*
	 * Analysis & upgrade: current utilization -> trend -> forecast -> contracted -> recommended capacity.
	 */
	$planning = $capacity['planning'];
	$upgrade = $circuit['upgrade'];
	$max_step = CapacityBuilder::STEPS[count(CapacityBuilder::STEPS) - 1];
	$b = $circuit['bottleneck'] !== null ? $circuit['segments'][$circuit['bottleneck']] : null;
	$flow_step = static fn(string $title, $value, $note = null, string $class = ''): CDiv => (new CDiv([
		(new CDiv($title))->addClass('nt-cap-chain-title'),
		(new CDiv($value))->addClass('nt-cap-flow-value'),
		$note !== null && $note !== '' ? (new CDiv($note))->addClass('nt-cap-small') : null
	]))->addClass('nt-cap-chain-step')->addClass($class !== '' ? $class : null);

	$recommended = $upgrade !== null
		? ($upgrade['to'] !== null ? $fmt_bps($upgrade['to']) : I18n::t('more than %1$s', $fmt_bps($max_step)))
		: ($b !== null && $b['projected'] !== null ? I18n::t('keep') : '—');

	$flow = (new CDiv([
		$flow_step(I18n::t('Current utilization'),
			$b !== null ? $fmt_pct($b['load']) : '—',
			$b !== null && $b['demand'] !== null ? $fmt_bps($b['demand']).' · '.$kind_label($b['kind']) : null
		),
		$flow_step(I18n::t('Trend'),
			$b !== null ? $fmt_growth($b['growth'], $b['load'], $b['bw']) : '—'
		),
		$flow_step(I18n::t('Forecast'),
			$circuit['forecast'] !== null ? $fmt_days($circuit['forecast']['days']) : I18n::t('no upward trend'),
			$circuit['forecast'] !== null ? $segment_names[$circuit['forecast']['segment']] : null
		),
		$flow_step(I18n::t('Contracted capacity'),
			$circuit['contract'] !== null ? $fmt_bps($circuit['contract']) : I18n::t('not set'),
			$b !== null && $b['speed'] !== null ? I18n::t('port %1$s', $fmt_bps($b['speed'])) : null
		),
		$flow_step(I18n::t('Recommended capacity'), $recommended,
			$upgrade !== null ? $segment_names[$upgrade['segment']] : null,
			$upgrade !== null ? 'nt-cap-flow-upgrade' : 'nt-cap-flow-ok'
		)
	]))->addClass('nt-cap-chain')->addClass('nt-cap-flow');

	$advice = [];

	if ($upgrade !== null) {
		$interface = $interfaces[$upgrade['interface']];
		$where = $interface['host'].' '.$interface['name'];

		if ($upgrade['to'] === null) {
			$advice[] = (new CDiv(I18n::t('%1$s needs more than %2$s (%3$s expected): review the design of the link.',
				$where, $fmt_bps($max_step), $fmt_bps($upgrade['projected'])
			)))->addClass('nt-cap-advice-main');
		}
		elseif ($upgrade['shared']) {
			$advice[] = (new CDiv(I18n::t('Expand the shared link %1$s (%2$s): %3$s → %4$s. %5$s circuit(s) use it.', $where,
				$hop_names[$circuit['hops'][$upgrade['interface']] ?? ''] ?? I18n::t('shared'),
				$fmt_bps($upgrade['from']), $fmt_bps($upgrade['to']), $upgrade['impact']
			)))->addClass('nt-cap-advice-main');
		}
		else {
			$advice[] = (new CDiv(I18n::t('Upgrade the circuit: %1$s → %2$s.', $fmt_bps($upgrade['from']), $fmt_bps($upgrade['to']))))
				->addClass('nt-cap-advice-main');
		}

		$advice[] = (new CDiv(I18n::t('Why: %1$s expected in %2$s days (today %3$s, growth by the trend); to stay at %4$s%% it needs %5$s.',
			$fmt_bps($upgrade['projected']), $planning['horizon'], $fmt_bps($circuit['segments'][$upgrade['segment']]['demand']),
			$upgrade['target'], $fmt_bps($upgrade['need'])
		)))->addClass('nt-cap-small');

		if ($upgrade['port_limit']) {
			$advice[] = (new CDiv('⚠ '.I18n::t('The port is %1$s: the new bandwidth needs a faster port (or another interface).',
				$fmt_bps($interface['values']['speed'] ?? null)
			)))->addClass('nt-cap-advice-warn');
		}

		if ($upgrade['check']) {
			$checks = [];

			foreach ($upgrade['check'] as $segment => $util) {
				$checks[] = mb_strtolower($segment_names[$segment]).' ('.$fmt_pct($util).')';
			}

			$advice[] = (new CDiv('⚠ '.I18n::t('Before the upgrade: check the capacity of %1$s — with the client using the %2$s, it would be above its target.',
				implode(', ', $checks), $fmt_bps($upgrade['to'])
			)))->addClass('nt-cap-advice-warn');
		}

		if ($upgrade['others']) {
			$advice[] = (new CDiv(I18n::t('Also needs more capacity: %1$s.',
				implode(', ', array_map(static fn($s) => mb_strtolower($segment_names[$s]), $upgrade['others']))
			)))->addClass('nt-cap-small');
		}
	}
	elseif ($b !== null && $b['projected'] !== null) {
		$advice[] = (new CDiv(I18n::t('No upgrade needed: the bottleneck is expected at %1$s in %2$s days (target %3$s%%).',
			$fmt_pct($b['projected'] * 100 / $b['bw']), $planning['horizon'], $b['target']
		)))->addClass('nt-cap-advice-main');
	}

	$html_page->addItem((new CDiv([
		(new CDiv(I18n::t('Analysis & upgrade')))->addClass('nt-cap-section-title'),
		(new CDiv(I18n::t('Recommended capacity: the next standard capacity that keeps the utilization below the target (last mile %1$s%%, distribution %2$s%%, backbone %3$s%%) until %4$s days from now, by the growth trend.',
			$planning['targets']['acesso'], $planning['targets']['distribuicao'], $planning['targets']['backbone'],
			$planning['horizon']
		)))->addClass('nt-cap-small'),
		$flow,
		$advice ? (new CDiv($advice))->addClass('nt-cap-advice') : null
	]))->addClass('nt-cap-section'));

	/*
	 * Upgrade simulation: what happens on each segment with another capacity for the circuit.
	 */
	$simulation = $circuit['simulation'] ?? null;

	if ($simulation !== null) {
		$select = (new CSelect('simulate'))->setId('nt-cap-simulate')->setValue((string) round($simulation['capacity'] / 1000000, 3));

		foreach ($simulation['choices'] as $choice) {
			$select->addOption(new CSelectOption((string) round($choice / 1000000, 3), $fmt_bps($choice)));
		}

		// No button: choosing a capacity (mouse or arrow keys) already simulates it, and only the section is updated
		// (nettopo.capacity.js.php), so the page does not jump back to the top. If that update fails, the form is
		// submitted normally and the page opens at the section (#simulation).
		$form = (new CForm('get', 'zabbix.php#simulation'))
			->setId('nt-cap-sim-form')
			->addVar('action', 'nettopo.capacity')
			->addVar('circuit', $cid)
			->addItem([
				(new CSpan(I18n::t('Today %1$s; simulate', $fmt_bps($simulation['current']))))->addClass('nt-cap-sim-label'),
				$select
			])
			->addClass('nt-cap-sim-form');

		$sim_states = [
			'ok' => I18n::t('OK'),
			'warning' => I18n::t('Above the target'),
			'critical' => I18n::t('Critical (90% or more)'),
			'over' => I18n::t('Does not fit')
		];
		$state_cell = static fn(?array $f): CTag => $f === null
			? (new CSpan('—'))->addClass('nt-cap-muted')
			: (new CSpan($sim_states[$f['state']]))->addClass('nt-cap-state')->addClass('nt-cap-sim-'.$f['state']);
		$pair = static function($before, $after): array {
			return [$before, (new CSpan(' → '))->addClass('nt-cap-muted'), (new CSpan($after))->addClass('nt-cap-strong')];
		};

		$sim_table = (new CTableInfo())
			->setHeader([I18n::t('Segment'), I18n::t('Capacity'), I18n::t('Use (p95)'), I18n::t('Free'), I18n::t('Status')])
			->addClass('nt-cap-sim')
			->setNoDataMessage(I18n::t('No utilization data yet: the first values arrive after two polls of the interfaces.'));
		$worst = null;

		foreach ($simulation['segments'] as $row) {
			$before = $row['before'];
			$after = $row['after'];

			if ($row['kind'] === 'shared' && ($worst === null || $after['util'] > $worst['after']['util'])) {
				$worst = $row;
			}

			$sim_table->addRow([
				[
					$segment_names[$row['segment']].' · '.($row['kind'] === 'shared'
						? I18n::t('shared links: receive the extra traffic')
						: I18n::t('circuit ports: new capacity')),
					(new CDiv($iface_label($row['interface'])))->addClass('nt-cap-small')
				],
				$pair($fmt_bps($before['capacity']), $fmt_bps($after['capacity'])),
				$pair($fmt_pct($before['util']).' · '.$fmt_bps($before['demand']),
					$fmt_pct($after['util']).' · '.$fmt_bps($after['demand'])
				),
				$pair($fmt_bps($before['free']), $after['free'] < 0 ? '−'.$fmt_bps(-$after['free']) : $fmt_bps($after['free'])),
				[
					$state_cell($before),
					(new CSpan(' → '))->addClass('nt-cap-muted'),
					$state_cell($after),
					$after['port_limit']
						? (new CDiv(I18n::t('port slower than the new capacity')))->addClass('nt-cap-small')->addClass(ZBX_STYLE_RED)
						: null
				]
			]);
		}

		$conclusion = $worst !== null && $worst['after']['state'] !== 'ok'
			? (new CDiv('⚠ '.I18n::t('With %1$s, %2$s would be at %3$s (%4$s): plan that segment first.',
				$fmt_bps($simulation['capacity']), $iface_label($worst['interface']), $fmt_pct($worst['after']['util']),
				mb_strtolower($sim_states[$worst['after']['state']])
			)))->addClass('nt-cap-advice-warn')
			: ($simulation['segments']
				? (new CDiv('✓ '.I18n::t('Every measured segment carries %1$s.', $fmt_bps($simulation['capacity']))))
					->addClass('nt-cap-advice-ok')
				: null);

		$html_page->addItem((new CDiv([
			(new CDiv(I18n::t('Upgrade simulation')))->addClass('nt-cap-section-title'),
			(new CDiv(I18n::t('Worst case: the client uses all of the new capacity. The circuit ports get it; the shared links on the path receive the difference to today\'s traffic.')))
				->addClass('nt-cap-small'),
			$form,
			$sim_table,
			$conclusion
		]))
			->setId('simulation')
			->addClass('nt-cap-section')
			->addClass('nt-cap-sim-section')
		);
	}

	// Graph of the utilization of every interface of the path (native Zabbix graph).
	$itemids = [];

	foreach ($circuit['interfaces'] as $id => $segment) {
		if (isset($interfaces[$id]['items']['util'])) {
			$itemids[] = $interfaces[$id]['items']['util'];
		}
	}

	if ($itemids) {
		$periods = new CList();

		foreach (['1h', '6h', '1d', '7d', '30d'] as $period) {
			$periods->addItem($period === $data['period']
				? (new CSpan($period))->addClass('nt-cap-period-active')
				: new CLink($period, (new CUrl('zabbix.php'))
					->setArgument('action', 'nettopo.capacity')
					->setArgument('circuit', $cid)
					->setArgument('period', $period)
					->getUrl()
				)
			);
		}

		$chart = (new CUrl('chart.php'))
			->setArgument('from', 'now-'.$data['period'])
			->setArgument('to', 'now')
			->setArgument('itemids', $itemids)
			->setArgument('width', 1200)
			->setArgument('height', 220)
			->setArgument('legend', 1)
			->setArgument('profileIdx', 'web.nettopo.capacity')
			->setArgument('resolve_macros', 1);

		$html_page->addItem((new CDiv([
			(new CDiv([
				(new CSpan(I18n::t('Utilization of the path')))->addClass('nt-cap-section-title'),
				$periods->addClass('nt-cap-periods')
			]))->addClass('nt-cap-graph-head'),
			(new CImg($chart->getUrl()))->addClass('nt-cap-graph')
		]))->addClass('nt-cap-graph-box'));
	}

	// Interfaces of the path.
	$table = (new CTableInfo())->setHeader([
		I18n::t('Segment'), _('Host'), _('Interface'), I18n::t('Point of the path'), I18n::t('Circuit ID from'), _('Status'),
		I18n::t('Capacity'), I18n::t('In'), I18n::t('Out'), I18n::t('Utilization'), 'p95', I18n::t('Peak 24h'),
		I18n::t('Forecast'), I18n::t('Critical limit'), _('Problems')
	]);

	foreach (CapacityBuilder::SEGMENTS as $segment) {
		foreach ($circuit['segments'][$segment]['interfaces'] as $id) {
			$interface = $interfaces[$id];
			$v = $interface['values'];
			$days = $v['days'] ?? null;
			$problems = [];

			foreach ($interface['problems'] as $problem) {
				$problems[] = (new CDiv($problem['name']))
					->addClass('nt-cap-problem')
					->addStyle('border-left-color: '.($severity_color($problem['severity']) ?? '#999').';');
			}

			$table->addRow([
				$segment_names[$segment],
				$interface['host'],
				$interface['name'],
				[
					$hop_names[$circuit['hops'][$id] ?? ''] ?? '',
					(new CDiv($interface['shared'] ? I18n::t('shared') : I18n::t('dedicated')))->addClass('nt-cap-small')
				],
				in_array($cid, $interface['cids'], true)
					? ($source_names[$interface['cid_source']] ?? $source_names['descricao'])
					: (new CSpan($interface['shared'] ? '—' : I18n::t('found by LLDP/CDP')))->addClass('nt-cap-muted'),
				array_key_exists('status', $v)
					? (CapacityBuilder::isDown($interface)
						? (new CSpan('down'))->addClass(ZBX_STYLE_RED)
						: (new CSpan('up'))->addClass(ZBX_STYLE_GREEN))
					: '—',
				$fmt_bps($v['bw'] ?? null),
				$fmt_bps($v['in'] ?? null),
				$fmt_bps($v['out'] ?? null),
				isset($interface['errors']['util']) && !isset($v['util'])
					? (new CSpan(I18n::t('error')))->addClass(ZBX_STYLE_RED)->setHint($interface['errors']['util'])
					: $fmt_pct($v['util'] ?? null),
				$fmt_pct($v['p95'] ?? null),
				$fmt_pct($v['peak'] ?? null),
				isset($v['forecast']) && $v['forecast'] >= 0 ? $fmt_pct(min($v['forecast'], 999)) : '—',
				$days === null || $days < 0
					? '—'
					: ($days >= CapacityBuilder::DAYS_NONE ? I18n::t('no upward trend') : $fmt_days($days)),
				$problems ?: ''
			]);
		}
	}

	$html_page->addItem((new CDiv([
		(new CDiv(I18n::t('Interfaces of the path')))->addClass('nt-cap-section-title'),
		$table
	]))->addClass('nt-cap-section'));

	$html_page->show();

	if ($simulation !== null) {
		$this->includeJsFile('nettopo.capacity.js.php');
	}

	return;
}

/*
 * ---------------------------------------------------------------------------------------------------------
 * Lists: circuits / interfaces
 * ---------------------------------------------------------------------------------------------------------
 */
$filter = $data['filter'];

$html_page->addItem(
	(new CFilter())
		->setResetUrl($base_url)
		->setProfile($data['profileIdx'])
		->setActiveTab($data['active_tab'])
		->addFilterTab(_('Filter'), [
			(new CFormList())
				->addRow(new CLabel(_('Host groups'), 'filter_groupids__ms'),
					(new CMultiSelect([
						'name' => 'filter_groupids[]',
						'object_name' => 'hostGroup',
						'data' => $filter['groups'],
						'popup' => [
							'parameters' => [
								'srctbl' => 'host_groups',
								'srcfld1' => 'groupid',
								'dstfrm' => 'zbx_filter',
								'dstfld1' => 'filter_groupids_',
								'with_hosts' => true,
								'enrich_parent_groups' => true
							]
						]
					]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				)
				->addRow(new CLabel(_('Hosts'), 'filter_hostids__ms'),
					(new CMultiSelect([
						'name' => 'filter_hostids[]',
						'object_name' => 'hosts',
						'data' => $filter['hosts'],
						'popup' => [
							'filter_preselect' => [
								'id' => 'filter_groupids_',
								'submit_as' => 'groupid'
							],
							'parameters' => [
								'srctbl' => 'hosts',
								'srcfld1' => 'hostid',
								'dstfrm' => 'zbx_filter',
								'dstfld1' => 'filter_hostids_'
							]
						]
					]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
				),
			(new CFormList())
				->addRow(I18n::t('Circuit'),
					(new CTextBox('filter_search', $filter['search']))
						->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
						->setAttribute('placeholder', 'NET-TO-0001')
				)
				->addRow(new CLabel(I18n::t('Only those needing attention'), 'filter_alerts'),
					(new CCheckBox('filter_alerts'))->setChecked($filter['alerts'] == 1)
				)
		])
		->addVar('action', 'nettopo.capacity')
);

foreach ($capacity['warnings'] as $warning) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], $warning));
}

$circuits = $capacity['circuits'];

// "Needs attention": link down, a capacity problem (Warning or above) or a saturation forecast.
if ($filter['alerts']) {
	$circuits = array_filter($circuits,
		static fn($c) => $c['down'] || $c['severity'] >= TRIGGER_SEVERITY_WARNING || ($c['forecast']['alert'] ?? false)
	);
}

// Tabs: the modules of the planning document. The chain map is the topology page colored by utilization.
$view = $data['view'];
$focus = $data['focus'];
$tabs = [
	'overview' => I18n::t('Overview'),
	'circuits' => I18n::t('Circuits'),
	'upgrades' => I18n::t('Analysis & upgrades'),
	'interfaces' => I18n::t('Interfaces')
];
$switch = (new CList())->addClass('nt-cap-switch');

foreach ($tabs as $value => $label) {
	$switch->addItem($value === $view && $focus === ''
		? (new CSpan($label))->addClass('nt-cap-switch-active')
		: new CLink($label, (new CUrl('zabbix.php'))
			->setArgument('action', 'nettopo.capacity')
			->setArgument('view', $value)
			->getUrl()
		)
	);
}

$switch->addItem((new CLink(I18n::t('Chain map'), (new CUrl('zabbix.php'))
	->setArgument('action', 'nettopo.view')
	->setArgument('capacity', 1)
	->getUrl()
))->setTitle(I18n::t('Topology map with the links colored by utilization and the circuits that depend on each link.')));

$html_page->addItem((new CDiv($switch))->addClass('nt-cap-tabs'));

$summary = $capacity['summary'];
$planning = $capacity['planning'];
$card = static fn(string $label, $value, string $class = ''): CDiv => (new CDiv([
	(new CDiv($value))->addClass('nt-cap-kpi-value'),
	(new CDiv($label))->addClass('nt-cap-kpi-label')
]))->addClass('nt-cap-kpi')->addClass($class !== '' ? $class : null);

$section = static fn(string $title, array $content, string $class = ''): CDiv => (new CDiv(array_merge([
	(new CDiv($title))->addClass('nt-cap-section-title')
], $content)))->addClass('nt-cap-section')->addClass($class !== '' ? $class : null);

$links_cell = static fn(array $circuit): CCol => (new CCol([
	new CLink(I18n::t('Details'), $circuit_url($circuit['cid'])),
	' · ',
	new CLink(I18n::t('Map'), $map_url($circuit['cid']))
]))->addClass(ZBX_STYLE_NOWRAP);

// Most at risk first: link down, then the highest of the load and the closeness of the saturation.
$by_risk = static function(array $circuits): array {
	uasort($circuits, static fn($a, $b) => ($b['risk'] <=> $a['risk']) ?: strnatcasecmp($a['cid'], $b['cid']));

	return $circuits;
};

// Recommendation of a circuit: "1 Gbps → 2 Gbps" (circuit) or "expand 10 Gbps → 20 Gbps" (shared link).
$upgrade_text = static function(array $circuit) use ($fmt_bps): CTag {
	$upgrade = $circuit['upgrade'];

	if ($upgrade === null) {
		return (new CSpan($circuit['load'] !== null ? I18n::t('keep') : '—'))->addClass('nt-cap-muted');
	}

	if ($upgrade['to'] === null) {
		return (new CSpan(I18n::t('more than %1$s', $fmt_bps(CapacityBuilder::STEPS[count(CapacityBuilder::STEPS) - 1]))))
			->addClass('nt-cap-upgrade');
	}

	return (new CSpan([
		$upgrade['shared'] ? I18n::t('expand').' ' : '',
		$fmt_bps($upgrade['from']),
		' → ',
		(new CSpan($fmt_bps($upgrade['to'])))->addClass('nt-cap-strong')
	]))->addClass('nt-cap-upgrade');
};

// Notes of a recommendation: what to check before, a port too slow, other segments that also need it.
$upgrade_notes = static function(array $circuit) use ($segment_names, $fmt_bps, $interfaces): array {
	$upgrade = $circuit['upgrade'];
	$notes = [];

	if ($upgrade === null) {
		return $notes;
	}

	if ($upgrade['check']) {
		$notes[] = (new CDiv('⚠ '.I18n::t('Before: check %1$s',
			implode(', ', array_map(static fn($s) => mb_strtolower($segment_names[$s]), array_keys($upgrade['check'])))
		)))->addClass('nt-cap-note-warn');
	}

	if ($upgrade['port_limit']) {
		$notes[] = (new CDiv('⚠ '.I18n::t('port of %1$s: needs a faster port',
			$fmt_bps($interfaces[$upgrade['interface']]['values']['speed'] ?? null)
		)))->addClass('nt-cap-note-warn');
	}

	if ($upgrade['shared'] && $upgrade['impact'] > 1) {
		$notes[] = (new CDiv(I18n::t('%1$s circuits use this link', $upgrade['impact'])))->addClass('nt-cap-small');
	}

	if ($upgrade['others']) {
		$notes[] = (new CDiv(I18n::t('also: %1$s',
			implode(', ', array_map(static fn($s) => mb_strtolower($segment_names[$s]), $upgrade['others']))
		)))->addClass('nt-cap-small');
	}

	return $notes;
};

$where_cell = static function(array $circuit) use ($segment_names, $iface_label): array {
	$upgrade = $circuit['upgrade'];
	$segment = $upgrade !== null ? $upgrade['segment'] : $circuit['bottleneck'];

	if ($segment === null) {
		return [(new CSpan('—'))->addClass('nt-cap-muted')];
	}

	$id = $upgrade !== null ? $upgrade['interface'] : $circuit['segments'][$segment]['interface'];

	return [
		$segment_names[$segment],
		$id !== null ? (new CDiv($iface_label($id)))->addClass('nt-cap-small') : null
	];
};

/*
 * Overview: "where should I look now?"
 */
if ($view === 'overview' && $focus === '') {
	$html_page->addItem((new CDiv([
		$card(I18n::t('Monitored circuits'), $summary['circuits']),
		$card(I18n::t('Average utilization'), $fmt_pct($summary['avg_load'])),
		$card(I18n::t('Circuits above 80%'), $summary['over80'], $summary['over80'] ? 'nt-cap-kpi-alert' : ''),
		$card(I18n::t('Critical alerts'), $summary['critical_alerts'], $summary['critical_alerts'] ? 'nt-cap-kpi-alert' : ''),
		$card(I18n::t('Saturation forecast'), [
			$summary['forecast'],
			(new CSpan(' '.($summary['forecast'] == 1 ? I18n::t('circuit') : I18n::t('circuits'))))->addClass('nt-cap-kpi-unit')
		], $summary['forecast'] ? 'nt-cap-kpi-forecast' : ''),
		$card(I18n::t('Free capacity of the chain'), $fmt_bps($summary['free_chain']))
	]))->addClass('nt-cap-kpis'));

	$chips = [];

	foreach (CapacityBuilder::SITUATIONS as $situation) {
		$count = $summary['situations'][$situation] ?? 0;

		if ($count > 0 || $situation !== 'nodata') {
			$chips[] = (new CSpan([$situation_tag($situation), ' ', (new CSpan($count))->addClass('nt-cap-strong')]))
				->addClass('nt-cap-sit-count');
		}
	}

	$html_page->addItem((new CDiv([
		(new CSpan(I18n::t('Situation of the circuits')))->addClass('nt-cap-fact-label'),
		$chips,
		(new CSpan(I18n::t('Critical: link down, critical limit of the role, 90% or saturating within 30 days. High: attention limit, 80% or within 60 days. Medium: 60%.')))
			->addClass('nt-cap-small')
	]))->addClass('nt-cap-sit-row'));

	// Ranking of the circuits by risk.
	$ranked = $by_risk($circuits);
	$table = (new CTableInfo())
		->setHeader([I18n::t('Circuit'), I18n::t('Client / site'), I18n::t('Utilization'), I18n::t('Average 30 days'),
			I18n::t('Forecast'), I18n::t('Situation'), ''
		])
		->addClass('nt-cap-table')
		->setNoDataMessage(I18n::t('No circuits found.'));

	foreach (array_slice($ranked, 0, 10, true) as $circuit) {
		$table->addRow([
			(new CLink($circuit['cid'], $circuit_url($circuit['cid'])))->addClass('nt-cap-cid'),
			$client_cell($circuit),
			[
				$circuit['down']
					? (new CSpan(I18n::t('Link down')))->addClass(ZBX_STYLE_RED)
					: (new CSpan($fmt_pct($circuit['load'])))->addClass('nt-cap-strong'),
				$circuit['bottleneck'] !== null
					? (new CDiv($segment_names[$circuit['bottleneck']]))->addClass('nt-cap-small')
					: null
			],
			$fmt_pct($circuit['avg']),
			$circuit['forecast'] !== null ? $fmt_days($circuit['forecast']['days']) : (new CSpan('—'))->addClass('nt-cap-muted'),
			$situation_tag($circuit['situation']),
			$links_cell($circuit)
		]);
	}

	$html_page->addItem($section(I18n::t('Ranking of the circuits'), [
		(new CDiv(I18n::t('Ordered by risk: link down first, then the highest of the utilization (p95) and the closeness of the saturation. Start with the ones that really need action.')))
			->addClass('nt-cap-small'),
		$table,
		count($ranked) > 10
			? (new CDiv(new CLink(I18n::t('All %1$s circuits', count($ranked)), (new CUrl('zabbix.php'))
				->setArgument('action', 'nettopo.capacity')
				->setArgument('view', 'circuits')
				->setArgument('sort', 'risk')
				->setArgument('sortorder', ZBX_SORT_DOWN)
				->getUrl()
			)))->addClass('nt-cap-more')
			: null
	]));

	// Recommended upgrades (most at risk first).
	$upgrades = array_filter($ranked, static fn($c) => $c['upgrade'] !== null);
	$table = (new CTableInfo())
		->setHeader([I18n::t('Circuit'), I18n::t('Where'), I18n::t('Recommendation'), I18n::t('Notes'), ''])
		->addClass('nt-cap-table')
		->setNoDataMessage(I18n::t('No upgrade needed within the planning horizon.'));

	foreach (array_slice($upgrades, 0, 5, true) as $circuit) {
		$table->addRow([
			(new CLink($circuit['cid'], $circuit_url($circuit['cid'])))->addClass('nt-cap-cid'),
			$where_cell($circuit),
			$upgrade_text($circuit),
			$upgrade_notes($circuit) ?: '',
			(new CCol(new CLink(I18n::t('Simulate'), $circuit_url($circuit['cid']).'#simulation')))->addClass(ZBX_STYLE_NOWRAP)
		]);
	}

	$html_page->addItem($section(I18n::t('Recommended upgrades'), [
		$table,
		count($upgrades) > 5
			? (new CDiv(new CLink(I18n::t('All %1$s recommendations', count($upgrades)), (new CUrl('zabbix.php'))
				->setArgument('action', 'nettopo.capacity')
				->setArgument('view', 'upgrades')
				->getUrl()
			)))->addClass('nt-cap-more')
			: null
	]));

	$html_page->addItem((new CDiv(I18n::t('Path of the work: alert → circuit → chain → bottleneck → forecast → recommended upgrade. The capacity problems in Monitoring > Problems link to their circuit.')))
		->addClass('nt-cap-small')->addClass('nt-cap-flow-hint'));
}

/*
 * Circuits: one per line with the load of each segment.
 */
if ($view === 'circuits' && $focus === '') {
	$html_page->addItem((new CDiv([
		$card(I18n::t('Circuits'), $summary['circuits']),
		$card(I18n::t('Bottleneck in the last mile'), $summary['acesso'], 'nt-cap-kpi-acesso'),
		$card(I18n::t('Bottleneck in the distribution'), $summary['distribuicao'], 'nt-cap-kpi-distribuicao'),
		$card(I18n::t('Bottleneck in the backbone'), $summary['backbone'], 'nt-cap-kpi-backbone'),
		$card(I18n::t('With capacity problems'), $summary['alert'], $summary['alert'] ? 'nt-cap-kpi-alert' : ''),
		$card(I18n::t('Saturation forecast'), $summary['forecast'], $summary['forecast'] ? 'nt-cap-kpi-forecast' : ''),
		$card(I18n::t('No room for the contracted bandwidth'), $summary['contract_risk'] ?? 0,
			($summary['contract_risk'] ?? 0) ? 'nt-cap-kpi-alert' : ''
		),
		$card(I18n::t('Circuit ID on the three roles'), ($summary['coverage'] ?? 0).' / '.$summary['circuits'])
	]))->addClass('nt-cap-kpis'));

	$html_page->addItem((new CDiv(I18n::t('Load = p95 of the utilization (current value while there is no p95 yet). Each segment shows its most loaded interface; the bottleneck is the segment that is worst in most of the four criteria: utilization, free capacity, time to saturation and circuits affected.')))
		->addClass('nt-cap-small')->addClass('nt-cap-toolbar'));

	$sort_url = (new CUrl('zabbix.php'))->setArgument('action', 'nettopo.capacity')->getUrl();
	$sort = $data['sort'];
	$desc = $data['sortorder'] === ZBX_SORT_DOWN;

	$value_of = static function(array $c) use ($sort) {
		switch ($sort) {
			case 'cid':
				return $c['cid'];
			case 'risk':
				return $c['risk'];
			case 'avg':
				return $c['avg'] ?? -1;
			case 'headroom':
				return $c['headroom'] ?? INF;
			case 'forecast':
				return $c['forecast']['days'] ?? INF;
			case 'acesso':
			case 'distribuicao':
			case 'backbone':
				return $c['segments'][$sort]['load'] ?? -1;
			default:
				return $c['down'] ? 1000 : ($c['load'] ?? -1);
		}
	};

	uasort($circuits, static function($a, $b) use ($value_of, $desc, $sort) {
		$cmp = $sort === 'cid' ? strnatcasecmp($value_of($a), $value_of($b)) : ($value_of($a) <=> $value_of($b));

		return ($desc ? -$cmp : $cmp) ?: strnatcasecmp($a['cid'], $b['cid']);
	});

	$table = (new CTableInfo())
		->setHeader([
			make_sorting_header(I18n::t('Circuit'), 'cid', $sort, $data['sortorder'], $sort_url),
			make_sorting_header($segment_names['acesso'], 'acesso', $sort, $data['sortorder'], $sort_url),
			make_sorting_header($segment_names['distribuicao'], 'distribuicao', $sort, $data['sortorder'], $sort_url),
			make_sorting_header($segment_names['backbone'], 'backbone', $sort, $data['sortorder'], $sort_url),
			make_sorting_header(I18n::t('Bottleneck'), 'load', $sort, $data['sortorder'], $sort_url),
			make_sorting_header(I18n::t('Free'), 'headroom', $sort, $data['sortorder'], $sort_url),
			make_sorting_header(I18n::t('Saturation forecast'), 'forecast', $sort, $data['sortorder'], $sort_url),
			make_sorting_header(I18n::t('Situation'), 'risk', $sort, $data['sortorder'], $sort_url),
			''
		])
		->addClass('nt-cap-table')
		->setNoDataMessage(I18n::t('No circuits found.'));

	foreach ($circuits as $circuit) {
		$client = ($circuit['site'] ?? '') !== '' || ($circuit['client'] ?? '') !== '' ? $client_cell($circuit) : [];

		$table->addRow([
			(new CCol([
				(new CLink($circuit['cid'], $circuit_url($circuit['cid'])))->addClass('nt-cap-cid'),
				' ',
				$coverage_tag($circuit),
				$client ? (new CDiv(implode(' · ', array_filter([$circuit['site'] ?? '', $circuit['client'] ?? '']))))
					->addClass('nt-cap-small')
					: null,
				$circuit['suggestions']
					? (new CDiv(count($circuit['suggestions']) == 1
						? I18n::t('1 hint')
						: I18n::t('%1$s hints', count($circuit['suggestions']))
					))->addClass('nt-cap-small')->addClass('nt-cap-hint-count')
					: null
			])),
			$segment_cell($circuit, 'acesso'),
			$segment_cell($circuit, 'distribuicao'),
			$segment_cell($circuit, 'backbone'),
			[
				$bottleneck_badge($circuit),
				$circuit['bottleneck'] !== null && !$circuit['down']
					? (new CDiv($votes_label($circuit)))->addClass('nt-cap-small')
					: null
			],
			[
				$fmt_bps($circuit['headroom']),
				$circuit['fits'] === false
					? (new CDiv(I18n::t('no room for the contracted bandwidth')))->addClass('nt-cap-small')->addClass(ZBX_STYLE_RED)
					: null
			],
			$circuit['forecast'] !== null
				? [
					$fmt_days($circuit['forecast']['days']),
					(new CDiv($segment_names[$circuit['forecast']['segment']]))->addClass('nt-cap-small')
				]
				: (new CSpan('—'))->addClass('nt-cap-muted'),
			$situation_tag($circuit['situation']),
			$links_cell($circuit)
		]);
	}

	$html_page->addItem($table);
}

/*
 * Analysis & upgrades: "what will saturate and what should I do?"
 */
if ($view === 'upgrades' && $focus === '') {
	$ranked = $by_risk($circuits);
	$upgrades = array_filter($ranked, static fn($c) => $c['upgrade'] !== null);
	$own = count(array_filter($upgrades, static fn($c) => !$c['upgrade']['shared']));
	$check = count(array_filter($upgrades, static fn($c) => (bool) $c['upgrade']['check']));

	$html_page->addItem((new CDiv([
		$card(I18n::t('Recommended upgrades'), count($upgrades), $upgrades ? 'nt-cap-kpi-forecast' : ''),
		$card(I18n::t('New contracted bandwidth'), $own, $own ? 'nt-cap-kpi-acesso' : ''),
		$card(I18n::t('Shared links to expand'), count($upgrades) - $own,
			count($upgrades) - $own ? 'nt-cap-kpi-distribuicao' : ''
		),
		$card(I18n::t('Check the path before'), $check, $check ? 'nt-cap-kpi-alert' : ''),
		$card(I18n::t('Saturation forecast'), $summary['forecast'], $summary['forecast'] ? 'nt-cap-kpi-forecast' : '')
	]))->addClass('nt-cap-kpis'));

	$table = (new CTableInfo())
		->setHeader([I18n::t('Circuit'), I18n::t('Where'), I18n::t('Current utilization'), I18n::t('Monthly growth'),
			I18n::t('Forecast'), I18n::t('Contracted'), I18n::t('Recommended'), I18n::t('Notes'), ''
		])
		->addClass('nt-cap-table')
		->addClass('nt-cap-upgrades')
		->setNoDataMessage(I18n::t('No circuits found.'));

	// Circuits that need an upgrade first, then the others (all by risk).
	$ordered = $upgrades + array_filter($ranked, static fn($c) => $c['upgrade'] === null);

	foreach ($ordered as $circuit) {
		$segment = $circuit['upgrade']['segment'] ?? $circuit['bottleneck'];
		$s = $segment !== null ? $circuit['segments'][$segment] : null;

		$table->addRow((new CRow([
			[
				(new CLink($circuit['cid'], $circuit_url($circuit['cid'])))->addClass('nt-cap-cid'),
				' ',
				$situation_tag($circuit['situation']),
				($circuit['site'] ?? '') !== '' || ($circuit['client'] ?? '') !== ''
					? (new CDiv(implode(' · ', array_filter([$circuit['site'] ?? '', $circuit['client'] ?? '']))))
						->addClass('nt-cap-small')
					: null
			],
			$where_cell($circuit),
			$s !== null && $s['load'] !== null
				? [$fmt_pct($s['load']), (new CDiv($fmt_bps($s['demand'])))->addClass('nt-cap-small')]
				: (new CSpan('—'))->addClass('nt-cap-muted'),
			$s !== null ? $fmt_growth($s['growth'], $s['load'], $s['bw']) : '—',
			$circuit['forecast'] !== null ? $fmt_days($circuit['forecast']['days']) : I18n::t('no upward trend'),
			$circuit['contract'] !== null ? $fmt_bps($circuit['contract']) : (new CSpan(I18n::t('not set')))->addClass('nt-cap-muted'),
			$upgrade_text($circuit),
			$upgrade_notes($circuit) ?: '',
			(new CCol(new CLink(I18n::t('Simulate'), $circuit_url($circuit['cid']).'#simulation')))->addClass(ZBX_STYLE_NOWRAP)
		]))->addClass($circuit['upgrade'] === null ? 'nt-cap-row-ok' : null));
	}

	$html_page->addItem($section(I18n::t('Analysis & upgrades'), [
		(new CDiv(I18n::t('For each circuit: current utilization → trend → forecast → contracted capacity → recommended capacity. The recommendation is the next standard capacity that keeps the segment below its target (last mile %1$s%%, distribution %2$s%%, backbone %3$s%%) until %4$s days from now; a Super admin changes these values in the settings of the module.',
			$planning['targets']['acesso'], $planning['targets']['distribuicao'], $planning['targets']['backbone'],
			$planning['horizon']
		)))->addClass('nt-cap-small'),
		$table
	]));
}

/*
 * Interfaces ranked by load (or the interface of a problem link).
 */
if ($view === 'interfaces' || $focus !== '') {
	if ($focus !== '') {
		$list = array_key_exists($focus, $interfaces) ? [$focus => $interfaces[$focus]] : [];

		if ($list) {
			$i = $list[$focus];
			$html_page->addItem((new CDiv($i['circuits']
				? I18n::t('%1$s %2$s is a shared link: %3$s circuits depend on it.', $i['host'], $i['name'], count($i['circuits']))
				: I18n::t('%1$s %2$s is not on the path of any circuit yet.', $i['host'], $i['name'])
			))->addClass('nt-cap-focus'));
		}
		else {
			$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [],
				I18n::t('The interface of this problem is no longer measured by the capacity templates.')
			));
		}
	}
	else {
		$list = array_filter($interfaces, static fn($i) => $i['listed']);

		if ($filter['search'] !== '') {
			$list = array_filter($list, static function($i) use ($filter) {
				foreach ($i['cids'] as $cid) {
					if (mb_stripos($cid, $filter['search']) !== false) {
						return true;
					}
				}

				return false;
			});
		}

		if ($filter['alerts']) {
			$list = array_filter($list, static fn($i) => $i['severity'] >= TRIGGER_SEVERITY_WARNING || CapacityBuilder::isDown($i)
				|| $i['forecast_alert']
			);
		}
	}

	uasort($list, static function($a, $b) {
		$la = CapacityBuilder::load($a)['value'] ?? -1;
		$lb = CapacityBuilder::load($b)['value'] ?? -1;

		return ($lb <=> $la) ?: strnatcasecmp($a['host'].$a['name'], $b['host'].$b['name']);
	});

	$table = (new CTableInfo())
		->setHeader([_('Host'), _('Interface'), I18n::t('Role'), I18n::t('Link'), I18n::t('Circuits'),
			I18n::t('Capacity'), I18n::t('Load'), I18n::t('Average 30 days'), I18n::t('Critical limit'), _('Problems')
		])
		->addClass('nt-cap-table')
		->setNoDataMessage(I18n::t('No interface with a circuit ID or shared-link word found.'));

	$max_cids = $focus !== '' ? 1000 : 6;

	foreach ($list as $interface) {
		$load = CapacityBuilder::load($interface);
		$days = $interface['values']['days'] ?? null;
		$avg = $interface['values']['avg'] ?? null;
		$cids = $interface['circuits'] ?: $interface['cids'];
		$cid_links = [];

		foreach (array_slice($cids, 0, $max_cids) as $i => $cid) {
			$cid_links[] = $i > 0 ? ', ' : null;
			$cid_links[] = new CLink($cid, $circuit_url($cid));
		}

		if (count($cids) > $max_cids) {
			$cid_links[] = ' '.I18n::t('(+%1$s)', count($cids) - $max_cids);
		}

		if ($interface['circuits_via_peer']) {
			$cid_links[] = (new CDiv(I18n::t('through the other end of the link')))->addClass('nt-cap-small');
		}

		$problems = [];

		foreach (array_slice($interface['problems'], 0, 3) as $problem) {
			$problems[] = (new CDiv($problem['name']))
				->addClass('nt-cap-problem')
				->addStyle('border-left-color: '.($severity_color($problem['severity']) ?? '#999').';');
		}

		$table->addRow([
			$interface['host'],
			[
				$interface['name'],
				$interface['alias'] !== '' ? (new CDiv($interface['alias']))->addClass('nt-cap-small') : null
			],
			$role_names[$interface['role']] ?? $interface['role'],
			$interface['shared'] ? I18n::t('shared') : I18n::t('dedicated'),
			$cid_links ?: (new CSpan('—'))->addClass('nt-cap-muted'),
			$fmt_bps($interface['values']['bw'] ?? null),
			(new CCol([
				$bar($load['value'] ?? null, $interface['severity'], CapacityBuilder::isDown($interface)),
				$load !== null ? (new CDiv($kind_label($load['kind'])))->addClass('nt-cap-small') : null
			]))->addClass('nt-cap-segcell'),
			$avg !== null && $avg >= 0 ? $fmt_pct($avg) : '—',
			$days === null || $days < 0
				? '—'
				: ($days >= CapacityBuilder::DAYS_NONE ? I18n::t('no upward trend') : $fmt_days($days)),
			$problems ?: ''
		]);
	}

	$html_page->addItem($table);
}

$html_page->show();
