// Mouse wheel zoom of the map and the upgrade simulation updated in place (without going back to the top of the page).
// Usage: node interactions.js [user] [password] [circuit]   (defaults: engenharia, lab password, NET-RJ-0001)
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.NETTOPO_BASE || 'http://127.0.0.1:8080';
const [user = 'engenharia', pass = 'Edita-Mapa-2026!', circuit = 'NET-RJ-0001'] = process.argv.slice(2);

let failures = 0;

function check(name, ok, detail = '') {
	console.log((ok ? 'ok   ' : 'FAIL ') + name + (detail ? '  ' + detail : ''));
	failures += ok ? 0 : 1;
}

async function login(context) {
	const page = await context.newPage();

	page.on('pageerror', (e) => check('no script error', false, e.message));
	await page.goto(base + '/index.php');
	await page.fill('#name', user);
	await page.fill('#password', pass);
	await Promise.all([page.waitForURL(/zabbix\.php/), page.click('#enter')]);

	return page;
}

async function wheelZoom(page) {
	await page.goto(base + '/zabbix.php?action=nettopo.view');
	await page.waitForFunction(() => window.NetTopo && window.NetTopo.app && window.NetTopo.app.data);
	await page.waitForTimeout(1000);

	const box = await page.evaluate(() => {
		const r = window.NetTopo.app.canvas.getBoundingClientRect();

		window.NetTopo.app.cy.zoom({level: 0.5, renderedPosition: {x: r.width / 2, y: r.height / 2}});

		return {x: r.left + r.width / 2 + 60, y: r.top + r.height / 2 + 40, left: r.left, top: r.top};
	});
	const state = () => page.evaluate(([x, y]) => {
		const cy = window.NetTopo.app.cy;
		const pan = cy.pan();
		const zoom = cy.zoom();

		// Graph coordinates of the point under the cursor.
		return {zoom, gx: (x - pan.x) / zoom, gy: (y - pan.y) / zoom, scroll: document.scrollingElement.scrollTop};
	}, [box.x - box.left, box.y - box.top]);

	await page.mouse.move(box.x, box.y);

	// Six notches of a mouse wheel (100 px each): the library's "mouse detection" starts after four events.
	const steps = [];
	let before = await state();

	for (let i = 0; i < 6; i++) {
		await page.mouse.wheel(0, -100);
		await page.waitForTimeout(80);
		const after = await state();

		steps.push(after.zoom / before.zoom);
		before = after;
	}

	const first = await state();

	check('wheel up zooms in about 20% per notch', steps.every((f) => f > 1.18 && f < 1.3),
		steps.map((f) => f.toFixed(3)).join(' ')
	);

	await page.mouse.wheel(0, 100);
	await page.waitForTimeout(80);
	const out = await state();

	check('wheel down zooms out', out.zoom < first.zoom, (out.zoom / first.zoom).toFixed(3));
	check('point under the cursor stays put', Math.abs(out.gx - first.gx) < 0.5 && Math.abs(out.gy - first.gy) < 0.5,
		`${first.gx.toFixed(1)},${first.gy.toFixed(1)} -> ${out.gx.toFixed(1)},${out.gy.toFixed(1)}`
	);
	check('page does not scroll', out.scroll === 0, String(out.scroll));

	// Touchpad: many small steps, smooth.
	const pad = await state();

	for (let i = 0; i < 10; i++) {
		await page.mouse.wheel(0, -4);
	}

	await page.waitForTimeout(80);
	const pad_after = await state();
	const pad_factor = pad_after.zoom / pad.zoom;

	check('touchpad scroll zooms smoothly', pad_factor > 1.05 && pad_factor < 1.2, pad_factor.toFixed(3));

	// Wheel reported in lines (Firefox) and touchpad pinch (wheel + Ctrl).
	const synthetic = (init) => page.evaluate(([x, y, init]) => {
		const target = document.elementFromPoint(x, y);
		const cy = window.NetTopo.app.cy;
		const z = cy.zoom();

		target.dispatchEvent(new WheelEvent('wheel', Object.assign({bubbles: true, cancelable: true, clientX: x, clientY: y}, init)));

		return cy.zoom() / z;
	}, [box.x, box.y, init]);
	const lines = await synthetic({deltaY: -3, deltaMode: 1});
	const pinch = await synthetic({deltaY: -5, ctrlKey: true});

	check('wheel in lines (Firefox) zooms like a notch', lines > 1.18 && lines < 1.3, lines.toFixed(3));
	check('touchpad pinch zooms in', pinch > 1.05 && pinch < 1.3, pinch.toFixed(3));

	// Limits.
	for (let i = 0; i < 40; i++) {
		await page.mouse.wheel(0, -300);
	}

	await page.waitForTimeout(80);
	check('zoom stops at the maximum', (await state()).zoom === 4);

	// Over the legend (outside the drawing) the wheel does not zoom.
	const legend = await page.evaluate(() => {
		const el = document.querySelector('.ntp-legend:not([hidden])');
		const r = el ? el.getBoundingClientRect() : null;

		return r && r.width > 0 ? {x: r.left + r.width / 2, y: r.top + r.height / 2} : null;
	});

	if (legend !== null) {
		const z = (await state()).zoom;

		await page.mouse.move(legend.x, legend.y);
		await page.mouse.wheel(0, 300);
		await page.waitForTimeout(80);
		check('wheel over the legend does not zoom', (await state()).zoom === z);
	}
}

