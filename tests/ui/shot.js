// Usage: node shot.js <url-path> <out.png> [width] [height] [user] [password] [theme]
// Logs in, opens the page, waits for the topology, saves a screenshot and prints console errors.
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
	const [path, out, width = '1500', height = '900', user = 'Admin', pass = 'zabbix'] = process.argv.slice(2);
	const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';
	const browser = await chromium.launch();
	const page = await browser.newPage({viewport: {width: +width, height: +height}});
	const errors = [];

	page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errors.push(m.type() + ': ' + m.text()); });
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));

	await page.goto(base + '/index.php');
	await page.fill('#name', user);
	await page.fill('#password', pass);
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
	await page.goto(base + '/' + path);
	await page.waitForTimeout(+(process.env.WAIT || 4000));

	if (process.env.EVAL) {
		console.log('EVAL:', JSON.stringify(await page.evaluate(process.env.EVAL)));
	}

	await page.screenshot({path: out, fullPage: false});
	console.log(errors.join('\n') || 'no console errors');
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
