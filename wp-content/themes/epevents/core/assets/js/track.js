/* EP Events – first-party analytics beacon. No cookies; IDs live in local/session storage. */
(function () {
	'use strict';
	var cfg = window.EPTrackConfig;
	if (!cfg || !cfg.endpoint) return;
	if (cfg.dnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) return;

	function rid() {
		var a = new Uint8Array(16);
		(window.crypto || window.msCrypto).getRandomValues(a);
		return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	function store(kind, key, fallback) {
		try {
			var s = window[kind], v = s.getItem(key);
			if (!v && fallback) { v = fallback(); s.setItem(key, v); }
			return v;
		} catch (e) { return fallback ? fallback() : null; }
	}

	var returning = !!store('localStorage', 'epc_vid');
	var vid = store('localStorage', 'epc_vid', rid);
	var sid = store('sessionStorage', 'epc_sid', rid);

	// Attribution for this session (UTM + external referrer). New UTM overrides.
	var qs = new URLSearchParams(location.search);
	var utm = {};
	['source', 'medium', 'campaign', 'term', 'content'].forEach(function (k) {
		var v = qs.get('utm_' + k);
		if (v) utm[k] = v.slice(0, 100);
	});
	if (!utm.source && qs.get('fbclid')) { utm.source = 'facebook'; utm.medium = utm.medium || 'social'; }
	if (!utm.source && qs.get('gclid')) { utm.source = 'google'; utm.medium = utm.medium || 'cpc'; }
	var refHost = '';
	try { refHost = document.referrer ? new URL(document.referrer).hostname : ''; } catch (e) {}
	if (refHost === location.hostname) refHost = '';

	try {
		var src = JSON.parse(sessionStorage.getItem('epc_src') || 'null');
		if (!src || Object.keys(utm).length) {
			src = { ref_domain: refHost.replace(/^www\./, ''), utm_source: utm.source || '', utm_medium: utm.medium || '', utm_campaign: utm.campaign || '', landing: location.pathname };
			sessionStorage.setItem('epc_src', JSON.stringify(src));
		}
		// Keep session attribution on internal navigations.
		if (!Object.keys(utm).length && src) {
			if (src.utm_source) utm.source = src.utm_source;
			if (src.utm_medium) utm.medium = src.utm_medium;
			if (src.utm_campaign) utm.campaign = src.utm_campaign;
		}
	} catch (e) {}

	function base(type) {
		var tz = '';
		try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
		return {
			type: type,
			postId: cfg.postId,
			postType: cfg.postType,
			vid: vid,
			sid: sid,
			returning: returning,
			url: location.href,
			ref: document.referrer,
			utm: utm,
			screen: screen.width + 'x' + screen.height,
			viewport: window.innerWidth + 'x' + window.innerHeight,
			lang: navigator.language || '',
			tz: tz,
			touch: ('ontouchend' in document) && navigator.maxTouchPoints > 1
		};
	}

	function send(data, beacon) {
		var body = JSON.stringify(data);
		if (beacon && navigator.sendBeacon) {
			return navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'text/plain' }));
		}
		return fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' }, credentials: 'omit' })
			.then(function (r) { return r.status === 200 ? r.json() : null; })
			.catch(function () { return null; });
	}

	// Page view + engagement.
	var view = null, visible = 0, since = document.visibilityState === 'visible' ? Date.now() : 0, maxScroll = 0;
	send(base('pageview')).then(function (r) { view = r; });

	function scrollPct() {
		var h = document.documentElement, b = document.body;
		var total = Math.max(h.scrollHeight, b.scrollHeight) - h.clientHeight;
		var pct = total > 0 ? Math.round((h.scrollTop || b.scrollTop) / total * 100) : 100;
		if (pct > maxScroll) maxScroll = Math.min(100, pct);
	}
	window.addEventListener('scroll', scrollPct, { passive: true });
	scrollPct();

	function flush() {
		if (since) { visible += Date.now() - since; since = 0; }
		if (view && view.id) send({ type: 'ping', id: view.id, tok: view.tok, duration: Math.round(visible / 1000), scroll: maxScroll }, true);
	}
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') flush();
		else since = Date.now();
	});
	window.addEventListener('pagehide', flush);

	// Clicks on buttons / tracked elements / outbound links.
	document.addEventListener('click', function (e) {
		var el = e.target.closest('a, button');
		if (!el) return;
		var tracked = el.hasAttribute('data-ep-track') || el.classList.contains('ep-btn');
		var outbound = el.tagName === 'A' && el.hostname && el.hostname !== location.hostname;
		if (!tracked && !outbound) return;
		var d = base(outbound ? 'outbound' : 'click');
		d.label = ((el.getAttribute('data-ep-track') || el.textContent || '').trim() + (el.href ? ' → ' + el.href : '')).slice(0, 190);
		send(d, true);
	});

	window.EPTrack = { event: function (type, extra) { var d = base(type); for (var k in extra) d[k] = extra[k]; send(d, true); } };
})();
