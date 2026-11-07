#!/usr/bin/env python3
"""Builds the Zabbix templates (YAML, export format 7.0) used by the nettopo module.

The JavaScript preprocessing steps live in templates/src/*.js (tested with tests/run_preproc.js)
and are embedded here, so the YAML always ships the tested code.
UUIDs are deterministic (uuid5) so re-importing an updated template updates it in place.
"""
import os
import uuid

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
NS = uuid.UUID('6f1d6a52-7a3e-4a8e-9a39-2f3f0c6d1e10')
GROUP = 'Templates/Network devices'
VERSION = '1.0'
# Capacity planning templates: 1.2 adds the host macro {$CIRCUIT_ID} and the item tag cid_source; 1.3 adds the
# 30-day average, the monthly growth, the item tag alias and the link from the problems to the module page;
# 1.4 gives the templates neutral names ("Template Capacity Planning ..."); 1.4.1 no longer fills the inventory
# field "Type" (vendor templates such as Huawei's already use it, and Zabbix allows one item per field).
CAP_VERSION = '1.6.0'


def uid(path):
    # Deterministic, but shaped as UUIDv4 (Zabbix import requires version 4).
    b = bytearray(uuid.uuid5(NS, 'nettopo/' + path).bytes)
    b[6] = (b[6] & 0x0F) | 0x40
    b[8] = (b[8] & 0x3F) | 0x80
    return uuid.UUID(bytes=bytes(b)).hex


def js(name):
    with open(os.path.join(HERE, 'src', name), encoding='utf-8') as f:
        # Tabs are expanded so the code can be emitted as a readable YAML literal block.
        return f.read().expandtabs(4).rstrip() + '\n'


class Literal(str):
    pass


def literal_representer(dumper, data):
    return dumper.represent_scalar('tag:yaml.org,2002:str', data, style='|')


class Dumper(yaml.SafeDumper):
    # Repeated values (tags shared by many items) are written out, not as YAML anchors/aliases.
    def ignore_aliases(self, data):
        return True


yaml.add_representer(Literal, literal_representer, Dumper=Dumper)


