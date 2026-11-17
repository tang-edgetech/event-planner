/* Tamago Reports admin: SVG time-series chart, real-time refresh, filters, post picker, goals. */
(function () {
	'use strict';
	var A = window.tamagoReportsAdmin || {}, T = A.i18n || {};
	var NS = 'http://www.w3.org/2000/svg';

	function el(tag, attrs, parent) {
		var e = document.createElementNS(NS, tag);
		for (var k in attrs) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	}
	function fmt(n) { return Number(n).toLocaleString(); }
	function niceMax(v) {
		if (v <= 4) return 4;
		var p = Math.pow(10, Math.floor(Math.log10(v))), m = v / p;
		return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p;
	}

	/* ---------- chart ---------- */
	function drawChart(box) {
		var s;
		try { s = JSON.parse(box.getAttribute('data-series')); } catch (e) { return; }
		if (!s || !s.keys) return;
		box.textContent = '';
		var W = Math.max(320, box.clientWidth), H = 260, pl = 44, pr = 12, pt = 12, pb = 28;
		var n = s.keys.length, max = niceMax(Math.max.apply(null, s.views.concat(s.visitors, [0])));
		var x = function (i) { return pl + (n < 2 ? (W - pl - pr) / 2 : i * (W - pl - pr) / (n - 1)); };
		var y = function (v) { return pt + (H - pt - pb) * (1 - v / max); };
		var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: H, role: 'img', 'aria-label': (T.views || 'Views') + ' / ' + (T.visitors || 'Visitors') }, box);

		for (var g = 0; g <= 4; g++) {
			var gv = max * g / 4, gy = y(gv);
			el('line', { x1: pl, x2: W - pr, y1: gy, y2: gy, 'class': 'tmg-grid' }, svg);
			var lt = el('text', { x: pl - 6, y: gy + 4, 'text-anchor': 'end', 'class': 'tmg-axis' }, svg);
			lt.textContent = fmt(Math.round(gv));
		}
		var every = Math.max(1, Math.ceil(n / Math.floor((W - pl - pr) / 70)));
		for (var i = 0; i < n; i += every) {
			var tx = el('text', { x: x(i), y: H - 8, 'text-anchor': 'middle', 'class': 'tmg-axis' }, svg);
			tx.textContent = (s.short && s.short[i]) || s.labels[i];
		}
		function path(vals) {
			return vals.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1); }).join(' ');
		}
		if (n > 1) el('path', { d: path(s.views) + ' L' + x(n - 1) + ' ' + y(0) + ' L' + x(0) + ' ' + y(0) + 'Z', 'class': 'tmg-area' }, svg);
		el('path', { d: path(s.views), 'class': 'tmg-line tmg-line-views' }, svg);
		el('path', { d: path(s.visitors), 'class': 'tmg-line tmg-line-visitors' }, svg);

		var guide = el('line', { y1: pt, y2: H - pb, 'class': 'tmg-guide', visibility: 'hidden' }, svg);
		var d1 = el('circle', { r: 4, 'class': 'tmg-dot-views', visibility: 'hidden' }, svg);
		var d2 = el('circle', { r: 4, 'class': 'tmg-dot-visitors', visibility: 'hidden' }, svg);
		var tip = document.createElement('div');
		tip.className = 'tmg-tip';
		tip.hidden = true;
		box.appendChild(tip);
		var legend = document.createElement('div');
		legend.className = 'tmg-legend';
		legend.innerHTML = '<span class="is-views"></span><span class="is-visitors"></span>';
		legend.children[0].textContent = T.views || 'Page views';
		legend.children[1].textContent = T.visitors || 'Visitors';
		box.appendChild(legend);

		var hit = el('rect', { x: pl, y: pt, width: W - pl - pr, height: H - pt - pb, fill: 'transparent' }, svg);
		function show(evt) {
			var r = svg.getBoundingClientRect(), px = (evt.clientX - r.left) * W / r.width;
			var i = n < 2 ? 0 : Math.max(0, Math.min(n - 1, Math.round((px - pl) / ((W - pl - pr) / (n - 1)))));
			var cx = x(i);
			guide.setAttribute('x1', cx); guide.setAttribute('x2', cx);
			d1.setAttribute('cx', cx); d1.setAttribute('cy', y(s.views[i]));
			d2.setAttribute('cx', cx); d2.setAttribute('cy', y(s.visitors[i]));
			[guide, d1, d2].forEach(function (e) { e.setAttribute('visibility', 'visible'); });
			tip.hidden = false;
			tip.innerHTML = '<b></b><br><span class="is-views"></span><br><span class="is-visitors"></span>';
			tip.children[0].textContent = s.labels[i];
			tip.children[2].textContent = (T.views || 'Page views') + ': ' + fmt(s.views[i]);
			tip.children[4].textContent = (T.visitors || 'Visitors') + ': ' + fmt(s.visitors[i]);
			var left = cx / W * r.width;
			tip.style.left = Math.min(r.width - tip.offsetWidth - 4, Math.max(4, left + 12)) + 'px';
		}
		function hide() {
			[guide, d1, d2].forEach(function (e) { e.setAttribute('visibility', 'hidden'); });
			tip.hidden = true;
		}
		hit.addEventListener('mousemove', show);
		hit.addEventListener('mouseleave', hide);
		hit.addEventListener('touchstart', function (e) { show(e.touches[0]); }, { passive: true });
	}
	var charts = document.querySelectorAll('.tmg-chart[data-series]');
	charts.forEach(drawChart);
	var rz;
	window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(function () { charts.forEach(drawChart); }, 200); });

	/* ---------- real-time ---------- */
	document.querySelectorAll('[data-tmg-realtime]').forEach(function (box) {
		function refresh() {
			if (document.hidden || !A.ajax) return;
			var url = A.ajax + '?action=tmg_reports_realtime&_ajax_nonce=' + encodeURIComponent(A.realtimeNonce) + '&limit=' + encodeURIComponent(box.getAttribute('data-limit') || '10');
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
				if (!res || !res.success) return;
				var d = res.data;
				['m5', 'm30', 'v30', 'time'].forEach(function (k) {
					var t = box.querySelector('[data-k="' + k + '"]');
					if (t) t.textContent = k === 'time' ? d[k] : fmt(d[k]);
				});
				var tb = box.querySelector('tbody');
				tb.textContent = '';
				if (!d.pages.length) {
					var tr0 = tb.insertRow(), c0 = tr0.insertCell();
					c0.colSpan = 2; c0.className = 'tmg-empty'; c0.textContent = T.noPages || '';
				}
				d.pages.forEach(function (p) {
					var tr = tb.insertRow(), a = tr.insertCell(), b = tr.insertCell();
					if (p.url) { var l = document.createElement('a'); l.href = p.url; l.textContent = p.label; a.appendChild(l); } else a.textContent = p.label;
					b.className = 'num'; b.textContent = fmt(p.n);
				});
			}).catch(function () {});
		}
		setInterval(refresh, 15000);
	});

	/* ---------- lazy report sections (long filtered ranges), 3 at a time ---------- */
	var pending = Array.prototype.slice.call(document.querySelectorAll('[data-tmg-section]'));
	function nextSection() {
		var box = pending.shift();
		if (!box) return;
		fetch(box.getAttribute('data-tmg-section'), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				// Server-rendered, escaped HTML from our own endpoint.
				if (res && res.success) box.innerHTML = res.data.html; else throw new Error('load');
			})
			['catch'](function () { box.innerHTML = '<p class="tmg-empty">' + (T.loadError || 'Could not load this section.') + '</p>'; })
			.then(nextSection);
	}
	for (var c = 0; c < 3; c++) nextSection();

	/* ---------- toolbar + filters ---------- */
	var range = document.getElementById('tmg-range');
	if (range) range.addEventListener('change', function () {
		var c = document.querySelector('.tmg-custom-dates');
		if (c) c.hidden = range.value !== 'custom';
		if (range.value !== 'custom') range.form.submit();
	});
	var fk = document.getElementById('tmg-filter-key'), fv = document.getElementById('tmg-filter-value'), fa = document.getElementById('tmg-filter-add');
	if (fk && fv && fa) {
		fk.addEventListener('change', function () {
			fv.hidden = fa.hidden = !fk.value;
			fv.name = fk.value ? 'f[' + fk.value + ']' : '';
			if (fk.value) fv.focus();
		});
	}

	/* ---------- settings: post picker ---------- */
	document.querySelectorAll('.tmg-picker').forEach(function (pk) {
		var input = pk.querySelector('.tmg-pick-search'), list = pk.querySelector('.tmg-pick-results'), picked = pk.querySelector('.tmg-picked'), timer;
		pk.addEventListener('click', function (e) {
			if (e.target.classList.contains('tmg-unpick')) e.target.closest('li').remove();
		});
		if (!input) return;
		input.addEventListener('input', function () {
			clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 2 && !/^\d+$/.test(q)) { list.hidden = true; return; }
			timer = setTimeout(function () {
				fetch(A.ajax + '?action=tmg_reports_find_posts&_ajax_nonce=' + encodeURIComponent(A.searchNonce) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); }).then(function (res) {
						list.textContent = '';
						var items = res && res.success ? res.data : [];
						if (!items.length) { var li0 = document.createElement('li'); li0.textContent = T.noMatch || ''; list.appendChild(li0); }
						items.forEach(function (it) {
							var li = document.createElement('li'), b = document.createElement('button');
							b.type = 'button'; b.className = 'button-link';
							b.textContent = it.title + ' (#' + it.id + ' · ' + it.type + ')';
							b.addEventListener('click', function () {
								if (!picked.querySelector('input[value="' + it.id + '"]')) {
									var row = document.createElement('li'), h = document.createElement('input'), x = document.createElement('button');
									h.type = 'hidden'; h.name = pk.getAttribute('data-name'); h.value = it.id;
									x.type = 'button'; x.className = 'button-link tmg-unpick'; x.textContent = '×'; x.setAttribute('aria-label', T.remove || 'Remove');
									row.appendChild(h); row.appendChild(document.createTextNode(it.title + ' #' + it.id + ' ')); row.appendChild(x);
									picked.appendChild(row);
								}
								list.hidden = true; input.value = '';
							});
							li.appendChild(b); list.appendChild(li);
						});
						list.hidden = false;
					}).catch(function () {});
			}, 250);
		});
	});

	/* ---------- settings: goals ---------- */
	var goals = document.getElementById('tmg-goals'), tpl = document.getElementById('tmg-goal-template'), add = document.getElementById('tmg-add-goal');
	if (goals && tpl && add) {
		var next = goals.tBodies[0].rows.length + 1000;
		add.addEventListener('click', function () {
			var html = tpl.innerHTML.replace(/__i__/g, String(next++));
			var tb = document.createElement('tbody');
			tb.innerHTML = html;
			goals.tBodies[0].appendChild(tb.firstElementChild);
		});
		goals.addEventListener('click', function (e) {
			if (e.target.classList.contains('tmg-remove-goal')) e.target.closest('tr').remove();
		});
	}
})();
