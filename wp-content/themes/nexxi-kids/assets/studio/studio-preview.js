/* NEXXI Studio – Customizer preview: live text/image updates + region outlines. */
(function (api) {
	'use strict';
	var D = window.NexxiStudioPreview || { titles: {}, defaults: {} };
	var ACCENT = '#fd6a49';
	var NAVY = '#031444';

	/* ---------- live values ---------- */
	function setting(key, fn) {
		api('nexxi_studio[' + key + ']', function (s) { s.bind(fn); });
	}
	function textNodes(el) {
		return Array.prototype.filter.call(el.childNodes, function (n) {
			return n.nodeType === 3 && n.nodeValue.replace(/[\s\u00a0]/g, '') !== '';
		});
	}
	function value(key, v) {
		v = (v || '').trim();
		return v === '' ? (D.defaults[key] || '') : v;
	}
	document.querySelectorAll('[data-nxe]').forEach(function (el) {
		var nodes = textNodes(el);
		el.getAttribute('data-nxe').split(',').forEach(function (key, i) {
			if (key === '-' || !nodes[i]) { return; }
			setting(key, function (v) {
				var n = nodes[i];
				var m = n.nodeValue.match(/^(\s*)[\s\S]*?(\s*)$/);
				n.nodeValue = m[1] + value(key, v).replace(/\s*\n\s*/g, ' ') + m[2];
			});
		});
	});
	document.querySelectorAll('[data-nxi]').forEach(function (el) {
		var key = el.getAttribute('data-nxi');
		setting(key, function (v) { el.removeAttribute('srcset'); el.src = value(key, v); });
	});
	document.querySelectorAll('[data-nxp]').forEach(function (el) {
		var key = el.getAttribute('data-nxp');
		setting(key, function (v) { el.placeholder = value(key, v); });
	});

	/* ---------- overlay ---------- */
	var css = document.createElement('style');
	css.textContent =
		'.nxs-box{position:fixed;pointer-events:none;z-index:2147483600;border-radius:14px;transition:all .12s ease;display:none}' +
		'.nxs-box--hover{border:2px dashed ' + ACCENT + ';background:rgba(253,106,73,.05)}' +
		'.nxs-box--active{border:3px solid ' + ACCENT + ';box-shadow:0 0 0 9999px rgba(3,20,68,.18)}' +
		'.nxs-tag{position:fixed;z-index:2147483601;display:none;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font:700 12px/1.2 Quicksand,system-ui,sans-serif;color:#fff;white-space:nowrap;box-shadow:0 6px 18px rgba(3,20,68,.25)}' +
		'.nxs-tag--hover{background:' + ACCENT + ';cursor:pointer}' +
		'.nxs-tag--active{background:' + NAVY + ';pointer-events:none}' +
		'.nxs-flash{outline:3px solid ' + ACCENT + '!important;outline-offset:4px!important;border-radius:6px;animation:nxsPulse .45s ease-in-out 3}' +
		'@keyframes nxsPulse{50%{outline-color:rgba(253,106,73,.15)}}';
	document.head.appendChild(css);

	function make(cls) { var el = document.createElement('div'); el.className = cls; document.body.appendChild(el); return el; }
	var hoverBox = make('nxs-box nxs-box--hover');
	var hoverTag = make('nxs-tag nxs-tag--hover');
	var activeBox = make('nxs-box nxs-box--active');
	var activeTag = make('nxs-tag nxs-tag--active');
	var hoverEl = null;
	var activeEl = null;

	function title(el) {
		var id = el.getAttribute('data-nxr');
		return D.titles[id] || id;
	}
	function place(box, tag, el) {
		if (!el || !el.isConnected) { box.style.display = 'none'; tag.style.display = 'none'; return; }
		var r = el.getBoundingClientRect();
		if (r.width === 0 && r.height === 0) { box.style.display = 'none'; tag.style.display = 'none'; return; }
		box.style.display = 'block';
		box.style.left = (r.left - 4) + 'px';
		box.style.top = (r.top - 4) + 'px';
		box.style.width = (r.width + 8) + 'px';
		box.style.height = (r.height + 8) + 'px';
		tag.style.display = 'inline-flex';
		tag.style.left = Math.max(6, r.left) + 'px';
		tag.style.top = Math.max(6, r.top - 30) + 'px';
	}
	function refresh() {
		place(hoverBox, hoverTag, hoverEl !== activeEl ? hoverEl : null);
		place(activeBox, activeTag, activeEl);
	}

	document.addEventListener('mouseover', function (e) {
		if (hoverTag.contains(e.target)) { return; }
		var el = e.target.closest ? e.target.closest('[data-nxr]') : null;
		if (el === hoverEl) { return; }
		hoverEl = el;
		if (el) { hoverTag.textContent = '✎ ' + title(el); }
		refresh();
	}, true);
	document.addEventListener('mouseleave', function () { hoverEl = null; refresh(); });
	hoverTag.addEventListener('click', function (e) {
		e.preventDefault();
		e.stopPropagation();
		if (hoverEl) { api.preview.send('nexxi-studio-focus', hoverEl.getAttribute('data-nxr')); }
	});
	window.addEventListener('scroll', refresh, { passive: true });
	window.addEventListener('resize', refresh);
	setInterval(refresh, 400); // Keeps outlines aligned while images / animations settle.

	function reveal(el) {
		var r = el.getBoundingClientRect();
		if (r.top < 90 || r.bottom > window.innerHeight) {
			el.scrollIntoView({ behavior: 'smooth', block: r.height > window.innerHeight * 0.8 ? 'start' : 'center' });
		}
	}

	var FONT_ZONES = {
		font_header:      { sel: 'body > header', label: '① Font Header – Đầu trang (Menu & Thanh thông báo)' },
		font_banner:      { sel: '[data-nxr="home-hero"] h1, [data-nxr="shop-hero"] h1, [data-nxr="flash-hero"] h1, [data-nx="page-hero"] h1, [data-nxr="home-lookbook"] h3', label: '② Font Banner – Tiêu đề lớn trên Banner' },
		font_banner_desc: { sel: '[data-nxr="home-hero"] p, [data-nxr="shop-hero"] p, [data-nxr="flash-hero"] p, [data-nx="page-hero"] p, [data-nxr="home-lookbook"] p', label: '③ Font Banner – Chữ mô tả phụ trong Banner' },
		font_heading:     { sel: '[data-nxr="home-categories"] h2, [data-nxr="home-bestsellers"] h2, [data-nxr="home-commit"] h2, main h2, main h3', label: '④ Font Tiêu đề Khu vực & Tên sản phẩm' },
		font_body:        { sel: '[data-nxr="home-categories"] p, .nexxi-prose, .nexxi-stitch-commerce .woocommerce, .nexxi-account, footer p', label: '⑤ Font Văn bản & Nội dung chi tiết' },
		font_button:      { sel: '[data-nx="home-cta-shop"], [data-nexxi-pdp-buy], [data-nexxi-add], [data-nx="pdp-price"], button', label: '⑥ Font Nút bấm, Nhãn & Giá tiền' }
	};

	api.bind('preview-ready', function () {
		api.preview.bind('nexxi-studio-region', function (id) {
			activeEl = id ? document.querySelector('[data-nxr="' + id + '"]') : null;
			if (activeEl) {
				activeTag.textContent = '✎ Đang chỉnh: ' + title(activeEl);
				reveal(activeEl);
			}
			refresh();
		});
		api.preview.bind('nexxi-studio-field', function (key) {
			if (FONT_ZONES[key]) {
				var z = FONT_ZONES[key];
				var list = document.querySelectorAll(z.sel);
				if (list.length) {
					activeEl = list[0];
					activeTag.textContent = '🔤 ' + z.label;
					reveal(activeEl);
					refresh();
					Array.prototype.slice.call(list, 0, 8).forEach(function (node) {
						node.classList.remove('nxs-flash');
						void node.offsetWidth;
						node.classList.add('nxs-flash');
						setTimeout(function () { node.classList.remove('nxs-flash'); }, 1800);
					});
				}
				return;
			}
			var el = null;
			document.querySelectorAll('[data-nxe]').forEach(function (n) {
				if (!el && n.getAttribute('data-nxe').split(',').indexOf(key) !== -1) { el = n; }
			});
			el = el || document.querySelector('[data-nxi="' + key + '"],[data-nxp="' + key + '"],[data-nxl="' + key + '"]');
			if (!el) { return; }
			reveal(el);
			el.classList.remove('nxs-flash');
			void el.offsetWidth;
			el.classList.add('nxs-flash');
			setTimeout(function () { el.classList.remove('nxs-flash'); }, 1500);
		});
		api.preview.send('nexxi-studio-ready');
	});
})(wp.customize);
