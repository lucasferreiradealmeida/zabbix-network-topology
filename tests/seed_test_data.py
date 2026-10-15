#!/usr/bin/env python3
"""Seeds a local Zabbix 7.x test instance with an ISP-like topology for the Network Topology module.

Creates hosts/items/triggers/maps through the API, then writes item history, problems and an
LLD "lost" neighbor directly into the database (no Zabbix server needed).
LLDP/CDP JSON values are produced by running the real template JavaScript on generated SNMP walks.

Usage: seed_test_data.py <api_url> <psql_args...>
"""
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
API = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080/api_jsonrpc.php'
PSQL = ['psql', '-h', '/tmp', '-p', '5433', '-U', 'zabbix', '-d', os.environ.get('NETTOPO_DB', 'zabbix'),
        '-v', 'ON_ERROR_STOP=1', '-q']
NOW = int(time.time())
_token = None
_next_id = [900000000]


def call(method, params, auth=True):
    global _token
    if auth and _token is None:
        _token = call('user.login', {'username': 'Admin', 'password': 'zabbix'}, auth=False)
    headers = {'Content-Type': 'application/json-rpc'}
    if auth:
        headers['Authorization'] = 'Bearer ' + _token
    req = urllib.request.Request(API, data=json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params,
                                                       'id': 1}).encode(), headers=headers)
    resp = json.load(urllib.request.urlopen(req))
    if 'error' in resp:
        raise RuntimeError('%s: %s' % (method, resp['error']))
    return resp['result']


def sql(statements):
    subprocess.run(PSQL, input='\n'.join(statements), text=True, check=True)


def q(value):
    return "'" + str(value).replace("'", "''") + "'"


def nid():
    _next_id[0] += 1
    return _next_id[0]


def run_js(script, value):
    """Runs a template preprocessing script (templates/src) on a walk value, like the Zabbix server does."""
    with tempfile.NamedTemporaryFile('w', suffix='.walk', delete=False) as f:
        f.write(value)
        path = f.name
    try:
        out = subprocess.run(['node', os.path.join(HERE, 'run_preproc.js'),
                              os.path.join(HERE, '..', 'templates', 'src', script), path],
                             capture_output=True, text=True, check=True)
    finally:
        os.unlink(path)
    return out.stdout.strip()


def mac_hex(mac):
    return ' '.join(mac.split(':')).upper()


def lldp_walk(ports, neighbors):
    """ports: [(lpn, ifindex, ifname)]; neighbors: [dict(lpn, mac, port, port_subtype, pdesc, name, desc, caps, ip)]"""
    lines = []
    for lpn, ifindex, ifname in ports:
        lines += ['.1.0.8802.1.1.2.1.3.7.1.2.%s = INTEGER: 5' % lpn,
                  '.1.0.8802.1.1.2.1.3.7.1.3.%s = STRING: "%s"' % (lpn, ifname),
                  '.1.0.8802.1.1.2.1.3.7.1.4.%s = STRING: "%s"' % (lpn, ifname)]
    for i, n in enumerate(neighbors, start=1):
        idx = '0.%s.%d' % (n['lpn'], i)
        lines.append('.1.0.8802.1.1.2.1.4.1.1.4.%s = INTEGER: 4' % idx)
        lines.append('.1.0.8802.1.1.2.1.4.1.1.5.%s = Hex-STRING: %s' % (idx, mac_hex(n['mac'])))
        lines.append('.1.0.8802.1.1.2.1.4.1.1.6.%s = INTEGER: %d' % (idx, n.get('port_subtype', 5)))
        if n.get('port_subtype', 5) == 3:
            lines.append('.1.0.8802.1.1.2.1.4.1.1.7.%s = Hex-STRING: %s' % (idx, mac_hex(n['port'])))
        else:
            lines.append('.1.0.8802.1.1.2.1.4.1.1.7.%s = STRING: "%s"' % (idx, n['port']))
        if n.get('pdesc'):
            lines.append('.1.0.8802.1.1.2.1.4.1.1.8.%s = STRING: "%s"' % (idx, n['pdesc']))
        lines.append('.1.0.8802.1.1.2.1.4.1.1.9.%s = STRING: "%s"' % (idx, n['name']))
        if n.get('desc'):
            lines.append('.1.0.8802.1.1.2.1.4.1.1.10.%s = STRING: "%s"' % (idx, n['desc']))
        lines.append('.1.0.8802.1.1.2.1.4.1.1.12.%s = BITS: %s' % (idx, n.get('caps', '28 00 bridge(2) router(4)')))
        if n.get('ip'):
            lines.append('.1.0.8802.1.1.2.1.4.2.1.3.%s.1.4.%s = INTEGER: 2' % (idx, n['ip']))
    for lpn, ifindex, ifname in ports:
        lines.append('.1.3.6.1.2.1.31.1.1.1.1.%s = STRING: "%s"' % (ifindex, ifname))
        lines.append('.1.3.6.1.2.1.2.2.1.2.%s = STRING: "%s"' % (ifindex, ifname))
    return '\n'.join(lines)


