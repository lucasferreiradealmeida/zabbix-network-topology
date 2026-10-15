<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Page: filter + topology canvas. The graph itself is loaded by the page through "nettopo.data".
**
** Saved maps: "mapid" opens a map (its host selection goes into the filter); "mapid=0" closes it. The open
** map is remembered per user. Users that never chose a map get the initial map set by the editors.
**/

namespace Modules\NetTopo\Actions;

use API,
	APP,
	CArrayHelper,
	CController,
	CControllerResponseData,
	CControllerResponseFatal,
	CCsrfTokenHelper,
	CProfile,
	CUrl,
	CWebUser;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n,
	Modules\NetTopo\Lib\MapStore,
	Modules\NetTopo\Lib\TopologyBuilder;

class TopologyView extends CController {

	public const PROFILE = 'web.nettopo.filter';
	public const DEFAULT_SOURCES = ['lldp', 'cdp', 'tags'];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_groupids' =>	'array_id',
			'filter_hostids' =>		'array_id',
			'filter_mapids' =>		'array_id',
			'filter_sources' =>		'array',
			'filter_traffic' =>		'in 0,1',
			'filter_isolated' =>	'in 0,1',
			'circuit' =>			'string',
			'capacity' =>			'in 0,1',
			'mapid' =>				'string'
		];

		$ret = $this->validateInput($fields);

		if ($ret && $this->hasInput('mapid')) {
			$mapid = $this->getInput('mapid');
			$ret = $mapid === '0' || preg_match(MapStore::ID_PATTERN, $mapid);
		}

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return (bool) $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getActionModule();
		$store = new MapStore($module);
		$warnings = [];

		[$map, $mapid] = $this->currentMap($store, $warnings);

		if ($this->hasInput('filter_set')) {
			$sources = array_values(array_intersect(TopologyBuilder::SOURCES,
				array_filter($this->getInput('filter_sources', []), 'is_string')
			));

			self::saveFilter([
				'groupids' => $this->getInput('filter_groupids', []),
				'hostids' => $this->getInput('filter_hostids', []),
				'mapids' => $this->getInput('filter_mapids', []),
				'sources' => $sources,
				'traffic' => (int) $this->getInput('filter_traffic', 0),
				'isolated' => (int) $this->getInput('filter_isolated', 0)
			]);
		}
		elseif ($this->hasInput('filter_rst')) {
			// "Reset" of an open map brings back its selection; without a map, the filter is cleared.
			if ($map !== null) {
				self::saveFilter($map['filter']);
			}
			else {
				foreach (['groupids', 'hostids', 'mapids'] as $field) {
					CProfile::deleteIdx(self::PROFILE.'.'.$field);
				}

				foreach (['sources', 'traffic', 'isolated'] as $field) {
					CProfile::delete(self::PROFILE.'.'.$field);
				}
			}
		}

		$filter = self::getFilter();
		$config = $module !== null ? $store->config() : [];
		$max_hosts = (int) ($config['max_hosts'] ?? 2000);
		$cid = strtoupper(trim($this->getInput('circuit', '')));
		$circuit = $this->circuit(CapacityBuilder::isValidId($cid) ? $cid : '', $max_hosts);

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
					: [],
				'maps' => $filter['mapids']
					? CArrayHelper::renameObjectsKeys(API::Map()->get([
						'output' => ['sysmapid', 'name'],
						'sysmapids' => $filter['mapids']
					]), ['sysmapid' => 'id'])
					: []
			],
			'profileIdx' => self::PROFILE,
			'active_tab' => CProfile::get(self::PROFILE.'.active', 1),
			'assets_path' => $module !== null ? $module->getRelativePath().'/assets' : 'modules/nettopo/assets',
			'version' => $module !== null ? $module->getVersion() : '1',
			'scripts' => self::scripts(),
			'lib_url' => 'zabbix.php?action=nettopo.lib',
			'map_name' => $map !== null ? $map['name'] : '',
			'client' => [
				'data_url' => 'zabbix.php?action=nettopo.data',
				'map_url' => 'zabbix.php?action=nettopo.map',
				'view_url' => 'zabbix.php?action=nettopo.view',
				'settings_url' => 'zabbix.php?action=nettopo.settings',
				'capacity_url' => 'zabbix.php?action=nettopo.capacity',
				// Chain map: links colored by the utilization of capacity planning (page "Capacity planning").
				'capacity_mode' => (bool) $this->getInput('capacity', 0),
				'csrf_token' => CCsrfTokenHelper::get('nettopo.map'),
				'request' => [
					'groupids' => $filter['groupids'],
					// The hosts of a highlighted circuit are always shown (with no filter, all hosts already are).
					'hostids' => ($filter['groupids'] || $filter['hostids'])
						? array_values(array_unique(array_merge($filter['hostids'], $circuit['hostids'] ?? [])))
						: [],
					'filter_hostids' => $filter['hostids'],
					'circuit' => $circuit['cid'] ?? '',
					'mapids' => $filter['mapids'],
					'sources' => $filter['sources'],
					'traffic' => $filter['traffic']
				],
				'filter' => [
					'groupids' => $filter['groupids'],
					'hostids' => $filter['hostids'],
					'mapids' => $filter['mapids'],
					'sources' => $filter['sources'],
					'traffic' => $filter['traffic'],
					'isolated' => $filter['isolated']
				],
				'show_isolated' => (bool) $filter['isolated'],
				'map' => $map,
				'maps' => array_values($store->listMaps()),
				'warnings' => $warnings,
				'can_edit_shared' => $store->canEditShared(),
				'can_save' => MapStore::canHavePersonal(),
				'is_super_admin' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
				'refresh' => max(10, (int) CWebUser::getRefresh()),
				'severities' => TopologyBuilder::severities(),
				'theme' => getUserTheme(CWebUser::$data),
				// Dates and times follow the language of the user in Zabbix, not the one of the browser.
				'locale' => str_replace('_', '-', I18n::lang()),
				'circuit' => $circuit,
				'strings' => I18n::all()
			]
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(I18n::t('Network topology').($map !== null ? ': '.$map['name'] : ''));
		$this->setResponse($response);
	}

	/**
	 * Map to show: the one asked for in the URL, the one opened last, or the initial map for new users.
	 *
	 * @return array  [map with filter and layout or null, map id or '']
	 */
	private function currentMap(MapStore $store, array &$warnings): array {
		$current = CProfile::get(MapStore::PROFILE_CURRENT, null);
		$map = null;
		$apply_filter = false;

		if ($this->hasInput('mapid')) {
			$requested = $this->getInput('mapid');
			$current = '';

			if ($requested !== '0') {
				$map = $store->get($requested);

				if ($map !== null) {
					$current = $requested;
					$apply_filter = !$this->hasInput('filter_set');
				}
				else {
					$warnings[] = I18n::t('The map was not found: it was deleted or it is a personal map of another user.');
				}
			}
		}
		elseif ($current === null) {
			// First visit: the initial map chosen by the editors, if any.
			$current = '';
			$default = $store->defaultMapId();

			if ($default !== '') {
				$map = $store->get($default);

				if ($map !== null) {
					$current = $default;
					$apply_filter = true;
				}
			}
		}
		elseif ($current !== '') {
			$map = $store->get((string) $current);

			if ($map === null) {
				$current = '';
			}
		}

		if ($this->hasInput('mapid') || CProfile::get(MapStore::PROFILE_CURRENT, null) !== $current) {
			CProfile::update(MapStore::PROFILE_CURRENT, (string) $current, PROFILE_TYPE_STR);
		}

		if ($apply_filter) {
			self::saveFilter($map['filter']);
		}

		return [$map, (string) $current];
	}

	private static function saveFilter(array $filter): void {
		CProfile::updateArray(self::PROFILE.'.groupids', $filter['groupids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::PROFILE.'.hostids', $filter['hostids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::PROFILE.'.mapids', $filter['mapids'], PROFILE_TYPE_ID);
		CProfile::update(self::PROFILE.'.sources', $filter['sources'] ? implode(',', $filter['sources']) : 'none',
			PROFILE_TYPE_STR
		);
		CProfile::update(self::PROFILE.'.traffic', (int) $filter['traffic'], PROFILE_TYPE_INT);
		CProfile::update(self::PROFILE.'.isolated', (int) $filter['isolated'], PROFILE_TYPE_INT);
	}

	/**
	 * Circuit to highlight on the map (link "Map" of the capacity planning page).
	 */
	private function circuit(string $cid, int $max_hosts): ?array {
		if ($cid === '') {
			return null;
		}

		$capacity = (new CapacityBuilder(['circuit' => $cid, 'max_hosts' => $max_hosts]))->build();
		$circuit = $capacity['circuits'][$cid] ?? null;

		if ($circuit === null) {
			return ['cid' => $cid, 'hostids' => [], 'summary' => I18n::t('Circuit %1$s not found (no interface with this ID in its description).', $cid), 'url' => ''];
		}

		$names = [
			'acesso' => I18n::t('Last mile'),
			'distribuicao' => I18n::t('Distribution'),
			'backbone' => I18n::t('Backbone')
		];

		if ($circuit['down']) {
			$summary = I18n::t('Link down');
		}
		elseif ($circuit['bottleneck'] !== null) {
			$interface = $capacity['interfaces'][$circuit['segments'][$circuit['bottleneck']]['interface']];
			$summary = I18n::t('Bottleneck: %1$s', $names[$circuit['bottleneck']].' '.round($circuit['load']).'%')
				.' ('.$interface['host'].' '.$interface['name'].')';
		}
		else {
			$summary = I18n::t('No data yet');
		}

		return [
			'cid' => $cid,
			'hostids' => array_map('strval', array_keys($circuit['hostids'])),
			'summary' => $summary,
			'url' => (new CUrl('zabbix.php'))
				->setArgument('action', 'nettopo.capacity')
				->setArgument('circuit', $cid)
				->getUrl()
		];
	}

	/**
	 * Page scripts with the size they must have, so the page can tell a missing file from a truncated one.
	 */
	private static function scripts(): array {
		$scripts = [];

		foreach (TopologyLib::SCRIPTS as $key => [$file, $global]) {
			$size = @filesize(TopologyLib::assetsDir().$file);
			$scripts[] = ['key' => $key, 'file' => $file, 'global' => $global, 'size' => $size === false ? 0 : $size];
		}

		return $scripts;
	}

	public static function getFilter(): array {
		$sources = (string) CProfile::get(self::PROFILE.'.sources', implode(',', self::DEFAULT_SOURCES));

		return [
			'groupids' => array_map('strval', CProfile::getArray(self::PROFILE.'.groupids', [])),
			'hostids' => array_map('strval', CProfile::getArray(self::PROFILE.'.hostids', [])),
			'mapids' => array_map('strval', CProfile::getArray(self::PROFILE.'.mapids', [])),
			'sources' => $sources === 'none'
				? []
				: array_values(array_intersect(TopologyBuilder::SOURCES, explode(',', $sources))),
			'traffic' => (int) CProfile::get(self::PROFILE.'.traffic', 1),
			'isolated' => (int) CProfile::get(self::PROFILE.'.isolated', 1)
		];
	}
}
