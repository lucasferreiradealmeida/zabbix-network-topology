<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Saves the settings form (Super admin, CSRF protected) and goes back to the settings page.
**/

namespace Modules\NetTopo\Actions;

use APP,
	CController,
	CControllerResponseRedirect,
	CMessageHelper,
	CUrl,
	Throwable;

use Modules\NetTopo\Lib\CapacityBuilder,
	Modules\NetTopo\Lib\I18n,
	Modules\NetTopo\Lib\MapStore;

class TopologySettingsUpdate extends CController {

	protected function checkInput(): bool {
		$fields = [
			'editors_user_type' =>	'required|in '.USER_TYPE_ZABBIX_ADMIN.','.USER_TYPE_SUPER_ADMIN,
			'editors_usrgrpids' =>	'array_id',
			'default_map' =>		'string',
			'max_hosts' =>			'required|int32|ge 10|le 100000',
			'target_acesso' =>		'int32|ge 10|le 100',
			'target_distribuicao' =>	'int32|ge 10|le 100',
			'target_backbone' =>	'int32|ge 10|le 100',
			'horizon' =>			'int32|ge 1|le 1095'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse($this->back());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$store = new MapStore(APP::ModuleManager()->getActionModule());

		try {
			$store->saveSettings([
				'editors' => [
					'user_type' => (int) $this->getInput('editors_user_type'),
					'usrgrpids' => $this->getInput('editors_usrgrpids', [])
				],
				'default_map' => $this->getInput('default_map', ''),
				'max_hosts' => (int) $this->getInput('max_hosts'),
				'capacity' => [
					'targets' => [
						'acesso' => $this->getInput('target_acesso', CapacityBuilder::DEFAULT_TARGETS['acesso']),
						'distribuicao' => $this->getInput('target_distribuicao',
							CapacityBuilder::DEFAULT_TARGETS['distribuicao']
						),
						'backbone' => $this->getInput('target_backbone', CapacityBuilder::DEFAULT_TARGETS['backbone'])
					],
					'horizon' => $this->getInput('horizon', CapacityBuilder::DEFAULT_HORIZON)
				]
			]);

			CMessageHelper::setSuccessTitle(I18n::t('Settings saved.'));
		}
		catch (Throwable $e) {
			CMessageHelper::setErrorTitle(I18n::t('Cannot save the settings.'));
			CMessageHelper::addError($e->getMessage());
		}

		$this->setResponse($this->back());
	}

	private function back(): CControllerResponseRedirect {
		return new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'nettopo.settings'));
	}
}
