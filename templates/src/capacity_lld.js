// Capacity Planning - discovery of the interfaces that belong to circuits.
// Zabbix JavaScript preprocessing (Duktape, ES5 only).
// Input: walk[] text with IF-MIB ifName, ifAlias, ifHighSpeed, ifDescr and SNMPv2-MIB sysName.
// Output: one LLD row per interface whose description (ifAlias) has
//   - a circuit ID: "CID:NET-TO-0001" (several IDs: "CID:NET-TO-0001,NET-TO-0002"), or
//   - a shared-link word: UPLINK, TRONCO, TRUNK (configurable).
// Optional: when {$CAPACITY.NETBOX.URL} is set, interfaces without a CID in the description take it
// from NetBox (custom field {$CAPACITY.NETBOX.FIELD} of the interface, or its description).
// Optional: the host macro {$CIRCUIT_ID} gives the circuit of the whole device (usually a CPE); it goes to
// the interfaces named in {$CIRCUIT_ID.IF} (names separated by commas, * matches anything: "ether1",
// "GigabitEthernet0/0/0", "sfp-sfpplus*").
//
// Zabbix replaces the user macros below before running the script.
var SEGMENT = cfg('{$CAPACITY.SEGMENT}', '');
var CID_PREFIX = cfg('{$CAPACITY.CID.PREFIX}', 'CID');
var SHARED_WORDS = cfg('{$CAPACITY.SHARED.WORDS}', 'UPLINK,TRONCO,TRUNK');
var NETBOX_URL = cfg('{$CAPACITY.NETBOX.URL}', '');
var NETBOX_TOKEN = cfg('{$CAPACITY.NETBOX.TOKEN}', '');
var NETBOX_FIELD = cfg('{$CAPACITY.NETBOX.FIELD}', 'circuit_id');
var NETBOX_DEVICE = cfg('{$CAPACITY.NETBOX.DEVICE}', '');
var HOST_CID = cfg('{$CIRCUIT_ID}', '');
var HOST_CID_IF = cfg('{$CIRCUIT_ID.IF}', '');

var OID_IFNAME = '1.3.6.1.2.1.31.1.1.1.1.';
var OID_IFALIAS = '1.3.6.1.2.1.31.1.1.1.18.';
var OID_IFHIGHSPEED = '1.3.6.1.2.1.31.1.1.1.15.';
var OID_IFDESCR = '1.3.6.1.2.1.2.2.1.2.';
var OID_SYSNAME = '1.3.6.1.2.1.1.5.0';

var SEGMENT_LABELS = {backbone: 'Backbone', distribuicao: 'Distribuição', cpe: 'CPE', indefinido: 'Indefinido'};

var ID = '[A-Za-z0-9][A-Za-z0-9._/-]*';
var CID_RE = new RegExp('(?:^|[^A-Za-z0-9])' + escapeRe(CID_PREFIX) + '\\s*[:=#]\\s*(' + ID + '(?:\\s*[,;]\\s*' + ID + ')*)', 'i');
var SHARED_RE = wordsRe(SHARED_WORDS);
var HOST_CIDS = splitIds(HOST_CID);
var HOST_CID_RE = HOST_CIDS.length ? namesRe(HOST_CID_IF) : null;

function cfg(value, fallback) {
	var v = String(value).replace(/^\s+|\s+$/g, '');

	// A macro that is not defined anywhere reaches the script unexpanded ("{$...}").
	return (v === '' || v.indexOf('{$') === 0) ? fallback : v;
}

function escapeRe(s) {
	return s.replace(/[\\^$.*+?()[\]{}|\/-]/g, '\\$&');
}

function wordsRe(list) {
	var words = [], parts = list.split(/[,;]/), k, w;

	for (k = 0; k < parts.length; k++) {
		w = parts[k].replace(/^\s+|\s+$/g, '');
		if (w !== '') {
			words.push(escapeRe(w));
		}
	}

	return words.length ? new RegExp('(?:^|[^A-Za-z0-9])(?:' + words.join('|') + ')(?:[^A-Za-z0-9]|$)', 'i') : null;
}

// "ether1, Gi0/0/*" -> /^(?:ether1|Gi0\/0\/.*)$/i ; null when the list is empty.
function namesRe(list) {
	var names = [], parts = list.split(/[,;]/), k, n;

	for (k = 0; k < parts.length; k++) {
		n = parts[k].replace(/^\s+|\s+$/g, '');
		if (n !== '') {
			names.push(escapeRe(n).replace(/\\\*/g, '.*').replace(/\\\?/g, '.'));
		}
	}

	return names.length ? new RegExp('^(?:' + names.join('|') + ')$', 'i') : null;
}

function splitIds(text) {
	var ids = [], seen = {}, parts = String(text).split(/[,;\s]+/), k, id;

	for (k = 0; k < parts.length; k++) {
		id = parts[k].replace(/[._\/-]+$/, '').toUpperCase();
		if (/^[A-Z0-9][A-Z0-9._\/-]*$/.test(id) && !seen[id]) {
			seen[id] = true;
			ids.push(id);
		}
	}

	return ids;
}

