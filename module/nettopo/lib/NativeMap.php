<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** "Publish": copies a saved topology map into a native Zabbix map (Monitoring > Maps), so it can be used in
** dashboards (Map widget), shared with the Zabbix map permissions and kept as a fixed picture.
**
** Hosts keep their positions, links get the triggers of their ports (the link turns red while a port
** trigger is in problem), zones/texts become map shapes and drawn lines become map lines. Native maps only
** draw straight links: curves are not copied.
**/

namespace Modules\NetTopo\Lib;

use API,
	DB,
	RuntimeException;

class NativeMap {

	/** Device type => default Zabbix image (Administration > General > Images). */
	private const ICONS = [
		'router' => 'Router_symbol_(48)',
		'switch' => 'Switch_(48)',
		'firewall' => 'Firewall_(48)',
		'server' => 'Server_(48)',
		'ap' => 'Satellite_antenna_(48)',
		'olt' => 'Hub_(48)',
		'cpe' => 'Modem_(48)',
		'phone' => 'Phone_(48)',
		'cloud' => 'Cloud_(48)',
		'storage' => 'SAN_(48)',
		'ups' => 'UPS_(48)',
		'printer' => 'Printer_(48)',
		'camera' => 'Video_terminal_(48)',
		'device' => 'Network_(48)',
		'ext' => 'Network_(48)'
	];

	/** Link draw types (the constant names differ between Zabbix 7.0 and 7.4, the values do not). */
	private const DRAW_LINE = 0;
	private const DRAW_BOLD = 2;
	private const DRAW_DASHED = 4;

	private const ICON = 48;
	private const MARGIN = 70;
	private const LINK_COLOR = '8A97A0';
	private const LINK_PROBLEM_COLOR = 'DD0000';

