/* EP Events – registration form behaviour (conditional fields, "Others", validation, AJAX submit). */
(function () {
	'use strict';

	const OTHER = '__other';

	function values(form, name) {
		const els = form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
		const out = [];
		els.forEach(function (el) {
			if (el.disabled) return;
			if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
			if (el.value !== '') out.push(el.value);
		});
		return out;
	}

	function visible(form, cond) {
		const wrap = form.querySelector('.epc-field[data-name="' + cond.field + '"]');
		const list = wrap && !wrap.hidden ? values(form, cond.field).map(function (v) { return String(v).toLowerCase().trim(); }) : [];
		const target = String(cond.value || '').toLowerCase().trim();
		const hit = list.indexOf(target) !== -1;
		switch (cond.operator) {
			case 'is_not': return !hit;
			case 'not_empty': return list.length > 0;
			case 'empty': return list.length === 0;
			case 'contains': return target !== '' && list.join(' ').indexOf(target) !== -1;
			default: return hit;
		}
	}

	function refresh(form) {
		// Iterate a few times so chained conditions settle.
		for (let pass = 0; pass < 3; pass++) {
			form.querySelectorAll('[data-cond]').forEach(function (wrap) {
				let cond;
				try { cond = JSON.parse(wrap.getAttribute('data-cond')); } catch (e) { return; }
				const show = visible(form, cond);
				wrap.hidden = !show;
				wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
					if (el.classList.contains('epc-other')) return;
					el.disabled = !show;
				});
			});
		}
		// "Others – please specify" inputs.
		form.querySelectorAll('.epc-other').forEach(function (other) {
			const wrap = other.closest('.epc-field');
			const chosen = !wrap.hidden && values(form, wrap.getAttribute('data-name')).indexOf(OTHER) !== -1;
			other.hidden = !chosen;
			other.disabled = !chosen;
			other.required = chosen;
		});
	}

	function setError(wrap, msg) {
		if (!wrap) return;
		const err = wrap.querySelector('.epc-error');
		wrap.classList.toggle('is-invalid', !!msg);
		if (err) { err.textContent = msg || ''; err.hidden = !msg; }
	}

	function validate(form) {
		let firstBad = null;
		form.querySelectorAll('.epc-field[data-name]').forEach(function (wrap) {
			if (wrap.hidden) { setError(wrap, ''); return; }
			let msg = '';
			const els = wrap.querySelectorAll('input, select, textarea');
			const group = wrap.querySelector('input[type=checkbox][name$="[]"]');
			if (group) {
				const req = wrap.querySelector('.epc-req');
				if (req && values(form, wrap.getAttribute('data-name')).length === 0) msg = 'Please choose at least one option.';
			} else {
				els.forEach(function (el) {
					if (msg || el.disabled || el.hidden) return;
					if (!el.checkValidity()) {
						if (el.validity.valueMissing) msg = el.type === 'checkbox' ? 'You must agree to continue.' : 'This field is required.';
						else if (el.dataset.format && el.closest('.epc-field--nric')) msg = 'Please enter a valid NRIC (e.g. 900101-14-5678).';
						else if (el.dataset.format && el.closest('.epc-field--phone')) msg = el.dataset.format === 'intl' ? 'Please include the country code (e.g. +60 12-345 6789).' : 'Please enter a valid phone number.';
						else msg = el.validationMessage || 'Invalid value.';
					}
				});
			}
			setError(wrap, msg);
			if (msg && !firstBad) firstBad = wrap;
		});
		if (firstBad) {
			firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
			const f = firstBad.querySelector('input, select, textarea');
			if (f) f.focus({ preventScroll: true });
		}
		return !firstBad;
	}

	function init(form) {
		form.addEventListener('change', function () { refresh(form); });
		form.addEventListener('input', function (e) {
			const wrap = e.target.closest('.epc-field');
			if (wrap && wrap.classList.contains('is-invalid')) setError(wrap, '');
		});
		refresh(form);

		// Attribution from the tracker (visitor id + UTM/referrer of the session).
		try {
			form.querySelector('[name=epc_visitor]').value = localStorage.getItem('epc_vid') || '';
			form.querySelector('[name=epc_source]').value = sessionStorage.getItem('epc_src') || '';
		} catch (e) { /* storage blocked */ }

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!validate(form)) return;

			const btn = form.querySelector('.epc-submit');
			const box = form.querySelector('.epc-form-errors');
			btn.disabled = true;
			btn.classList.add('is-loading');
			box.hidden = true;

			const body = new FormData(form);
			body.append('epc_ajax', '1');

			fetch(form.getAttribute('action'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.ok) {
						if (res.redirect) { window.location.href = res.redirect; return; }
						const wrap = form.closest('.epc-form-wrap');
						wrap.innerHTML = '<div class="epc-success">' + res.html + '</div>';
						wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
						return;
					}
					Object.keys(res.errors || {}).forEach(function (name) {
						setError(form.querySelector('.epc-field[data-name="' + name + '"]'), res.errors[name]);
					});
					box.textContent = res.message || 'Please check the form.';
					box.hidden = false;
					const bad = form.querySelector('.is-invalid') || box;
					bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
				})
				.catch(function () {
					box.textContent = 'Network error. Please try again.';
					box.hidden = false;
				})
				.finally(function () {
					btn.disabled = false;
					btn.classList.remove('is-loading');
				});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('form.epc-form').forEach(init);
	});
})();
