# Nexxi Kids Fashion

Mã nguồn tùy chỉnh cho website WordPress hiện có tại `nexxi.vn`. Repository chỉ chứa classic theme `nexxi-theme` và plugin khung `nexxi-core`; WordPress, WooCommerce, dữ liệu và uploads được quản lý trên hosting.

## Cấu trúc

```text
wp-content/
  themes/nexxi-theme/
    style.css             Header nhận diện theme
    functions.php         Theme support và enqueue assets
    header.php            Header và menu mobile Alpine
    index.php             Template dự phòng chuẩn WordPress
    footer.php            Footer và wp_footer()
    src/css/app.css       Tailwind source và design tokens tạm thời
    src/js/app.js         Alpine source
    assets/css/app.css    CSS production đã build
    assets/js/app.js      JavaScript production đã build
    package.json          Công cụ build chỉ dùng khi phát triển
  plugins/nexxi-core/
    nexxi-core.php        Plugin skeleton, chưa có nghiệp vụ
```

## Yêu cầu phát triển

- Node.js phiên bản còn được hỗ trợ và npm. Lockfile được tạo bằng Node.js 24.19.0 và npm 11.9.0.
- WordPress và WooCommerce chỉ cần khi kiểm tra tích hợp thực tế. Chưa biết phiên bản PHP, WordPress và WooCommerce trên Mắt Bão; cần xác nhận trước khi triển khai.

## Cài và build

Trong thư mục `wp-content/themes/nexxi-theme`:

```sh
npm ci
npm run build
```

Để phát triển, chạy `npm run dev:css` và `npm run dev:js` ở hai terminal. Trước khi đóng gói production, chạy lại `npm run build`. Commit cả `package-lock.json` và hai file đã build trong `assets/`; không commit `node_modules`.

Tailwind CSS quét các file theme. Design tokens trong `src/css/app.css` hiện là giá trị khởi đầu, cần đối chiếu với tài liệu nhận diện thương hiệu chính thức. Alpine.js được đóng gói vào `assets/js/app.js`; frontend production không dùng CDN hay Node server.

## Cài trên WordPress

1. Chỉ đưa thư mục `wp-content/themes/nexxi-theme` vào `wp-content/themes/` trên hosting. Bỏ `node_modules`, `src`, `package.json`, `package-lock.json` và README khi đóng gói nếu chỉ triển khai runtime; giữ `style.css`, các PHP template và `assets/`.
2. Chỉ đưa thư mục `wp-content/plugins/nexxi-core` vào `wp-content/plugins/`.
3. Sao lưu và thử trên staging trước. Trong admin WordPress, kích hoạt theme **Nexxi Kids Fashion** và plugin **Nexxi Core**, sau đó gán menu tại vị trí **Primary Menu**.
4. Xác nhận WooCommerce đang hoạt động, mở trang cửa hàng, sản phẩm, giỏ hàng và thanh toán. Kiểm tra site đang dùng Cart/Checkout Blocks hay classic templates trước khi tùy chỉnh các trang đó ở nhiệm vụ sau.
5. Kiểm tra CSS, menu mobile, console trình duyệt, breakpoint, keyboard navigation và PHP error log. Xác nhận phiên bản PHP, WordPress, WooCommerce và plugin SEO trên staging.

Không triển khai toàn bộ repository vào WordPress root. Không chép WordPress Core, WooCommerce Core, cấu hình chứa secret, database, uploads hoặc `node_modules`. Việc triển khai qua cPanel, Git hay SFTP cần được quyết định theo khả năng gói hosting Mắt Bão; repository chưa cấu hình tự động triển khai.
