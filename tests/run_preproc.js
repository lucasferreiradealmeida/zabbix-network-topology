// Runs a Zabbix JS preprocessing body against an input file (Node harness) and checks ES5 syntax.
//   node run_preproc.js SCRIPT INPUT [MACROS.json] [HTTP_MOCK.json]
// MACROS.json: {"{$NAME}": "value"} replaced in the script text, like the Zabbix server does.
// HTTP_MOCK.json: {"url substring": {"status": 200, "body": {...}}} answers for HttpRequest.get().
const fs = require('fs');
const acorn = require('acorn');
const [,, scriptPath, inputPath, macrosPath, mockPath] = process.argv;
let body = fs.readFileSync(scriptPath, 'utf8');

if (macrosPath) {
	const macros = JSON.parse(fs.readFileSync(macrosPath, 'utf8'));
	for (const [macro, value] of Object.entries(macros)) {
		body = body.split(macro).join(value);
	}
}

const mock = mockPath ? JSON.parse(fs.readFileSync(mockPath, 'utf8')) : {};

class HttpRequest {
	constructor() {
		this.headers = [];
		this.status = 0;
	}
	addHeader(h) {
		this.headers.push(h);
	}
	get(url) {
		const key = Object.keys(mock).find((k) => url.includes(k));
		if (key === undefined) {
			throw new Error('no mock for ' + url);
		}
		this.status = mock[key].status ?? 200;
		HttpRequest.log.push({url, headers: this.headers});
		return typeof mock[key].body === 'string' ? mock[key].body : JSON.stringify(mock[key].body);
	}
	getStatus() {
		return this.status;
	}
}
HttpRequest.log = [];

// Zabbix wraps the body in a function(value) { ... }
acorn.parse('(function (value) {\n' + body + '\n})', {ecmaVersion: 5});
const fn = new Function('value', 'HttpRequest', body);
const out = fn(fs.readFileSync(inputPath, 'utf8'), HttpRequest);
process.stdout.write(out + '\n');

if (HttpRequest.log.length) {
	process.stderr.write('HTTP: ' + JSON.stringify(HttpRequest.log) + '\n');
}
