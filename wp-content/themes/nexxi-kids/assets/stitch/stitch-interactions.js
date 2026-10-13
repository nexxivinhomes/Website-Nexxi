/* NEXXI Kids – storefront behaviour for the Stitch screens.
 * Every handler binds to data-* hooks rendered by the theme (inc/stitch-*.php),
 * never to sample copy, so the Stitch markup can evolve safely. */
(() => {
  'use strict';
  const config = window.NexxiStitch || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const money = value => new Intl.NumberFormat('vi-VN').format(Math.round(Number(value) || 0)) + '₫';
  const json = value => { try { return JSON.parse(value); } catch (e) { return null; } };
  const chart = Object.entries(config.sizeChart || {}).map(([size, row]) => [Number(size), Number(row[0]), Number(row[1]), Number(row[2])]).sort((a, b) => a[0] - b[0]);

  /* ---------- Toast ---------- */
  let toastTimer;
  const toast = (message, options = {}) => {
    let box = $('#nexxi-toast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'nexxi-toast';
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    box.className = 'nexxi-toast' + (options.error ? ' nexxi-toast--error' : '');
    box.replaceChildren();
    const icon = document.createElement('span');
    icon.className = 'material-symbols-outlined';
    icon.style.fontSize = '18px';
    icon.textContent = options.icon || (options.error ? 'error' : 'check_circle');
    const text = document.createElement('span');
    text.textContent = message;
    box.append(icon, text);
    if (options.link) {
      const link = document.createElement('a');
      link.href = options.link.href;
      link.textContent = options.link.label;
      box.appendChild(link);
    }
    requestAnimationFrame(() => box.classList.add('is-visible'));
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => box.classList.remove('is-visible'), options.duration || 3200);
  };

  /* ---------- Cart ---------- */
  const paintCartCount = count => $$('[data-nexxi-cart-count]').forEach(badge => { badge.textContent = String(count); });
  const addToCart = async (productId, quantity = 1) => {
    if (!config.ajaxAdd) throw new Error('no-endpoint');
    const body = new URLSearchParams({product_id: String(productId), quantity: String(Math.max(1, quantity))});
    const response = await fetch(config.ajaxAdd, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body});
    const data = await response.json().catch(() => null);
    if (!response.ok || !data || data.error) {
      const error = new Error('add-failed');
      error.productUrl = data && data.product_url;
      throw error;
    }
    const fragments = data.fragments || {};
    if (fragments['nexxi-cart-count'] !== undefined) paintCartCount(fragments['nexxi-cart-count']);
    if (window.jQuery) window.jQuery(document.body).trigger('added_to_cart', [fragments, data.cart_hash]);
    return data;
  };
  const busy = (button, state) => { if (button) { button.classList.toggle('is-loading', state); button.setAttribute('aria-busy', state ? 'true' : 'false'); } };
  const addedToast = name => toast(`Đã thêm “${name}” vào giỏ hàng`, {icon: 'shopping_bag', link: {href: config.cart, label: 'Xem giỏ'}});
  const failedToast = error => {
    toast('Mẫu này vừa hết hàng hoặc chưa thể thêm, mẹ thử chọn size khác nhé.', {error: true, duration: 4200});
    return error;
  };

  /* ---------- Product cards ---------- */
  const cardData = card => card._nexxi || (card._nexxi = json(card.getAttribute('data-nexxi-card')) || {});
  const selectToggle = (button, selected) => {
    if (button.dataset.baseClass === undefined) button.dataset.baseClass = button.className;
    button.className = button.dataset.baseClass + (selected ? ' bg-primary text-on-primary shadow-sm' : '');
    button.setAttribute('aria-pressed', selected ? 'true' : 'false');
  };
  const initCards = (root = document) => {
    $$('[data-nexxi-card]', root).forEach(card => {
      const data = cardData(card);
      if (data.type !== 'variable') return;
      $$('[data-nexxi-size]', card).forEach(button => {
        const available = (data.variations || []).some(v => v.size === button.dataset.nexxiSize && v.inStock);
        button.classList.toggle('is-unavailable', !available);
        if (!available) button.title = (button.title || '') + ' – tạm hết';
      });
    });
  };
  document.addEventListener('click', async event => {
    const sizeButton = event.target.closest('[data-nexxi-card] [data-nexxi-size]');
    if (sizeButton) {
      event.preventDefault();
      const card = sizeButton.closest('[data-nexxi-card]');
      const wasSelected = sizeButton.getAttribute('aria-pressed') === 'true';
      $$('[data-nexxi-size]', card).forEach(button => selectToggle(button, false));
      selectToggle(sizeButton, !wasSelected);
      return;
    }
    const addButton = event.target.closest('[data-nexxi-card] [data-nexxi-add]');
    if (!addButton) return;
    event.preventDefault();
    const card = addButton.closest('[data-nexxi-card]');
    const data = cardData(card);
    let target = data.id;
    if (data.type === 'variable') {
      const chosen = $('[data-nexxi-size][aria-pressed="true"]', card);
      const pool = (data.variations || []).filter(v => v.inStock && (!chosen || v.size === chosen.dataset.nexxiSize));
      const sizesLeft = new Set(pool.map(v => v.size));
      if (!pool.length) { failedToast(); return; }
      if (!chosen && sizesLeft.size > 1) {
        if (!$('[data-nexxi-size]', card)) { location.href = data.url; return; }
        toast('Mẹ chọn size cho bé trước khi thêm vào giỏ nhé!', {icon: 'straighten'});
        $$('[data-nexxi-size]:not(.is-unavailable)', card).forEach(button => { button.animate([{transform: 'scale(1)'}, {transform: 'scale(1.18)'}, {transform: 'scale(1)'}], {duration: 420}); });
        return;
      }
      target = pool[0].id;
    } else if (data.type && data.type !== 'simple') {
      location.href = data.url;
      return;
    }
    busy(addButton, true);
    try { await addToCart(target, 1); addedToast(data.name); } catch (error) { if (error.productUrl && data.type !== 'simple') location.href = error.productUrl; else failedToast(error); }
    busy(addButton, false);
  });

  /* ---------- Wishlist ---------- */
  const wishKey = 'nexxi-stitch-wishlist';
  const readLocal = () => { const list = json(localStorage.getItem(wishKey) || '[]'); return Array.isArray(list) ? list.map(Number).filter(Boolean) : []; };
  let wishlist = new Set(readLocal());
  const saveLocal = () => { try { localStorage.setItem(wishKey, JSON.stringify(Array.from(wishlist))); } catch (e) { /* storage full or disabled */ } };
  let syncTimer;
  const syncServer = () => {
    if (!config.loggedIn || !config.restUrl) return;
    clearTimeout(syncTimer);
    syncTimer = setTimeout(() => {
      fetch(config.restUrl + 'wishlist', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify({ids: Array.from(wishlist)})}).catch(() => {});
    }, 400);
  };
  if (config.loggedIn && Array.isArray(config.wishlist)) {
    const server = new Set(config.wishlist.map(Number));
    const merged = new Set([...server, ...wishlist]);
    wishlist = merged;
    saveLocal();
    if (merged.size !== server.size) syncServer();
  }
  const paintWishlist = (root = document) => {
    $$('[data-nexxi-wishlist]', root).forEach(button => {
      const on = wishlist.has(Number(button.dataset.nexxiWishlist));
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
      button.setAttribute('aria-label', on ? 'Bỏ khỏi yêu thích' : 'Thêm vào yêu thích');
      const icon = $('.material-symbols-outlined', button);
      if (icon) {
        if (icon.dataset.offIcon === undefined) icon.dataset.offIcon = icon.textContent.trim();
        icon.textContent = on ? 'favorite' : icon.dataset.offIcon;
      }
    });
    $$('[data-nexxi-wishlist-count]').forEach(badge => { badge.textContent = String(wishlist.size); });
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-nexxi-wishlist]');
    if (!button) return;
    event.preventDefault();
    const id = Number(button.dataset.nexxiWishlist);
    const added = !wishlist.has(id);
    if (added) wishlist.add(id); else wishlist.delete(id);
    saveLocal();
    syncServer();
    paintWishlist();
    toast(added ? 'Đã lưu vào danh sách yêu thích' : 'Đã bỏ khỏi danh sách yêu thích', {icon: added ? 'favorite' : 'heart_broken', link: added && config.wishlistPage ? {href: config.wishlistPage, label: 'Xem danh sách'} : null});
    const grid = button.closest('[data-nexxi-wishlist-grid]');
    if (grid && !added) { button.closest('[data-nexxi-card]')?.remove(); toggleWishlistEmpty(); }
  });
  const toggleWishlistEmpty = () => {
    const grid = $('[data-nexxi-wishlist-grid]');
    const empty = $('[data-nexxi-wishlist-empty]');
    if (!grid || !empty) return;
    const has = !!$('[data-nexxi-card]', grid);
    empty.classList.toggle('hidden', has);
    grid.classList.toggle('hidden', !has);
  };
  const renderWishlistPage = async () => {
    const grid = $('[data-nexxi-wishlist-grid]');
    if (!grid) return;
    const ids = Array.from(wishlist);
    const rendered = $$('[data-nexxi-card]', grid).map(card => cardData(card).id).filter(Boolean);
    if (!ids.length) { grid.replaceChildren(); toggleWishlistEmpty(); return; }
    if (ids.length === rendered.length && ids.every(id => rendered.includes(id))) { toggleWishlistEmpty(); return; }
    grid.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(config.restUrl + 'cards?ids=' + ids.join(','), {credentials: 'same-origin'});
      const data = await response.json();
      grid.innerHTML = data.html || '';
      initCards(grid);
      paintWishlist(grid);
    } catch (e) { /* keep server-rendered cards */ }
    grid.removeAttribute('aria-busy');
    toggleWishlistEmpty();
  };

  /* ---------- Countdowns ---------- */
  const countdown = ids => {
    const boxes = ids.map(id => document.getElementById(id));
    if (!boxes.every(Boolean)) return;
    const end = Date.parse(config.saleEnd || '');
    const tick = () => {
      if (!Number.isFinite(end)) { boxes.forEach(box => { box.textContent = '--'; }); return false; }
      let left = Math.max(0, Math.floor((end - Date.now()) / 1000));
      const parts = [Math.floor(left / 3600), Math.floor((left %= 3600) / 60), left % 60];
      parts.forEach((value, index) => { boxes[index].textContent = String(value).padStart(2, '0'); });
      return true;
    };
    if (tick()) setInterval(tick, 1000);
  };
  countdown(['fs-hours', 'fs-minutes', 'fs-seconds']);
  countdown(['hours-box', 'minutes-box', 'seconds-box']);
  countdown(['pdp-hours', 'pdp-minutes', 'pdp-seconds']);

  /* ---------- Search ---------- */
  const search = input => {
    const term = (input?.value || '').trim();
    if (!term) { input?.focus(); return; }
    const url = new URL(config.shop || '/', location.origin);
    url.searchParams.set('s', term);
    url.searchParams.set('post_type', 'product');
    location.href = url.toString();
  };
  $$('[data-nexxi-search]').forEach(input => input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); search(input); } }));
  $$('[data-nexxi-search-btn]').forEach(button => button.addEventListener('click', event => { event.preventDefault(); search($('[data-nexxi-search]', button.parentElement) || $('[data-nexxi-search]')); }));

  /* ---------- Listing filters ---------- */
  const listingUrl = () => {
    const url = new URL(location.href);
    url.pathname = url.pathname.replace(/\/page\/\d+\/?$/, '/');
    url.searchParams.delete('paged');
    url.searchParams.delete('product-page');
    return url;
  };
  $$('[data-nexxi-sort]').forEach(select => select.addEventListener('change', () => { if (select.value) location.href = select.value; }));
  $$('[data-nexxi-filter]').forEach(input => input.addEventListener('change', () => {
    const slug = input.dataset.nexxiFilter;
    const values = $$(`[data-nexxi-filter="${slug}"]:checked`).map(box => box.value);
    const url = listingUrl();
    if (values.length) url.searchParams.set('filter_' + slug, values.join(',')); else url.searchParams.delete('filter_' + slug);
    if (values.length > 1) url.searchParams.set('query_type_' + slug, 'or'); else url.searchParams.delete('query_type_' + slug);
    location.href = url.toString();
  }));
  $$('[data-nexxi-more-toggle]').forEach(button => {
    const label = button.textContent;
    button.addEventListener('click', () => {
      const items = $$(`[data-nexxi-more="${button.dataset.nexxiMoreToggle}"]`);
      const open = items.some(item => item.classList.contains('hidden'));
      items.forEach(item => item.classList.toggle('hidden', !open));
      button.textContent = open ? 'Thu gọn' : label;
    });
  });
  $$('[data-nexxi-price]').forEach(range => {
    const label = $('[data-nexxi-price-label]');
    const short = value => (value >= 1000 ? new Intl.NumberFormat('vi-VN').format(Math.round(value / 1000)) + 'k' : String(value));
    const min = Number(range.dataset.min || range.min || 0);
    range.addEventListener('input', () => { if (label) label.textContent = `${short(min)} - ${short(Number(range.value))} đ`; });
    range.addEventListener('change', () => {
      const url = listingUrl();
      if (Number(range.value) >= Number(range.max)) url.searchParams.delete('max_price'); else url.searchParams.set('max_price', range.value);
      location.href = url.toString();
    });
  });

  /* ---------- Home: best-seller tabs ---------- */
  window.filterItems = (category, button) => {
    $$('#filter-tab-group .filter-tab').forEach(tab => {
      const on = tab === button;
      tab.classList.toggle('bg-primary', on);
      tab.classList.toggle('text-on-primary', on);
      tab.classList.toggle('shadow-sm', on);
      tab.classList.toggle('bg-surface-container-low', !on);
      tab.classList.toggle('text-on-surface-variant', !on);
    });
    $$('#product-grid [data-nexxi-card]').forEach(card => { card.hidden = category !== 'all' && card.dataset.category !== category; });
  };
  window.scrollBestSeller = direction => { $('#product-grid')?.scrollBy({left: direction * 280, behavior: 'smooth'}); };

  /* ---------- Size advisor (home section + product page) ---------- */
  const adviseSize = (weight, height) => {
    if (!chart.length || !(weight > 0) || !(height > 0)) return null;
    const byHeight = chart.reduce((best, row) => (Math.abs(height - row[3]) <= Math.abs(height - best[3]) ? row : best), chart[0])[0];
    const gap = row => (weight < row[1] ? row[1] - weight : weight > row[2] ? weight - row[2] : 0);
    const byWeight = chart.reduce((best, row) => (gap(row) <= gap(best) ? row : best), chart[0])[0];
    return chart.find(row => row[0] === Math.max(byHeight, byWeight));
  };
  $$('[data-nexxi-size-advisor]').forEach(box => {
    const weight = $('[data-advisor-weight]', box);
    const height = $('[data-advisor-height]', box);
    const title = $('[data-advisor-title]', box);
    const desc = $('[data-advisor-desc]', box);
    const link = $('[data-advisor-link]', box);
    const apply = $('[data-advisor-apply]', box);
    const update = () => {
      const w = Number(weight?.value);
      const h = Number(height?.value);
      const row = (w >= 6 && w <= 60 && h >= 60 && h <= 180) ? adviseSize(w, h) : null;
      box.dataset.advised = row ? String(row[0]) : '';
      if (!title) return;
      if (!row) {
        title.textContent = 'Mẹ nhập cân nặng 8–55 kg và chiều cao 75–170 cm nhé';
        if (desc) desc.textContent = 'Bảng size chỉ mang tính tham khảo.';
        return;
      }
      title.replaceChildren('Khuyên dùng: ');
      const strong = document.createElement('strong');
      strong.className = 'text-secondary font-bold';
      strong.textContent = `Size ${row[0]} (${row[1]}–${row[2]} kg)`;
      title.appendChild(strong);
      if (desc) desc.textContent = 'Nếu bé nằm giữa hai size, mẹ chọn size lớn hơn để bé mặc thoải mái lâu hơn.';
      if (link) {
        const url = new URL(config.shop || '/', location.origin);
        url.searchParams.set('filter_size', 'size-' + row[0]);
        link.href = url.toString();
      }
      $$('[data-size-row]').forEach(tr => {
        const on = Number(tr.dataset.sizeRow) === row[0];
        tr.classList.toggle('bg-secondary-fixed', on);
        tr.classList.toggle('font-bold', on);
      });
    };
    [weight, height].forEach(input => input?.addEventListener('input', update));
    apply?.addEventListener('click', () => {
      const size = Number(box.dataset.advised);
      const pill = $(`[data-nexxi-pill][data-number="${size}"]`);
      if (!size) { toast('Mẹ nhập cân nặng và chiều cao của bé trước nhé', {icon: 'straighten'}); return; }
      if (!pill) { toast(`Mẫu này chưa có Size ${size}, mẹ tham khảo mẫu khác nhé`, {error: true}); return; }
      if (pill.disabled) { toast(`Size ${size} đang tạm hết với màu đã chọn`, {error: true}); return; }
      pill.click();
      pill.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
    update();
  });

  /* ---------- Product page ---------- */
  const product = config.currentProduct;
  if (config.screen === 'product' && product) {
    const variations = product.variations || [];
    const state = {size: '', color: '', qty: 1};
    const mainImg = $('#mainProductImg');
    const zoom = $('[data-nexxi-zoom]');
    const showImage = src => { if (!src || !mainImg) return; mainImg.src = src; if (zoom) zoom.href = src; };

    $$('[data-nexxi-thumb]').forEach(thumb => thumb.addEventListener('click', () => {
      showImage(thumb.dataset.nexxiThumb);
      $$('[data-nexxi-thumb]').forEach(other => {
        const on = other === thumb;
        other.classList.toggle('ring-2', on);
        other.classList.toggle('ring-secondary-container', on);
        other.classList.toggle('opacity-70', !on);
      });
    }));

    const pills = $$('[data-nexxi-pill]');
    const colors = $$('[data-nexxi-color]');
    const needsSize = pills.length > 0;
    const needsColor = colors.length > 0;
    const matches = (v, size, color) => (!size || v.size === size) && (!color || v.color === color);
    const current = () => (product.type === 'variable' && (!needsSize || state.size) && (!needsColor || state.color) ? variations.find(v => matches(v, state.size, state.color)) || null : null);

    const paintOptions = () => {
      pills.forEach(pill => {
        const slug = pill.dataset.nexxiPill;
        const available = variations.some(v => v.inStock && matches(v, slug, state.color));
        const on = slug === state.size;
        pill.disabled = !available;
        pill.classList.toggle('is-unavailable', !available);
        pill.classList.toggle('ring-2', on);
        pill.classList.toggle('ring-secondary-container', on);
        pill.classList.toggle('bg-secondary-fixed', on);
        pill.classList.toggle('bg-surface-container-low', !on);
        pill.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      colors.forEach(button => {
        const slug = button.dataset.nexxiColor;
        const available = variations.some(v => v.inStock && matches(v, state.size, slug));
        const on = slug === state.color;
        button.classList.toggle('is-unavailable', !available);
        button.classList.toggle('ring-2', on);
        button.classList.toggle('ring-secondary-container', on);
        button.classList.toggle('bg-secondary-fixed', on);
        button.classList.toggle('bg-surface-container-low', !on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    };

    const qtyEl = $('#productQtyDisplay');
    const paintQty = () => { if (qtyEl) qtyEl.textContent = String(state.qty); };
    const priceEl = $('[data-nexxi-price]');
    const regularEl = $('[data-nexxi-regular]');
    const discountEl = $('[data-nexxi-discount]');
    const stockEl = $('[data-nexxi-stock]');
    const defaults = {price: priceEl?.textContent, regular: regularEl?.textContent, regularHidden: regularEl?.classList.contains('hidden'), discount: discountEl?.textContent, discountHidden: discountEl?.classList.contains('hidden'), stock: stockEl?.textContent};
    const maxQty = () => { const v = current(); const max = Number(v ? v.max : product.max); return max > 0 ? max : 99; };
    const paintVariation = () => {
      const v = current();
      if (!v) {
        if (priceEl) priceEl.textContent = defaults.price;
        if (regularEl) { regularEl.textContent = defaults.regular; regularEl.classList.toggle('hidden', defaults.regularHidden); }
        if (discountEl) { discountEl.textContent = defaults.discount; discountEl.classList.toggle('hidden', defaults.discountHidden); }
        if (stockEl) stockEl.textContent = defaults.stock;
        return;
      }
      const onSale = v.regular > v.price;
      if (priceEl) priceEl.textContent = money(v.price);
      if (regularEl) { regularEl.textContent = money(v.regular); regularEl.classList.toggle('hidden', !onSale); }
      if (discountEl) { discountEl.textContent = '-' + Math.round((1 - v.price / v.regular) * 100) + '%'; discountEl.classList.toggle('hidden', !onSale); }
      if (stockEl) stockEl.textContent = !v.inStock ? 'Tạm hết hàng' : (v.stock !== null && v.stock <= 10 ? `Chỉ còn ${v.stock} chiếc` : 'Còn hàng – giao ngay');
      if (v.image) showImage(v.image);
      state.qty = Math.min(state.qty, maxQty());
      paintQty();
    };

    pills.forEach(pill => pill.addEventListener('click', () => {
      if (pill.disabled) return;
      state.size = pill.dataset.nexxiPill === state.size ? '' : pill.dataset.nexxiPill;
      const badge = $('#currentSelectedSizeBadge');
      if (badge) badge.textContent = state.size ? ($('div', pill)?.textContent.trim() || pill.textContent.trim()) : 'Chọn size cho bé';
      $('[data-nexxi-size-hint]')?.classList.add('hidden');
      // Auto-pick the only colour left for this size.
      if (state.size && needsColor && !state.color) {
        const left = [...new Set(variations.filter(v => v.inStock && v.size === state.size).map(v => v.color))];
        if (left.length === 1) selectColor(left[0]);
      }
      if (state.size && state.color && !variations.some(v => v.inStock && matches(v, state.size, state.color))) selectColor('');
      paintOptions();
      paintVariation();
    }));
    const selectColor = slug => {
      state.color = slug;
      const button = colors.find(b => b.dataset.nexxiColor === slug);
      const name = $('#selectedColorName');
      if (name) name.textContent = button ? button.dataset.name : 'Chọn màu';
      if (state.size && slug && !variations.some(v => v.inStock && matches(v, state.size, slug))) {
        state.size = '';
        const badge = $('#currentSelectedSizeBadge');
        if (badge) badge.textContent = 'Chọn size cho bé';
      }
    };
    colors.forEach(button => button.addEventListener('click', () => {
      selectColor(button.dataset.nexxiColor === state.color ? '' : button.dataset.nexxiColor);
      paintOptions();
      paintVariation();
    }));
    if (colors.length === 1) selectColor(colors[0].dataset.nexxiColor);
    if (pills.length === 1 && !pills[0].disabled) { state.size = pills[0].dataset.nexxiPill; const badge = $('#currentSelectedSizeBadge'); if (badge) badge.textContent = $('div', pills[0])?.textContent.trim() || ''; }
    paintOptions();
    paintVariation();

    $$('[data-nexxi-qty]').forEach(button => button.addEventListener('click', () => {
      state.qty = Math.min(maxQty(), Math.max(1, state.qty + Number(button.dataset.nexxiQty)));
      paintQty();
    }));

    const purchase = async (button, buyNow) => {
      let target = product.id;
      if (product.type === 'variable') {
        const v = current();
        if (!v) {
          const hint = $('[data-nexxi-size-hint]');
          const missing = needsSize && !state.size ? 'size' : 'màu';
          if (hint) { hint.textContent = `Mẹ chọn ${missing} cho bé trước khi ${buyNow ? 'mua' : 'thêm vào giỏ'} nhé!`; hint.classList.remove('hidden'); }
          toast(`Mẹ chọn ${missing} cho bé trước nhé!`, {icon: 'straighten'});
          ($('[data-nexxi-size-grid]') || colors[0])?.scrollIntoView({behavior: 'smooth', block: 'center'});
          return;
        }
        if (!v.inStock) { failedToast(); return; }
        target = v.id;
      } else if (product.type !== 'simple') {
        return;
      }
      busy(button, true);
      try {
        await addToCart(target, state.qty);
        if (buyNow) { location.href = config.checkout; return; }
        addedToast(product.name);
      } catch (error) { failedToast(error); }
      busy(button, false);
    };
    $('[data-nexxi-pdp-add]')?.addEventListener('click', event => purchase(event.currentTarget, false));
    $('[data-nexxi-pdp-buy]')?.addEventListener('click', event => purchase(event.currentTarget, true));

    // Tabs.
    const tabs = $$('[data-nexxi-tab]');
    const activeClass = tabs[0]?.className || '';
    const idleClass = tabs[1]?.className.replace(' flex items-center gap-1.5', '') || '';
    const showTab = name => tabs.forEach(tab => {
      const on = tab.dataset.nexxiTab === name;
      const extra = tab.dataset.nexxiTab === 'reviews' ? ' flex items-center gap-1.5' : '';
      tab.className = (on ? activeClass.replace(' flex items-center gap-1.5', '') : idleClass) + extra;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      document.getElementById('tabContent-' + tab.dataset.nexxiTab)?.classList.toggle('hidden', !on);
    });
    tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.nexxiTab)));
    $$('[data-nexxi-tab-link]').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      showTab(link.dataset.nexxiTabLink);
      document.getElementById('danh-gia-tab')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }));
    if (location.hash === '#danh-gia-tab' || /^#comment-\d+$/.test(location.hash)) showTab('reviews');

    $('[data-nexxi-size-table-open]')?.addEventListener('click', () => {
      const table = $('[data-nexxi-size-table]');
      if (!table) return;
      table.open = true;
      table.scrollIntoView({behavior: 'smooth', block: 'center'});
    });

    // Review stars.
    $$('[data-nexxi-star]').forEach(input => input.addEventListener('change', () => {
      const value = Number(input.value);
      $$('[data-star-icon]').forEach(icon => { icon.style.fontVariationSettings = `'FILL' ${Number(icon.dataset.starIcon) <= value ? 1 : 0}`; });
    }));
  }

  /* ---------- Cart page ---------- */
  $$('.nexxi-cart-qty').forEach(input => input.addEventListener('input', () => {
    const button = $('.woocommerce-cart-form button[name="update_cart"]');
    if (button) button.disabled = false;
  }));

  initCards();
  paintWishlist();
  renderWishlistPage();
})();
