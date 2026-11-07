// Capacity Planning - discovery of the interfaces that belong to circuits.
// Zabbix JavaScript preprocessing (Duktape, ES5 only).
// Input: walk[] text with IF-MIB ifName, ifAlias, ifHighSpeed, ifDescr and SNMPv2-MIB sysName.
// Output: one LLD row per interface whose description (ifAlias) has
//   - a circuit ID: "CID:NET-TO-0001" (several IDs: "CID:NET-TO-0001,NET-TO-0002"). The word before the
//     separator is configurable and may be a list ({$CAPACITY.CID.PREFIX}=CID,EVC_ID reads "EVC_ID#40137877"), or
//   - a shared-link word: UPLINK, TRONCO, TRUNK (configurable), or a whole field of a "|"-separated description
//     ({$CAPACITY.SHARED.FIELDS}=CORE matches "name|CORE|100G|...").
// The capacity written in a "|"-separated description ("name|BORDER|200M|EVC_ID#1") is returned in {#BW_DESC}
// (Mbps, 0 = none) and used as the capacity of the interface unless a {$CAPACITY.BW.MBPS} contract exists.
// Role of the device ({#SEGMENT}): {$CAPACITY.SEGMENT} when a profile template or the host sets it; otherwise it is
// found from the data: an interface with a whole field of {$CAPACITY.ROLE.BACKBONE.FIELDS} ("|CORE|") = backbone,
// {$CAPACITY.ROLE.DIST.MIN} or more circuits = distribution, fewer = CPE. {#UTIL_WARN}/{#UTIL_CRIT} are the limits
// of that role ({$CAPACITY.ROLE.WARN:"<role>"}, {$CAPACITY.ROLE.CRIT:"<role>"}).
// Point-to-point interfaces: on the roles in {$CAPACITY.P2P.ROLES} (default backbone), interfaces with a /30 or /31
// IPv4 address and no circuit are discovered too ({#LINK} = p2p): they are the core end of the links to the
// distribution devices, found by the module through the addresses (no LLDP/CDP needed).
// Optional: when {$CAPACITY.NETBOX.URL} is set, interfaces without a CID in the description take it
// from NetBox (custom field {$CAPACITY.NETBOX.FIELD} of the interface, or its description).
// Optional: the host macro {$CIRCUIT_ID} gives the circuit of the whole device (usually a CPE); it goes to
// the interfaces named in {$CIRCUIT_ID.IF} (names separated by commas, * matches anything: "ether1",
// "GigabitEthernet0/0/0", "sfp-sfpplus*").
//
// Zabbix replaces the user macros below before running the script.
var SEGMENT = cfg('{$CAPACITY.SEGMENT}', '');
var CID_PREFIX = cfg('{$CAPACITY.CID.PREFIX}', 'CID,EVC_ID,EID');
var SHARED_WORDS = cfg('{$CAPACITY.SHARED.WORDS}', 'UPLINK,TRONCO,TRUNK');
var SHARED_FIELDS = cfg('{$CAPACITY.SHARED.FIELDS}', 'CORE');
var SHARED_EXCLUDE = cfg('{$CAPACITY.SHARED.EXCLUDE}', '');
var BW_FROM_DESC = cfg('{$CAPACITY.BW.FROM.DESC}', '1') !== '0';
var NETBOX_URL = cfg('{$CAPACITY.NETBOX.URL}', '');
var NETBOX_TOKEN = cfg('{$CAPACITY.NETBOX.TOKEN}', '');
var NETBOX_FIELD = cfg('{$CAPACITY.NETBOX.FIELD}', 'circuit_id');
var NETBOX_DEVICE = cfg('{$CAPACITY.NETBOX.DEVICE}', '');
var HOST_CID = cfg('{$CIRCUIT_ID}', '');
var HOST_CID_IF = cfg('{$CIRCUIT_ID.IF}', '');
var ROLE_BACKBONE_FIELDS = cfg('{$CAPACITY.ROLE.BACKBONE.FIELDS}', 'CORE');
var ROLE_DIST_MIN = parseInt(cfg('{$CAPACITY.ROLE.DIST.MIN}', '2'), 10);
var P2P_ROLES = cfg('{$CAPACITY.P2P.ROLES}', 'backbone');
var P2P_PREFIX = parseInt(cfg('{$CAPACITY.P2P.PREFIX}', '30'), 10);
// Utilization limits by role (%): attention and critical.
var ROLE_LIMITS = {
	backbone: [cfg('{$CAPACITY.ROLE.WARN:"backbone"}', '60'), cfg('{$CAPACITY.ROLE.CRIT:"backbone"}', '80')],
	distribuicao: [cfg('{$CAPACITY.ROLE.WARN:"distribuicao"}', '70'), cfg('{$CAPACITY.ROLE.CRIT:"distribuicao"}', '85')],
	cpe: [cfg('{$CAPACITY.ROLE.WARN:"cpe"}', '80'), cfg('{$CAPACITY.ROLE.CRIT:"cpe"}', '95')],
	indefinido: [cfg('{$CAPACITY.ROLE.WARN}', '70'), cfg('{$CAPACITY.ROLE.CRIT}', '90')]
};

