<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Settings page (Super admin): who edits public maps, initial map, host limit; list of public maps.
**/

namespace Modules\NetTopo\Actions;

use API,
	APP,
	CArrayHelper,
	CController,
	CControllerResponseData,
	CCsrfTokenHelper;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n,
	Modules\NetTopo\Lib\MapStore;

class TopologySettings extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getActionModule();
		$store = new MapStore($module);
		$config = $store->config();
		$editors = MapStore::editors($config);

		$maps = array_filter($store->listMaps(), static fn(array $map): bool => $map['scope'] === MapStore::SCOPE_SHARED);
		$sizes = [];

		foreach ($config['maps'] ?? [] as $id => $stored) {
			$sizes[$id] = strlen(json_encode($stored));
		}

		$sysmapids = array_filter(array_column($maps, 'sysmapid'));
		$native = $sysmapids
			? array_column(API::Map()->get(['output' => ['sysmapid', 'name'], 'sysmapids' => array_values($sysmapids)]),
				'name', 'sysmapid'
			)
			: [];

		$data = [
			'csrf_token' => CCsrfTokenHelper::get('nettopo.settings.update'),
			'assets_path' => $module->getRelativePath().'/assets',
			'version' => $module->getVersion(),
			'editors' => $editors,
			'usrgrps' => $editors['usrgrpids']
				? CArrayHelper::renameObjectsKeys(API::UserGroup()->get([
					'output' => ['usrgrpid', 'name'],
					'usrgrpids' => $editors['usrgrpids']
				]), ['usrgrpid' => 'id'])
				: [],
			'default_map' => $store->defaultMapId(),
			'max_hosts' => (int) ($config['max_hosts'] ?? 2000),
			'capacity' => CapacityBuilder::planning($config['capacity'] ?? []),
			'maps' => $maps,
			'sizes' => $sizes,
			'native' => $native,
			'usage' => $store->usage()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(I18n::t('Network topology settings'));
		$this->setResponse($response);
	}
}
