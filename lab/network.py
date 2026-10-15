#!/usr/bin/env python3
"""Simulated ISP network for the Network Topology lab.

Writes one snmpsim data file per device into ./snmpdata (the SNMP community selects the device):
IF-MIB interfaces with growing traffic counters, LLDP-MIB neighbor tables, CISCO-CDP-MIB on the core,
and Huawei entity sensors (CPU/memory/temperature) so the official templates raise real problems.

Capacity planning: interface descriptions carry the circuit IDs (CID:NET-RJ-0001...) and the shared
links (UPLINK/TRONCO/TRUNK), with traffic levels that put the bottleneck of each circuit in a different
segment (last mile, distribution, backbone) and one circuit whose traffic grows (forecast demo).

Usage:
    python3 network.py generate      # normal state
    python3 network.py break         # PE1-VR <-> PE12-VARJAO goes down (LLDP neighbor lost + Link down)
    python3 network.py fix           # back to normal
"""
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.environ.get('LAB_SNMPDATA') or os.path.join(HERE, 'snmpdata')

HUAWEI = 'Huawei Versatile Routing Platform Software VRP (R) software, Version 8.180 (NE40E V800R011C10SPC200)'
MIKROTIK = 'RouterOS CCR2004-1G-12S+2XS'
CISCO = 'Cisco IOS XE Software, Version 17.09.04a, ASR1001-X'

# name: (community, sysDescr, chassis MAC, management IP, [cpu %, memory %, temperature C] or None)
DEVICES = {
    'PE1-VR': ('pe1', HUAWEI, '70:7b:e8:00:00:01', '10.0.99.1', [35, 48, 41]),
    'PE3-ITATIAIA': ('pe3', HUAWEI, '70:7b:e8:00:00:03', '10.0.99.3', [41, 52, 96]),
    'PE5-QUATIS': ('pe5', HUAWEI, '70:7b:e8:00:00:05', '10.0.99.5', [97, 61, 44]),
    'PE12-VARJAO': ('pe12', HUAWEI, '70:7b:e8:00:00:12', '10.0.99.12', [22, 40, 39]),
    'PE15-PENEDO': ('pe15', MIKROTIK, '48:a9:8a:00:00:15', '10.0.99.15', None),
    'CORE-RJ-01': ('core', CISCO, '00:1e:7a:00:01:00', '10.0.99.100', None),
}

# Ports: device -> {LLDP local port number: (ifIndex, ifName, speed bps, rx bps, tx bps)}
PORTS = {
    'PE1-VR': {1: (7, 'XGigabitEthernet0/0/7', 10e9, 1.1e9, 6.0e9), 2: (8, 'XGigabitEthernet0/0/8', 10e9, 1.2e9, 0.8e9),
               3: (9, 'XGigabitEthernet0/0/9', 10e9, 2.2e9, 0.9e9), 4: (10, 'XGigabitEthernet0/0/10', 10e9, 8.6e9, 2.1e9),
               5: (20, 'XGigabitEthernet0/0/20', 100e9, 41e9, 27e9), 11: (41, 'XGigabitEthernet0/0/41', 10e9, 480e6, 210e6),
               12: (53, 'GigabitEthernet0/2/3', 1e9, 240e6, 190e6), 13: (12, 'XGigabitEthernet0/0/12', 10e9, 2.4e9, 1.9e9),
               14: (17, 'XGigabitEthernet0/0/17', 10e9, 300e6, 120e6), 15: (55, 'GigabitEthernet0/2/5', 1e9, 20e6, 5e6),
               16: (30, 'XGigabitEthernet0/0/30', 10e9, 1.5e9, 2.5e9), 19: (60, 'GigabitEthernet0/3/0', 1e9, 90e6, 650e6)},
    'PE3-ITATIAIA': {4: (4, 'XGigabitEthernet0/0/4', 10e9, 2.1e9, 8.6e9), 6: (6, 'XGigabitEthernet0/0/6', 10e9, 900e6, 400e6),
                     8: (8, 'XGigabitEthernet0/0/8', 10e9, 700e6, 300e6), 15: (15, 'XGigabitEthernet0/0/15', 10e9, 1.1e9, 0.5e9),
                     21: (21, 'XGigabitEthernet0/0/21', 10e9, 8.8e9, 2.0e9)},
    'PE5-QUATIS': {2: (2, 'XGigabitEthernet0/0/2', 10e9, 6.0e9, 1.1e9), 3: (25, 'GigabitEthernet0/0/5', 1e9, 80e6, 20e6)},
    'PE12-VARJAO': {1: (1, 'XGigabitEthernet0/0/1', 10e9, 0.9e9, 2.2e9), 3: (3, 'XGigabitEthernet0/0/3', 10e9, 0.3e9, 0.4e9)},
    'PE15-PENEDO': {1: (1, 'sfp-sfpplus1', 10e9, 0.8e9, 1.2e9), 2: (2, 'sfp-sfpplus2', 10e9, 0.4e9, 0.3e9)},
    'CORE-RJ-01': {1: (101, 'Te1/1/1', 100e9, 27e9, 41e9), 2: (102, 'Te1/1/2', 10e9, 2.0e9, 8.8e9),
                   3: (103, 'Te1/1/3', 100e9, 38e9, 52e9), 4: (104, 'Te1/1/4', 10e9, 3.6e9, 5.5e9),
                   5: (201, 'GigabitEthernet0/0/1', 1e9, 410e6, 930e6)},
}

