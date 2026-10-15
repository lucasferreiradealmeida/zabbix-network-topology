<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Creates (or updates) a native Zabbix dashboard for one circuit: a gauge per segment with the load of its
** most loaded interface, the utilization graph of the whole path and the capacity problems of its devices.
**/

namespace Modules\NetTopo\Actions;

use API,
	APP,
	CController,
	CControllerResponseRedirect,
	CMessageHelper,
	CRoleHelper,
	CUrl;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n;

class CapacityDashboard extends CController {

	private const SEGMENT_COLORS = ['acesso' => '42A5F5', 'distribuicao' => 'AB47BC', 'backbone' => 'FF7043'];

	protected function checkInput(): bool {
		$ret = $this->validateInput(['circuit' => 'required|not_empty|string']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'nettopo.capacity')
			));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER
			&& $this->checkAccess(CRoleHelper::ACTIONS_EDIT_DASHBOARDS);
	}

	protected function doAction(): void {
		$cid = strtoupper(trim($this->getInput('circuit')));
		$back = (new CUrl('zabbix.php'))->setArgument('action', 'nettopo.capacity');

		if (!CapacityBuilder::isValidId($cid)) {
			CMessageHelper::setErrorTitle(I18n::t('Circuit %1$s not found (no interface with this ID in its description).', $cid));
			$this->setResponse(new CControllerResponseRedirect($back));

			return;
		}

		$back->setArgument('circuit', $cid);

		$module = APP::ModuleManager()->getActionModule();
		$capacity = (new CapacityBuilder([
			'circuit' => $cid,
			'max_hosts' => (int) (($module !== null ? $module->getConfig() : [])['max_hosts'] ?? 2000)
		]))->build();

		$circuit = $capacity['circuits'][$cid] ?? null;

		if ($circuit === null) {
			CMessageHelper::setErrorTitle(I18n::t('Circuit %1$s not found (no interface with this ID in its description).', $cid));
			$this->setResponse(new CControllerResponseRedirect($back));

			return;
		}

		$interfaces = $capacity['interfaces'];

		// Fixed name (dashboard names are unique in Zabbix): the same dashboard for every user and language.
		$name = 'Capacidade - '.$cid;

		try {
			$pages = [['widgets' => $this->widgets($circuit, $interfaces)]];

			$existing = API::Dashboard()->get([
				'output' => ['dashboardid'],
				'filter' => ['name' => $name]
			]);

			if ($existing && !API::Dashboard()->get([
					'output' => [],
					'dashboardids' => [$existing[0]['dashboardid']],
					'editable' => true
				])) {
				// Somebody else's dashboard that this user can only see: open it as it is.
				CMessageHelper::setWarningTitle(I18n::t('The dashboard "%1$s" already exists and you cannot edit it: it was opened as it is.', $name));
				$this->setResponse(new CControllerResponseRedirect(
					(new CUrl('zabbix.php'))
						->setArgument('action', 'dashboard.view')
						->setArgument('dashboardid', $existing[0]['dashboardid'])
				));

				return;
			}

			if ($existing) {
				$result = API::Dashboard()->update([
					'dashboardid' => $existing[0]['dashboardid'],
					'pages' => $pages
				]);
			}
			else {
				$result = API::Dashboard()->create([
					'name' => $name,
					'display_period' => 30,
					'auto_start' => 1,
					'private' => PUBLIC_SHARING,
					'pages' => $pages
				]);
			}
		}
		catch (\Throwable $e) {
			$result = false;
			error($e->getMessage());
		}

		if (!$result) {
			CMessageHelper::setErrorTitle(I18n::t('Cannot create the dashboard of circuit %1$s', $cid));
			$this->setResponse(new CControllerResponseRedirect($back));

			return;
		}

		$this->setResponse(new CControllerResponseRedirect(
			(new CUrl('zabbix.php'))
				->setArgument('action', 'dashboard.view')
				->setArgument('dashboardid', $result['dashboardids'][0])
		));
	}

	private function widgets(array $circuit, array $interfaces): array {
		$segment_names = [
			'acesso' => I18n::t('Last mile'),
			'distribuicao' => I18n::t('Distribution'),
			'backbone' => I18n::t('Backbone')
		];

		$itemids = [];

		foreach ($circuit['interfaces'] as $id => $segment) {
			foreach (['util', 'p95'] as $metric) {
				if (isset($interfaces[$id]['items'][$metric])) {
					$itemids[] = $interfaces[$id]['items'][$metric];
				}
			}
		}

		$items = $itemids
			? API::Item()->get([
				'output' => ['itemid', 'name'],
				'itemids' => $itemids,
				'preservekeys' => true
			])
			: [];

		$widgets = [];

		// One gauge per measured segment: its most loaded interface (p95 when available).
		$x = 0;
		$measured = array_filter(CapacityBuilder::SEGMENTS,
			static fn($segment) => $circuit['segments'][$segment]['interface'] !== null
		);
		$width = $measured ? intdiv(DASHBOARD_MAX_COLUMNS, count($measured)) : DASHBOARD_MAX_COLUMNS;

		foreach ($measured as $segment) {
			$interface = $interfaces[$circuit['segments'][$segment]['interface']];
			$itemid = $interface['items']['p95'] ?? $interface['items']['util'] ?? null;

			if ($itemid === null) {
				continue;
			}

			$widgets[] = [
				'type' => 'gauge',
				'name' => $segment_names[$segment].': '.$interface['host'].' '.$interface['name']
					.($circuit['bottleneck'] === $segment ? ' ('.I18n::t('bottleneck').')' : ''),
				'x' => $x,
				'y' => 0,
				'width' => $width,
				'height' => 5,
				'fields' => [
					['type' => ZBX_WIDGET_FIELD_TYPE_ITEM, 'name' => 'itemid.0', 'value' => $itemid],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'min', 'value' => '0'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'max', 'value' => '100'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'description',
						'value' => isset($interface['items']['p95']) ? I18n::t('p95 of the utilization') : I18n::t('Utilization')
					],
					['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'desc_size', 'value' => 10],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.0.color', 'value' => '59DB8F'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.0.threshold', 'value' => '0'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.1.color', 'value' => 'FFD54F'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.1.threshold', 'value' => '70'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.2.color', 'value' => 'E57373'],
					['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'thresholds.2.threshold', 'value' => '90']
				]
			];
			$x += $width;
		}

		// Utilization of every interface of the path, colored by segment.
		$fields = [
			['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'lefty_min', 'value' => '0'],
			['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'lefty_max', 'value' => '100'],
			['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'righty', 'value' => 0],
		];
		$ds = 0;

		foreach (CapacityBuilder::SEGMENTS as $segment) {
			foreach ($circuit['segments'][$segment]['interfaces'] as $id) {
				$itemid = $interfaces[$id]['items']['util'] ?? null;

				if ($itemid === null || !array_key_exists($itemid, $items)) {
					continue;
				}

				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'ds.'.$ds.'.hosts.0',
					'value' => $interfaces[$id]['host']
				];
				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'ds.'.$ds.'.items.0',
					'value' => $items[$itemid]['name']
				];
				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'ds.'.$ds.'.color',
					'value' => self::shade(self::SEGMENT_COLORS[$segment], $ds)
				];
				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'ds.'.$ds.'.width', 'value' => 2];
				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'ds.'.$ds.'.fill', 'value' => 0];
				$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'ds.'.$ds.'.transparency', 'value' => 0];
				$ds++;
			}
		}

		if ($ds > 0) {
			$widgets[] = [
				'type' => 'svggraph',
				'name' => I18n::t('Utilization of the path').' - '.$circuit['cid'],
				'x' => 0,
				'y' => 5,
				'width' => DASHBOARD_MAX_COLUMNS,
				'height' => 6,
				'fields' => $fields
			];
		}

		// Problems of the interfaces of the path: capacity, and link down from the vendor templates
		// (both use the tag "interface").
		$fields = [
			['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'evaltype', 'value' => TAG_EVAL_TYPE_OR],
			['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'show_tags', 'value' => 3]
		];
		$hostids = [];
		$names = [];

		foreach (array_keys($circuit['interfaces']) as $id) {
			$hostids[$interfaces[$id]['hostid']] = true;
			$names[$interfaces[$id]['name']] = true;
		}

		$i = 0;

		foreach (array_keys($names) as $name) {
			$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'tags.'.$i.'.tag', 'value' => 'interface'];
			$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => 'tags.'.$i.'.operator', 'value' => TAG_OPERATOR_EQUAL];
			$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => 'tags.'.$i.'.value', 'value' => (string) $name];
			$i++;
		}

		$i = 0;

		foreach (array_keys($hostids) as $hostid) {
			$fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_HOST, 'name' => 'hostids.'.$i++, 'value' => (string) $hostid];
		}

		$widgets[] = [
			'type' => 'problems',
			'name' => I18n::t('Problems of the interfaces of the path'),
			'x' => 0,
			'y' => 11,
			'width' => DASHBOARD_MAX_COLUMNS,
			'height' => 5,
			'fields' => $fields
		];

		return $widgets;
	}

	/**
	 * Lighter or darker shade of a segment color, so several interfaces of a segment can be told apart.
	 */
	private static function shade(string $hex, int $index): string {
		$factor = [1.0, 0.75, 1.25, 0.6, 1.4][$index % 5];
		$rgb = array_map('hexdec', str_split($hex, 2));

		return implode('', array_map(static fn($c) => sprintf('%02X', min(255, (int) round($c * $factor))), $rgb));
	}
}
