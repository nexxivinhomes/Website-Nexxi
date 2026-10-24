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
      // Several colours / other options still possible, or an "any value" variation: choose on the product page.
      if (pool.length > 1 || pool[0].any) { location.href = data.url; return; }
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
  const parsedEnd = Date.parse(config.saleEnd || '');
  const fallbackLeft = Number(config.saleLeft || 0);
  const saleEndMs = Number.isFinite(parsedEnd) && parsedEnd > Date.now()
    ? parsedEnd
    : (fallbackLeft > 0 ? Date.now() + fallbackLeft * 1000 : 0);
  let expireReloadScheduled = false;
  const countdown = ids => {
    const boxes = ids.map(id => document.getElementById(id));
    if (!boxes.every(Boolean)) return;
    const wasRunning = saleEndMs > Date.now();
    const tick = () => {
      if (!saleEndMs || saleEndMs <= Date.now()) {
        boxes.forEach(box => { box.textContent = '00'; });
        if (wasRunning && !expireReloadScheduled) {
          expireReloadScheduled = true;
          setTimeout(() => location.reload(), 1200);
        }
        return false;
      }
      let left = Math.max(0, Math.floor((saleEndMs - Date.now()) / 1000));
      const parts = [Math.floor(left / 3600), Math.floor((left %= 3600) / 60), left % 60];
      parts.forEach((value, index) => { boxes[index].textContent = String(value).padStart(2, '0'); });
      return left > 0;
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
      if (pill.classList.contains('is-unavailable')) { toast(`Size ${size} đang tạm hết với phân loại đã chọn`, {error: true}); return; }
      pill.click();
      pill.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
    update();
  });

  /* ---------- Product page ---------- */
  const product = config.currentProduct;
  if (config.screen === 'product' && product) {
    const variations = product.variations || [];
    const state = {attrs: {}, qty: 1};
    const mainImg = $('#mainProductImg');
    const zoom = $('[data-nexxi-zoom]');
    const showImage = src => {
      if (!src || !mainImg) return;
      mainImg.src = src;
      if (zoom) zoom.href = src;
      const matchedThumb = $$('[data-nexxi-thumb]').find(t => t.dataset.nexxiThumb === src);
      if (matchedThumb) {
        $$('[data-nexxi-thumb]').forEach(other => {
          const on = other === matchedThumb;
          other.classList.toggle('ring-2', on);
          other.classList.toggle('ring-secondary-container', on);
          other.classList.toggle('opacity-70', !on);
        });
      }
    };

    $$('[data-nexxi-thumb]').forEach(thumb => thumb.addEventListener('click', () => {
      showImage(thumb.dataset.nexxiThumb);
      $$('[data-nexxi-thumb]').forEach(other => {
        const on = other === thumb;
        other.classList.toggle('ring-2', on);
        other.classList.toggle('ring-secondary-container', on);
        other.classList.toggle('opacity-70', !on);
      });
    }));

    // 5-item horizontal thumbnail carousel with wrap-around arrows (never changes main image).
    const thumbCarousel = $('[data-nexxi-thumb-carousel]');
    if (thumbCarousel) {
      const track = $('[data-nexxi-thumb-track]', thumbCarousel);
      const thumbs = $$('[data-nexxi-thumb]', thumbCarousel);
      const visibleCount = 5;
      let offsetIndex = 0;
      const maxOffset = () => Math.max(0, thumbs.length - visibleCount);

      const scrollToOffset = idx => {
        if (!track || !thumbs.length) return;
        const max = maxOffset();
        offsetIndex = Math.max(0, Math.min(max, idx));
        if (max === 0) {
          track.scrollTo({left: 0, behavior: 'smooth'});
          return;
        }
        const firstLeft = thumbs[0].offsetLeft;
        const targetLeft = thumbs[offsetIndex] ? (thumbs[offsetIndex].offsetLeft - firstLeft) : 0;
        track.scrollTo({left: targetLeft, behavior: 'smooth'});
      };

      $$('[data-nexxi-thumb-nav]', thumbCarousel).forEach(btn => btn.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const dir = Number(btn.dataset.nexxiThumbNav) || 1;
        const max = maxOffset();
        if (max === 0) {
          track?.scrollTo({left: 0, behavior: 'smooth'});
          return;
        }
        if (dir > 0) {
          // If already at the last visible position, wrap around to the beginning (0)
          const atEnd = offsetIndex >= max || (track && track.scrollLeft >= (track.scrollWidth - track.clientWidth - 4));
          scrollToOffset(atEnd ? 0 : offsetIndex + 1);
        } else {
          // If already at the beginning, wrap around to the end (max)
          const atStart = offsetIndex <= 0 && (!track || track.scrollLeft <= 4);
          scrollToOffset(atStart ? max : offsetIndex - 1);
        }
      }));
    }

    // One selector group per variation attribute (colour, size or any custom attribute).
    const groups = $$('[data-nexxi-attr-group]').map(el => ({
      key: el.dataset.nexxiAttrGroup,
      label: el.dataset.label || 'phân loại',
      el,
      buttons: $$('[data-nexxi-attr]', el),
      selected: $('[data-nexxi-attr-selected]', el),
    }));
    // '' on a variation means "any value" for that attribute.
    const fits = (v, sel) => Object.entries(sel).every(([key, value]) => !value || !v.attrs || !v.attrs[key] || v.attrs[key] === value);
    const complete = () => groups.every(g => state.attrs[g.key]);
    const current = () => (product.type === 'variable' && complete() ? variations.find(v => fits(v, state.attrs)) || null : null);
    const available = (key, value) => variations.some(v => v.inStock && fits(v, {...state.attrs, [key]: value}));

    const paintOptions = () => groups.forEach(g => g.buttons.forEach(button => {
      const value = button.dataset.value;
      const on = state.attrs[g.key] === value;
      button.classList.toggle('is-unavailable', !available(g.key, value));
      button.classList.toggle('ring-2', on);
      button.classList.toggle('ring-secondary-container', on);
      button.classList.toggle('bg-secondary-fixed', on);
      button.classList.toggle('bg-surface-container-low', !on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    }));
    const paintLabels = () => groups.forEach(g => {
      if (!g.selected) return;
      const button = g.buttons.find(b => b.dataset.value === state.attrs[g.key]);
      g.selected.textContent = button ? button.dataset.name : g.selected.dataset.placeholder;
    });

    const qtyEl = $('#productQtyDisplay');
    const paintQty = () => { if (qtyEl) qtyEl.textContent = String(state.qty); };
    const priceEl = $('[data-nexxi-price]');
    const regularEl = $('[data-nexxi-regular]');
    const discountEl = $('[data-nexxi-discount]');
    const galleryDiscountEl = $('[data-nexxi-gallery-discount]');
    const stockEl = $('[data-nexxi-stock]');
    const defaults = {
      price: priceEl?.textContent,
      regular: regularEl?.textContent,
      regularHidden: regularEl?.classList.contains('hidden'),
      discount: discountEl?.textContent,
      discountHidden: discountEl?.classList.contains('hidden'),
      galleryDiscount: galleryDiscountEl?.textContent,
      galleryDiscountHidden: galleryDiscountEl?.classList.contains('hidden'),
      stock: stockEl?.textContent
    };
    const maxQty = () => { const v = current(); const max = Number(v ? v.max : product.max); return max > 0 ? max : 99; };
    const paintVariation = () => {
      // Switch main product image if a selected classification/color button or variation has an image
      let targetImg = '';
      groups.forEach(g => {
        const activeBtn = g.buttons.find(b => b.dataset.value === state.attrs[g.key] && b.dataset.image);
        if (activeBtn && activeBtn.dataset.image) targetImg = activeBtn.dataset.image;
      });
      const v = current();
      if (!targetImg && v && v.image) {
        targetImg = v.image;
      } else if (!targetImg && Object.values(state.attrs).some(Boolean)) {
        const imgVar = variations.find(x => x.image && fits(x, state.attrs));
        if (imgVar && imgVar.image) targetImg = imgVar.image;
      }
      if (targetImg) showImage(targetImg);

      if (!v) {
        if (priceEl) priceEl.textContent = defaults.price;
        if (regularEl) { regularEl.textContent = defaults.regular; regularEl.classList.toggle('hidden', defaults.regularHidden); }
        if (discountEl) { discountEl.textContent = defaults.discount; discountEl.classList.toggle('hidden', defaults.discountHidden); }
        if (galleryDiscountEl) { galleryDiscountEl.textContent = defaults.galleryDiscount; galleryDiscountEl.classList.toggle('hidden', defaults.galleryDiscountHidden); }
        if (stockEl) stockEl.textContent = defaults.stock;
        return;
      }
      const onSale = v.regular > v.price;
      const pctText = '-' + Math.round((1 - v.price / v.regular) * 100) + '%';
      if (priceEl) priceEl.textContent = money(v.price);
      if (regularEl) { regularEl.textContent = money(v.regular); regularEl.classList.toggle('hidden', !onSale); }
      if (discountEl) { discountEl.textContent = pctText; discountEl.classList.toggle('hidden', !onSale); }
      if (galleryDiscountEl) { galleryDiscountEl.textContent = pctText; galleryDiscountEl.classList.toggle('hidden', !onSale); }
      if (stockEl) stockEl.textContent = !v.inStock ? 'Tạm hết hàng' : (v.stock !== null && v.stock <= 10 ? `Chỉ còn ${v.stock} chiếc` : 'Còn hàng – giao ngay');
      state.qty = Math.min(state.qty, maxQty());
      paintQty();
    };
    const repaint = () => { paintLabels(); paintOptions(); paintVariation(); };

    const select = (key, value) => {
      state.attrs[key] = value;
      if (value) {
        // Drop other choices that no longer combine with this one.
        groups.forEach(g => {
          if (g.key !== key && state.attrs[g.key] && !variations.some(v => v.inStock && fits(v, {[key]: value, [g.key]: state.attrs[g.key]}))) state.attrs[g.key] = '';
        });
        // Auto-pick a group when only one option is left.
        groups.forEach(g => {
          if (state.attrs[g.key]) return;
          const left = g.buttons.filter(b => available(g.key, b.dataset.value));
          if (left.length === 1) state.attrs[g.key] = left[0].dataset.value;
        });
      }
      $('[data-nexxi-size-hint]')?.classList.add('hidden');
      groups.forEach(g => g.el.classList.remove('nexxi-attr-missing'));
      repaint();
    };
    groups.forEach(g => g.buttons.forEach(button => button.addEventListener('click', () => {
      select(g.key, state.attrs[g.key] === button.dataset.value ? '' : button.dataset.value);
    })));
    groups.forEach(g => { if (g.buttons.length === 1) state.attrs[g.key] = g.buttons[0].dataset.value; });
    repaint();

    $$('[data-nexxi-qty]').forEach(button => button.addEventListener('click', () => {
      state.qty = Math.min(maxQty(), Math.max(1, state.qty + Number(button.dataset.nexxiQty)));
      paintQty();
    }));

    // Variations with an "any value" attribute need the chosen values posted: use WooCommerce's form handler.
    const postForm = (v, buyNow) => {
      const form = document.createElement('form');
      form.method = 'post';
      form.action = buyNow ? config.checkout : (config.cart || product.url);
      const fields = {'add-to-cart': product.id, product_id: product.id, variation_id: v.id, quantity: state.qty};
      groups.forEach(g => {
        const b = g.buttons.find(x => x.dataset.value === state.attrs[g.key]);
        if (b) {
          if (g.key === 'nexxi_color') {
            fields['nexxi_color'] = b.dataset.name || b.dataset.raw || b.dataset.value;
          } else {
            fields['attribute_' + g.key] = b.dataset.raw || b.dataset.value;
          }
        }
      });
      Object.entries(fields).forEach(([name, value]) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = String(value); form.appendChild(input); });
      document.body.appendChild(form);
      form.submit();
    };

    const purchase = async (button, buyNow) => {
      let target = product.id;
      if (product.type === 'variable') {
        const v = current();
        if (!v) {
          const missing = groups.find(g => !state.attrs[g.key]);
          const what = missing ? missing.label : 'phân loại';
          const hint = $('[data-nexxi-size-hint]');
          if (hint) { hint.textContent = `Mẹ chọn ${what} cho bé trước khi ${buyNow ? 'mua' : 'thêm vào giỏ'} nhé!`; hint.classList.remove('hidden'); }
          toast(missing ? `Mẹ chọn ${what} cho bé trước nhé!` : 'Phân loại này tạm hết, mẹ chọn phân loại khác nhé!', {icon: 'straighten', error: !missing});
          if (missing) { missing.el.classList.add('nexxi-attr-missing'); missing.el.scrollIntoView({behavior: 'smooth', block: 'center'}); }
          return;
        }
        if (!v.inStock) { failedToast(); return; }
        if (v.any || state.attrs.nexxi_color) { postForm(v, buyNow); return; }
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

  /* ---------- Mobile Drawer & Mobile Shop Filter Toggle ---------- */
  const mobDrawer = $('#nexxi-mobile-drawer');
  const setMobDrawer = (open, focusSearch = false) => {
    if (!mobDrawer) return;
    mobDrawer.classList.toggle('is-open', open);
    mobDrawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('nexxi-mob-drawer-open', open);
    $$('[data-nexxi-mob-menu-open]').forEach(btn => btn.setAttribute('aria-expanded', open ? 'true' : 'false'));
    if (open && focusSearch) {
      setTimeout(() => $('[data-nexxi-mob-search-input]', mobDrawer)?.focus(), 120);
    }
  };
  $$('[data-nexxi-mob-menu-open]').forEach(btn => btn.addEventListener('click', e => {
    e.preventDefault();
    setMobDrawer(!mobDrawer?.classList.contains('is-open'), false);
  }));
  $$('[data-nexxi-mob-search-open]').forEach(btn => btn.addEventListener('click', e => {
    e.preventDefault();
    setMobDrawer(true, true);
  }));
  $$('[data-nexxi-mob-close]').forEach(el => el.addEventListener('click', () => setMobDrawer(false)));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && mobDrawer?.classList.contains('is-open')) setMobDrawer(false);
  });

  $$('[data-nexxi-mobile-filter-toggle]').forEach(btn => btn.addEventListener('click', e => {
    if (window.innerWidth >= 1024) return;
    e.preventDefault();
    const card = btn.closest('.nexxi-shop-filter-card');
    if (!card) return;
    const isOpen = card.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }));

  /* ---------- Hero Background Banner Slideshow (5s/slide) ---------- */
  const initHeroSlider = () => {
    const slider = $('[data-nexxi-hero-slider]');
    if (!slider) return;
    const section = slider.closest('[data-nxr="home-hero"]') || slider.parentElement;
    let dotsWrap = $('[data-nexxi-hero-dots]', section);
    if (!dotsWrap && section) {
      dotsWrap = document.createElement('div');
      dotsWrap.className = 'nexxi-hero-bg__nav relative z-20 flex items-center justify-center gap-2 pt-space-md';
      dotsWrap.setAttribute('data-nexxi-hero-dots', '');
      dotsWrap.setAttribute('aria-label', 'Chuyển ảnh nền banner');
      section.appendChild(dotsWrap);
    }

    const intervalMs = Number(slider.getAttribute('data-interval')) || 5000;
    const allSlides = $$('.nexxi-hero-bg__slide', slider);
    let activeSlides = [];
    let currentIndex = 0;
    let timer = null;

    const getValidSlides = () => allSlides.filter(slide => {
      const img = $('img', slide);
      const src = (img?.getAttribute('src') || '').trim();
      const valid = src !== '' && !src.endsWith('/');
      slide.classList.toggle('is-empty', !valid);
      if (!valid) slide.classList.remove('is-active');
      return valid;
    });

    const goTo = (index, restartTimer = true) => {
      if (!activeSlides.length) return;
      currentIndex = ((index % activeSlides.length) + activeSlides.length) % activeSlides.length;
      activeSlides.forEach((slide, idx) => {
        slide.classList.toggle('is-active', idx === currentIndex);
      });
      if (dotsWrap) {
        $$('.nexxi-hero-bg__dot', dotsWrap).forEach((dot, idx) => {
          const isMatch = idx === currentIndex;
          dot.classList.remove('is-active');
          if (isMatch) {
            void dot.offsetWidth; // Restart 5s CSS progress bar animation
            dot.classList.add('is-active');
          }
          dot.setAttribute('aria-current', isMatch ? 'true' : 'false');
        });
      }
      if (restartTimer) startTimer();
    };

    const startTimer = () => {
      if (timer) clearInterval(timer);
      if (activeSlides.length <= 1) return;
      timer = setInterval(() => {
        goTo(currentIndex + 1, false);
      }, intervalMs);
    };

    const rebuild = (focusKey = null) => {
      activeSlides = getValidSlides();
      if (dotsWrap) {
        dotsWrap.replaceChildren();
        if (activeSlides.length > 1) {
          activeSlides.forEach((slide, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'nexxi-hero-bg__dot' + (idx === 0 ? ' is-active' : '');
            btn.setAttribute('aria-label', `Ảnh banner ${idx + 1}`);
            btn.addEventListener('click', e => {
              e.preventDefault();
              goTo(idx, true);
            });
            dotsWrap.appendChild(btn);
          });
          dotsWrap.style.display = '';
        } else {
          dotsWrap.style.display = 'none';
        }
      }
      let targetIdx = currentIndex < activeSlides.length ? currentIndex : 0;
      if (focusKey) {
        const foundIdx = activeSlides.findIndex(s => $('img', s)?.getAttribute('data-nxi') === focusKey);
        if (foundIdx !== -1) targetIdx = foundIdx;
      }
      goTo(targetIdx, true);
    };

    rebuild();
    document.addEventListener('nexxi:hero-slider-refresh', e => {
      rebuild(e.detail?.key || null);
    });
  };

  /* ---------- Exact 0px Gap Between Fixed Header & Main/Banner ---------- */
  const syncHeaderOffset = () => {
    const header = $('body > header');
    const main = $('body > header + main') || $('main');
    if (!header || !main) return;
    const apply = () => {
      const h = Math.round(header.getBoundingClientRect().height);
      if (h > 0) {
        document.documentElement.style.setProperty('--nexxi-header-h', `${h}px`);
        main.style.setProperty('padding-top', `${h}px`, 'important');
      }
    };
    apply();
    if (window.ResizeObserver) {
      new ResizeObserver(apply).observe(header);
    }
    window.addEventListener('resize', apply, {passive: true});
    window.addEventListener('load', apply, {once: true});
  };

  syncHeaderOffset();
  initHeroSlider();
  initCards();
  paintWishlist();
  renderWishlistPage();
})();
