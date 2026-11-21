const state = {
  jobId: '',
  running: false,
  paused: false,
  pauseReason: '',
  stopped: false,
  items: [],
  index: 0,
  delayMs: 4000,
  openActiveTab: false,
  activeTabId: null,
  adminTabId: null,
  adminWindowId: null,
  results: [],
  port: null,
  pauseResolver: null,
  manualClosedTabs: new Set()
};

function getPublicState() {
  return {
    jobId: state.jobId,
    running: state.running,
    paused: state.paused,
    pauseReason: state.pauseReason,
    stopped: state.stopped,
    index: state.index,
    total: state.items.length,
    activeTabId: state.activeTabId
  };
}

function updateBadge() {
  try {
    if (!state.running) {
      chrome.action.setBadgeText({ text: '' });
      return;
    }
    if (state.paused) {
      chrome.action.setBadgeBackgroundColor({ color: '#d97706' });
      chrome.action.setBadgeText({ text: '!' });
      return;
    }
    chrome.action.setBadgeBackgroundColor({ color: '#1b2a59' });
    chrome.action.setBadgeText({ text: `${Math.min(state.index + 1, state.items.length)}/${state.items.length}` });
  } catch (e) {}
}

function emitToAdmin(msg) {
  if (!state.port) return;
  try {
    state.port.postMessage({ ...msg, state: getPublicState() });
  } catch (e) {}
}

async function safeCloseTab(tabId) {
  if (!tabId) return;
  if (state.activeTabId === tabId) {
    state.activeTabId = null;
  }
  try {
    await chrome.tabs.remove(tabId);
  } catch (e) {}
}

function sleep(ms) {
  return new Promise((resolve) => {
    const start = Date.now();
    const id = setInterval(() => {
      if (state.stopped || Date.now() - start >= ms) {
        clearInterval(id);
        resolve();
      }
    }, 250);
  });
}

function waitForTabComplete(tabId, maxWaitMs = 15000) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (status) => {
      if (done) return;
      done = true;
      chrome.tabs.onUpdated.removeListener(onUpdated);
      chrome.tabs.onRemoved.removeListener(onRemoved);
      clearTimeout(timer);
      resolve(status);
    };

    const onUpdated = (updatedTabId, info) => {
      if (updatedTabId === tabId && info.status === 'complete') {
        finish('complete');
      }
    };

    const onRemoved = (removedTabId) => {
      if (removedTabId === tabId) {
        state.manualClosedTabs.add(tabId);
        finish('removed');
      }
    };

    chrome.tabs.onUpdated.addListener(onUpdated);
    chrome.tabs.onRemoved.addListener(onRemoved);

    chrome.tabs.get(tabId).then((t) => {
      if (t && t.status === 'complete') {
        finish('complete');
      }
    }).catch(() => finish('removed'));

    const timer = setTimeout(() => finish('timeout'), maxWaitMs);
  });
}

async function runExtractorOnTab(tabId) {
  try {
    const execResults = await chrome.scripting.executeScript({
      target: { tabId },
      files: ['shopee-extractor.js']
    });
    if (Array.isArray(execResults) && execResults[0] && execResults[0].result) {
      return execResults[0].result;
    }
    return { ok: false, status: 'error', reason: 'Không nhận được dữ liệu từ tab Shopee.' };
  } catch (err) {
    return {
      ok: false,
      status: state.manualClosedTabs.has(tabId) ? 'tab_closed_by_user' : 'error',
      reason: err && err.message ? err.message : 'Lỗi đọc nội dung trang Shopee.'
    };
  }
}

function waitForPauseAction() {
  return new Promise((resolve) => {
    state.pauseResolver = resolve;
  });
}

function resolvePauseAction(action) {
  if (state.pauseResolver) {
    const fn = state.pauseResolver;
    state.pauseResolver = null;
    fn(action);
  }
}

