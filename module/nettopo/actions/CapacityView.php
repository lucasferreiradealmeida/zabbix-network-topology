<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Page "Capacity planning": overview (where to look now), circuits with the load of each segment and the
** bottleneck, analysis & upgrades (what will saturate and what to do), the interfaces ranked by load, and the
** detail of one circuit (path, segment by segment, upgrade simulation, graph, interfaces, hints).
**
** Problems of the capacity templates link here with hostid + snmpindex: an interface of one circuit opens the
** circuit, a shared link opens the interfaces view focused on it.
**/

namespace Modules\NetTopo\Actions;

use API,
	APP,
	CArrayHelper,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CControllerResponseRedirect,
	CCsrfTokenHelper,
	CProfile,
	CRoleHelper,
	CUrl;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n,
	Modules\NetTopo\Lib\TopologyBuilder;

class CapacityView extends CController {

	public const PROFILE = 'web.nettopo.capacity.filter';

	public const SORT_FIELDS = ['cid', 'risk', 'load', 'avg', 'headroom', 'forecast', 'acesso', 'distribuicao',
		'backbone'
	];

	public const VIEWS = ['overview', 'circuits', 'upgrades', 'interfaces'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_groupids' =>	'array_id',
			'filter_hostids' =>		'array_id',
			'filter_search' =>		'string',
			'filter_alerts' =>		'in 0,1',
			'view' =>				'in '.implode(',', self::VIEWS),
			'circuit' =>			'string',
			'simulate' =>			'string',
			'hostid' =>				'id',
			'snmpindex' =>			'int32',
			'period' =>				'in 1h,6h,1d,7d,30d',
			'sort' =>				'in '.implode(',', self::SORT_FIELDS),
			'sortorder' =>			'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE.'.groupids', $this->getInput('filter_groupids', []), PROFILE_TYPE_ID);
			CProfile::updateArray(self::PROFILE.'.hostids', $this->getInput('filter_hostids', []), PROFILE_TYPE_ID);
			CProfile::update(self::PROFILE.'.search', trim($this->getInput('filter_search', '')), PROFILE_TYPE_STR);
			CProfile::update(self::PROFILE.'.alerts', (int) $this->getInput('filter_alerts', 0), PROFILE_TYPE_INT);
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::deleteIdx(self::PROFILE.'.groupids');
			CProfile::deleteIdx(self::PROFILE.'.hostids');
			CProfile::delete(self::PROFILE.'.search');
			CProfile::delete(self::PROFILE.'.alerts');
		}

		if ($this->hasInput('view')) {
			CProfile::update(self::PROFILE.'.view', $this->getInput('view'), PROFILE_TYPE_STR);
		}

		if ($this->hasInput('sort')) {
			CProfile::update(self::PROFILE.'.sort', $this->getInput('sort'), PROFILE_TYPE_STR);
			CProfile::update(self::PROFILE.'.sortorder', $this->getInput('sortorder', ZBX_SORT_DOWN), PROFILE_TYPE_STR);
		}

		$filter = self::getFilter();
		$module = APP::ModuleManager()->getActionModule();
		$config = $module !== null ? $module->getConfig() : [];
		$circuit = strtoupper(trim($this->getInput('circuit', '')));
		$view = CProfile::get(self::PROFILE.'.view', 'overview');
		$view = in_array($view, self::VIEWS, true) ? $view : 'overview';
		$focus = '';

		// Link of a problem: the interface (host + SNMP index) of a circuit or of a shared link.
		if ($circuit === '' && $this->hasInput('hostid') && $this->hasInput('snmpindex')) {
			$focus = $this->getInput('hostid').':'.$this->getInput('snmpindex');
			$all = (new CapacityBuilder([
				'max_hosts' => (int) ($config['max_hosts'] ?? 2000),
				'planning' => $config['capacity'] ?? []
			]))->build();
			$serves = $all['interfaces'][$focus]['circuits'] ?? [];

			if (count($serves) == 1) {
				$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))
					->setArgument('action', 'nettopo.capacity')
					->setArgument('circuit', (string) reset($serves))
				));

				return;
			}

			$view = 'interfaces';
		}

		// Capacity to simulate, in Mbps.
		$simulate = str_replace(',', '.', trim($this->getInput('simulate', '')));
		$simulate = is_numeric($simulate) && $simulate > 0 ? (float) $simulate * 1000000 : 0;

		// An ID outside the allowed characters cannot exist: it is just "not found".
		if (isset($all)) {
			$capacity = $all;
		}
		elseif ($circuit === '' || CapacityBuilder::isValidId($circuit)) {
			$capacity = (new CapacityBuilder([
				// The detail of a circuit shows the whole path, whatever the filter.
				'groupids' => $circuit === '' ? $filter['groupids'] : [],
				'hostids' => $circuit === '' ? $filter['hostids'] : [],
				'search' => $circuit === '' ? $filter['search'] : '',
				'circuit' => $circuit,
				'max_hosts' => (int) ($config['max_hosts'] ?? 2000),
				'planning' => $config['capacity'] ?? [],
				'simulate' => $simulate
			]))->build();
		}
		else {
			$capacity = ['circuits' => [], 'interfaces' => [], 'hosts' => [], 'summary' => [], 'warnings' => [],
				'planning' => CapacityBuilder::planning($config['capacity'] ?? [])
			];
		}

		$data = [
			'filter' => $filter + [
				'groups' => $filter['groupids']
					? CArrayHelper::renameObjectsKeys(API::HostGroup()->get([
						'output' => ['groupid', 'name'],
						'groupids' => $filter['groupids']
					]), ['groupid' => 'id'])
					: [],
				'hosts' => $filter['hostids']
					? CArrayHelper::renameObjectsKeys(API::Host()->get([
						'output' => ['hostid', 'name'],
						'hostids' => $filter['hostids']
					]), ['hostid' => 'id'])
					: []
			],
			'profileIdx' => self::PROFILE,
			'active_tab' => CProfile::get(self::PROFILE.'.active', 1),
			'view' => $view,
			'focus' => $focus,
			'sort' => CProfile::get(self::PROFILE.'.sort', 'risk'),
			'sortorder' => CProfile::get(self::PROFILE.'.sortorder', ZBX_SORT_DOWN),
			'circuit' => $circuit,
			'period' => $this->getInput('period', '1d'),
			'capacity' => $capacity,
			'severities' => TopologyBuilder::severities(),
			'assets_path' => $module !== null ? $module->getRelativePath().'/assets' : 'modules/nettopo/assets',
			'version' => $module !== null ? $module->getVersion() : '1',
			'dashboard_csrf' => CCsrfTokenHelper::get('nettopo.capacity.dashboard'),
			'can_create_dashboard' => $this->checkAccess(CRoleHelper::ACTIONS_EDIT_DASHBOARDS)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(I18n::t('Capacity planning'));
		$this->setResponse($response);
	}

	public static function getFilter(): array {
		return [
			'groupids' => array_map('strval', CProfile::getArray(self::PROFILE.'.groupids', [])),
			'hostids' => array_map('strval', CProfile::getArray(self::PROFILE.'.hostids', [])),
			'search' => (string) CProfile::get(self::PROFILE.'.search', ''),
			'alerts' => (int) CProfile::get(self::PROFILE.'.alerts', 0)
		];
	}
}