def cdp_walk(ifnames, entries):
    """ifnames: {ifindex: name}; entries: [dict(ifindex, ip, device, port, platform)]"""
    lines = []
    for i, e in enumerate(entries, start=1):
        idx = '%s.%d' % (e['ifindex'], i)
        ip_hex = ' '.join('%02X' % int(x) for x in e['ip'].split('.'))
        lines += ['.1.3.6.1.4.1.9.9.23.1.2.1.1.3.%s = INTEGER: 1' % idx,
                  '.1.3.6.1.4.1.9.9.23.1.2.1.1.4.%s = Hex-STRING: %s' % (idx, ip_hex),
                  '.1.3.6.1.4.1.9.9.23.1.2.1.1.6.%s = STRING: "%s"' % (idx, e['device']),
                  '.1.3.6.1.4.1.9.9.23.1.2.1.1.7.%s = STRING: "%s"' % (idx, e['port']),
                  '.1.3.6.1.4.1.9.9.23.1.2.1.1.8.%s = STRING: "%s"' % (idx, e['platform']),
                  '.1.3.6.1.4.1.9.9.23.1.2.1.1.9.%s = Hex-STRING: 00 00 00 29' % idx]
    for ifindex, name in ifnames.items():
        lines.append('.1.3.6.1.2.1.31.1.1.1.1.%s = STRING: "%s"' % (ifindex, name))
    return '\n'.join(lines)


# ---------------------------------------------------------------------------------------------------------------
# Inventory of the test network
# ---------------------------------------------------------------------------------------------------------------

HUAWEI = 'Huawei Versatile Routing Platform Software VRP (R) software, Version 8.180 (NE40E V800R011C10SPC200)'
MIKROTIK = 'MikroTik RouterOS 7.15.3 (stable) CCR2004-1G-12S+2XS'

HOSTS = {
    'PE1-VR': {'ip': '10.0.99.1', 'group': 'BACKBONE', 'templates': ['lldp']},
    'PE3-ITATIAIA': {'ip': '10.0.99.3', 'group': 'BACKBONE', 'templates': ['lldp']},
    'PE5-QUATIS': {'ip': '10.0.99.5', 'group': 'BACKBONE', 'templates': ['lldp']},
    'PE12-VARJAO': {'ip': '10.0.99.12', 'group': 'BACKBONE', 'templates': ['lldp']},
    'PE15-PENEDO': {'ip': '10.0.99.15', 'group': 'BACKBONE', 'templates': ['lldp']},
    'CORE-RJ-01': {'ip': '10.0.99.100', 'group': 'BACKBONE', 'templates': ['lldp', 'cdp']},
    'OLT-VR-01': {'ip': '10.0.98.10', 'group': 'ACESSO', 'templates': [],
                  'tags': [{'tag': 'topology.type', 'value': 'olt'},
                           {'tag': 'topology.link', 'value': 'PE1-VR | gpon-uplink-1 | GigabitEthernet0/3/0'}]},
    'CPE-CLIENTE-A': {'ip': '10.0.97.11', 'group': 'ACESSO', 'templates': [],
                      'tags': [{'tag': 'topology.type', 'value': 'cpe'}]},
    'CPE-CLIENTE-B': {'ip': '10.0.97.12', 'group': 'ACESSO', 'templates': [],
                      'tags': [{'tag': 'topology.type', 'value': 'cpe'}]},
    'RT-CORE-TO': {'ip': '10.10.0.1', 'group': 'Regional TO', 'templates': []},
    'SW-AGG-TO': {'ip': '10.10.0.2', 'group': 'Regional TO', 'templates': []},
    'CPE-TO-PREFEITURA': {'ip': '10.10.0.3', 'group': 'Regional TO', 'templates': [],
                          'tags': [{'tag': 'topology.type', 'value': 'cpe'}]},
}

