/**
 * Tamago SMTP admin screen: provider presets, field toggles, test email / connection check (AJAX),
 * log detail view tabs and remote-image toggle, delete confirmations.
 *
 * Used by: Settings → SMTP (all tabs). Data comes from wp_localize_script( 'tmgSmtp' ).
 */
(function () {
	'use strict';

	var data = window.tmgSmtp || {};
	var t = data.i18n || {};

	function $(id) { return document.getElementById(id); }

	function rowOf(id) {
		var el = $(id);
		return el ? el.closest('tr') : null;
	}

	/* ---------------------------------------------------------------- */
	/* Settings tab                                                      */
	/* ---------------------------------------------------------------- */

	var provider = $('tmg-smtp-provider');
	var encryption = $('tmg-smtp-encryption');
	var auth = $('tmg-smtp-auth');
	var port = $('tmg-smtp-port');

	function setIfEditable(id, value) {
		var el = $(id);
		if (!el || el.disabled) { return; }
		if (el.type === 'checkbox') { el.checked = !!value; } else { el.value = value; }
		el.dispatchEvent(new Event('change'));
	}

	if (provider) {
		provider.addEventListener('change', function () {
			var p = (data.providers || {})[provider.value];
			if (!p) { return; }
			var help = $('tmg-smtp-provider-help');
			if (help) { help.textContent = p.help || ''; }
			if (provider.value === 'other') { return; }
			setIfEditable('tmg-smtp-host', p.host);
			setIfEditable('tmg-smtp-encryption', p.encryption);
			setIfEditable('tmg-smtp-port', p.port);
			setIfEditable('tmg-smtp-auth', p.auth);
			var user = $('tmg-smtp-username');
			if (p.username && user && !user.disabled && user.value === '') { user.value = p.username; }
		});
	}

	function toggleRows() {
		var autoRow = rowOf('tmg-smtp-auto-tls');
		if (autoRow && encryption) { autoRow.hidden = encryption.value !== 'none'; }
		var on = !auth || auth.checked;
		['tmg-smtp-username', 'tmg-smtp-password'].forEach(function (id) {
			var row = rowOf(id);
			if (row) { row.hidden = !on; }
		});
	}

	if (encryption) {
		encryption.addEventListener('change', function () {
			// Move the port along only while it is one of the standard ports.
			if (port && !port.disabled && ['25', '465', '587', ''].indexOf(port.value) !== -1) {
				port.value = encryption.value === 'ssl' ? '465' : (encryption.value === 'tls' ? '587' : '25');
			}
			toggleRows();
		});
	}
	if (auth) { auth.addEventListener('change', toggleRows); }
	toggleRows();

	/* ---------------------------------------------------------------- */
	/* Test tab                                                          */
	/* ---------------------------------------------------------------- */

	function post(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', data.nonce);
		Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
		return fetch(data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (text) {
					try { return JSON.parse(text); } catch (e) { return { success: false, data: { message: text || t.failed } }; }
				});
			});
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function showResult(box, ok, res) {
		box.innerHTML = '';
		var notice = el('div', 'notice inline ' + (ok ? 'notice-success' : 'notice-error'));
		notice.appendChild(el('p', '', res.message || t.failed));
		if (res.hint) {
			var p = el('p');
			p.appendChild(el('strong', '', (t.hint || '') + ' '));
			p.appendChild(document.createTextNode(res.hint));
			notice.appendChild(p);
		}
		if (res.log_url) {
			var lp = el('p');
			var a = el('a', '', t.viewLog);
			a.href = res.log_url;
			lp.appendChild(a);
			notice.appendChild(lp);
		}
		box.appendChild(notice);
		if (res.steps && res.steps.length) {
			var list = el('ul', 'tmg-smtp-steps');
			res.steps.forEach(function (s) {
				var li = el('li', s.ok ? 'is-ok' : 'is-fail');
				li.appendChild(el('span', 'dashicons ' + (s.ok ? 'dashicons-yes-alt' : 'dashicons-dismiss')));
				li.appendChild(el('strong', '', s.label + ': '));
				li.appendChild(document.createTextNode(s.detail || ''));
				list.appendChild(li);
			});
			box.appendChild(list);
		}
		if (res.transcript) {
			var details = el('details', 'tmg-smtp-transcript-box');
			if (!ok) { details.open = true; }
			details.appendChild(el('summary', '', t.transcript));
			details.appendChild(el('pre', 'tmg-smtp-pre tmg-smtp-transcript', res.transcript));
			box.appendChild(details);
		}
	}

	var testForm = $('tmg-smtp-test-form');
	if (testForm) {
		testForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = $('tmg-smtp-test-send');
			var box = $('tmg-smtp-test-result');
			var format = testForm.querySelector('input[name="tmg_smtp_test_format"]:checked');
			btn.disabled = true;
			box.innerHTML = '';
			box.appendChild(el('p', '', t.sending));
			post('tmg_smtp_send_test', { to: $('tmg-smtp-test-to').value, format: format ? format.value : 'html' })
				.then(function (r) { showResult(box, !!r.success, r.data || {}); })
				.catch(function () { showResult(box, false, { message: t.failed }); })
				.finally(function () { btn.disabled = false; });
		});
	}

	var check = $('tmg-smtp-check');
	if (check) {
		check.addEventListener('click', function () {
			var box = $('tmg-smtp-check-result');
			check.disabled = true;
			box.innerHTML = '';
			box.appendChild(el('p', '', t.checking));
			post('tmg_smtp_check_connection', {})
				.then(function (r) { showResult(box, !!r.success, r.data || {}); })
				.catch(function () { showResult(box, false, { message: t.failed }); })
				.finally(function () { check.disabled = false; });
		});
	}

	/* ---------------------------------------------------------------- */
	/* Logs tab                                                          */
	/* ---------------------------------------------------------------- */

	document.querySelectorAll('.tmg-smtp-view-tabs').forEach(function (tabs) {
		tabs.addEventListener('click', function (e) {
			var btn = e.target.closest('button[data-view]');
			if (!btn) { return; }
			tabs.querySelectorAll('button[data-view]').forEach(function (b) {
				var on = b === btn;
				b.classList.toggle('button-primary', on);
				b.setAttribute('aria-selected', on ? 'true' : 'false');
				var panel = $('tmg-smtp-view-' + b.getAttribute('data-view'));
				if (panel) { panel.hidden = !on; }
			});
		});
	});

	document.querySelectorAll('.tmg-smtp-remote-images').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var frame = btn.closest('.tmg-smtp-view').querySelector('iframe.tmg-smtp-preview');
			if (frame && frame.getAttribute('data-remote')) {
				frame.setAttribute('srcdoc', frame.getAttribute('data-remote'));
				btn.disabled = true;
			}
		});
	});

	document.querySelectorAll('form.tmg-smtp-delete-all').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(t.confirmAll)) { e.preventDefault(); }
		});
	});

	document.querySelectorAll('a.tmg-smtp-confirm-one').forEach(function (a) {
		a.addEventListener('click', function (e) {
			if (!window.confirm(t.confirmOne)) { e.preventDefault(); }
		});
	});

	var logsForm = document.querySelector('form.tmg-smtp-logs-form');
	if (logsForm) {
		logsForm.addEventListener('submit', function (e) {
			var submitter = e.submitter;
			if (submitter && submitter.name === 'filter_action') { return; }
			var a1 = logsForm.querySelector('select[name="action"]');
			var a2 = logsForm.querySelector('select[name="action2"]');
			var chosen = (a1 && a1.value === 'delete') || (a2 && a2.value === 'delete');
			if (chosen && !window.confirm(t.confirmDelete)) { e.preventDefault(); }
		});
	}
})();
