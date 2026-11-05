/* Branding page: live preview of colours, fonts, shapes and logo while editing. */
(function () {
	'use strict';
	var cfg = window.EPTBrand || {};
	var box = document.getElementById('ept-brand-preview');
	if (!box) return;

	box.innerHTML =
		'<div class="eptp-label">Live preview</div>' +
		'<div class="eptp">' +
			'<div class="eptp-head"><span class="eptp-logo"></span><nav><span>Programme</span><span>Speakers</span><span class="eptp-btn eptp-btn--sm">Register</span></nav></div>' +
			'<div class="eptp-hero"><span class="eptp-badge">13 September 2026</span><h2>AstraZeneca Complement Day</h2><p>Hilton Kuala Lumpur · 8:30am</p><span class="eptp-btn eptp-btn--light">Register now</span></div>' +
			'<div class="eptp-body">' +
				'<div class="eptp-card"><h3>Registration</h3><p>Fields marked * are required.</p><label>Email address *</label><span class="eptp-input">meiling.lim@hospital.my</span><span class="eptp-btn">Submit</span></div>' +
				'<div class="eptp-card"><div class="eptp-img"></div><h3>Our speakers</h3><p>Leading clinicians share the latest evidence.</p><a>Read more →</a></div>' +
			'</div>' +
			'<div class="eptp-foot"><span class="eptp-logo eptp-logo--light"></span><span class="eptp-copy"></span></div>' +
		'</div>';

	var loaded = {};
	function loadFont(name) {
		var f = cfg.fonts && cfg.fonts[name];
		if (!f || loaded[name]) return;
		loaded[name] = true;
		var l = document.createElement('link');
		l.rel = 'stylesheet';
		l.href = 'https://fonts.googleapis.com/css2?family=' + name.replace(/ /g, '+') + ':wght@' + f.w + '&display=swap';
		document.head.appendChild(l);
	}
	function stack(name) {
		var sans = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
		if (!name || name === 'system') return sans;
		if (name === 'custom') { var c = val('brand_font_custom'); return c ? "'" + c + "', " + sans : sans; }
		var serif = cfg.fonts[name] && cfg.fonts[name].kind === 'serif';
		return "'" + name + "', " + (serif ? 'Georgia, serif' : sans);
	}
	function field(name) { return document.querySelector('.acf-field[data-name="' + name + '"]'); }
	function val(name) {
		var f = field(name);
		if (!f) return cfg.defaults[name];
		var radio = f.querySelector('input[type=radio]:checked');
		if (radio) return radio.value;
		// ACF adds an empty hidden input before selects, so look for the real control first.
		var el = f.querySelector('select') || f.querySelector('input:not([type=radio]):not([type=hidden]):not([type=checkbox])') || f.querySelector('input[type=hidden]');
		var v = el ? el.value : '';
		return v === '' || v == null ? cfg.defaults[name] : v;
	}
	function img(name) {
		var f = field(name);
		var i = f && f.querySelector('.image-wrap img');
		return i && i.getAttribute('src') && f.querySelector('.acf-image-uploader.has-value') ? i.getAttribute('src') : '';
	}
	function contrast(hex) {
		var h = String(hex || '').replace('#', '');
		if (h.length === 3) h = h.replace(/(.)/g, '$1$1');
		var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
		return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255 < 0.55 ? '#fff' : '#141414';
	}

	var last = '';
	function render() {
		var heading = val('brand_font_heading'), body = val('brand_font_body');
		loadFont(heading); loadFont(body);
		var corners = { square: ['4px', '4px'], soft: ['14px', '8px'], round: ['22px', '12px'] }[val('brand_corners')] || ['14px', '8px'];
		var btn = { pill: '999px', rounded: '10px', square: '4px' }[val('brand_buttons')] || '999px';
		var logo = img('brand_logo') || cfg.logo || '';
		var logoLight = img('brand_logo_light') || logo;
		var state = [heading, body, corners, btn, logo, logoLight, val('brand_primary'), val('brand_secondary'), val('brand_page'), val('brand_surface'), val('brand_text'), val('brand_muted'), val('brand_border'), val('brand_footer_bg'), val('brand_footer_text'), val('brand_heading_weight'), val('brand_font_size'), val('brand_logo_height'), val('brand_copyright'), val('brand_font_custom')].join('|');
		if (state === last) return;
		last = state;
		var s = box.style;
		s.setProperty('--p', val('brand_primary'));
		s.setProperty('--pc', contrast(val('brand_primary')));
		s.setProperty('--s', val('brand_secondary'));
		s.setProperty('--sc', contrast(val('brand_secondary')));
		s.setProperty('--page', val('brand_page'));
		s.setProperty('--surface', val('brand_surface'));
		s.setProperty('--text', val('brand_text'));
		s.setProperty('--muted', val('brand_muted'));
		s.setProperty('--border', val('brand_border'));
		s.setProperty('--fbg', val('brand_footer_bg'));
		s.setProperty('--ftx', val('brand_footer_text'));
		s.setProperty('--fh', stack(heading));
		s.setProperty('--fb', stack(body));
		s.setProperty('--hw', val('brand_heading_weight'));
		s.setProperty('--fs', val('brand_font_size') + 'px');
		s.setProperty('--r', corners[0]);
		s.setProperty('--ri', corners[1]);
		s.setProperty('--rb', btn);
		s.setProperty('--lh', Math.max(20, Math.min(120, parseInt(val('brand_logo_height'), 10) || 40)) * 0.75 + 'px');
		box.querySelectorAll('.eptp-logo').forEach(function (el) {
			var src = el.classList.contains('eptp-logo--light') ? logoLight : logo;
			if (src) el.innerHTML = '<img alt="" src="' + src.replace(/"/g, '') + '">';
			else el.textContent = cfg.site;
		});
		box.querySelector('.eptp-copy').textContent = String(val('brand_copyright') || '').replace('{year}', new Date().getFullYear()).replace('{site}', cfg.site);
	}
	render();
	setInterval(render, 400);
})();
