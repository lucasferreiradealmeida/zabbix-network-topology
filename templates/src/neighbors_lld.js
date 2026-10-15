// Network Topology (nettopo) - converts the neighbors JSON into LLD rows (one per neighbor).
var list = JSON.parse(value), rows = [], k, n, name, port;

for (k = 0; k < list.length; k++) {
	n = list[k];
	name = n.rn || n.rc || (n.ra && n.ra.length ? n.ra[0] : '') || 'unknown';
	port = n.rp || '';

	// A MAC address as remote port ID is not human friendly, prefer the port description.
	if (n.rpd && (port === '' || /^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i.test(port))) {
		port = n.rpd;
	}

	rows.push({
		'{#NT.KEY}': n.k,
		'{#NT.LOCAL.PORT}': n.lp || n.lpn || '',
		'{#NT.LOCAL.IFINDEX}': n.li || '',
		'{#NT.REMOTE.NAME}': name,
		'{#NT.REMOTE.PORT}': port,
		'{#NT.REMOTE.CHASSIS}': n.rc || '',
		'{#NT.REMOTE.IP}': (n.ra && n.ra.length) ? n.ra[0] : ''
	});
}

return JSON.stringify(rows);
