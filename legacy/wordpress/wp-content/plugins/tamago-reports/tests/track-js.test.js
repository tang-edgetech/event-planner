/*
 * Front-end beacon smoke test in a fake browser (no dependencies).
 * Run: node tests/track-js.test.js assets/js/track.js
 */
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
let fails = 0;
const ok = (name, cond, extra) => { console.log((cond ? 'PASS ' : 'FAIL ') + name + (cond ? '' : ' ' + JSON.stringify(extra || ''))); if (!cond) fails++; };

function env(cfg, opts = {}) {
	const sent = [], listeners = {}, wl = {};
	const store = {};
	const loc = { href: 'http://site.test/a/?utm_source=x', hostname: 'site.test', pathname: '/a/' };
	const doc = {
		referrer: 'https://google.com/', visibilityState: 'visible', cookie: opts.cookie || '',
		documentElement: { scrollHeight: 2000, clientHeight: 1000, scrollTop: 0 }, body: { scrollHeight: 2000 },
		addEventListener: (t, f) => { (listeners[t] = listeners[t] || []).push(f); },
		querySelectorAll: () => opts.inputs || [],
	};
	const win = {
		tamagoReportsConfig: cfg, innerWidth: 1200, innerHeight: 800, pageYOffset: 0, doNotTrack: null,
		crypto: { getRandomValues: a => { for (let i = 0; i < a.length; i++) a[i] = (i * 37 + 11) & 255; return a; } },
		addEventListener: (t, f) => { (wl[t] = wl[t] || []).push(f); },
		fetch: (url, o) => { sent.push({ via: 'fetch', body: JSON.parse(o.body), o }); return Promise.resolve({ status: 200, json: () => Promise.resolve({ id: 7, tok: 'tk' }) }); },
	};
	if (opts.stub) win.tamagoReports = { q: opts.stub };
	const nav = { language: 'en-MY', doNotTrack: opts.dnt || null, globalPrivacyControl: opts.gpc, maxTouchPoints: 0,
		sendBeacon: (u, b) => { sent.push({ via: 'beacon', blob: b }); return true; } };
	const hist = { pushState: function () { loc.href = arguments[2]; } };
	const ctx = { window: win, document: doc, navigator: nav, location: loc, history: hist, screen: { width: 1920, height: 1080 },
		localStorage: { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: k => { delete store[k]; } },
		Blob: function (parts) { this.text = parts.join(''); }, Uint8Array, Promise, JSON, Math, Date, String, RegExp, Array, Intl, isNaN, setTimeout: (f) => f(), setInterval: () => 1, clearInterval: () => {} };
	ctx.window.document = doc;
	vm.createContext(ctx);
	vm.runInContext(src.replace('})(window, document);', '})(window, document);'), ctx);
	const beacons = () => sent.map(s => s.via === 'beacon' ? JSON.parse(s.blob.text) : s.body);
	return { ctx, sent, beacons, listeners, wl, store, loc, doc, win };
}
const base = { endpoint: 'http://site.test/collect', p: 4, k: 'singular', consent: 'none', cookie: '', cookieV: '', dnt: false, gpc: true, out: true, dl: 'pdf|zip', mail: true, tel: true, attr: true, forms: 'form.track', scroll: true, time: true };
const tick = () => new Promise(r => setImmediate(r));

