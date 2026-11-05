/* EP Events theme: nav, modals, countdown, reveal, lightbox, carousel. */
(function () {
	'use strict';

	// Mobile nav.
	document.querySelectorAll('.ep-nav-toggle').forEach(function (btn) {
		const nav = document.getElementById(btn.getAttribute('aria-controls'));
		if (!nav) return;
		btn.addEventListener('click', function () {
			const open = btn.getAttribute('aria-expanded') !== 'true';
			btn.setAttribute('aria-expanded', open);
			nav.classList.toggle('is-open', open);
		});
		nav.addEventListener('click', function (e) {
			if (e.target.closest('a')) { btn.setAttribute('aria-expanded', 'false'); nav.classList.remove('is-open'); }
		});
	});

	// Offset anchor scrolling under the sticky bar.
	const bar = document.querySelector('.ep-msnav, .ep-header');
	if (bar) document.documentElement.style.scrollPaddingTop = (bar.offsetHeight + 12) + 'px';

	// Modals.
	let lastFocus = null;
	function openModal(m) {
		lastFocus = document.activeElement;
		m.hidden = false;
		document.body.classList.add('ep-noscroll');
		const f = m.querySelector('a, button');
		if (f) f.focus();
	}
	function closeModal(m) {
		m.hidden = true;
		document.body.classList.remove('ep-noscroll');
		if (lastFocus) lastFocus.focus();
	}
	document.addEventListener('click', function (e) {
		const opener = e.target.closest('[data-ep-modal]');
		if (opener) {
			const m = document.getElementById(opener.getAttribute('data-ep-modal'));
			if (m) { e.preventDefault(); openModal(m); }
			return;
		}
		if (e.target.closest('[data-ep-close]')) {
			const m = e.target.closest('.ep-modal');
			if (m) closeModal(m);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		document.querySelectorAll('.ep-modal:not([hidden])').forEach(closeModal);
		const lb = document.querySelector('.ep-lightbox');
		if (lb) lb.remove(), document.body.classList.remove('ep-noscroll');
	});

	// Countdown.
	document.querySelectorAll('[data-ep-countdown]').forEach(function (el) {
		const target = new Date(el.getAttribute('data-ep-countdown')).getTime();
		const parts = {};
		el.querySelectorAll('[data-k]').forEach(function (s) { parts[s.dataset.k] = s; });
		function tick() {
			const d = target - Date.now();
			if (isNaN(target) || d <= 0) { el.remove(); return false; }
			parts.days.textContent = Math.floor(d / 864e5);
			parts.hours.textContent = String(Math.floor(d % 864e5 / 36e5)).padStart(2, '0');
			parts.minutes.textContent = String(Math.floor(d % 36e5 / 6e4)).padStart(2, '0');
			parts.seconds.textContent = String(Math.floor(d % 6e4 / 1e3)).padStart(2, '0');
			return true;
		}
		if (tick()) { const t = setInterval(function () { if (!tick()) clearInterval(t); }, 1000); }
	});

	// Reveal on scroll.
	const reveals = document.querySelectorAll('.has-animations .ep-reveal');
	if (reveals.length && 'IntersectionObserver' in window) {
		const io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) {
				if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
			});
		}, { rootMargin: '0px 0px -8% 0px' });
		reveals.forEach(function (r) { io.observe(r); });
	} else {
		reveals.forEach(function (r) { r.classList.add('is-in'); });
	}

	// Lightbox.
	document.addEventListener('click', function (e) {
		const a = e.target.closest('[data-ep-lightbox]');
		if (!a) return;
		e.preventDefault();
		const group = [].slice.call(document.querySelectorAll('[data-ep-lightbox="' + a.getAttribute('data-ep-lightbox') + '"]'));
		let i = group.indexOf(a);
		const box = document.createElement('div');
		box.className = 'ep-lightbox';
		box.setAttribute('role', 'dialog');
		box.innerHTML = '<img alt=""><p></p><button class="ep-lb-close" aria-label="Close">×</button>' + (group.length > 1 ? '<button class="ep-lb-prev" aria-label="Previous">‹</button><button class="ep-lb-next" aria-label="Next">›</button>' : '');
		function show() {
			box.querySelector('img').src = group[i].href;
			box.querySelector('p').textContent = group[i].getAttribute('data-caption') || '';
		}
		box.addEventListener('click', function (ev) {
			if (ev.target.classList.contains('ep-lb-prev')) { i = (i - 1 + group.length) % group.length; show(); }
			else if (ev.target.classList.contains('ep-lb-next')) { i = (i + 1) % group.length; show(); }
			else if (ev.target === box || ev.target.classList.contains('ep-lb-close')) { box.remove(); document.body.classList.remove('ep-noscroll'); }
		});
		show();
		document.body.appendChild(box);
		document.body.classList.add('ep-noscroll');
	});

	// Carousel arrows.
	document.querySelectorAll('[data-ep-carousel]').forEach(function (track) {
		const nav = track.nextElementSibling;
		if (!nav || !nav.classList.contains('ep-carousel-nav')) return;
		const step = function () { const item = track.querySelector('.ep-gallery__item'); return item ? item.offsetWidth + 12 : 300; };
		nav.querySelector('.ep-carousel-prev').addEventListener('click', function () { track.scrollBy({ left: -step(), behavior: 'smooth' }); });
		nav.querySelector('.ep-carousel-next').addEventListener('click', function () { track.scrollBy({ left: step(), behavior: 'smooth' }); });
	});
})();
