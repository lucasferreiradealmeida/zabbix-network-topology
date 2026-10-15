// A saved map whose filter was changed: the header says so, "Restore" brings the saved selection back,
// and the filter "Reset" button does the same. Usage: node selection.js <mapid>
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';

(async () => {
	const mapid = process.argv[2];
	const browser = await chromium.launch();
	const page = await browser.newPage({viewport: {width: 1400, height: 900}});
	page.on('pageerror', (e) => console.log('PAGEERROR', e.message));
	await page.goto(base + '/index.php');
	await page.fill('#name', 'Admin');
	await page.fill('#password', 'zabbix');
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);

	const ready = () => page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	const state = () => page.evaluate(() => ({
		map: window.NetTopo.app.map && window.NetTopo.app.map.name,
		sources: window.NetTopo.app.config.filter.sources.join(','),
		changed: window.NetTopo.app.selectionChanged(),
		text: document.querySelector('.ntp-state').textContent
	}));

	await page.goto(base + '/zabbix.php?action=nettopo.view&mapid=' + mapid);
	await ready();
	console.log('opened      ', JSON.stringify(await state()));

	// Change the filter (only LLDP) as the filter form would.
	await page.goto(base + '/zabbix.php?action=nettopo.view&filter_set=1&filter_groupids[]=23&filter_sources[]=lldp'
		+ '&filter_traffic=1&filter_isolated=1');
	await ready();
	console.log('filter LLDP ', JSON.stringify(await state()));

	await Promise.all([page.waitForURL(/mapid=/), page.click('.ntp-header .ntp-btn-text >> nth=0')]);
	await ready();
	console.log('restore     ', JSON.stringify(await state()));

	await page.goto(base + '/zabbix.php?action=nettopo.view&filter_set=1&filter_groupids[]=23&filter_sources[]=cdp'
		+ '&filter_traffic=1&filter_isolated=1');
	await ready();
	await page.goto(base + '/zabbix.php?action=nettopo.view&filter_rst=1');
	await ready();
	console.log('filter reset', JSON.stringify(await state()));

	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
