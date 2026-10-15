// Network Topology (nettopo) - LLDP neighbors from SNMP walk[] output.
// Zabbix JavaScript preprocessing (Duktape, ES5 only). Input: walk[] text. Output: JSON array.
// Fields: k=stable key, lpn=LLDP local port num, lp=local port name, li=local ifIndex,
// lpid=advertised local port id, rn=remote sysName, rc=remote chassis id, rp=remote port id,
// rpd=remote port description, ra=remote management IPs, rd=remote sysDescr, cap=remote capabilities.
var MAX_JSON = 60000;

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
		else if (type === 'Hex-STRING' || type === 'BITS') {
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
			val = text.substring(i, e).replace(/\s+/g, ' ').replace(/^ +| +$/g, '');
			i = e + 1;
		}
		else {
			nl = text.indexOf('\n', i);
			e = nl < 0 ? n : nl;
			val = text.substring(i, e).replace(/\s+$/, '');
			i = e + 1;
		}

		out[oid] = {t: type, v: val};
	}

	return out;
}

function hexBytes(v) {
	var parts = v.split(' '), bytes = [], k;

	for (k = 0; k < parts.length; k++) {
		if (/^[0-9A-Fa-f]{2}$/.test(parts[k])) {
			bytes.push(parseInt(parts[k], 16));
		}
		else if (parts[k] !== '') {
			break;
		}
	}

	return bytes;
}

function hex2(b) {
	return (b < 16 ? '0' : '') + b.toString(16);
}

function bytesToMac(bytes) {
	var r = [], k;

	for (k = 0; k < bytes.length; k++) {
		r.push(hex2(bytes[k]));
	}

	return r.join(':');
}

function bytesToText(bytes) {
	var r = '', k, b, b2, b3, cp;

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
			cp = ((b & 0x0F) << 12) | ((b2 & 0x3F) << 6) | (b3 & 0x3F);
			r += String.fromCharCode(cp);
		}
		else {
			r += '?';
		}
	}

	return r;
}

function isPrintable(bytes) {
	var k;

	if (bytes.length === 0) {
		return false;
	}
	for (k = 0; k < bytes.length; k++) {
		if ((bytes[k] < 32 && bytes[k] !== 9) || bytes[k] === 127) {
			if (bytes[k] === 0 && k === bytes.length - 1) {
				continue;
			}
			return false;
		}
	}

	return true;
}

// Text of a string-like value (STRING or Hex-STRING).
function asText(cell) {
	if (!cell) {
		return '';
	}
	if (cell.t === 'Hex-STRING') {
		return bytesToText(hexBytes(cell.v)).replace(/^\s+|\s+$/g, '');
	}

	return String(cell.v).replace(/^\s+|\s+$/g, '');
}

function asInt(cell) {
	var m;

	if (!cell) {
		return null;
	}
	m = /\((-?\d+)\)\s*$/.exec(cell.v) || /^(-?\d+)/.exec(cell.v);

	return m ? parseInt(m[1], 10) : null;
}

// LLDP chassis/port identifier formatted according to its subtype.
// macSubtype: subtype number meaning "MAC address"; netSubtype: subtype meaning "network address".
function formatId(cell, subtype, macSubtype, netSubtype) {
	var bytes, text;

	if (!cell) {
		return '';
	}

	if (cell.t === 'Hex-STRING') {
		bytes = hexBytes(cell.v);

		if (subtype === macSubtype || (bytes.length === 6 && !isPrintable(bytes))) {
			return bytesToMac(bytes);
		}
		if (subtype === netSubtype && bytes.length === 5 && bytes[0] === 1) {
			return bytes[1] + '.' + bytes[2] + '.' + bytes[3] + '.' + bytes[4];
		}
		if (isPrintable(bytes)) {
			return bytesToText(bytes).replace(/^\s+|\s+$/g, '');
		}

		return bytesToMac(bytes);
	}

	text = String(cell.v).replace(/^\s+|\s+$/g, '');

	if (subtype === macSubtype && text.length === 6) {
		bytes = [];
		for (var k = 0; k < 6; k++) {
			bytes.push(text.charCodeAt(k) & 0xFF);
		}

		return bytesToMac(bytes);
	}

	return text;
}

