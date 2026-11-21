(function () {
  const EXT_VERSION = '1.0.0';
  document.documentElement.dataset.nexxiShopeeCatExt = EXT_VERSION;

  let port = null;
  let keepaliveTimer = null;

  function postToPage(payload) {
    window.postMessage(
      {
        source: 'nexxi-ext',
        version: EXT_VERSION,
        ...payload
      },
      window.location.origin
    );
  }

  function stopKeepalive() {
    if (keepaliveTimer) {
      clearInterval(keepaliveTimer);
      keepaliveTimer = null;
    }
  }

  function startKeepalive() {
    stopKeepalive();
    keepaliveTimer = setInterval(() => {
      if (port) {
        try {
          port.postMessage({ type: 'KEEPALIVE' });
        } catch (e) {
          stopKeepalive();
        }
      }
    }, 20000);
  }

  function ensurePort() {
    if (port) return port;
    port = chrome.runtime.connect({ name: 'nexxi-admin-bridge' });
    port.onMessage.addListener((msg) => {
      if (!msg || typeof msg !== 'object') return;
      if (msg.type === 'NEXXI_EXT_BATCH_DONE' || msg.type === 'NEXXI_EXT_ERROR') {
        stopKeepalive();
      }
      postToPage(msg);
    });
    port.onDisconnect.addListener(() => {
      port = null;
      stopKeepalive();
    });
    return port;
  }

  // Initial handshake announcement on admin page load
  postToPage({ type: 'NEXXI_EXT_READY' });

  window.addEventListener('message', (event) => {
    if (event.source !== window) return;
    const data = event.data;
    if (!data || typeof data !== 'object' || data.source !== 'nexxi-page') return;

    if (data.type === 'NEXXI_PAGE_PING') {
      let currentState = null;
      try {
        chrome.runtime.sendMessage({ type: 'NEXXI_POPUP_GET_STATE' }, (resp) => {
          if (chrome.runtime.lastError) {
            postToPage({ type: 'NEXXI_EXT_READY' });
            return;
          }
          currentState = resp && resp.state ? resp.state : null;
          postToPage({ type: 'NEXXI_EXT_READY', state: currentState });
        });
      } catch (e) {
        postToPage({ type: 'NEXXI_EXT_READY' });
      }
      return;
    }

    if (data.type === 'NEXXI_PAGE_START_BATCH') {
      const p = ensurePort();
      startKeepalive();
      p.postMessage({
        type: 'START_BATCH',
        jobId: data.jobId || '',
        items: Array.isArray(data.items) ? data.items : [],
        delayMs: data.delayMs,
        openActiveTab: Boolean(data.openActiveTab)
      });
      return;
    }

    if (['NEXXI_PAGE_PAUSE', 'NEXXI_PAGE_RESUME', 'NEXXI_PAGE_SKIP', 'NEXXI_PAGE_STOP'].includes(data.type)) {
      const cmd = data.type.replace('NEXXI_PAGE_', '');
      const p = ensurePort();
      p.postMessage({ type: cmd });
    }
  });
})();