function buildCandidateUrls(item) {
  const out = [];
  const seen = new Set();
  const pushEntry = (rawUrl, shopId = '', shopName = '', itemId = '') => {
    const u = String(rawUrl || '').trim();
    if (!u || !u.startsWith('http') || seen.has(u)) return;
    seen.add(u);
    out.push({
      url: u,
      shop_id: String(shopId || '').trim(),
      shop_name: String(shopName || '').trim(),
      item_id: String(itemId || '').trim()
    });
  };

  if (item && Array.isArray(item.candidate_urls)) {
    for (const c of item.candidate_urls) {
      if (!c) continue;
      if (typeof c === 'string') {
        pushEntry(c);
      } else if (typeof c === 'object') {
        pushEntry(c.url || c.shopee_url, c.shop_id, c.shop_name, c.item_id);
      }
    }
  }
  if (item && item.shopee_url) {
    pushEntry(item.shopee_url, item.shop_id, item.shop_name, item.item_id);
  }
  return out;
}

async function processBatch() {
  state.running = true;
  state.paused = false;
  state.pauseReason = '';
  state.stopped = false;
  state.results = [];
  updateBadge();

  try {
    for (let i = 0; i < state.items.length; i++) {
      if (state.stopped) break;
      state.index = i;
      updateBadge();

      const item = state.items[i];
      const candidates = buildCandidateUrls(item);
      let itemResult = null;

      for (let cIdx = 0; cIdx < candidates.length; cIdx++) {
        if (state.stopped) break;
        const cand = candidates[cIdx];

        emitToAdmin({
          type: 'NEXXI_EXT_ITEM_START',
          jobId: state.jobId,
          index: i,
          total: state.items.length,
          item,
          attempt: cIdx + 1,
          totalAttempts: candidates.length,
          activeUrl: cand.url,
          activeShopId: cand.shop_id || item.shop_id || '',
          activeShopName: cand.shop_name || item.shop_name || ''
        });

        let candidateResult = null;
        let retrySameCandidate = false;

        do {
          retrySameCandidate = false;
          if (state.stopped) break;

          // If user clicked manual Pause before this item started, wait before opening tab
          while (state.paused && !state.stopped) {
            updateBadge();
            const pAct = await waitForPauseAction();
            state.paused = false;
            state.pauseReason = '';
            if (pAct === 'stop' || state.stopped) {
              state.stopped = true;
              break;
            }
            if (pAct === 'skip') {
              candidateResult = { ok: false, status: 'skipped', reason: 'Người dùng đã bỏ qua sản phẩm này.' };
              break;
            }
          }
          if (state.stopped || candidateResult) break;

          let tab = null;
          try {
            const createOpts = {
              url: cand.url,
              active: Boolean(state.openActiveTab)
            };
            if (state.adminWindowId) {
              createOpts.windowId = state.adminWindowId;
            }
            tab = await chrome.tabs.create(createOpts);
          } catch (err) {
            candidateResult = { ok: false, status: 'error', reason: 'Không thể mở tab Shopee: ' + (err?.message || err) };
            break;
          }

          const tabId = tab.id;
          state.activeTabId = tabId;

          const loadStatus = await waitForTabComplete(tabId, 15000);
          if (state.stopped) {
            await safeCloseTab(tabId);
            break;
          }
          if (loadStatus === 'removed') {
            candidateResult = { ok: false, status: 'tab_closed_by_user', reason: 'Tab Shopee đã bị đóng trước khi lấy danh mục.' };
            state.activeTabId = null;
            break;
          }

          const extracted = await runExtractorOnTab(tabId);

          if (extracted && extracted.status === 'captcha_or_login') {
            state.paused = true;
            state.pauseReason = extracted.reason || 'Shopee yêu cầu đăng nhập hoặc xác minh CAPTCHA.';
            updateBadge();
            try {
              await chrome.tabs.update(tabId, { active: true });
            } catch (e) {}
            emitToAdmin({
              type: 'NEXXI_EXT_PAUSED',
              jobId: state.jobId,
              index: i,
              total: state.items.length,
              item,
              reason: state.pauseReason,
              tabId
            });

            const action = await waitForPauseAction();
            state.paused = false;
            state.pauseReason = '';
            updateBadge();

            if (action === 'stop' || state.stopped) {
              state.stopped = true;
              await safeCloseTab(tabId);
              break;
            }
            if (action === 'skip') {
              await safeCloseTab(tabId);
              candidateResult = { ok: false, status: 'skipped', reason: 'Người dùng bỏ qua sau cảnh báo xác minh Shopee.' };
              break;
            }

            // action === 'resume': check paused tab first, or retry cleanly
            let tabStillOpen = false;
            try {
              const curTab = await chrome.tabs.get(tabId);
              tabStillOpen = Boolean(curTab && curTab.id);
            } catch (e) {
              tabStillOpen = false;
            }

            if (tabStillOpen) {
              const retried = await runExtractorOnTab(tabId);
              await safeCloseTab(tabId);
              if (retried && retried.ok) {
                candidateResult = retried;
                break;
              }
              if (retried && retried.status === 'captcha_or_login') {
                retrySameCandidate = true;
                continue;
              }
              retrySameCandidate = true;
              continue;
            } else {
              state.activeTabId = null;
              retrySameCandidate = true;
              continue;
            }
          }

          // ALWAYS close opened Shopee tab immediately after extracting
          await safeCloseTab(tabId);
          candidateResult = extracted;
        } while (retrySameCandidate && !state.stopped);

        if (state.stopped) break;

        if (candidateResult && candidateResult.ok) {
          itemResult = {
            ...candidateResult,
            used_url: cand.url,
            used_shop_id: cand.shop_id || '',
            used_shop_name: cand.shop_name || '',
            used_item_id: cand.item_id || '',
            attempt: cIdx + 1,
            totalAttempts: candidates.length
          };
          break;
        }

        if (candidateResult && (candidateResult.status === 'skipped' || candidateResult.status === 'tab_closed_by_user')) {
          itemResult = candidateResult;
          break;
        }

        const canTryNext = cIdx < candidates.length - 1 && candidateResult && ['not_found', 'timeout', 'error'].includes(candidateResult.status);
        if (canTryNext) {
          await sleep(800);
          continue;
        }

        const baseReason = (candidateResult && candidateResult.reason) ? String(candidateResult.reason) : 'Không lấy được danh mục Shopee.';
        const suffix = candidates.length > 1 ? ` (đã thử ${candidates.length}/${candidates.length} link Shopee trên Pancake)` : '';
        itemResult = {
          ...(candidateResult || { ok: false, status: 'error' }),
          reason: baseReason + suffix,
          used_url: cand.url,
          used_shop_id: cand.shop_id || '',
          used_shop_name: cand.shop_name || '',
          used_item_id: cand.item_id || '',
          attempt: cIdx + 1,
          totalAttempts: candidates.length
        };
      }

      if (state.stopped) break;

      if (!itemResult) {
        itemResult = { ok: false, status: 'error', reason: 'Không thu được kết quả danh mục.' };
      }
      state.results.push({ item, result: itemResult });

      emitToAdmin({
        type: 'NEXXI_EXT_ITEM_RESULT',
        jobId: state.jobId,
        index: i,
        total: state.items.length,
        item,
        result: itemResult
      });

      if (state.items.length > 1 && i < state.items.length - 1 && !state.stopped) {
        await sleep(Math.max(1000, Number(state.delayMs) || 4000));
      }
    }
  } finally {
    if (state.activeTabId) {
      await safeCloseTab(state.activeTabId);
    }
    const wasStopped = state.stopped;
    const jobId = state.jobId;
    const total = state.items.length;
    const completed = state.results.length;
    state.running = false;
    state.paused = false;
    state.pauseReason = '';
    state.stopped = false;
    state.items = [];
    state.index = 0;
    updateBadge();

    emitToAdmin({
      type: 'NEXXI_EXT_BATCH_DONE',
      jobId,
      stopped: wasStopped,
      total,
      completed
    });
  }
}