function cidsFrom(description) {
	var m = CID_RE.exec(description || '');

	return m ? splitIds(m[1]) : [];
}

function isShared(description) {
	return SHARED_RE !== null && SHARED_RE.test(description || '');
}

function normalizeSegment(s) {
	var v = s.toLowerCase()
		.replace(/[à-å]/g, 'a').replace(/ç/g, 'c').replace(/[è-ë]/g, 'e')
		.replace(/[ì-ï]/g, 'i').replace(/[ò-ö]/g, 'o').replace(/[ù-ü]/g, 'u');

	if (/^(backbone|core|nucleo)$/.test(v)) {
		return 'backbone';
	}
	if (/^(distribuicao|distribution|metro|agregacao|aggregation)$/.test(v)) {
		return 'distribuicao';
	}
	if (/^(cpe|acesso|access|cliente|customer)$/.test(v)) {
		return 'cpe';
	}

	return 'indefinido';
}

function unescapeQuoted(s) {
	return s.replace(/\\(.)/g, '$1');
}

function parseWalk(text) {
	var out = {}, i = 0, n = text.length, c, s, oid, m, type, val, e, nl;

	while (i < n) {
		c = text.charAt(i);
		if (c === '\n' || c === '\r' || c === ' ' || c === '\t') {
			i++;
			continue;
		}

		s = i;
		while (i < n && '0123456789.'.indexOf(text.charAt(i)) !== -1) {
			i++;
		}
		oid = text.substring(s, i);
		while (i < n && text.charAt(i) === ' ') {
			i++;
		}

		if (oid === '' || text.charAt(i) !== '=') {
			nl = text.indexOf('\n', i);
			i = nl < 0 ? n : nl + 1;
			continue;
		}

		if (oid.charAt(0) === '.') {
			oid = oid.substring(1);
		}

		i++;
		while (i < n && text.charAt(i) === ' ') {
			i++;
		}

		type = '';
		m = /^([A-Za-z][A-Za-z0-9\-]*):/.exec(text.substring(i, i + 40));
		if (m) {
			type = m[1];
			i += m[0].length;
			while (i < n && text.charAt(i) === ' ') {
				i++;
			}
		}

		if (text.charAt(i) === '"') {
			e = i + 1;
			while (e < n) {
				c = text.charAt(e);
				if (c === '\\') {
					e += 2;
					continue;
				}
				if (c === '"') {
					break;
				}
				e++;
			}
			val = unescapeQuoted(text.substring(i + 1, e));
			nl = text.indexOf('\n', e);
			i = nl < 0 ? n : nl + 1;
		}
		else if (type === 'Hex-STRING') {
			e = i;
			while (true) {
				nl = text.indexOf('\n', e);
				if (nl < 0) {
					e = n;
					break;
				}
				if (text.charAt(nl + 1) === '.' || /^[0-9]+(\.[0-9]+)+ *=/.test(text.substring(nl + 1, nl + 200))) {
					e = nl;
					break;
				}
				e = nl + 1;
			}
			val = hexToText(text.substring(i, e));
			i = e + 1;
		}
		else {
			nl = text.indexOf('\n', i);
			e = nl < 0 ? n : nl;
			val = text.substring(i, e).replace(/\s+$/, '');
			i = e + 1;
		}

		out[oid] = val;
	}

	return out;
}

// Descriptions with accents come as Hex-STRING (UTF-8 bytes).
function hexToText(v) {
	var parts = v.replace(/\s+/g, ' ').replace(/^ +| +$/g, '').split(' '), bytes = [], r = '', k, b, b2, b3;

	for (k = 0; k < parts.length; k++) {
		if (/^[0-9A-Fa-f]{2}$/.test(parts[k])) {
			bytes.push(parseInt(parts[k], 16));
		}
	}

	for (k = 0; k < bytes.length; k++) {
		b = bytes[k];
		if (b === 0) {
			continue;
		}
		if (b < 0x80) {
			r += String.fromCharCode(b);
		}
		else if ((b & 0xE0) === 0xC0 && k + 1 < bytes.length) {
			b2 = bytes[++k];
			r += String.fromCharCode(((b & 0x1F) << 6) | (b2 & 0x3F));
		}
		else if ((b & 0xF0) === 0xE0 && k + 2 < bytes.length) {
			b2 = bytes[++k];
			b3 = bytes[++k];
			r += String.fromCharCode(((b & 0x0F) << 12) | ((b2 & 0x3F) << 6) | (b3 & 0x3F));
		}
		else {
			r += String.fromCharCode(b);
		}
	}

	return r;
}

function netboxIds(value) {
	var ids = [], k, v;

	if (value === null || value === undefined || value === '') {
		return [];
	}
	if (Object.prototype.toString.call(value) === '[object Array]') {
		for (k = 0; k < value.length; k++) {
			ids = ids.concat(netboxIds(value[k]));
		}
		return splitIds(ids.join(','));
	}
	if (typeof value === 'object') {
		v = value.cid || value.display || value.name || '';
		return splitIds(String(v));
	}

	return splitIds(String(value));
}

