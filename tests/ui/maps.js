// End-to-end check of saved maps: create, lock/unlock, edit (zone, text, line, curve), save, reload, restore,
// publish as native map. Usage: node maps.js <screenshot dir>
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';
const dir = process.argv[2] || '.';
const log = (...a) => console.log(...a);

async function login(page, user = 'Admin', pass = 'zabbix') {
	await page.goto(base + '/index.php');
	await page.fill('#name', user);
	await page.fill('#password', pass);
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
}

const app = (page, expr) => page.evaluate('(() => { const a = window.NetTopo.app; return ' + expr + '; })()');

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage({viewport: {width: 1500, height: 950}});
	const errors = [];

	page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
	page.on('dialog', (d) => d.accept());

	await login(page);
	await page.goto(base + '/zabbix.php?action=nettopo.view&mapid=0&filter_set=1&filter_groupids[]=23&filter_sources[]=lldp'
		+ '&filter_sources[]=cdp&filter_sources[]=tags&filter_traffic=1&filter_isolated=1');
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	await page.waitForTimeout(800);

	log('free selection, nodes:', await app(page, 'a.cy.nodes().length'), 'locked:', await app(page, 'a.locked'));

	// 1) Save as a new public map.
	await page.click('.ntp-header .ntp-btn-primary');
	await page.waitForSelector('.ntp-dialog');
	await page.fill('.ntp-dialog input[type=text]', 'Backbone RJ (teste)');
	await page.screenshot({path: dir + '/m1-save-dialog.png'});
	await Promise.all([page.waitForURL(/mapid=s/), page.click('.ntp-dialog .ntp-btn-primary')]);
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	await page.waitForTimeout(800);

	const mapid = await app(page, 'a.map.id');
	log('created map', mapid, 'scope', await app(page, 'a.map.scope'), 'locked', await app(page, 'a.locked'),
		'saved nodes', await app(page, 'Object.keys(a.map.layout.nodes).length'));
	await page.screenshot({path: dir + '/m2-saved-map.png'});

	// 2) Locked: dragging a host does not move it.
	const before = await app(page, 'a.cy.getElementById("h' + '"+a.cy.nodes("[kind=\\"host\\"]")[0].data("hostid")).position()');
	const host = await app(page, '(() => { const n = a.cy.nodes("[kind=\\"host\\"]")[0]; return {id: n.id(), p: n.renderedPosition()}; })()');
	const box = await page.locator('.ntp-canvas').boundingBox();

	await page.mouse.move(box.x + host.p.x, box.y + host.p.y);
	await page.mouse.down();
	await page.mouse.move(box.x + host.p.x + 80, box.y + host.p.y + 40, {steps: 8});
	await page.mouse.up();
	await page.waitForTimeout(300);
	log('locked drag moved?', JSON.stringify(before) !== JSON.stringify(await app(page, 'a.cy.getElementById("' + host.id + '").position()')),
		'dirty', await app(page, 'a.dirty'));

	// Unlock and drag: moves, page is dirty, Restore brings it back.
	await page.click('.ntp-dock .ntp-dock-group:nth-child(2) .ntp-btn');
	const host2 = await app(page, '(() => { const n = a.cy.getElementById("' + host.id + '"); return n.renderedPosition(); })()');

	await page.keyboard.press('Escape');
	await page.mouse.move(box.x + host2.x, box.y + host2.y);
	await page.mouse.down();
	await page.mouse.move(box.x + host2.x + 90, box.y + host2.y + 50, {steps: 8});
	await page.mouse.up();
	await page.waitForTimeout(400);
	const moved = await app(page, 'a.cy.getElementById("' + host.id + '").position()');
	log('unlocked drag moved:', JSON.stringify(before), '->', JSON.stringify(moved), 'dirty', await app(page, 'a.dirty'));
	await page.screenshot({path: dir + '/m3-moved.png'});
	await page.click('.ntp-header .ntp-btn-text >> text=' + await app(page, 'a.t("Restore")'));
	await page.waitForTimeout(500);
	log('after restore:', JSON.stringify(await app(page, 'a.cy.getElementById("' + host.id + '").position()')), 'dirty', await app(page, 'a.dirty'));

	// 3) Edit mode: zone, text, line, bend a link.
	await page.click('.ntp-header .ntp-btn-text >> text=' + await app(page, 'a.t("Edit")'));
	await page.click('.ntp-draw-tools .ntp-btn >> nth=0');
	await page.waitForTimeout(200);
	await page.fill('.ntp-props input.ntp-input', 'POP Volta Redonda');
	await page.click('.ntp-draw-tools .ntp-btn >> nth=2');
	await page.waitForTimeout(200);
	await page.fill('.ntp-props textarea.ntp-input', 'Anel metro VR');
	await page.click('.ntp-draw-tools .ntp-btn >> nth=3');
	await page.waitForTimeout(200);

	// Bend the first host-to-host link by dragging its middle.
	const edge = await app(page, '(() => { const e = a.cy.edges("[kind=\\"net\\"]").filter((x) => !x.data("toExt"))[0];'
		+ ' const m = e.renderedMidpoint(); return {id: e.id(), m}; })()');
	await page.mouse.move(box.x + edge.m.x, box.y + edge.m.y);
	await page.mouse.down();
	await page.mouse.move(box.x + edge.m.x + 60, box.y + edge.m.y - 70, {steps: 10});
	await page.mouse.up();
	await page.waitForTimeout(300);
	log('bend:', JSON.stringify(await app(page, 'a.bends["' + edge.id + '"]')), 'curve-style:',
		await app(page, 'a.cy.getElementById("' + edge.id + '").style("curve-style")'));
	await page.screenshot({path: dir + '/m4-editing.png'});

	// Save.
	await page.click('.ntp-header .ntp-btn-primary');
	await page.waitForTimeout(800);
	log('saved rev', await app(page, 'a.map.rev'), 'dirty', await app(page, 'a.dirty'),
		'shapes', await app(page, 'a.map.layout.shapes.length'), 'edges', await app(page, 'Object.keys(a.map.layout.edges).length'));

	// 4) Reload: everything comes back.
	await page.goto(base + '/zabbix.php?action=nettopo.view');
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	await page.waitForTimeout(800);
	log('reloaded map', await app(page, 'a.map && a.map.id'), 'shapes on canvas', await app(page, 'a.cy.nodes("[kind=\\"shape\\"]").length'),
		'lines', await app(page, 'a.cy.edges("[kind=\\"dline\\"]").length'),
		'bent edge style', await app(page, 'a.cy.getElementById("' + edge.id + '").style("curve-style")'));
	await page.screenshot({path: dir + '/m5-reloaded.png'});

	// 5) Publish as a native map.
	await page.click('.ntp-header .ntp-menu-wrap:last-child > .ntp-btn');
	await page.screenshot({path: dir + '/m6-more-menu.png'});
	await page.click('.ntp-menu-item >> text=' + await app(page, 'a.t("Publish as a native Zabbix map")'));
	await page.waitForTimeout(2500);
	log('sysmapid', await app(page, 'a.map.sysmapid'), 'toasts:', await page.locator('.ntp-toast').allInnerTexts());
	await page.screenshot({path: dir + '/m7-published.png'});

	log(errors.length ? 'ERRORS:\n' + errors.join('\n') : 'no console errors');
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