def neighbor_template(proto, title, template_name, walk_oids, walk_desc, script):
    t = template_name
    p = proto.lower()
    walk_key = 'nettopo.%s.walk' % p
    json_key = 'nettopo.%s.neighbors' % p

    return {
        'uuid': uid(t),
        'template': t,
        'name': t,
        'description': Literal(
            'Network Topology (nettopo) - %s neighbors by SNMP.\n\n'
            'Collects the %s neighbor table and publishes it as JSON in the item "%s",\n'
            'which the Zabbix frontend module "Network Topology" uses to draw links between hosts.\n'
            'Each neighbor is also discovered (LLD) so a lost neighbor is detected and can raise a problem.\n\n'
            'Walked: %s\n\n'
            'Macros:\n'
            '{$NETTOPO.INTERVAL} - polling interval of the neighbor table.\n'
            '{$NETTOPO.LOST.KEEP} - how long a lost neighbor is kept (shown as a down link) before it is removed.\n'
            '{$NETTOPO.LOST.ALERT} - set to 0 to stop the "neighbor lost" problems (links are still drawn as down).\n'
            % (title, title, json_key, walk_desc)
        ),
        'vendor': {'name': 'nettopo', 'version': VERSION},
        'groups': [{'name': GROUP}],
        'items': [
            {
                'uuid': uid(t + '/' + walk_key),
                'name': '%s: neighbor table (raw walk)' % title,
                'type': 'SNMP_AGENT',
                'snmp_oid': 'walk[%s]' % ','.join(walk_oids),
                'key': walk_key,
                'delay': '{$NETTOPO.INTERVAL}',
                'history': '0',
                'value_type': 'TEXT',
                'description': 'Raw SNMP walk of the %s tables. Not stored, used as master item.' % title,
                'tags': [{'tag': 'component', 'value': 'topology'}],
            },
            {
                'uuid': uid(t + '/' + json_key),
                'name': '%s: neighbors' % title,
                'type': 'DEPENDENT',
                'key': json_key,
                'delay': '0',
                'history': '7d',
                'value_type': 'TEXT',
                'description': Literal(
                    'Current %s neighbors as a JSON array (read by the Network Topology module).\n'
                    'Fields: k=key, lp=local port, li=local ifIndex, rn=remote name, rc=remote chassis ID,\n'
                    'rp=remote port, rpd=remote port description, ra=remote management IPs, rd=remote description,\n'
                    'cap=remote capabilities.\n' % title
                ),
                'preprocessing': [
                    {'type': 'JAVASCRIPT', 'parameters': [Literal(js(script))]},
                    {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['1h']},
                ],
                'master_item': {'key': walk_key},
                'tags': [{'tag': 'component', 'value': 'topology'}],
            },
        ],
        'discovery_rules': [
            {
                'uuid': uid(t + '/nettopo.%s.discovery' % p),
                'name': '%s neighbors discovery' % title,
                'type': 'DEPENDENT',
                'key': 'nettopo.%s.discovery' % p,
                'delay': '0',
                'lifetime_type': 'DELETE_AFTER',
                'lifetime': '{$NETTOPO.LOST.KEEP}',
                'enabled_lifetime_type': 'DISABLE_NEVER',
                'description': 'One entity per %s neighbor. Lost neighbors are kept for {$NETTOPO.LOST.KEEP}.' % title,
                'item_prototypes': [
                    {
                        'uuid': uid(t + '/nettopo.%s.neighbor' % p),
                        'name': '%s neighbor {#NT.REMOTE.NAME} ({#NT.REMOTE.PORT}) on {#NT.LOCAL.PORT}' % title,
                        'type': 'DEPENDENT',
                        'key': 'nettopo.%s.neighbor[{#NT.KEY}]' % p,
                        'delay': '0',
                        'history': '7d',
                        'trends': '0',
                        'value_type': 'UNSIGNED',
                        'valuemap': {'name': 'nettopo neighbor state'},
                        'description': '1 = neighbor present, 0 = neighbor no longer seen on this port.',
                        'preprocessing': [
                            {
                                'type': 'JSONPATH',
                                'parameters': ['$[?(@.k == "{#NT.KEY}")].length()'],
                                'error_handler': 'CUSTOM_VALUE',
                                'error_handler_params': '0',
                            },
                        ],
                        'master_item': {'key': json_key},
                        'tags': [
                            {'tag': 'component', 'value': 'topology'},
                            {'tag': 'nt.ifindex', 'value': '{#NT.LOCAL.IFINDEX}'},
                            {'tag': 'nt.local', 'value': '{#NT.LOCAL.PORT}'},
                            {'tag': 'nt.proto', 'value': p},
                            {'tag': 'nt.rchassis', 'value': '{#NT.REMOTE.CHASSIS}'},
                            {'tag': 'nt.remote', 'value': '{#NT.REMOTE.NAME}'},
                            {'tag': 'nt.rip', 'value': '{#NT.REMOTE.IP}'},
                            {'tag': 'nt.rport', 'value': '{#NT.REMOTE.PORT}'},
                        ],
                        'trigger_prototypes': [
                            {
                                'uuid': uid(t + '/nettopo.%s.neighbor/lost' % p),
                                'expression': 'last(/%s/nettopo.%s.neighbor[{#NT.KEY}])=0 and {$NETTOPO.LOST.ALERT}=1'
                                              % (t, p),
                                'name': '%s: neighbor {#NT.REMOTE.NAME} lost on {#NT.LOCAL.PORT}' % title,
                                'opdata': 'Remote port: {#NT.REMOTE.PORT}',
                                'priority': 'WARNING',
                                'description': 'The %s neighbor is no longer seen on this port (link down, '
                                               'cable moved or neighbor replaced).' % title,
                                'manual_close': 'YES',
                                'tags': [
                                    {'tag': 'nettopo', 'value': 'lost'},
                                    {'tag': 'scope', 'value': 'availability'},
                                ],
                            },
                        ],
                    },
                ],
                'master_item': {'key': json_key},
                'preprocessing': [
                    {'type': 'JAVASCRIPT', 'parameters': [Literal(js('neighbors_lld.js'))]},
                ],
            },
        ],
        'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'topology'}],
        'macros': [
            {'macro': '{$NETTOPO.INTERVAL}', 'value': '5m', 'description': 'Polling interval of the neighbor table.'},
            {'macro': '{$NETTOPO.LOST.ALERT}', 'value': '1',
             'description': 'Set to 0 to disable the "neighbor lost" trigger.'},
            {'macro': '{$NETTOPO.LOST.KEEP}', 'value': '7d',
             'description': 'How long a lost neighbor is kept (drawn as a down link) before being removed.'},
        ],
        'valuemaps': [
            {
                'uuid': uid(t + '/valuemap'),
                'name': 'nettopo neighbor state',
                'mappings': [{'value': '0', 'newvalue': 'Lost'}, {'value': '1', 'newvalue': 'Present'}],
            },
        ],
    }


def build(template, filename, group=GROUP):
    templates = template if isinstance(template, list) else [template]
    doc = {'zabbix_export': {
        'version': '7.0',
        'template_groups': [{'uuid': uid('group/' + group), 'name': group}],
        'templates': templates,
    }}
    path = os.path.join(HERE, filename)
    with open(path, 'w', encoding='utf-8') as f:
        yaml.dump(doc, f, Dumper=Dumper, sort_keys=False, allow_unicode=True, width=1000)
    print('written', path)


# ---------------------------------------------------------------------------------------------------
# Capacity planning: layer 1 (base template) and layer 2 (role profiles).
# ---------------------------------------------------------------------------------------------------

CAP_GROUP = 'Templates/Capacity Planning'
CAP_BASE = 'Template Capacity Planning'

# (template suffix, segment code, utilization warning %, critical %, description)
CAP_ROLES = [
    ('Backbone', 'backbone', '60', '80',
     'Roteadores core, enlaces troncais e interfaces de backbone. Limites mais baixos: o backbone precisa de folga '
     'para absorver a queda de um tronco.'),
    ('Distribuicao', 'distribuicao', '70', '85',
     'Switches/roteadores de agregação, equipamentos metro e distribuição regional.'),
    ('CPE', 'cpe', '80', '95',
     'CPE do cliente e interfaces de última milha (normalmente limitadas pela banda contratada).'),
]

CAP_WALK_OIDS = [
    '1.3.6.1.2.1.31.1.1.1.1',   # ifName
    '1.3.6.1.2.1.31.1.1.1.18',  # ifAlias (description)
    '1.3.6.1.2.1.31.1.1.1.15',  # ifHighSpeed
    '1.3.6.1.2.1.2.2.1.2',      # ifDescr
    '1.3.6.1.2.1.1.5',          # sysName
    '1.3.6.1.2.1.4.20.1.2',     # ipAdEntIfIndex (IPv4 address -> interface)
    '1.3.6.1.2.1.4.20.1.3',     # ipAdEntNetMask
]