// LLDP system capabilities (BITS, MSB first): other, repeater, bridge, wlanAccessPoint, router,
// telephone, docsisCableDevice, stationOnly.
function formatCaps(cell) {
	var names = ['other', 'repeater', 'bridge', 'wlan-ap', 'router', 'telephone', 'docsis', 'station'],
		bytes, caps = [], bit;

	if (!cell) {
		return '';
	}

	bytes = hexBytes(cell.v);
	for (bit = 0; bit < names.length; bit++) {
		if (bytes.length > (bit >> 3) && (bytes[bit >> 3] & (0x80 >> (bit & 7)))) {
			caps.push(names[bit]);
		}
	}

	return caps.join(',');
}

function sanitizeKey(s) {
	return String(s).replace(/[^A-Za-z0-9._:\/\-]/g, '_').substring(0, 160);
}

function truncate(s, len) {
	s = String(s).replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ');

	return s.length > len ? s.substring(0, len - 1) + '…' : s;
}

function numCmp(a, b) {
	var pa = String(a).split('.'), pb = String(b).split('.'), k, x, y;

	for (k = 0; k < Math.max(pa.length, pb.length); k++) {
		x = parseInt(pa[k] || '0', 10);
		y = parseInt(pb[k] || '0', 10);
		if (x !== y) {
			return x - y;
		}
	}

	return 0;
}

var W = parseWalk(value);

var P_LOC = '1.0.8802.1.1.2.1.3.7.1.',
	P_REM = '1.0.8802.1.1.2.1.4.1.1.',
	P_MAN = '1.0.8802.1.1.2.1.4.2.1.',
	P_IFNAME = '1.3.6.1.2.1.31.1.1.1.1.',
	P_IFDESCR = '1.3.6.1.2.1.2.2.1.2.';

var loc = {}, rem = {}, man = {}, ifName = {}, ifDescr = {}, byName = {}, oid, rest, dot, col, idx, parts;

for (oid in W) {
	if (!W.hasOwnProperty(oid)) {
		continue;
	}

	if (oid.indexOf(P_REM) === 0) {
		rest = oid.substring(P_REM.length);
		dot = rest.indexOf('.');
		col = rest.substring(0, dot);
		idx = rest.substring(dot + 1);
		if (!rem[idx]) {
			rem[idx] = {};
		}
		rem[idx][col] = W[oid];
	}
	else if (oid.indexOf(P_LOC) === 0) {
		rest = oid.substring(P_LOC.length);
		dot = rest.indexOf('.');
		col = rest.substring(0, dot);
		idx = rest.substring(dot + 1);
		if (!loc[idx]) {
			loc[idx] = {};
		}
		loc[idx][col] = W[oid];
	}
	else if (oid.indexOf(P_MAN) === 0) {
		// index: timeMark.localPortNum.remIndex.addrSubtype.addrLen.addr...
		parts = oid.substring(P_MAN.length).split('.');
		if (parts.length >= 6) {
			idx = parts[1] + '.' + parts[2] + '.' + parts[3];
			var subtype = parseInt(parts[4], 10), alen = parseInt(parts[5], 10), addr = parts.slice(6), ip = '';

			if (subtype === 1 && alen === 4 && addr.length >= 4) {
				ip = addr.slice(0, 4).join('.');
			}
			else if (subtype === 2 && alen === 16 && addr.length >= 16) {
				var groups = [];
				for (var g = 0; g < 16; g += 2) {
					groups.push(((parseInt(addr[g], 10) << 8) | parseInt(addr[g + 1], 10)).toString(16));
				}
				ip = groups.join(':').replace(/(^|:)0(:0)+(:|$)/, '::');
			}

			if (ip !== '') {
				if (!man[idx]) {
					man[idx] = [];
				}
				if (man[idx].indexOf(ip) === -1) {
					man[idx].push(ip);
				}
			}
		}
	}
	else if (oid.indexOf(P_IFNAME) === 0) {
		idx = oid.substring(P_IFNAME.length);
		ifName[idx] = asText(W[oid]);
		if (ifName[idx] !== '' && byName[ifName[idx].toLowerCase()] === undefined) {
			byName[ifName[idx].toLowerCase()] = idx;
		}
	}
	else if (oid.indexOf(P_IFDESCR) === 0) {
		idx = oid.substring(P_IFDESCR.length);
		ifDescr[idx] = asText(W[oid]);
	}
}