# Ports per host: lpn -> (ifindex, ifName)
PORTS = {
    'PE1-VR': {1: (7, 'XGigabitEthernet0/0/7'), 2: (8, 'XGigabitEthernet0/0/8'), 3: (9, 'XGigabitEthernet0/0/9'),
               4: (10, 'XGigabitEthernet0/0/10'), 5: (20, 'XGigabitEthernet0/0/20'),
               11: (41, 'XGigabitEthernet0/0/41'), 12: (53, 'GigabitEthernet0/2/3'),
               13: (12, 'XGigabitEthernet0/0/12'), 14: (17, 'XGigabitEthernet0/0/17'),
               15: (55, 'GigabitEthernet0/2/5'), 16: (30, 'XGigabitEthernet0/0/30'),
               17: (43, 'XGigabitEthernet0/0/43'), 18: (44, 'XGigabitEthernet0/0/44'),
               19: (60, 'GigabitEthernet0/3/0')},
    'PE5-QUATIS': {2: (2, 'XGigabitEthernet0/0/2'), 3: (25, 'GigabitEthernet0/0/5')},
    'PE15-PENEDO': {1: (1, 'sfp-sfpplus1'), 2: (2, 'sfp-sfpplus2')},
    'PE12-VARJAO': {1: (1, 'XGigabitEthernet0/0/1'), 3: (3, 'XGigabitEthernet0/0/3')},
    'PE3-ITATIAIA': {4: (4, 'XGigabitEthernet0/0/4'), 6: (6, 'XGigabitEthernet0/0/6'), 8: (8, 'XGigabitEthernet0/0/8'),
                     15: (15, 'XGigabitEthernet0/0/15'), 21: (21, 'XGigabitEthernet0/0/21')},
    'CORE-RJ-01': {1: (101, 'Te1/1/1'), 2: (102, 'Te1/1/2')},
}

MACS = {'PE1-VR': '70:7b:e8:00:00:01', 'PE3-ITATIAIA': '70:7b:e8:00:00:03', 'PE5-QUATIS': '70:7b:e8:00:00:05',
        'PE12-VARJAO': '70:7b:e8:00:00:12', 'PE15-PENEDO': '48:a9:8a:00:00:15', 'CORE-RJ-01': '00:1e:7a:00:01:00'}


def nb(lpn, name, port, desc=HUAWEI, mac=None, ip=None, pdesc=None, caps='28 00 bridge(2) router(4)', subtype=5):
    return {'lpn': lpn, 'name': name, 'port': port, 'desc': desc, 'mac': mac or MACS[name],
            'ip': ip if ip is not None else HOSTS.get(name, {}).get('ip'), 'pdesc': pdesc, 'caps': caps,
            'port_subtype': subtype}


