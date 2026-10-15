"""Submits the module settings form as Admin: settings_set.py USER_TYPE [USRGRPID...]

Capacity planning settings: CAPACITY="last mile,distribution,backbone,horizon days" (e.g. CAPACITY=80,70,60,2);
without it the values in the form are kept.
"""
import os, re, sys, urllib.parse, urllib.request, http.cookiejar

base = os.environ.get('NETTOPO_BASE', 'http://127.0.0.1:8080').rstrip('/') + '/'
cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
op.open(base + 'index.php', urllib.parse.urlencode({'name': 'Admin', 'password': 'zabbix', 'enter': 'Sign in'}).encode())
html = op.open(base + 'zabbix.php?action=nettopo.settings').read().decode()
token = re.search(r'name="_csrf_token" value="([^"]+)"', html).group(1)
# The initial map is kept (the attributes of the select come in any order); DEFAULT_MAP changes it.
tag = re.search(r'<z-select[^>]*name="default_map"[^>]*>', html)
default = re.search(r'\svalue="([^"]*)"', tag.group(0)) if tag else None
if 'DEFAULT_MAP' in os.environ:
    default = None
    default_map = os.environ['DEFAULT_MAP']
else:
    default_map = default.group(1) if default else ''
names = ['target_acesso', 'target_distribuicao', 'target_backbone', 'horizon']
current = [re.search(r'name="%s" value="([^"]*)"' % n, html) for n in names]
capacity = os.environ['CAPACITY'].split(',') if os.environ.get('CAPACITY') else [m.group(1) if m else '' for m in current]
fields = [('_csrf_token', token), ('editors_user_type', sys.argv[1]), ('max_hosts', '2000'),
          ('default_map', default_map)]
fields += [('editors_usrgrpids[]', g) for g in sys.argv[2:]]
fields += [(n, v) for n, v in zip(names, capacity) if v != '']
op.open(base + 'zabbix.php?action=nettopo.settings.update', urllib.parse.urlencode(fields).encode()).read()
print('submitted', fields[1:])
