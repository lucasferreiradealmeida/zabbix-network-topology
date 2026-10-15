<?php declare(strict_types = 0);
/*
** Network Topology module for Zabbix.
** Capacity planning, circuit page: the upgrade simulation is recalculated in place. The page asks the server for
** the new values, swaps only the simulation section and keeps the scroll position (a normal form submission
** reloaded the page and sent the user back to the top). If anything fails, the form is submitted normally.
**
** @var CView $this
**/
?>
<script>
(function() {
	'use strict';

	let pending = null;

	function bind(section) {
		const form = section.querySelector('form.nt-cap-sim-form');

		if (!form) {
			return;
		}

		const select = form.querySelector('z-select');
		const current = select ? String(select.value) : null;

		// The form has no button; this only catches a submission started by the browser itself.
		form.addEventListener('submit', (e) => {
			e.preventDefault();
			run(form);
		});

		// Choosing another capacity simulates it at once: a click in the list or the arrow keys on the field (the
		// focus comes back to the new field after the update, so the arrows keep working).
		if (select) {
			select.addEventListener('change', () => {
				if (String(select.value) !== current) {
					run(form);
				}
			});
		}
	}

	function run(form) {
		const section = form.closest('.nt-cap-sim-section');
		const url = 'zabbix.php?' + new URLSearchParams(new FormData(form)).toString();
		const had_focus = section.contains(document.activeElement);

		if (pending !== null) {
			pending.abort();
		}

		const controller = new AbortController();

		pending = controller;
		section.classList.add('nt-cap-sim-loading');
		section.setAttribute('aria-busy', 'true');

		fetch(url, {credentials: 'same-origin', signal: controller.signal})
			.then((response) => {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}

				return response.text();
			})
			.then((html) => {
				const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.nt-cap-sim-section');

				// Session expired, error page...: the normal submission shows what happened.
				if (fresh === null) {
					throw new Error('no simulation in the answer');
				}

				const node = document.importNode(fresh, true);

				section.replaceWith(node);
				bind(node);
				pending = null;

				if (had_focus) {
					const select = node.querySelector('z-select');

					if (select && typeof select.focus === 'function') {
						select.focus();
					}
				}

				// A reload of the page (F5) shows the same simulation.
				history.replaceState(history.state, '', url + '#simulation');
			})
			.catch((error) => {
				if (error.name === 'AbortError') {
					return;
				}

				console.warn('Network Topology: simulation in place failed (' + error.message + '), reloading the page.');
				pending = null;
				HTMLFormElement.prototype.submit.call(form);
			});
	}

	const section = document.querySelector('.nt-cap-sim-section');

	if (section !== null) {
		bind(section);
	}
})();
</script>
