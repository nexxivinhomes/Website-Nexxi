# Quy Tắc Bắt Buộc Cho Dự Án Website NEXXI (`D:\Code web\Nexxi`)

## 1. Quy Tắc Commit & Push Đa Nhánh Lên GitHub (`nexxivinhomes/Website-Nexxi`) — BẮT BUỘC TUYỆT ĐỐI

Website live `https://nexxi.vn` triển khai code qua **WP Pusher / Git Deployer** từ hai nhánh subtree độc lập là **`theme`** và **`plugin`**, **KHÔNG** deploy trực tiếp từ nhánh `main`:

1. **Ánh xạ 3 nhánh bắt buộc trên repo `Website-Nexxi`**:
   - **`main`** (`origin/main`): Chứa toàn bộ monorepo (`wp-content/themes/nexxi-kids/`, `wp-content/plugins/nexxi-kids-commerce/`, `wp-content/plugins/nexxi-pancake-manager/`, scripts và các gói `.zip`).
   - **`theme`** (`origin/theme`): Nhánh triển khai cho **Installed Themes** trên WordPress. Thư mục gốc `/` của nhánh `theme` chính là nội dung bên trong `wp-content/themes/nexxi-kids/` (`style.css`, `functions.php`, `inc/`, `assets/`... nằm ngay ở gốc nhánh, KHÔNG có tiền tố `wp-content/themes/nexxi-kids/`).
   - **`plugin`** (`origin/plugin`): Nhánh triển khai cho **Installed Plugins** trên WordPress. Thư mục gốc `/` của nhánh `plugin` chính là nội dung bên trong `wp-content/plugins/nexxi-kids-commerce/` (`nexxi-kids-commerce.php`, `includes/`, `assets/`... nằm ngay ở gốc nhánh, KHÔNG có tiền tố `wp-content/plugins/nexxi-kids-commerce/`).

2. **Quy trình bắt buộc mỗi khi yêu cầu commit / push thay đổi lên GitHub**:
   - **Bước 1 — Đóng gói lại file `.zip` tương ứng** (dùng `System.IO.Compression.ZipFile` với dấu `/` chuẩn Linux, không dùng `Compress-Archive` ghi dấu `\`):
     - Nếu sửa theme (`wp-content/themes/nexxi-kids/`): cập nhật `nexxi-kids-theme.zip` và `nexxi-kids.zip` (gốc bên trong zip là `nexxi-kids/...`).
     - Nếu sửa plugin (`wp-content/plugins/nexxi-kids-commerce/`): cập nhật `nexxi-kids-commerce-plugin.zip` và `nexxi-kids-commerce.zip` (gốc bên trong zip là `nexxi-kids-commerce/...`).
   - **Bước 2 — Commit vào nhánh `main`**:
     `git add` đầy đủ các file mã nguồn + file `.zip` vừa đóng gói và `git commit` trên nhánh `main`.
   - **Bước 3 — Đồng bộ & Push đồng thời sang nhánh `theme` / `plugin` bằng script chuẩn**:
     Chạy ngay lệnh sau để tự động push `origin/main` **VÀ** tách subtree đẩy thẳng lên `origin/theme` (khi theme thay đổi) và/hoặc `origin/plugin` (khi plugin thay đổi):
     ```powershell
     powershell -ExecutionPolicy Bypass -File "D:\Code web\Nexxi\tools\push-nexxi-branches.ps1"
     ```
   - **Cấm tuyệt đối**:
     - Không bao giờ chỉ push mỗi nhánh `main` rồi dừng lại khi có thay đổi trong `wp-content/themes/nexxi-kids/` hoặc `wp-content/plugins/nexxi-kids-commerce/`.
     - Không merge thẳng nhánh `main` vào `theme` hoặc `plugin` (sẽ làm sai cấu trúc thư mục gốc của WP Pusher); luôn dùng `tools\push-nexxi-branches.ps1` (`git commit-tree` theo subtree).

---

## 2. Quy Tắc Giao Diện Stitch & Hiệu Năng
- Mọi trang giao diện khách hàng (Trang chủ, Cửa hàng, Sản phẩm, Flash Sale, Giỏ hàng, Thanh toán, Tài khoản, Trang tĩnh, Danh mục bài viết `blog` và Chi tiết bài viết `single`) đều phải render thông qua `nexxi_kids_render_stitch()` để đồng bộ Header, Footer, NEXXI Studio và Mobile Drawer.
- Khi sửa CSS/HTML hiển thị trên frontend, nhớ tăng `$purge_ver` trong `wp-content/themes/nexxi-kids/inc/stitch.php` (và `Version` trong `style.css` / `NEXXI_KIDS_VERSION` khi cần) để LiteSpeed Cache tự động làm mới ngay sau khi Deploy Theme.
- Luôn kiểm tra cú pháp PHP (`php -l`) trước khi commit.
