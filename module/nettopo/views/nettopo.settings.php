<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Settings page (Super admin).
**
** @var CView $this
** @var array $data
**/

use Modules\NetTopo\Lib\I18n;

$this->addCssFile($data['assets_path'].'/css/nettopo.css?v='.urlencode($data['version']));

$default_map = (new CSelect('default_map'))
	->setId('default_map')
	->setFocusableElementId('default_map_focus')
	->setValue($data['default_map'])
	->addOption(new CSelectOption('', I18n::t('None (free selection)')));

foreach ($data['maps'] as $map) {
	$default_map->addOption(new CSelectOption($map['id'], $map['name']));
}

$form = (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'nettopo.settings.update')->getUrl()))
	->setName('nettopo_settings')
	->addItem((new CVar(CSRF_TOKEN_NAME, $data['csrf_token']))->removeId());

$form_list = (new CFormList())
	->addRow(I18n::t('Who can change public maps'),
		(new CRadioButtonList('editors_user_type', $data['editors']['user_type']))
			->addValue(I18n::t('Admins and Super admins'), USER_TYPE_ZABBIX_ADMIN)
			->addValue(I18n::t('Only Super admins'), USER_TYPE_SUPER_ADMIN)
			->setModern()
	)
	->addRow(new CLabel(I18n::t('Also these user groups'), 'editors_usrgrpids__ms'),
		(new CMultiSelect([
			'name' => 'editors_usrgrpids[]',
			'object_name' => 'usersGroups',
			'data' => $data['usrgrps'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'usrgrp',
					'srcfld1' => 'usrgrpid',
					'dstfrm' => 'nettopo_settings',
					'dstfld1' => 'editors_usrgrpids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	)
	->addRow('', (new CDiv(I18n::t('Everybody can open public maps, move hosts on the screen and save personal maps. Only these users change the public maps (the standard for all teams).')))
		->addClass('nt-set-hint')
	)
	->addRow(new CLabel(I18n::t('Initial map'), 'default_map_focus'), $default_map)
	->addRow('', (new CDiv(I18n::t('Opened for users that have not chosen a map yet.')))->addClass('nt-set-hint'))
	->addRow((new CLabel(I18n::t('Host limit per map'), 'max_hosts'))->setAsteriskMark(),
		(new CNumericBox('max_hosts', $data['max_hosts'], 6))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
	);

// Capacity planning: targets of the upgrade recommendation and the planning horizon.
$capacity = $data['capacity'];
$target = static fn(string $name, int $value): array => [
	(new CNumericBox($name, $value, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
	' %'
];

$capacity_list = (new CFormList())
	->addRow(new CLabel(I18n::t('Last mile (circuit ports)'), 'target_acesso'),
		$target('target_acesso', $capacity['targets']['acesso'])
	)
	->addRow(new CLabel(I18n::t('Distribution (shared links)'), 'target_distribuicao'),
		$target('target_distribuicao', $capacity['targets']['distribuicao'])
	)
	->addRow(new CLabel(I18n::t('Backbone (shared links)'), 'target_backbone'),
		$target('target_backbone', $capacity['targets']['backbone'])
	)
	->addRow('', (new CDiv(I18n::t('Utilization target after an upgrade')))->addClass('nt-set-hint'))
	->addRow(new CLabel(I18n::t('Planning horizon (days)'), 'horizon'),
		(new CNumericBox('horizon', $capacity['horizon'], 4))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
	)
	->addRow('', (new CDiv(I18n::t('The recommended capacity keeps each segment below its target until the end of the horizon, by the growth trend of the p95.')))
		->addClass('nt-set-hint')
	);

$form->addItem(
	(new CTabView())
		->addTab('settings', I18n::t('Settings'), $form_list)
		->addTab('capacity', I18n::t('Capacity planning'), $capacity_list)
		->setFooter(makeFormFooter(new CSubmit('update', _('Update'))))
);

// Public maps.
$table = (new CTableInfo())->setHeader([
	I18n::t('Name'), I18n::t('Last change'), I18n::t('Size'), I18n::t('Native map'), ''
]);

foreach ($data['maps'] as $map) {
	$open = (new CUrl('zabbix.php'))
		->setArgument('action', 'nettopo.view')
		->setArgument('mapid', $map['id']);

	$native = '';

	if ($map['sysmapid'] !== '' && array_key_exists($map['sysmapid'], $data['native'])) {
		$native = new CLink($data['native'][$map['sysmapid']], (new CUrl('zabbix.php'))
			->setArgument('action', 'map.view')
			->setArgument('sysmapid', $map['sysmapid'])
		);
	}

	$table->addRow([
		[new CLink($map['name'], $open), $map['default'] ? [' ', (new CSpan(I18n::t('initial')))->addClass('nt-set-badge')] : null],
		$map['updated'] ? zbx_date2str(DATE_TIME_FORMAT, $map['updated']).' · '.$map['updated_by'] : '',
		round(($data['sizes'][$map['id']] ?? 0) / 1024, 1).' KB',
		$native,
		new CLink(I18n::t('Open'), $open)
	]);
}

$usage = $data['usage'];

$html_page = (new CHtmlPage())
	->setTitle(I18n::t('Network topology settings'))
	->setControls((new CTag('nav', true,
		(new CList())->addItem(new CRedirectButton(I18n::t('Back to the map'),
			(new CUrl('zabbix.php'))->setArgument('action', 'nettopo.view')
		))
	))->setAttribute('aria-label', _('Content controls')))
	->addItem($form)
	->addItem(
		(new CDiv([
			(new CTag('h4', true, I18n::t('Public maps'))),
			(new CDiv(I18n::t('Storage used by public maps: %1$s KB of %2$s KB.',
				round($usage['used'] / 1024, 1), round($usage['limit'] / 1024)
			)))->addClass('nt-set-hint'),
			$table
		]))->addClass('nt-set-maps')
	);

$html_page->show();