CAP_SEGMENT_JS = r"""// Role of the device for capacity planning.
var raw = String('{$CAPACITY.SEGMENT}');
var v = raw.toLowerCase()
    .replace(/[à-å]/g, 'a').replace(/ç/g, 'c').replace(/[è-ë]/g, 'e')
    .replace(/[ì-ï]/g, 'i').replace(/[ò-ö]/g, 'o').replace(/[ù-ü]/g, 'u');

if (/^(backbone|core|nucleo)$/.test(v)) {
    return 'Backbone';
}
if (/^(distribuicao|distribution|metro|agregacao|aggregation)$/.test(v)) {
    return 'Distribuição';
}
if (/^(cpe|acesso|access|cliente|customer)$/.test(v)) {
    return 'CPE';
}

// Empty (or "auto"): the discovery finds the role from the data (core trunks, number of circuits).
if (raw === '' || raw.indexOf('{$') === 0 || /^(auto|automatico)$/.test(v)) {
    return 'Automático';
}

return 'Indefinido';
"""

CAP_TAGS = [
    {'tag': 'alias', 'value': '{#IFALIAS}'},
    {'tag': 'cid_source', 'value': '{#CID_SOURCE}'},
    {'tag': 'circuit', 'value': '{#CIRCUITS}'},
    {'tag': 'component', 'value': 'capacity'},
    {'tag': 'interface', 'value': '{#IFNAME}'},
    {'tag': 'link', 'value': '{#LINK}'},
    {'tag': 'segment', 'value': '{#SEGMENT}'},
]


def cap_item(t, key, name, metric, description, **fields):
    item = {
        'uuid': uid(t + '/' + key),
        'name': 'Capacidade {#IFNAME} ({#CIRCUIT_LABEL}): ' + name,
        'key': key,
        'description': description,
        'tags': sorted(CAP_TAGS + [{'tag': 'capacity', 'value': metric}], key=lambda x: x['tag']),
    }
    item.update(fields)
    return item