LLDP = {
    'PE1-VR': [
        nb(1, 'PE5-QUATIS', 'XGigabitEthernet0/0/2'),
        nb(2, 'PE15-PENEDO', 'sfp-sfpplus1', desc=MIKROTIK),
        # lpn 3 (to PE12-VARJAO) is LOST: not in the current table.
        nb(4, 'PE3-ITATIAIA', 'XGigabitEthernet0/0/4'),
        nb(5, 'CORE-RJ-01', 'Te1/1/1', desc='Cisco IOS XE Software, ASR1000'),
        nb(11, 'ARC-VR_1.1', 'sfp-sfpplus2', desc=MIKROTIK, mac='48:a9:8a:11:22:01', ip='10.0.99.21'),
        nb(12, 'BNG-VR-01', '00:e0:fc:12:34:99', desc='Huawei NE20E-S2F', mac='00:e0:fc:12:34:56', ip='',
           pdesc='GigabitEthernet0/2/3', subtype=3),
        nb(13, 'SW-SPO-04', 'XGigabitEthernet0/0/12', desc='Huawei S6730', mac='70:7b:e8:aa:00:04', ip='',
           caps='20 00 bridge(2)'),
        nb(14, 'DMZ-SERVERS', 'bridge1/vlan100', desc=MIKROTIK, mac='48:a9:8a:11:22:02', ip='10.20.0.1'),
        nb(15, 'WIFI-CONTROLLER', 'vlan200', desc='TP-Link Omada Controller', mac='5c:a6:e6:00:00:01', ip='',
           caps='80 00 other(0)'),
        nb(16, 'NAT-01', 'sfp-sfpplus2', desc=MIKROTIK, mac='48:a9:8a:11:22:03', ip='10.30.0.1'),
        nb(17, 'I-BR-RJ-VRD-VRD-AGG-02', '25GE0/2/22', desc='Huawei S6730', mac='70:7b:e8:aa:00:12', ip='',
           caps='20 00 bridge(2)'),
        nb(18, 'I-BR-RJ-VRD-VRD-AGG-01', '25GE0/2/22', desc='Huawei S6730', mac='70:7b:e8:aa:00:11', ip='',
           caps='20 00 bridge(2)'),
    ],
    'PE5-QUATIS': [
        nb(2, 'PE1-VR', 'XGigabitEthernet0/0/7'),
        nb(3, 'AP-NOC-01', 'ether1', desc='MikroTik RouterOS 7.12 hAP ax3', mac='48:a9:8a:33:00:01', ip='',
           caps='10 00 wlanAccessPoint(3)'),
    ],
    'PE15-PENEDO': [
        nb(1, 'PE1-VR', 'XGigabitEthernet0/0/8'),
        nb(2, 'PE12-VARJAO', 'XGigabitEthernet0/0/3'),
    ],
    'PE12-VARJAO': [
        # lpn 1 (to PE1-VR) is LOST.
        nb(3, 'PE15-PENEDO', 'sfp-sfpplus2', desc=MIKROTIK),
    ],
    'PE3-ITATIAIA': [
        nb(4, 'PE1-VR', 'XGigabitEthernet0/0/10'),
        nb(6, 'AGG-ITA-01', 'XGigabitEthernet0/0/4', desc='Huawei S6730', mac='70:7b:e8:bb:00:01', ip='',
           caps='20 00 bridge(2)'),
        nb(8, 'AGG-ITA-02', '25GE0/2/22', desc='Huawei S6730', mac='70:7b:e8:bb:00:02', ip='',
           caps='20 00 bridge(2)'),
        nb(15, 'SW-ITATIAIA-01', 'XGigabitEthernet0/0/2', desc='Huawei S5735', mac='70:7b:e8:bb:00:03', ip='',
           caps='20 00 bridge(2)'),
    ],
    'CORE-RJ-01': [
        nb(1, 'PE1-VR', 'XGigabitEthernet0/0/20'),
    ],
}

CDP = {
    'CORE-RJ-01': [
        {'ifindex': 101, 'ip': '10.0.99.1', 'device': 'PE1-VR', 'port': 'XGigabitEthernet0/0/20',
         'platform': 'Huawei NE40E'},
        {'ifindex': 102, 'ip': '10.0.99.3', 'device': 'PE3-ITATIAIA.rede.local', 'port': 'XGigabitEthernet0/0/21',
         'platform': 'Huawei NE40E'},
    ]
}