(async () => {
	// Cookieless pageview.
	let e = env(Object.assign({}, base));
	await tick();
	let b = e.beacons();
	ok('cookieless: one pageview sent by fetch', b.length === 1 && b[0].t === 'pageview' && e.sent[0].via === 'fetch' && e.sent[0].o.keepalive === true && e.sent[0].o.headers['Content-Type'] === 'text/plain');
	ok('cookieless: no ids, nothing stored', !('v' in b[0]) && Object.keys(e.store).length === 0);
	ok('payload fields', b[0].p === 4 && b[0].k === 'singular' && b[0].u === 'http://site.test/a/?utm_source=x' && b[0].r === 'https://google.com/' && b[0].sw === '1920x1080' && b[0].l === 'en-MY');
	// Clicks.
	const click = (el) => e.listeners.click.forEach(f => f({ target: { closest: () => el }, button: 0 }));
	const a = (href, extra = {}) => Object.assign({ tagName: 'A', href, protocol: new URL(href).protocol, hostname: new URL(href).hostname, pathname: new URL(href).pathname, hasAttribute: () => false, getAttribute: () => null, textContent: 'link' }, extra);
	click(a('https://other.test/x'));
	click(a('http://site.test/files/a.PDF'));
	click(a('mailto:hi@x.test?subject=1'));
	click(a('tel:+60123'));
	click(a('http://site.test/internal'));
	click({ tagName: 'BUTTON', getAttribute: k => ({ 'data-tmg-event': 'register', 'data-tmg-label': 'Hero', 'data-tmg-value': '5' })[k] || null, textContent: 'Register' });
	b = e.beacons();
	ok('outbound / download / mailto / tel / data-tmg-event events', JSON.stringify(b.slice(1).map(x => [x.t, x.en, x.el])) === JSON.stringify([['outbound', 'outbound', 'https://other.test/x'], ['download', 'download', 'http://site.test/files/a.PDF'], ['mailto', 'mailto', 'hi@x.test'], ['tel', 'tel', '+60123'], ['click', 'register', 'Hero']]), b.slice(1));
	ok('data-tmg-value sent as number', b[b.length - 1].ev === 5);
	// Forms.
	e.listeners.submit[0]({ target: { matches: s => s === 'form.track', getAttribute: k => (k === 'action' ? '/register' : null), id: 'reg' } });
	ok('form submit event', e.beacons().pop().t === 'form');
	// Custom API.
	e.win.tamagoReports.track('signup', 'footer', 3);
	let last = e.beacons().pop();
	ok('tamagoReports.track()', last.t === 'custom' && last.en === 'signup' && last.el === 'footer' && last.ev === 3);
	// Engagement ping on hide.
	e.doc.documentElement.scrollTop = 1000; e.win.pageYOffset = 1000;
	(e.wl.scroll || []).forEach(f => f());
	e.doc.visibilityState = 'hidden';
	e.listeners.visibilitychange.forEach(f => f());
	last = e.beacons().pop();
	ok('ping on hide with id/token + scroll', last.t === 'ping' && last.id === 7 && last.tok === 'tk' && last.sc === 100 && typeof last.d === 'number');
	// SPA.
	e.doc.visibilityState = 'visible';
	const n0 = e.beacons().length;
	e.ctx.history.pushState({}, '', 'http://site.test/b/');
	await tick();
	b = e.beacons().slice(n0);
	ok('SPA pushState → ping + new pageview (kind spa, previous URL as referrer)', b.some(x => x.t === 'pageview' && x.k === 'spa' && x.p === 0 && x.r === 'http://site.test/a/?utm_source=x'), b);

	// Wait for consent.
	e = env(Object.assign({}, base, { consent: 'wait' }), { inputs: [{ value: '' }] });
	await tick();
	ok('wait mode: nothing before consent', e.sent.length === 0);
	e.win.tamagoReports.track('early', 'x');
	e.win.tamagoReports.consent(true);
	await tick();
	b = e.beacons();
	ok('consent(true): pageview with visitor + session IDs, new visitor', b[0].t === 'pageview' && /^[a-f0-9]{32}$/.test(b[0].v) && /^[a-f0-9]{32}$/.test(b[0].s) && b[0].n === 1, b[0]);
	ok('queued event replayed after consent', b.some(x => x.en === 'early'));
	ok('visitor id kept in localStorage', e.store.tmg_vid === b[0].v && e.win.tamagoReports.visitorId() === b[0].v);
	e.win.tamagoReports.consent(false);
	ok('consent(false) clears storage', !e.store.tmg_vid && !e.store.tmg_sid);

	// Consent cookie.
	e = env(Object.assign({}, base, { consent: 'wait', cookie: 'cmplz_stats', cookieV: 'allow' }), { cookie: 'a=1; cmplz_stats=allow' });
	await tick();
	ok('consent cookie starts tracking', e.beacons().length === 1);
	e = env(Object.assign({}, base, { consent: 'wait', cookie: 'cmplz_stats', cookieV: 'allow' }), { cookie: 'cmplz_stats=deny' });
	await tick();
	ok('wrong cookie value: nothing sent', e.sent.length === 0);

	// Stub queue (calls before the script loaded).
	e = env(Object.assign({}, base, { consent: 'wait' }), { stub: [['consent', true], ['track', 'pre', 'l']] });
	await tick();
	ok('stub queue replayed', e.beacons().some(x => x.en === 'pre'));

	// GPC / DNT.
	e = env(Object.assign({}, base), { gpc: true });
	await tick();
	ok('GPC: nothing sent', e.sent.length === 0);
	e = env(Object.assign({}, base, { dnt: true }), { dnt: '1' });
	await tick();
	ok('DNT (when on): nothing sent', e.sent.length === 0);

	console.log('\nJS TOTAL: ' + (fails ? fails + ' failed' : 'all passed'));
	process.exit(fails ? 1 : 0);
})();
