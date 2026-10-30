/**
 * NEXXI Web Product Manager — Tab 3 "Quản lý sản phẩm trên Web" (trang 🥞 Pancake SKU).
 * Danh sách / lọc / sửa toàn bộ thông tin / xóa sản phẩm, xuất file CSV & nhập file sửa hàng loạt (chỉ mục thay đổi).
 */
(function () {
	'use strict';

	const C = window.NXWPM;
	const root = document.getElementById('nxwpmRoot');
	if (!C || !root) return;

	const $ = (s, el = document) => el.querySelector(s);
	const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const stripTags = (s) => String(s ?? '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

	const STATUS = { publish: ['Hiển thị', 'ok'], draft: ['Bản nháp', 'warn'], private: ['Riêng tư', 'info'], pending: ['Chờ duyệt', 'warn'] };
	const STOCK = { instock: ['Còn hàng', 'ok'], outofstock: ['Hết hàng', 'err'], onbackorder: ['Đặt trước', 'warn'] };

	const state = {
		paged: 1,
		loaded: false,
		selected: new Set(),
		rows: [],
		edit: null,
		snapshot: '',
		importToken: '',
		importKey: '',
		searchTimer: null,
	};

	async function post(action, data = {}) {
		const fd = new FormData();
		fd.append('action', 'nxwpm_' + action);
		fd.append('nonce', C.nonce);
		Object.keys(data).forEach((k) => fd.append(k, data[k]));
		try {
			const r = await fetch(C.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
			const t = await r.text();
			try {
				return JSON.parse(t);
			} catch (e) {
				return { success: false, data: { message: 'Máy chủ trả về dữ liệu không hợp lệ: ' + stripTags(t).slice(0, 220) } };
			}
		} catch (e) {
			return { success: false, data: { message: 'Lỗi kết nối mạng.' } };
		}
	}

	function toast(msg, type = 'ok') {
		const el = $('#nxwpmToast');
		el.textContent = msg;
		el.className = 'nxwpm-toast is-' + type;
		el.hidden = false;
		clearTimeout(toast._t);
		toast._t = setTimeout(() => { el.hidden = true; }, 4200);
	}

	function badge(map, key) {
		const m = map[key] || [key || '—', 'info'];
		return '<span class="nxp-badge nxp-badge-' + m[1] + '">' + esc(m[0]) + '</span>';
	}

	/* ======================================================================
	 * DANH SÁCH SẢN PHẨM
	 * ==================================================================== */

	function getFilters() {
		const o = {};
		new FormData($('#nxwpmFilter')).forEach((v, k) => { o[k] = String(v); });
		return o;
	}

	async function loadList(paged) {
		state.paged = paged || state.paged || 1;
		state.loaded = true;
		const wrap = $('#nxwpmTableWrap');
		wrap.classList.add('is-loading');
		const res = await post('list', Object.assign({ paged: state.paged }, getFilters()));
		wrap.classList.remove('is-loading');
		if (!res.success) {
			wrap.innerHTML = '<div class="nxwpm-empty is-err">' + esc(res.data?.message || 'Không tải được danh sách') + '</div>';
			return;
		}
		const d = res.data;
		state.rows = d.rows;
		Object.keys(d.counts || {}).forEach((k) => {
			const el = $('[data-stat="' + k + '"]', root);
			if (el) el.textContent = Number(d.counts[k]).toLocaleString('vi-VN');
		});
		renderTable(d);
		renderPager(d);
	}

	function renderTable(d) {
		const wrap = $('#nxwpmTableWrap');
		if (!d.rows.length) {
			wrap.innerHTML = '<div class="nxwpm-empty">Không có sản phẩm nào khớp bộ lọc.</div>';
			return;
		}
		let h = '<table class="nxp-table nxwpm-table"><thead><tr>';
		h += '<th style="width:30px;"><input type="checkbox" id="nxwpmCheckAll"></th>';
		h += '<th style="width:56px;">Ảnh</th><th>Sản phẩm</th><th>Kiểu</th><th>Giá</th><th>Kho</th><th>Trạng thái</th><th>SEO</th><th>Cập nhật</th><th style="width:170px;">Thao tác</th>';
		h += '</tr></thead><tbody>';
		d.rows.forEach((r) => {
			const checked = state.selected.has(r.id) ? ' checked' : '';
			const thumb = r.thumb ? '<img class="nxp-mini-img" src="' + esc(r.thumb) + '" alt="">' : '<div class="nxp-mini-img nxwpm-noimg">🚫</div>';
			const seo = r.seo_ok ? '<span class="nxp-badge nxp-badge-ok">✓ Đủ</span>' : (r.seo_partial ? '<span class="nxp-badge nxp-badge-warn">Thiếu</span>' : '<span class="nxp-badge nxp-badge-err">Chưa có</span>');
			const stock = r.stock === null ? badge(STOCK, r.stock_status) : '<strong>' + r.stock + '</strong> ' + badge(STOCK, r.stock_status);
			h += '<tr data-id="' + r.id + '">';
			h += '<td><input type="checkbox" class="nxwpm-check" value="' + r.id + '"' + checked + '></td>';
			h += '<td>' + thumb + '</td>';
			h += '<td><a href="#" class="nxwpm-open nxwpm-name" data-id="' + r.id + '">' + esc(r.name) + '</a>';
			h += '<div class="nxwpm-sub">ID #' + r.id + ' • SKU: <code>' + esc(r.sku || '—') + '</code>' + (r.gallery ? ' • ' + r.gallery + ' ảnh album' : '') + '</div>';
			if (r.categories) h += '<div class="nxwpm-sub">📁 ' + esc(r.categories) + '</div>';
			h += '</td>';
			h += '<td>' + (r.type === 'variable' ? '<span class="nxp-badge nxp-badge-info">' + r.variations + ' biến thể</span>' : '<span class="nxwpm-sub">Đơn giản</span>') + '</td>';
			h += '<td class="nxwpm-price">' + (r.price_html || '—') + '</td>';
			h += '<td>' + stock + '</td>';
			h += '<td>' + badge(STATUS, r.status) + '</td>';
			h += '<td>' + seo + '</td>';
			h += '<td class="nxwpm-sub">' + esc(r.modified) + '</td>';
			h += '<td><div class="nxwpm-row-actions">';
			h += '<button type="button" class="nxp-btn nxp-btn-navy nxp-btn-sm nxwpm-open" data-id="' + r.id + '">✏️ Sửa</button>';
			h += '<a class="nxp-btn nxp-btn-outline nxp-btn-sm" href="' + esc(r.view_url) + '" target="_blank" rel="noopener" title="Xem trên web">👁</a>';
			h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm nxwpm-trash-one" data-id="' + r.id + '" title="Chuyển thùng rác">🗑️</button>';
			h += '</div></td></tr>';
		});
		h += '</tbody></table>';
		h += '<div class="nxwpm-sub" style="margin-top:8px;">Hiển thị ' + d.rows.length + ' / ' + Number(d.total).toLocaleString('vi-VN') + ' sản phẩm khớp bộ lọc.</div>';
		wrap.innerHTML = h;
		syncCheckAll();
	}

	function renderPager(d) {
		const el = $('#nxwpmPager');
		if (d.pages <= 1) { el.innerHTML = ''; return; }
		const cur = d.paged;
		const pages = new Set([1, d.pages, cur - 2, cur - 1, cur, cur + 1, cur + 2]);
		let h = '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" data-page="' + (cur - 1) + '"' + (cur <= 1 ? ' disabled' : '') + '>‹ Trước</button>';
		let last = 0;
		Array.from(pages).filter((p) => p >= 1 && p <= d.pages).sort((a, b) => a - b).forEach((p) => {
			if (p - last > 1) h += '<span class="nxwpm-sub">…</span>';
			h += '<button type="button" class="nxp-btn nxp-btn-sm ' + (p === cur ? 'nxp-btn-primary' : 'nxp-btn-outline') + '" data-page="' + p + '">' + p + '</button>';
			last = p;
		});
		h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" data-page="' + (cur + 1) + '"' + (cur >= d.pages ? ' disabled' : '') + '>Sau ›</button>';
		el.innerHTML = h;
	}

	function updateSelCount() {
		$$('.nxwpm-sel-count', root).forEach((el) => { el.textContent = state.selected.size; });
		$('#nxwpmBulkbar').classList.toggle('is-active', state.selected.size > 0);
	}

	function syncCheckAll() {
		const all = $('#nxwpmCheckAll');
		const boxes = $$('.nxwpm-check', root);
		if (all) all.checked = boxes.length > 0 && boxes.every((b) => b.checked);
		updateSelCount();
	}

	$('#nxwpmFilter').addEventListener('change', (e) => {
		if (e.target.name === 'q') return;
		loadList(1);
	});
	$('#nxwpmFilter [name="q"]').addEventListener('input', () => {
		clearTimeout(state.searchTimer);
		state.searchTimer = setTimeout(() => loadList(1), 450);
	});
	$('#nxwpmFilter [name="q"]').addEventListener('keydown', (e) => {
		if (e.key === 'Enter') { e.preventDefault(); clearTimeout(state.searchTimer); loadList(1); }
	});
	$('#nxwpmBtnReset').addEventListener('click', () => { $('#nxwpmFilter').reset(); loadList(1); });
	$('#nxwpmPager').addEventListener('click', (e) => {
		const b = e.target.closest('[data-page]');
		if (b && !b.disabled) { loadList(Number(b.dataset.page)); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	});

	$('#nxwpmTableWrap').addEventListener('change', (e) => {
		if (e.target.id === 'nxwpmCheckAll') {
			$$('.nxwpm-check', root).forEach((b) => {
				b.checked = e.target.checked;
				const id = Number(b.value);
				if (b.checked) state.selected.add(id); else state.selected.delete(id);
			});
			updateSelCount();
		} else if (e.target.classList.contains('nxwpm-check')) {
			const id = Number(e.target.value);
			if (e.target.checked) state.selected.add(id); else state.selected.delete(id);
			syncCheckAll();
		}
	});

	$('#nxwpmTableWrap').addEventListener('click', async (e) => {
		const open = e.target.closest('.nxwpm-open');
		if (open) { e.preventDefault(); openEditor(Number(open.dataset.id)); return; }
		const trash = e.target.closest('.nxwpm-trash-one');
		if (trash) {
			const row = state.rows.find((r) => r.id === Number(trash.dataset.id));
			if (!confirm('Chuyển sản phẩm "' + (row ? row.name : trash.dataset.id) + '" vào Thùng rác?')) return;
			await doDelete([Number(trash.dataset.id)], false);
		}
	});

	async function doDelete(ids, force) {
		if (!ids.length) { alert('Vui lòng chọn ít nhất 1 sản phẩm.'); return; }
		const res = await post('delete', { ids: ids.join(','), force: force ? '1' : '0' });
		if (res.success) {
			ids.forEach((id) => state.selected.delete(id));
			toast(res.data.message);
			loadList();
		} else {
			toast(res.data?.message || 'Lỗi xóa sản phẩm', 'err');
		}
	}

	$('#nxwpmBtnBulkTrash').addEventListener('click', () => {
		const ids = Array.from(state.selected);
		if (ids.length && confirm('Chuyển ' + ids.length + ' sản phẩm đã chọn vào Thùng rác?')) doDelete(ids, false);
	});
	$('#nxwpmBtnBulkDelete').addEventListener('click', () => {
		const ids = Array.from(state.selected);
		if (!ids.length) return;
		if (!confirm('⚠️ XÓA VĨNH VIỄN ' + ids.length + ' sản phẩm đã chọn (kèm toàn bộ biến thể)? Thao tác này KHÔNG thể hoàn tác.')) return;
		doDelete(ids, true);
	});
	$('#nxwpmBtnClearSel').addEventListener('click', () => {
		state.selected.clear();
		$$('.nxwpm-check', root).forEach((b) => { b.checked = false; });
		syncCheckAll();
	});
	$('#nxwpmBtnBulkStatus').addEventListener('click', async () => {
		const st = $('#nxwpmBulkStatus').value;
		const ids = Array.from(state.selected);
		if (!st || !ids.length) { alert('Chọn sản phẩm và trạng thái cần đổi.'); return; }
		const res = await post('bulk_status', { ids: ids.join(','), status: st });
		toast(res.data?.message || 'Đã cập nhật', res.success ? 'ok' : 'err');
		loadList();
	});

	/* ======================================================================
	 * XUẤT FILE
	 * ==================================================================== */

	root.addEventListener('click', (e) => {
		const b = e.target.closest('[data-export]');
		if (!b) return;
		const scope = b.dataset.export;
		const u = new URL(C.exportUrl, window.location.origin);
		u.searchParams.set('scope', scope);
		u.searchParams.set('variations', $('#nxwpmExportVariations').checked ? '1' : '0');
		if (scope === 'selected') {
			if (!state.selected.size) { alert('Vui lòng tick chọn sản phẩm cần tải trong bảng bên dưới.'); return; }
			u.searchParams.set('ids', Array.from(state.selected).join(','));
		} else if (scope === 'filtered') {
			const f = getFilters();
			Object.keys(f).forEach((k) => { if (f[k] && k !== 'per_page') u.searchParams.set(k, f[k]); });
		}
		window.location.href = u.toString();
		toast('⏳ Đang tạo file… trình duyệt sẽ tự tải về sau vài giây.');
	});

	/* ======================================================================
	 * NHẬP FILE — CHỈ SỬA MỤC CÓ THAY ĐỔI
	 * ==================================================================== */

	const prog = {
		box: $('#nxwpmProgress'), title: $('#nxwpmProgTitle'), count: $('#nxwpmProgCount'),
		bar: $('#nxwpmProgBar'), summary: $('#nxwpmImportSummary'), report: $('#nxwpmImportReport'),
	};

	function fileKey(f) { return f ? [f.name, f.size, f.lastModified].join('|') : ''; }

	async function uploadImportFile(file) {
		prog.title.textContent = '⏳ Đang đọc file ' + file.name + '…';
		const fd = new FormData();
		fd.append('action', 'nxwpm_import_upload');
		fd.append('nonce', C.nonce);
		fd.append('file', file);
		let res;
		try {
			const r = await fetch(C.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
			const t = await r.text();
			try { res = JSON.parse(t); } catch (e) { res = { success: false, data: { message: stripTags(t).slice(0, 220) || 'Lỗi tải file' } }; }
		} catch (e) {
			res = { success: false, data: { message: 'Lỗi kết nối mạng.' } };
		}
		if (!res.success) throw new Error(res.data?.message || 'Không đọc được file');
		state.importToken = res.data.token;
		state.importKey = fileKey(file);
		return res.data;
	}

	function renderReportItem(r) {
		const cls = { will_update: 'warn', updated: 'ok', partial: 'warn', error: 'err', notfound: 'err', unchanged: 'info' }[r.status] || 'info';
		const lbl = { will_update: 'Sẽ cập nhật', updated: 'Đã cập nhật', partial: 'Cập nhật 1 phần', error: 'Lỗi', notfound: 'Không tìm thấy', unchanged: 'Không đổi' }[r.status] || r.status;
		let h = '<div class="nxwpm-report-item is-' + cls + '"><div class="nxwpm-report-head"><span class="nxp-badge nxp-badge-' + cls + '">' + lbl + '</span> <strong>' + esc(r.label) + '</strong> <span class="nxwpm-sub">(dòng ' + r.line + ')</span></div>';
		if (r.message) h += '<div class="nxwpm-sub">' + esc(r.message) + '</div>';
		if (r.changes && r.changes.length) {
			h += '<ul class="nxwpm-changes">';
			r.changes.forEach((c) => {
				h += '<li><b>' + esc(c.field) + ':</b> <span class="old">' + esc(c.old || '(trống)') + '</span> → <span class="new">' + esc(c.new) + '</span></li>';
			});
			h += '</ul>';
		}
		if (r.errors && r.errors.length) h += '<div class="nxwpm-errs">⚠️ ' + r.errors.map(esc).join('<br>⚠️ ') + '</div>';
		return h + '</div>';
	}

	async function runImport(dry) {
		const input = $('#nxwpmImportFile');
		const file = input.files[0];
		if (!file) { alert('Vui lòng chọn file CSV hoặc Excel (.xlsx) cần nhập.'); input.click(); return; }
		if (!dry && !confirm('Cập nhật lên Website các mục CÓ THAY ĐỔI trong file "' + file.name + '"?\n(Ô trống được giữ nguyên, các mục giống dữ liệu hiện tại sẽ bỏ qua.)')) return;

		const btns = [$('#nxwpmBtnDryRun'), $('#nxwpmBtnImport')];
		btns.forEach((b) => { b.disabled = true; });
		prog.box.style.display = 'block';
		prog.bar.style.width = '3%';
		prog.summary.innerHTML = '';
		prog.report.innerHTML = '';
		prog.count.textContent = '';

		const stats = { total: 0, changed: 0, unchanged: 0, updated: 0, error: 0, notfound: 0, fields: 0 };
		try {
			let meta = null;
			if (!state.importToken || state.importKey !== fileKey(file)) {
				meta = await uploadImportFile(file);
			}
			if (meta && meta.unknown && meta.unknown.length) {
				prog.summary.innerHTML = '<div class="nxwpm-sub">ℹ️ Bỏ qua các cột không nhận diện: ' + meta.unknown.map(esc).join(', ') + '</div>';
			}
			prog.title.textContent = dry ? '🔍 Đang so sánh file với dữ liệu trên Website…' : '⬆️ Đang cập nhật các mục thay đổi lên Website…';
			let offset = 0;
			let total = meta ? meta.total : 0;
			let done = false;
			const limit = dry ? 60 : 15;
			while (!done) {
				const res = await post('import_process', { token: state.importToken, offset, limit, dry_run: dry ? '1' : '0' });
				if (!res.success) throw new Error(res.data?.message || 'Lỗi xử lý file');
				const d = res.data;
				total = d.total;
				offset = d.next;
				done = d.done;
				let html = '';
				d.results.forEach((r) => {
					stats.total++;
					if (r.status === 'unchanged') { stats.unchanged++; return; }
					if (r.status === 'notfound') stats.notfound++;
					if (r.status === 'error') stats.error++;
					if (['will_update', 'updated', 'partial'].includes(r.status)) {
						stats.changed++;
						stats.fields += (r.changes || []).length;
						if (r.status !== 'will_update') stats.updated++;
						if (r.status === 'partial') stats.error++;
					}
					html += renderReportItem(r);
				});
				if (html) prog.report.insertAdjacentHTML('beforeend', html);
				prog.bar.style.width = Math.max(3, Math.round((offset / Math.max(1, total)) * 100)) + '%';
				prog.count.textContent = offset + ' / ' + total + ' dòng';
			}

			let sum = '<div class="nxwpm-summary-grid">';
			sum += '<div><b>' + stats.total + '</b><span>dòng đã quét</span></div>';
			sum += '<div class="is-warn"><b>' + stats.changed + '</b><span>' + (dry ? 'dòng có thay đổi' : 'dòng đã cập nhật') + ' (' + stats.fields + ' mục)</span></div>';
			sum += '<div class="is-info"><b>' + stats.unchanged + '</b><span>dòng không đổi (giữ nguyên)</span></div>';
			sum += '<div class="is-err"><b>' + (stats.error + stats.notfound) + '</b><span>lỗi / không tìm thấy</span></div>';
			sum += '</div>';
			if (dry && stats.changed > 0) {
				sum += '<button type="button" class="nxp-btn nxp-btn-primary" id="nxwpmBtnApplyDry">✅ Áp dụng ' + stats.changed + ' dòng thay đổi lên Website</button>';
			}
			prog.summary.insertAdjacentHTML('beforeend', sum);
			if (!stats.changed && !stats.error && !stats.notfound) {
				prog.report.innerHTML = '<div class="nxwpm-empty">✓ File không có mục nào khác với dữ liệu hiện tại trên Website.</div>';
			}
			prog.bar.style.width = '100%';
			prog.title.textContent = dry
				? '🔍 Kiểm tra xong: ' + stats.changed + ' dòng sẽ được cập nhật (chưa lưu gì).'
				: '🎉 Hoàn tất: đã cập nhật ' + stats.updated + ' dòng, giữ nguyên ' + stats.unchanged + ' dòng.';
			if (!dry) {
				state.importToken = '';
				state.importKey = '';
				loadList();
			}
		} catch (err) {
			prog.title.textContent = '✗ ' + err.message;
			state.importToken = '';
			state.importKey = '';
		}
		btns.forEach((b) => { b.disabled = false; });
	}

	$('#nxwpmBtnDryRun').addEventListener('click', () => runImport(true));
	$('#nxwpmBtnImport').addEventListener('click', () => runImport(false));
	$('#nxwpmImportFile').addEventListener('change', () => { state.importToken = ''; state.importKey = ''; });
	prog.summary.addEventListener('click', (e) => { if (e.target.id === 'nxwpmBtnApplyDry') runImport(false); });

	const btnSyncSkuSeo = $('#nxwpmBtnSyncSkuSeo');
	if (btnSyncSkuSeo) {
		btnSyncSkuSeo.addEventListener('click', async () => {
			if (!confirm('Đối chiếu toàn bộ sản phẩm trên Website với kho 705+ mã SKU chuẩn để điền Tên sản phẩm, Mô tả & tối ưu toàn bộ thẻ SEO ngay bây giờ?')) return;
			const origLabel = btnSyncSkuSeo.textContent;
			btnSyncSkuSeo.disabled = true;
			btnSyncSkuSeo.textContent = '⏳ Đang đồng bộ Tên, Mô tả & SEO…';
			const res = await post('sync_sku_seo', {});
			btnSyncSkuSeo.disabled = false;
			btnSyncSkuSeo.textContent = origLabel;
			if (!res.success) {
				toast(res.data?.message || 'Lỗi đồng bộ SKU & SEO', 'err');
				return;
			}
			const rep = res.data?.report || {};
			const stEl = $('#nxwpmSkuSeoStatus');
			if (stEl && rep.synced_at) {
				stEl.innerHTML = 'Lần đồng bộ gần nhất (<strong>' + esc(rep.synced_at) + '</strong>): Đã điền Tên, Mô tả &amp; SEO chuẩn cho <strong>' + Number(rep.updated_matched || 0) + '</strong> sản phẩm khớp mã SKU (trên kho ' + Number(rep.catalog_sku_count || 705) + ' SKU) + tối ưu SEO cho <strong>' + Number(rep.fallback_seo_fixed || 0) + '</strong> sản phẩm khác.';
			}
			toast(res.data?.message || 'Đã đồng bộ hoàn tất!');
			loadList();
		});
	}

	/* ======================================================================
	 * CỬA SỔ SỬA TOÀN BỘ THÔNG TIN SẢN PHẨM
	 * ==================================================================== */

	const modal = $('#nxwpmModal');
	const body = $('#nxwpmModalBody');

	function blankProduct() {
		return {
			id: 0, product_type: 'simple', sku: '', name: '', slug: '', status: 'publish', tags: '',
			regular_price: '', sale_price: '', manage_stock: 'no', stock_quantity: '', stock_status: 'instock',
			short_description: '', description: '', featured_image_id: 0, featured_image_thumb: '', featured_image_url: '',
			gallery: [], category_ids: [], variations: [],
			seo_title: '', seo_description: '', seo_keywords: '', seo_canonical: '', seo_robots: '', og_title: '', og_description: '', og_image_url: '',
			view_url: '', wc_edit_url: '',
		};
	}

	function pickMedia(multiple, cb) {
		if (!window.wp || !wp.media) { alert('Thư viện Media chưa sẵn sàng, vui lòng tải lại trang.'); return; }
		const frame = wp.media({ title: 'Chọn ảnh sản phẩm', library: { type: 'image' }, multiple: multiple ? 'add' : false, button: { text: 'Dùng ảnh này' } });
		frame.on('select', () => {
			const sel = frame.state().get('selection').toJSON();
			cb(sel.map((a) => ({ id: a.id, thumb: (a.sizes && (a.sizes.thumbnail || a.sizes.medium) ? (a.sizes.thumbnail || a.sizes.medium).url : a.url), full: a.url })));
		});
		frame.open();
	}

	function openModal() {
		modal.hidden = false;
		document.body.classList.add('nxwpm-modal-open');
	}

	function closeModal(force) {
		if (!force && state.edit && JSON.stringify(buildPayload()) !== state.snapshot && !confirm('Bạn có thay đổi chưa lưu. Đóng cửa sổ và bỏ các thay đổi?')) return;
		modal.hidden = true;
		document.body.classList.remove('nxwpm-modal-open');
		state.edit = null;
	}

	async function openEditor(id) {
		openModal();
		$('#nxwpmModalTitle').textContent = '⏳ Đang tải sản phẩm #' + id + '…';
		$('#nxwpmModalSub').textContent = '';
		body.innerHTML = '<div class="nxwpm-empty">⏳ Đang tải toàn bộ thông tin sản phẩm…</div>';
		const res = await post('get', { id });
		if (!res.success) {
			body.innerHTML = '<div class="nxwpm-empty is-err">' + esc(res.data?.message || 'Không tải được sản phẩm') + '</div>';
			return;
		}
		setEditData(res.data);
	}

	function openNew() {
		openModal();
		setEditData(blankProduct());
	}

	function setEditData(d) {
		const p = Object.assign(blankProduct(), d);
		state.edit = {
			p,
			featured: p.featured_image_id ? { id: p.featured_image_id, thumb: p.featured_image_thumb, src: '' } : (p.featured_image_url ? { id: 0, thumb: p.featured_image_url, src: p.featured_image_url } : null),
			gallery: (p.gallery || []).map((g) => ({ id: g.id, thumb: g.url, src: '' })),
			variations: (p.variations || []).map((v) => Object.assign({}, v, { _img: v.image_id ? String(v.image_id) : '' })),
			deleted: [],
			sec: (state.edit && state.edit.sec) || 'info',
		};
		renderEditor();
		state.snapshot = JSON.stringify(buildPayload());
	}

	function field(label, html, cls = '') {
		return '<label class="nxwpm-field ' + cls + '"><span>' + label + '</span>' + html + '</label>';
	}
	function inp(k, v, attrs = '') {
		return '<input type="text" data-k="' + k + '" value="' + esc(v) + '" ' + attrs + '>';
	}
	function sel(k, v, opts, attrs = '') {
		return '<select data-k="' + k + '" ' + attrs + '>' + opts.map((o) => '<option value="' + esc(o[0]) + '"' + (String(v) === String(o[0]) ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select>';
	}

	function renderEditor() {
		const E = state.edit;
		const p = E.p;
		const isNew = !p.id;
		const isVar = p.product_type === 'variable';
		$('#nxwpmModalTitle').textContent = isNew ? '➕ Thêm sản phẩm mới' : '✏️ ' + p.name;
		$('#nxwpmModalSub').innerHTML = isNew ? 'Điền thông tin rồi bấm Lưu để tạo sản phẩm.' : 'ID #' + p.id + ' • SKU <code>' + esc(p.sku || '—') + '</code> • ' + (isVar ? E.variations.length + ' biến thể' : 'Sản phẩm đơn giản');
		$('#nxwpmModalLinks').innerHTML = isNew ? '' : '<a href="' + esc(p.view_url) + '" target="_blank" rel="noopener">👁 Xem trên web</a><a href="' + esc(p.wc_edit_url) + '" target="_blank" rel="noopener">⚙️ Mở trình sửa WooCommerce</a>';

		let h = '';
		// ---------- INFO ----------
		h += '<section data-sec="info"><div class="nxwpm-form-grid">';
		h += field('Tên sản phẩm *', inp('name', p.name), 'span-2');
		h += field('Mã SKU', inp('sku', p.sku, 'placeholder="VD: 2VBKHN2B01"'));
		h += field('Đường dẫn tĩnh (slug)', inp('slug', p.slug, 'placeholder="Tự tạo từ tên nếu để trống"'));
		h += field('Trạng thái', sel('status', p.status, [['publish', 'Hiển thị (Công khai)'], ['draft', 'Bản nháp'], ['private', 'Riêng tư (ẩn)'], ['pending', 'Chờ duyệt']]));
		h += field('Kiểu sản phẩm', isNew ? sel('product_type', p.product_type, [['simple', 'Sản phẩm đơn giản'], ['variable', 'Sản phẩm có biến thể (Màu / Size)']]) : '<input type="text" value="' + (isVar ? 'Có biến thể (Màu / Size)' : 'Sản phẩm đơn giản') + '" disabled>');
		if (isVar) {
			h += '<div class="nxwpm-note span-2">💡 Sản phẩm có biến thể: giá & tồn kho được quản lý theo từng biến thể ở tab <b>🎨 Biến thể</b>.</div>';
		} else {
			h += field('Giá gốc (' + esc(C.currency) + ')', inp('regular_price', p.regular_price, 'inputmode="numeric"'));
			h += field('Giá giảm (' + esc(C.currency) + ')', inp('sale_price', p.sale_price, 'inputmode="numeric" placeholder="Để trống nếu không giảm"'));
			h += field('Tồn kho', inp('stock_quantity', p.stock_quantity, 'inputmode="numeric" placeholder="Để trống = không quản lý tồn"'));
			h += field('Tình trạng kho', sel('stock_status', p.stock_status, [['instock', 'Còn hàng'], ['outofstock', 'Hết hàng'], ['onbackorder', 'Đặt trước']]));
		}
		h += field('Thẻ sản phẩm (cách nhau bởi dấu | hoặc dấu phẩy)', inp('tags', p.tags), 'span-2');
		h += '<div class="nxwpm-field span-2"><span>Danh mục sản phẩm</span><input type="search" class="nxwpm-cat-search" placeholder="🔎 Lọc danh mục…"><div class="nxwpm-cat-list">';
		(C.categories || []).forEach((c) => {
			h += '<label data-path="' + esc(c.path.toLowerCase()) + '"><input type="checkbox" class="nxwpm-cat" value="' + c.id + '" data-path="' + esc(c.path) + '"' + (p.category_ids.includes(c.id) ? ' checked' : '') + '> ' + esc(c.path) + '</label>';
		});
		h += '</div></div>';
		h += '</div></section>';

		// ---------- DESC ----------
		h += '<section data-sec="desc">';
		h += field('Mô tả ngắn (hiển thị cạnh giá, hỗ trợ HTML)', '<textarea data-k="short_description" rows="6">' + esc(p.short_description) + '</textarea>');
		h += field('Mô tả chi tiết (hỗ trợ HTML)', '<textarea data-k="description" rows="16">' + esc(p.description) + '</textarea>');
		h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmPreviewDesc">👁 Xem trước mô tả</button><div class="nxwpm-desc-preview" id="nxwpmDescPreview" hidden></div>';
		h += '</section>';

		// ---------- IMAGES ----------
		h += '<section data-sec="images">';
		h += '<div class="nxwpm-img-block"><h4>Ảnh đại diện</h4><div class="nxwpm-featured">';
		h += E.featured ? '<img src="' + esc(E.featured.thumb) + '" alt="">' : '<div class="nxwpm-img-empty">Chưa có ảnh</div>';
		h += '<div class="nxwpm-actions-col"><button type="button" class="nxp-btn nxp-btn-navy nxp-btn-sm" data-img="feat-lib">📁 Chọn từ thư viện</button>';
		h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" data-img="feat-url">🔗 Dùng URL ảnh</button>';
		if (E.featured) h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" data-img="feat-del">✖ Bỏ ảnh</button>';
		h += '</div></div></div>';
		h += '<div class="nxwpm-img-block"><h4>Album ảnh sản phẩm (' + E.gallery.length + ')</h4><div class="nxwpm-gallery">';
		E.gallery.forEach((g, i) => {
			h += '<div class="nxwpm-gitem"><img src="' + esc(g.thumb) + '" alt=""><div class="nxwpm-gtools">';
			h += '<button type="button" data-img="g-left" data-i="' + i + '" title="Đưa lên trước">◀</button>';
			h += '<button type="button" data-img="g-feat" data-i="' + i + '" title="Đặt làm ảnh đại diện">★</button>';
			h += '<button type="button" data-img="g-right" data-i="' + i + '" title="Đưa ra sau">▶</button>';
			h += '<button type="button" data-img="g-del" data-i="' + i + '" title="Xóa khỏi album">✖</button></div></div>';
		});
		h += '</div><div class="nxwpm-actions"><button type="button" class="nxp-btn nxp-btn-navy nxp-btn-sm" data-img="g-lib">📁 Thêm ảnh từ thư viện</button><button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" data-img="g-url">🔗 Thêm bằng URL</button></div>';
		h += '<p class="nxwpm-sub">Ảnh từ URL bên ngoài (VD: ảnh Pancake) sẽ được tải về Thư viện Media khi bấm Lưu.</p></div>';
		h += '</section>';

		// ---------- VARIATIONS ----------
		h += '<section data-sec="variations">';
		if (!isVar) {
			h += '<div class="nxwpm-empty">Sản phẩm đơn giản không có biến thể.' + (isNew ? ' Chọn "Kiểu sản phẩm: Có biến thể" ở tab Thông tin để thêm biến thể Màu / Size.' : '') + '</div>';
		} else {
			h += '<div class="nxwpm-var-tools">';
			h += '<div class="nxwpm-var-tool"><b>⚡ Tạo nhanh biến thể</b><input type="text" id="nxwpmGenColors" placeholder="Màu: Đỏ, Xanh, Vàng"><input type="text" id="nxwpmGenSizes" placeholder="Size: 90, 100, 110"><button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnGen">Tạo tổ hợp</button></div>';
			h += '<div class="nxwpm-var-tool"><b>🧮 Áp dụng cho tất cả</b><input type="text" id="nxwpmAllReg" placeholder="Giá gốc"><input type="text" id="nxwpmAllSale" placeholder="Giá giảm ([XOA] = bỏ)"><input type="text" id="nxwpmAllQty" placeholder="Tồn kho"><button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnApplyAll">Áp dụng</button></div>';
			h += '</div>';
			h += '<div class="nxwpm-var-scroll"><table class="nxp-table nxwpm-var-table"><thead><tr><th>Ảnh</th><th>ID</th><th>SKU biến thể</th><th>Màu</th><th>Size</th><th>Giá gốc</th><th>Giá giảm</th><th>Tồn kho</th><th>Tình trạng</th><th>Hiển thị</th><th></th></tr></thead><tbody>';
			E.variations.forEach((v, i) => {
				h += '<tr data-vi="' + i + '">';
				h += '<td><button type="button" class="nxwpm-var-img" data-vimg="' + i + '" title="Chọn ảnh biến thể">' + (v.image_thumb ? '<img src="' + esc(v.image_thumb) + '" alt="">' : '＋') + '</button></td>';
				h += '<td class="nxwpm-sub">' + (v.id ? '#' + v.id : '<span class="nxp-badge nxp-badge-info">Mới</span>') + '</td>';
				h += '<td><input type="text" data-vk="sku" value="' + esc(v.sku) + '"></td>';
				h += '<td><input type="text" data-vk="color" value="' + esc(v.color) + '" class="w-s"></td>';
				h += '<td><input type="text" data-vk="size" value="' + esc(v.size) + '" class="w-xs"></td>';
				h += '<td><input type="text" data-vk="regular_price" value="' + esc(v.regular_price) + '" class="w-s" inputmode="numeric"></td>';
				h += '<td><input type="text" data-vk="sale_price" value="' + esc(v.sale_price) + '" class="w-s" inputmode="numeric"></td>';
				h += '<td><input type="text" data-vk="stock_quantity" value="' + esc(v.stock_quantity) + '" class="w-xs" inputmode="numeric"></td>';
				h += '<td><select data-vk="stock_status">' + [['instock', 'Còn'], ['outofstock', 'Hết'], ['onbackorder', 'Đặt trước']].map((o) => '<option value="' + o[0] + '"' + (v.stock_status === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select></td>';
				h += '<td><input type="checkbox" data-vk="status"' + (v.status !== 'private' ? ' checked' : '') + '></td>';
				h += '<td><button type="button" class="nxwpm-x-sm" data-vdel="' + i + '" title="Xóa biến thể">🗑</button></td>';
				h += '</tr>';
			});
			if (!E.variations.length) h += '<tr><td colspan="11" class="nxwpm-sub" style="text-align:center;">Chưa có biến thể nào.</td></tr>';
			h += '</tbody></table></div>';
			h += '<button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmBtnAddVar">➕ Thêm biến thể</button>';
			if (E.deleted.length) h += ' <span class="nxwpm-sub">Sẽ xóa ' + E.deleted.length + ' biến thể khi bấm Lưu.</span>';
		}
		h += '</section>';

		// ---------- SEO ----------
		h += '<section data-sec="seo">';
		if (C.seoPlugin) h += '<div class="nxwpm-note">ℹ️ Website đang dùng plugin SEO — dữ liệu dưới đây được đồng bộ vào Yoast / Rank Math.</div>';
		h += '<div class="nxwpm-serp"><div class="nxwpm-serp-url" id="nxwpmSerpUrl"></div><div class="nxwpm-serp-title" id="nxwpmSerpTitle"></div><div class="nxwpm-serp-desc" id="nxwpmSerpDesc"></div></div>';
		h += '<div class="nxwpm-form-grid">';
		h += field('SEO Title <em class="nxwpm-counter" data-count="seo_title" data-max="60"></em>', inp('seo_title', p.seo_title, 'placeholder="Mặc định: tên sản phẩm"'), 'span-2');
		h += field('Meta Description <em class="nxwpm-counter" data-count="seo_description" data-max="160"></em>', '<textarea data-k="seo_description" rows="3" placeholder="Mô tả hiển thị trên Google (120–160 ký tự)">' + esc(p.seo_description) + '</textarea>', 'span-2');
		h += field('Từ khóa SEO (Focus keywords, cách nhau dấu phẩy)', inp('seo_keywords', p.seo_keywords), 'span-2');
		h += field('Canonical URL', inp('seo_canonical', p.seo_canonical, 'placeholder="Để trống = link sản phẩm"'));
		h += field('Meta Robots', sel('seo_robots', p.seo_robots, [['', 'Mặc định (index, follow)'], ['index,follow', 'index, follow'], ['noindex,follow', 'noindex, follow (ẩn khỏi Google)'], ['index,nofollow', 'index, nofollow'], ['noindex,nofollow', 'noindex, nofollow']]));
		h += field('OpenGraph Title (Facebook / Zalo)', inp('og_title', p.og_title, 'placeholder="Mặc định: SEO Title"'), 'span-2');
		h += field('OpenGraph Description', '<textarea data-k="og_description" rows="2" placeholder="Mặc định: Meta Description">' + esc(p.og_description) + '</textarea>', 'span-2');
		h += field('OpenGraph Image (URL) <button type="button" class="nxwpm-link-btn" data-img="og-lib">📁 Chọn từ thư viện</button>', inp('og_image_url', p.og_image_url, 'placeholder="Mặc định: ảnh đại diện"'), 'span-2');
		h += '</div><button type="button" class="nxp-btn nxp-btn-outline nxp-btn-sm" id="nxwpmSeoAuto">✨ Tự điền các ô SEO còn trống từ tên & mô tả</button>';
		h += '</section>';

		body.innerHTML = h;
		showSec(E.sec);
		updateSeoPreview();
	}

	function showSec(sec) {
		if (state.edit) state.edit.sec = sec;
		$$('#nxwpmModalTabs button').forEach((b) => b.classList.toggle('active', b.dataset.sec === sec));
		$$('section[data-sec]', body).forEach((s) => { s.hidden = s.dataset.sec !== sec; });
	}

	function readP() {
		const E = state.edit;
		$$('[data-k]', body).forEach((el) => { E.p[el.dataset.k] = el.value; });
		E.p.category_ids = $$('.nxwpm-cat:checked', body).map((c) => Number(c.value));
	}

	function updateSeoPreview() {
		if (!state.edit) return;
		const g = (k) => ($('[data-k="' + k + '"]', body) || {}).value || '';
		const title = g('seo_title') || g('name') || 'Tên sản phẩm';
		const desc = g('seo_description') || stripTags(g('short_description') || g('description')).slice(0, 160);
		const url = g('seo_canonical') || state.edit.p.view_url || (location.origin + '/san-pham/' + (g('slug') || '…'));
		const t = $('#nxwpmSerpTitle');
		if (!t) return;
		t.textContent = title;
		$('#nxwpmSerpDesc').textContent = desc || 'Chưa có mô tả.';
		$('#nxwpmSerpUrl').textContent = url;
		$$('.nxwpm-counter', body).forEach((c) => {
			const n = g(c.dataset.count).length;
			const max = Number(c.dataset.max);
			c.textContent = n + ' / ' + max;
			c.className = 'nxwpm-counter ' + (n === 0 ? '' : n > max ? 'is-err' : n >= max * 0.6 ? 'is-ok' : 'is-warn');
		});
	}

	function buildPayload() {
		const E = state.edit;
		if (!E) return null;
		if (body.querySelector('[data-k]')) readP();
		const p = E.p;
		const isVar = p.product_type === 'variable';
		const pathById = {};
		(C.categories || []).forEach((c) => { pathById[c.id] = c.path; });
		const out = {
			id: p.id,
			product_type: p.product_type,
			name: p.name, sku: p.sku, slug: p.slug, status: p.status,
			tags: p.tags,
			categories: p.category_ids.map((id) => pathById[id]).filter(Boolean).join(' | '),
			short_description: p.short_description, description: p.description,
			featured_image_url: E.featured ? (E.featured.id ? String(E.featured.id) : E.featured.src) : '',
			gallery_image_urls: E.gallery.map((g) => (g.id ? String(g.id) : g.src)).join(' | '),
			seo_title: p.seo_title, seo_description: p.seo_description, seo_keywords: p.seo_keywords, seo_canonical: p.seo_canonical,
			seo_robots: p.seo_robots, og_title: p.og_title, og_description: p.og_description, og_image_url: p.og_image_url,
		};
		if (!isVar) {
			const qty = String(p.stock_quantity ?? '').trim();
			Object.assign(out, {
				regular_price: p.regular_price, sale_price: p.sale_price,
				manage_stock: qty !== '' ? 'yes' : 'no', stock_quantity: qty,
				stock_status: p.stock_status,
			});
		} else {
			out.variations = E.variations.map((v) => {
				const qty = String(v.stock_quantity ?? '').trim();
				return {
					id: v.id || 0, sku: v.sku, color: v.color, size: v.size,
					regular_price: v.regular_price, sale_price: v.sale_price,
					manage_stock: qty !== '' ? 'yes' : 'no', stock_quantity: qty,
					stock_status: v.stock_status || 'instock', status: v.status === 'private' ? 'private' : 'publish',
					featured_image_url: v._img || '',
				};
			});
			out.deleted_variations = E.deleted;
		}
		return out;
	}

	async function saveEditor() {
		const payload = buildPayload();
		if (!payload) return;
		if (!String(payload.name || '').trim()) { showSec('info'); alert('Vui lòng nhập tên sản phẩm.'); return; }
		const btn = $('#nxwpmBtnSave');
		btn.disabled = true;
		btn.textContent = '⏳ Đang lưu…';
		const res = await post('save', { payload: JSON.stringify(payload) });
		btn.disabled = false;
		btn.textContent = '💾 Lưu tất cả thay đổi';
		if (!res.success) { alert(res.data?.message || 'Lỗi lưu sản phẩm'); return; }
		toast(res.data.message, res.data.errors.length ? 'warn' : 'ok');
		if (res.data.errors.length) alert('Đã lưu, nhưng có một số mục chưa cập nhật được:\n\n• ' + res.data.errors.join('\n• '));
		if (res.data.product) setEditData(res.data.product);
		loadList();
	}

	// ----- Modal events -----
	modal.addEventListener('click', (e) => {
		if (e.target.closest('[data-close]')) { closeModal(false); return; }
		const tab = e.target.closest('#nxwpmModalTabs button');
		if (tab) { if (state.edit) readP(); showSec(tab.dataset.sec); }
	});
	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && !modal.hidden && !document.querySelector('.media-modal:not([style*="display: none"])')) closeModal(false);
	});
	$('#nxwpmBtnSave').addEventListener('click', saveEditor);
	$('#nxwpmBtnAdd').addEventListener('click', openNew);

	body.addEventListener('input', (e) => {
		const E = state.edit;
		if (!E) return;
		if (e.target.classList.contains('nxwpm-cat-search')) {
			const q = e.target.value.toLowerCase().trim();
			$$('.nxwpm-cat-list label', body).forEach((l) => { l.style.display = !q || l.dataset.path.includes(q) ? '' : 'none'; });
			return;
		}
		const tr = e.target.closest('tr[data-vi]');
		if (tr && e.target.dataset.vk) {
			const v = E.variations[Number(tr.dataset.vi)];
			v[e.target.dataset.vk] = e.target.type === 'checkbox' ? (e.target.checked ? 'publish' : 'private') : e.target.value;
			return;
		}
		updateSeoPreview();
	});
	body.addEventListener('change', (e) => {
		const E = state.edit;
		if (!E) return;
		const tr = e.target.closest('tr[data-vi]');
		if (tr && e.target.dataset.vk) {
			const v = E.variations[Number(tr.dataset.vi)];
			v[e.target.dataset.vk] = e.target.type === 'checkbox' ? (e.target.checked ? 'publish' : 'private') : e.target.value;
			return;
		}
		if (e.target.dataset.k === 'product_type') { readP(); renderEditor(); }
	});

	body.addEventListener('click', (e) => {
		const E = state.edit;
		if (!E) return;
		const t = e.target.closest('[data-img],[data-vimg],[data-vdel],#nxwpmBtnAddVar,#nxwpmBtnGen,#nxwpmBtnApplyAll,#nxwpmSeoAuto,#nxwpmPreviewDesc');
		if (!t) return;
		e.preventDefault();
		readP();
		const act = t.dataset.img;
		const i = Number(t.dataset.i);
		if (act === 'feat-lib') {
			pickMedia(false, (arr) => { if (arr[0]) { E.featured = { id: arr[0].id, thumb: arr[0].thumb, src: '' }; renderEditor(); } });
		} else if (act === 'feat-url') {
			const u = prompt('Dán URL ảnh đại diện (https://…):');
			if (u && /^https?:\/\//i.test(u.trim())) { E.featured = { id: 0, thumb: u.trim(), src: u.trim() }; renderEditor(); }
		} else if (act === 'feat-del') {
			E.featured = null; renderEditor();
		} else if (act === 'g-lib') {
			pickMedia(true, (arr) => { arr.forEach((a) => { if (!E.gallery.some((g) => g.id === a.id)) E.gallery.push({ id: a.id, thumb: a.thumb, src: '' }); }); renderEditor(); });
		} else if (act === 'g-url') {
			const u = prompt('Dán 1 hoặc nhiều URL ảnh (mỗi URL một dòng hoặc cách nhau dấu |):');
			if (u) { u.split(/[\n|]+/).map((s) => s.trim()).filter((s) => /^https?:\/\//i.test(s)).forEach((s) => E.gallery.push({ id: 0, thumb: s, src: s })); renderEditor(); }
		} else if (act === 'g-del') {
			E.gallery.splice(i, 1); renderEditor();
		} else if (act === 'g-left' && i > 0) {
			[E.gallery[i - 1], E.gallery[i]] = [E.gallery[i], E.gallery[i - 1]]; renderEditor();
		} else if (act === 'g-right' && i < E.gallery.length - 1) {
			[E.gallery[i + 1], E.gallery[i]] = [E.gallery[i], E.gallery[i + 1]]; renderEditor();
		} else if (act === 'g-feat') {
			const g = E.gallery[i];
			if (E.featured) E.gallery[i] = E.featured; else E.gallery.splice(i, 1);
			E.featured = g; renderEditor();
		} else if (act === 'og-lib') {
			pickMedia(false, (arr) => { if (arr[0]) { E.p.og_image_url = arr[0].full; renderEditor(); } });
		} else if (t.dataset.vimg !== undefined) {
			const v = E.variations[Number(t.dataset.vimg)];
			pickMedia(false, (arr) => { if (arr[0]) { v._img = String(arr[0].id); v.image_thumb = arr[0].thumb; renderEditor(); } });
		} else if (t.dataset.vdel !== undefined) {
			const idx = Number(t.dataset.vdel);
			const v = E.variations[idx];
			if (!confirm('Xóa biến thể ' + (v.sku || [v.color, v.size].filter(Boolean).join(' / ') || '#' + (idx + 1)) + '? (Áp dụng khi bấm Lưu)')) return;
			if (v.id) E.deleted.push(v.id);
			E.variations.splice(idx, 1);
			renderEditor();
		} else if (t.id === 'nxwpmBtnAddVar') {
			E.variations.push({ id: 0, sku: '', color: '', size: '', regular_price: '', sale_price: '', stock_quantity: '', stock_status: 'instock', status: 'publish', _img: '', image_thumb: '' });
			renderEditor();
		} else if (t.id === 'nxwpmBtnGen') {
			const colors = ($('#nxwpmGenColors').value || '').split(',').map((s) => s.trim()).filter(Boolean);
			const sizes = ($('#nxwpmGenSizes').value || '').split(',').map((s) => s.trim()).filter(Boolean);
			if (!colors.length && !sizes.length) { alert('Nhập ít nhất 1 màu hoặc 1 size.'); return; }
			const base = String(E.p.sku || '').trim().toUpperCase();
			const slug = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'D').replace(/[^a-z0-9]/gi, '').toUpperCase();
			let added = 0;
			(colors.length ? colors : ['']).forEach((c) => {
				(sizes.length ? sizes : ['']).forEach((s) => {
					const exists = E.variations.some((v) => String(v.color).toLowerCase() === c.toLowerCase() && String(v.size).toLowerCase() === s.toLowerCase());
					if (exists) return;
					E.variations.push({ id: 0, sku: base ? [base, slug(c), slug(s)].filter(Boolean).join('-') : '', color: c, size: s, regular_price: '', sale_price: '', stock_quantity: '', stock_status: 'instock', status: 'publish', _img: '', image_thumb: '' });
					added++;
				});
			});
			toast('Đã thêm ' + added + ' biến thể mới (chưa lưu).');
			renderEditor();
		} else if (t.id === 'nxwpmBtnApplyAll') {
			const reg = $('#nxwpmAllReg').value.trim();
			const sale = $('#nxwpmAllSale').value.trim();
			const qty = $('#nxwpmAllQty').value.trim();
			E.variations.forEach((v) => {
				if (reg) v.regular_price = reg;
				if (sale) v.sale_price = /^\[x[oó]a\]$/i.test(sale) ? '' : sale;
				if (qty) v.stock_quantity = qty;
			});
			renderEditor();
		} else if (t.id === 'nxwpmSeoAuto') {
			const p = E.p;
			if (!p.seo_title) p.seo_title = p.name;
			if (!p.seo_description) {
				const d = stripTags(p.short_description || p.description);
				p.seo_description = d.length > 157 ? d.slice(0, 155).replace(/\s+\S*$/, '') + '…' : d;
			}
			if (!p.og_title) p.og_title = p.seo_title;
			if (!p.og_description) p.og_description = p.seo_description;
			renderEditor();
		} else if (t.id === 'nxwpmPreviewDesc') {
			const pv = $('#nxwpmDescPreview');
			pv.hidden = !pv.hidden;
			pv.innerHTML = '<h4>Mô tả ngắn</h4>' + (E.p.short_description || '<em>(trống)</em>') + '<hr><h4>Mô tả chi tiết</h4>' + (E.p.description || '<em>(trống)</em>');
		}
	});

	/* ======================================================================
	 * NẠP DỮ LIỆU KHI MỞ TAB
	 * ==================================================================== */

	function paneVisible() {
		const pane = document.getElementById('nxp-pane-web_products');
		return pane && pane.style.display !== 'none' && pane.offsetParent !== null;
	}
	function ensureLoaded() { if (!state.loaded && paneVisible()) loadList(1); }

	document.addEventListener('click', (e) => {
		if (e.target.closest('.nxp-tab-link[data-tab="web_products"]')) setTimeout(ensureLoaded, 30);
	});
	window.addEventListener('popstate', () => setTimeout(ensureLoaded, 60));
	ensureLoaded();
})();