# (host, ifindex) -> (oper, in_bps, out_bps, speed_bps)
PORT_VALUES = {
    ('PE1-VR', 7): (1, 3.2e9, 1.1e9, 10e9),
    ('PE1-VR', 8): (1, 1.2e9, 0.8e9, 10e9),
    ('PE1-VR', 9): (2, 0, 0, 10e9),
    ('PE1-VR', 10): (1, 8.6e9, 2.1e9, 10e9),
    ('PE1-VR', 20): (1, 41e9, 27e9, 100e9),
    ('PE1-VR', 41): (1, 480e6, 210e6, 10e9),
    ('PE1-VR', 12): (1, 2.4e9, 1.9e9, 10e9),
    ('PE1-VR', 60): (1, 650e6, 90e6, 1e9),
    ('PE5-QUATIS', 2): (1, 1.1e9, 3.2e9, 10e9),
    ('PE15-PENEDO', 1): (1, 0.8e9, 1.2e9, 10e9),
    ('PE15-PENEDO', 2): (1, 0.4e9, 0.3e9, 10e9),
    ('PE12-VARJAO', 1): (2, 0, 0, 10e9),
    ('PE3-ITATIAIA', 4): (1, 2.1e9, 8.6e9, 10e9),
    ('CORE-RJ-01', 101): (1, 27e9, 41e9, 100e9),
}