var OID_IFNAME = '1.3.6.1.2.1.31.1.1.1.1.';
var OID_IFALIAS = '1.3.6.1.2.1.31.1.1.1.18.';
var OID_IFHIGHSPEED = '1.3.6.1.2.1.31.1.1.1.15.';
var OID_IFDESCR = '1.3.6.1.2.1.2.2.1.2.';
var OID_SYSNAME = '1.3.6.1.2.1.1.5.0';
var OID_IPIFINDEX = '1.3.6.1.2.1.4.20.1.2.';
var OID_IPMASK = '1.3.6.1.2.1.4.20.1.3.';

var SEGMENT_LABELS = {backbone: 'Backbone', distribuicao: 'Distribuição', cpe: 'CPE', indefinido: 'Indefinido'};

var ID = '[A-Za-z0-9][A-Za-z0-9._/-]*';
// After the first ID, "," or ";" adds another ID only when that word is not the key of the next field:
// "EID=9002;PEER_IP=10.0.0.63" is one circuit, "CID:NET-TO-0001,NET-TO-0002" is two.
var CID_RE = new RegExp('(?:^|[^A-Za-z0-9])(?:' + prefixAlternatives(CID_PREFIX) + ')\\s*[:=#]\\s*(' + ID +
	'(?:\\s*[,;]\\s*(?![A-Za-z0-9._/-]+\\s*[:=#])' + ID + ')*)', 'i');
var SHARED_RE = wordsRe(SHARED_WORDS);
var SHARED_FIELD_LIST = fieldList(SHARED_FIELDS);
// Links left out of capacity planning (temporary, backup...): words or "|" fields of the description.
var SHARED_EXCLUDE_RE = wordsRe(SHARED_EXCLUDE);
var HOST_CIDS = splitIds(HOST_CID);
var HOST_CID_RE = HOST_CIDS.length ? namesRe(HOST_CID_IF) : null;
var ROLE_BACKBONE_LIST = fieldList(ROLE_BACKBONE_FIELDS);

if (isNaN(ROLE_DIST_MIN) || ROLE_DIST_MIN < 1) {
	ROLE_DIST_MIN = 2;
}
if (isNaN(P2P_PREFIX) || P2P_PREFIX < 24 || P2P_PREFIX > 31) {
	P2P_PREFIX = 30;
}

function cfg(value, fallback) {
	var v = String(value).replace(/^\s+|\s+$/g, '');

	// A macro that is not defined anywhere reaches the script unexpanded ("{$...}").
	return (v === '' || v.indexOf('{$') === 0) ? fallback : v;
}

function escapeRe(s) {
	return s.replace(/[\\^$.*+?()[\]{}|\/-]/g, '\\$&');
}

// "CID, EVC_ID" -> "CID|EVC_ID" (escaped); falls back to CID when the list is empty.
function prefixAlternatives(list) {
	var words = [], parts = list.split(/[,;]/), k, w;

	for (k = 0; k < parts.length; k++) {
		w = parts[k].replace(/^\s+|\s+$/g, '');
		if (w !== '') {
			words.push(escapeRe(w));
		}
	}

	return words.length ? words.join('|') : 'CID';
}

// "CORE, TRUNK" -> ['CORE', 'TRUNK'] (upper case).
function fieldList(list) {
	var out = [], parts = list.split(/[,;]/), k, w;

	for (k = 0; k < parts.length; k++) {
		w = parts[k].replace(/^\s+|\s+$/g, '').toUpperCase();
		if (w !== '') {
			out.push(w);
		}
	}

	return out;
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
	if (isExcluded(description)) {
		return false;
	}

	if (SHARED_RE !== null && SHARED_RE.test(description || '')) {
		return true;
	}

	return hasField(description, SHARED_FIELD_LIST);
}

function isExcluded(description) {
	return SHARED_EXCLUDE_RE !== null && SHARED_EXCLUDE_RE.test(description || '');
}

// Whole "|"-separated fields only: "name|CORE|100G" has CORE, "CORE-RJ-01_CORE-SP-01" has not.
function hasField(description, list) {
	var fields, k, f, w;

	if (!list.length) {
		return false;
	}

	fields = String(description || '').split('|');

	for (k = 0; k < fields.length; k++) {
		f = fields[k].replace(/^\s+|\s+$/g, '').toUpperCase();
		for (w = 0; w < list.length; w++) {
			if (f === list[w]) {
				return true;
			}
		}
	}

	return false;
}

// "255.255.255.254" -> 31; -1 when not a contiguous mask.
function prefixLength(mask) {
	var parts = String(mask).split('.'), bits = '', k, b;

	if (parts.length !== 4) {
		return -1;
	}

	for (k = 0; k < 4; k++) {
		b = parseInt(parts[k], 10);
		if (isNaN(b) || b < 0 || b > 255) {
			return -1;
		}
		bits += ('00000000' + b.toString(2)).slice(-8);
	}

	if (!/^1*0*$/.test(bits)) {
		return -1;
	}

	return bits.indexOf('0') < 0 ? 32 : bits.indexOf('0');
}

