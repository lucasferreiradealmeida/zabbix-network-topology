// Capacity Planning - point-to-point IPv4 addresses of the device, for finding neighbors without LLDP/CDP.
// Zabbix JavaScript preprocessing (Duktape, ES5 only).
// Input: walk[] text with IF-MIB ifName/ifDescr and IP-MIB ipAdEntIfIndex/ipAdEntNetMask.
// Output: JSON list [[address, prefix length, interface name], ...] of the addresses whose mask is
// /{$CAPACITY.P2P.PREFIX} or longer (default 30: /30 and /31), sorted. Two interfaces of two devices in the same
// /30 or /31 are the two ends of one link: the module "Network Topology" pairs them.
var PREFIX_MIN = parseInt(cfg('{$CAPACITY.P2P.PREFIX}', '30'), 10);

var OID_IFNAME = '1.3.6.1.2.1.31.1.1.1.1.';
var OID_IFDESCR = '1.3.6.1.2.1.2.2.1.2.';
var OID_IPIFINDEX = '1.3.6.1.2.1.4.20.1.2.';
var OID_IPMASK = '1.3.6.1.2.1.4.20.1.3.';

if (isNaN(PREFIX_MIN) || PREFIX_MIN < 24 || PREFIX_MIN > 31) {
	PREFIX_MIN = 30;
}

function cfg(value, fallback) {
	return (value.indexOf('{$') === 0) ? fallback : value;
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

// "255.255.255.252" -> 30; -1 when not a contiguous mask.
function prefixLength(mask) {
	var parts = String(mask).replace(/^"|"$/g, '').split('.'), bits = '', k, b;

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

	return /^1*0*$/.test(bits) ? bits.indexOf('0') < 0 ? 32 : bits.indexOf('0') : -1;
}

var walk = parseWalk(value);
var result = [], oid, ip, idx, prefix, name, first;

for (oid in walk) {
	if (!walk.hasOwnProperty(oid) || oid.indexOf(OID_IPIFINDEX) !== 0) {
		continue;
	}

	ip = oid.substring(OID_IPIFINDEX.length);

	if (!/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/.test(ip)) {
		continue;
	}

	first = parseInt(ip.split('.')[0], 10);

	// Loopback and "this network" are never links.
	if (first === 127 || first === 0) {
		continue;
	}

	prefix = prefixLength(walk[OID_IPMASK + ip] || '');

	if (prefix < PREFIX_MIN || prefix > 31) {
		continue;
	}

	idx = String(walk[oid]).replace(/[^0-9]/g, '');
	name = walk[OID_IFNAME + idx] || walk[OID_IFDESCR + idx] || '';

	if (idx === '' || name === '') {
		continue;
	}

	result.push([ip, prefix, name]);
}

result.sort(function(a, b) {
	return a[0] < b[0] ? -1 : (a[0] > b[0] ? 1 : 0);
});

return JSON.stringify(result);
