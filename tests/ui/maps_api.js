// Saved maps through the page client (CSRF token of the page): personal copy, conflict between two editors,
// rename, initial map, another user's personal map, delete. Usage: node maps_api.js
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';

async function session(browser, user, pass, path = 'zabbix.php?action=nettopo.view') {
	const page = await (await browser.newContext({viewport: {width: 1400, height: 900}})).newPage();
	page.on('dialog', (d) => d.accept());
	page.on('pageerror', (e) => console.log(user, 'PAGEERROR', e.message));
	await page.goto(base + '/index.php');
	await page.fill('#name', user);
	await page.fill('#password', pass);
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
	await page.goto(base + '/' + path);
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	return page;
}

const req = (page, params) => page.evaluate(async (p) => {
	try { return await window.NetTopo.app.request(p); } catch (e) { return {error: e.message}; }
}, params);
const app = (page, expr) => page.evaluate('(() => { const a = window.NetTopo.app; return ' + expr + '; })()');

(async () => {
	const browser = await chromium.launch();
	const admin = await session(browser, 'Admin', 'zabbix');
	const editor = await session(browser, 'engenharia', 'Edita-Mapa-2026!');
	const oper = await session(browser, 'noc', 'Leitura-Mapa-2026!');

	const shared = await app(admin, 'a.maps.find((m) => m.scope === "shared")');
	console.log('shared map:', shared.id, shared.name, 'rev', shared.rev);
	console.log('editor can edit shared:', await app(editor, 'a.config.can_edit_shared'),
		'| operator can edit shared:', await app(oper, 'a.config.can_edit_shared'));

	// Operator: cannot change the public map, can save a personal copy.
	const denied = await req(oper, {operation: 'save', mapid: shared.id, rev: shared.rev, filter: {groupids: ['23']},
		layout: {nodes: {}}});
	console.log('operator saves public map ->', denied.error || 'SAVED (WRONG)');
	const copy = await req(oper, {operation: 'save', name: 'Minha cópia', scope: 'personal',
		filter: {groupids: ['23'], sources: ['lldp']}, layout: {nodes: {h1: [10, 20]}}});
	console.log('operator personal copy ->', copy.map && copy.map.id, copy.map && copy.map.scope, copy.error || '');
	const sneaky = await req(oper, {operation: 'save', name: 'Público?', scope: 'shared', filter: {}, layout: {}});
	console.log('operator creates public map ->', sneaky.error || 'CREATED (WRONG)');

	// Two editors save the same revision: the second one is told to reload.
	const s1 = await req(admin, {operation: 'save', mapid: shared.id, rev: shared.rev, filter: {groupids: ['23']},
		layout: {nodes: {h99: [1, 2]}, shapes: [{id: 'd0011223344', t: 'text', x: 0, y: 0, w: 100, h: 30, text: 'A'}]}});
	console.log('admin saves rev', shared.rev, '->', s1.map ? 'rev ' + s1.map.rev : s1.error);
	const s2 = await req(editor, {operation: 'save', mapid: shared.id, rev: shared.rev, filter: {groupids: ['23']},
		layout: {nodes: {}}});
	console.log('editor saves stale rev ->', s2.error || 'SAVED (WRONG)');

	// Rename by the editor (Admin role).
	const rn = await req(editor, {operation: 'rename', mapid: shared.id, name: 'Backbone RJ'});
	console.log('editor renames ->', rn.map ? rn.map.name + ' rev ' + rn.map.rev : rn.error);

	// The personal map of the operator is invisible to others.
	await admin.goto(base + '/zabbix.php?action=nettopo.view&mapid=' + copy.map.id);
	await admin.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	console.log('admin opens operator personal map ->', await app(admin, 'a.map ? a.map.name : "free selection"'),
		'| toast:', (await admin.locator('.ntp-toast').allInnerTexts()).join(' / '));

	// Invalid input.
	const bad = await req(admin, {operation: 'save', mapid: 'zzz', filter: {}, layout: {}});
	console.log('bad map id ->', bad.error);
	const big = await req(oper, {operation: 'save', mapid: copy.map.id, rev: copy.map.rev, filter: {},
		layout: {shapes: Array.from({length: 600}, (_, i) => ({id: 'd' + (100000 + i).toString(16) + 'aa', t: 'rect', x: i, y: 0,
			w: 50, h: 50, text: 'x'.repeat(400), color: '#ff0000; background:url(x)'}))}});
	console.log('600 shapes (limit 500, text 300, bad color) ->', big.map ? 'stored ' + big.map.layout.shapes.length
		+ ' shapes, text ' + big.map.layout.shapes[0].text.length + ', color ' + big.map.layout.shapes[0].color : big.error);

	// Delete the personal copy.
	const del = await req(oper, {operation: 'delete', mapid: copy.map.id});
	console.log('operator deletes copy ->', del.message || del.error);

	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