	/**
	 * @param array  $map        saved map (MapStore::get())
	 * @param array  $topology   TopologyBuilder::build() for the selection of the map
	 * @param array  $positions  node id => [x, y] as shown in the page; nodes without a position are left out
	 *
	 * @throws RuntimeException
	 *
	 * @return array  ['sysmapid' => string, 'name' => string, 'created' => bool]
	 */
	public static function publish(array $map, array $topology, array $positions): array {
		$nodes = [];

		foreach ($topology['nodes'] as $node) {
			if (array_key_exists($node['id'], $positions)) {
				$nodes[$node['id']] = $node;
			}
		}

		if (!$nodes) {
			throw new RuntimeException(I18n::t('There is nothing to publish: the map has no hosts.'));
		}

		$shapes = $map['layout']['shapes'] ?? [];

		// Bounding box of everything, then shift it into the positive quadrant of the native map.
		$x1 = $y1 = PHP_FLOAT_MAX;
		$x2 = $y2 = -PHP_FLOAT_MAX;

		$extend = static function(float $ax, float $ay, float $bx, float $by) use (&$x1, &$y1, &$x2, &$y2): void {
			$x1 = min($x1, $ax);
			$y1 = min($y1, $ay);
			$x2 = max($x2, $bx);
			$y2 = max($y2, $by);
		};

		foreach ($nodes as $id => $node) {
			[$x, $y] = $positions[$id];
			// Icon plus room for the label below it.
			$extend($x - 60, $y - self::ICON / 2, $x + 60, $y + self::ICON / 2 + 34);
		}

		foreach ($shapes as $shape) {
			if ($shape['t'] === 'line') {
				$extend(min($shape['x'], $shape['x2']), min($shape['y'], $shape['y2']),
					max($shape['x'], $shape['x2']), max($shape['y'], $shape['y2'])
				);
			}
			else {
				$extend($shape['x'] - $shape['w'] / 2, $shape['y'] - $shape['h'] / 2,
					$shape['x'] + $shape['w'] / 2, $shape['y'] + $shape['h'] / 2
				);
			}
		}

		$dx = self::MARGIN - $x1;
		$dy = self::MARGIN - $y1;
		$width = (int) min(65535, max(400, ceil($x2 - $x1 + 2 * self::MARGIN)));
		$height = (int) min(65535, max(300, ceil($y2 - $y1 + 2 * self::MARGIN)));
		$clamp = static fn(float $v, int $max): int => (int) max(0, min($max, round($v)));

		$icons = self::icons();
		$selements = [];
		$selement_ids = [];
		$n = 0;

		foreach ($nodes as $id => $node) {
			[$x, $y] = $positions[$id];
			$key = 'nt'.(++$n);
			$selement_ids[$id] = $key;

			$selement = [
				'selementid' => $key,
				'iconid_off' => $icons[$node['kind'] === 'host' ? $node['type'] : 'ext'] ?? $icons['device'],
				'x' => $clamp($x + $dx - self::ICON / 2, $width - self::ICON),
				'y' => $clamp($y + $dy - self::ICON / 2, $height - self::ICON)
			];

			if ($node['kind'] === 'host') {
				$selement += [
					'elementtype' => SYSMAP_ELEMENT_TYPE_HOST,
					'elements' => [['hostid' => $node['hostid']]],
					'label' => '{HOST.NAME}'
				];
			}
			else {
				$selement += [
					'elementtype' => SYSMAP_ELEMENT_TYPE_IMAGE,
					'label' => mb_substr($node['name'], 0, 255)
				];
			}

			$selements[] = $selement;
		}

		$link_triggers = self::portTriggers($topology['edges'], $nodes);
		$with_indicator = array_key_exists('indicator_type', DB::getSchema('sysmaps_links')['fields']);
		$links = [];

		foreach ($topology['edges'] as $edge) {
			if (!isset($selement_ids[$edge['source']], $selement_ids[$edge['target']]) || $edge['lost']) {
				continue;
			}

			// Port names only on links between two hosts: on a native map every label is a box on the line.
			$between_hosts = $nodes[$edge['source']]['kind'] === 'host' && $nodes[$edge['target']]['kind'] === 'host';
			$ports = array_map([PortName::class, 'short'],
				array_filter([$edge['source_port'], $edge['target_port']], 'strlen')
			);
			$link = [
				'selementid1' => $selement_ids[$edge['source']],
				'selementid2' => $selement_ids[$edge['target']],
				'color' => self::LINK_COLOR,
				'drawtype' => $edge['origin'] === 'dep' ? self::DRAW_DASHED : self::DRAW_LINE,
				'label' => $between_hosts ? mb_substr(implode(' - ', $ports), 0, 255) : ''
			];

			$triggerids = $link_triggers[$edge['id']] ?? [];

			if ($triggerids) {
				$link['linktriggers'] = array_map(static fn(string $triggerid): array => [
					'triggerid' => $triggerid,
					'color' => self::LINK_PROBLEM_COLOR,
					'drawtype' => self::DRAW_BOLD
				], $triggerids);

				if ($with_indicator) {
					$link['indicator_type'] = 1;
				}
			}

			$links[] = $link;
		}

		$links = self::dropStackedLabels($links, $selements);

		$native_shapes = [];
		$native_lines = [];
		$z = 0;

		foreach ($shapes as $shape) {
			// "auto" follows the theme in the module; native maps have a light background.
			$hex = $shape['color'] === 'auto' ? '#1f2c33' : $shape['color'];
			$color = strtoupper(substr($hex, 1));
			$border = ['solid' => SYSMAP_SHAPE_BORDER_TYPE_SOLID, 'dashed' => SYSMAP_SHAPE_BORDER_TYPE_DASHED,
				'dotted' => SYSMAP_SHAPE_BORDER_TYPE_DOTTED
			][$shape['ls']];

			if ($shape['t'] === 'line') {
				$native_lines[] = [
					'x1' => $clamp($shape['x'] + $dx, $width),
					'y1' => $clamp($shape['y'] + $dy, $height),
					'x2' => $clamp($shape['x2'] + $dx, $width),
					'y2' => $clamp($shape['y2'] + $dy, $height),
					'line_type' => $border,
					'line_width' => $shape['lw'],
					'line_color' => $color,
					'zindex' => $z++
				];

				continue;
			}

			$is_text = $shape['t'] === 'text';

			$native_shapes[] = [
				'type' => $shape['t'] === 'ellipse' ? SYSMAP_SHAPE_TYPE_ELLIPSE : SYSMAP_SHAPE_TYPE_RECTANGLE,
				'x' => $clamp($shape['x'] - $shape['w'] / 2 + $dx, $width),
				'y' => $clamp($shape['y'] - $shape['h'] / 2 + $dy, $height),
				'width' => (int) max(1, round($shape['w'])),
				'height' => (int) max(1, round($shape['h'])),
				'text' => $shape['text'],
				'font' => 9,
				'font_size' => $shape['fs'],
				'font_color' => $is_text ? $color : '333333',
				'text_halign' => SYSMAP_SHAPE_LABEL_HALIGN_CENTER,
				'text_valign' => $is_text ? SYSMAP_SHAPE_LABEL_VALIGN_MIDDLE : SYSMAP_SHAPE_LABEL_VALIGN_TOP,
				'border_type' => $is_text ? SYSMAP_SHAPE_BORDER_TYPE_NONE : $border,
				'border_width' => 2,
				'border_color' => $color,
				'background_color' => (!$is_text && $shape['fill']) ? self::tint($hex) : '',
				'zindex' => $z++
			];
		}

		$name = self::mapName(I18n::t('Topology'), $map['name']);

		$data = [
			'name' => $name,
			'width' => $width,
			'height' => $height,
			'label_type' => MAP_LABEL_TYPE_LABEL,
			'label_location' => MAP_LABEL_LOC_BOTTOM,
			'highlight' => SYSMAP_HIGHLIGHT_ON,
			'expandproblem' => SYSMAP_PROBLEMS_NUMBER,
			'markelements' => 1,
			'show_unack' => EXTACK_OPTION_ALL,
			'grid_show' => SYSMAP_GRID_SHOW_OFF,
			'private' => $map['scope'] === MapStore::SCOPE_SHARED ? PUBLIC_SHARING : PRIVATE_SHARING,
			'selements' => $selements,
			'links' => $links,
			'shapes' => $native_shapes,
			'lines' => $native_lines
		];

		$existing = $map['sysmapid'] !== ''
			? API::Map()->get(['output' => ['sysmapid', 'name'], 'sysmapids' => [$map['sysmapid']], 'editable' => true])
			: [];

		if ($existing) {
			// Same map published before by someone using the other language ("Topology" / "Topologia"), or a
			// native map with this name that is not ours: keep the old name.
			$same_map = in_array($existing[0]['name'],
				[self::mapName('Topology', $map['name']), self::mapName('Topologia', $map['name'])], true
			);

			if ($existing[0]['name'] !== $name && ($same_map || self::nameTaken($name))) {
				$data['name'] = $existing[0]['name'];
			}

			$result = API::Map()->update([['sysmapid' => $map['sysmapid']] + $data]);
			$created = false;
		}
		else {
			$data['name'] = self::freeName($name);
			$result = API::Map()->create([$data]);
			$created = true;
		}

		if (!$result || !$result['sysmapids']) {
			throw new RuntimeException(I18n::t('Cannot publish the map.'));
		}

		return ['sysmapid' => (string) reset($result['sysmapids']), 'name' => $data['name'], 'created' => $created];
	}

