#!/usr/bin/env python3
"""Preenche o CIRCUIT_ID dos equipamentos no Zabbix a partir de uma base externa (enriquecimento pela API).

O template "Template Capacity Planning" lê o circuito de cada interface pela descrição
("CID:NET-RJ-0001"), pelo NetBox ou pelas macros de host {$CIRCUIT_ID} / {$CIRCUIT_ID.IF}. Este script grava
essas macros a partir de qualquer uma destas fontes:

  --csv ARQUIVO         planilha/exportação do CMDB com as colunas circuit_id, host, interface e,
                        opcional, contracted_mbps (separador vírgula ou ponto e vírgula)
  --inventory CAMPO     campo do inventário do host com o CIRCUIT_ID (ex.: contract_number, asset_tag)
  --tag NOME            tag de host com o CIRCUIT_ID (ex.: circuit)
  --name-regex REGEX    expressão regular no nome do host com o grupo "cid",
                        ex.: 'CPE-(?P<cid>NET-[A-Z]{2}-[0-9]{4})'

A banda contratada (coluna contracted_mbps do CSV) vira a macro global {$CAPACITY.BW.MBPS:"<CIRCUIT_ID>"}.

Sem --apply o script só mostra o que mudaria. Exemplos:

  python3 capacity_enrich.py --url https://zabbix.exemplo --token SEU_TOKEN --csv circuitos.csv
  python3 capacity_enrich.py --url https://zabbix.exemplo --token SEU_TOKEN --csv circuitos.csv --apply --discover
  python3 capacity_enrich.py --url https://zabbix.exemplo --token SEU_TOKEN --group CPEs \\
      --inventory contract_number --interface ether1 --apply

Requer Python 3.8+ e um token de API (Usuários > Tokens de API) de um usuário que possa editar os hosts
(e as macros globais, para a banda contratada: Super admin).
"""
import argparse
import csv
import json
import re
import ssl
import sys
import urllib.error
import urllib.request

ID_RE = re.compile(r'^[A-Z0-9][A-Z0-9._/-]*$')
MACRO_CID = '{$CIRCUIT_ID}'
MACRO_IF = '{$CIRCUIT_ID.IF}'
MACRO_BW = '{$CAPACITY.BW.MBPS:"%s"}'


class Api:

    def __init__(self, url, token=None, ca_file=None):
        self.url = url.rstrip('/')
        if not self.url.endswith('api_jsonrpc.php'):
            self.url += '/api_jsonrpc.php'
        self.token = token
        self.context = ssl.create_default_context(cafile=ca_file) if ca_file else None

    def call(self, method, params):
        headers = {'Content-Type': 'application/json-rpc'}
        if self.token and method not in ('apiinfo.version', 'user.login'):
            headers['Authorization'] = 'Bearer ' + self.token
        body = json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode()
        request = urllib.request.Request(self.url, body, headers)
        try:
            with urllib.request.urlopen(request, timeout=60, context=self.context) as response:
                data = json.loads(response.read().decode())
        except urllib.error.URLError as e:
            raise SystemExit('Não foi possível falar com %s: %s' % (self.url, e))
        if 'error' in data:
            raise SystemExit('Erro da API em %s: %s %s' % (method, data['error'].get('message', ''),
                                                          data['error'].get('data', '')))
        return data['result']

    def login(self, user, password):
        self.token = self.call('user.login', {'username': user, 'password': password})


def clean_ids(text):
    ids = []
    for part in re.split(r'[,;\s]+', (text or '').upper()):
        part = part.strip().rstrip('._/-')
        if part and ID_RE.match(part) and part not in ids:
            ids.append(part)
    return ids


