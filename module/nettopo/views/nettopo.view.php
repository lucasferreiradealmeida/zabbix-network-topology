<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
**
** @var CView $this
** @var array $data
**/

use Modules\NetTopo\Lib\I18n;

$this->addJsFile('layout.mode.js');
$this->addCssFile($data['assets_path'].'/css/nettopo.css?v='.urlencode($data['version']));
$this->enableLayoutModes();

$web_layout_mode = $this->getLayoutMode();
$filter = $data['filter'];

$html_page = (new CHtmlPage())
	->setTitle(I18n::t('Network topology'))
	->setWebLayoutMode($web_layout_mode)
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(get_icon('kioskmode', ['mode' => $web_layout_mode]))
		))->setAttribute('aria-label', _('Content controls'))
	);

if ($web_layout_mode == ZBX_LAYOUT_NORMAL) {
	$source_options = [
		['label' => I18n::t('LLDP'), 'value' => 'lldp'],
		['label' => I18n::t('CDP'), 'value' => 'cdp'],
		['label' => I18n::t('Host tags'), 'value' => 'tags'],
		['label' => I18n::t('Trigger dependencies'), 'value' => 'deps']
	];

	$html_page->addItem(
		(new CFilter())
			->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'nettopo.view'))
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
					)
					->addRow(new CLabel(I18n::t('Links from maps'), 'filter_mapids__ms'),
						(new CMultiSelect([
							'name' => 'filter_mapids[]',
							'object_name' => 'sysmaps',
							'data' => $filter['maps'],
							'popup' => [
								'parameters' => [
									'srctbl' => 'sysmaps',
									'srcfld1' => 'sysmapid',
									'dstfrm' => 'zbx_filter',
									'dstfld1' => 'filter_mapids_'
								]
							]
						]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
					),
				(new CFormList())
					->addRow(I18n::t('Link sources'),
						(new CCheckBoxList('filter_sources'))
							->setOptions($source_options)
							->setChecked($filter['sources'])
							->setVertical()
					)
					->addRow(new CLabel(I18n::t('Show traffic on links'), 'filter_traffic'),
						(new CCheckBox('filter_traffic'))->setChecked($filter['traffic'] == 1)
					)
					->addRow(new CLabel(I18n::t('Show hosts without links'), 'filter_isolated'),
						(new CCheckBox('filter_isolated'))->setChecked($filter['isolated'] == 1)
					)
			])
			->addVar('action', 'nettopo.view')
	);
}

$html_page->addItem(
	(new CDiv())
		->setId('nettopo')
		->addClass('nettopo')
		->addClass(in_array($data['client']['theme'], ['dark-theme', 'hc-dark'], true) ? 'nettopo-dark' : 'nettopo-light')
);

$html_page->show();

// Graph libraries (Cytoscape.js + fCoSE layout, MIT) and the page script are loaded only on this page.
// Errors while loading them are recorded, so the page can explain what went wrong (see nettopo.view.js.php).
$assets = $data['assets_path'];
$version = urlencode($data['version']);

(new CScriptTag(
	'window.NetTopoLoadErrors = [];'.
	'window.addEventListener("error", function(e) {'.
		'var tag = e.target && e.target.tagName === "SCRIPT" ? e.target : null, src = tag ? tag.src : e.filename;'.
		'if (src && src.indexOf('.json_encode('/'.$assets.'/').') !== -1) {'.
			'NetTopoLoadErrors.push(src.replace(/^.*\/assets\//, "").replace(/\?.*$/, "")'.
				' + ": " + (tag ? "not loaded" : e.message));'.
		'}'.
	'}, true);'
))->show();

foreach ($data['scripts'] as $script) {
	echo (new CTag('script', true))->setAttribute('src', $assets.'/'.$script['file'].'?v='.$version);
}

$this->includeJsFile('nettopo.view.js.php', [
	'boot' => [
		'scripts' => array_map(static fn(array $script): array => $script + [
			'url' => $assets.'/'.$script['file'].'?v='.$version
		], $data['scripts']),
		'lib_url' => $data['lib_url'],
		'assets_path' => $assets
	],
	'client' => $data['client']
]);
