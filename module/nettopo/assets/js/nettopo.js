/*
** Network Topology module for Zabbix - page client.
**
** Draws the topology with Cytoscape.js and refreshes statuses without moving anything. A saved map keeps
** the host selection, the position of every node, the shape of the links (curve, elbow, straight) and the
** drawings (zones, texts, lines). Public maps are the standard for every team: they open locked, anyone can
** unlock and move things around, and "Restore" brings the saved picture back.
*/

(function() {
	'use strict';

	const GRID = 10;
	const TILE = 44;
	const EXT_TILE = 30;
	const HANDLE_ID = '__nt_handle';

	// Device glyphs, 24x24, drawn with strokes.
	const GLYPHS = {
		router: '<circle cx="12" cy="12" r="9"/><path d="M12 3.5v5M10 6.5l2 2 2-2M12 20.5v-5M10 17.5l2-2 2 2'
			+ 'M3.5 12h5M6.5 10l2 2-2 2M20.5 12h-5M17.5 10l-2 2 2 2"/>',
		switch: '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M6 10h11M14.5 7.5l2.5 2.5-2.5 2.5'
			+ 'M18 14H7M9.5 11.5L7 14l2.5 2.5"/>',
		firewall: '<rect x="2.5" y="4" width="19" height="16" rx="1.5"/><path d="M2.5 9.3h19M2.5 14.7h19M9 4v5.3'
			+ 'M15 9.3v5.4M9 14.7V20"/>',
		server: '<rect x="4" y="3" width="16" height="7.5" rx="1.5"/><rect x="4" y="13.5" width="16" height="7.5" rx="1.5"/>'
			+ '<path d="M7.5 6.75h.01M7.5 17.25h.01M11 6.75h5M11 17.25h5"/>',
		ap: '<path d="M4.5 9.5a10.5 10.5 0 0 1 15 0M7.5 12.5a6.3 6.3 0 0 1 9 0M10.5 15.5a2.2 2.2 0 0 1 3 0"/>'
			+ '<circle cx="12" cy="18.5" r="1"/>',
		olt: '<rect x="2.5" y="6" width="19" height="12" rx="1.5"/><path d="M6 10h.01M9 10h.01M12 10h.01M15 10h.01'
			+ 'M6 14h12"/>',
		cpe: '<rect x="3.5" y="11" width="17" height="8" rx="1.5"/><path d="M7 15h.01M10.5 15h.01M17 11V5M14.5 7.5'
			+ 'a3.5 3.5 0 0 1 5 0"/>',
		phone: '<path d="M6.5 3.5h3l1.5 4-2 1.5a10 10 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5'
			+ 'a2 2 0 0 1 2-2z"/>',
		cloud: '<path d="M7 18.5h10.5a4 4 0 0 0 .5-8 6 6 0 0 0-11.5 1.5A3.3 3.3 0 0 0 7 18.5z"/>',
		storage: '<ellipse cx="12" cy="5.5" rx="7.5" ry="2.5"/><path d="M4.5 5.5v13c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5'
			+ 'v-13M4.5 12c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5"/>',
		ups: '<rect x="3" y="6.5" width="16" height="11" rx="1.5"/><path d="M19 10.5h2v3h-2M11.5 8.5l-2.5 4h4l-2.5 4"/>',
		printer: '<path d="M6.5 9V3.5h11V9M6.5 17h-3v-8h17v8h-3"/><rect x="6.5" y="13.5" width="11" height="7"/>',
		camera: '<rect x="2.5" y="7" width="14" height="10" rx="1.5"/><path d="M16.5 10.5l5-3v9l-5-3"/>',
		device: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z"/>',
		// Neighbor that is not a Zabbix host: a plug.
		plug: '<path d="M9 3.5v5M15 3.5v5M6.5 8.5h11v3a5.5 5.5 0 0 1-11 0zM12 17v3.5"/>'
	};

	// Toolbar icons, 16x16, drawn with strokes.
	const UI = {
		zoomIn: '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14M7 5v4M5 7h4"/>',
		zoomOut: '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14M5 7h4"/>',
		fit: '<path d="M2 6V2h4M10 2h4v4M14 10v4h-4M6 14H2v-4"/>',
		lock: '<rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5 7V5a3 3 0 0 1 6 0v2"/>',
		unlock: '<rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5 7V5a3 3 0 0 1 5.8-1"/>',
		arrange: '<rect x="6" y="1.5" width="4" height="3" rx=".5"/><rect x="1.5" y="11.5" width="4" height="3" rx=".5"/>'
			+ '<rect x="10.5" y="11.5" width="4" height="3" rx=".5"/><path d="M8 4.5V8M3.5 11.5V8h9v3.5"/>',
		labels: '<path d="M2 12.5l3.2-9 3.2 9M3.2 9.5h4M10.5 8.5h4M10.5 11.5h4"/>',
		neighbors: '<rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1" '
			+ 'stroke-dasharray="1.6 1.4"/><path d="M7 4.5h2.5V9"/>',
		rect: '<rect x="2" y="3.5" width="12" height="9" rx="1.5" stroke-dasharray="2.2 1.6"/>',
		ellipse: '<ellipse cx="8" cy="8" rx="6" ry="4.5" stroke-dasharray="2.2 1.6"/>',
		text: '<path d="M3 3.5h10M8 3.5V13M6 13h4"/>',
		line: '<path d="M3 13L13 3M8.5 3H13v4.5"/>',
		image: '<rect x="2" y="2.5" width="12" height="11" rx="1.5"/><circle cx="6" cy="6.5" r="1.3"/>'
			+ '<path d="M14 11l-3.5-3.5L4 13.5"/>',
		edit: '<path d="M3 13l.6-2.6L10.5 3.5l2 2-6.9 6.9zM9.3 4.7l2 2"/>',
		save: '<path d="M3 2.5h8l2.5 2.5v8.5h-10.5z"/><path d="M5.5 2.5v3.5h5V2.5M5 13.5V9.5h6v4"/>',
		restore: '<path d="M3.5 8a4.5 4.5 0 1 0 1.3-3.2"/><path d="M3 2.5v3h3"/>',
		more: '<path d="M3.5 8h.01M8 8h.01M12.5 8h.01" stroke-width="2.6"/>',
		map: '<path d="M1.5 4l4-1.5 5 1.5 4-1.5v9.5l-4 1.5-5-1.5-4 1.5zM5.5 2.5v9.5M10.5 4v9.5"/>',
		search: '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/>',
		caret: '<path d="M4.5 6.5L8 10l3.5-3.5"/>',
		star: '<path d="M8 2l1.8 3.8 4.2.5-3.1 2.8.8 4.1L8 11.2 4.3 13.2l.8-4.1L2 6.3l4.2-.5z"/>',
		globe: '<circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c-2.2 2.4-2.2 9.6 0 12M8 2c2.2 2.4 2.2 9.6 0 12"/>',
		user: '<circle cx="8" cy="5.5" r="2.8"/><path d="M2.5 14a5.5 5.5 0 0 1 11 0"/>',
		link: '<path d="M6.5 9.5l3-3M7 4.5l1.2-1.2a2.8 2.8 0 0 1 4 4L11 8.5M9 11.5l-1.2 1.2a2.8 2.8 0 0 1-4-4L5 7.5"/>',
		trash: '<path d="M2.5 4.5h11M6 4.5V3h4v1.5M4 4.5l.7 9h6.6l.7-9"/>',
		gear: '<circle cx="8" cy="8" r="2.3"/><path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4'
			+ 'M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4"/>',
		publish: '<path d="M8 10.5V2.5M5 5.5l3-3 3 3M2.5 10v3.5h11V10"/>',
		check: '<path d="M3 8.5l3 3 7-7"/>',
		close: '<path d="M4 4l8 8M12 4l-8 8"/>',
		plus: '<path d="M8 3v10M3 8h10"/>',
		curve: '<path d="M2.5 13C4 5 12 11 13.5 3"/>',
		elbow: '<path d="M2.5 13.5V8h11V2.5"/>',
		straight: '<path d="M2.5 13.5L13.5 2.5"/>',
		auto: '<path d="M2.5 13.5C6 13.5 10 2.5 13.5 2.5" stroke-dasharray="2 1.6"/>',
		gauge: '<path d="M2 11.5a6 6 0 1 1 12 0"/><path d="M8 11.5l2.8-3.6M4.2 8.2l.9.6M8 5v1"/>'
	};

	// Colors offered for drawings.
	const DRAW_COLORS = ['#4f8fd6', '#36a17b', '#d9a227', '#e06c3c', '#d64545', '#9b5fcf', '#2fa3b5', '#7d8a96'];

	// Opacity of the background of a zone.
	const ZONE_FILL = 0.09;

	const SEV_FALLBACK = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];

	const PALETTES = {
		dark: {
			canvas: '#1c2024', grid: 'rgba(255, 255, 255, 0.07)', text: '#e8ecef', muted: '#98a4ae',
			tile: '#2a3138', tile_border: '#4d5864', glyph: '#d3dae0', chip: '#20262b', chip_border: '#3d4650',
			ok: '#4cb974', up: '#5e9f78', down: '#ee5d5d', unknown: '#778591', ext: '#828e98',
			logical: '#7a9cff', accent: '#4f9be8', port: '#b9c5ce', highlight: '#ffffff',
			band_low: '#4cb974', band_medium: '#ffd25e', band_high: '#ff9d5c', band_critical: '#ff6b6b'
		},
		light: {
			canvas: '#f6f8f9', grid: 'rgba(31, 44, 51, 0.10)', text: '#1f2c33', muted: '#5d6b75',
			tile: '#ffffff', tile_border: '#b7c2ca', glyph: '#3a4853', chip: '#ffffff', chip_border: '#d2dadf',
			ok: '#2e9e4f', up: '#4a9767', down: '#d64545', unknown: '#98a4ad', ext: '#8996a0',
			logical: '#4f6fd6', accent: '#0a72b4', port: '#3f4f5a', highlight: '#1f2c33',
			band_low: '#2e9e4f', band_medium: '#d9a400', band_high: '#e8772e', band_critical: '#d64545'
		}
	};

	// Layers layout: lower = higher on the screen.
	const ROLE_TIER = {backbone: 0, distribuicao: 1, cpe: 2};

	const NETWORK_NODES = 'node[kind="host"], node[kind="ext"]';
	const DRAWINGS = 'node[kind="shape"], node[kind="lpoint"], edge[kind="dline"]';

	/*
	 * Helpers
	 */

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function fmtBps(value, short = false) {
		if (value === null || value === undefined || isNaN(value)) {
			return '—';
		}

		const units = short ? ['', 'K', 'M', 'G', 'T'] : ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
		let i = 0;

		while (Math.abs(value) >= 1000 && i < units.length - 1) {
			value /= 1000;
			i++;
		}

		const num = value >= 100 ? value.toFixed(0) : (value >= 10 ? value.toFixed(1) : value.toFixed(2));

		return short ? num.replace(/\.0+$/, '') + units[i] : num + ' ' + units[i];
	}

	function fmtAge(clock) {
		let s = Math.max(0, Math.floor(Date.now() / 1000) - clock);
		const d = Math.floor(s / 86400);
		s %= 86400;
		const h = Math.floor(s / 3600);
		const m = Math.floor((s % 3600) / 60);

		if (d > 0) {
			return d + 'd ' + h + 'h';
		}

		return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
	}

	function el(tag, attrs = {}, children = []) {
		const node = document.createElement(tag);

		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') {
				node.className = value;
			}
			else if (key === 'text') {
				node.textContent = value;
			}
			else if (key === 'html') {
				node.innerHTML = value;
			}
			else if (key.startsWith('on') && typeof value === 'function') {
				node.addEventListener(key.substring(2), value);
			}
			else if (value !== null && value !== undefined && value !== false) {
				node.setAttribute(key, value === true ? '' : value);
			}
		}

		for (const child of [].concat(children)) {
			if (child !== null && child !== undefined && child !== false) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		}

		return node;
	}

	function svg(name, size = 16) {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 16 16" '
			+ 'fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" '
			+ 'aria-hidden="true">' + (UI[name] || '') + '</svg>';
	}

	function hexToRgb(hex) {
		const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');

		return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [128, 128, 128];
	}

	function mix(a, b, t) {
		const ca = hexToRgb(a);
		const cb = hexToRgb(b);

		return '#' + ca.map((c, i) => Math.round(c + (cb[i] - c) * t).toString(16).padStart(2, '0')).join('');
	}

	// Long names may wrap after "-", "_", "." or "/" ("AGG-REGIONAL-NORTE-01" -> "AGG-REGIONAL-" / "NORTE-01"):
	// the graph library breaks lines on zero-width spaces.
	function breakable(text) {
		return String(text).replace(/([-_./])(?=\S)/g, '$1​');
	}

	// Usual short forms of interface names, for the labels on the links (the tooltip keeps the full name).
	const PORT_SHORT = [
		[/^HundredGig(?:abit)?E(?:thernet)?/i, 'Hu'],
		[/^FortyGig(?:abit)?E(?:thernet)?/i, 'Fo'],
		[/^TwentyFiveGig(?:abit)?E(?:thernet)?/i, 'Twe'],
		[/^TenGig(?:abit)?E(?:thernet)?/i, 'Te'],
		[/^XGigabitEthernet/i, 'XGE'],
		[/^GigabitEthernet/i, 'Gi'],
		[/^FastEthernet/i, 'Fa'],
		[/^Ethernet(?=\s*\d)/i, 'Eth'],
		[/^Port-channel/i, 'Po'],
		[/^Bundle-Ether/i, 'BE']
	];

	function shortPort(name) {
		for (const [re, short] of PORT_SHORT) {
			if (re.test(name)) {
				return name.replace(re, short);
			}
		}

		return name;
	}

	function hashString(text) {
		let h = 2166136261;

		for (let i = 0; i < text.length; i++) {
			h ^= text.charCodeAt(i);
			h = Math.imul(h, 16777619);
		}

		return h >>> 0;
	}

	// Small seeded random generator: the same graph always gets the same automatic layout.
	function mulberry32(seed) {
		return function() {
			seed |= 0;
			seed = (seed + 0x6D2B79F5) | 0;
			let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
			t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

			return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
		};
	}

	function withSeed(seed, fn) {
		const original = Math.random;

		Math.random = mulberry32(seed);

		try {
			return fn();
		}
		finally {
			Math.random = original;
		}
	}

	function randomHex(length) {
		const bytes = new Uint8Array(Math.ceil(length / 2));

		(window.crypto || window.msCrypto).getRandomValues(bytes);

		return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('').substring(0, length);
	}

	function sameSet(a, b) {
		const sa = new Set((a || []).map(String));
		const sb = new Set((b || []).map(String));

		return sa.size === sb.size && [...sa].every((v) => sb.has(v));
	}

	function snap(value) {
		return Math.round(value / GRID) * GRID;
	}

	function round1(value) {
		return Math.round(value * 10) / 10;
	}

	class NetTopoApp {

		constructor(config) {
			this.config = config;
			this.strings = config.strings || {};
			this.dark = config.theme === 'dark-theme' || config.theme === 'hc-dark';
			this.palette = this.dark ? PALETTES.dark : PALETTES.light;
			this.severities = config.severities || [];
			this.root = document.getElementById('nettopo');
			this.cy = null;
			this.data = null;
			this.nodeIndex = {};
			this.icons = {};
			this.prefs = this.loadPrefs();
			this.showLabels = this.prefs.labels !== false;
			this.showExternal = this.prefs.external !== false;
			this.showIsolated = !!config.show_isolated;
			this.showTraffic = !!(config.request && config.request.traffic);

			// Saved map (null = free selection of the filter).
			this.map = config.map || null;
			this.maps = config.maps || [];
			this.editing = false;
			this.locked = this.map !== null;
			this.dirty = false;
			this.bends = {};
			this.handleFor = null;
			this.edgeDrag = null;
			this.lineDrag = null;
			this.zoneDrag = null;
			this.dialog = null;
			this.clickTimer = null;
			this.refreshTimer = null;
			this.loading = false;
			this.pinned = false;
			this.openMenu = null;

			// Capacity planning: a circuit whose path is highlighted (link "Map" of that page).
			this.circuit = config.circuit && config.circuit.cid ? config.circuit : null;
			this.circuitPath = null;

			// Chain map: links colored by utilization (bands 60/80/90 %) with the circuits that depend on them.
			this.capacityMode = !!config.capacity_mode;
			this.capacityEdges = {};
			this.reloadWanted = false;

			this.buildDom();
			this.resize();
			this.initGraph();
			this.applyGrab();
			this.updateHeader();
			this.load(true);

			for (const warning of config.warnings || []) {
				this.toast('warning', warning);
			}

			window.addEventListener('resize', () => this.resize());
			window.addEventListener('beforeunload', (e) => {
				if (this.editing && this.dirty) {
					e.preventDefault();
					e.returnValue = '';
				}
			});

			const filter = document.querySelector('.filter-container');

			if (filter && window.ResizeObserver) {
				new ResizeObserver(() => this.resize()).observe(filter);
			}

			this.refreshTimer = setInterval(() => {
				if (!document.hidden) {
					this.load(false);
				}
			}, Math.max(10, this.config.refresh || 60) * 1000);
		}

		t(string) {
			return this.strings[string] !== undefined ? this.strings[string] : string;
		}

		tf(string, ...args) {
			return args.reduce((text, arg, i) => text.replace('%' + (i + 1) + '$s', arg), this.t(string));
		}

		// View toggles are remembered in the browser (labels, unregistered neighbors, legend).
		loadPrefs() {
			try {
				return JSON.parse(window.localStorage.getItem('nettopo.prefs') || '{}') || {};
			}
			catch (e) {
				return {};
			}
		}

		savePref(name, value) {
			this.prefs[name] = value;

			try {
				window.localStorage.setItem('nettopo.prefs', JSON.stringify(this.prefs));
			}
			catch (e) {
				// Storage unavailable (private mode): ignore.
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * DOM
		 * ---------------------------------------------------------------------------------------------
		 */

		button(icon, title, onclick, extra = {}) {
			const label = extra.label || null;
			const attrs = Object.assign({type: 'button', class: 'ntp-btn' + (label ? ' ntp-btn-text' : ''), title,
				'aria-label': title, onclick
			}, extra.attrs || {});

			if (extra.class) {
				attrs.class += ' ' + extra.class;
			}

			const btn = el('button', attrs);

			btn.innerHTML = svg(icon) + (label ? '<span>' + esc(label) + '</span>' : '');

			return btn;
		}

		buildDom() {
			const t = (s) => this.t(s);

			this.canvas = el('div', {class: 'ntp-canvas'});
			this.root.classList.add('ntp-root');

			this.buildHeader();
			this.buildDock();

			this.props = el('div', {class: 'ntp-props', hidden: true});
			this.legend = this.buildLegend();
			this.status = el('div', {class: 'ntp-status'});
			this.warnings = el('div', {class: 'ntp-warnings', hidden: true});
			this.tooltip = el('div', {class: 'ntp-tooltip', role: 'tooltip', hidden: true});
			this.overlay = el('div', {class: 'ntp-overlay', text: t('Loading topology...')});
			this.toasts = el('div', {class: 'ntp-toasts', 'aria-live': 'polite'});

			// Opener for the native Zabbix host menu (handled by Zabbix's [data-menu-popup] listener).
			this.menuAnchor = el('a', {class: 'nettopo-menu-anchor', href: 'javascript:void(0)', tabindex: '-1',
				'aria-hidden': 'true'});

			this.root.append(this.canvas, this.header, this.dock, this.props, this.legend, this.status, this.warnings,
				this.tooltip, this.overlay, this.toasts, this.menuAnchor
			);

			this.capacityBar = el('div', {class: 'nettopo-circuit nettopo-capbar', hidden: !this.capacityMode}, [
				el('strong', {text: t('Chain map: links colored by utilization')}),
				el('span', {text: ' · ' + t('Click a link to see the circuits that depend on it.')}),
				this.button('close', t('Back to the link status colors'), () => this.toggleCapacity(), {class: 'ntp-btn-small'})
			]);
			this.root.append(this.capacityBar);

			if (this.circuit) {
				this.circuitBar = el('div', {class: 'nettopo-circuit'}, [
					el('strong', {text: t('Circuit') + ' ' + this.circuit.cid}),
					el('span', {text: ' · ' + this.circuit.summary + ' '}),
					this.circuit.url ? el('a', {href: this.circuit.url, text: t('Details')}) : null,
					this.button('close', t('Show the whole map'), () => this.exitCircuit(), {class: 'ntp-btn-small'})
				]);
				this.root.append(this.circuitBar);
			}

			document.addEventListener('click', (e) => {
				if (this.openMenu && !this.openMenu.wrap.contains(e.target)) {
					this.closeMenus();
				}
			});

			document.addEventListener('keydown', (e) => this.onKey(e));
		}

		buildHeader() {
			const t = (s) => this.t(s);

			this.mapButton = el('button', {type: 'button', class: 'ntp-mapsel-btn', 'aria-haspopup': 'true',
				title: t('Saved maps')
			});
			this.mapMenu = el('div', {class: 'ntp-menu ntp-menu-maps', role: 'menu', hidden: true});
			const mapWrap = el('div', {class: 'ntp-menu-wrap ntp-mapsel'}, [this.mapButton, this.mapMenu]);

			this.mapButton.addEventListener('click', (e) => {
				e.stopPropagation();
				this.toggleMenu(mapWrap, this.mapMenu, () => this.fillMapMenu());
			});

			this.stateChip = el('span', {class: 'ntp-chip'});
			this.stateText = el('span', {class: 'ntp-state'});

			this.searchInput = el('input', {type: 'search', class: 'ntp-search-input', placeholder: t('Find host'),
				list: 'nettopo-search-list', 'aria-label': t('Find host')});
			this.searchList = el('datalist', {id: 'nettopo-search-list'});
			this.searchInput.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
					this.search(this.searchInput.value);
				}
			});
			this.searchInput.addEventListener('change', () => this.search(this.searchInput.value));

			const search = el('label', {class: 'ntp-search', html: svg('search')}, [this.searchInput, this.searchList]);

			this.restoreBtn = this.button('restore', t('Back to the saved map (or to the automatic layout)'),
				() => this.restore(), {label: t('Restore')}
			);
			this.editBtn = this.button('edit', t('Draw zones and texts, bend links'), () => this.setEditing(!this.editing),
				{label: t('Edit')}
			);
			this.saveBtn = this.button('save', t('Save'), () => this.saveClick(), {label: t('Save'), class: 'ntp-btn-primary'});

			this.moreMenu = el('div', {class: 'ntp-menu ntp-menu-right', role: 'menu', hidden: true});
			const moreBtn = this.button('more', t('More actions'), null);
			const moreWrap = el('div', {class: 'ntp-menu-wrap'}, [moreBtn, this.moreMenu]);

			moreBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.toggleMenu(moreWrap, this.moreMenu, () => this.fillMoreMenu());
			});

			this.header = el('div', {class: 'ntp-header'}, [
				mapWrap,
				this.stateChip,
				this.stateText,
				el('span', {class: 'ntp-spacer'}),
				search,
				this.restoreBtn,
				this.editBtn,
				this.saveBtn,
				moreWrap
			]);
		}

		buildDock() {
			const t = (s) => this.t(s);

			this.lockBtn = this.button('lock', '', () => this.setLocked(!this.locked));
			this.labelsBtn = this.button('labels', t('Show or hide labels'), () => this.toggleLabels());
			this.externalBtn = this.button('neighbors', t('Show or hide neighbors that are not Zabbix hosts'),
				() => this.toggleExternal()
			);
			this.capacityBtn = this.button('gauge', t('Color the links by utilization (chain map)'),
				() => this.toggleCapacity()
			);

			this.arrangeMenu = el('div', {class: 'ntp-menu ntp-menu-side', role: 'menu', hidden: true});
			const arrangeBtn = this.button('arrange', t('Arrange automatically'), null);
			const arrangeWrap = el('div', {class: 'ntp-menu-wrap'}, [arrangeBtn, this.arrangeMenu]);

			arrangeBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.toggleMenu(arrangeWrap, this.arrangeMenu, () => this.fillArrangeMenu());
			});

			this.drawGroup = el('div', {class: 'ntp-dock-group ntp-draw-tools'}, [
				this.button('rect', t('Zone (rectangle)'), () => this.addShape('rect')),
				this.button('ellipse', t('Zone (ellipse)'), () => this.addShape('ellipse')),
				this.button('text', t('Text'), () => this.addShape('text')),
				this.button('line', t('Line'), () => this.addShape('line'))
			]);

			this.dock = el('div', {class: 'ntp-dock'}, [
				el('div', {class: 'ntp-dock-group'}, [
					this.button('zoomIn', t('Zoom in'), () => this.zoomBy(1.25)),
					this.button('zoomOut', t('Zoom out'), () => this.zoomBy(0.8)),
					this.button('fit', t('Fit to screen'), () => this.fit(true))
				]),
				el('div', {class: 'ntp-dock-group'}, [this.lockBtn, arrangeWrap]),
				el('div', {class: 'ntp-dock-group'}, [this.labelsBtn, this.externalBtn, this.capacityBtn]),
				this.drawGroup,
				el('div', {class: 'ntp-dock-group'}, [
					this.button('image', t('Export image (PNG)'), () => this.exportPng())
				])
			]);

			this.updateDock();
		}

		updateDock() {
			const t = (s) => this.t(s);
			const locked = this.locked && !this.editing;

			this.lockBtn.innerHTML = svg(locked ? 'lock' : 'unlock');
			this.lockBtn.title = locked ? t('Positions locked: click to move hosts') : t('Click to lock the positions');
			this.lockBtn.setAttribute('aria-label', this.lockBtn.title);
			this.lockBtn.classList.toggle('ntp-on', locked);
			this.lockBtn.disabled = this.editing;
			this.labelsBtn.classList.toggle('ntp-on', this.showLabels);
			this.externalBtn.classList.toggle('ntp-on', this.showExternal);
			this.capacityBtn.classList.toggle('ntp-on', this.capacityMode);
		}

		toggleMenu(wrap, menu, fill) {
			const open = menu.hidden;

			this.closeMenus();

			if (open) {
				fill();
				menu.hidden = false;
				this.openMenu = {wrap, menu};
			}
		}

		closeMenus() {
			for (const menu of [this.mapMenu, this.moreMenu, this.arrangeMenu]) {
				if (menu) {
					menu.hidden = true;
				}
			}

			this.openMenu = null;
		}

		menuItem(icon, label, onclick, extra = {}) {
			const item = el('button', {type: 'button', role: 'menuitem', class: 'ntp-menu-item' + (extra.class ? ' '
				+ extra.class : ''), disabled: extra.disabled || false, title: extra.title || null
			});

			item.innerHTML = (icon ? svg(icon) : '<span class="ntp-menu-noicon"></span>') + '<span class="ntp-menu-label">'
				+ esc(label) + '</span>' + (extra.suffix || '');
			item.addEventListener('click', (e) => {
				e.stopPropagation();
				this.closeMenus();
				onclick();
			});

			return item;
		}

		fillMapMenu() {
			const t = (s) => this.t(s);
			const menu = this.mapMenu;
			const current = this.map ? this.map.id : '';
			const shared = this.maps.filter((m) => m.scope === 'shared');
			const personal = this.maps.filter((m) => m.scope === 'personal');

			menu.replaceChildren();

			const section = (title, maps, icon) => {
				menu.appendChild(el('div', {class: 'ntp-menu-title', text: title}));

				if (!maps.length) {
					menu.appendChild(el('div', {class: 'ntp-menu-empty', text: t('No maps yet')}));
				}

				for (const map of maps) {
					menu.appendChild(this.menuItem(icon, map.name, () => this.openMap(map.id), {
						class: map.id === current ? 'ntp-menu-current' : '',
						title: (map.updated_by ? this.tf('Changed by %1$s on %2$s', map.updated_by,
							this.fmtDate(map.updated)) : ''),
						suffix: map.default ? '<span class="ntp-menu-star" title="' + esc(t('Initial map')) + '">'
							+ svg('star', 12) + '</span>' : ''
					}));
				}
			};

			section(t('Public maps (standard for all teams)'), shared, 'globe');
			section(t('My maps'), personal, 'user');

			menu.appendChild(el('div', {class: 'ntp-menu-sep'}));
			menu.appendChild(this.menuItem('map', t('Free selection (no map)'), () => this.openMap('0'), {
				class: current === '' ? 'ntp-menu-current' : ''
			}));

			if (this.config.can_save) {
				menu.appendChild(this.menuItem('plus', t('Save the current view as a new map...'),
					() => this.openSaveDialog('new')
				));
			}
		}

		fillMoreMenu() {
			const t = (s) => this.t(s);
			const menu = this.moreMenu;
			const map = this.map;
			const can_edit = map !== null && map.can_edit;

			menu.replaceChildren();

			if (this.config.can_save) {
				menu.appendChild(this.menuItem('plus', t('Save as a new map...'), () => this.openSaveDialog('new')));
			}

			if (can_edit) {
				menu.appendChild(this.menuItem('edit', t('Rename...'), () => this.openRenameDialog()));
			}

			if (can_edit && map.scope === 'shared') {
				menu.appendChild(map.default
					? this.menuItem('star', t('Stop opening this map for new users'), () => this.setDefault(''))
					: this.menuItem('star', t('Open this map for users that have not chosen one'),
						() => this.setDefault(map.id))
				);
			}

			if (map !== null) {
				menu.appendChild(el('div', {class: 'ntp-menu-sep'}));

				if (can_edit) {
					menu.appendChild(this.menuItem('publish', map.sysmapid
						? t('Update the native Zabbix map')
						: t('Publish as a native Zabbix map'), () => this.publish(), {
						title: t('A copy in Monitoring > Maps, to use in dashboards (Map widget). Curves are not copied.')
					}));
				}

				if (map.sysmapid) {
					menu.appendChild(this.menuItem('map', t('Open the native Zabbix map'), () => {
						window.open('zabbix.php?action=map.view&sysmapid=' + encodeURIComponent(map.sysmapid), '_blank');
					}));
				}

				menu.appendChild(this.menuItem('link', t('Copy link to this map'), () => this.copyLink()));

				if (can_edit) {
					menu.appendChild(this.menuItem('trash', t('Delete map...'), () => this.deleteMap(),
						{class: 'ntp-menu-danger'}
					));
				}
			}

			menu.appendChild(el('div', {class: 'ntp-menu-sep'}));
			menu.appendChild(this.menuItem('image', t('Export image (PNG)'), () => this.exportPng()));

			if (this.config.is_super_admin) {
				menu.appendChild(this.menuItem('gear', t('Settings'), () => {
					window.location.href = this.config.settings_url;
				}));
			}
		}

		fillArrangeMenu() {
			const t = (s) => this.t(s);
			const menu = this.arrangeMenu;

			menu.replaceChildren(
				el('div', {class: 'ntp-menu-title', text: t('Arrange the hosts')}),
				this.menuItem(null, t('Layers (core on top)'), () => this.arrange('tiers')),
				this.menuItem(null, t('Automatic (forces)'), () => this.arrange('auto')),
				this.menuItem(null, t('Circle'), () => this.arrange('circle')),
				this.menuItem(null, t('Grid'), () => this.arrange('grid'))
			);
		}

		buildLegend() {
			const t = (s) => this.t(s);
			const p = this.palette;
			const tile = (color, fill) => el('span', {class: 'ntp-lg-tile', style: 'border-color:' + color
				+ ';background:' + (fill || p.tile)
			});
			const line = (color, extra = '') => el('span', {class: 'ntp-lg-line ' + extra, style: 'border-color:' + color});
			const row = (marker, label) => el('li', {}, [marker, el('span', {text: label})]);

			const hosts = el('ul', {}, [
				row(el('span', {class: 'ntp-lg-tile ntp-lg-ok', style: 'border-color:' + p.tile_border + ';background:'
					+ p.tile}, [el('i', {style: 'background:' + p.ok})]), t('No problems'))
			]);

			for (const s of this.severities.slice().reverse()) {
				hosts.appendChild(row(tile(s.color, mix(p.tile, s.color, 0.22)), s.name));
			}

			hosts.appendChild(row(el('span', {class: 'ntp-lg-tile ntp-lg-ext', style: 'border-color:' + p.ext}),
				t('Neighbor that is not a Zabbix host')
			));
			hosts.appendChild(row(el('span', {class: 'ntp-lg-tile ntp-lg-outscope', style: 'border-color:' + p.muted}),
				t('Host outside the selection')
			));

			const links = this.capacityMode
				? el('ul', {}, [
					row(line(p.band_low, 'ntp-lg-thick'), t('below 60%')),
					row(line(p.band_medium, 'ntp-lg-thick'), t('60% to 80%')),
					row(line(p.band_high, 'ntp-lg-thick'), t('80% to 90%')),
					row(line(p.band_critical, 'ntp-lg-thick'), t('90% or more')),
					row(line(p.unknown), t('not measured by capacity planning')),
					row(line(p.down, 'ntp-lg-dots'), t('Lost (neighbor no longer seen)'))
				])
				: el('ul', {}, [
					row(line(p.up), t('Up')),
					row(line(p.down, 'ntp-lg-thick'), t('Down')),
					row(line(this.sevColor(3)), t('Problem on the port')),
					row(line(p.down, 'ntp-lg-dots'), t('Lost (neighbor no longer seen)')),
					row(line(p.unknown), t('No port status')),
					row(line(p.ext, 'ntp-lg-dashed'), t('To a neighbor that is not a host')),
					row(line(p.logical, 'ntp-lg-dashed'), t('Dependency (logical)'))
				]);

			const body = el('div', {class: 'ntp-legend-body'}, [
				el('div', {class: 'ntp-legend-col'}, [el('h5', {text: t('Hosts')}), hosts]),
				el('div', {class: 'ntp-legend-col'}, [
					el('h5', {text: this.capacityMode ? t('Utilization of the link') : t('Links')}), links
				])
			]);

			const header = el('button', {type: 'button', class: 'ntp-legend-header', 'aria-expanded': 'true'}, [
				el('span', {text: t('Legend')})
			]);

			const legend = el('div', {class: 'ntp-legend'}, [header, body]);
			const setCollapsed = (collapsed) => {
				legend.classList.toggle('ntp-legend-collapsed', collapsed);
				header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			};

			// Collapsed until the user opens it (then the choice is remembered).
			setCollapsed(this.prefs.legend_collapsed !== false);

			header.addEventListener('click', () => {
				const collapsed = !legend.classList.contains('ntp-legend-collapsed');

				setCollapsed(collapsed);
				this.savePref('legend_collapsed', collapsed);
			});

			return legend;
		}

		resize() {
			const top = this.root.getBoundingClientRect().top;
			const height = Math.max(460, window.innerHeight - top - 12);

			this.root.style.height = height + 'px';

			if (this.cy) {
				this.cy.resize();
			}
		}

		/*
		 * Header state: map name, kind, unsaved changes.
		 */
		selectionChanged() {
			if (!this.map) {
				return false;
			}

			const a = this.map.filter;
			const b = this.config.filter;

			return !(sameSet(a.groupids, b.groupids) && sameSet(a.hostids, b.hostids) && sameSet(a.mapids, b.mapids)
				&& sameSet(a.sources, b.sources) && Number(a.traffic) === Number(b.traffic)
				&& Number(a.isolated) === Number(b.isolated));
		}

		updateHeader() {
			const t = (s) => this.t(s);
			const map = this.map;

			this.mapButton.innerHTML = svg('map') + '<span class="ntp-mapsel-name">'
				+ esc(map ? map.name : t('Free selection')) + '</span>' + svg('caret', 12);

			this.stateChip.className = 'ntp-chip ' + (map ? 'ntp-chip-' + map.scope : 'ntp-chip-free');
			this.stateChip.innerHTML = map
				? svg(map.scope === 'shared' ? 'globe' : 'user', 12) + esc(map.scope === 'shared' ? t('Public') : t('Personal'))
				: esc(t('Not saved'));
			this.stateChip.title = map
				? (map.scope === 'shared'
					? (map.can_edit ? t('Standard map for all teams. You can change it.')
						: t('Standard map for all teams. You can move things on your screen; only editors save it.'))
					: t('Only you see this map.'))
				: t('The hosts of the filter, arranged automatically. Save them as a map to keep the positions.');

			const notes = [];

			if (this.selectionChanged()) {
				notes.push(t('the filter differs from the saved map'));
			}

			if (this.dirty) {
				notes.push(t('unsaved changes'));
			}

			this.stateText.textContent = notes.join(' · ');
			this.stateText.hidden = !notes.length;
			this.root.classList.toggle('ntp-is-dirty', this.dirty || this.selectionChanged());

			const can_write = map !== null && map.can_edit;

			this.saveBtn.hidden = !this.config.can_save;
			this.saveBtn.title = map === null
				? t('Save as a map: the hosts of the filter, positions, curves and drawings')
				: (can_write ? t('Save the map') : t('You cannot change this map: save a personal copy'));
			this.saveBtn.querySelector('span').textContent = map !== null && !can_write ? t('Save a copy') : t('Save');
			this.saveBtn.classList.toggle('ntp-attention', this.dirty || this.selectionChanged());
			this.restoreBtn.classList.toggle('ntp-attention', this.dirty || this.selectionChanged());
			this.editBtn.classList.toggle('ntp-on', this.editing);
			this.editBtn.title = this.editing ? t('Leave the edit mode') : t('Draw zones and texts, bend links');
			this.editBtn.innerHTML = svg(this.editing ? 'check' : 'edit') + '<span>' + esc(this.editing ? t('Done')
				: t('Edit')) + '</span>';
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Graph
		 * ---------------------------------------------------------------------------------------------
		 */

		sevColor(severity) {
			if (severity < 0) {
				return this.palette.ok;
			}

			const s = this.severities.find((item) => item.severity === severity);

			return s ? s.color : SEV_FALLBACK[severity] || SEV_FALLBACK[0];
		}

		/**
		 * Tile image (56x56 drawn over a 44x44 node so the badges stick out of its corners).
		 */
		hostIcon(node) {
			const p = this.palette;
			const problem = node.severity >= 0;
			const sev = problem ? this.sevColor(node.severity) : null;
			const glyph = problem ? sev : p.glyph;
			const count = node.problem_count || 0;
			const key = [node.type, glyph, sev, count > 99 ? '99+' : count, node.maintenance ? 'm' : '',
				node.unavailable ? 'u' : ''].join('|');

			if (this.icons[key]) {
				return this.icons[key];
			}

			let s = '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 56 56">'
				+ '<g transform="translate(15 15) scale(1.0833)" fill="none" stroke="' + glyph + '" stroke-width="1.7" '
				+ 'stroke-linecap="round" stroke-linejoin="round">' + (GLYPHS[node.type] || GLYPHS.device) + '</g>';

			if (problem && count > 0) {
				const text = count > 99 ? '99+' : String(count);
				const w = 9 + text.length * 6;

				s += '<rect x="' + (55 - w) + '" y="1" width="' + w + '" height="15" rx="7.5" fill="' + sev + '" '
					+ 'stroke="' + p.canvas + '" stroke-width="1.5"/>'
					+ '<text x="' + (55 - w / 2) + '" y="12.2" text-anchor="middle" font-family="Arial, sans-serif" '
					+ 'font-size="10.5" font-weight="bold" fill="#ffffff">' + text + '</text>';
			}
			else if (!problem) {
				s += '<circle cx="47" cy="9" r="4.2" fill="' + p.ok + '" stroke="' + p.canvas + '" stroke-width="1.5"/>';
			}

			if (node.maintenance) {
				// Maintenance: wrench, top left.
				s += '<circle cx="9" cy="9" r="7.5" fill="#e8a33a" stroke="' + p.canvas + '" stroke-width="1.5"/>'
					+ '<path d="M11.3 6.8a2.6 2.6 0 0 1-3.4 3.4l-2.6 2.6-1.1-1.1 2.6-2.6a2.6 2.6 0 0 1 3.4-3.4l-1.4 1.4.3.8.8.3z" '
					+ 'fill="#ffffff"/>';
			}

			if (node.unavailable) {
				// Not reachable by Zabbix: crossed plug, bottom right.
				s += '<circle cx="47" cy="47" r="7.5" fill="#d64545" stroke="' + p.canvas + '" stroke-width="1.5"/>'
					+ '<path d="M44 44l6 6M50 44l-6 6" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/>';
			}

			s += '</svg>';

			return this.icons[key] = 'data:image/svg+xml;utf8,' + encodeURIComponent(s);
		}

		extIcon(type) {
			const key = 'ext|' + type;

			if (this.icons[key]) {
				return this.icons[key];
			}

			const glyph = type && type !== 'device' && GLYPHS[type] ? GLYPHS[type] : GLYPHS.plug;
			const s = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30">'
				+ '<g transform="translate(6 6) scale(0.75)" fill="none" stroke="' + this.palette.ext + '" stroke-width="2" '
				+ 'stroke-linecap="round" stroke-linejoin="round">' + glyph + '</g></svg>';

			return this.icons[key] = 'data:image/svg+xml;utf8,' + encodeURIComponent(s);
		}

		initGraph() {
			const p = this.palette;
			const font = 'Arial, Tahoma, Verdana, sans-serif';

			if (window.cytoscapeFcose && !this.fcoseRegistered) {
				try {
					cytoscape.use(window.cytoscapeFcose);
				}
				catch (e) {
					// Already registered.
				}

				this.fcoseRegistered = true;
			}

			this.cy = cytoscape({
				container: this.canvas,
				elements: [],
				minZoom: 0.05,
				maxZoom: 4,
				boxSelectionEnabled: false,
				selectionType: 'single',
				autounselectify: true,
				textureOnViewport: false,
				style: [
					{
						selector: 'node, edge',
						style: {'z-index-compare': 'manual', 'font-family': font}
					},
					{
						selector: 'node[kind="host"]',
						style: {
							'shape': 'round-rectangle',
							'corner-radius': 9,
							'width': TILE,
							'height': TILE,
							'z-index': 30,
							'background-color': 'data(fill)',
							'background-image': 'data(icon)',
							'background-width': 56,
							'background-height': 56,
							'background-clip': 'none',
							'background-image-containment': 'over',
							'bounds-expansion': 8,
							'border-width': 'data(bw)',
							'border-color': 'data(border)',
							'label': 'data(label)',
							'text-valign': 'bottom',
							'text-halign': 'center',
							'text-margin-y': 7,
							'text-wrap': 'wrap',
							'text-max-width': 170,
							'font-size': 10,
							'line-height': 1.3,
							'color': p.text,
							'text-background-color': p.chip,
							'text-background-opacity': 0.92,
							'text-background-padding': 3,
							'text-background-shape': 'round-rectangle',
							'text-border-width': 1,
							'text-border-color': p.chip_border,
							'text-border-opacity': 1,
							'min-zoomed-font-size': 5,
							'overlay-padding': 6
						}
					},
					{
						selector: 'node[kind="ext"]',
						style: {
							'shape': 'round-rectangle',
							'corner-radius': 7,
							'width': EXT_TILE,
							'height': EXT_TILE,
							'z-index': 28,
							'background-color': p.canvas,
							'background-opacity': 0.9,
							'background-image': 'data(icon)',
							'background-fit': 'contain',
							'border-width': 1.5,
							'border-style': 'dotted',
							'border-color': p.ext,
							'label': 'data(label)',
							'text-valign': 'bottom',
							'text-halign': 'center',
							'text-margin-y': 5,
							'text-wrap': 'wrap',
							// Narrower than the gap between neighbors of the same host (FAN_GAP).
							'text-max-width': 90,
							'font-size': 9,
							'font-style': 'italic',
							'color': p.muted,
							'text-background-color': p.canvas,
							'text-background-opacity': 0.7,
							'text-background-padding': 1.5,
							'text-background-shape': 'round-rectangle',
							'min-zoomed-font-size': 6
						}
					},
					{
						selector: 'node.outscope',
						style: {'border-style': 'dashed', 'border-width': 2, 'border-color': p.muted, 'opacity': 0.82}
					},
					{
						selector: 'edge[kind="net"]',
						style: {
							'z-index': 10,
							'curve-style': 'bezier',
							'control-point-step-size': 24,
							'width': 'data(width)',
							'line-color': 'data(color)',
							'line-style': 'solid',
							'line-cap': 'round',
							'source-label': 'data(srcLabel)',
							'target-label': 'data(tgtLabel)',
							'source-text-offset': 30,
							'target-text-offset': 30,
							'source-text-rotation': 'autorotate',
							'target-text-rotation': 'autorotate',
							'label': 'data(midLabel)',
							'text-rotation': 'autorotate',
							'font-size': 8,
							'color': p.port,
							'text-background-color': p.chip,
							'text-background-opacity': 0.88,
							'text-background-padding': 2,
							'text-background-shape': 'round-rectangle',
							'min-zoomed-font-size': 6,
							'overlay-padding': 5
						}
					},
					{
						selector: 'edge[kind="net"][status="down"]',
						style: {'line-style': 'solid'}
					},
					{
						selector: 'edge[kind="net"][?lost]',
						style: {'line-style': 'dashed', 'line-dash-pattern': [1, 5], 'line-cap': 'round'}
					},
					{
						selector: 'edge[kind="net"][?toExt]',
						style: {'line-style': 'dashed', 'line-dash-pattern': [6, 4]}
					},
					{
						selector: 'edge[kind="net"][origin="dep"]',
						style: {
							'line-style': 'dashed',
							'line-dash-pattern': [5, 4],
							'target-arrow-shape': 'triangle',
							'target-arrow-color': 'data(color)',
							'arrow-scale': 0.9
						}
					},
					{
						selector: '.nolabel',
						style: {'source-label': '', 'target-label': '', 'label': ''}
					},
					{
						selector: 'node[kind="host"].nolabel',
						style: {'label': 'data(name)'}
					},
					{
						selector: '.faded',
						style: {'opacity': 0.13}
					},
					{
						selector: 'node.highlight',
						style: {'border-width': 3.5, 'border-color': p.accent}
					},
					{
						selector: 'edge.highlight',
						style: {'width': 4}
					},
					{
						selector: 'node.found',
						style: {'overlay-color': p.accent, 'overlay-opacity': 0.25, 'overlay-padding': 12}
					},
					{
						selector: '.hidden',
						style: {'display': 'none'}
					},

					// Drawings
					{
						selector: 'node[kind="shape"]',
						style: {
							'z-index': 1,
							'shape': 'data(cyshape)',
							'corner-radius': 14,
							'width': 'data(w)',
							'height': 'data(h)',
							'background-color': 'data(color)',
							'background-opacity': 'data(fillop)',
							'border-width': 2,
							'border-color': 'data(color)',
							'border-style': 'data(ls)',
							'border-opacity': 0.9,
							'label': 'data(text)',
							'font-size': 'data(fs)',
							'font-weight': 'bold',
							'color': 'data(color)',
							'text-valign': 'top',
							'text-halign': 'center',
							'text-margin-y': 'data(tmy)',
							'text-wrap': 'wrap',
							'text-max-width': 'data(w)',
							// Same color as the zone fill: links passing behind the name do not cross it.
							'text-background-color': 'data(lbg)',
							'text-background-opacity': 1,
							'text-background-padding': 4,
							'text-background-shape': 'round-rectangle',
							'min-zoomed-font-size': 4
						}
					},
					{
						selector: 'node[kind="shape"][t="text"]',
						style: {
							'z-index': 26,
							'background-opacity': 0,
							'border-width': 0,
							'text-valign': 'center',
							'text-margin-y': 0,
							'text-background-opacity': 0
						}
					},
					{
						selector: 'node[kind="lpoint"]',
						style: {
							'z-index': 27,
							'shape': 'ellipse',
							'width': 1,
							'height': 1,
							'background-color': 'data(color)',
							'border-width': 0,
							'opacity': 0
						}
					},
					{
						selector: 'edge[kind="dline"]',
						style: {
							'z-index': 5,
							'curve-style': 'straight',
							'line-color': 'data(color)',
							'width': 'data(lw)',
							'line-style': 'data(ls)',
							'line-cap': 'round',
							'source-arrow-shape': 'data(sa)',
							'target-arrow-shape': 'data(ta)',
							'source-arrow-color': 'data(color)',
							'target-arrow-color': 'data(color)',
							'arrow-scale': 1.1,
							'label': 'data(text)',
							'font-size': 'data(fs)',
							'font-weight': 'bold',
							'color': 'data(color)',
							'text-rotation': 'autorotate',
							'text-margin-y': -10,
							'min-zoomed-font-size': 4
						}
					},
					{
						selector: '.ntp-passive',
						style: {'events': 'no'}
					},
					{
						// Outside the edit mode zones are drawn over the links (their fill is almost transparent),
						// so the zone names stay readable; while editing they go back under the links, which can
						// then be grabbed inside a zone.
						selector: 'node[kind="shape"][t!="text"].ntp-passive',
						style: {'z-index': 12}
					},
					{
						selector: 'edge[kind="dline"].ntp-passive',
						style: {'z-index': 13}
					},
					{
						selector: '.ntp-editable[kind="lpoint"]',
						style: {'width': 12, 'height': 12, 'opacity': 1, 'border-width': 2, 'border-color': p.canvas}
					},
					{
						selector: 'node[kind="shape"][t="text"].ntp-editable',
						style: {'border-width': 1, 'border-style': 'dashed', 'border-color': p.muted, 'border-opacity': 0.6}
					},
					{
						selector: ':selected',
						style: {'overlay-color': p.accent, 'overlay-opacity': 0.18, 'overlay-padding': 6}
					},
					{
						selector: 'node[kind="handle"]',
						style: {
							'z-index': 100,
							'shape': 'rectangle',
							'width': 12,
							'height': 12,
							'background-color': p.accent,
							'border-width': 2,
							'border-color': p.canvas,
							'label': ''
						}
					}
				]
			});

			this.bindGraphEvents();
			this.updateGrid();
		}

		bindGraphEvents() {
			const cy = this.cy;

			cy.on('mouseover', 'node[kind="host"], node[kind="ext"], edge[kind="net"]', (e) => {
				if (!this.pinned && !this.edgeDrag) {
					this.showTooltip(e.target, e.originalEvent);
				}
				this.canvas.style.cursor = this.editing && e.target.isEdge() ? 'move' : 'pointer';
			});

			cy.on('mousemove', 'node[kind="host"], node[kind="ext"], edge[kind="net"]', (e) => {
				if (!this.pinned) {
					this.positionTooltip(e.originalEvent);
				}
			});

			cy.on('mouseout', 'node, edge', () => {
				if (!this.pinned) {
					this.hideTooltip();
				}
				this.canvas.style.cursor = '';
			});

			cy.on('tap', 'node[kind="host"], node[kind="ext"], edge[kind="net"]', (e) => {
				const target = e.target;
				const oe = e.originalEvent;

				if (this.editing) {
					return;
				}

				clearTimeout(this.clickTimer);

				// Single click is delayed briefly so that a double click does not open the menu.
				this.clickTimer = setTimeout(() => {
					if (target.isNode() && target.data('kind') === 'host') {
						this.unpin();
						this.openHostMenu(target.data('hostid'), oe);
					}
					else {
						this.pin(target, oe);
					}
				}, 230);
			});

			cy.on('dbltap', 'node[kind="host"], node[kind="ext"]', (e) => {
				clearTimeout(this.clickTimer);

				if (!this.editing) {
					this.highlight(e.target);
				}
			});

			cy.on('dbltap', 'edge[kind="net"]', (e) => {
				if (this.editing) {
					this.setBend(e.target, null);
					this.markChanged();
				}
			});

			cy.on('tap', (e) => {
				if (e.target === cy) {
					clearTimeout(this.clickTimer);
					this.unpin();
					this.clearHighlight();
				}
			});

			cy.on('select', 'node, edge', (e) => this.onSelect(e.target));
			cy.on('unselect', 'node, edge', () => {
				setTimeout(() => {
					if (this.cy.$(':selected').empty()) {
						this.onSelect(null);
					}
				}, 0);
			});

			// Moving hosts: temporary in view mode, part of the drawing in edit mode.
			cy.on('dragfree', 'node', (e) => this.onDragFree(e.target));
			cy.on('grab', 'node[kind="shape"]', (e) => this.onZoneGrab(e));
			cy.on('drag', 'node', (e) => this.onNodeDrag(e.target));

			// Bending links and moving drawn lines (edit mode).
			cy.on('tapstart', 'edge[kind="net"]', (e) => this.onEdgeTapStart(e));
			cy.on('tapstart', 'edge[kind="dline"]', (e) => this.onLineTapStart(e));
			cy.on('tapdrag', (e) => this.onTapDrag(e));
			cy.on('tapend', () => this.onTapEnd());

			cy.on('pan zoom', () => {
				if (!this.pinned) {
					this.hideTooltip();
				}
				this.updateGrid();
			});

			/*
			 * Mouse wheel zoom. The library divides the step of a "real" mouse wheel by the size of the
			 * notch, so one notch zoomed less than 1%. Our listener runs first (capture phase on the
			 * parent) and stops the event before the library sees it.
			 */
			if (!this.wheelBound) {
				this.wheelBound = true;
				this.canvas.parentNode.addEventListener('wheel', (e) => this.onWheel(e),
					{capture: true, passive: false}
				);
			}
		}

		/**
		 * One notch of a mouse wheel (100 px) zooms about 23% around the cursor; touchpads send many small
		 * steps and zoom smoothly; a touchpad pinch (wheel + Ctrl) gets a bigger factor, like in browsers.
		 */
		onWheel(e) {
			const cy = this.cy;

			if (!cy || !this.canvas.contains(e.target)) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			if (!cy.userZoomingEnabled() || this.edgeDrag || cy.$('node:grabbed').nonempty()) {
				return;
			}

			// deltaMode is read first: Firefox then reports a notch as 3 lines instead of a variable pixel value.
			const mode = e.deltaMode;
			let delta = e.deltaY;

			if (mode === 1) {
				delta *= 33;
			}
			else if (mode === 2) {
				delta *= this.canvas.clientHeight || 600;
			}

			if (e.ctrlKey) {
				delta *= 8;
			}

			delta = Math.max(-300, Math.min(300, delta));

			if (delta === 0) {
				return;
			}

			const level = Math.max(cy.minZoom(), Math.min(cy.maxZoom(), cy.zoom() * Math.pow(2, -delta * 0.003)));

			if (level === cy.zoom()) {
				return;
			}

			const rect = this.canvas.getBoundingClientRect();

			cy.zoom({level, renderedPosition: {x: e.clientX - rect.left, y: e.clientY - rect.top}});
		}

		/**
		 * The dot grid of the canvas follows pan and zoom.
		 */
		updateGrid() {
			const zoom = this.cy.zoom();
			const pan = this.cy.pan();
			const size = GRID * 2 * zoom;

			if (size < 6) {
				this.canvas.style.backgroundImage = 'none';

				return;
			}

			this.canvas.style.backgroundImage = 'radial-gradient(' + this.palette.grid + ' 1.1px, transparent 1.3px)';
			this.canvas.style.backgroundSize = size + 'px ' + size + 'px';
			this.canvas.style.backgroundPosition = (pan.x % size) + 'px ' + (pan.y % size) + 'px';
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Data
		 * ---------------------------------------------------------------------------------------------
		 */

		async load(initial) {
			if (this.loading) {
				return;
			}

			this.loading = true;

			const params = new URLSearchParams();
			const req = this.config.request;

			for (const field of ['groupids', 'hostids', 'mapids', 'sources']) {
				for (const value of req[field] || []) {
					params.append(field + '[]', value);
				}
			}

			params.append('traffic', req.traffic ? '1' : '0');

			// The path of the circuit is asked once: its links keep their IDs across refreshes.
			if (this.circuit && !this.circuitPath) {
				params.append('circuit', this.circuit.cid);
			}

			if (this.capacityMode) {
				params.append('capacity', '1');
			}

			try {
				const response = await fetch(this.config.data_url, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: params.toString(),
					credentials: 'same-origin'
				});

				const data = await response.json();

				if (data.error) {
					throw new Error((data.error.messages || []).join('\n') || this.t('Cannot load topology data.'));
				}

				this.apply(data, initial);
				this.overlay.hidden = !!data.nodes.length;
			}
			catch (error) {
				if (initial || !this.data) {
					this.overlay.hidden = false;
					this.overlay.textContent = this.t('Cannot load topology data.') + ' ' + (error.message || '');
				}
				else {
					this.setWarnings([this.t('Cannot load topology data.') + ' ' + (error.message || '')]);
				}
			}
			finally {
				this.loading = false;

				if (this.reloadWanted) {
					this.reloadWanted = false;
					this.load(false);
				}
			}
		}

		nodeElement(node) {
			const t = (s) => this.t(s);
			const p = this.palette;
			let data;

			if (node.kind === 'host') {
				const problem = node.severity >= 0;
				const sev = problem ? this.sevColor(node.severity) : null;
				const lines = [breakable(node.name)];

				if (node.ip) {
					lines.push(node.ip);
				}

				data = {
					fill: problem ? mix(p.tile, sev, 0.22) : p.tile,
					border: problem ? sev : p.tile_border,
					bw: problem ? 2.5 : 1.5,
					icon: this.hostIcon(node),
					label: lines.join('\n')
				};

				if (node.problem_count > 0) {
					data.label += '\n' + node.problem_count + ' ' + (node.problem_count == 1 ? t('problem') : t('problems'));
				}
			}
			else {
				data = {icon: this.extIcon(node.type), label: breakable(node.name)};
			}

			return {
				group: 'nodes',
				data: Object.assign({}, node, data),
				classes: this.nodeClasses(node)
			};
		}

		nodeClasses(node) {
			const classes = [];

			if (node.kind === 'host' && !node.in_scope) {
				classes.push('outscope');
			}

			if (!this.showLabels) {
				classes.push('nolabel');
			}

			return classes.join(' ');
		}

		edgeElement(edge) {
			const p = this.palette;
			const toExt = edge.target.charAt(0) === 'x' || edge.source.charAt(0) === 'x';
			let color;

			if (edge.status === 'down' || edge.lost) {
				color = p.down;
			}
			else if (edge.origin === 'dep') {
				color = p.logical;
			}
			else if (edge.severity >= 2) {
				color = this.sevColor(edge.severity);
			}
			else if (toExt && edge.status !== 'up') {
				color = p.ext;
			}
			else if (edge.status === 'unknown') {
				color = p.unknown;
			}
			else {
				color = p.up;
			}

			let width = edge.status === 'down' ? 3.2 : (toExt ? 1.3 : 1.8);
			const speed = edge.traffic && edge.traffic.speed;

			if (speed && speed > 0 && edge.status !== 'down') {
				width = Math.max(width, Math.min(5, 1.4 + Math.max(0, Math.log10(speed / 1e8)) * 0.9));
			}

			// Chain map: the band of the utilization measured by capacity planning (gray = not measured).
			const cap = this.capacityMode ? this.capacityEdges[edge.id] : null;
			const capColor = this.capacityMode && !edge.lost && edge.status !== 'down' && edge.origin !== 'dep';

			if (capColor) {
				color = cap && cap.load !== null ? p['band_' + cap.band] : p.unknown;
				width = cap && cap.load !== null ? width + 1.2 : width;
			}

			let mid = '';

			if (edge.lost) {
				mid = this.t('lost');
			}
			else if (edge.status === 'down') {
				mid = '✕ ' + this.t('down');
			}
			else if (capColor && cap) {
				mid = (cap.load !== null ? Math.round(cap.load) + '%' : '')
					+ (cap.count ? (cap.load !== null ? ' · ' : '') + cap.count + ' ' + this.t(cap.count == 1 ? 'circuit' : 'circuits') : '');
			}
			else if (this.showTraffic && edge.traffic && (edge.traffic.in !== null || edge.traffic.out !== null)) {
				const parts = [];

				if (edge.traffic.in !== null) {
					parts.push('▼' + fmtBps(edge.traffic.in, true));
				}

				if (edge.traffic.out !== null) {
					parts.push('▲' + fmtBps(edge.traffic.out, true));
				}

				mid = parts.join(' ');

				if (edge.traffic.util !== null && edge.traffic.util !== undefined && edge.traffic.util >= 1) {
					mid += ' · ' + Math.round(edge.traffic.util) + '%';
				}
			}
			else if (edge.label) {
				mid = edge.label;
			}

			return {
				group: 'edges',
				data: Object.assign({}, edge, {
					kind: 'net',
					toExt,
					color,
					width,
					srcLabel: shortPort(edge.source_port || ''),
					tgtLabel: shortPort(edge.target_port || ''),
					midLabel: mid
				}),
				classes: this.showLabels ? '' : 'nolabel'
			};
		}

		apply(data, initial) {
			const cy = this.cy;
			const firstRender = !this.data;

			this.data = data;
			this.nodeIndex = {};
			this.capacityEdges = this.capacityMode ? (data.capacity || {}) : {};

			// Sorted: the automatic layouts depend on the order of the elements.
			const nodes = data.nodes.slice().sort((a, b) => a.id < b.id ? -1 : (a.id > b.id ? 1 : 0));
			const edges = data.edges.slice().sort((a, b) => a.id < b.id ? -1 : (a.id > b.id ? 1 : 0));

			for (const node of nodes) {
				this.nodeIndex[node.id] = node;
			}

			const elements = nodes.map((node) => this.nodeElement(node))
				.concat(edges.map((edge) => this.edgeElement(edge)));

			const incomingIds = new Set(elements.map((item) => item.data.id));
			const added = [];

			cy.batch(() => {
				cy.elements(NETWORK_NODES + ', edge[kind="net"]').forEach((item) => {
					if (!incomingIds.has(item.id())) {
						item.remove();
					}
				});

				for (const item of elements) {
					const existing = cy.getElementById(item.data.id);

					if (existing.nonempty()) {
						// id/source/target are immutable in Cytoscape.
						const update = Object.assign({}, item.data);

						delete update.id;
						delete update.source;
						delete update.target;

						existing.data(update);
						existing.classes(item.classes + (existing.hasClass('faded') ? ' faded' : '')
							+ (existing.hasClass('highlight') ? ' highlight' : '')
							+ (existing.hasClass('hidden') ? ' hidden' : ''));
					}
					else if (item.group === 'nodes') {
						added.push(cy.add(item));
					}
				}

				for (const item of elements) {
					if (item.group === 'edges' && cy.getElementById(item.data.id).empty()
							&& cy.getElementById(item.data.source).nonempty()
							&& cy.getElementById(item.data.target).nonempty()) {
						const edge = cy.add(item);

						if (this.bends[edge.id()]) {
							this.applyBendStyle(edge);
						}
					}
				}
			});

			this.applyVisibility();
			this.updateSearchList();
			this.updateStatus();
			this.setWarnings(data.warnings || []);

			if (this.circuit) {
				if (data.circuit !== undefined) {
					this.circuitPath = data.circuit || null;
				}

				this.applyCircuit();
			}

			if (firstRender || initial) {
				this.placeInitial();
			}
			else if (added.length) {
				this.placeNew(cy.collection(added));
			}

			if (!data.nodes.length) {
				this.overlay.hidden = false;
				this.overlay.textContent = this.t('No hosts match the filter.');
			}
		}

		applyVisibility() {
			const cy = this.cy;

			cy.batch(() => {
				cy.nodes(NETWORK_NODES).forEach((node) => {
					let hidden = false;

					if (node.data('kind') === 'ext' && !this.showExternal) {
						hidden = true;
					}

					if (!hidden && node.data('kind') === 'host' && !this.showIsolated) {
						const visibleNeighbors = node.connectedEdges('[kind="net"]').connectedNodes().filter((n) =>
							n.id() !== node.id() && (n.data('kind') === 'host' || this.showExternal)
						);

						hidden = visibleNeighbors.empty();
					}

					node.toggleClass('hidden', hidden);
				});
			});
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Layout
		 * ---------------------------------------------------------------------------------------------
		 */

		/**
		 * First drawing: the saved map, or the standard automatic layout (always the same for the same hosts).
		 */
		placeInitial() {
			if (this.map) {
				this.applySaved(this.map.layout, false);
			}
			else {
				this.runLayout(this.defaultLayoutName(), false);
				this.syncDrawingState();
			}

			this.setDirty(false);
		}

		defaultLayoutName() {
			const tiered = this.cy.nodes('[kind="host"]').some((n) => this.tierOf(n) !== null);

			return tiered ? 'tiers' : 'auto';
		}

		/**
		 * Applies a saved layout: positions, link shapes and drawings.
		 */
		applySaved(layout, animate) {
			const cy = this.cy;
			const saved = (layout && layout.nodes) || {};
			const nodes = cy.nodes(NETWORK_NODES);
			const withPosition = nodes.filter((n) => saved[n.id()] !== undefined);

			this.removeHandle();
			cy.elements(DRAWINGS).remove();

			// Link shapes.
			cy.edges('[kind="net"]').forEach((edge) => this.clearBendStyle(edge));
			this.bends = Object.assign({}, (layout && layout.edges) || {});
			cy.edges('[kind="net"]').forEach((edge) => {
				if (this.bends[edge.id()]) {
					this.applyBendStyle(edge);
				}
			});

			if (withPosition.nonempty()) {
				cy.batch(() => {
					withPosition.forEach((n) => n.position({x: saved[n.id()][0], y: saved[n.id()][1]}));
				});

				this.placeNew(nodes.difference(withPosition), false);
			}
			else {
				this.runLayout(this.defaultLayoutName(), false);
			}

			for (const shape of (layout && layout.shapes) || []) {
				this.addShapeElements(shape);
			}

			this.syncDrawingState();
			this.fit(animate);
		}

		/**
		 * Places nodes that appeared after the layout: next to their already placed neighbors.
		 */
		placeNew(nodes, animate = true) {
			if (nodes.empty()) {
				return;
			}

			const saved = this.map && this.map.layout ? this.map.layout.nodes || {} : {};
			const placed = this.cy.nodes(NETWORK_NODES).difference(nodes);
			const bb = placed.nonempty() ? placed.boundingBox() : {x1: 0, y1: 0, x2: 0, y2: 0, w: 0, h: 0};
			let orphan = 0;

			nodes.forEach((node) => {
				if (saved[node.id()] !== undefined) {
					node.position({x: saved[node.id()][0], y: saved[node.id()][1]});

					return;
				}

				const anchors = node.neighborhood('node').intersection(placed);
				let x, y;

				if (anchors.nonempty()) {
					x = 0;
					y = 0;
					anchors.forEach((a) => {
						x += a.position('x');
						y += a.position('y');
					});

					// Deterministic spot around the neighbors.
					const angle = (hashString(node.id()) % 360) * Math.PI / 180;

					x = x / anchors.length + Math.cos(angle) * 95;
					y = y / anchors.length + Math.sin(angle) * 95;
				}
				else {
					x = bb.x2 + 130 + (orphan % 4) * 100;
					y = bb.y1 + Math.floor(orphan / 4) * 100;
					orphan++;
				}

				node.position({x, y});
			});

			if (animate) {
				nodes.flashClass('found', 1500);
			}
		}

		/**
		 * Role of a host in the layers layout: "topology.level" tag, else the capacity planning template.
		 */
		tierOf(node) {
			const level = parseInt(node.data('level'), 10);

			if (!isNaN(level)) {
				return level;
			}

			const role = node.data('role');

			return role && ROLE_TIER[role] !== undefined ? ROLE_TIER[role] : null;
		}

		layoutOptions(name, eles) {
			const count = eles.nodes().length;

			if (name === 'auto') {
				if (this.fcoseRegistered) {
					return {
						name: 'fcose',
						quality: count > 600 ? 'draft' : 'default',
						randomize: true,
						animate: false,
						nodeDimensionsIncludeLabels: true,
						packComponents: true,
						nodeRepulsion: () => 7000,
						idealEdgeLength: (edge) => edge.data('toExt') ? 80 : 160,
						edgeElasticity: () => 0.45,
						gravity: 0.25,
						numIter: 2500,
						tile: true,
						tilingPaddingVertical: 24,
						tilingPaddingHorizontal: 24
					};
				}

				return {name: 'cose', animate: false, nodeDimensionsIncludeLabels: true, randomize: true,
					idealEdgeLength: () => 130, nodeRepulsion: () => 400000};
			}

			if (name === 'circle') {
				return {name: 'circle', animate: false, nodeDimensionsIncludeLabels: true, spacingFactor: 1.1};
			}

			return {name: 'grid', animate: false, nodeDimensionsIncludeLabels: true, avoidOverlap: true,
				avoidOverlapPadding: 30};
		}

		/**
		 * Arranges the visible hosts. Always gives the same picture for the same hosts and links.
		 */
		runLayout(name, markDirty = true) {
			const cy = this.cy;
			const nodes = cy.nodes(NETWORK_NODES).filter(':visible');
			const eles = nodes.union(nodes.connectedEdges('[kind="net"]').filter(':visible'));

			if (nodes.empty()) {
				return;
			}

			const seed = hashString(nodes.map((n) => n.id()).join(','));

			if (name === 'tiers') {
				const positions = this.tierPositions(nodes);

				cy.batch(() => nodes.forEach((n) => n.position(positions[n.id()])));
			}
			else {
				withSeed(seed, () => {
					try {
						eles.layout(this.layoutOptions(name, eles)).run();
					}
					catch (error) {
						// fCoSE can fail on unusual graphs; fall back to the built-in layout.
						eles.layout({name: 'cose', animate: false, randomize: true}).run();
					}
				});

				if (name === 'auto') {
					this.stretchToViewport(nodes);
				}
			}

			if (this.editing) {
				nodes.forEach((n) => n.position({x: snap(n.position('x')), y: snap(n.position('y'))}));
			}

			// Hidden nodes keep a sensible place for when they are shown again.
			this.placeNew(cy.nodes(NETWORK_NODES).filter(':hidden'), false);
			this.fit(false);

			if (markDirty) {
				this.markChanged();
			}
		}

		arrange(name) {
			this.runLayout(name, true);

			if (this.locked && !this.editing) {
				this.toast('info', this.t('Arranged on your screen. Save the map to keep it, or Restore to go back.'));
			}
		}

		/**
		 * Layers: core on top, distribution in the middle, CPEs at the bottom ("topology.level" tag or capacity
		 * planning templates); hosts without a role go one layer below their neighbors. Within a layer, hosts
		 * are ordered to cross as few links as possible (barycenter method).
		 */
		tierPositions(nodes) {
			const isExt = (id) => id.charAt(0) === 'x';
			const ids = nodes.map((n) => n.id());
			const inSet = new Set(ids);
			const hosts = ids.filter((id) => !isExt(id));
			const adj = {};
			const extOf = {};
			const tier = {};

			for (const id of ids) {
				adj[id] = [];
			}

			nodes.connectedEdges('[kind="net"]').forEach((edge) => {
				const s = edge.source().id();
				const t = edge.target().id();

				if (inSet.has(s) && inSet.has(t) && s !== t && !adj[s].includes(t)) {
					adj[s].push(t);
					adj[t].push(s);
				}
			});

			const hostAdj = (id) => adj[id].filter((n) => !isExt(n));
			const name = (id) => ((this.nodeIndex[id] && this.nodeIndex[id].name) || id).toLowerCase();
			const byName = (a, b) => name(a) < name(b) ? -1 : (name(a) > name(b) ? 1 : 0);

			// 1) Known roles ("topology.level" tag or capacity planning template).
			const queue = [];

			nodes.forEach((n) => {
				if (n.data('kind') === 'host') {
					const level = this.tierOf(n);

					if (level !== null) {
						tier[n.id()] = level;
						queue.push(n.id());
					}
				}
			});

			// 2) Parts of the network without any role: their most connected host is the top.
			const seen = new Set(queue);
			const spread = (start) => {
				const stack = [start];

				while (stack.length) {
					const cur = stack.pop();

					for (const next of hostAdj(cur)) {
						if (!seen.has(next)) {
							seen.add(next);
							stack.push(next);
						}
					}
				}
			};

			queue.forEach(spread);

			for (const id of hosts.slice().sort((a, b) => hostAdj(b).length - hostAdj(a).length || byName(a, b))) {
				if (!seen.has(id)) {
					seen.add(id);
					tier[id] = 0;
					queue.push(id);
					spread(id);
				}
			}

			// 3) Hosts without a role: one layer below the closest host with one.
			queue.sort((a, b) => tier[a] - tier[b]);

			for (let i = 0; i < queue.length; i++) {
				const cur = queue[i];

				for (const next of hostAdj(cur)) {
					if (tier[next] === undefined) {
						tier[next] = tier[cur] + 1;
						queue.push(next);
					}
				}
			}

			// 4) Neighbors that are not hosts hang below the (highest) host that sees them.
			for (const id of ids.filter(isExt)) {
				const owners = adj[id].filter((n) => !isExt(n)).sort((a, b) => tier[a] - tier[b] || byName(a, b));

				if (owners.length) {
					(extOf[owners[0]] = extOf[owners[0]] || []).push(id);
				}
			}

			for (const owner of Object.keys(extOf)) {
				extOf[owner].sort(byName);
			}

			// Rows of hosts, ordered to cross as few links as possible (barycenter method).
			const levels = [...new Set(hosts.map((id) => tier[id]))].sort((a, b) => a - b);
			const rows = levels.map((level) => hosts.filter((id) => tier[id] === level).sort(byName));
			const order = {};

			rows.forEach((row) => row.forEach((id, i) => order[id] = i / Math.max(1, row.length - 1)));

			for (let sweep = 0; sweep < 4; sweep++) {
				const range = sweep % 2 === 0 ? [...rows.keys()].slice(1) : [...rows.keys()].reverse().slice(1);
				const ref = sweep % 2 === 0 ? -1 : 1;

				for (const r of range) {
					const refIds = new Set(rows[r + ref] || []);
					const bary = (id) => {
						const linked = hostAdj(id).filter((n) => refIds.has(n));

						return linked.length ? linked.reduce((sum, n) => sum + order[n], 0) / linked.length : order[id];
					};

					rows[r] = rows[r].slice().sort((a, b) => bary(a) - bary(b) || byName(a, b));
					rows[r].forEach((id, i) => order[id] = i / Math.max(1, rows[r].length - 1));
				}
			}

			// Coordinates: each host takes the width of its fan of neighbors; long rows wrap.
			const FAN_PER_LINE = 4;
			const FAN_GAP = 100;
			const HOST_GAP = 170;
			const MAX_ROW = 12;
			const fanLines = (id) => Math.ceil((extOf[id] || []).length / FAN_PER_LINE);
			// Room for the fan plus a gap, so zones drawn around neighboring hosts do not overlap.
			const slot = (id) => Math.max(HOST_GAP, Math.min((extOf[id] || []).length, FAN_PER_LINE) * FAN_GAP + 90);
			const positions = {};
			let y = 0;

			for (const row of rows) {
				for (let start = 0; start < row.length; start += MAX_ROW) {
					const chunk = row.slice(start, start + MAX_ROW);
					const width = chunk.reduce((sum, id) => sum + slot(id), 0);
					let x = -width / 2;

					for (const id of chunk) {
						const cx = x + slot(id) / 2;

						positions[id] = {x: snap(cx), y: snap(y)};

						(extOf[id] || []).forEach((ext, i) => {
							const line = Math.floor(i / FAN_PER_LINE);
							const inLine = Math.min(FAN_PER_LINE, extOf[id].length - line * FAN_PER_LINE);
							const k = i % FAN_PER_LINE;

							positions[ext] = {
								x: snap(cx + (k - (inLine - 1) / 2) * FAN_GAP),
								y: snap(y + 110 + line * 70)
							};
						});

						x += slot(id);
					}

					const lines = Math.max(0, ...chunk.map(fanLines));

					y += 200 + (lines > 0 ? 110 + (lines - 1) * 70 : 0);
				}
			}

			// Neighbors seen only by hidden or missing hosts: a row at the bottom.
			ids.filter((id) => positions[id] === undefined).forEach((id, i, all) => {
				positions[id] = {x: snap((i - (all.length - 1) / 2) * FAN_GAP), y: snap(y)};
			});

			return positions;
		}

		/**
		 * Force-directed layouts ignore the screen shape: stretch the result towards the canvas aspect ratio.
		 */
		stretchToViewport(nodes) {
			if (nodes.length < 4) {
				return;
			}

			const bb = nodes.boundingBox({includeLabels: false, includeOverlays: false});

			if (bb.w < 1 || bb.h < 1) {
				return;
			}

			const target = this.cy.width() / Math.max(1, this.cy.height());
			const ratio = bb.w / bb.h;
			const sx = ratio < target ? Math.min(1.6, target / ratio) : 1;
			const sy = ratio > target ? Math.min(1.6, ratio / target) : 1;

			if (sx === 1 && sy === 1) {
				return;
			}

			const cx = bb.x1 + bb.w / 2;
			const cy = bb.y1 + bb.h / 2;

			nodes.positions((n) => ({x: cx + (n.position('x') - cx) * sx, y: cy + (n.position('y') - cy) * sy}));
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Saved state
		 * ---------------------------------------------------------------------------------------------
		 */

		/**
		 * Current drawing: node positions, link shapes, zones/texts/lines.
		 */
		collectLayout() {
			const nodes = {};

			this.cy.nodes(NETWORK_NODES).forEach((n) => {
				const pos = n.position();

				nodes[n.id()] = [round1(pos.x), round1(pos.y)];
			});

			const edges = {};

			for (const [id, bend] of Object.entries(this.bends)) {
				edges[id] = bend;
			}

			const shapes = [];

			this.cy.nodes('[kind="shape"]').forEach((n) => {
				const d = n.data();
				const pos = n.position();

				shapes.push({id: n.id(), t: d.t, x: round1(pos.x), y: round1(pos.y), w: round1(d.w), h: round1(d.h),
					text: d.text, color: d.colorKey || d.color, fs: d.fs, ls: d.ls, fill: d.fill ? 1 : 0});
			});

			this.cy.edges('[kind="dline"]').forEach((edge) => {
				const d = edge.data();
				const a = edge.source().position();
				const b = edge.target().position();

				shapes.push({id: d.shape, t: 'line', x: round1(a.x), y: round1(a.y), x2: round1(b.x), y2: round1(b.y),
					text: d.text, color: d.colorKey || d.color, fs: d.fs, ls: d.ls, lw: d.lw, arrow: d.arrow});
			});

			return {nodes, edges, shapes};
		}

		markChanged() {
			this.setDirty(true);
		}

		setDirty(dirty) {
			this.dirty = dirty;
			this.updateHeader();
		}

		/**
		 * "Restore": the saved map as it is stored (selection included), or the automatic layout.
		 */
		restore() {
			if (this.map && this.selectionChanged()) {
				// The saved selection comes back through the page (the filter is part of the map).
				this.openMap(this.map.id);

				return;
			}

			if (this.map) {
				this.applySaved(this.map.layout, true);
			}
			else {
				this.bends = {};
				this.cy.edges('[kind="net"]').forEach((edge) => this.clearBendStyle(edge));
				this.removeHandle();
				this.cy.elements(DRAWINGS).remove();
				this.runLayout(this.defaultLayoutName(), false);
			}

			this.setDirty(false);
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Lock and edit mode
		 * ---------------------------------------------------------------------------------------------
		 */

		setLocked(locked) {
			this.locked = locked;
			this.applyGrab();
			this.updateDock();
		}

		applyGrab() {
			this.cy.autoungrabify(!(this.editing || !this.locked));
		}

		setEditing(editing) {
			this.editing = editing;
			this.root.classList.toggle('ntp-editing', editing);
			this.cy.autounselectify(!editing);

			if (!editing) {
				this.cy.elements().unselect();
				this.removeHandle();
				this.onSelect(null);
			}
			else {
				this.unpin();
				this.clearHighlight();
				this.toast('info', this.t('Edit mode: drag hosts, drag a link to bend it (double-click straightens it), add zones and texts with the tools on the left.'));
			}

			this.syncDrawingState();
			this.applyGrab();
			this.updateDock();
			this.updateHeader();
		}

		/**
		 * Drawings react to the mouse only while editing.
		 */
		syncDrawingState() {
			const drawings = this.cy.elements(DRAWINGS);

			drawings.toggleClass('ntp-passive', !this.editing);
			drawings.toggleClass('ntp-editable', this.editing);
		}

		onDragFree(node) {
			if (node.data('kind') === 'handle') {
				this.markChanged();

				return;
			}

			if (this.editing) {
				const moved = this.zoneDrag && this.zoneDrag.zone.same(node)
					? node.union(this.zoneDrag.members)
					: node;

				moved.forEach((n) => {
					if (n.data('kind') !== 'lpoint' || !n.hasClass('ntp-free')) {
						n.position({x: snap(n.position('x')), y: snap(n.position('y'))});
					}
				});

				this.zoneDrag = null;
				this.updateHandle();
			}

			this.markChanged();
		}

		/**
		 * Dragging a zone takes the hosts and drawings inside it along (Shift: only the zone).
		 */
		onZoneGrab(e) {
			const zone = e.target;

			this.zoneDrag = null;

			if (!this.editing || zone.data('t') === 'text' || (e.originalEvent && e.originalEvent.shiftKey)) {
				return;
			}

			const pos = zone.position();
			const w = zone.data('w') / 2;
			const h = zone.data('h') / 2;
			const ellipse = zone.data('t') === 'ellipse';
			const inside = (p) => ellipse
				? Math.pow((p.x - pos.x) / w, 2) + Math.pow((p.y - pos.y) / h, 2) <= 1
				: Math.abs(p.x - pos.x) <= w && Math.abs(p.y - pos.y) <= h;

			const members = this.cy.nodes(NETWORK_NODES + ', node[kind="lpoint"], node[kind="shape"][t="text"]')
				.filter((n) => !n.same(zone) && inside(n.position()));

			this.zoneDrag = {zone, members, last: {x: pos.x, y: pos.y}};
		}

		onNodeDrag(node) {
			if (this.zoneDrag && this.zoneDrag.zone.same(node)) {
				const pos = node.position();
				const dx = pos.x - this.zoneDrag.last.x;
				const dy = pos.y - this.zoneDrag.last.y;

				this.zoneDrag.last = {x: pos.x, y: pos.y};
				this.zoneDrag.members.forEach((n) => {
					n.position({x: n.position('x') + dx, y: n.position('y') + dy});
				});
			}

			if (node.data('kind') === 'handle') {
				this.resizeFromHandle(node);
			}
			else if (this.handleFor && this.handleFor.same(node)) {
				this.updateHandle();
			}
		}

		onKey(e) {
			if (e.key === 'Escape') {
				this.unpin();
				this.clearHighlight();
				this.closeMenus();

				if (this.dialog) {
					this.closeDialog();
				}
			}

			const typing = e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA'
				|| e.target.isContentEditable);

			if (this.editing && !typing && (e.key === 'Delete' || e.key === 'Backspace')) {
				const selected = this.cy.$(':selected');

				if (selected.nonempty()) {
					e.preventDefault();
					this.deleteSelected();
				}
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Link shapes (curve, elbow, straight)
		 * ---------------------------------------------------------------------------------------------
		 */

		/**
		 * Bends are stored for the link drawn from its lower node id to the higher one, so they survive the
		 * link being reported the other way around.
		 */
		isReversed(edge) {
			return edge.source().id() > edge.target().id();
		}

		toCanonical(edge, bend) {
			if (!bend || !this.isReversed(edge)) {
				return bend;
			}

			if (bend.s === 'curve') {
				return {s: 'curve', d: -bend.d, w: round1((1 - bend.w) * 1000) / 1000};
			}

			if (bend.s === 'taxi') {
				return {s: 'taxi', t: round1(100 - bend.t), dir: bend.dir};
			}

			return bend;
		}

		fromCanonical(edge, bend) {
			// The conversion is its own inverse.
			return this.toCanonical(edge, bend);
		}

		setBend(edge, bend) {
			if (bend === null) {
				delete this.bends[edge.id()];
				this.clearBendStyle(edge);
			}
			else {
				this.bends[edge.id()] = this.toCanonical(edge, bend);
				this.applyBendStyle(edge);
			}

			if (edge.selected()) {
				this.onSelect(edge);
			}
		}

		applyBendStyle(edge) {
			const bend = this.fromCanonical(edge, this.bends[edge.id()]);

			if (!bend) {
				return;
			}

			if (bend.s === 'curve') {
				edge.style({
					'curve-style': 'unbundled-bezier',
					'control-point-distances': [bend.d],
					'control-point-weights': [bend.w],
					'edge-distances': 'node-position'
				});
			}
			else if (bend.s === 'taxi') {
				edge.style({
					'curve-style': 'round-taxi',
					'taxi-direction': bend.dir || 'auto',
					'taxi-turn': bend.t + '%',
					'taxi-turn-min-distance': 8,
					'taxi-radius': 10,
					'edge-distances': 'node-position'
				});
			}
			else {
				edge.style({'curve-style': 'straight'});
			}
		}

		clearBendStyle(edge) {
			edge.removeStyle('curve-style control-point-distances control-point-weights edge-distances taxi-direction '
				+ 'taxi-turn taxi-turn-min-distance taxi-radius');
		}

		/**
		 * Curve through point p: one control point placed so that the middle of the curve passes over p.
		 */
		curveThrough(edge, p) {
			const s = edge.source().position();
			const t = edge.target().position();
			const vx = t.x - s.x;
			const vy = t.y - s.y;
			const len2 = vx * vx + vy * vy;

			if (len2 < 1) {
				return null;
			}

			const along = ((p.x - s.x) * vx + (p.y - s.y) * vy) / len2;
			const perp = (vx * (p.y - s.y) - vy * (p.x - s.x)) / Math.sqrt(len2);
			const w = Math.min(0.95, Math.max(0.05, 2 * along - 0.5));

			return {s: 'curve', d: round1(2 * perp), w: Math.round(w * 1000) / 1000};
		}

		/**
		 * Elbow: the turn follows the mouse along the main direction of the link.
		 */
		taxiThrough(edge, p, dir) {
			const s = edge.source().position();
			const t = edge.target().position();
			const horizontal = dir === 'horizontal' || (dir !== 'vertical' && Math.abs(t.x - s.x) >= Math.abs(t.y - s.y));
			const span = horizontal ? t.x - s.x : t.y - s.y;

			if (Math.abs(span) < 1) {
				return {s: 'taxi', t: 50, dir: dir || 'auto'};
			}

			const turn = ((horizontal ? p.x - s.x : p.y - s.y) / span) * 100;

			return {s: 'taxi', t: round1(Math.min(100, Math.max(0, turn))), dir: dir || 'auto'};
		}

		onEdgeTapStart(e) {
			if (!this.editing) {
				return;
			}

			const edge = e.target;

			this.edgeDrag = {edge, start: e.renderedPosition || e.position, moved: false};
			this.cy.userPanningEnabled(false);
		}

		/**
		 * A drawn line moves as a whole when dragged by its middle (its ends move it one side at a time).
		 */
		onLineTapStart(e) {
			if (!this.editing) {
				return;
			}

			this.lineDrag = {edge: e.target, last: Object.assign({}, e.position), moved: false};
			this.cy.userPanningEnabled(false);
		}

		onTapDrag(e) {
			if (this.lineDrag) {
				const dx = e.position.x - this.lineDrag.last.x;
				const dy = e.position.y - this.lineDrag.last.y;

				this.lineDrag.last = Object.assign({}, e.position);
				this.lineDrag.moved = true;
				this.lineDrag.edge.connectedNodes().forEach((n) => {
					n.position({x: n.position('x') + dx, y: n.position('y') + dy});
				});

				return;
			}

			const drag = this.edgeDrag;

			if (!drag) {
				return;
			}

			const r = e.renderedPosition || e.position;

			if (!drag.moved && Math.abs(r.x - drag.start.x) + Math.abs(r.y - drag.start.y) < 5) {
				return;
			}

			drag.moved = true;
			this.hideTooltip();

			const current = this.fromCanonical(drag.edge, this.bends[drag.edge.id()]);
			const bend = current && current.s === 'taxi'
				? this.taxiThrough(drag.edge, e.position, current.dir)
				: this.curveThrough(drag.edge, e.position);

			if (bend) {
				this.bends[drag.edge.id()] = this.toCanonical(drag.edge, bend);
				this.applyBendStyle(drag.edge);
			}
		}

		onTapEnd() {
			if (this.lineDrag) {
				const line = this.lineDrag;

				this.lineDrag = null;
				this.cy.userPanningEnabled(true);

				if (line.moved) {
					line.edge.connectedNodes().forEach((n) => n.position({x: snap(n.position('x')), y: snap(n.position('y'))}));
					this.markChanged();
				}

				return;
			}

			const drag = this.edgeDrag;

			if (!drag) {
				return;
			}

			this.edgeDrag = null;
			this.cy.userPanningEnabled(true);

			if (drag.moved) {
				this.markChanged();
				this.cy.elements().unselect();
				drag.edge.select();
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Drawings
		 * ---------------------------------------------------------------------------------------------
		 */

		addShape(type) {
			if (!this.editing) {
				this.setEditing(true);
			}

			const ext = this.cy.extent();
			const cx = snap((ext.x1 + ext.x2) / 2);
			const cy = snap((ext.y1 + ext.y2) / 2);
			const id = 'd' + randomHex(10);
			let shape;

			if (type === 'line') {
				shape = {id, t: 'line', x: cx - 100, y: cy, x2: cx + 100, y2: cy, text: '', color: DRAW_COLORS[7], fs: 12,
					ls: 'dashed', lw: 2, arrow: 1};
			}
			else if (type === 'text') {
				// "auto": the text color of the theme of whoever looks at the map (dark or light).
				shape = {id, t: 'text', x: cx, y: cy, w: 180, h: 40, text: this.t('Text'), color: 'auto', fs: 16,
					ls: 'solid', fill: 0};
			}
			else {
				const zones = this.cy.nodes('[kind="shape"][t!="text"]').length;

				shape = {id, t: type, x: cx, y: cy, w: 300, h: 200, text: this.t('New zone'),
					color: DRAW_COLORS[zones % DRAW_COLORS.length], fs: 14, ls: 'dashed', fill: 1};
			}

			const added = this.addShapeElements(shape);

			this.syncDrawingState();
			this.cy.elements().unselect();
			(type === 'line' ? added.filter('edge') : added).select();
			this.markChanged();
		}

		/**
		 * @return {cytoscape.Collection}  elements of the drawing
		 */
		addShapeElements(shape) {
			const fs = shape.fs || 14;
			const colorKey = shape.color || DRAW_COLORS[0];
			const color = colorKey === 'auto' ? this.palette.text : colorKey;

			if (shape.t === 'line') {
				const common = {kind: 'lpoint', line: shape.id, color};

				return this.cy.add([
					{group: 'nodes', data: Object.assign({id: shape.id + ':a'}, common), position: {x: shape.x, y: shape.y}},
					{group: 'nodes', data: Object.assign({id: shape.id + ':b'}, common), position: {x: shape.x2, y: shape.y2}},
					{group: 'edges', data: {
						id: shape.id + ':l', kind: 'dline', shape: shape.id, source: shape.id + ':a', target: shape.id + ':b',
						text: shape.text || '', color, colorKey, fs, ls: shape.ls || 'solid', lw: shape.lw || 2,
						arrow: shape.arrow || 0, ta: shape.arrow >= 1 ? 'triangle' : 'none',
						sa: shape.arrow >= 2 ? 'triangle' : 'none'
					}}
				]);
			}

			const fillop = shape.t === 'text' ? 0 : (shape.fill ? ZONE_FILL : 0);

			return this.cy.add({
				group: 'nodes',
				data: {
					id: shape.id, kind: 'shape', t: shape.t, w: shape.w, h: shape.h, text: shape.text || '',
					color, colorKey, fs, ls: shape.ls || 'solid', fill: shape.fill ? 1 : 0, fillop,
					lbg: mix(this.palette.canvas, color, fillop),
					cyshape: shape.t === 'ellipse' ? 'ellipse' : 'round-rectangle',
					tmy: Math.round(fs * 1.3 + 16)
				},
				position: {x: shape.x, y: shape.y}
			});
		}

		updateShape(ele, changes) {
			if (changes.color !== undefined) {
				changes = Object.assign({}, changes, {
					colorKey: changes.color,
					color: changes.color === 'auto' ? this.palette.text : changes.color
				});
			}

			if (ele.data('kind') === 'dline') {
				const data = Object.assign({}, changes);

				if (changes.arrow !== undefined) {
					data.ta = changes.arrow >= 1 ? 'triangle' : 'none';
					data.sa = changes.arrow >= 2 ? 'triangle' : 'none';
				}

				ele.data(data);

				if (changes.color) {
					ele.connectedNodes().data('color', changes.color);
				}
			}
			else {
				const data = Object.assign({}, changes);

				if (changes.fill !== undefined && ele.data('t') !== 'text') {
					data.fillop = changes.fill ? ZONE_FILL : 0;
				}

				if (changes.fs !== undefined) {
					data.tmy = Math.round(changes.fs * 1.3 + 16);
				}

				if (data.color !== undefined || data.fillop !== undefined) {
					data.lbg = mix(this.palette.canvas, data.color !== undefined ? data.color : ele.data('color'),
						data.fillop !== undefined ? data.fillop : ele.data('fillop')
					);
				}

				ele.data(data);
			}

			this.markChanged();
		}

		deleteSelected() {
			const selected = this.cy.$(':selected');

			selected.forEach((ele) => {
				const kind = ele.data('kind');

				if (kind === 'shape') {
					ele.remove();
				}
				else if (kind === 'dline' || kind === 'lpoint') {
					const id = ele.data('shape') || ele.data('line');

					this.cy.elements('[shape="' + id + '"], [line="' + id + '"]').remove();
				}
				else if (kind === 'net') {
					this.setBend(ele, null);
				}
			});

			this.removeHandle();
			this.onSelect(null);
			this.markChanged();
		}

		/**
		 * Resize handle at the bottom right corner of the selected zone or text.
		 */
		showHandle(shape) {
			this.removeHandle();
			this.handleFor = shape;
			this.handleAnchor = {
				x: shape.position('x') - shape.data('w') / 2,
				y: shape.position('y') - shape.data('h') / 2
			};

			this.cy.add({
				group: 'nodes',
				data: {id: HANDLE_ID, kind: 'handle'},
				position: {x: shape.position('x') + shape.data('w') / 2, y: shape.position('y') + shape.data('h') / 2},
				selectable: false
			});
		}

		updateHandle() {
			const shape = this.handleFor;
			const handle = this.cy.getElementById(HANDLE_ID);

			if (!shape || handle.empty() || shape.removed()) {
				return;
			}

			this.handleAnchor = {
				x: shape.position('x') - shape.data('w') / 2,
				y: shape.position('y') - shape.data('h') / 2
			};
			handle.position({x: shape.position('x') + shape.data('w') / 2, y: shape.position('y') + shape.data('h') / 2});
		}

		removeHandle() {
			this.cy.getElementById(HANDLE_ID).remove();
			this.handleFor = null;
		}

		resizeFromHandle(handle) {
			const shape = this.handleFor;

			if (!shape || shape.removed()) {
				return;
			}

			const a = this.handleAnchor;
			const p = handle.position();
			const w = Math.max(30, p.x - a.x);
			const h = Math.max(24, p.y - a.y);

			shape.data({w, h});
			shape.position({x: a.x + w / 2, y: a.y + h / 2});
		}

		/*
		 * Properties panel (edit mode).
		 */
		onSelect(ele) {
			if (!this.editing || !ele || ele.removed() || !ele.selected()) {
				this.props.hidden = true;

				if (!ele) {
					this.removeHandle();
				}

				return;
			}

			const kind = ele.data('kind');

			if (kind === 'handle') {
				return;
			}

			if (kind === 'shape' && ele.data('t') !== 'line') {
				this.showHandle(ele);
			}
			else {
				this.removeHandle();
			}

			if (kind === 'lpoint') {
				this.props.hidden = true;

				return;
			}

			if (kind === 'host' || kind === 'ext') {
				this.props.hidden = true;

				return;
			}

			this.props.replaceChildren(...(kind === 'net' ? this.edgeProps(ele) : this.shapeProps(ele)));
			this.props.hidden = false;
		}

		propRow(label, control) {
			return el('div', {class: 'ntp-prop'}, [el('label', {text: label}), control]);
		}

		segmented(options, value, onchange) {
			const group = el('div', {class: 'ntp-seg'});

			for (const [key, label, icon] of options) {
				const b = el('button', {type: 'button', class: 'ntp-seg-btn' + (key === value ? ' ntp-on' : ''),
					title: label, 'aria-label': label
				});

				b.innerHTML = icon ? svg(icon, 14) : esc(label);
				b.addEventListener('click', () => {
					group.querySelectorAll('.ntp-seg-btn').forEach((x) => x.classList.remove('ntp-on'));
					b.classList.add('ntp-on');
					onchange(key);
				});
				group.appendChild(b);
			}

			return group;
		}

		swatches(value, onchange) {
			const group = el('div', {class: 'ntp-swatches'});
			const colors = DRAW_COLORS.concat(['auto']);

			for (const color of colors) {
				const label = color === 'auto' ? this.t('Text color of the theme') : color;
				const b = el('button', {type: 'button', class: 'ntp-swatch' + (color === value ? ' ntp-on' : '')
					+ (color === 'auto' ? ' ntp-swatch-auto' : ''),
					style: 'background:' + (color === 'auto' ? this.palette.text : color), title: label, 'aria-label': label
				});

				b.addEventListener('click', () => {
					group.querySelectorAll('.ntp-swatch').forEach((x) => x.classList.remove('ntp-on'));
					b.classList.add('ntp-on');
					onchange(color);
				});
				group.appendChild(b);
			}

			return group;
		}

		shapeProps(ele) {
			const t = (s) => this.t(s);
			const isLine = ele.data('kind') === 'dline';
			const isText = !isLine && ele.data('t') === 'text';
			const title = isLine ? t('Line') : (isText ? t('Text') : t('Zone'));

			const text = el(isText ? 'textarea' : 'input', {class: 'ntp-input', rows: isText ? 3 : null,
				value: isText ? null : ele.data('text'), placeholder: isLine ? t('Optional label') : ''
			});

			if (isText) {
				text.value = ele.data('text');
			}

			text.addEventListener('input', () => this.updateShape(ele, {text: text.value}));

			const rows = [
				el('div', {class: 'ntp-props-title', text: title}),
				this.propRow(isText ? t('Text') : t('Label'), text),
				this.propRow(t('Color'), this.swatches(ele.data('colorKey') || ele.data('color'),
					(color) => this.updateShape(ele, {color})))
			];

			if (!isText) {
				rows.push(this.propRow(t('Line'), this.segmented([
					['solid', t('Solid')], ['dashed', t('Dashed')], ['dotted', t('Dotted')]
				], ele.data('ls'), (ls) => this.updateShape(ele, {ls}))));
			}

			if (isLine) {
				rows.push(this.propRow(t('Width'), this.segmented([[1, '1'], [2, '2'], [4, '4']], ele.data('lw'),
					(lw) => this.updateShape(ele, {lw})
				)));
				rows.push(this.propRow(t('Arrows'), this.segmented([
					[0, t('None')], [1, t('End')], [2, t('Both')]
				], ele.data('arrow'), (arrow) => this.updateShape(ele, {arrow}))));
			}
			else if (!isText) {
				const fill = el('input', {type: 'checkbox', checked: ele.data('fill') ? true : null});

				fill.addEventListener('change', () => this.updateShape(ele, {fill: fill.checked ? 1 : 0}));
				rows.push(this.propRow(t('Background'), el('span', {class: 'ntp-check'}, [fill])));
			}

			rows.push(this.propRow(t('Text size'), this.segmented([[12, 'P'], [16, 'M'], [22, 'G'], [30, 'GG']],
				ele.data('fs'), (fs) => this.updateShape(ele, {fs})
			)));

			if (!isLine && !isText) {
				rows.push(el('div', {class: 'ntp-props-hint', text: t('Dragging a zone takes the hosts inside it along (hold Shift to move only the zone). Resize it by the square in the corner.')}));
			}

			rows.push(el('div', {class: 'ntp-props-actions'}, [
				this.button('trash', t('Delete'), () => this.deleteSelected(), {label: t('Delete'), class: 'ntp-btn-danger'})
			]));

			return rows;
		}

		edgeProps(edge) {
			const t = (s) => this.t(s);
			const bend = this.fromCanonical(edge, this.bends[edge.id()]);
			const style = bend ? bend.s : 'auto';
			const src = this.nodeIndex[edge.source().id()] || {name: edge.source().id()};
			const tgt = this.nodeIndex[edge.target().id()] || {name: edge.target().id()};

			const rows = [
				el('div', {class: 'ntp-props-title', text: t('Link')}),
				el('div', {class: 'ntp-props-sub', text: src.name + ' ↔ ' + tgt.name}),
				this.propRow(t('Shape'), this.segmented([
					['auto', t('Automatic'), 'auto'], ['curve', t('Curve'), 'curve'], ['taxi', t('Elbow'), 'elbow'],
					['straight', t('Straight'), 'straight']
				], style, (s) => {
					if (s === 'auto') {
						this.setBend(edge, null);
					}
					else if (s === 'curve') {
						const a = edge.source().position();
						const b = edge.target().position();

						this.setBend(edge, this.curveThrough(edge, {x: (a.x + b.x) / 2 + (b.y - a.y) * 0.2,
							y: (a.y + b.y) / 2 - (b.x - a.x) * 0.2}));
					}
					else if (s === 'taxi') {
						this.setBend(edge, {s: 'taxi', t: 50, dir: 'auto'});
					}
					else {
						this.setBend(edge, {s: 'straight'});
					}

					this.markChanged();
				}))
			];

			if (style === 'taxi') {
				rows.push(this.propRow(t('Direction'), this.segmented([
					['auto', t('Automatic')], ['horizontal', t('Horizontal')], ['vertical', t('Vertical')]
				], bend.dir || 'auto', (dir) => {
					this.setBend(edge, Object.assign({}, bend, {dir}));
					this.markChanged();
				})));
			}

			rows.push(el('div', {class: 'ntp-props-hint', text: t('Drag the link to bend it. Double-click it to go back to automatic.')}));

			return rows;
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Saved maps
		 * ---------------------------------------------------------------------------------------------
		 */

		openMap(id) {
			if (this.editing && this.dirty && !window.confirm(this.t('Leave without saving the changes?'))) {
				return;
			}

			this.dirty = false;
			window.location.href = this.config.view_url + '&mapid=' + encodeURIComponent(id);
		}

		async request(params) {
			const body = new URLSearchParams();

			for (const [key, value] of Object.entries(params)) {
				body.append(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
			}

			body.append('_csrf_token', this.config.csrf_token);

			const response = await fetch(this.config.map_url, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: body.toString(),
				credentials: 'same-origin'
			});

			let data;

			try {
				data = await response.json();
			}
			catch (e) {
				throw new Error('HTTP ' + response.status);
			}

			if (data.error) {
				throw new Error((data.error.messages || []).join('\n'));
			}

			if (data.maps) {
				this.maps = data.maps;
			}

			return data;
		}

		saveClick() {
			if (this.map && this.map.can_edit) {
				this.saveMap();
			}
			else {
				this.openSaveDialog(this.map ? 'copy' : 'new');
			}
		}

		async saveMap() {
			try {
				const data = await this.request({
					operation: 'save',
					mapid: this.map.id,
					rev: this.map.rev,
					filter: this.config.filter,
					layout: this.collectLayout()
				});

				this.map = data.map;
				this.setDirty(false);
				this.toast('success', data.message);
			}
			catch (error) {
				this.toast('error', error.message);
			}
		}

		async createMap(name, scope) {
			const data = await this.request({
				operation: 'save',
				name,
				scope,
				filter: this.config.filter,
				layout: this.collectLayout()
			});

			this.dirty = false;
			window.location.href = this.config.view_url + '&mapid=' + encodeURIComponent(data.map.id);
		}

		openSaveDialog(mode) {
			const t = (s) => this.t(s);
			const name = el('input', {type: 'text', class: 'ntp-input', maxlength: 128,
				value: mode === 'copy' && this.map ? this.map.name + ' (' + t('copy') + ')' : ''
			});
			const canShare = !!this.config.can_edit_shared;
			const scopeShared = el('input', {type: 'radio', name: 'ntp-scope', value: 'shared', disabled: !canShare,
				checked: canShare && mode === 'new' ? true : null
			});
			const scopePersonal = el('input', {type: 'radio', name: 'ntp-scope', value: 'personal',
				checked: !canShare || mode !== 'new' ? true : null
			});

			const content = [
				el('p', {class: 'ntp-dialog-text', text: t('The map keeps the hosts selected in the filter, their positions, the shape of the links and the drawings.')}),
				this.propRow(t('Name'), name),
				el('div', {class: 'ntp-prop ntp-prop-radios'}, [
					el('label', {text: t('Who sees it')}),
					el('div', {}, [
						el('label', {class: 'ntp-radio' + (canShare ? '' : ' ntp-disabled')}, [scopeShared,
							el('span', {html: svg('globe', 13) + esc(t('Public: the standard map for all teams'))})
						]),
						el('label', {class: 'ntp-radio'}, [scopePersonal,
							el('span', {html: svg('user', 13) + esc(t('Personal: only me'))})
						]),
						canShare ? null : el('div', {class: 'ntp-props-hint', text: t('Only editors (Admins, or the groups chosen by a Super admin) create public maps.')})
					])
				])
			];

			this.showDialog(mode === 'copy' ? t('Save a copy') : t('Save as a new map'), content, t('Save'), async () => {
				if (name.value.trim() === '') {
					name.focus();

					throw new Error(t('Enter a name for the map.'));
				}

				await this.createMap(name.value.trim(), scopeShared.checked ? 'shared' : 'personal');
			});

			name.focus();
			name.select();
		}

		openRenameDialog() {
			const t = (s) => this.t(s);
			const name = el('input', {type: 'text', class: 'ntp-input', maxlength: 128, value: this.map.name});

			this.showDialog(t('Rename map'), [this.propRow(t('Name'), name)], t('Rename'), async () => {
				const data = await this.request({operation: 'rename', mapid: this.map.id, name: name.value});

				this.map = Object.assign(this.map, {name: data.map.name, rev: data.map.rev});
				this.updateHeader();
				this.toast('success', data.message);
			});

			name.focus();
			name.select();
		}

		async deleteMap() {
			const t = (s) => this.t(s);
			const text = this.map.scope === 'shared'
				? this.tf('Delete the public map "%1$s"? Every team will stop seeing it.', this.map.name)
				: this.tf('Delete the map "%1$s"?', this.map.name);

			if (!window.confirm(text)) {
				return;
			}

			try {
				await this.request({operation: 'delete', mapid: this.map.id});
				this.dirty = false;
				window.location.href = this.config.view_url + '&mapid=0';
			}
			catch (error) {
				this.toast('error', error.message || t('Error'));
			}
		}

		async setDefault(id) {
			try {
				const data = await this.request({operation: 'default', mapid: id});
				const current = this.maps.find((m) => this.map && m.id === this.map.id);

				if (current) {
					this.map.default = current.default;
				}

				this.toast('success', data.message);
			}
			catch (error) {
				this.toast('error', error.message);
			}
		}

		async publish() {
			const t = (s) => this.t(s);

			if (this.dirty || this.selectionChanged()) {
				this.toast('warning', t('Save the map before publishing it.'));

				return;
			}

			const positions = {};

			this.cy.nodes(NETWORK_NODES).filter(':visible').forEach((n) => {
				positions[n.id()] = [round1(n.position('x')), round1(n.position('y'))];
			});

			this.toast('info', t('Publishing...'));

			try {
				const data = await this.request({operation: 'publish', mapid: this.map.id, positions});

				this.map = data.map;
				this.toast('success', data.message, data.url ? {href: data.url, text: t('Open')} : null);
			}
			catch (error) {
				this.toast('error', error.message);
			}
		}

		copyLink() {
			const url = new URL(this.config.view_url + '&mapid=' + encodeURIComponent(this.map.id), window.location.href);
			const done = () => this.toast('success', this.t('Link copied. Anyone with access to these hosts can open it.'));

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(url.toString()).then(done, () => window.prompt(this.t('Link'), url.toString()));
			}
			else {
				window.prompt(this.t('Link'), url.toString());
			}
		}

		showDialog(title, content, okLabel, onOk) {
			const t = (s) => this.t(s);

			this.closeDialog();

			const error = el('div', {class: 'ntp-dialog-error', hidden: true});
			const ok = el('button', {type: 'button', class: 'ntp-btn ntp-btn-text ntp-btn-primary'}, [el('span', {text: okLabel})]);
			const cancel = el('button', {type: 'button', class: 'ntp-btn ntp-btn-text'}, [el('span', {text: t('Cancel')})]);
			const box = el('div', {class: 'ntp-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title}, [
				el('div', {class: 'ntp-dialog-title', text: title}),
				el('div', {class: 'ntp-dialog-body'}, content),
				error,
				el('div', {class: 'ntp-dialog-buttons'}, [cancel, ok])
			]);
			const backdrop = el('div', {class: 'ntp-backdrop'}, [box]);

			const submit = async () => {
				ok.disabled = true;
				error.hidden = true;

				try {
					await onOk();
					this.closeDialog();
				}
				catch (e) {
					error.textContent = e.message;
					error.hidden = false;
				}
				finally {
					ok.disabled = false;
				}
			};

			ok.addEventListener('click', submit);
			cancel.addEventListener('click', () => this.closeDialog());
			box.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
					e.preventDefault();
					submit();
				}
			});
			backdrop.addEventListener('click', (e) => {
				if (e.target === backdrop) {
					this.closeDialog();
				}
			});

			this.root.appendChild(backdrop);
			this.dialog = backdrop;
		}

		closeDialog() {
			if (this.dialog) {
				this.dialog.remove();
				this.dialog = null;
			}
		}

		toast(type, text, link = null) {
			if (!text) {
				return;
			}

			const item = el('div', {class: 'ntp-toast ntp-toast-' + type, role: type === 'error' ? 'alert' : 'status'}, [
				el('span', {text}),
				link ? el('a', {href: link.href, target: '_blank', rel: 'noopener', text: link.text}) : null
			]);
			const close = this.button('close', this.t('Close'), () => item.remove(), {class: 'ntp-btn-small'});

			item.appendChild(close);

			// The same message again (e.g. edit mode turned on twice) replaces the old one.
			for (const old of [...this.toasts.children]) {
				if (old.textContent === item.textContent) {
					old.remove();
				}
			}

			this.toasts.appendChild(item);

			while (this.toasts.children.length > 3) {
				this.toasts.firstChild.remove();
			}

			if (type !== 'error') {
				setTimeout(() => item.remove(), type === 'info' ? 7000 : 9000);
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * View controls
		 * ---------------------------------------------------------------------------------------------
		 */

		zoomBy(factor) {
			const cy = this.cy;

			cy.zoom({
				level: Math.max(cy.minZoom(), Math.min(cy.maxZoom(), cy.zoom() * factor)),
				renderedPosition: {x: cy.width() / 2, y: cy.height() / 2}
			});
		}

		/**
		 * Fits the visible graph in the free area of the canvas: below the header, right of the toolbar and
		 * above the legend, so no node hides behind them.
		 */
		fit(animate) {
			const cy = this.cy;
			const visible = cy.elements(':visible').filter((e) => e.data('kind') !== 'handle');

			if (visible.empty()) {
				return;
			}

			const bb = visible.boundingBox();
			const width = cy.width();
			const height = cy.height();
			const top = 58;
			const left = 56;
			const pad = 24;
			let bottom = 34;

			if (!this.legend.classList.contains('ntp-legend-collapsed')) {
				bottom = Math.max(bottom, Math.min(height * 0.3, this.legend.getBoundingClientRect().height + 16));
			}

			// The chain map banner sits at the bottom center.
			if (this.capacityMode) {
				bottom = Math.max(bottom, 52);
			}

			const freeWidth = Math.max(100, width - left - 2 * pad);
			const freeHeight = Math.max(100, height - top - bottom);
			let zoom = Math.min(freeWidth / Math.max(bb.w, 1), freeHeight / Math.max(bb.h, 1));

			zoom = Math.max(cy.minZoom(), Math.min(1.6, zoom));

			const pan = {
				x: left + pad + (freeWidth - bb.w * zoom) / 2 - bb.x1 * zoom,
				y: top + (freeHeight - bb.h * zoom) / 2 - bb.y1 * zoom
			};

			if (animate) {
				cy.animate({zoom, pan, duration: 300});
			}
			else {
				cy.viewport({zoom, pan});
				this.updateGrid();
			}
		}

		toggleLabels() {
			this.showLabels = !this.showLabels;
			this.savePref('labels', this.showLabels);
			this.cy.elements(NETWORK_NODES + ', edge[kind="net"]').toggleClass('nolabel', !this.showLabels);
			this.updateDock();
		}

		/**
		 * Chain map on/off: links colored by the utilization of capacity planning, or by their status.
		 */
		toggleCapacity() {
			this.capacityMode = !this.capacityMode;
			this.capacityBar.hidden = !this.capacityMode;
			this.updateDock();

			const legend = this.buildLegend();

			this.legend.replaceWith(legend);
			this.legend = legend;

			try {
				const url = new URL(window.location.href);

				if (this.capacityMode) {
					url.searchParams.set('capacity', '1');
				}
				else {
					url.searchParams.delete('capacity');
				}

				window.history.replaceState(null, '', url.toString());
			}
			catch (e) {
				// Old browsers: only the URL is not updated.
			}

			if (this.data) {
				this.apply(this.data, false);
			}

			if (this.loading) {
				this.reloadWanted = true;
			}
			else {
				this.load(false);
			}
		}

		toggleExternal() {
			this.showExternal = !this.showExternal;
			this.savePref('external', this.showExternal);
			this.applyVisibility();
			this.updateDock();
		}

		highlight(node) {
			const neighborhood = node.closedNeighborhood();

			this.cy.batch(() => {
				this.cy.elements(NETWORK_NODES + ', edge[kind="net"]').removeClass('highlight').addClass('faded');
				neighborhood.removeClass('faded').addClass('highlight');
			});
		}

		clearHighlight() {
			this.cy.elements().removeClass('faded highlight');
			this.applyCircuit();
		}

		/**
		 * Highlights the hosts of the circuit and the links that touch its interfaces.
		 */
		applyCircuit() {
			if (!this.circuit || !this.circuitPath) {
				return;
			}

			const hosts = new Set(this.circuitPath.hostids.map(String));
			const edges = new Set(this.circuitPath.edges);

			this.cy.batch(() => {
				this.cy.elements(NETWORK_NODES + ', edge[kind="net"]').removeClass('highlight').addClass('faded');

				this.cy.nodes('[kind="host"]').forEach((node) => {
					if (hosts.has(String(node.data('hostid')))) {
						node.removeClass('faded').addClass('highlight');
					}
				});

				this.cy.edges('[kind="net"]').forEach((edge) => {
					if (edges.has(edge.id())) {
						edge.removeClass('faded').addClass('highlight');
						edge.connectedNodes().removeClass('faded');
					}
				});
			});
		}

		exitCircuit() {
			this.circuit = null;
			this.circuitPath = null;

			if (this.circuitBar) {
				this.circuitBar.remove();
			}

			this.cy.elements().removeClass('faded highlight');

			// Hosts added only to show the circuit go away with it.
			const req = this.config.request;
			const before = (req.hostids || []).join(',');

			req.hostids = (req.groupids || []).length || (req.filter_hostids || []).length
				? (req.filter_hostids || [])
				: [];

			if (req.hostids.join(',') !== before) {
				this.load(false);
			}

			try {
				const url = new URL(window.location.href);

				url.searchParams.delete('circuit');
				window.history.replaceState(null, '', url.toString());
			}
			catch (e) {
				// Old browsers: the URL keeps the parameter, nothing else changes.
			}
		}

		updateSearchList() {
			const names = new Set();

			this.cy.nodes(NETWORK_NODES).forEach((n) => {
				names.add(n.data('name'));

				if (n.data('host') && n.data('host') !== n.data('name')) {
					names.add(n.data('host'));
				}
			});

			this.searchList.replaceChildren(...Array.from(names).sort().map((name) => el('option', {value: name})));
		}

		search(text) {
			text = (text || '').trim().toLowerCase();

			if (text === '') {
				return;
			}

			const nodes = this.cy.nodes(NETWORK_NODES);
			const matches = nodes.filter((n) => {
				const values = [n.data('name'), n.data('host'), n.data('ip'), n.data('chassis')];

				return values.some((v) => v && String(v).toLowerCase() === text);
			});

			const found = matches.nonempty() ? matches : nodes.filter((n) =>
				[n.data('name'), n.data('host'), n.data('ip')].some((v) => v && String(v).toLowerCase().includes(text))
			);

			if (found.empty()) {
				this.searchInput.classList.add('ntp-search-miss');
				setTimeout(() => this.searchInput.classList.remove('ntp-search-miss'), 1200);

				return;
			}

			const node = found[0];

			if (node.hasClass('hidden')) {
				node.removeClass('hidden');
			}

			this.cy.animate({center: {eles: node}, zoom: Math.max(this.cy.zoom(), 1.2), duration: 400});
			this.highlight(node);
			node.flashClass('found', 2000);
		}

		/**
		 * Date and time (or only the time) in the language of the user in Zabbix.
		 */
		fmtDate(clock, timeOnly = false) {
			const date = new Date(clock * 1000);

			try {
				return timeOnly ? date.toLocaleTimeString(this.config.locale) : date.toLocaleString(this.config.locale);
			}
			catch (e) {
				// Unknown locale name: the one of the browser.
				return timeOnly ? date.toLocaleTimeString() : date.toLocaleString();
			}
		}

		updateStatus() {
			const s = this.data.stats;
			const time = this.fmtDate(this.data.time, true);
			const parts = [
				s.hosts + ' ' + this.t('hosts'),
				s.neighbors + ' ' + this.t('neighbors'),
				s.links + ' ' + this.t('links') + (s.down ? ' (' + s.down + ' ' + this.t('down') + ')' : ''),
				time
			];

			this.status.replaceChildren(el('span', {text: parts.join(' · ')}));
			this.status.classList.toggle('ntp-status-down', s.down > 0);
		}

		setWarnings(warnings) {
			this.warnings.hidden = !warnings.length;
			this.warnings.replaceChildren(...warnings.map((w) => el('div', {text: w})));
		}

		exportPng() {
			const handle = this.cy.getElementById(HANDLE_ID);

			handle.style('display', 'none');

			const png = this.cy.png({full: true, scale: 2, bg: this.palette.canvas, maxWidth: 8000, maxHeight: 8000});

			handle.removeStyle('display');

			const name = (this.map ? this.map.name : 'network-topology').replace(/[^\w.-]+/g, '-');
			const link = el('a', {href: png, download: name + '-' + new Date().toISOString().slice(0, 10) + '.png'});

			document.body.appendChild(link);
			link.click();
			link.remove();
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Zabbix host menu
		 * ---------------------------------------------------------------------------------------------
		 */

		openHostMenu(hostid, originalEvent) {
			const rect = this.root.getBoundingClientRect();
			const x = originalEvent ? originalEvent.clientX : rect.left + rect.width / 2;
			const y = originalEvent ? originalEvent.clientY : rect.top + rect.height / 2;
			const anchor = this.menuAnchor;

			anchor.style.left = (x - rect.left) + 'px';
			anchor.style.top = (y - rect.top) + 'px';

			const data = {type: 'host', data: {hostid: String(hostid)}};

			anchor.setAttribute('data-menu-popup', JSON.stringify(data));

			if (window.jQuery) {
				// jQuery caches data-* values: keep its cache in sync with the attribute.
				window.jQuery(anchor).data('menu-popup', data);
			}

			anchor.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true, view: window, detail: 1,
				clientX: x, clientY: y
			}));
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Tooltips
		 * ---------------------------------------------------------------------------------------------
		 */

		pin(target, originalEvent) {
			this.showTooltip(target, originalEvent);
			this.pinned = true;
			this.tooltip.classList.add('ntp-tooltip-pinned');
		}

		unpin() {
			this.pinned = false;
			this.tooltip.classList.remove('ntp-tooltip-pinned');
			this.hideTooltip();
		}

		hideTooltip() {
			this.tooltip.hidden = true;
		}

		showTooltip(target, originalEvent) {
			const html = target.isNode() ? this.nodeTooltip(target.data()) : this.edgeTooltip(target.data());

			this.tooltip.innerHTML = html;
			this.tooltip.hidden = false;
			this.positionTooltip(originalEvent);
		}

		positionTooltip(originalEvent) {
			if (!originalEvent || this.tooltip.hidden) {
				return;
			}

			const rect = this.root.getBoundingClientRect();
			const tip = this.tooltip.getBoundingClientRect();
			let x = originalEvent.clientX - rect.left + 16;
			let y = originalEvent.clientY - rect.top + 16;

			if (x + tip.width > rect.width - 8) {
				x = Math.max(8, originalEvent.clientX - rect.left - tip.width - 16);
			}

			if (y + tip.height > rect.height - 8) {
				y = Math.max(8, originalEvent.clientY - rect.top - tip.height - 16);
			}

			this.tooltip.style.left = x + 'px';
			this.tooltip.style.top = y + 'px';
		}

		problemRows(problems) {
			return problems.map((p) => '<li><span class="ntp-sev" style="background:' + esc(this.sevColor(p.severity))
				+ '"></span>' + esc(p.name) + (p.clock ? ' <span class="ntp-muted">' + esc(fmtAge(p.clock)) + '</span>' : '')
				+ '</li>'
			).join('');
		}

		row(label, value) {
			return value === '' || value === null || value === undefined
				? ''
				: '<tr><th>' + esc(this.t(label)) + '</th><td>' + esc(value) + '</td></tr>';
		}

		nodeTooltip(d) {
			const t = (s) => this.t(s);

			if (d.kind === 'ext') {
				const seen = (d.seen_by || []).map((s) => s.name + (s.port ? ' (' + s.port + ')' : '')).join(', ');

				return '<div class="ntp-tip-head" style="border-color:' + esc(this.palette.ext) + '">' + esc(d.name)
					+ '<span class="ntp-tip-kind">' + esc(t('not a Zabbix host')) + ' · '
					+ esc((d.protocols || []).join(', ').toUpperCase()) + '</span></div>'
					+ '<table>'
					+ this.row('Chassis ID', d.chassis) + this.row('Management IP', d.ip)
					+ this.row('Description', d.desc) + this.row('Capabilities', d.caps) + this.row('Seen by', seen)
					+ '</table>';
			}

			const status = [];

			if (d.maintenance) {
				status.push(t('In maintenance'));
			}

			if (d.unavailable) {
				status.push(t('Unavailable'));
			}

			if (!d.in_scope) {
				status.push(t('Host outside the selection'));
			}

			let html = '<div class="ntp-tip-head" style="border-color:' + esc(d.severity >= 0 ? this.sevColor(d.severity)
				: this.palette.ok) + '">' + esc(d.name);

			if (d.host !== d.name || d.ip) {
				html += '<span class="ntp-tip-kind">' + esc([d.host !== d.name ? d.host : '', d.ip].filter(Boolean)
					.join(' · ')) + '</span>';
			}

			html += '</div>';

			if (status.length) {
				html += '<div class="ntp-tip-flags">' + esc(status.join(' · ')) + '</div>';
			}

			html += '<div class="ntp-tip-section">' + esc(t('Problems')) + (d.problem_count ? ' (' + d.problem_count + ')'
				: '') + '</div>';

			html += d.problem_count
				? '<ul class="ntp-problems">' + this.problemRows(d.problems || []) + '</ul>'
				: '<div class="ntp-muted">' + esc(t('No problems')) + '</div>';

			html += '<div class="ntp-tip-hint">' + esc(t('Click: host menu. Double-click: highlight neighbors.'))
				+ '</div>';

			return html;
		}

		edgeTooltip(d) {
			const t = (s) => this.t(s);
			const src = this.nodeIndex[d.source] || {name: d.source};
			const tgt = this.nodeIndex[d.target] || {name: d.target};
			const origins = {lldp: 'LLDP', cdp: 'CDP', tag: t('Host tag'), map: t('Map link'), dep: t('Dependency')};
			const statusText = d.lost
				? t('Lost (neighbor no longer seen)')
				: ({up: t('Up'), down: t('Down'), unknown: t('No port status'), logical: t('Dependency (logical)')}[d.status]
					|| d.status);
			const operName = (v) => v === null || v === undefined ? '' : ({1: 'up', 2: 'down', 3: 'testing', 4: 'unknown',
				5: 'dormant', 6: 'notPresent', 7: 'lowerLayerDown'}[v] || String(v));

			let html = '<div class="ntp-tip-head" style="border-color:' + esc(d.color) + '">' + esc(src.name) + ' ↔ '
				+ esc(tgt.name) + '<span class="ntp-tip-kind">' + esc(statusText) + '</span></div><table>';

			if (d.source_port || d.target_port) {
				html += this.row('Local port', d.source_port ? src.name + ': ' + d.source_port
					+ (d.source_oper ? ' [' + operName(d.source_oper) + ']' : '') : '');
				html += this.row('Remote port', d.target_port ? tgt.name + ': ' + d.target_port
					+ (d.target_oper ? ' [' + operName(d.target_oper) + ']' : '') : '');
			}

			html += this.row('Source', (d.protocols || [d.origin]).map((o) => origins[o] || o).join(' + ')
				+ (d.map ? ' (' + d.map + ')' : ''));

			if (d.lost && d.ts_delete) {
				// LLD keeps the lost neighbor until this time, then the link disappears from the map.
				html += this.row('Kept until', this.fmtDate(d.ts_delete));
			}

			const cap = this.capacityEdges[d.id];

			if (cap && cap.load !== null) {
				html += this.row('Utilization (p95)', Math.round(cap.load) + '%' + (cap.kind === 'now' ? ' (' + t('now') + ')' : ''));
			}

			if (d.traffic) {
				html += this.row('In', fmtBps(d.traffic.in)) + this.row('Out', fmtBps(d.traffic.out))
					+ this.row('Speed', d.traffic.speed ? fmtBps(d.traffic.speed) : '')
					+ this.row('Utilization', d.traffic.util !== null && d.traffic.util !== undefined
						? d.traffic.util + '%' : '');
			}

			html += '</table>';

			// Chain map: the circuits that depend on this link (links to their capacity planning page).
			if (cap && cap.count) {
				const shown = cap.circuits.slice(0, 12).map((cid) => '<a href="' + esc(this.config.capacity_url + '&circuit='
					+ encodeURIComponent(cid)) + '">' + esc(cid) + '</a>');

				html += '<div class="ntp-tip-section">' + esc(t('Circuits on this link')) + ': ' + cap.count + '</div>'
					+ '<div class="ntp-tip-circuits">' + shown.join(', ')
					+ (cap.count > shown.length ? ' (+' + (cap.count - shown.length) + ')' : '') + '</div>';
			}

			if (d.problems && d.problems.length) {
				html += '<div class="ntp-tip-section">' + esc(t('Problems')) + '</div>'
					+ '<ul class="ntp-problems">' + this.problemRows(d.problems) + '</ul>';
			}

			return html;
		}
	}

	window.NetTopo = {
		app: null,

		init(config) {
			if (typeof cytoscape === 'undefined') {
				const root = document.getElementById('nettopo');

				if (root) {
					root.textContent = 'Network Topology: graph library not loaded (assets/lib/cytoscape.min.js).';
				}

				return;
			}

			this.app = new NetTopoApp(config);
		}
	};
})();