	/**
	 * Triggers of the ports at the ends of each link (trigger tag "interface" with the port name).
	 *
	 * @return array  edge id => trigger ids
	 */
	private static function portTriggers(array $edges, array $nodes): array {
		$hostids = [];

		foreach ($nodes as $node) {
			if ($node['kind'] === 'host') {
				$hostids[$node['hostid']] = true;
			}
		}

		if (!$hostids) {
			return [];
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'hostids' => array_keys($hostids),
			'selectHosts' => ['hostid'],
			'selectTags' => ['tag', 'value'],
			'tags' => [
				['tag' => 'interface', 'operator' => TAG_OPERATOR_EXISTS],
				['tag' => 'nt.local', 'operator' => TAG_OPERATOR_EXISTS]
			],
			'evaltype' => TAG_EVAL_TYPE_OR,
			'monitored' => true,
			'preservekeys' => true
		]);

		// hostid => normalized port => trigger ids
		$by_port = [];

		foreach ($triggers as $triggerid => $trigger) {
			foreach ($trigger['tags'] as $tag) {
				if (in_array($tag['tag'], ['interface', 'nt.local'], true) && $tag['value'] !== '') {
					foreach ($trigger['hosts'] as $host) {
						$by_port[(string) $host['hostid']][PortName::normalize($tag['value'])][] = (string) $triggerid;
					}
				}
			}
		}

