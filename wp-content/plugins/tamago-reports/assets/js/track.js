/*
 * Tamago Reports beacon: page views, engaged time, scroll depth, clicks and events.
 * No cookies. Cookieless mode stores nothing; consent mode keeps a visitor ID in localStorage.
 * API: tamagoReports.consent(true|false), tamagoReports.track(name, label, value), tamagoReports.visitorId().
 */
(function (w, d) {
	'use strict';
	var c = w.tamagoReportsConfig, n = navigator, L = location;
	if (!c || !c.endpoint || (w.tamagoReports && w.tamagoReports.v)) return;

	var stub = (w.tamagoReports && w.tamagoReports.q) || [];
	var store = c.consent === 'wait', ok = false, vid = '', sid = '', isNew = 0, queue = [];
	var view = null, shown = 0, since = 0, maxScroll = 0, lastUrl = '', ref = d.referrer;
	var off = (c.dnt && (n.doNotTrack == '1' || w.doNotTrack == '1')) || (c.gpc && n.globalPrivacyControl === true);

	function rid() {
		var a = new Uint8Array(16), s = '', i;
		(w.crypto || w.msCrypto).getRandomValues(a);
		for (i = 0; i < 16; i++) s += (a[i] < 16 ? '0' : '') + a[i].toString(16);
		return s;
	}
	function ls(k, v) {
		try {
			if (v === undefined) return localStorage.getItem(k);
			if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v);
		} catch (e) {}
		return null;
	}
	function ids() {
		if (!store) return;
		vid = ls('tmg_vid');
		isNew = vid ? 0 : 1;
		if (!vid) { vid = rid(); ls('tmg_vid', vid); }
		var s = (ls('tmg_sid') || '').split('.');
		sid = s[0] && Date.now() - (+s[1] || 0) < 18e5 ? s[0] : rid(); // 30-minute idle session
	}
	function cookieOk() {
		if (!c.cookie) return false;
		var m = d.cookie.match(new RegExp('(?:^|; )' + c.cookie.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
		if (!m) return false;
		try { m = decodeURIComponent(m[1]); } catch (e) { m = m[1]; }
		return !c.cookieV || m.indexOf(c.cookieV) > -1;
	}

	function send(o, beacon) {
		if (store && sid) ls('tmg_sid', sid + '.' + Date.now());
		var b = JSON.stringify(o);
		if (beacon && n.sendBeacon && n.sendBeacon(c.endpoint, new Blob([b], { type: 'text/plain' }))) return null;
		if (!w.fetch) return null;
		return w.fetch(c.endpoint, { method: 'POST', body: b, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
			.then(function (r) { return r.status === 200 ? r.json() : null; })['catch'](function () { return null; });
	}
	function base(t) {
		var o = { t: t, p: c.p, k: c.k, u: L.href, r: ref, sw: screen.width + 'x' + screen.height, vp: w.innerWidth + 'x' + w.innerHeight, l: n.language || '', tc: ('ontouchend' in d) && n.maxTouchPoints > 1 ? 1 : 0 };
		try { o.z = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
		if (store) { o.v = vid; o.s = sid; o.n = isNew; }
		return o;
	}
	function ev(t, name, label, value) {
		if (off) return;
		if (!ok) { if (queue.length < 20) queue.push([t, name, label, value]); return; }
		var o = base(t);
		o.en = String(name || '').slice(0, 64);
		o.el = String(label == null ? '' : label).slice(0, 300);
		if (value != null && value !== '' && !isNaN(value)) o.ev = +value;
		send(o, 1);
	}

	// Page view + engagement (visible time, deepest scroll).
	function scrolled() {
		var h = d.documentElement, b = d.body || h, tot = Math.max(h.scrollHeight, b.scrollHeight) - h.clientHeight;
		var pct = tot > 0 ? Math.round((w.pageYOffset || h.scrollTop) / tot * 100) : 100;
		if (pct > maxScroll) maxScroll = Math.min(100, pct);
	}
	function pageview() {
		lastUrl = L.href.split('#')[0];
		view = null; shown = 0; maxScroll = 0;
		since = d.visibilityState === 'visible' ? Date.now() : 0;
		scrolled();
		var p = send(base('pageview'));
		if (p) p.then(function (r) { view = r; });
	}
	function flush() {
		if (since) { shown += Date.now() - since; since = 0; }
		if (ok && view && view.id && (c.time || c.scroll)) send({ t: 'ping', id: view.id, tok: view.tok, d: Math.round(shown / 1000), sc: maxScroll }, 1);
	}
	if (c.scroll) w.addEventListener('scroll', scrolled, { passive: true });
	d.addEventListener('visibilitychange', function () {
		if (d.visibilityState === 'hidden') flush(); else since = Date.now();
	});
	w.addEventListener('pagehide', flush);

	// Clicks: data-tmg-event elements, mailto / tel, downloads, outbound links.
	function txt(el) {
		return (el.getAttribute('aria-label') || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 100);
	}
	function onClick(e) {
		var el = e.target && e.target.closest ? e.target.closest('a,[data-tmg-event]') : null;
		if (!el || !ok) return;
		var name = c.attr && el.getAttribute('data-tmg-event');
		if (name) return ev('click', name, el.getAttribute('data-tmg-label') || txt(el), el.getAttribute('data-tmg-value'));
		if (el.tagName !== 'A' || !el.href) return;
		var href = el.href, proto = el.protocol;
		if (proto === 'mailto:') return c.mail && ev('mailto', 'mailto', href.slice(7).split('?')[0]);
		if (proto === 'tel:') return c.tel && ev('tel', 'tel', href.slice(4));
		if (!/^https?:$/.test(proto)) return;
		if (c.dl && (el.hasAttribute('download') || new RegExp('\\.(' + c.dl + ')$', 'i').test(el.pathname))) return ev('download', 'download', href);
		if (c.out && el.hostname && el.hostname !== L.hostname) ev('outbound', 'outbound', href);
	}
	d.addEventListener('click', onClick, true);
	d.addEventListener('auxclick', function (e) { if (e.button === 1) onClick(e); }, true);

	if (c.forms) {
		d.addEventListener('submit', function (e) {
			var f = e.target;
			try { if (!f.matches(c.forms)) return; } catch (x) { return; }
			ev('form', f.getAttribute('data-tmg-event') || f.id || f.getAttribute('name') || 'form', f.getAttribute('action') || L.pathname);
		}, true);
	}

	// Single-page apps: a pushState / back-forward to a new URL is a new page view.
	function nav() {
		setTimeout(function () {
			if (!ok || L.href.split('#')[0] === lastUrl) return;
			flush();
			ref = lastUrl; c.p = 0; c.k = 'spa';
			pageview();
		}, 0);
	}
	var push = history.pushState;
	if (push) history.pushState = function () { var r = push.apply(this, arguments); nav(); return r; };
	w.addEventListener('popstate', nav);

	function fill() {
		var f = d.querySelectorAll('input[name="tmg_vid"],input[data-tmg-visitor]'), i;
		for (i = 0; i < f.length && vid; i++) f[i].value = vid;
	}
	function begin() {
		if (ok || off) return;
		ok = true;
		ids();
		fill();
		pageview();
		var q = queue; queue = [];
		for (var i = 0; i < q.length; i++) ev.apply(null, q[i]);
	}

	var api = w.tamagoReports = {
		v: 1,
		consent: function (yes) {
			if (!store) return;
			if (yes === false) { ok = false; vid = sid = ''; ls('tmg_vid', null); ls('tmg_sid', null); return; }
			begin();
		},
		track: function (name, label, value) { ev('custom', name, label, value); },
		visitorId: function () { return vid; }
	};

	if (!store || cookieOk()) {
		begin();
	} else if (c.cookie) {
		var tries = 0, timer = setInterval(function () {
			if (ok || ++tries > 600) return clearInterval(timer);
			if (cookieOk()) { clearInterval(timer); begin(); }
		}, 1000);
	}
	for (var i = 0; i < stub.length; i++) {
		if (stub[i] && api[stub[i][0]]) api[stub[i][0]].apply(null, [].slice.call(stub[i], 1));
	}
})(window, document);
