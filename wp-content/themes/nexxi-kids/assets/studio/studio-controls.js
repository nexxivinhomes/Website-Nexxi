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

		/* ---------- Hero Banner Sorter (Drag & Drop + Up/Down Buttons) ---------- */
		function parseHeroOrder(raw) {
			var order = [];
			String(raw || '1,2,3,4,5').split(',').forEach(function (p) {
				var n = parseInt($.trim(p), 10);
				if (n >= 1 && n <= 5 && order.indexOf(n) === -1) {
					order.push(n);
				}
			});
			for (var s = 1; s <= 5; s++) {
				if (order.indexOf(s) === -1) { order.push(s); }
			}
			return order;
		}

		function getHeroOrder() {
			var s = api('nexxi_studio[home-hero__order]');
			return parseHeroOrder(s ? s.get() : '1,2,3,4,5');
		}

		function getSlotImageUrl(slot) {
			var s = api('nexxi_studio[home-hero__bg' + slot + ']');
			if (s) {
				return $.trim(s.get() || '');
			}
			return (D.heroDefaults && D.heroDefaults[slot]) ? D.heroDefaults[slot] : '';
		}

		function setHeroOrder(newOrder, movedSlot) {
			var normalized = parseHeroOrder(newOrder.join(','));
			var str = normalized.join(',');
			var s = api('nexxi_studio[home-hero__order]');
			if (s) {
				s.set(str);
			}
			$('input[data-nxs-hero-order-input]').val(str).trigger('change');
			syncHeroUI(normalized);
			if (movedSlot) {
				api.previewer.send('nexxi-studio-field', 'home-hero__bg' + movedSlot);
			}
		}

		function moveHeroSlot(slot, delta) {
			var order = getHeroOrder();
			var idx = order.indexOf(Number(slot));
			if (idx === -1) { return; }
			var targetIdx = idx + delta;
			if (targetIdx < 0 || targetIdx >= order.length) { return; }
			var temp = order[idx];
			order[idx] = order[targetIdx];
			order[targetIdx] = temp;
			setHeroOrder(order, slot);
		}

		function syncHeroUI(order) {
			order = order || getHeroOrder();
			// 1. Update priorities of the 5 WP_Customize_Image_Control boxes so they reorder in sidebar
			order.forEach(function (slot, idx) {
				var ctrl = api.control('nexxi_studio[home-hero__bg' + slot + ']');
				if (ctrl) {
					ctrl.priority(10 + idx);
					ctrl.deferred.embedded.done(function () {
						var $bar = ctrl.container.find('.nxs-hero-slot-bar');
						if (!$bar.length) {
							$bar = $(
								'<div class="nxs-hero-slot-bar">' +
									'<span class="nxs-hero-slot-bar__badge"></span>' +
									'<div class="nxs-hero-slot-bar__actions">' +
										'<button type="button" class="nxs-hero-slot-bar__btn" data-dir="-1" title="Đưa ảnh này lên trên">↑ Lên</button>' +
										'<button type="button" class="nxs-hero-slot-bar__btn" data-dir="1" title="Đưa ảnh này xuống dưới">↓ Xuống</button>' +
									'</div>' +
								'</div>'
							);
							$bar.on('click', '.nxs-hero-slot-bar__btn', function (e) {
								e.preventDefault();
								e.stopPropagation();
								moveHeroSlot(slot, parseInt($(this).attr('data-dir'), 10));
							});
							ctrl.container.prepend($bar);
						}
						$bar.find('.nxs-hero-slot-bar__badge').text('Thứ tự hiển thị: Vị trí ' + (idx + 1) + ' (Ô #' + slot + ')');
						$bar.find('[data-dir="-1"]').prop('disabled', idx === 0);
						$bar.find('[data-dir="1"]').prop('disabled', idx === order.length - 1);
					});
				}
			});

			// 2. Render the top visual drag-and-drop sorter list
			var $list = $('[data-nxs-hero-sorter]');
			if (!$list.length) { return; }
			$list.empty();
			var dragSlot = null;

			order.forEach(function (slot, idx) {
				var url = getSlotImageUrl(slot);
				var hasImg = url !== '';
				var $li = $(
					'<li class="nxs-hero-sorter__item' + (hasImg ? '' : ' is-empty') + '" draggable="true" data-slot="' + slot + '">' +
						'<span class="nxs-hero-sorter__handle" title="Kéo thả để đổi vị trí">⠿</span>' +
						'<span class="nxs-hero-sorter__pos">' + (idx + 1) + '</span>' +
						(hasImg
							? '<img class="nxs-hero-sorter__thumb" src="' + $('<div>').text(url).html() + '" alt="">'
							: '<span class="nxs-hero-sorter__empty-thumb">Trống</span>') +
						'<div class="nxs-hero-sorter__info">' +
							'<span class="nxs-hero-sorter__label">Ảnh nền Banner #' + slot + '</span>' +
							'<span class="nxs-hero-sorter__status">' + (hasImg ? 'Đang bật · Vị trí ' + (idx + 1) : 'Chưa chọn ảnh') + '</span>' +
						'</div>' +
						'<div class="nxs-hero-sorter__btns">' +
							'<button type="button" class="nxs-hero-sorter__btn" data-dir="-1"' + (idx === 0 ? ' disabled' : '') + ' title="Đưa lên trên">↑</button>' +
							'<button type="button" class="nxs-hero-sorter__btn" data-dir="1"' + (idx === order.length - 1 ? ' disabled' : '') + ' title="Đưa xuống dưới">↓</button>' +
						'</div>' +
					'</li>'
				);

				$li.on('click', function (e) {
					if ($(e.target).closest('.nxs-hero-sorter__btn').length) { return; }
					api.previewer.send('nexxi-studio-field', 'home-hero__bg' + slot);
					var ctrl = api.control('nexxi_studio[home-hero__bg' + slot + ']');
					if (ctrl && ctrl.container && ctrl.container[0]) {
						ctrl.container[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
				});

				$li.find('.nxs-hero-sorter__btn').on('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					moveHeroSlot(slot, parseInt($(this).attr('data-dir'), 10));
				});

				$li.on('dragstart', function (e) {
					dragSlot = slot;
					$li.addClass('is-dragging');
					if (e.originalEvent && e.originalEvent.dataTransfer) {
						e.originalEvent.dataTransfer.effectAllowed = 'move';
						e.originalEvent.dataTransfer.setData('text/plain', String(slot));
					}
				});
				$li.on('dragend', function () {
					dragSlot = null;
					$list.find('.nxs-hero-sorter__item').removeClass('is-dragging is-dragover');
				});
				$li.on('dragover', function (e) {
					e.preventDefault();
					if (dragSlot && dragSlot !== slot) {
						$li.addClass('is-dragover');
					}
				});
				$li.on('dragleave', function () {
					$li.removeClass('is-dragover');
				});
				$li.on('drop', function (e) {
					e.preventDefault();
					$li.removeClass('is-dragover');
					if (!dragSlot || dragSlot === slot) { return; }
					var currentOrder = getHeroOrder();
					var fromIdx = currentOrder.indexOf(Number(dragSlot));
					var toIdx = currentOrder.indexOf(Number(slot));
					if (fromIdx === -1 || toIdx === -1) { return; }
					currentOrder.splice(fromIdx, 1);
					currentOrder.splice(toIdx, 0, Number(dragSlot));
					setHeroOrder(currentOrder, dragSlot);
				});

				$list.append($li);
			});
		}

		var orderCtrl = api.control('nexxi_studio[home-hero__order]');
		if (orderCtrl) {
			orderCtrl.deferred.embedded.done(function () {
				syncHeroUI();
				orderCtrl.container.on('click', '[data-nxs-hero-order-reset]', function (e) {
					e.preventDefault();
					setHeroOrder([1, 2, 3, 4, 5], 1);
				});
			});
		} else {
			setTimeout(function () { syncHeroUI(); }, 300);
		}
		[1, 2, 3, 4, 5].forEach(function (slot) {
			api('nexxi_studio[home-hero__bg' + slot + ']', function (s) {
				s.bind(function () { syncHeroUI(); });
			});
		});
		api('nexxi_studio[home-hero__order]', function (s) {
			s.bind(function (v) { syncHeroUI(parseHeroOrder(v)); });
		});
	});
})(wp.customize, jQuery);
