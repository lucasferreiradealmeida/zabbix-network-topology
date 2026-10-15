<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix 7.0+.
** Adds "Monitoring > Network topology" and "Monitoring > Capacity planning" to the main menu.
**/

namespace Modules\NetTopo;

use APP,
	CMenuItem,
	Zabbix\Core\CModule;

use Modules\NetTopo\Lib\I18n;

class Module extends CModule {

	public function init(): void {
		// The menu exists only for logged in users (not on the login page or API calls).
		if (!APP::Component()->has('menu.main')) {
			return;
		}

		$menu = APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu();

		$item = (new CMenuItem(I18n::t('Network topology')))->setAction('nettopo.view');
		$capacity = (new CMenuItem(I18n::t('Capacity planning')))->setAction('nettopo.capacity');

		if ($menu->find(_('Maps')) !== null) {
			$menu->insertAfter(_('Maps'), $item);
		}
		else {
			$menu->add($item);
		}

		$menu->insertAfter(I18n::t('Network topology'), $capacity);
	}
}