// ifIndex => true for the interfaces with a point-to-point IPv4 address (/P2P_PREFIX to /31).
function pointToPoint(walk) {
	var out = {}, oid, ip, prefix, first;

	for (oid in walk) {
		if (!walk.hasOwnProperty(oid) || oid.indexOf(OID_IPIFINDEX) !== 0) {
			continue;
		}

		ip = oid.substring(OID_IPIFINDEX.length);
		first = parseInt(ip.split('.')[0], 10);

		if (first === 127 || first === 0) {
			continue;
		}

		prefix = prefixLength(walk[OID_IPMASK + ip] || '');

		if (prefix >= P2P_PREFIX && prefix <= 31) {
			out[String(walk[oid]).replace(/[^0-9]/g, '')] = true;
		}
	}

	return out;
}

// Capacity written as a whole "|"-separated field ("100M", "1G", "10 Gbps", "2,5G", or with a key: "B:1G",
// "BW=100M"): Mbps, 0 = none.
function bandwidthFrom(description) {
	var fields, k, m, unit;

	if (!BW_FROM_DESC) {
		return 0;
	}

	fields = String(description || '').split('|');

	for (k = 0; k < fields.length; k++) {
		m = /^\s*(?:(?:B|BW|BANDA|BANDWIDTH)\s*[:=]\s*)?([0-9]+(?:[.,][0-9]+)?)\s*([MGT])(?:bps|bit|b)?\s*$/i.exec(fields[k]);
		if (m) {
			unit = m[2].toUpperCase();

			return Math.round(parseFloat(m[1].replace(',', '.')) * (unit === 'T' ? 1000000 : (unit === 'G' ? 1000 : 1)) * 1000) / 1000;
		}
	}

	return 0;
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
// Role set by a profile template or by the host; 'indefinido' = find it from the data (below).
var segment = normalizeSegment(SEGMENT);
var p2p = pointToPoint(walk);
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

var rows = [], candidates = [], circuits = {}, backbone_field = false, c;

function makeRow(idx, name, alias, cids, link, source) {
	var speed = parseInt(walk[OID_IFHIGHSPEED + idx] || '0', 10);

	return {
		'{#SNMPINDEX}': idx,
		'{#IFNAME}': name,
		'{#IFALIAS}': alias,
		'{#IFSPEED}': String(isNaN(speed) ? 0 : speed),
		// Capacity written in the description (Mbps, 0 = none): see bandwidthFrom().
		'{#BW_DESC}': String(bandwidthFrom(alias)),
		'{#CIRCUIT_ID}': cids.length ? cids[0] : '',
		// Context of {$CAPACITY.BW.MBPS}: the (first) circuit ID, or the interface name for links.
		'{#BW_KEY}': cids.length ? cids[0] : name,
		'{#CIRCUITS}': cids.join(','),
		'{#CIRCUIT_LABEL}': cids.length ? cids.join(',') : (link === 'p2p' ? 'ponto a ponto' : 'compartilhado'),
		'{#LINK}': link,
		'{#CID_SOURCE}': source
	};
}

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

	if (hasField(alias, ROLE_BACKBONE_LIST)) {
		backbone_field = true;
	}

	if (!cids.length && !shared) {
		// Not a circuit: kept aside in case the device turns out to be a role with point-to-point discovery.
		if (p2p[idx] && !isExcluded(alias)) {
			candidates.push(makeRow(idx, name, alias, [], 'p2p', ''));
		}
		continue;
	}

	for (c = 0; c < cids.length; c++) {
		circuits[cids[c]] = true;
	}

	rows.push(makeRow(idx, name, alias, cids, shared ? 'compartilhado' : 'dedicado', source));
}

// Role from the data when no profile template or host macro sets it: the device that has core trunks is
// backbone; the one that gathers several circuits is distribution; the one with fewer is the CPE.
if (segment === 'indefinido') {
	var count = 0;

	for (c in circuits) {
		if (circuits.hasOwnProperty(c)) {
			count++;
		}
	}

	segment = backbone_field ? 'backbone' : (count >= ROLE_DIST_MIN ? 'distribuicao' : (count > 0 ? 'cpe' : 'indefinido'));
}

if (candidates.length && hasField(segment, fieldList(P2P_ROLES))) {
	rows = rows.concat(candidates);
}

for (k = 0; k < rows.length; k++) {
	rows[k]['{#SEGMENT}'] = segment;
	rows[k]['{#SEGMENT_LABEL}'] = SEGMENT_LABELS[segment];
	rows[k]['{#UTIL_WARN}'] = ROLE_LIMITS[segment][0];
	rows[k]['{#UTIL_CRIT}'] = ROLE_LIMITS[segment][1];
}

rows.sort(function(a, b) {
	return parseInt(a['{#SNMPINDEX}'], 10) - parseInt(b['{#SNMPINDEX}'], 10);
});

return JSON.stringify(rows);