async function simulation(page) {
	await page.setViewportSize({width: 1400, height: 800});
	await page.goto(base + '/zabbix.php?action=nettopo.capacity&circuit=' + encodeURIComponent(circuit));

	const section = page.locator('#simulation');

	if (await section.count() === 0) {
		check('circuit page has the simulation', false, circuit);

		return;
	}

	await section.scrollIntoViewIfNeeded();
	await page.waitForTimeout(300);

	const position = () => page.evaluate(() => {
		const scroller = [document.scrollingElement, ...document.querySelectorAll('.wrapper, main')]
			.find((el) => el && el.scrollTop > 0);

		return {
			scroll: scroller ? scroller.scrollTop : 0,
			top: Math.round(document.getElementById('simulation').getBoundingClientRect().top)
		};
	});
	const table = () => page.locator('#simulation table.nt-cap-sim').innerText();
	const start = await position();
	const before = await table();
	// A real reload loses this mark (history.replaceState also counts as navigation for Playwright).
	const reloaded = async () => !(await page.evaluate(() => window.ntTestMark === 1));

	await page.evaluate(() => { window.ntTestMark = 1; });

	// 1. Another capacity in the list: simulated right away.
	await page.click('#nt-cap-simulate button');
	const options = page.locator('#nt-cap-simulate li:not([aria-selected="true"])');
	const chosen = (await options.nth(Math.min(2, await options.count() - 1)).innerText()).trim();

	await Promise.all([
		page.waitForResponse((r) => r.url().includes('simulate=')),
		options.nth(Math.min(2, await options.count() - 1)).click()
	]);
	await page.waitForFunction(() => !document.querySelector('#simulation.nt-cap-sim-loading'));
	await page.waitForTimeout(200);

	const after_select = await position();
	const changed = await table();

	check('simulation updated in place', changed !== before && changed.includes(chosen.split(' ')[0]), chosen);
	check('page did not reload', !(await reloaded()));
	check('page stays at the simulation', Math.abs(after_select.top - start.top) <= 2 && after_select.scroll === start.scroll,
		JSON.stringify([start, after_select])
	);
	check('address keeps the simulation', /simulate=/.test(page.url()) && page.url().endsWith('#simulation'), page.url());

	// 2. No "Simulate" button any more: choosing a value is enough.
	check('no simulate button', await page.locator('#simulation .nt-cap-sim-form button[type="submit"]').count() === 0);

	// 3. Keyboard: arrow keys on the field simulate at once, and the focus comes back to the new field, so the next
	// arrow keeps working (the list was rebuilt).
	await page.focus('#nt-cap-simulate button');
	const key_before = await page.locator('#nt-cap-simulate').evaluate((el) => el.value);

	for (const key of ['ArrowUp', 'ArrowUp']) {
		await Promise.all([
			page.waitForResponse((r) => r.url().includes('simulate=')),
			page.keyboard.press(key)
		]);
		await page.waitForFunction(() => !document.querySelector('#simulation.nt-cap-sim-loading'));
		await page.waitForTimeout(200);
	}

	const key_after = await page.locator('#nt-cap-simulate').evaluate((el) => el.value);
	const after_keys = await position();

	check('arrow keys simulate (twice in a row)', Number(key_after) < Number(key_before), key_before + ' -> ' + key_after);
	check('keyboard focus stays on the field',
		await page.evaluate(() => document.getElementById('nt-cap-simulate').contains(document.activeElement))
	);
	check('keyboard keeps the page in place', !(await reloaded()) && Math.abs(after_keys.top - start.top) <= 2,
		JSON.stringify(after_keys)
	);
	check('only one simulation section', await page.locator('.nt-cap-sim-section').count() === 1);

	// 4. F5 shows the same simulation.
	const value = await page.locator('#nt-cap-simulate').evaluate((el) => el.value);

	await page.reload();
	check('reload keeps the simulated value', await page.locator('#nt-cap-simulate').evaluate((el) => el.value) === value,
		value
	);
}

(async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext({viewport: {width: 1500, height: 900}});
	const page = await login(context);

	await wheelZoom(page);
	await simulation(page);

	await browser.close();
	console.log(failures ? failures + ' failure(s)' : 'all ok');
	process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