# Physical links between simulated devices: (device A, lpn A, device B, lpn B)
LINKS = [
    ('PE1-VR', 1, 'PE5-QUATIS', 2),
    ('PE1-VR', 2, 'PE15-PENEDO', 1),
    ('PE1-VR', 3, 'PE12-VARJAO', 1),
    ('PE1-VR', 4, 'PE3-ITATIAIA', 4),
    ('PE1-VR', 5, 'CORE-RJ-01', 1),
    ('PE15-PENEDO', 2, 'PE12-VARJAO', 3),
    ('PE3-ITATIAIA', 21, 'CORE-RJ-01', 2),
]

# Interface descriptions (ifAlias): circuit IDs and shared links, as in the naming standard.
DESCRIPTIONS = {
    'CORE-RJ-01': {101: 'TRUNK PE1-VR XGE0/0/20', 102: 'TRUNK PE3-ITATIAIA XGE0/0/21', 103: 'TRONCO BB-SP-01',
                   104: 'TRONCO BB-BSB-01', 201: 'CID:NET-RJ-0005 Datacenter Norte'},
    'PE1-VR': {20: 'UPLINK CORE-RJ-01 Te1/1/1', 7: 'CID:NET-RJ-0004 Quatis', 9: 'CID:NET-RJ-0001 Varjao',
               10: 'TRONCO PE3-ITATIAIA', 12: 'CID:NET-RJ-0006 Filial SPO'},
    'PE3-ITATIAIA': {21: 'UPLINK CORE-RJ-01 Te1/1/2', 15: 'CID:NET-RJ-0003 Itatiaia', 4: 'TRONCO PE1-VR'},
    'PE5-QUATIS': {2: 'CID:NET-RJ-0004 WAN'},
    'PE12-VARJAO': {1: 'CID:NET-RJ-0001 WAN'},
    # No CID here: setup_lab.py gives the circuit by the host macros {$CIRCUIT_ID} / {$CIRCUIT_ID.IF}.
    'PE15-PENEDO': {1: 'WAN'},
}

# Traffic that grows over time (snmpsim module growth.py): (device, ifIndex, direction) ->
# (start bit/s, growth bit/s per hour, cap bit/s). Quatis download: 60% of 10G, +1.5 points per hour.
GROWTH = {
    ('PE1-VR', 7, 'tx'): (6.0e9, 0.15e9, 9.9e9),
    ('PE5-QUATIS', 2, 'rx'): (6.0e9, 0.15e9, 9.9e9),
}

