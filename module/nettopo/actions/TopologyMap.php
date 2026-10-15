<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Saved maps: create, save (selection + drawing), rename, delete, choose the initial map and publish as a
** native Zabbix map. JSON answers; the page sends the CSRF token of this action.
**/

namespace Modules\NetTopo\Actions;

use APP,
	CController,
	CControllerResponseData,
	CMessageHelper,
	CUrl,
	RuntimeException,
	Throwable;

use Modules\NetTopo\Lib\I18n,
	Modules\NetTopo\Lib\MapStore,
	Modules\NetTopo\Lib\NativeMap,
	Modules\NetTopo\Lib\TopologyBuilder;

class TopologyMap extends CController {

	protected function checkInput(): bool {
		$fields = [
			'operation' =>	'required|in save,rename,delete,default,publish',
			'mapid' =>		'string',
			'name' =>		'string',
			'scope' =>		'in '.MapStore::SCOPE_SHARED.','.MapStore::SCOPE_PERSONAL,
			'rev' =>		'int32',
			'filter' =>		'string',
			'layout' =>		'string',
			'positions' =>	'string'
		];

		$ret = $this->validateInput($fields);
		$mapid = $this->getInput('mapid', '');

		if ($ret && $mapid !== '' && !preg_match(MapStore::ID_PATTERN, $mapid)) {
			$ret = false;
		}

		if (!$ret) {
			$messages = array_column(CMessageHelper::getMessages(), 'message');
			CMessageHelper::clear();

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => $messages ?: [I18n::t('Invalid request.')]]
			])]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !\CWebUser::isGuest();
	}

	protected function doAction(): void {
		$store = new MapStore(APP::ModuleManager()->getActionModule());
		$mapid = $this->getInput('mapid', '');
		$output = [];

		try {
			switch ($this->getInput('operation')) {
				case 'save':
					$data = [
						'filter' => $this->getInput('filter', ''),
						'layout' => $this->getInput('layout', '')
					];

					if ($mapid !== '') {
						$data['id'] = $mapid;

						if ($this->hasInput('rev')) {
							$data['rev'] = (int) $this->getInput('rev');
						}

						$output['message'] = I18n::t('Map saved.');
					}
					else {
						$data['name'] = $this->getInput('name', '');
						$data['scope'] = $this->getInput('scope', MapStore::SCOPE_PERSONAL);
						$output['message'] = I18n::t('Map created.');
					}

					$output['map'] = $store->save($data);
					break;

				case 'rename':
					$output['map'] = $store->rename($mapid, $this->getInput('name', ''));
					$output['message'] = I18n::t('Map renamed.');
					break;

				case 'delete':
					$store->delete($mapid);
					$output['message'] = I18n::t('Map deleted.');
					break;

				case 'default':
					$store->setDefault($mapid);
					$output['message'] = $mapid !== ''
						? I18n::t('Users that have not chosen a map will now open this one.')
						: I18n::t('There is no initial map anymore.');
					break;

				case 'publish':
					$output += $this->publish($store, $mapid);
					break;
			}

			$output['maps'] = array_values($store->listMaps());
		}
		catch (Throwable $e) {
			$output = ['error' => ['messages' => [$e->getMessage()]]];
		}

		foreach (CMessageHelper::getMessages() as $message) {
			if ($message['type'] === CMessageHelper::MESSAGE_TYPE_ERROR) {
				$output['error']['messages'][] = $message['message'];
			}
		}

		CMessageHelper::clear();

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
		]));
	}

	private function publish(MapStore $store, string $mapid): array {
		$map = $store->get($mapid);

		if ($map === null) {
			throw new RuntimeException(I18n::t('The map no longer exists.'));
		}

		if (!$map['can_edit']) {
			throw new RuntimeException(I18n::t('You are not allowed to change public maps.'));
		}

		$module = APP::ModuleManager()->getActionModule();
		$filter = $map['filter'];

		$topology = (new TopologyBuilder([
			'groupids' => $filter['groupids'],
			'hostids' => $filter['hostids'],
			'mapids' => $filter['mapids'],
			'sources' => $filter['sources'],
			'traffic' => false,
			'max_hosts' => (int) ($module->getConfig()['max_hosts'] ?? 2000)
		]))->build();

		// Positions as shown in the page (only the visible nodes); the saved ones complete them.
		$positions = MapStore::sanitizeLayout(['nodes' => json_decode($this->getInput('positions', '{}'), true)])['nodes']
			?: $map['layout']['nodes'];

		$result = NativeMap::publish($map, $topology, $positions);
		$store->setSysmapId($mapid, $result['sysmapid']);

		return [
			'map' => $store->get($mapid),
			'message' => ($result['created']
				? I18n::t('Native map "%1$s" created.', $result['name'])
				: I18n::t('Native map "%1$s" updated.', $result['name'])
			),
			'url' => (new CUrl('zabbix.php'))
				->setArgument('action', 'map.view')
				->setArgument('sysmapid', $result['sysmapid'])
				->getUrl()
		];
	}
}