// NetBox interfaces of the device: lowercase name => {cids: [...], shared: bool}.
function netboxInterfaces(device) {
	var result = {}, pages = 0, url, req, body, data, list, k, it, ids, tags, t;

	url = NETBOX_URL.replace(/\/+$/, '') + '/api/dcim/interfaces/?limit=1000&device=' + encodeURIComponent(device);

	while (url && pages < 50) {
		req = new HttpRequest();
		req.addHeader('Accept: application/json');
		if (NETBOX_TOKEN !== '') {
			// "Token xxx" (API token v1) unless a scheme is given, e.g. "Bearer nbt_...".
			req.addHeader('Authorization: ' + (NETBOX_TOKEN.indexOf(' ') > 0 ? NETBOX_TOKEN : 'Token ' + NETBOX_TOKEN));
		}

		body = req.get(url);

		if (req.getStatus() !== 200) {
			throw 'NetBox answered HTTP ' + req.getStatus() + ' for ' + url;
		}

		data = JSON.parse(body);
		list = data.results || [];

		for (k = 0; k < list.length; k++) {
			it = list[k];
			ids = netboxIds((it.custom_fields || {})[NETBOX_FIELD]);
			if (!ids.length) {
				ids = cidsFrom(it.description || '');
			}

			tags = [];
			for (t = 0; t < (it.tags || []).length; t++) {
				tags.push(it.tags[t].name || it.tags[t].slug || '');
			}

			result[String(it.name).toLowerCase()] = {
				cids: ids,
				shared: isShared(it.description || '') || isShared(tags.join(' '))
			};
		}

		url = data.next || '';
		pages++;
	}

	return result;
}

var walk = parseWalk(value);
var segment = normalizeSegment(SEGMENT);
var indexes = [], seen = {}, oid, idx, k;

for (oid in walk) {
	if (walk.hasOwnProperty(oid)) {
		if (oid.indexOf(OID_IFNAME) === 0) {
			idx = oid.substring(OID_IFNAME.length);
		}
		else if (oid.indexOf(OID_IFDESCR) === 0) {
			idx = oid.substring(OID_IFDESCR.length);
		}
		else {
			continue;
		}

		if (/^[0-9]+$/.test(idx) && !seen[idx]) {
			seen[idx] = true;
			indexes.push(idx);
		}
	}
}

indexes.sort(function(a, b) {
	return parseInt(a, 10) - parseInt(b, 10);
});

var netbox = null;

if (NETBOX_URL !== '') {
	netbox = netboxInterfaces(NETBOX_DEVICE !== '' ? NETBOX_DEVICE : (walk[OID_SYSNAME] || ''));
}

var rows = [];

for (k = 0; k < indexes.length; k++) {
	idx = indexes[k];

	var name = walk[OID_IFNAME + idx] || walk[OID_IFDESCR + idx] || ('if' + idx);
	var alias = walk[OID_IFALIAS + idx] || '';
	var descr = walk[OID_IFDESCR + idx] || '';
	var cids = cidsFrom(alias);
	var shared = isShared(alias);
	var source = cids.length ? 'descricao' : '';
	var nb = netbox !== null ? netbox[name.toLowerCase()] : null;

	if (nb) {
		if (!cids.length && nb.cids.length) {
			cids = nb.cids;
			source = 'netbox';
		}
		shared = shared || nb.shared;
	}

	// Circuit of the device (host macro): the chosen interfaces belong to that circuit only.
	if (!cids.length && HOST_CID_RE !== null && (HOST_CID_RE.test(name) || (descr !== '' && HOST_CID_RE.test(descr)))) {
		cids = HOST_CIDS;
		shared = false;
		source = 'macro';
	}

	if (!cids.length && !shared) {
		continue;
	}

	var speed = parseInt(walk[OID_IFHIGHSPEED + idx] || '0', 10);

	rows.push({
		'{#SNMPINDEX}': idx,
		'{#IFNAME}': name,
		'{#IFALIAS}': alias,
		'{#IFSPEED}': String(isNaN(speed) ? 0 : speed),
		'{#CIRCUIT_ID}': cids.length ? cids[0] : '',
		// Context of {$CAPACITY.BW.MBPS}: the (first) circuit ID, or the interface name for shared links.
		'{#BW_KEY}': cids.length ? cids[0] : name,
		'{#CIRCUITS}': cids.join(','),
		'{#CIRCUIT_LABEL}': cids.length ? cids.join(',') : 'compartilhado',
		'{#LINK}': shared ? 'compartilhado' : 'dedicado',
		'{#SEGMENT}': segment,
		'{#SEGMENT_LABEL}': SEGMENT_LABELS[segment],
		'{#CID_SOURCE}': source
	});
}

return JSON.stringify(rows);
