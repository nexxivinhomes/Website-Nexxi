(() => {
  const cartKey = 'nexxi-cart-count';
  const readCount = () => Number(localStorage.getItem(cartKey) || 0);
  const paintCount = () => {
    const count = readCount();
    document.querySelectorAll('a[href="cart.html"], a[href="checkout.html"]').forEach(link => {
      const badges = link.querySelectorAll('span');
      badges.forEach(badge => {
        if (/^\d+$/.test(badge.textContent.trim()) && badge.className.includes('rounded-full')) badge.textContent = String(count);
      });
    });
  };
  const toast = message => {
    let node = document.getElementById('nexxi-toast');
    if (!node) {
      node = document.createElement('div'); node.id = 'nexxi-toast';
      Object.assign(node.style, {position:'fixed', zIndex:9999, bottom:'24px', left:'50%', transform:'translateX(-50%)', background:'#1b2a59', color:'#fff', padding:'12px 20px', borderRadius:'999px', boxShadow:'0 8px 24px #1b2a5933', font:'600 14px Inter, sans-serif'});
      document.body.append(node);
    }
    node.textContent = message; node.hidden = false; clearTimeout(node.timer); node.timer = setTimeout(() => node.hidden = true, 2400);
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('button'); if (!button) return;
    const label = button.innerText.trim().toLowerCase();
    if (label.includes('thêm vào giỏ') || label.includes('thêm vào túi')) {
      localStorage.setItem(cartKey, String(readCount() + 1)); paintCount(); toast('Đã thêm sản phẩm vào giỏ hàng');
    } else if (label.includes('mua ngay')) {
      localStorage.setItem(cartKey, String(readCount() + 1)); location.href = 'checkout.html';
    }
  });
  document.addEventListener('submit', event => { event.preventDefault(); toast('Thông tin đã được ghi nhận'); });
  paintCount();
})();