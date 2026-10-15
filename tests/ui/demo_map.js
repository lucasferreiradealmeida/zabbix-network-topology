// Builds the demo public map of the lab ("Backbone RJ"): layers layout, zones around the core and the POPs,
// a title, two curved links; saves and publishes it. Usage: node demo_map.js <mapid>
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';

(async () => {
	const mapid = process.argv[2];
	const browser = await chromium.launch();
	const page = await browser.newPage({viewport: {width: 1500, height: 950}});
	page.on('dialog', (d) => d.accept());
	page.on('pageerror', (e) => console.log('PAGEERROR', e.message));
	await page.goto(base + '/index.php');
	await page.fill('#name', 'Admin');
	await page.fill('#password', 'zabbix');
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
	await page.goto(base + '/zabbix.php?action=nettopo.view&mapid=' + mapid);
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	await page.waitForTimeout(1500);

	const result = await page.evaluate(async () => {
		const a = window.NetTopo.app;
		const cy = a.cy;

		a.setEditing(true);
		cy.elements('node[kind="shape"], node[kind="lpoint"], edge[kind="dline"]').remove();
		a.bends = {};
		cy.edges('[kind="net"]').forEach((e) => a.clearBendStyle(e));
		a.runLayout('tiers', true);

		const byName = (name) => cy.nodes().filter((n) => n.data('name') === name)[0];
		const zone = (id, names, text, color, t = 'rect', pad = 46) => {
			const nodes = cy.collection(names.map(byName).filter(Boolean));
			const fan = nodes.union(nodes.neighborhood('node[kind="ext"]').filter((x) =>
				x.connectedEdges().connectedNodes('[kind="host"]').every((h) => nodes.contains(h))));
			const bb = fan.boundingBox({includeLabels: true});
			a.addShapeElements({id, t, x: (bb.x1 + bb.x2) / 2, y: (bb.y1 + bb.y2) / 2 - 8, w: bb.w + 2 * pad,
				h: bb.h + 2 * pad + 16, text, color, fs: 14, ls: 'dashed', fill: 1});
		};

		zone('d0c0e01aa01', ['CORE-RJ-01'], 'Core RJ', '#e06c3c');
		zone('d0c0e01aa02', ['PE1-VR'], 'POP Volta Redonda', '#4f8fd6');
		zone('d0c0e01aa03', ['PE3-ITATIAIA'], 'POP Itatiaia', '#9b5fcf');
		zone('d0c0e01aa04', ['PE12-VARJAO', 'PE15-PENEDO', 'PE5-QUATIS', 'OLT-VR-01'], 'Clientes (última milha)',
			'#36a17b', 'rect', 40);

		const top = cy.nodes('[kind="shape"]').boundingBox();
		a.addShapeElements({id: 'd0c0e01aa05', t: 'text', x: (top.x1 + top.x2) / 2, y: top.y1 - 50, w: 420, h: 40,
			text: 'Rede Lab · Rio de Janeiro', color: 'auto', fs: 22, ls: 'solid', fill: 0});

		// Curves: the ring between Varjão and Penedo, and the PE1-PE3 trunk.
		const between = (x, y) => cy.edges('[kind="net"]').filter((e) => {
			const names = [e.source().data('name'), e.target().data('name')];
			return names.includes(x) && names.includes(y);
		})[0];
		const ring = between('PE12-VARJAO', 'PE15-PENEDO');
		if (ring) {
			const s = ring.source().position(), t = ring.target().position();
			a.setBend(ring, a.curveThrough(ring, {x: (s.x + t.x) / 2, y: Math.max(s.y, t.y) + 70}));
		}
		const trunk = between('PE1-VR', 'PE3-ITATIAIA');
		if (trunk) {
			const s = trunk.source().position(), t = trunk.target().position();
			a.setBend(trunk, a.curveThrough(trunk, {x: (s.x + t.x) / 2, y: Math.min(s.y, t.y) - 60}));
		}

		a.syncDrawingState();
		a.fit(false);
		await a.saveMap();
		a.setEditing(false);

		return {rev: a.map.rev, shapes: a.map.layout.shapes.length, bends: Object.keys(a.map.layout.edges).length};
	});

	console.log('saved', JSON.stringify(result));

	const published = await page.evaluate(async () => {
		await window.NetTopo.app.publish();
		return window.NetTopo.app.map.sysmapid;
	});

	console.log('native map', published);
	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
