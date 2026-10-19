/* NEXXI Studio – Customizer controls: keeps the preview on the right page and highlights what is being edited. */
(function (api, $) {
	'use strict';
	var D = window.NexxiStudioControls || { regions: {} };
	var current = null;

	function path(url) {
		try { var u = new URL(url, window.location.href); return u.pathname.replace(/\/+$/, '') || '/'; } catch (e) { return url; }
	}
	function showRegion(id) {
		current = id;
		var region = D.regions[id];
		if (region && region.url && path(api.previewer.previewUrl.get()) !== path(region.url)) {
			api.previewer.previewUrl.set(region.url); // Highlight is sent again once the new page is ready.
			return;
		}
		api.previewer.send('nexxi-studio-region', id);
	}

	api.bind('ready', function () {
		Object.keys(D.regions).forEach(function (id) {
			api.section('nxs_' + id, function (section) {
				section.expanded.bind(function (expanded) {
					if (expanded) { showRegion(id); }
					else if (current === id) { current = null; api.previewer.send('nexxi-studio-region', null); }
				});
			});
		});

		function resend() {
			if (current) { setTimeout(function () { api.previewer.send('nexxi-studio-region', current); }, 150); }
		}
		api.previewer.bind('nexxi-studio-ready', resend);
		api.previewer.bind('ready', resend);
		api.previewer.bind('nexxi-studio-focus', function (id) {
			var section = api.section('nxs_' + id);
			if (section) { section.focus(); }
		});

		function attach(control) {
			var m = /^nexxi_studio\[(.+)\]$/.exec(control.id);
			if (!m) { return; }
			control.deferred.embedded.done(function () {
				control.container.on('focusin click', function () { api.previewer.send('nexxi-studio-field', m[1]); });
			});
		}
		api.control.each(attach);
		api.control.bind('add', attach);

		// “Xem trước” links inside help blocks navigate the preview instead of leaving the Customizer.
		$(document).on('click', '.nxs-preview-link', function (e) {
			e.preventDefault();
			api.previewer.previewUrl.set($(this).data('url'));
		});

		// Keep custom font slot labels in all zone dropdowns updated live when a font file is uploaded or named.
		function refreshCustomFontLabels() {
			[1, 2, 3].forEach(function (slot) {
				var nameSetting = api('nexxi_studio[custom_font_' + slot + '_name]');
				var fileSetting = api('nexxi_studio[custom_font_' + slot + '_file]');
				var name = nameSetting ? $.trim(nameSetting.get() || '') : '';
				var file = fileSetting ? $.trim(fileSetting.get() || '') : '';
				if (!name && file) {
					var base = file.split('/').pop().replace(/\.[^.]+$/, '');
					name = base.replace(/[-_]+/g, ' ');
				}
				var label = '📁 Font tải lên #' + slot + ' (tải file ở mục bên dưới)';
				if (name && file) {
					label = '📁 Font tải lên #' + slot + ': ' + name;
				} else if (name) {
					label = '📁 Font tải lên #' + slot + ': ' + name + ' (chưa chọn file)';
				}
				$('select[data-customize-setting-link^="nexxi_studio[font_"] option[value="custom_' + slot + '"]').text(label);
			});
		}
		[1, 2, 3].forEach(function (slot) {
			api('nexxi_studio[custom_font_' + slot + '_name]', function (s) { s.bind(refreshCustomFontLabels); });
			api('nexxi_studio[custom_font_' + slot + '_file]', function (s) { s.bind(refreshCustomFontLabels); });
		});
	});
})(wp.customize, jQuery);