def main():
    group_ids = {}
    for name in ['BACKBONE', 'ACESSO', 'Regional TO']:
        found = call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': name}})
        group_ids[name] = found[0]['groupid'] if found else call('hostgroup.create', {'name': name})['groupids'][0]

    templates = {t['host']: t['templateid'] for t in call('template.get', {
        'output': ['host'], 'filter': {'host': ['Network Topology LLDP by SNMP', 'Network Topology CDP by SNMP']}})}
    tpl = {'lldp': templates['Network Topology LLDP by SNMP'], 'cdp': templates['Network Topology CDP by SNMP']}

    existing = call('host.get', {'output': ['hostid', 'host'], 'filter': {'host': list(HOSTS)}})
    if existing:
        call('host.delete', [h['hostid'] for h in existing])

    hostids = {}
    for name, h in HOSTS.items():
        hostids[name] = call('host.create', {
            'host': name,
            'groups': [{'groupid': group_ids[h['group']]}],
            'interfaces': [{'type': 2, 'main': 1, 'useip': 1, 'ip': h['ip'], 'dns': '', 'port': '161',
                            'details': {'version': 2, 'community': 'public'}}],
            'templates': [{'templateid': tpl[t]} for t in h['templates']],
            'tags': h.get('tags', [])
        })['hostids'][0]

    # Generic items used by triggers.
    items = {}

    def item(host, key, name, value_type=3, units='', tags=None):
        itemid = call('item.create', {'hostid': hostids[host], 'name': name, 'key_': key, 'type': 2,
                                      'value_type': value_type, 'units': units, 'tags': tags or []})['itemids'][0]
        items[(host, key)] = itemid
        return itemid

    for host in HOSTS:
        item(host, 'health', 'Health')

    # Interface items like the official templates, with the "interface" tag.
    for (host, ifindex), (oper, rx, tx, speed) in PORT_VALUES.items():
        ifname = next(n for i, n in PORTS[host].values() if i == ifindex) if host in PORTS else str(ifindex)
        tags = [{'tag': 'interface', 'value': ifname}, {'tag': 'component', 'value': 'network'}]
        item(host, 'net.if.status[ifOperStatus.%d]' % ifindex, 'Interface %s: Operational status' % ifname, tags=tags)
        item(host, 'net.if.in[ifHCInOctets.%d]' % ifindex, 'Interface %s: Bits received' % ifname, units='bps',
             tags=tags)
        item(host, 'net.if.out[ifHCOutOctets.%d]' % ifindex, 'Interface %s: Bits sent' % ifname, units='bps',
             tags=tags)
        item(host, 'net.if.speed[ifHighSpeed.%d]' % ifindex, 'Interface %s: Speed' % ifname, units='bps', tags=tags)

    # Triggers (problems are written to the DB below).
    triggers = {}

    def trigger(host, name, priority, key='health', tags=None, deps=None):
        expr = 'last(/%s/%s)=2' % (host, key)
        params = {'description': name, 'expression': expr, 'priority': priority, 'tags': tags or []}
        if deps:
            params['dependencies'] = [{'triggerid': triggers[d]} for d in deps]
        triggers[(host, name)] = call('trigger.create', params)['triggerids'][0]
        return triggers[(host, name)]

    problems = [
        ('PE1-VR', 'Chassis temperature is critical (85 C)', 5, 'health', []),
        ('PE1-VR', 'Interface XGigabitEthernet0/0/9(Uplink PE12): Link down', 3, 'net.if.status[ifOperStatus.9]',
         [('interface', 'XGigabitEthernet0/0/9'), ('scope', 'availability')]),
        ('PE3-ITATIAIA', 'Power supply 2 failed', 5, 'health', []),
        ('PE3-ITATIAIA', 'Interface XGigabitEthernet0/0/4: High bandwidth usage (>85%)', 2, 'health',
         [('interface', 'XGigabitEthernet0/0/4'), ('scope', 'performance')]),
        ('PE5-QUATIS', 'High CPU utilization (95%)', 4, 'health', []),
        ('PE12-VARJAO', 'BGP session to PE1-VR is down', 4, 'health', []),
        ('PE12-VARJAO', 'Interface XGigabitEthernet0/0/1: Link down', 3, 'net.if.status[ifOperStatus.1]',
         [('interface', 'XGigabitEthernet0/0/1')]),
        ('OLT-VR-01', 'OLT: 3 ONUs offline', 3, 'health', []),
        ('RT-CORE-TO', 'Interface Gi0/0/2: Link down', 3, 'health', [('interface', 'Gi0/0/2')]),
    ]

    for host, name, priority, key, tags in problems:
        trigger(host, name, priority, key, [{'tag': t, 'value': v} for t, v in tags])

    trigger('OLT-VR-01', 'OLT unreachable', 4)
    trigger('CPE-CLIENTE-A', 'Unavailable by ICMP ping', 4, deps=[('OLT-VR-01', 'OLT unreachable')])
    trigger('CPE-CLIENTE-B', 'Unavailable by ICMP ping', 4, deps=[('OLT-VR-01', 'OLT unreachable')])
    trigger('SW-AGG-TO', 'Unavailable by ICMP ping', 4)
    problems.append(('CPE-CLIENTE-A', 'Unavailable by ICMP ping', 4, 'health', []))

    # Map "Regional TO" (links RT-CORE-TO <-> SW-AGG-TO, SW-AGG-TO <-> CPE, RT-CORE-TO <-> CPE)
    for m in call('map.get', {'output': ['sysmapid'], 'filter': {'name': 'Regional TO'}}):
        call('map.delete', [m['sysmapid']])
    selements = []
    for i, name in enumerate(['RT-CORE-TO', 'SW-AGG-TO', 'CPE-TO-PREFEITURA']):
        selements.append({'selementid': str(i + 1), 'elements': [{'hostid': hostids[name]}], 'elementtype': 0,
                          'iconid_off': '151', 'x': 100 + i * 200, 'y': 150})
    version = tuple(int(x) for x in call('apiinfo.version', {}, auth=False).split('.')[:2])
    links = [
        {'selementid1': '1', 'selementid2': '2', 'label': 'Fibra 10G'},
        {'selementid1': '2', 'selementid2': '3', 'label': '{?last(/SW-AGG-TO/health)}', 'indicator_type': 1,
         'linktriggers': [{'triggerid': triggers[('SW-AGG-TO', 'Unavailable by ICMP ping')], 'color': 'FF0000',
                           'drawtype': 0}]},
        {'selementid1': '1', 'selementid2': '3', 'label': 'Backup', 'indicator_type': 1,
         'linktriggers': [{'triggerid': triggers[('RT-CORE-TO', 'Interface Gi0/0/2: Link down')], 'color': 'FF0000',
                           'drawtype': 0}]},
    ]
    if version < (7, 4):
        # Link indicators (indicator_type) were introduced in Zabbix 7.4.
        for link in links:
            link.pop('indicator_type', None)
    mapid = call('map.create', {'name': 'Regional TO', 'width': 800, 'height': 400, 'selements': selements,
                                'links': links})['sysmapids'][0]

    # -------------------------------------------------------------------------------------------------------
    # Direct DB writes: history, problems, maintenance/availability, lost LLD neighbor.
    # -------------------------------------------------------------------------------------------------------
    stmts = []

    # IDs for rows written directly: above anything already in the database (re-runs are allowed).
    out = subprocess.run(PSQL + ['-At', '-c', 'SELECT GREATEST(900000000, (SELECT COALESCE(MAX(eventid), 0) FROM events),'
                                 ' (SELECT COALESCE(MAX(itemid), 0) FROM items),'
                                 ' (SELECT COALESCE(MAX(eventtagid), 0) FROM event_tag),'
                                 ' (SELECT COALESCE(MAX(itemtagid), 0) FROM item_tag),'
                                 ' (SELECT COALESCE(MAX(itemdiscoveryid), 0) FROM item_discovery))'],
                         capture_output=True, text=True, check=True)
    _next_id[0] = int(out.stdout.strip()) + 1000

    neighbor_items = {i['hostid'] + '|' + i['key_']: i['itemid'] for i in call('item.get', {
        'output': ['itemid', 'hostid', 'key_'], 'hostids': list(hostids.values()),
        'filter': {'key_': ['nettopo.lldp.neighbors', 'nettopo.cdp.neighbors']}})}

    for host, nbs in LLDP.items():
        ports = [(lpn, ifi, ifn) for lpn, (ifi, ifn) in PORTS[host].items()]
        value = run_js('lldp_neighbors.js', lldp_walk(ports, nbs))
        itemid = neighbor_items[hostids[host] + '|nettopo.lldp.neighbors']
        stmts.append('INSERT INTO history_text (itemid, clock, value, ns) VALUES (%s, %d, %s, 0);'
                     % (itemid, NOW - 60, q(value)))

    for host, entries in CDP.items():
        ifnames = {ifi: ifn for _, (ifi, ifn) in PORTS[host].items()}
        value = run_js('cdp_neighbors.js', cdp_walk(ifnames, entries))
        itemid = neighbor_items[hostids[host] + '|nettopo.cdp.neighbors']
        stmts.append('INSERT INTO history_text (itemid, clock, value, ns) VALUES (%s, %d, %s, 0);'
                     % (itemid, NOW - 60, q(value)))

    for (host, ifindex), values in PORT_VALUES.items():
        for key, value in zip(['net.if.status[ifOperStatus.%d]', 'net.if.in[ifHCInOctets.%d]',
                               'net.if.out[ifHCOutOctets.%d]', 'net.if.speed[ifHighSpeed.%d]'], values):
            stmts.append('INSERT INTO history_uint (itemid, clock, value, ns) VALUES (%s, %d, %d, 0);'
                         % (items[(host, key % ifindex)], NOW - 30, int(value)))

    for host, name, priority, key, tags in problems:
        triggerid = triggers[(host, name)]
        eventid = nid()
        clock = NOW - (3600 * (priority + 1)) - 600
        stmts.append('UPDATE triggers SET value=1, lastchange=%d WHERE triggerid=%s;' % (clock, triggerid))
        stmts.append('INSERT INTO events (eventid, source, object, objectid, clock, value, acknowledged, ns, name, '
                     'severity) VALUES (%d, 0, 0, %s, %d, 1, 0, 0, %s, %d);' % (eventid, triggerid, clock, q(name),
                                                                                priority))
        stmts.append('INSERT INTO problem (eventid, source, object, objectid, clock, ns, name, acknowledged, severity) '
                     'VALUES (%d, 0, 0, %s, %d, 0, %s, 0, %d);' % (eventid, triggerid, clock, q(name), priority))
        for tag, value in tags:
            stmts.append('INSERT INTO problem_tag (problemtagid, eventid, tag, value) VALUES (%d, %d, %s, %s);'
                         % (nid(), eventid, q(tag), q(value)))
            stmts.append('INSERT INTO event_tag (eventtagid, eventid, tag, value) VALUES (%d, %d, %s, %s);'
                         % (nid(), eventid, q(tag), q(value)))

    stmts.append('UPDATE hosts SET maintenance_status=1, maintenance_type=0, maintenance_from=%d WHERE hostid=%s;'
                 % (NOW - 3600, hostids['PE15-PENEDO']))
    stmts.append('UPDATE interface SET available=2 WHERE hostid=%s;' % hostids['OLT-VR-01'])

    # Lost LLDP neighbors (LLD entity status = lost) on both ends of PE1-VR <-> PE12-VARJAO.
    prototypes = {p['hostid']: p['itemid'] for p in call('itemprototype.get', {
        'output': ['itemid', 'hostid'], 'hostids': [hostids['PE1-VR'], hostids['PE12-VARJAO']],
        'filter': {'key_': 'nettopo.lldp.neighbor[{#NT.KEY}]'}})}
    lost = [
        ('PE1-VR', '3_70:7b:e8:00:00:12_XGigabitEthernet0/0/1', 'XGigabitEthernet0/0/9', '9', 'PE12-VARJAO',
         'XGigabitEthernet0/0/1', '70:7b:e8:00:00:12', '10.0.99.12'),
        ('PE12-VARJAO', '1_70:7b:e8:00:00:01_XGigabitEthernet0/0/9', 'XGigabitEthernet0/0/1', '1', 'PE1-VR',
         'XGigabitEthernet0/0/9', '70:7b:e8:00:00:01', '10.0.99.1'),
    ]
    for host, key, local, ifindex, remote, rport, chassis, rip in lost:
        itemid = nid()
        hostid = hostids[host]
        master = neighbor_items[hostid + '|nettopo.lldp.neighbors']
        stmts.append("INSERT INTO items (itemid, type, hostid, name, key_, delay, history, trends, status, value_type, "
                     "flags, master_itemid, uuid) VALUES (%d, 18, %s, %s, %s, '0', '7d', '0', 0, 3, 4, %s, '');"
                     % (itemid, hostid, q('LLDP neighbor %s (%s) on %s' % (remote, rport, local)),
                        q('nettopo.lldp.neighbor[%s]' % key), master))
        stmts.append('INSERT INTO item_rtdata (itemid) VALUES (%d);' % itemid)
        name = 'LLDP neighbor %s (%s) on %s' % (remote, rport, local)
        stmts.append('INSERT INTO item_rtname (itemid, name_resolved, name_resolved_upper) VALUES (%d, %s, %s);'
                     % (itemid, q(name), q(name.upper())))
        stmts.append("INSERT INTO item_discovery (itemdiscoveryid, itemid, parent_itemid, key_, lastcheck, ts_delete, "
                     "status) VALUES (%d, %d, %s, %s, %d, %d, 1);" % (nid(), itemid, prototypes[hostid],
                                                                      q('nettopo.lldp.neighbor[{#NT.KEY}]'), NOW - 300,
                                                                      NOW + 6 * 86400))
        for tag, value in [('component', 'topology'), ('nt.ifindex', ifindex), ('nt.local', local),
                           ('nt.proto', 'lldp'), ('nt.rchassis', chassis), ('nt.remote', remote), ('nt.rip', rip),
                           ('nt.rport', rport)]:
            stmts.append('INSERT INTO item_tag (itemtagid, itemid, tag, value) VALUES (%d, %d, %s, %s);'
                         % (nid(), itemid, q(tag), q(value)))
        stmts.append('INSERT INTO history_uint (itemid, clock, value, ns) VALUES (%d, %d, 0, 0);' % (itemid, NOW - 300))

    sql(['BEGIN;'] + stmts + ['COMMIT;'])

    print(json.dumps({'groups': group_ids, 'hosts': hostids, 'map': mapid}, indent=1))


if __name__ == '__main__':
    main()