async function handleControlCommand(cmd, extra = {}) {
  if (cmd === 'PAUSE') {
    if (state.running && !state.paused) {
      state.paused = true;
      state.pauseReason = extra.reason || 'Đã tạm dừng theo yêu cầu người dùng.';
      updateBadge();
      emitToAdmin({
        type: 'NEXXI_EXT_PAUSED',
        jobId: state.jobId,
        index: state.index,
        total: state.items.length,
        item: state.items[state.index] || null,
        reason: state.pauseReason
      });
    }
    return getPublicState();
  }

  if (cmd === 'RESUME') {
    if (state.paused) {
      resolvePauseAction('resume');
    }
    return getPublicState();
  }

  if (cmd === 'SKIP') {
    if (state.paused) {
      resolvePauseAction('skip');
    }
    return getPublicState();
  }

  if (cmd === 'STOP') {
    state.stopped = true;
    if (state.paused) {
      resolvePauseAction('stop');
    }
    if (state.activeTabId) {
      await safeCloseTab(state.activeTabId);
    }
    updateBadge();
    return getPublicState();
  }
  return getPublicState();
}

chrome.runtime.onConnect.addListener((port) => {
  if (port.name !== 'nexxi-admin-bridge') return;

  state.port = port;
  if (port.sender && port.sender.tab) {
    state.adminTabId = port.sender.tab.id;
    state.adminWindowId = port.sender.tab.windowId;
  }

  port.onMessage.addListener(async (msg) => {
    if (!msg || typeof msg !== 'object') return;
    if (msg.type === 'PING' || msg.type === 'KEEPALIVE') {
      try {
        port.postMessage({ type: 'NEXXI_EXT_PONG', state: getPublicState() });
      } catch (e) {}
      return;
    }

    if (msg.type === 'START_BATCH') {
      if (state.running) {
        emitToAdmin({
          type: 'NEXXI_EXT_ERROR',
          jobId: msg.jobId || '',
          reason: 'Extension đang chạy một lượt quét khác. Vui lòng chờ hoàn tất hoặc bấm Dừng.'
        });
        return;
      }
      const rawItems = Array.isArray(msg.items) ? msg.items : [];
      const validItems = rawItems.filter((it) => buildCandidateUrls(it).length > 0);
      if (!validItems.length) {
        emitToAdmin({
          type: 'NEXXI_EXT_ERROR',
          jobId: msg.jobId || '',
          reason: 'Không có link Shopee hợp lệ để mở.'
        });
        return;
      }
      state.jobId = String(msg.jobId || ('job_' + Date.now()));
      state.items = validItems;
      state.index = 0;
      state.delayMs = Number(msg.delayMs) >= 0 ? Number(msg.delayMs) : 4000;
      state.openActiveTab = Boolean(msg.openActiveTab);
      if (port.sender && port.sender.tab) {
        state.adminTabId = port.sender.tab.id;
        state.adminWindowId = port.sender.tab.windowId;
      }
      processBatch();
      return;
    }

    if (msg.type === 'PAUSE') await handleControlCommand('PAUSE', msg);
    if (msg.type === 'RESUME') await handleControlCommand('RESUME');
    if (msg.type === 'SKIP') await handleControlCommand('SKIP');
    if (msg.type === 'STOP') await handleControlCommand('STOP');
  });

  port.onDisconnect.addListener(async () => {
    if (state.port === port) {
      state.port = null;
      if (state.running) {
        await handleControlCommand('STOP');
      }
    }
  });
});

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || typeof msg !== 'object') return;
  if (msg.type === 'NEXXI_POPUP_GET_STATE') {
    sendResponse({ ok: true, state: getPublicState() });
    return true;
  }
  if (['NEXXI_POPUP_PAUSE', 'NEXXI_POPUP_RESUME', 'NEXXI_POPUP_SKIP', 'NEXXI_POPUP_STOP'].includes(msg.type)) {
    const cmd = msg.type.replace('NEXXI_POPUP_', '');
    handleControlCommand(cmd).then((st) => sendResponse({ ok: true, state: st }));
    return true;
  }
});
