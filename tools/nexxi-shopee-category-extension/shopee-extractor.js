(function () {
  const MAX_WAIT_MS = 18000;
  const POLL_MS = 500;
  const startTime = Date.now();

  function cleanText(str) {
    return String(str || '').replace(/\s+/g, ' ').trim();
  }

  function isElementVisible(el) {
    if (!el || typeof el.getBoundingClientRect !== 'function') return false;
    const style = window.getComputedStyle(el);
    if (!style || style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
      return false;
    }
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function detectGate() {
    const href = String(window.location.href || '').toLowerCase();
    const gateUrlPatterns = [
      '/buyer/login',
      '/verify/captcha',
      '/verify/traffic',
      '/verify/',
      'captcha'
    ];
    for (const pat of gateUrlPatterns) {
      if (href.includes(pat)) {
        return 'Shopee yêu cầu đăng nhập hoặc xác minh CAPTCHA (' + pat + ').';
      }
    }

    const loginInput = document.querySelector('form input[name="loginKey"]');
    if (loginInput && isElementVisible(loginInput)) {
      return 'Shopee đang hiển thị trang đăng nhập.';
    }

    const captchaSelectors = [
      'iframe[src*="captcha"]',
      'iframe[src*="verify"]',
      '[class*="captcha" i]',
      '[id*="captcha" i]'
    ];
    for (const sel of captchaSelectors) {
      const nodes = document.querySelectorAll(sel);
      for (const n of nodes) {
        if (isElementVisible(n)) {
          return 'Shopee đang yêu cầu xác minh CAPTCHA.';
        }
      }
    }

    const bodyText = cleanText(document.body ? document.body.innerText : '').toLowerCase();
    const promptMarkers = [
      'vui lòng giải',
      'đăng nhập để tiếp tục',
      'verify to continue',
      'xác minh bảo mật',
      'trượt để xác minh',
      'kéo để hoàn thành câu đố',
      'security check'
    ];
    for (const marker of promptMarkers) {
      if (bodyText.includes(marker)) {
        return 'Shopee yêu cầu xác minh bảo mật hoặc đăng nhập để tiếp tục.';
      }
    }
    return '';
  }

  function detectNotFound() {
    const elapsed = Date.now() - startTime;
    let pathname = '/';
    try {
      pathname = (new URL(window.location.href).pathname || '/').toLowerCase();
    } catch (e) {}
    if (document.readyState === 'complete' && elapsed >= 1500) {
      if (pathname === '/' || pathname.includes('/404') || pathname.includes('/not-found') || pathname.includes('/error')) {
        return 'Link sản phẩm đã chuyển hướng khỏi trang sản phẩm Shopee (' + pathname + ').';
      }
    }

    const bodyText = cleanText(document.body ? document.body.innerText : '').toLowerCase();
    if (!bodyText || bodyText.length < 15 || elapsed < 1200) {
      return '';
    }

    const notFoundMarkers = [
      'sản phẩm này không tồn tại',
      'sản phẩm không tồn tại',
      'không tìm thấy sản phẩm',
      'sản phẩm đã bị xóa',
      'sản phẩm không khả dụng',
      'sản phẩm hiện không khả dụng',
      'sản phẩm này đã bị gỡ',
      'sản phẩm này hiện không có sẵn',
      'rất tiếc, sản phẩm',
      'trang không tồn tại',
      'không tìm thấy trang',
      "product doesn't exist",
      "this product doesn't exist",
      "item doesn't exist",
      'product not found',
      'item not found',
      'page not found',
      'this item is no longer available',
      'product is unavailable'
    ];
    for (const marker of notFoundMarkers) {
      if (bodyText.includes(marker)) {
        return 'Sản phẩm không tồn tại hoặc đã bị gỡ/ẩn trên Shopee.';
      }
    }

    if (
      elapsed >= 3000 &&
      document.readyState === 'complete' &&
      !document.querySelector('h1') &&
      !document.querySelector('a[href*="-cat."]') &&
      (bodyText.includes('quay lại trang chủ') || bodyText.includes('về trang chủ') || bodyText.includes('trở lại shopee'))
    ) {
      return 'Sản phẩm không tồn tại hoặc đang bị ẩn trên Shopee.';
    }
    return '';
  }

  function buildPayload(strategy, tiers) {
    const cleanTiers = (tiers || []).filter((t) => t && cleanText(t.name));
    if (cleanTiers.length < 2) {
      return null;
    }
    const tier1 = cleanText(cleanTiers[0]?.name || '');
    const tier2 = cleanText(cleanTiers[1]?.name || '');
    const tier3 = cleanText(cleanTiers[2]?.name || '');
    if (!tier2 || ['shopee', 'trang chủ', 'home'].includes(tier2.toLowerCase())) {
      return null;
    }
    return {
      ok: true,
      status: 'ok',
      strategy,
      tier1,
      tier2,
      tier3: tier3 && tier3.toLowerCase() !== tier2.toLowerCase() ? tier3 : '',
      allTiers: cleanTiers,
      url: window.location.href
    };
  }

  // Strategy A: -cat.<id1>.<id2>.<id3> links in breadcrumb / top product region
  function tryStrategyCatUrl() {
    const links = Array.from(document.querySelectorAll('a[href*="-cat."]'));
    if (!links.length) return null;

    const h1 = document.querySelector('h1');
    const h1Top = h1 ? h1.getBoundingClientRect().top : 600;
    const maxTop = Math.max(450, h1Top + 120);

    const inBreadcrumb = [];
    const nearTop = [];
    for (const a of links) {
      const txt = cleanText(a.textContent);
      if (!txt) continue;
      const r = a.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) continue;
      if (a.closest('nav, [role="navigation"], [class*="breadcrumb" i], [aria-label*="breadcrumb" i], .shopee-breadcrumb')) {
        inBreadcrumb.push(a);
      } else if (r.top >= 0 && r.top <= maxTop) {
        nearTop.push(a);
      }
    }

    const visibleAll = links.filter((a) => cleanText(a.textContent) && isElementVisible(a));
    const candidates = inBreadcrumb.length >= 2 ? inBreadcrumb : (nearTop.length >= 2 ? nearTop : (visibleAll.length >= 2 ? visibleAll : [...inBreadcrumb, ...nearTop]));
    if (!candidates.length) return null;

    const byPath = new Map();
    for (const a of candidates) {
      const href = String(a.getAttribute('href') || a.href || '');
      const m = href.match(/-cat\.(\d+(?:\.\d+)*)/i);
      if (!m) continue;
      const catIdPath = m[1];
      const name = cleanText(a.textContent);
      if (!name || ['shopee', 'trang chủ', 'home'].includes(name.toLowerCase())) continue;
      const level = catIdPath.split('.').length;
      const existing = byPath.get(catIdPath);
      if (!existing || name.length < existing.name.length) {
        byPath.set(catIdPath, { level, name, catIdPath, href: a.href || href });
      }
    }

    const sorted = Array.from(byPath.values()).sort((x, y) => x.level - y.level);
    if (sorted.length < 2) return null;

    const byLevel = new Map();
    for (const item of sorted) {
      if (!byLevel.has(item.level)) {
        byLevel.set(item.level, item);
      }
    }

    const ordered = [];
    if (byLevel.has(1) && byLevel.has(2)) {
      ordered.push(byLevel.get(1), byLevel.get(2));
      if (byLevel.has(3)) ordered.push(byLevel.get(3));
      if (byLevel.has(4)) ordered.push(byLevel.get(4));
      return buildPayload('cat_url', ordered);
    }

    return buildPayload('cat_url', sorted);
  }

  // Strategy B: JSON-LD BreadcrumbList
  function tryStrategyJsonLd() {
    const scripts = Array.from(document.querySelectorAll('script[type="application/ld+json"]'));
    if (!scripts.length) return null;
    const h1Text = cleanText(document.querySelector('h1')?.textContent || '').toLowerCase();

    const extractNodes = (obj, acc) => {
      if (!obj || typeof obj !== 'object') return;
      if (Array.isArray(obj)) {
        obj.forEach((x) => extractNodes(x, acc));
        return;
      }
      if (obj['@type'] === 'BreadcrumbList' && Array.isArray(obj.itemListElement)) {
        acc.push(obj.itemListElement);
      }
      if (Array.isArray(obj['@graph'])) {
        extractNodes(obj['@graph'], acc);
      }
    };

    const lists = [];
    for (const s of scripts) {
      try {
        const parsed = JSON.parse(s.textContent || '{}');
        extractNodes(parsed, lists);
      } catch (e) {}
    }

    for (const list of lists) {
      const sorted = [...list].sort((a, b) => Number(a.position || 0) - Number(b.position || 0));
      let items = sorted.map((it) => {
        const itemObj = it && typeof it.item === 'object' ? it.item : null;
        const name = cleanText(it?.name || itemObj?.name || '');
        const href = String((typeof it?.item === 'string' ? it.item : itemObj?.['@id']) || '');
        const m = href.match(/-cat\.(\d+(?:\.\d+)*)/i);
        return { name, href, catIdPath: m ? m[1] : '' };
      }).filter((x) => x.name);

      while (items.length && ['shopee', 'trang chủ', 'home'].includes(items[0].name.toLowerCase())) {
        items.shift();
      }
      if (items.length) {
        const last = items[items.length - 1];
        if (!last.catIdPath && (items.length > 3 || (h1Text && last.name.toLowerCase() === h1Text))) {
          items.pop();
        }
      }
      if (items.length >= 2) {
        const tiers = items.map((it, idx) => ({
          level: it.catIdPath ? it.catIdPath.split('.').length : idx + 1,
          name: it.name,
          catIdPath: it.catIdPath,
          href: it.href
        }));
        const res = buildPayload('jsonld', tiers);
        if (res) return res;
      }
    }
    return null;
  }

  // Strategy C: DOM Breadcrumb container starting with "Shopee" anchor
  function tryStrategyDomBreadcrumb() {
    const anchors = Array.from(document.querySelectorAll('a'));
    const h1Text = cleanText(document.querySelector('h1')?.textContent || '').toLowerCase();
    for (const rootAnchor of anchors) {
      const txt = cleanText(rootAnchor.textContent).toLowerCase();
      if (txt !== 'shopee' && txt !== 'trang chủ') continue;
      let p = '/';
      try {
        p = new URL(rootAnchor.href, window.location.origin).pathname || '/';
      } catch (e) {}
      if (p !== '/') continue;

      let container = rootAnchor.parentElement;
      for (let hop = 0; hop < 3 && container; hop++) {
        const childLinks = Array.from(container.querySelectorAll('a')).filter((a) => isElementVisible(a));
        if (childLinks.length >= 3 && childLinks.length <= 10) {
          const tiers = [];
          for (const a of childLinks) {
            if (a === rootAnchor) continue;
            const label = cleanText(a.textContent);
            const href = String(a.getAttribute('href') || a.href || '');
            if (!label || ['shopee', 'trang chủ', 'home'].includes(label.toLowerCase())) continue;
            if (href.includes('/shop/') || href.includes('/search')) continue;
            if (h1Text && label.toLowerCase() === h1Text) continue;
            const m = href.match(/-cat\.(\d+(?:\.\d+)*)/i);
            tiers.push({
              level: m ? m[1].split('.').length : tiers.length + 1,
              name: label,
              catIdPath: m ? m[1] : '',
              href: a.href || href
            });
          }
          const res = buildPayload('dom_breadcrumb', tiers);
          if (res) return res;
        }
        container = container.parentElement;
      }
    }
    return null;
  }

  function evaluateOnce() {
    const gate = detectGate();
    if (gate) {
      return { ok: false, status: 'captcha_or_login', reason: gate, url: window.location.href };
    }

    const catRes = tryStrategyCatUrl() || tryStrategyJsonLd() || tryStrategyDomBreadcrumb();
    if (catRes) {
      return catRes;
    }

    const nf = detectNotFound();
    if (nf) {
      return { ok: false, status: 'not_found', reason: nf, url: window.location.href };
    }
    return null;
  }

  return new Promise((resolve) => {
    const initial = evaluateOnce();
    if (initial) {
      resolve(initial);
      return;
    }

    let settled = false;
    let observer = null;
    let pollId = null;
    let timeoutId = null;

    const finish = (result) => {
      if (settled) return;
      settled = true;
      if (observer) {
        try { observer.disconnect(); } catch (e) {}
      }
      if (pollId) clearInterval(pollId);
      if (timeoutId) clearTimeout(timeoutId);
      resolve(result);
    };

    const tick = () => {
      const r = evaluateOnce();
      if (r) finish(r);
    };

    try {
      observer = new MutationObserver(tick);
      if (document.documentElement) {
        observer.observe(document.documentElement, { childList: true, subtree: true });
      }
    } catch (e) {}

    pollId = setInterval(tick, POLL_MS);
    timeoutId = setTimeout(() => {
      const lastTry = evaluateOnce();
      if (lastTry) {
        finish(lastTry);
      } else {
        finish({
          ok: false,
          status: 'timeout',
          reason: 'Quá thời gian chờ trang Shopee hiển thị danh mục (18s).',
          url: window.location.href
        });
      }
    }, MAX_WAIT_MS);
  });
})();
