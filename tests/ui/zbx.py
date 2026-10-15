"""Tiny Zabbix API helper for the local test environment (Admin / zabbix)."""
import json, sys, urllib.request

import os
URL = os.environ.get('NETTOPO_API', 'http://127.0.0.1:8080/api_jsonrpc.php')
_token = None


def call(method, params, auth=True):
    global _token
    if auth and _token is None:
        _token = call('user.login', {'username': 'Admin', 'password': 'zabbix'}, auth=False)
    body = {'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}
    headers = {'Content-Type': 'application/json-rpc'}
    if auth:
        headers['Authorization'] = 'Bearer ' + _token
    req = urllib.request.Request(URL, json.dumps(body).encode(), headers)
    with urllib.request.urlopen(req, timeout=120) as r:
        data = json.loads(r.read())
    if 'error' in data:
        raise RuntimeError('%s: %s' % (method, data['error']))
    return data['result']


if __name__ == '__main__':
    print(json.dumps(call(sys.argv[1], json.loads(sys.argv[2])), indent=1)[:4000])