def capacity_base_template():
    t = CAP_BASE
    ref = '/' + t + '/'
    crit_macro = '{$CAPACITY.UTIL.CRIT:"{#CIRCUIT_ID}"}'
    warn_macro = '{$CAPACITY.UTIL.WARN:"{#CIRCUIT_ID}"}'
    # Limit in force: {$CAPACITY.UTIL.CRIT} (by circuit, host or global) when it is not 0, else the limit of the
    # role found by the discovery ({#UTIL_CRIT}). Constant arithmetic, valid in trigger expressions.
    crit = '((%s=0)*{#UTIL_CRIT}+%s)' % (crit_macro, crit_macro)
    warn = '((%s=0)*{#UTIL_WARN}+%s)' % (warn_macro, warn_macro)
    bw = '{$CAPACITY.BW.MBPS:"{#BW_KEY}"}'
    # Problem -> circuit: the module page finds the interface by host and SNMP index (one circuit: its detail).
    url = 'zabbix.php?action=nettopo.capacity&hostid={HOST.ID}&snmpindex={#SNMPINDEX}'
    url_name = 'Planejamento de capacidade: {#CIRCUIT_LABEL}'

    def trigger_tags(kind):
        return [
            {'tag': 'capacity', 'value': kind},
            {'tag': 'circuit', 'value': '{#CIRCUITS}'},
            {'tag': 'component', 'value': 'capacity'},
            {'tag': 'interface', 'value': '{#IFNAME}'},
            {'tag': 'scope', 'value': 'capacity'},
            {'tag': 'segment', 'value': '{#SEGMENT}'},
        ]
    p95_key = 'capacity.if.util.p95[{#SNMPINDEX}]'
    days_key = 'capacity.if.days_left[{#SNMPINDEX}]'
    # Growth of the p95 over the forecast horizon: below {$CAPACITY.TREND.MIN} points it is noise, not a trend.
    growth = '(last(//capacity.if.util.forecast[{#SNMPINDEX}])-last(//%s))' % p95_key
    # timeleft() takes a constant limit: one call per source of the limit, the unused one weighted by 0.
    days_expr = ('((%s=0)*timeleft(//%s,{$CAPACITY.TREND.PERIOD},{#UTIL_CRIT})/86400'
                 '+(%s>0)*timeleft(//%s,{$CAPACITY.TREND.PERIOD},%s)/86400)'
                 % (crit_macro, p95_key, crit_macro, p95_key, crit_macro))
    high_name = 'Capacidade {#SEGMENT_LABEL}: {#IFNAME} ({#CIRCUIT_LABEL}) com p95 acima do limite crítico'
    high_expr = 'last(%s%s)>=%s' % (ref, p95_key, crit)

    item_prototypes = [
        cap_item(t, 'capacity.if.in[{#SNMPINDEX}]', 'tráfego de entrada', 'in',
                 'Bits por segundo recebidos (IF-MIB ifHCInOctets).',
                 type='SNMP_AGENT', snmp_oid='1.3.6.1.2.1.31.1.1.1.6.{#SNMPINDEX}', delay='{$CAPACITY.INTERVAL}',
                 history='7d', value_type='UNSIGNED', units='bps',
                 preprocessing=[{'type': 'CHANGE_PER_SECOND', 'parameters': ['']},
                                {'type': 'MULTIPLIER', 'parameters': ['8']}]),
        cap_item(t, 'capacity.if.out[{#SNMPINDEX}]', 'tráfego de saída', 'out',
                 'Bits por segundo enviados (IF-MIB ifHCOutOctets).',
                 type='SNMP_AGENT', snmp_oid='1.3.6.1.2.1.31.1.1.1.10.{#SNMPINDEX}', delay='{$CAPACITY.INTERVAL}',
                 history='7d', value_type='UNSIGNED', units='bps',
                 preprocessing=[{'type': 'CHANGE_PER_SECOND', 'parameters': ['']},
                                {'type': 'MULTIPLIER', 'parameters': ['8']}]),
        cap_item(t, 'capacity.if.speed[{#SNMPINDEX}]', 'velocidade da porta', 'speed',
                 'Velocidade informada pelo equipamento (IF-MIB ifHighSpeed).',
                 type='SNMP_AGENT', snmp_oid='1.3.6.1.2.1.31.1.1.1.15.{#SNMPINDEX}', delay='{$CAPACITY.INTERVAL}',
                 history='7d', trends='0', value_type='UNSIGNED', units='bps',
                 preprocessing=[{'type': 'MULTIPLIER', 'parameters': ['1000000']},
                                {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['1h']}]),
        cap_item(t, 'capacity.if.status[{#SNMPINDEX}]', 'status operacional', 'status',
                 'IF-MIB ifOperStatus.',
                 type='SNMP_AGENT', snmp_oid='1.3.6.1.2.1.2.2.1.8.{#SNMPINDEX}', delay='{$CAPACITY.INTERVAL}',
                 history='7d', trends='0', value_type='UNSIGNED', valuemap={'name': 'capacity ifOperStatus'},
                 preprocessing=[{'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['1h']}]),
        cap_item(t, 'capacity.if.bw[{#SNMPINDEX}]', 'capacidade', 'bw',
                 'Capacidade usada no cálculo, na ordem: a banda contratada ({$CAPACITY.BW.MBPS:"CIRCUIT_ID"} em '
                 'Mbps; num enlace compartilhado o contexto é o nome da interface), a banda escrita na descrição da '
                 'interface ("nome|BORDER|200M|EVC_ID#1") ou a velocidade da porta.',
                 type='CALCULATED', delay='{$CAPACITY.INTERVAL}', history='7d', trends='0', value_type='FLOAT',
                 units='bps',
                 params='(%s=0)*({#BW_DESC}*1000000+({#BW_DESC}=0)*last(//capacity.if.speed[{#SNMPINDEX}]))+%s*1000000'
                        % (bw, bw)),
        cap_item(t, 'capacity.if.util[{#SNMPINDEX}]', 'utilização', 'util',
                 'Maior sentido (entrada ou saída) dividido pela capacidade.',
                 type='CALCULATED', delay='{$CAPACITY.INTERVAL}', history='35d', value_type='FLOAT', units='%',
                 params='100*max(last(//capacity.if.in[{#SNMPINDEX}]),last(//capacity.if.out[{#SNMPINDEX}]))'
                        '/last(//capacity.if.bw[{#SNMPINDEX}])'),
        cap_item(t, p95_key, 'utilização p95', 'p95',
                 'Percentil 95 da utilização em {$CAPACITY.P95.PERIOD}: o uso "normal de pico", sem os picos raros.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='%',
                 params='percentile(//capacity.if.util[{#SNMPINDEX}],{$CAPACITY.P95.PERIOD},95)',
                 trigger_prototypes=[
                     {
                         'uuid': uid(t + '/trigger/p95.crit'),
                         'expression': high_expr,
                         'name': high_name,
                         'opdata': 'p95: {ITEM.LASTVALUE1}',
                         'url': url,
                         'url_name': url_name,
                         'priority': 'HIGH',
                         'description': 'O p95 de utilização passou do limite crítico: o do papel do equipamento '
                                        '({#SEGMENT_LABEL}: {#UTIL_CRIT}%), ou {$CAPACITY.UTIL.CRIT} quando '
                                        'definido (por circuito: {$CAPACITY.UTIL.CRIT:"CIRCUIT_ID"}).',
                         'tags': trigger_tags('p95'),
                     },
                     {
                         'uuid': uid(t + '/trigger/p95.warn'),
                         'expression': 'last(%s%s)>=%s' % (ref, p95_key, warn),
                         'name': 'Capacidade {#SEGMENT_LABEL}: {#IFNAME} ({#CIRCUIT_LABEL}) com p95 acima do '
                                 'limite de atenção',
                         'opdata': 'p95: {ITEM.LASTVALUE1}',
                         'url': url,
                         'url_name': url_name,
                         'priority': 'WARNING',
                         'description': 'O p95 de utilização passou do limite de atenção ({#SEGMENT_LABEL}: '
                                        '{#UTIL_WARN}%, ou {$CAPACITY.UTIL.WARN} quando definido). Hora de planejar '
                                        'aumento.',
                         'dependencies': [{'name': high_name, 'expression': high_expr}],
                         'tags': trigger_tags('p95'),
                     },
                 ]),
        cap_item(t, 'capacity.if.util.peak[{#SNMPINDEX}]', 'pico em 24h', 'peak',
                 'Maior utilização nas últimas 24 horas.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='%',
                 params='max(//capacity.if.util[{#SNMPINDEX}],1d)'),
        cap_item(t, 'capacity.if.util.avg[{#SNMPINDEX}]', 'utilização média', 'avg',
                 'Média da utilização em {$CAPACITY.AVG.PERIOD} (padrão 30 dias): o uso típico, para comparar com o '
                 'atual e o p95.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='%',
                 params='avg(//capacity.if.util[{#SNMPINDEX}],{$CAPACITY.AVG.PERIOD})'),
        cap_item(t, 'capacity.if.growth[{#SNMPINDEX}]', 'crescimento do p95 em 30 dias', 'growth',
                 'Quanto o p95 sobe em 30 dias (pontos percentuais), pela reta de tendência de '
                 '{$CAPACITY.TREND.PERIOD}. Base do crescimento mensal e da capacidade recomendada.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='p.p.',
                 params='forecast(//%s,{$CAPACITY.TREND.PERIOD},30d)-forecast(//%s,{$CAPACITY.TREND.PERIOD},0)'
                        % (p95_key, p95_key)),
        cap_item(t, 'capacity.if.util.forecast[{#SNMPINDEX}]', 'p95 previsto', 'forecast',
                 'p95 previsto daqui a {$CAPACITY.FORECAST.HORIZON}, pela tendência de {$CAPACITY.TREND.PERIOD}.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='%',
                 params='forecast(//%s,{$CAPACITY.TREND.PERIOD},{$CAPACITY.FORECAST.HORIZON})' % p95_key),
        cap_item(t, days_key, 'dias até o limite crítico', 'days',
                 'Dias até o p95 chegar ao limite crítico, pela tendência de {$CAPACITY.TREND.PERIOD}. '
                 '3650 = sem tendência de alta (o p95 previsto sobe menos de {$CAPACITY.TREND.MIN} ponto(s) '
                 'percentual(is) em {$CAPACITY.FORECAST.HORIZON}); 0 = subindo e já no limite (a linha de '
                 'tendência já passou do limite); negativo = dados insuficientes.',
                 type='CALCULATED', delay='{$CAPACITY.CALC.INTERVAL}', history='400d', trends='0',
                 value_type='FLOAT', units='dias',
                 # timeleft() answers "never" (DBL_MAX) when the trend line crossed the limit in the past:
                 # with a real upward trend that means "already there", so it becomes 0.
                 params='(%s<{$CAPACITY.TREND.MIN})*3650+(%s>={$CAPACITY.TREND.MIN})*(%s<3650)*%s' % (
                     growth, growth, days_expr, days_expr)),
    ]

    return {
        'uuid': uid(t),
        'template': t,
        'name': t,
        'description': Literal(
            'Capacity planning - camada 1 (template base). Pode ser vinculado direto a todos os roteadores (por exemplo\n'
            'dentro de um template que todos já usam): o papel de cada um é descoberto pelos dados. Quem tem tronco\n'
            'com o campo CORE na descrição é backbone; quem agrega {$CAPACITY.ROLE.DIST.MIN} ou mais circuitos é\n'
            'distribuição; quem tem menos é CPE. Os perfis "%s - Backbone", "- Distribuicao" e "- CPE" (ou a macro\n'
            '{$CAPACITY.SEGMENT} no host) forçam um papel nas exceções.\n\n'
            'Descobre as interfaces cuja descrição (ifAlias) tem o identificador do circuito, por exemplo\n'
            '"CID:NET-TO-0001" (vários circuitos: "CID:NET-TO-0001,NET-TO-0002"; a palavra antes do separador é\n'
            '{$CAPACITY.CID.PREFIX}, que aceita lista: CID,EVC_ID lê "EVC_ID#40137877"), ou uma palavra de enlace\n'
            'compartilhado (UPLINK, TRONCO, TRUNK) ou um campo inteiro de uma descrição separada por "|"\n'
            '({$CAPACITY.SHARED.FIELDS}=CORE). A banda escrita na descrição ("nome|BORDER|200M|EVC_ID#1") vira a\n'
            'capacidade da interface (também com chave: "B:1G"). Para cada uma coleta tráfego, capacidade, utilização, p95,\n'
            'pico de 24h, média de 30 dias, crescimento mensal e a previsão de saturação, com as tags circuit,\n'
            'segment, link, interface e alias. Os problemas trazem um link para o circuito no módulo.\n'
            'Nos backbones descobre também as interfaces com IP /30 ou /31 (tag link=p2p): são a ponta do core dos\n'
            'enlaces com as distribuições, que o módulo acha pelos endereços (item capacity.ip.p2p), sem LLDP/CDP.\n'
            'Opcional, como macro global (Administração > Macros): {$CAPACITY.SHARED.EXCLUDE}=TEMPORARIO,BACKUP tira da\n'
            'conta os enlaces com essas palavras na descrição (não entram como tronco, uplink nem ponto a ponto).\n'
            'O módulo "Network Topology" usa essas tags para montar o caminho de cada circuito e apontar o gargalo.\n\n'
            'Opcional: com {$CAPACITY.NETBOX.URL} preenchido, interfaces sem CID na descrição buscam o CID\n'
            'no NetBox (campo personalizado {$CAPACITY.NETBOX.FIELD} da interface ou a descrição dela).\n\n'
            'Opcional: a macro de host {$CIRCUIT_ID} informa o circuito do equipamento inteiro (normalmente\n'
            'o CPE). Ele vai para as interfaces listadas em {$CIRCUIT_ID.IF} (nomes separados por vírgula,\n'
            '* vale qualquer coisa: ether1, GigabitEthernet0/0/0, sfp-sfpplus*). A tag cid_source mostra de\n'
            'onde veio o CID de cada interface: descricao, netbox ou macro.\n'
            % t
        ),
        'vendor': {'name': 'nettopo', 'version': CAP_VERSION},
        'groups': [{'name': CAP_GROUP}],
        'items': [
            {
                'uuid': uid(t + '/capacity.if.walk'),
                'name': 'Capacidade: interfaces (walk)',
                'type': 'SNMP_AGENT',
                'snmp_oid': 'walk[%s]' % ','.join(CAP_WALK_OIDS),
                'key': 'capacity.if.walk',
                'delay': '{$CAPACITY.LLD.INTERVAL}',
                'history': '0',
                'value_type': 'TEXT',
                'description': 'ifName, ifAlias, ifHighSpeed, ifDescr, sysName e os endereços IPv4 (IP-MIB). Não é '
                               'guardado: alimenta a descoberta.',
                'tags': [{'tag': 'component', 'value': 'capacity'}],
            },
            {
                'uuid': uid(t + '/capacity.ip.p2p'),
                'name': 'Capacidade: endereços ponto a ponto',
                'type': 'DEPENDENT',
                'key': 'capacity.ip.p2p',
                'delay': '0',
                'history': '1d',
                'value_type': 'TEXT',
                'description': 'Endereços IPv4 /30 e /31 do equipamento e a interface de cada um (IP-MIB). O módulo '
                               'liga duas interfaces de equipamentos diferentes que estão no mesmo /30 ou /31: '
                               'descobre quem liga em quem sem LLDP/CDP.',
                'preprocessing': [
                    {'type': 'JAVASCRIPT', 'parameters': [Literal(js('capacity_ip.js'))]},
                    # The module reads the last value of the last 24 hours.
                    {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['6h']},
                ],
                'master_item': {'key': 'capacity.if.walk'},
                'tags': [{'tag': 'component', 'value': 'capacity'}],
            },
            {
                'uuid': uid(t + '/capacity.segment'),
                'name': 'Capacidade: papel do equipamento',
                'type': 'DEPENDENT',
                'key': 'capacity.segment',
                'delay': '0',
                'history': '7d',
                'value_type': 'CHAR',
                'description': 'Backbone, Distribuição ou CPE quando {$CAPACITY.SEGMENT} está definido (perfil ou '
                               'host); "Automático" quando a descoberta acha o papel pelos dados.',
                'preprocessing': [
                    {'type': 'JAVASCRIPT', 'parameters': [Literal(CAP_SEGMENT_JS)]},
                    {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['1d']},
                ],
                'master_item': {'key': 'capacity.if.walk'},
                'tags': [{'tag': 'component', 'value': 'capacity'}],
            },
        ],
        'discovery_rules': [
            {
                'uuid': uid(t + '/capacity.if.discovery'),
                'name': 'Capacidade: interfaces de circuitos',
                'type': 'DEPENDENT',
                'key': 'capacity.if.discovery',
                'delay': '0',
                'lifetime_type': 'DELETE_AFTER',
                'lifetime': '{$CAPACITY.LOST.KEEP}',
                'enabled_lifetime_type': 'DISABLE_NEVER',
                'description': 'Uma entidade por interface com CID ou palavra de enlace compartilhado na descrição.',
                'item_prototypes': item_prototypes,
                'trigger_prototypes': [
                    {
                        'uuid': uid(t + '/trigger/forecast'),
                        'expression': 'last(%s%s)>=0 and last(%s%s)<{$CAPACITY.FORECAST.DAYS} and last(%s%s)<%s'
                                      % (ref, days_key, ref, days_key, ref, p95_key, crit),
                        'name': 'Capacidade {#SEGMENT_LABEL}: {#IFNAME} ({#CIRCUIT_LABEL}) deve chegar ao limite '
                                'crítico em menos de {$CAPACITY.FORECAST.DAYS} dias',
                        'opdata': 'Faltam {ITEM.LASTVALUE1}',
                        'url': url,
                        'url_name': url_name,
                        'priority': 'INFO',
                        'description': 'Pela tendência de {$CAPACITY.TREND.PERIOD}, o p95 chega ao limite crítico '
                                       'antes de {$CAPACITY.FORECAST.DAYS} dias.',
                        'manual_close': 'YES',
                        'tags': trigger_tags('forecast'),
                    },
                ],
                'master_item': {'key': 'capacity.if.walk'},
                'preprocessing': [
                    {'type': 'JAVASCRIPT', 'parameters': [Literal(js('capacity_lld.js'))]},
                    # Template changes reach the discovered items within the hour.
                    {'type': 'DISCARD_UNCHANGED_HEARTBEAT', 'parameters': ['1h']},
                ],
            },
        ],
        'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'capacity'}],
        'macros': [
            {'macro': '{$CAPACITY.AVG.PERIOD}', 'value': '30d',
             'description': 'Janela da utilização média.'},
            {'macro': '{$CAPACITY.BW.MBPS}', 'value': '0',
             'description': 'Banda contratada em Mbps (0 = usar a velocidade da porta). Use contexto por circuito: '
                            '{$CAPACITY.BW.MBPS:"NET-TO-0001"}=300 (pode ser macro global).'},
            {'macro': '{$CAPACITY.BW.FROM.DESC}', 'value': '1',
             'description': 'Usar a banda escrita na descrição da interface (campo inteiro separado por "|": 100M, '
                            '1G, 10G) como capacidade quando não houver {$CAPACITY.BW.MBPS}. 0 = usar a velocidade '
                            'da porta.'},
            {'macro': '{$CAPACITY.CALC.INTERVAL}', 'value': '1h',
             'description': 'Intervalo dos cálculos de p95, pico e previsão.'},
            {'macro': '{$CAPACITY.CID.PREFIX}', 'value': 'CID,EVC_ID,EID',
             'description': 'Palavra(s) antes do identificador na descrição da interface, separadas por vírgula: '
                            'CID:NET-TO-0001, EVC_ID#40137877 ou EID=7604002.'},
            {'macro': '{$CAPACITY.FORECAST.DAYS}', 'value': '60',
             'description': 'Avisar quando a previsão de saturação for menor que este número de dias.'},
            {'macro': '{$CAPACITY.FORECAST.HORIZON}', 'value': '30d',
             'description': 'Daqui a quanto tempo calcular o "p95 previsto".'},
            {'macro': '{$CAPACITY.INTERVAL}', 'value': '5m',
             'description': 'Intervalo de coleta do tráfego (5 minutos é o padrão de mercado para p95).'},
            {'macro': '{$CAPACITY.LLD.INTERVAL}', 'value': '1h',
             'description': 'Intervalo de leitura das descrições das interfaces (descoberta).'},
            {'macro': '{$CAPACITY.LOST.KEEP}', 'value': '30d',
             'description': 'Por quanto tempo manter os itens de uma interface que perdeu o CID na descrição.'},
            {'macro': '{$CAPACITY.NETBOX.DEVICE}', 'value': '',
             'description': 'Nome do equipamento no NetBox (vazio = sysName informado pelo equipamento).'},
            {'macro': '{$CAPACITY.NETBOX.FIELD}', 'value': 'circuit_id',
             'description': 'Campo personalizado da interface no NetBox com o CID.'},
            {'macro': '{$CAPACITY.NETBOX.TOKEN}', 'type': 'SECRET_TEXT',
             'description': 'Token da API do NetBox ("Bearer nbt_..." para tokens v2).'},
            {'macro': '{$CAPACITY.NETBOX.URL}', 'value': '',
             'description': 'Opcional: URL do NetBox (ex.: https://netbox.exemplo). Vazio = só a descrição da interface.'},
            {'macro': '{$CAPACITY.P2P.PREFIX}', 'value': '30',
             'description': 'Máscara mínima de um enlace ponto a ponto: 30 = /30 e /31; 31 = só /31.'},
            {'macro': '{$CAPACITY.P2P.ROLES}', 'value': 'backbone',
             'description': 'Papéis em que as interfaces com IP ponto a ponto (sem circuito) também são descobertas, '
                            'separados por vírgula (backbone, distribuicao, cpe). No backbone elas são a ponta do core '
                            'dos enlaces com as distribuições.'},
            {'macro': '{$CAPACITY.P95.PERIOD}', 'value': '7d',
             'description': 'Janela do percentil 95.'},
            {'macro': '{$CAPACITY.ROLE.BACKBONE.FIELDS}', 'value': 'CORE',
             'description': 'Papel automático: o equipamento com uma interface com um destes campos inteiros na '
                            'descrição ("nome|CORE|100G|...") é backbone.'},
            {'macro': '{$CAPACITY.ROLE.CRIT}', 'value': '90',
             'description': 'Limite crítico (%) de um equipamento sem papel.'},
            {'macro': '{$CAPACITY.ROLE.CRIT:"backbone"}', 'value': '80',
             'description': 'Limite crítico (%) do backbone.'},
            {'macro': '{$CAPACITY.ROLE.CRIT:"cpe"}', 'value': '95',
             'description': 'Limite crítico (%) do CPE.'},
            {'macro': '{$CAPACITY.ROLE.CRIT:"distribuicao"}', 'value': '85',
             'description': 'Limite crítico (%) da distribuição.'},
            {'macro': '{$CAPACITY.ROLE.DIST.MIN}', 'value': '2',
             'description': 'Papel automático: com este número de circuitos ou mais (e sem tronco CORE) o equipamento '
                            'é distribuição; com menos, CPE.'},
            {'macro': '{$CAPACITY.ROLE.WARN}', 'value': '70',
             'description': 'Limite de atenção (%) de um equipamento sem papel.'},
            {'macro': '{$CAPACITY.ROLE.WARN:"backbone"}', 'value': '60',
             'description': 'Limite de atenção (%) do backbone.'},
            {'macro': '{$CAPACITY.ROLE.WARN:"cpe"}', 'value': '80',
             'description': 'Limite de atenção (%) do CPE.'},
            {'macro': '{$CAPACITY.ROLE.WARN:"distribuicao"}', 'value': '70',
             'description': 'Limite de atenção (%) da distribuição.'},
            {'macro': '{$CAPACITY.SEGMENT}', 'value': '',
             'description': 'Papel do equipamento: backbone, distribuicao ou cpe. Vazio = automático (pelos dados). '
                            'Os perfis definem; no host, corrige uma exceção.'},
            {'macro': '{$CAPACITY.SHARED.FIELDS}', 'value': 'CORE',
             'description': 'Campos inteiros (separados por "|") que marcam um enlace compartilhado: '
                            '"nome|CORE|100G|operadora" é compartilhado, "CORE-RJ-01_CORE-SP-01" não.'},
            {'macro': '{$CAPACITY.SHARED.WORDS}', 'value': 'UPLINK,TRONCO,TRUNK',
             'description': 'Palavras que marcam um enlace compartilhado por vários circuitos.'},
            {'macro': '{$CAPACITY.TREND.MIN}', 'value': '1',
             'description': 'Crescimento mínimo do p95 (pontos percentuais em {$CAPACITY.FORECAST.HORIZON}) para '
                            'considerar tendência de alta. Abaixo disso é oscilação normal.'},
            {'macro': '{$CAPACITY.TREND.PERIOD}', 'value': '30d',
             'description': 'Janela da tendência usada na previsão.'},
            {'macro': '{$CAPACITY.UTIL.CRIT}', 'value': '0',
             'description': 'Limite crítico de utilização (%). 0 = o do papel ({$CAPACITY.ROLE.CRIT:"<papel>"}). '
                            'Contexto por circuito aceito: {$CAPACITY.UTIL.CRIT:"NET-TO-0001"}=90.'},
            {'macro': '{$CAPACITY.UTIL.WARN}', 'value': '0',
             'description': 'Limite de atenção de utilização (%). 0 = o do papel ({$CAPACITY.ROLE.WARN:"<papel>"}). '
                            'Contexto por circuito aceito: {$CAPACITY.UTIL.WARN:"NET-TO-0001"}=80.'},
            {'macro': '{$CIRCUIT_ID}', 'value': '',
             'description': 'Opcional, no host: circuito(s) do equipamento inteiro (ex.: NET-RJ-0005), para CPEs sem '
                            'CID na descrição da interface. Vai para as interfaces escolhidas por {$CIRCUIT_ID.IF}.'},
            {'macro': '{$CIRCUIT_ID.IF}', 'value': '',
             'description': 'Interface(s) que recebem o {$CIRCUIT_ID} do host: nomes separados por vírgula, '
                            '* vale qualquer coisa (ex.: ether1 ou GigabitEthernet0/0/0 ou sfp-sfpplus*).'},
        ],
        'valuemaps': [
            {
                'uuid': uid(t + '/valuemap/ifOperStatus'),
                'name': 'capacity ifOperStatus',
                'mappings': [{'value': str(v), 'newvalue': n} for v, n in [
                    (1, 'up'), (2, 'down'), (3, 'testing'), (4, 'unknown'), (5, 'dormant'), (6, 'notPresent'),
                    (7, 'lowerLayerDown')]],
            },
        ],
    }


def capacity_role_template(suffix, segment, warn, crit, description):
    t = '%s - %s' % (CAP_BASE, suffix)

    return {
        'uuid': uid(t),
        'template': t,
        'name': t,
        'description': Literal(
            'Capacity planning - camada 2 (perfil %s).\n%s\n\n'
            'Opcional: o template base acha o papel sozinho. Use este perfil só para forçar o papel de um\n'
            'equipamento que a regra automática classifica errado. Ele inclui o template base e define\n'
            '{$CAPACITY.SEGMENT}=%s (limites do papel: atenção %s%%, crítico %s%%).\n'
            % (suffix, description, segment, warn, crit)
        ),
        'vendor': {'name': 'nettopo', 'version': CAP_VERSION},
        'templates': [{'name': CAP_BASE}],
        'groups': [{'name': CAP_GROUP}],
        'tags': [{'tag': 'class', 'value': 'network'}, {'tag': 'target', 'value': 'capacity'},
                 {'tag': 'segment', 'value': segment}],
        'macros': [
            {'macro': '{$CAPACITY.SEGMENT}', 'value': segment, 'description': 'Papel do equipamento.'},
        ],
    }


LLDP_OIDS = [
    '1.0.8802.1.1.2.1.3.7.1',      # lldpLocPortTable
    '1.0.8802.1.1.2.1.4.1.1',      # lldpRemTable
    '1.0.8802.1.1.2.1.4.2.1.3',    # lldpRemManAddrIfSubtype (management address is in the index)
    '1.3.6.1.2.1.31.1.1.1.1',      # ifName
    '1.3.6.1.2.1.2.2.1.2',         # ifDescr
]

CDP_OIDS = [
    '1.3.6.1.4.1.9.9.23.1.2.1.1.3',  # cdpCacheAddressType
    '1.3.6.1.4.1.9.9.23.1.2.1.1.4',  # cdpCacheAddress
    '1.3.6.1.4.1.9.9.23.1.2.1.1.6',  # cdpCacheDeviceId
    '1.3.6.1.4.1.9.9.23.1.2.1.1.7',  # cdpCacheDevicePort
    '1.3.6.1.4.1.9.9.23.1.2.1.1.8',  # cdpCachePlatform
    '1.3.6.1.4.1.9.9.23.1.2.1.1.9',  # cdpCacheCapabilities
    '1.3.6.1.2.1.31.1.1.1.1',        # ifName
    '1.3.6.1.2.1.2.2.1.2',           # ifDescr
]

if __name__ == '__main__':
    build(neighbor_template('LLDP', 'LLDP', 'Network Topology LLDP by SNMP', LLDP_OIDS,
                            'LLDP-MIB lldpLocPortTable, lldpRemTable, lldpRemManAddrTable; IF-MIB ifName, ifDescr.',
                            'lldp_neighbors.js'),
          'nettopo_lldp_snmp.yaml')
    build(neighbor_template('CDP', 'CDP', 'Network Topology CDP by SNMP', CDP_OIDS,
                            'CISCO-CDP-MIB cdpCacheTable; IF-MIB ifName, ifDescr.',
                            'cdp_neighbors.js'),
          'nettopo_cdp_snmp.yaml')
    build([capacity_base_template()] + [capacity_role_template(*role) for role in CAP_ROLES],
          'capacity_planning.yaml', CAP_GROUP)
