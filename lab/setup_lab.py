#!/usr/bin/env python3
"""Prepares the Network Topology lab in a running Zabbix (docker compose up -d first).

- generates the simulated network (snmpdata/*.snmprec);
- imports the nettopo templates (topology + capacity planning) and enables the module;
- creates the lab devices (SNMP agent on the simulator) with the official vendor templates and the
  capacity planning profile of each one (Backbone, Distribuicao, CPE);
- asks the Zabbix server to poll them right away.

Only the Python 3 standard library is needed.
Usage: python3 setup_lab.py [--url http://localhost:8080] [--user Admin] [--password zabbix]
                            [--snmp-host snmpsim] [--snmp-port 1161]
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import network  # noqa: E402

TEMPLATES_DIR = os.path.join(HERE, '..', 'templates')
GROUP = 'LAB'

CAPACITY = 'Template Capacity Planning'

# device -> (vendor template, nettopo protocols, topology.level, capacity planning profile)
LAB_HOSTS = {
    'CORE-RJ-01': ('Cisco IOS by SNMP', ['lldp', 'cdp'], '0', 'Backbone'),
    'PE1-VR': ('Huawei VRP by SNMP', ['lldp'], '1', 'Distribuicao'),
    'PE3-ITATIAIA': ('Huawei VRP by SNMP', ['lldp'], '1', 'Distribuicao'),
    'PE5-QUATIS': ('Huawei VRP by SNMP', ['lldp'], '2', 'CPE'),
    'PE12-VARJAO': ('Huawei VRP by SNMP', ['lldp'], '2', 'CPE'),
    'PE15-PENEDO': ('Mikrotik by SNMP', ['lldp'], '2', 'CPE'),
}

# Capacity planning runs in minutes in the lab (defaults are 5m/1h/7d/30d, see the template macros).
LAB_CAPACITY_MACROS = {
    '{$CAPACITY.INTERVAL}': '1m',
    '{$CAPACITY.LLD.INTERVAL}': '2m',
    '{$CAPACITY.CALC.INTERVAL}': '2m',
    '{$CAPACITY.P95.PERIOD}': '30m',
    '{$CAPACITY.TREND.PERIOD}': '6h',
    '{$CAPACITY.FORECAST.HORIZON}': '1d',
}

# Capacity planning settings of the module in the lab: the traffic grows in hours, so the upgrade recommendation looks
# 2 days ahead (default 180 days; Settings > Capacity planning).
LAB_PLANNING = {'horizon': 2}

# Contracted bandwidth of circuits (global macro with context = circuit ID), in Mbps:
#  NET-RJ-0001: 2.5 Gbps on a 10G port;
#  NET-RJ-0003: 2 Gbps, more than the free capacity of the Itatiaia uplink ("no room for the contracted").
LAB_GLOBAL_MACROS = {
    '{$CAPACITY.BW.MBPS:"NET-RJ-0001"}': ('2500', 'Lab: banda contratada do circuito NET-RJ-0001 (Mbps)'),
    '{$CAPACITY.BW.MBPS:"NET-RJ-0003"}': ('2000', 'Lab: banda contratada do circuito NET-RJ-0003 (Mbps)'),
}

# Circuit given by host macros instead of the interface description (PE15-PENEDO's WAN says only "WAN").
LAB_HOST_MACROS = {
    'PE15-PENEDO': {'{$CIRCUIT_ID}': 'NET-RJ-0002', '{$CIRCUIT_ID.IF}': 'sfp-sfpplus1'},
}

# Users to try the saved maps with different permissions: (user group, rights on LAB, role, password).
# Zabbix refuses passwords that contain the user name.
# "noc" opens the public maps locked and can only save personal copies; "engenharia" (Admin role) changes them.
LAB_USERS = {
    'noc': ('LAB - NOC (leitura)', 2, 'User role', 'Leitura-Mapa-2026!'),
    'engenharia': ('LAB - Engenharia de rede', 3, 'Admin role', 'Edita-Mapa-2026!'),
}


class Api:

    def __init__(self, url):
        self.url = url.rstrip('/') + '/api_jsonrpc.php'
        self.token = None

    def call(self, method, params):
        headers = {'Content-Type': 'application/json-rpc'}

        if self.token is not None:
            headers['Authorization'] = 'Bearer ' + self.token

        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        req = urllib.request.Request(self.url, data=body, headers=headers)

        with urllib.request.urlopen(req, timeout=60) as resp:
            data = json.load(resp)

        if 'error' in data:
            raise RuntimeError('%s: %s' % (method, data['error'].get('data') or data['error']))

        return data['result']

    def login(self, user, password, wait=600):
        """Waits until the frontend answers (the first start creates the database), then logs in."""
        deadline = time.time() + wait
        last_error = None

        while time.time() < deadline:
            try:
                version = self.call('apiinfo.version', {})
                self.token = self.call('user.login', {'username': user, 'password': password})

                return version
            except (urllib.error.URLError, ConnectionError, RuntimeError, ValueError, OSError) as e:
                last_error = e
                print('    waiting for the Zabbix frontend/API... (%s)' % str(e)[:90])
                time.sleep(10)

        raise SystemExit('Zabbix API not available at %s: %s' % (self.url, last_error))


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--url', default=os.environ.get('LAB_URL', 'http://localhost:8080'))
    parser.add_argument('--user', default='Admin')
    parser.add_argument('--password', default='zabbix')
    parser.add_argument('--snmp-host', default='snmpsim', help='address of the SNMP simulator as seen by Zabbix')
    parser.add_argument('--snmp-port', default='1161')
    args = parser.parse_args()

    print('1/5 Simulated network')
    network.write(False, restart_growth=True)

    print('2/5 Connecting to %s' % args.url)
    api = Api(args.url)
    print('    Zabbix %s' % api.login(args.user, args.password))

    print('3/5 Templates and module')
    rules = {
        'template_groups': {'createMissing': True},
        'templates': {'createMissing': True, 'updateExisting': True},
        'items': {'createMissing': True, 'updateExisting': True},
        'discoveryRules': {'createMissing': True, 'updateExisting': True},
        'triggers': {'createMissing': True, 'updateExisting': True},
        'valueMaps': {'createMissing': True, 'updateExisting': True},
        'templateLinkage': {'createMissing': True}
    }

    for name in ('nettopo_lldp_snmp.yaml', 'nettopo_cdp_snmp.yaml', 'capacity_planning.yaml'):
        with open(os.path.join(TEMPLATES_DIR, name), encoding='utf-8') as f:
            api.call('configuration.import', {'format': 'yaml', 'source': f.read(), 'rules': rules})

    modules = api.call('module.get', {'output': ['moduleid', 'status', 'config'], 'filter': {'id': 'nettopo'}})

    if not modules:
        api.call('module.create', [{'id': 'nettopo', 'relative_path': 'modules/nettopo', 'status': 1,
                                    'config': {'max_hosts': 2000, 'maps': {}, 'capacity': LAB_PLANNING}}])
    else:
        # The saved maps live in the module configuration: it is kept, only the lab planning settings are added.
        update = {'moduleid': modules[0]['moduleid'], 'status': 1}
        config = modules[0].get('config') or {}

        if isinstance(config, dict) and 'capacity' not in config:
            config['capacity'] = LAB_PLANNING
            update['config'] = config

        if modules[0]['status'] != '1' or 'config' in update:
            api.call('module.update', [update])

    print('4/5 Lab devices (group %s)' % GROUP)
    groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': GROUP}})
    groupid = groups[0]['groupid'] if groups else api.call('hostgroup.create', {'name': GROUP})['groupids'][0]

    needed = sorted({t for t, _, _, _ in LAB_HOSTS.values()}
                    | {'%s - %s' % (CAPACITY, role) for _, _, _, role in LAB_HOSTS.values()}
                    | {'Network Topology LLDP by SNMP', 'Network Topology CDP by SNMP'})
    templates = {t['host']: t['templateid'] for t in api.call('template.get', {
        'output': ['host'], 'filter': {'host': needed}})}
    missing = [t for t in needed if t not in templates]

    if missing:
        raise SystemExit('Templates not found in Zabbix: %s' % ', '.join(missing))

    existing = api.call('host.get', {'output': ['hostid'], 'filter': {'host': list(LAB_HOSTS) + ['OLT-VR-01']}})

    if existing:
        api.call('host.delete', [h['hostid'] for h in existing])

    use_ip = all(part.isdigit() for part in args.snmp_host.split('.')) and args.snmp_host.count('.') == 3

    for macro, (value, description) in LAB_GLOBAL_MACROS.items():
        found = api.call('usermacro.get', {'output': ['globalmacroid'], 'globalmacro': True,
                                           'filter': {'macro': macro}})
        if found:
            api.call('usermacro.updateglobal', {'globalmacroid': found[0]['globalmacroid'], 'value': value})
        else:
            api.call('usermacro.createglobal', {'macro': macro, 'value': value, 'description': description})

    for host, (vendor, protos, level, role) in LAB_HOSTS.items():
        community = network.DEVICES[host][0]
        tpls = ([templates[vendor]] + [templates['Network Topology %s by SNMP' % p.upper()] for p in protos]
                + [templates['%s - %s' % (CAPACITY, role)]])

        api.call('host.create', {
            'host': host,
            'groups': [{'groupid': groupid}],
            'interfaces': [{
                'type': 2, 'main': 1, 'useip': 1 if use_ip else 0,
                'ip': args.snmp_host if use_ip else '', 'dns': '' if use_ip else args.snmp_host,
                'port': args.snmp_port, 'details': {'version': 2, 'community': community}
            }],
            'templates': [{'templateid': t} for t in tpls],
            'tags': [{'tag': 'topology.level', 'value': level}],
            'inventory_mode': 1,
            'macros': [
                {'macro': '{$NETTOPO.INTERVAL}', 'value': '1m'},
                {'macro': '{$NETTOPO.LOST.KEEP}', 'value': '1d'}
            ] + [{'macro': m, 'value': v} for m, v in LAB_CAPACITY_MACROS.items()]
              + [{'macro': m, 'value': v} for m, v in LAB_HOST_MACROS.get(host, {}).items()]
        })
        print('    %-14s %s + %s + capacity %s' % (host, vendor, ' + '.join('nettopo ' + p.upper() for p in protos),
                                                   role))

    # A device without LLDP, linked by a host tag (see README: "topology.link").
    api.call('host.create', {
        'host': 'OLT-VR-01',
        'groups': [{'groupid': groupid}],
        'tags': [
            {'tag': 'topology.type', 'value': 'olt'},
            {'tag': 'topology.level', 'value': '2'},
            {'tag': 'topology.link', 'value': 'PE1-VR | gpon-uplink-1 | GigabitEthernet0/3/0'}
        ]
    })
    print('    %-14s (no SNMP) linked to PE1-VR by the host tag topology.link' % 'OLT-VR-01')

    roles = {r['name']: r['roleid'] for r in api.call('role.get', {'output': ['roleid', 'name']})}

    for username, (group, permission, role, password) in LAB_USERS.items():
        found = api.call('usergroup.get', {'output': ['usrgrpid'], 'filter': {'name': group}})
        usrgrpid = found[0]['usrgrpid'] if found else api.call('usergroup.create', {
            'name': group, 'hostgroup_rights': [{'id': groupid, 'permission': permission}]})['usrgrpids'][0]

        if found:
            api.call('usergroup.update', {'usrgrpid': usrgrpid,
                                          'hostgroup_rights': [{'id': groupid, 'permission': permission}]})

        users = api.call('user.get', {'output': ['userid'], 'filter': {'username': username}})
        params = {'roleid': roles[role], 'usrgrps': [{'usrgrpid': usrgrpid}], 'lang': 'pt_BR'}

        if users:
            api.call('user.update', dict(params, userid=users[0]['userid']))
        else:
            api.call('user.create', dict(params, username=username, passwd=password))

    print('    users: %s' % ', '.join('%s / %s (%s)' % (u, p[3], p[2]) for u, p in LAB_USERS.items()))

    print('5/5 Asking the Zabbix server to poll now')
    time.sleep(20)  # let the server load the new hosts into its configuration cache

    hostids = [h['hostid'] for h in api.call('host.get', {'output': ['hostid'], 'groupids': [groupid]})]
    items = api.call('item.get', {'output': ['itemid'], 'hostids': hostids,
                                  'filter': {'key_': ['nettopo.lldp.walk', 'nettopo.cdp.walk', 'capacity.if.walk']}})
    rules_ids = api.call('discoveryrule.get', {'output': ['itemid'], 'hostids': hostids})
    ids = [i['itemid'] for i in items] + [r['itemid'] for r in rules_ids]

    for _ in range(2):
        api.call('task.create', [{'type': 6, 'request': {'itemid': itemid}} for itemid in ids])
        time.sleep(15)

    print()
    public_url = os.environ.get('LAB_PUBLIC_URL', args.url).rstrip('/')
    print('Done. Open %s (Admin / zabbix) > Monitoring > Network topology' % public_url)
    print('Filter by host group "%s". Data shows up within 1-3 minutes (interface traffic after 2 polls).' % GROUP)
    print('Capacity planning: Monitoring > Capacity planning (6 circuits; p95 and forecast after ~5-10 minutes).')
    print('Saved maps: save a public map as Admin, then open it as noc (read only) or engenharia (editor).')
    if os.environ.get('LAB_URL'):
        print('Then try:  docker compose run --rm tools python network.py break   (later: ... network.py fix)')
    else:
        print('Then try:  python3 network.py break   (and later: python3 network.py fix)')


if __name__ == '__main__':
    main()
