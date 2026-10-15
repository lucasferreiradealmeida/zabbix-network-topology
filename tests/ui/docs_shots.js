// Screenshots of the documentation (lab with the demo map). Usage: node docs_shots.js <out dir> <mapid> [lost]
// "lost": only lab-link-perdido.png, after "network.py break" (PE1-VR <-> PE12-VARJAO down and lost).
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';
const [out, mapid, only] = process.argv.slice(2);

async function open(browser, user, pass, width = 1600, height = 1000) {
	const page = await browser.newPage({viewport: {width, height}});
	page.on('pageerror', (e) => console.log(user, 'PAGEERROR', e.message));
	await page.goto(base + '/index.php');
	await page.fill('#name', user);
	await page.fill('#password', pass);
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);
	return page;
}

async function topology(page, query, collapseFilter = true) {
	await page.goto(base + '/zabbix.php?action=nettopo.view' + query);
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);

	if (collapseFilter && await page.locator('.ui-tabs-active .filter-trigger').count()) {
		await page.click('.filter-trigger');
		await page.waitForTimeout(400);
		await page.evaluate(() => { window.NetTopo.app.resize(); window.NetTopo.app.fit(false); });
	}

	await page.waitForTimeout(1200);
}

(async () => {
	const browser = await chromium.launch();
	const shot = (page, name, options = {}) => page.screenshot(Object.assign({path: out + '/' + name}, options));

	// Standard map, locked (pt-BR editor).
	const eng = await open(browser, 'engenharia', 'Edita-Mapa-2026!');

	if (only === 'lost') {
		await topology(eng, '&mapid=' + mapid);
		const edge = await eng.evaluate(() => {
			const a = window.NetTopo.app;
			const names = (e) => [e.source().data('name'), e.target().data('name')].sort().join(' ');
			const e = a.cy.edges('[kind="net"]').filter((x) => names(x) === 'PE1-VR PE12-VARJAO')[0];
			const box = a.canvas.getBoundingClientRect();

			// Graph on the left, details of the link on the right.
			const bb = a.cy.elements().renderedBoundingBox();
			a.cy.panBy({x: 80 - bb.x1, y: 0});
			const right = a.cy.elements().renderedBoundingBox().x2;

			a.pin(e, {clientX: box.left + right + 30, clientY: box.top + 150});

			return {lost: e.data('lost'), status: e.data('status')};
		});
		console.log('lost link', JSON.stringify(edge));
		await eng.waitForTimeout(500);
		await shot(eng, 'lab-link-perdido.png');
		await browser.close();

		return;
	}
	await topology(eng, '&mapid=' + mapid);
	await shot(eng, 'topologia-mapa.png');

	// Edit mode with the POP zone selected.
	await eng.evaluate(() => {
		const a = window.NetTopo.app;
		a.setEditing(true);
		document.querySelectorAll('.ntp-toast').forEach((t) => t.remove());
		const zone = a.cy.nodes('[kind="shape"]').filter((n) => n.data('text') === 'POP Volta Redonda')[0];
		a.cy.elements().unselect();
		zone.select();
	});
	await eng.waitForTimeout(600);
	await shot(eng, 'mapa-edicao.png');
	await eng.evaluate(() => window.NetTopo.app.setEditing(false));

	// Chain map: links colored by utilization, the circuits of the busiest shared link pinned on the right.
	await topology(eng, '&mapid=' + mapid + '&capacity=1');
	await eng.evaluate(() => {
		const a = window.NetTopo.app;
		const caps = a.capacityEdges;
		let best = null;

		for (const [id, c] of Object.entries(caps)) {
			if (c.load !== null && (best === null || c.count > caps[best].count
					|| (c.count === caps[best].count && c.load > caps[best].load))) {
				best = id;
			}
		}

		const box = a.canvas.getBoundingClientRect();
		const bb = a.cy.elements().renderedBoundingBox();
		a.cy.panBy({x: 80 - bb.x1, y: 0});
		const right = a.cy.elements().renderedBoundingBox().x2;

		if (best !== null) {
			a.pin(a.cy.getElementById(best), {clientX: box.left + right + 30, clientY: box.top + 150});
		}
	});
	await eng.waitForTimeout(500);
	await shot(eng, 'capacidade-mapa.png');

	// Native map.
	const sysmapid = await eng.evaluate(() => window.NetTopo.app.map && window.NetTopo.app.map.sysmapid);
	await eng.goto(base + '/zabbix.php?action=map.view&sysmapid=' + sysmapid);
	await eng.waitForTimeout(2500);
	await shot(eng, 'mapa-nativo.png');

	// Capacity planning: overview, circuits, analysis & upgrades.
	const capacity = async (view, name) => {
		await eng.setViewportSize({width: 1600, height: 1000});
		await eng.goto(base + '/zabbix.php?action=nettopo.capacity&view=' + view);

		if (await eng.locator('.ui-tabs-active .filter-trigger').count()) {
			await eng.click('.filter-trigger');
		}

		await eng.waitForTimeout(800);
		await shot(eng, name);
	};

	await capacity('overview', 'capacidade-visao-geral.png');
	await capacity('circuits', 'capacidade-circuitos.png');
	await capacity('upgrades', 'capacidade-upgrades.png');

	// Detail of a circuit down to the comparison table; then the analysis and the upgrade simulation (pt-BR titles).
	const section = (title) => eng.locator('.nt-cap-section')
		.filter({has: eng.locator('.nt-cap-section-title', {hasText: title})}).first();

	await eng.setViewportSize({width: 1600, height: 2700});
	await eng.goto(base + '/zabbix.php?action=nettopo.capacity&circuit=NET-RJ-0003');
	await eng.waitForTimeout(1500);
	let analysis = await section('Análise e upgrade').boundingBox();
	await shot(eng, 'capacidade-detalhe.png', analysis ? {clip: {x: 0, y: 0, width: 1600, height: Math.round(analysis.y) - 4}} : {});

	await eng.goto(base + '/zabbix.php?action=nettopo.capacity&circuit=NET-RJ-0001');
	await eng.waitForTimeout(1500);
	analysis = await section('Análise e upgrade').boundingBox();
	const simulation = await section('Simulação de upgrade').boundingBox();

	if (analysis && simulation) {
		await shot(eng, 'capacidade-simulacao.png', {clip: {x: 222, y: Math.round(analysis.y) - 10, width: 1378,
			height: Math.round(simulation.y + simulation.height - analysis.y) + 14
		}});
	}

	// Dark theme.
	const dark = await open(browser, 'admin.rede', 'Edita-Topo-2026!');
	await topology(dark, '&mapid=' + mapid);
	await shot(dark, 'tema-escuro.png');

	await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