# The link taken down by "break".
BREAKABLE = ('PE1-VR', 3, 'PE12-VARJAO', 1)

# Neighbors that are not monitored by Zabbix: device -> [(lpn, sysName, port, MAC, IP, sysDescr, caps hex)]
UNMONITORED = {
    'PE1-VR': [
        (11, 'ARC-VR_1.1', 'sfp-sfpplus2', '48:a9:8a:11:22:01', '10.0.99.21', MIKROTIK, '0800'),
        (12, 'BNG-VR-01', 'GigabitEthernet0/2/3', '00:e0:fc:12:34:56', '', 'Huawei NE20E-S2F', '0800'),
        (13, 'SW-SPO-04', 'XGigabitEthernet0/0/12', '70:7b:e8:aa:00:04', '', 'Huawei S6730', '2000'),
        (14, 'DMZ-SERVERS', 'bridge1/vlan100', '48:a9:8a:11:22:02', '10.20.0.1', MIKROTIK, '0800'),
        (15, 'WIFI-CONTROLLER', 'vlan200', '5c:a6:e6:00:00:01', '', 'TP-Link Omada Controller', '8000'),
        (16, 'NAT-01', 'sfp-sfpplus2', '48:a9:8a:11:22:03', '10.30.0.1', MIKROTIK, '0800'),
    ],
    'PE3-ITATIAIA': [
        (6, 'AGG-ITA-01', 'XGigabitEthernet0/0/4', '70:7b:e8:bb:00:01', '', 'Huawei S6730', '2000'),
        (8, 'AGG-ITA-02', '25GE0/2/22', '70:7b:e8:bb:00:02', '', 'Huawei S6730', '2000'),
        (15, 'SW-ITATIAIA-01', 'XGigabitEthernet0/0/2', '70:7b:e8:bb:00:03', '', 'Huawei S5735', '2000'),
    ],
    'PE5-QUATIS': [
        (3, 'AP-NOC-01', 'ether1', '48:a9:8a:33:00:01', '', 'RouterOS hAP ax3', '1000'),
    ],
    'CORE-RJ-01': [
        (3, 'BB-SP-01', 'HundredGigE0/0/0/1', '00:1e:7a:00:02:01', '10.0.98.1', 'Cisco IOS XR Software', '0014'),
        (4, 'BB-BSB-01', 'TenGigE0/0/0/2', '00:1e:7a:00:03:01', '10.0.98.2', 'Cisco IOS XR Software', '0014'),
        (5, 'DC-NORTE-FW01', 'port1', '00:09:0f:00:05:01', '', 'FortiGate-600E', '0080'),
    ],
}

# The core also speaks CDP: (local ifIndex, device ID, remote port, IP, platform)
CDP = {
    'CORE-RJ-01': [
        (101, 'PE1-VR', 'XGigabitEthernet0/0/20', '10.0.99.1', 'Huawei NE40E'),
        (102, 'PE3-ITATIAIA.rede.local', 'XGigabitEthernet0/0/21', '10.0.99.3', 'Huawei NE40E'),
    ]
}


def oid_key(oid):
    return [int(x) for x in oid.split('.')]


def text_value(text):
    """snmprec tag and value of an OCTET STRING: non-ASCII text is written as hex (UTF-8)."""
    if all(32 <= ord(ch) < 127 for ch in text):
        return '4', text
    return '4x', text.encode('utf-8').hex()


