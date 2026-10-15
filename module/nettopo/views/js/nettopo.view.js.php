<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Starts the page. When a static script did not arrive intact (blocked path, file cut short by the web
** server or by the Docker Desktop file sharing...), the scripts are loaded again through zabbix.php
** (action nettopo.lib); if that also fails, the page shows what the browser received.
**
** @var CView $this
** @var array $data
**/
?>
<script>
jQuery(function() {
	'use strict';

	const boot = <?= json_encode($data['boot'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
	const config = <?= json_encode($data['client'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
	const root = document.getElementById('nettopo');
	const t = (s) => (config.strings && config.strings[s]) || s;

	const missing = () => boot.scripts.filter((script) => typeof window[script.global] === 'undefined');

	function start() {
		window.NetTopo.init(config);
	}

	function inject(code) {
		// define/module/exports/require are hidden so the libraries always register as globals.
		const tag = document.createElement('script');

		tag.text = '(function(define, module, exports, require) {\n' + code + '\n}).call(window);';
		document.head.appendChild(tag);
		tag.remove();
	}

	function report(scripts, fallback_error) {
		const checks = scripts.map((script) =>
			fetch(script.url, {cache: 'no-store', credentials: 'same-origin'})
				.then((response) => response.ok
					? response.arrayBuffer().then((body) => script.file + ': HTTP ' + response.status + ', '
						+ body.byteLength + ' / ' + script.size + ' bytes'
						+ (body.byteLength < script.size ? ' (incomplete)' : ''))
					: script.file + ': HTTP ' + response.status
				)
				.catch((error) => script.file + ': ' + error.message)
		);

		Promise.all(checks).then((lines) => {
			const text = [
				'Network Topology: ' + t('The page could not load its graph library.'),
				'',
				t('What the browser received from the web server:'),
				...lines.map((line) => '  ' + line),
				...(window.NetTopoLoadErrors || []).map((line) => '  ' + line),
				'',
				t('Loading through zabbix.php also failed:') + ' ' + fallback_error,
				'',
				t('Check that the web server delivers the files in %1$s (for Docker Desktop on Windows or Mac, copy the module into the image instead of mounting the folder).')
					.replace('%1$s', boot.assets_path + '/')
			].join('\n');

			console.error(text);

			if (root) {
				root.classList.add('nettopo-load-error');
				root.textContent = text;
			}
		});
	}

	const need = missing();

	if (need.length == 0) {
		start();

		return;
	}

	// The graph libraries depend on each other: if one is missing, all of them are loaded again.
	const libs_missing = need.some((script) => script.key !== 'nettopo');
	const reload = boot.scripts.filter((script) => script.key === 'nettopo' ? need.includes(script) : libs_missing);

	console.warn('Network Topology: the web server did not deliver ' + need.map((script) => script.file).join(', ')
		+ ' correctly' + ((window.NetTopoLoadErrors || []).length ? ' (' + NetTopoLoadErrors.join('; ') + ')' : '')
		+ '. Loading through zabbix.php instead.'
	);

	const url = boot.lib_url + '&' + reload.map((script) => 'files[]=' + encodeURIComponent(script.key)).join('&');

	fetch(url, {credentials: 'same-origin'})
		.then((response) => {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}

			return response.json();
		})
		.then((data) => {
			if (!Array.isArray(data.scripts)) {
				throw new Error(data.error || 'unexpected answer');
			}

			for (const script of data.scripts) {
				if (script.code !== null) {
					inject(script.code);
				}
			}

			const still_missing = missing();

			if (still_missing.length) {
				throw new Error('still missing ' + still_missing.map((script) => script.global).join(', '));
			}

			start();
		})
		.catch((error) => report(need, error.message));
});
</script>
