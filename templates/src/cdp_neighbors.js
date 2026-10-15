// Network Topology (nettopo) - CDP neighbors from SNMP walk[] output (CISCO-CDP-MIB cdpCacheTable).
// Zabbix JavaScript preprocessing (Duktape, ES5 only). Output: JSON array, same fields as the LLDP item.
var MAX_JSON = 60000;

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
			val = text.substring(i + 1, e).replace(/\\(.)/g, '$1');
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

function asText(cell) {
	var bytes, r = '', k;

	if (!cell) {
		return '';
	}
	if (cell.t === 'Hex-STRING') {
		bytes = hexBytes(cell.v);
		for (k = 0; k < bytes.length; k++) {
			if (bytes[k] >= 32 && bytes[k] < 127) {
				r += String.fromCharCode(bytes[k]);
			}
		}

		return r.replace(/^\s+|\s+$/g, '');
	}

	return String(cell.v).replace(/^\s+|\s+$/g, '');
}

// cdpCacheAddress: 4 octets for IPv4. Zabbix may print it as Hex-STRING or (when printable) as STRING.
function asIp(cell) {
	var bytes = [], k;

	if (!cell) {
		return '';
	}
	if (cell.t === 'Hex-STRING') {
		bytes = hexBytes(cell.v);
	}
	else if (cell.t === 'IpAddress') {
		return cell.v;
	}
	else {
		for (k = 0; k < cell.v.length; k++) {
			bytes.push(cell.v.charCodeAt(k) & 0xFF);
		}
	}

	return bytes.length === 4 ? bytes.join('.') : '';
}

// cdpCacheCapabilities (4 octets bitmask): 0x01 router, 0x02 TB bridge, 0x04 SR bridge, 0x08 switch,
// 0x10 host, 0x20 IGMP, 0x40 repeater, 0x80 phone (VoIP), 0x100 remotely managed device.
function asCaps(cell) {
	var bytes, mask = 0, caps = [], k;

	if (!cell) {
		return '';
	}
	bytes = cell.t === 'Hex-STRING' ? hexBytes(cell.v) : [];
	for (k = 0; k < bytes.length; k++) {
		mask = (mask * 256) + bytes[k];
	}
	if (mask & 0x01) { caps.push('router'); }
	if (mask & 0x0E) { caps.push('bridge'); }
	if (mask & 0x10) { caps.push('station'); }
	if (mask & 0x40) { caps.push('repeater'); }
	if (mask & 0x80) { caps.push('telephone'); }

	return caps.join(',');
}

function sanitizeKey(s) {
	return String(s).replace(/[^A-Za-z0-9._:\/\-]/g, '_').substring(0, 160);
}

function truncate(s, len) {
	s = String(s).replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ');

	return s.length > len ? s.substring(0, len - 1) + '…' : s;
}

var W = parseWalk(value);

var P_CDP = '1.3.6.1.4.1.9.9.23.1.2.1.1.',
	P_IFNAME = '1.3.6.1.2.1.31.1.1.1.1.',
	P_IFDESCR = '1.3.6.1.2.1.2.2.1.2.';

var cache = {}, ifName = {}, ifDescr = {}, oid, rest, dot, col, idx;

for (oid in W) {
	if (!W.hasOwnProperty(oid)) {
		continue;
	}
	if (oid.indexOf(P_CDP) === 0) {
		rest = oid.substring(P_CDP.length);
		dot = rest.indexOf('.');
		col = rest.substring(0, dot);
		idx = rest.substring(dot + 1);
		if (!cache[idx]) {
			cache[idx] = {};
		}
		cache[idx][col] = W[oid];
	}
	else if (oid.indexOf(P_IFNAME) === 0) {
		ifName[oid.substring(P_IFNAME.length)] = asText(W[oid]);
	}
	else if (oid.indexOf(P_IFDESCR) === 0) {
		ifDescr[oid.substring(P_IFDESCR.length)] = asText(W[oid]);
	}
}

var keys = [];
for (idx in cache) {
	if (cache.hasOwnProperty(idx)) {
		keys.push(idx);
	}
}
keys.sort(function(a, b) {
	var pa = a.split('.'), pb = b.split('.');

	return (parseInt(pa[0], 10) - parseInt(pb[0], 10)) || (parseInt(pa[1], 10) - parseInt(pb[1], 10));
});

var list = [], seen = {};

for (var r = 0; r < keys.length; r++) {
	idx = keys[r];
	var e = cache[idx], ifindex = idx.split('.')[0], nb = {},
		device = asText(e['6']), port = asText(e['7']), ip = '';

	if (device === '' && port === '') {
		continue;
	}
	// cdpCacheAddressType 1 = ip
	if (!e['3'] || /(^|\()1\)?$/.test(String(e['3'].v)) || String(e['3'].v) === '1') {
		ip = asIp(e['4']);
	}

	nb.k = sanitizeKey('cdp_' + ifindex + '_' + device + '_' + port);
	if (seen[nb.k]) {
		continue;
	}
	seen[nb.k] = true;

	nb.li = ifindex;
	nb.lp = ifName[ifindex] || ifDescr[ifindex] || ifindex;
	if (device !== '') {
		nb.rn = truncate(device, 128);
	}
	if (port !== '') {
		nb.rp = truncate(port, 128);
	}
	if (ip !== '') {
		nb.ra = [ip];
	}

	var platform = asText(e['8']);
	if (platform !== '') {
		nb.rd = truncate(platform, 100);
	}

	var caps = asCaps(e['9']);
	if (caps !== '') {
		nb.cap = caps;
	}

	list.push(nb);
}

var json = JSON.stringify(list);

while (json.length > MAX_JSON && list.length > 0) {
	list.pop();
	json = JSON.stringify(list);
}

return json;