def build(device, broken, t0):
    community, descr, mac, ip, sensors = DEVICES[device]
    rec = {}

    def put(oid, tag, value):
        rec[oid] = '%s|%s|%s' % (oid, tag, value)

    put('1.3.6.1.2.1.1.1.0', '4', descr)
    put('1.3.6.1.2.1.1.2.0', '6', '1.3.6.1.4.1.2011.2.62.2.3')
    put('1.3.6.1.2.1.1.3.0', '67', '8640000')
    put('1.3.6.1.2.1.1.4.0', '4', 'noc@example.net')
    put('1.3.6.1.2.1.1.5.0', '4', device)
    put('1.3.6.1.2.1.1.6.0', '4', 'Lab')
    put('1.3.6.1.2.1.25.1.1.0', '67', '8640000')

    down_ports = set()

    if broken:
        a, lpn_a, b, lpn_b = BREAKABLE
        if device == a:
            down_ports.add(lpn_a)
        if device == b:
            down_ports.add(lpn_b)

    for lpn, (ifindex, ifname, speed, rx, tx) in PORTS[device].items():
        oper = 2 if lpn in down_ports else 1
        if oper == 2:
            rx = tx = 0

        put('1.3.6.1.2.1.2.2.1.2.%d' % ifindex, '4', ifname)
        put('1.3.6.1.2.1.2.2.1.3.%d' % ifindex, '2', '6')
        put('1.3.6.1.2.1.2.2.1.5.%d' % ifindex, '66', str(int(min(speed, 4294967295))))
        put('1.3.6.1.2.1.2.2.1.7.%d' % ifindex, '2', '1')
        put('1.3.6.1.2.1.2.2.1.8.%d' % ifindex, '2', str(oper))
        for column in (13, 14, 19, 20):
            put('1.3.6.1.2.1.2.2.1.%d.%d' % (column, ifindex), '65', '0')
        put('1.3.6.1.2.1.31.1.1.1.1.%d' % ifindex, '4', ifname)
        for column, direction, rate in ((6, 'rx', rx), (10, 'tx', tx)):
            oid = '1.3.6.1.2.1.31.1.1.1.%d.%d' % (column, ifindex)
            growth = GROWTH.get((device, ifindex, direction))
            if growth is not None and oper == 1:
                start, per_hour, cap = growth
                put(oid, '70:growth', 't0=%d,start=%d,growth=%d,cap=%d' % (t0, start, per_hour, cap))
            else:
                put(oid, '70:numeric', 'rate=%d,initial=1000000' % int(rate / 8))
        put('1.3.6.1.2.1.31.1.1.1.15.%d' % ifindex, '66', str(int(speed / 1e6)))
        put('1.3.6.1.2.1.31.1.1.1.18.%d' % ifindex, *text_value(DESCRIPTIONS.get(device, {}).get(ifindex, '')))

        put('1.0.8802.1.1.2.1.3.7.1.2.%d' % lpn, '2', '5')
        put('1.0.8802.1.1.2.1.3.7.1.3.%d' % lpn, '4', ifname)
        put('1.0.8802.1.1.2.1.3.7.1.4.%d' % lpn, '4', ifname)

    neighbors = []

    for a, lpn_a, b, lpn_b in LINKS:
        for local, lpn, remote, rlpn in ((a, lpn_a, b, lpn_b), (b, lpn_b, a, lpn_a)):
            if local == device and lpn not in down_ports:
                r_comm, r_descr, r_mac, r_ip, _ = DEVICES[remote]
                neighbors.append((lpn, remote, PORTS[remote][rlpn][1], r_mac, r_ip, r_descr, '2800'))

    neighbors += [n for n in UNMONITORED.get(device, []) if n[0] not in down_ports]

    for ri, (lpn, name, port, n_mac, n_ip, n_descr, caps) in enumerate(neighbors, start=1):
        idx = '0.%d.%d' % (lpn, ri)
        put('1.0.8802.1.1.2.1.4.1.1.4.%s' % idx, '2', '4')
        put('1.0.8802.1.1.2.1.4.1.1.5.%s' % idx, '4x', n_mac.replace(':', ''))
        put('1.0.8802.1.1.2.1.4.1.1.6.%s' % idx, '2', '5')
        put('1.0.8802.1.1.2.1.4.1.1.7.%s' % idx, '4', port)
        put('1.0.8802.1.1.2.1.4.1.1.9.%s' % idx, '4', name)
        put('1.0.8802.1.1.2.1.4.1.1.10.%s' % idx, '4', n_descr)
        put('1.0.8802.1.1.2.1.4.1.1.12.%s' % idx, '4x', caps)
        if n_ip:
            put('1.0.8802.1.1.2.1.4.2.1.3.%s.1.4.%s' % (idx, n_ip), '2', '2')

    for di, (ifindex, dev_id, port, n_ip, platform) in enumerate(CDP.get(device, []), start=1):
        idx = '%d.%d' % (ifindex, di)
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.3.%s' % idx, '2', '1')
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.4.%s' % idx, '4x', ''.join('%02x' % int(x) for x in n_ip.split('.')))
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.6.%s' % idx, '4', dev_id)
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.7.%s' % idx, '4', port)
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.8.%s' % idx, '4', platform)
        put('1.3.6.1.4.1.9.9.23.1.2.1.1.9.%s' % idx, '4x', '00000029')

    if sensors is not None:
        # ENTITY-MIB chassis + main processing unit, Huawei entity sensors for the MPU.
        cpu, memory, temperature = sensors
        for ent, (cls, name) in {67108867: (3, 'NE40E Chassis'), 67108873: (9, 'MPU Board 9')}.items():
            put('1.3.6.1.2.1.47.1.1.1.1.2.%d' % ent, '4', name)
            put('1.3.6.1.2.1.47.1.1.1.1.5.%d' % ent, '2', str(cls))
            put('1.3.6.1.2.1.47.1.1.1.1.7.%d' % ent, '4', name)
            put('1.3.6.1.2.1.47.1.1.1.1.8.%d' % ent, '4', 'REV A')
            put('1.3.6.1.2.1.47.1.1.1.1.10.%d' % ent, '4', 'V800R011C10SPC200')
            put('1.3.6.1.2.1.47.1.1.1.1.11.%d' % ent, '4', '2102351931DM%06d' % (ent % 1000000))
        put('1.3.6.1.4.1.2011.5.25.31.1.1.1.1.5.67108873', '2', str(cpu))
        put('1.3.6.1.4.1.2011.5.25.31.1.1.1.1.7.67108873', '2', str(memory))
        put('1.3.6.1.4.1.2011.5.25.31.1.1.1.1.11.67108873', '2', str(temperature))

    return '\n'.join(rec[oid] for oid in sorted(rec, key=oid_key)) + '\n'


