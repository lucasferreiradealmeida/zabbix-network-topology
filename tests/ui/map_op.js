// Runs one saved-map operation through the page client as Admin. Usage: node map_op.js '<json params>'
// e.g. node map_op.js '{"operation":"delete","mapid":"s11abbbf70b87"}'
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto(base + '/index.php');
	await page.fill('#name', process.env.NETTOPO_USER || 'Admin');
	await page.fill('#password', process.env.NETTOPO_PASS || 'zabbix');
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
	await page.goto(base + '/zabbix.php?action=nettopo.view');
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app);
	const result = await page.evaluate(async (p) => {
		try { const r = await window.NetTopo.app.request(p); return {message: r.message, maps: r.maps.map((m) => m.id + ' ' + m.name + (m.default ? ' *' : ''))}; }
		catch (e) { return {error: e.message}; }
	}, JSON.parse(process.argv[2]));
	console.log(JSON.stringify(result));
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
