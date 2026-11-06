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

		/* ---------- Dynamic Footer Extra Links Repeater ---------- */
		function escHtml(str) {
			return String(str || '')
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function buildQuickLinksOptionsHtml() {
			var list = Array.isArray(D.quickLinks) ? D.quickLinks : [];
			var html = '<option value="">— Chọn nhanh trang có sẵn (tự điền link) —</option>';
			var groups = {};
			var groupOrder = [];
			list.forEach(function (item) {
				if (!item || !item.url) { return; }
				var g = item.group || 'Trang hệ thống';
				if (!groups[g]) {
					groups[g] = [];
					groupOrder.push(g);
				}
				groups[g].push(item);
			});
			groupOrder.forEach(function (g) {
				html += '<optgroup label="' + escHtml(g) + '">';
				groups[g].forEach(function (item) {
					html += '<option value="' + escHtml(item.url) + '" data-title="' + escHtml(item.title || item.label || '') + '">' + escHtml(item.label || item.title || item.url) + '</option>';
				});
				html += '</optgroup>';
			});
			return html;
		}

		var quickLinksOptionsHtml = buildQuickLinksOptionsHtml();

		function parseFooterLinks(raw) {
			if (!raw) { return []; }
			var arr = raw;
			if (!Array.isArray(arr)) {
				try {
					arr = JSON.parse(String(raw));
				} catch (e) {
					return [];
				}
			}
			if (!Array.isArray(arr)) { return []; }
			return arr.slice(0, 30).map(function (it) {
				return {
					label: (it && typeof it.label === 'string') ? it.label : '',
					url: (it && typeof it.url === 'string') ? it.url : '',
					new_tab: !!(it && it.new_tab && it.new_tab !== '0' && it.new_tab !== 'false')
				};
			});
		}

		function serializeCleanFooterLinks(items) {
			var clean = [];
			(items || []).forEach(function (it) {
				if (clean.length >= 30 || !it) { return; }
				var lbl = $.trim(it.label || '');
				if (!lbl) { return; }
				clean.push({
					label: lbl,
					url: $.trim(it.url || ''),
					new_tab: it.new_tab ? 1 : 0
				});
			});
			return clean.length ? JSON.stringify(clean) : '';
		}

		function syncQuickSelectValue($select, urlVal) {
			var clean = $.trim(urlVal || '');
			if (!clean) {
				$select.val('');
				return;
			}
			var found = false;
			$select.find('option').each(function () {
				if (this.value && this.value === clean) {
					found = true;
					return false;
				}
			});
			$select.val(found ? clean : '');
		}

		function initFooterLinksControl(control) {
			var isFooterLinks = (control && control.params && control.params.type === 'nexxi-footer-links') ||
				(control && control.id && /_extra_links\]$/.test(control.id));
			if (!isFooterLinks) { return; }

			control.deferred.embedded.done(function () {
				var $wrap = control.container.find('[data-nxs-footer-links]');
				if (!$wrap.length || $wrap.data('nxsFlBound')) { return; }
				$wrap.data('nxsFlBound', true);

				var setting = control.setting || api(control.id);
				var $hidden = $wrap.find('[data-nxs-fl-input]');
				var $list = $wrap.find('[data-nxs-fl-list]');
				var $empty = $wrap.find('[data-nxs-fl-empty]');
				var $addBtn = $wrap.find('[data-nxs-fl-add]');

				var items = parseFooterLinks(setting ? setting.get() : $hidden.val());
				var debounceTimer = null;
				var isSelfUpdating = false;

				function commitImmediate() {
					if (debounceTimer) {
						clearTimeout(debounceTimer);
						debounceTimer = null;
					}
					if (!setting) { return; }
					var nextJson = serializeCleanFooterLinks(items);
					var prevJson = String(setting.get() || '');
					if (nextJson === prevJson) { return; }
					isSelfUpdating = true;
					setting.set(nextJson);
					$hidden.val(nextJson);
					isSelfUpdating = false;
				}

				function commitDebounced() {
					if (debounceTimer) {
						clearTimeout(debounceTimer);
					}
					debounceTimer = setTimeout(function () {
						debounceTimer = null;
						commitImmediate();
					}, 450);
				}

				function updateCardTitle($card, item) {
					var txt = $.trim(item.label || '');
					$card.find('[data-nxs-fl-title]').text(txt || 'Liên kết mới (chưa nhập tên)');
					$card.toggleClass('is-unsaved', !txt);
				}

				function refreshHeadersAndButtons() {
					var total = items.length;
					$empty.toggle(total === 0);
					$addBtn.prop('disabled', total >= 30);
					$list.children('.nxs-fl-card').each(function (idx) {
						var $c = $(this);
						$c.find('[data-nxs-fl-num]').text('#' + (idx + 1));
						$c.find('[data-fl-move="-1"]').prop('disabled', idx === 0);
						$c.find('[data-fl-move="1"]').prop('disabled', idx === total - 1);
					});
				}

				function buildCard(item) {
					var $card = $(
						'<div class="nxs-fl-card">' +
							'<div class="nxs-fl-card__head">' +
								'<div class="nxs-fl-card__meta">' +
									'<span class="nxs-fl-card__num" data-nxs-fl-num>#1</span>' +
									'<span class="nxs-fl-card__title" data-nxs-fl-title></span>' +
								'</div>' +
								'<div class="nxs-fl-card__actions">' +
									'<button type="button" class="nxs-fl-btn nxs-fl-btn--move" data-fl-move="-1" title="Đưa liên kết lên trên" aria-label="Đưa lên trên">↑</button>' +
									'<button type="button" class="nxs-fl-btn nxs-fl-btn--move" data-fl-move="1" title="Đưa liên kết xuống dưới" aria-label="Đưa xuống dưới">↓</button>' +
									'<button type="button" class="nxs-fl-btn nxs-fl-btn--del" data-fl-del title="Xóa liên kết này">✕ Xóa</button>' +
								'</div>' +
							'</div>' +
							'<div class="nxs-fl-card__body">' +
								'<label class="nxs-fl-field">' +
									'<span class="nxs-fl-field__label">Chữ liên kết (Tên hiển thị)</span>' +
									'<input type="text" class="nxs-fl-input nxs-fl-label" placeholder="Vd: Blog Góc Của Mẹ, Chính sách Sỉ...">' +
								'</label>' +
								'<label class="nxs-fl-field">' +
									'<span class="nxs-fl-field__label">Chọn nhanh trang có sẵn</span>' +
									'<select class="nxs-fl-select nxs-fl-quick">' + quickLinksOptionsHtml + '</select>' +
								'</label>' +
								'<label class="nxs-fl-field">' +
									'<span class="nxs-fl-field__label">Link khi bấm (URL)</span>' +
									'<input type="text" class="nxs-fl-input nxs-fl-url" placeholder="Vd: /gioi-thieu/ hoặc https://shopee.vn/...">' +
								'</label>' +
								'<label class="nxs-fl-check">' +
									'<input type="checkbox" class="nxs-fl-newtab">' +
									'<span>Mở liên kết trong tab mới</span>' +
								'</label>' +
							'</div>' +
						'</div>'
					);

					var $labelInput = $card.find('.nxs-fl-label');
					var $quickSelect = $card.find('.nxs-fl-quick');
					var $urlInput = $card.find('.nxs-fl-url');
					var $newTabCheck = $card.find('.nxs-fl-newtab');

					$labelInput.val(item.label || '');
					$urlInput.val(item.url || '');
					$newTabCheck.prop('checked', !!item.new_tab);
					syncQuickSelectValue($quickSelect, item.url);
					updateCardTitle($card, item);

					// Vietnamese IME-safe debounced updates on Label
					var composingLabel = false;
					$labelInput.on('compositionstart', function () {
						composingLabel = true;
					});
					$labelInput.on('compositionend', function () {
						composingLabel = false;
						item.label = this.value;
						updateCardTitle($card, item);
						commitDebounced();
					});
					$labelInput.on('input', function (e) {
						item.label = this.value;
						updateCardTitle($card, item);
						if (composingLabel || (e.originalEvent && e.originalEvent.isComposing)) { return; }
						commitDebounced();
					});
					$labelInput.on('change blur', function () {
						if (composingLabel) { return; }
						item.label = this.value;
						updateCardTitle($card, item);
						commitImmediate();
					});

					// Quick Page Selector -> fills URL and suggests Label if empty
					$quickSelect.on('change', function () {
						var chosenUrl = String($(this).val() || '');
						if (!chosenUrl) { return; }
						var $opt = $(this).find('option:selected');
						var suggestedTitle = $.trim($opt.attr('data-title') || '');
						item.url = chosenUrl;
						$urlInput.val(chosenUrl);
						if (!$.trim(item.label || '') && suggestedTitle) {
							item.label = suggestedTitle;
							$labelInput.val(suggestedTitle);
							updateCardTitle($card, item);
						}
						commitImmediate();
					});

					// IME-safe debounced updates on URL
					var composingUrl = false;
					$urlInput.on('compositionstart', function () {
						composingUrl = true;
					});
					$urlInput.on('compositionend', function () {
						composingUrl = false;
						item.url = this.value;
						syncQuickSelectValue($quickSelect, item.url);
						commitDebounced();
					});
					$urlInput.on('input', function (e) {
						item.url = this.value;
						syncQuickSelectValue($quickSelect, item.url);
						if (composingUrl || (e.originalEvent && e.originalEvent.isComposing)) { return; }
						commitDebounced();
					});
					$urlInput.on('change blur', function () {
						if (composingUrl) { return; }
						item.url = this.value;
						syncQuickSelectValue($quickSelect, item.url);
						commitImmediate();
					});

					// Open in new tab checkbox -> immediate update
					$newTabCheck.on('change', function () {
						item.new_tab = $(this).is(':checked');
						commitImmediate();
					});

					// Move Up / Down -> immediate DOM move + setting update
					$card.on('click', '[data-fl-move]', function (e) {
						e.preventDefault();
						e.stopPropagation();
						var dir = parseInt($(this).attr('data-fl-move'), 10);
						var idx = items.indexOf(item);
						if (idx === -1) { return; }
						var targetIdx = idx + dir;
						if (targetIdx < 0 || targetIdx >= items.length) { return; }
						var tmp = items[idx];
						items[idx] = items[targetIdx];
						items[targetIdx] = tmp;
						if (dir < 0) {
							$card.insertBefore($card.prev('.nxs-fl-card'));
						} else {
							$card.insertAfter($card.next('.nxs-fl-card'));
						}
						refreshHeadersAndButtons();
						commitImmediate();
					});

					// Delete card -> immediate removal + setting update (only reloads preview if card had a saved label)
					$card.on('click', '[data-fl-del]', function (e) {
						e.preventDefault();
						e.stopPropagation();
						var idx = items.indexOf(item);
						if (idx !== -1) {
							items.splice(idx, 1);
						}
						$card.remove();
						refreshHeadersAndButtons();
						commitImmediate();
					});

					return $card;
				}

				function renderAllCards() {
					$list.empty();
					items.forEach(function (it) {
						$list.append(buildCard(it));
					});
					refreshHeadersAndButtons();
				}

				$addBtn.on('click', function (e) {
					e.preventDefault();
					if (items.length >= 30) { return; }
					var newItem = { label: '', url: '', new_tab: false };
					items.push(newItem);
					var $newCard = buildCard(newItem);
					$list.append($newCard);
					refreshHeadersAndButtons();
					$newCard.find('.nxs-fl-label').trigger('focus');
				});

				if (setting) {
					setting.bind(function (nextVal) {
						if (isSelfUpdating) { return; }
						var normalizedIncoming = String(nextVal || '');
						if (normalizedIncoming === serializeCleanFooterLinks(items)) { return; }
						items = parseFooterLinks(normalizedIncoming);
						renderAllCards();
					});
				}

				renderAllCards();
			});
		}

		api.control.each(initFooterLinksControl);
		api.control.bind('add', initFooterLinksControl);
	});
})(wp.customize, jQuery);
