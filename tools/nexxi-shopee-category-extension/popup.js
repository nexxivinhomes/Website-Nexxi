(function () {
  const statusLine = document.getElementById('statusLine');
  const subLine = document.getElementById('subLine');
  const controlRow = document.getElementById('controlRow');
  const btnPause = document.getElementById('btnPause');
  const btnResume = document.getElementById('btnResume');
  const btnSkip = document.getElementById('btnSkip');
  const btnStop = document.getElementById('btnStop');
  const btnOpenAdmin = document.getElementById('btnOpenAdmin');

  function renderState(st) {
    if (!st || !st.running) {
      statusLine.textContent = '✅ Sẵn sàng kết nối';
      subLine.textContent = 'Mở trang quản trị Pancake SKU trên nexxi.vn để bắt đầu lấy danh mục Shopee.';
      controlRow.style.display = 'none';
      return;
    }

    controlRow.style.display = 'flex';
    if (st.paused) {
      statusLine.textContent = `⚠️ Đang tạm dừng (${st.index + 1}/${st.total})`;
      subLine.textContent = st.pauseReason || 'Vui lòng hoàn tất xác minh trên tab Shopee đang mở rồi bấm Tiếp tục.';
      btnPause.style.display = 'none';
      btnResume.style.display = 'inline-flex';
      btnSkip.style.display = 'inline-flex';
    } else {
      statusLine.textContent = `⏳ Đang quét danh mục (${st.index + 1}/${st.total})`;
      subLine.textContent = 'Đang mở tab Shopee lấy Tầng 2 & Tầng 3 và tự động đóng tab sau khi xong.';
      btnPause.style.display = 'inline-flex';
      btnResume.style.display = 'none';
      btnSkip.style.display = 'none';
    }
  }

  function refresh() {
    chrome.runtime.sendMessage({ type: 'NEXXI_POPUP_GET_STATE' }, (resp) => {
      if (chrome.runtime.lastError) {
        statusLine.textContent = '✅ Sẵn sàng kết nối';
        return;
      }
      renderState(resp && resp.state ? resp.state : null);
    });
  }

  function sendCmd(type) {
    chrome.runtime.sendMessage({ type }, (resp) => {
      if (resp && resp.state) renderState(resp.state);
      else refresh();
    });
  }

  btnPause.addEventListener('click', () => sendCmd('NEXXI_POPUP_PAUSE'));
  btnResume.addEventListener('click', () => sendCmd('NEXXI_POPUP_RESUME'));
  btnSkip.addEventListener('click', () => sendCmd('NEXXI_POPUP_SKIP'));
  btnStop.addEventListener('click', () => sendCmd('NEXXI_POPUP_STOP'));

  btnOpenAdmin.addEventListener('click', () => {
    chrome.tabs.create({ url: 'https://nexxi.vn/wp-admin/admin.php?page=nexxi-pancake-sync&tab=products' });
  });

  refresh();
  setInterval(refresh, 1500);
})();