		$result = [];

		foreach ($edges as $edge) {
			$ids = [];

			foreach (['source', 'target'] as $end) {
				$node = $nodes[$edge[$end]] ?? null;

				if ($node === null || $node['kind'] !== 'host' || $edge[$end.'_port'] === '') {
					continue;
				}

				$ids = array_merge($ids, $by_port[$node['hostid']][PortName::normalize($edge[$end.'_port'])] ?? []);
			}

			if ($ids) {
				$result[$edge['id']] = array_values(array_unique($ids));
			}
		}

		return $result;
	}

	/**
	 * @return array  device type => imageid (missing images fall back to any icon)
	 */
	private static function icons(): array {
		$images = API::Image()->get([
			'output' => ['imageid', 'name'],
			'filter' => ['imagetype' => IMAGE_TYPE_ICON]
		]);

		$by_name = array_column($images, 'imageid', 'name');
		$fallback = $by_name['Network_(48)'] ?? ($images ? $images[0]['imageid'] : null);

		if ($fallback === null) {
			throw new RuntimeException(I18n::t('There are no icons in Administration > General > Images.'));
		}

		$result = [];

		foreach (self::ICONS as $type => $name) {
			$result[$type] = $by_name[$name] ?? $fallback;
		}

		return $result;
	}

	/**
	 * A native map draws each link label as a box in the middle of the link. Links fanning out of the same host
	 * put their boxes on top of each other: of the labels that would overlap, only the first one is kept (the
	 * module page still shows every port).
	 */
	private static function dropStackedLabels(array $links, array $selements): array {
		$centers = [];

		foreach ($selements as $selement) {
			$centers[$selement['selementid']] = [$selement['x'] + self::ICON / 2, $selement['y'] + self::ICON / 2];
		}

		$placed = [];

		foreach ($links as &$link) {
			if ($link['label'] === '') {
				continue;
			}

			[$x1, $y1] = $centers[$link['selementid1']];
			[$x2, $y2] = $centers[$link['selementid2']];

			// Approximate size of the label box drawn by the Zabbix frontend.
			$width = mb_strlen($link['label']) * 5.4 + 12;
			$box = [($x1 + $x2 - $width) / 2, ($y1 + $y2) / 2 - 12, $width, 24];

			foreach ($placed as $other) {
				if ($box[0] < $other[0] + $other[2] && $other[0] < $box[0] + $box[2]
						&& $box[1] < $other[1] + $other[3] && $other[1] < $box[1] + $box[3]) {
					$link['label'] = '';

					continue 2;
				}
			}

			$placed[] = $box;
		}
		unset($link);

		return $links;
	}

	private static function mapName(string $prefix, string $name): string {
		return mb_substr($prefix.' - '.$name, 0, 128);
	}

	private static function nameTaken(string $name): bool {
		return (bool) API::Map()->get(['output' => [], 'filter' => ['name' => $name], 'limit' => 1]);
	}

	/**
	 * Map names are unique: "Name", "Name (2)", "Name (3)"...
	 */
	private static function freeName(string $name): string {
		$candidate = $name;

		for ($i = 2; $i < 100 && self::nameTaken($candidate); $i++) {
			$candidate = mb_substr($name, 0, 120).' ('.$i.')';
		}

		return $candidate;
	}

	/**
	 * Light background for a zone: the zone color mixed with white.
	 */
	private static function tint(string $color): string {
		$rgb = array_map('hexdec', str_split(substr($color, 1), 2));

		return strtoupper(implode('', array_map(static fn(int $c): string =>
			str_pad(dechex((int) round($c + (255 - $c) * 0.85)), 2, '0', STR_PAD_LEFT), $rgb
		)));
	}
}