def read_csv(path):
    with open(path, newline='', encoding='utf-8-sig') as f:
        sample = f.read(4096)
        f.seek(0)
        dialect = csv.Sniffer().sniff(sample, delimiters=',;\t') if sample else csv.excel
        rows = list(csv.DictReader(f, dialect=dialect))
    result = []
    for n, row in enumerate(rows, start=2):
        row = {(k or '').strip().lower(): (v or '').strip() for k, v in row.items()}
        cids = clean_ids(row.get('circuit_id') or row.get('cid') or row.get('circuito') or '')
        host = row.get('host') or row.get('equipamento') or ''
        if not cids or not host:
            print('  linha %d ignorada: falta circuit_id ou host' % n)
            continue
        result.append({
            'cids': cids,
            'host': host,
            'interface': row.get('interface') or row.get('porta') or '',
            'contracted': (row.get('contracted_mbps') or row.get('banda_mbps') or '').replace(',', '.')
        })
    return result


def find_hosts(api, names, groupid):
    """Technical or visible host names -> host."""
    params = {'output': ['hostid', 'host', 'name'], 'selectMacros': ['hostmacroid', 'macro', 'value']}
    if groupid:
        params['groupids'] = [groupid]
    found = {}
    for field in ('host', 'name'):
        missing = [n for n in names if n not in found]
        if not missing:
            break
        for host in api.call('host.get', dict(params, filter={field: missing})):
            found.setdefault(host[field], host)
    return found


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--url', required=True, help='endereço do frontend do Zabbix')
    parser.add_argument('--token', help='token da API')
    parser.add_argument('--user', help='usuário (se não usar token)')
    parser.add_argument('--password', help='senha (se não usar token)')
    parser.add_argument('--ca-file', help='certificado da CA interna, se o HTTPS do Zabbix usar uma')
    parser.add_argument('--csv')
    parser.add_argument('--inventory', metavar='CAMPO')
    parser.add_argument('--tag', metavar='NOME')
    parser.add_argument('--name-regex', metavar='REGEX')
    parser.add_argument('--group', help='só hosts deste grupo')
    parser.add_argument('--interface', help='interface(s) para {$CIRCUIT_ID.IF} quando a fonte não informa')
    parser.add_argument('--apply', action='store_true', help='gravar (sem isto, só mostra)')
    parser.add_argument('--discover', action='store_true', help='rodar a descoberta de capacidade agora')
    args = parser.parse_args()

    if not (args.csv or args.inventory or args.tag or args.name_regex):
        parser.error('informe uma fonte: --csv, --inventory, --tag ou --name-regex')

    name_re = None
    if args.name_regex:
        name_re = re.compile(args.name_regex)
        if 'cid' not in name_re.groupindex:
            parser.error('--name-regex precisa de um grupo chamado cid: (?P<cid>...)')

    api = Api(args.url, args.token, args.ca_file)
    print('Zabbix %s' % api.call('apiinfo.version', {}))
    if not args.token:
        if not (args.user and args.password):
            parser.error('informe --token ou --user e --password')
        api.login(args.user, args.password)

    groupid = None
    if args.group:
        groups = api.call('hostgroup.get', {'output': ['groupid'], 'filter': {'name': args.group}})
        if not groups:
            raise SystemExit('Grupo não encontrado: %s' % args.group)
        groupid = groups[0]['groupid']

    # host name -> {'cids': [...], 'interfaces': [...]}, circuit -> contracted Mbps
    wanted = {}
    contracted = {}

    def want(host, cids, interface):
        entry = wanted.setdefault(host, {'cids': [], 'interfaces': []})
        for cid in cids:
            if cid not in entry['cids']:
                entry['cids'].append(cid)
        for name in [i.strip() for i in (interface or '').split(',') if i.strip()]:
            if name not in entry['interfaces']:
                entry['interfaces'].append(name)

    if args.csv:
        rows = read_csv(args.csv)
        print('CSV: %d linha(s) com circuito' % len(rows))
        for row in rows:
            want(row['host'], row['cids'], row['interface'] or args.interface)
            if row['contracted']:
                try:
                    value = float(row['contracted'])
                except ValueError:
                    print('  banda inválida para %s: %s' % (row['cids'][0], row['contracted']))
                    continue
                for cid in row['cids']:
                    contracted[cid] = ('%g' % value)

    if args.inventory or args.tag or name_re:
        params = {'output': ['hostid', 'host', 'name'], 'selectTags': ['tag', 'value']}
        if args.inventory:
            params['selectInventory'] = [args.inventory]
        if groupid:
            params['groupids'] = [groupid]
        for host in api.call('host.get', params):
            cids = []
            if args.inventory and isinstance(host.get('inventory'), dict):
                cids += clean_ids(host['inventory'].get(args.inventory, ''))
            if args.tag:
                for tag in host.get('tags', []):
                    if tag['tag'] == args.tag:
                        cids += clean_ids(tag['value'])
            if name_re:
                match = name_re.search(host['host']) or name_re.search(host['name'])
                if match and match.group('cid'):
                    cids += clean_ids(match.group('cid'))
            if cids:
                want(host['host'], cids, args.interface)

    if not wanted and not contracted:
        print('Nada a fazer: nenhuma fonte trouxe circuitos.')
        return

    hosts = find_hosts(api, list(wanted), groupid)
    changes = 0
    discover = []

    print()
    print('%-28s %-26s %s' % ('HOST', 'MACRO', 'ATUAL -> NOVO'))
    for name, entry in sorted(wanted.items()):
        host = hosts.get(name)
        if host is None:
            print('%-28s %s' % (name, 'host não encontrado (nome técnico ou visível)'))
            continue
        current = {m['macro']: m for m in host.get('macros', [])}
        desired = {MACRO_CID: ','.join(entry['cids'])}
        if entry['interfaces']:
            desired[MACRO_IF] = ','.join(entry['interfaces'])
        elif not current.get(MACRO_IF, {}).get('value'):
            print('%-28s aviso: sem interface; defina %s (use --interface ou a coluna interface)' % (name, MACRO_IF))
        changed = False
        for macro, value in desired.items():
            old = current.get(macro)
            if old is not None and old.get('value') == value:
                continue
            print('%-28s %-26s %s -> %s' % (name, macro, old.get('value', '?') if old else '(nenhuma)', value))
            changes += 1
            changed = True
            if args.apply:
                if old is not None:
                    api.call('usermacro.update', {'hostmacroid': old['hostmacroid'], 'value': value})
                else:
                    api.call('usermacro.create', {'hostid': host['hostid'], 'macro': macro, 'value': value})
        if changed:
            discover.append(host['hostid'])

    if contracted:
        existing = {m['macro']: m for m in api.call('usermacro.get', {
            'output': ['globalmacroid', 'macro', 'value'], 'globalmacro': True,
            'search': {'macro': '{$CAPACITY.BW.MBPS:'}})}
        for cid, value in sorted(contracted.items()):
            macro = MACRO_BW % cid
            old = existing.get(macro)
            if old is not None and old['value'] == value:
                continue
            print('%-28s %-26s %s -> %s Mbps' % ('(global)', macro, old['value'] if old else '(nenhuma)', value))
            changes += 1
            if args.apply:
                if old is not None:
                    api.call('usermacro.updateglobal', {'globalmacroid': old['globalmacroid'], 'value': value})
                else:
                    api.call('usermacro.createglobal', {'macro': macro, 'value': value,
                                                        'description': 'Banda contratada do circuito (Mbps)'})

    print()
    if not changes:
        print('Tudo já está atualizado.')
    elif not args.apply:
        print('%d alteração(ões). Nada foi gravado: rode de novo com --apply.' % changes)
    else:
        print('%d alteração(ões) gravada(s).' % changes)
        if args.discover and discover:
            items = api.call('item.get', {'output': ['itemid'], 'hostids': discover,
                                          'filter': {'key_': 'capacity.if.walk'}})
            if items:
                api.call('task.create', [{'type': 6, 'request': {'itemid': i['itemid']}} for i in items])
                print('Descoberta pedida para %d host(s): o circuito aparece em 1-2 minutos.' % len(items))
        elif discover:
            print('A descoberta roda sozinha a cada {$CAPACITY.LLD.INTERVAL} (padrão 1h); use --discover para agora.')


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