for (idx in ifDescr) {
	if (ifDescr.hasOwnProperty(idx) && ifDescr[idx] !== '' && byName[ifDescr[idx].toLowerCase()] === undefined) {
		byName[ifDescr[idx].toLowerCase()] = idx;
	}
}

// Resolve the local LLDP port number to an interface (ifIndex + ifName).
function resolveLocal(lpn) {
	var l = loc[lpn] || {},
		portId = formatId(l['3'], asInt(l['2']), 3, 4),
		portDesc = asText(l['4']),
		ifindex = null,
		candidates = [portId, portDesc],
		k;

	for (k = 0; k < candidates.length && ifindex === null; k++) {
		if (candidates[k] !== '' && byName[candidates[k].toLowerCase()] !== undefined) {
			ifindex = byName[candidates[k].toLowerCase()];
		}
	}

	if (ifindex === null && /^\d+$/.test(portId) && ifName[portId] !== undefined) {
		ifindex = portId;
	}

	if (ifindex === null && (ifName[lpn] !== undefined || ifDescr[lpn] !== undefined)) {
		ifindex = lpn;
	}

	var name = '';
	if (ifindex !== null) {
		name = ifName[ifindex] || ifDescr[ifindex] || '';
	}
	if (name === '') {
		name = (portId !== '' && !/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/.test(portId)) ? portId : (portDesc || portId || lpn);
	}

	return {name: name, ifindex: ifindex, id: portId};
}

var list = [], keys = [];

for (idx in rem) {
	if (rem.hasOwnProperty(idx)) {
		keys.push(idx);
	}
}

keys.sort(function(a, b) {
	var pa = a.split('.'), pb = b.split('.');

	return numCmp(pa.slice(1).join('.'), pb.slice(1).join('.'));
});

var seen = {};

for (var r = 0; r < keys.length; r++) {
	idx = keys[r];
	var e = rem[idx], ip = idx.split('.'), lpn = ip.length >= 3 ? ip[1] : '', local = resolveLocal(lpn);
	var chassis = formatId(e['5'], asInt(e['4']), 4, 5),
		port = formatId(e['7'], asInt(e['6']), 3, 4),
		name = asText(e['9']),
		nb = {};

	if (chassis === '' && name === '' && port === '') {
		continue;
	}

	nb.k = sanitizeKey(lpn + '_' + (chassis || name) + '_' + port);
	if (seen[nb.k]) {
		continue;
	}
	seen[nb.k] = true;

	nb.lpn = lpn;
	nb.lp = local.name;
	if (local.ifindex !== null) {
		nb.li = local.ifindex;
	}
	if (local.id !== '' && local.id !== local.name) {
		nb.lpid = local.id;
	}
	if (name !== '') {
		nb.rn = truncate(name, 128);
	}
	if (chassis !== '') {
		nb.rc = chassis;
		nb.rct = asInt(e['4']);
	}
	if (port !== '') {
		nb.rp = truncate(port, 128);
		nb.rpt = asInt(e['6']);
	}

	var pdesc = asText(e['8']);
	if (pdesc !== '' && pdesc !== port) {
		nb.rpd = truncate(pdesc, 80);
	}
	if (man[idx]) {
		nb.ra = man[idx];
	}

	var sdesc = asText(e['10']);
	if (sdesc !== '') {
		nb.rd = truncate(sdesc, 100);
	}

	var caps = formatCaps(e['12'] || e['11']);
	if (caps !== '') {
		nb.cap = caps;
	}

	list.push(nb);
}

var json = JSON.stringify(list);

if (json.length > MAX_JSON) {
	for (var q = 0; q < list.length; q++) {
		delete list[q].rd;
	}
	json = JSON.stringify(list);
}

if (json.length > MAX_JSON) {
	for (var q2 = 0; q2 < list.length; q2++) {
		delete list[q2].rpd;
		delete list[q2].lpid;
	}
	json = JSON.stringify(list);
}

while (json.length > MAX_JSON && list.length > 0) {
	list.pop();
	json = JSON.stringify(list);
}

return json;