def write(broken, data_dir=None, restart_growth=False):
    data_dir = data_dir or DATA
    os.makedirs(data_dir, exist_ok=True)
    t0 = growth_start(data_dir, restart_growth)

    for device, (community, *_rest) in DEVICES.items():
        path = os.path.join(data_dir, community + '.snmprec')
        tmp = path + '.tmp'
        with open(tmp, 'w') as f:
            f.write(build(device, broken, t0))
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)

    with open(os.path.join(data_dir, '.state'), 'w') as f:
        f.write('broken' if broken else 'ok')


def growth_start(data_dir, restart=False):
    """Start time of the growing traffic: kept across "break"/"fix" so the trend is not reset."""
    path = os.path.join(data_dir, '.growth_t0')

    try:
        if restart:
            raise OSError('restart')
        with open(path) as f:
            return int(f.read().strip())
    except (OSError, ValueError):
        t0 = int(time.time())
        with open(path, 'w') as f:
            f.write(str(t0))
        return t0


def main():
    action = sys.argv[1] if len(sys.argv) > 1 else 'generate'

    if action in ('generate', 'fix'):
        write(False)
        print('Simulated network: normal (all links up).')
    elif action == 'break':
        write(True)
        a, _, b, _ = BREAKABLE
        print('Simulated network: link %s <-> %s is DOWN. Zabbix notices it at the next poll (about 1-2 min).' % (a, b))
    else:
        print(__doc__)
        sys.exit(1)


if __name__ == '__main__':
    main()
