# Bàn giao dự án NEXXI cho Google Antigravity

## Mục tiêu

Hoàn thiện website bán quần áo trẻ em NEXXI bằng WordPress và WooCommerce, giữ giao diện bám sát tuyệt đối thiết kế Stitch đã cung cấp.

## Thư mục dự án

Mở toàn bộ thư mục này trong Antigravity:

```text
D:\Code web\Nexxi
```

Môi trường preview Docker đang dùng:

```powershell
docker compose -f docker-compose.preview.yml up -d
```

Website chạy tại `http://localhost:8088/`.

## Nguồn thiết kế Stitch

- Project: **Vietnamese WooCommerce Theme Design**
- Project ID: `12463860735441122443`
- Trang chủ tham chiếu: `5bddf742d10b46a4be3dff543b78075d`
- Ảnh tham chiếu toàn trang đã tải: `stitch-export/5bddf742d10b46a4be3dff543b78075d-reference-full.png`
- Tài nguyên Stitch cục bộ: `wp-content/themes/nexxi-kids/assets/stitch/`

Phải sử dụng thiết kế Stitch làm nguồn chuẩn. Không tự thay đổi bố cục, màu sắc, kiểu chữ, khoảng cách hoặc thành phần khi chưa có yêu cầu mới.

## Yêu cầu giao diện bắt buộc

### Header

Header phải giống đúng mẫu người dùng đã cung cấp:

1. Thanh thông báo nền hồng nhạt ở đầu trang, chạy ngang một dòng.
2. Thanh chính nền trắng gồm logo NEXXI, nhãn `KIDS FASHION`, menu trung tâm, ô tìm kiếm, yêu thích, giỏ hàng và tài khoản.
3. Menu hiển thị một hàng: `Trang Chủ`, `Giới Thiệu`, `Sản Phẩm`, huy hiệu `Bé Yêu`, `Flash Sale`, huy hiệu `Hot`, `Bảng Size`, `Liên Hệ`.
4. Không được xuống dòng các nhãn menu ở màn hình desktop. Ở độ rộng 1433px, header vẫn phải là một hàng và có chiều cao như mẫu.
5. Header này phải dùng thống nhất trên trang chủ, cửa hàng, sản phẩm, flash sale, giỏ hàng, thanh toán, tài khoản, đăng nhập và thành viên.
6. Không thay header bằng menu hamburger trên desktop.

Mã liên quan:

- `wp-content/themes/nexxi-kids/inc/stitch.php`
- `wp-content/themes/nexxi-kids/assets/stitch/woocommerce-stitch.css`

Lưu ý: nhãn menu được thay thành non-breaking spaces trong `nexxi_kids_stitch_lock_header_labels()` để ngăn xuống dòng. Giữ nguyên hành vi này khi chỉnh sửa header.

### Trang chủ

- Theo sát screen Stitch tham chiếu.
- Có hero cho thời trang trẻ em, CTA, ảnh trẻ em, sản phẩm nổi bật, flash sale, giới thiệu chất liệu và bảng size.
- Phần bảng size dùng bảng tham khảo từ người dùng: size 1–16, cân nặng 8–55kg, chiều cao 75–170cm.
- Không thay card hoặc bố cục chính bằng giao diện WooCommerce mặc định.

### WooCommerce

- Theme: `wp-content/themes/nexxi-kids/`
- Plugin: `wp-content/plugins/nexxi-kids-commerce/`
- Các route WordPress phải hiển thị bằng giao diện Stitch thay vì template WooCommerce mặc định.
- Giỏ hàng, checkout, trang tài khoản và thêm vào giỏ phải hoạt động.
- Các trang chính gồm `/`, `/cua-hang/`, trang sản phẩm, `/gio-hang/`, `/thanh-toan/`, `/tai-khoan/`.

## Trạng thái triển khai hiện có

- Docker preview đang có WordPress + WooCommerce (`http://localhost:8088`, PHP 8.3, WC 11.2).
- Theme và plugin đã được tạo, đồng thời có các gói cài đặt:
  - `nexxi-kids-theme.zip`
  - `nexxi-kids-commerce-plugin.zip`
- Header chung được lấy từ thiết kế trang chủ rồi dùng cho các màn hình khác.
- CSS header có quy tắc compact cho desktop 1024–1600px.

### Kiến trúc theme (`wp-content/themes/nexxi-kids/inc/`)

HTML Stitch gốc nằm ở `assets/stitch/pages/`. Script `tools/add-stitch-hooks.ps1` gắn thuộc tính `data-nx="..."` vào các khối cần thay dữ liệu, và PHP thay các khối này qua DOM. Code không dùng regex hay so khớp văn bản mẫu.

| File | Vai trò |
| --- | --- |
| `stitch-dom.php` | Helper DOM: `nexxi_dom_locate`, `nexxi_dom_get_outer`, `nexxi_dom_remove`… |
| `stitch-data.php` | Truy vấn WooCommerce: sản phẩm mới, bán chạy, đang sale (`nexxi_kids_sale_parent_ids`) |
| `stitch-components.php` | Thẻ sản phẩm (shop, flash). Chỉ hiện sao khi có đánh giá thật. |
| `stitch-home.php` / `stitch-shop.php` / `stitch-product.php` | Trang chủ, cửa hàng (lọc size/chất liệu/giá/sale), trang sản phẩm biến thể |
| `stitch-pages.php` | Trang nội dung Stitch (header/footer Stitch + nội dung WP editor), form liên hệ, trang yêu thích, REST |
| `stitch.php` | Header/footer chung, active menu, link footer, cấu hình JS `window.NexxiStitch` |
| `stitch-cart.php` | Giỏ hàng, checkout, tài khoản |

- JS: `assets/stitch/stitch-interactions.js` xử lý:
  - thêm giỏ hàng bằng AJAX;
  - chọn màu/size của biến thể;
  - wishlist (lưu localStorage, đồng bộ vào tài khoản khi đăng nhập);
  - bộ lọc, đếm ngược và tư vấn size.
- Customizer → **NEXXI – Thông tin cửa hàng**: hotline, email, địa chỉ, giờ làm việc và thời điểm kết thúc Flash Sale.
- Trang WordPress dùng các slug sau:

  | Slug | Trang |
  | --- | --- |
  | `gioi-thieu` | Giới thiệu |
  | `lien-he` | Liên hệ, gồm form, bản đồ và FAQ |
  | `yeu-thich` | Yêu thích |
  | `doi-tra` | Đổi trả, có neo `#bao-hanh` |
  | `van-chuyen` | Vận chuyển |
- REST:
  - `GET/POST nexxi/v1/wishlist`: wishlist của người dùng đăng nhập.
  - `GET nexxi/v1/cards?ids=`: HTML thẻ sản phẩm cho trang yêu thích.
- Form liên hệ dùng admin-post với action `nexxi_contact`. Form có nonce, honeypot và giới hạn 5 lần mỗi 10 phút theo IP, rồi gửi bằng `wp_mail`. Môi trường production cần cấu hình SMTP.

### NEXXI Studio – công cụ chỉnh sửa toàn site

Mở **Giao diện → Tùy biến**. Các panel được đánh số ① → ⑧ theo vị trí trên trang:

| Panel | Nội dung |
| --- | --- |
| ① Toàn trang | Màu, thông tin cửa hàng, các trang phụ |
| ② Header | Thông báo, logo, menu 8 mục, ô tìm kiếm |
| ③ Trang chủ | 9 khu vực |
| ④ Cửa hàng | Phần cửa hàng |
| ⑤ Sản phẩm | Phần trang sản phẩm |
| ⑥ Flash Sale | Phần Flash Sale |
| ⑦ Đăng nhập | Phần đăng nhập |
| ⑧ Footer | Footer dùng chung, mạng xã hội |

Mỗi khu vực là một section riêng, có ô "Hiển thị khu vực này" và các ô chữ, ảnh, link, biểu tượng. Phần xem trước giúp biết đang sửa ở đâu:
- Rê chuột lên trang sẽ hiện khung kèm nhãn "✎ Tên vùng". Bấm nhãn để mở đúng section.
- Khi mở một section, trang xem trước tự chuyển sang trang chứa vùng đó và đóng khung vùng.
- Khi bấm vào một ô nhập, phần tử tương ứng trên trang sẽ nhấp nháy.

Kỹ thuật:
- `tools/studio-annotate.php` (nằm trong theme) gắn các thuộc tính `data-nxr` (vùng), `data-nxe` (text node), `data-nxi` (ảnh), `data-nxl` (link) và `data-nxp` (placeholder) vào HTML Stitch, rồi sinh file `inc/studio-fields.php`. Bản sao lưu HTML trước khi gắn nằm ở `backups/stitch-pages-before-studio/`.
- Khi HTML Stitch thay đổi, chạy lại tool (tool idempotent, key giữ nguyên nếu cấu trúc không đổi):

  ```powershell
  docker run --rm -v "D:\Code web\Nexxi\wp-content\themes\nexxi-kids:/theme" -w /theme wordpress:cli-php8.3 php tools/studio-annotate.php
  ```

- `inc/studio.php` chứa registry vùng, field thủ công, và các bước áp giá trị:
  - `nexxi_studio_apply_content`: áp chữ, ảnh, placeholder trên shell gốc, chạy trước các module.
  - `nexxi_studio_apply_late`: áp link, ẩn vùng, mạng xã hội và màu (thay token Tailwind và hex trong `woocommerce-stitch.css`).
  - Header được dựng lại từ các ô menu. Footer của trang chủ được dùng chung cho mọi trang.
- `inc/studio-customizer.php` đăng ký giao diện chỉnh sửa. `assets/studio/` chứa JS xem trước và JS bảng điều khiển.
- Mọi giá trị lưu trong theme mod `nexxi_studio` (dạng mảng). Giá trị rỗng hoặc trùng giá trị gốc thì giữ nguyên chữ của Stitch.

### Dữ liệu mẫu cho preview

```powershell
docker compose -f docker-compose.preview.yml run --rm cli wp eval-file /preview-import/seed-preview.php
```

Script này tạo:
- thuộc tính màu/size;
- 8 sản phẩm biến thể (size × màu, có tồn kho riêng);
- đánh giá;
- các trang nội dung;
- thông tin cửa hàng mặc định.

Script cũng tắt chế độ *Coming soon* của WooCommerce. Nếu chế độ này bật, khách chưa đăng nhập sẽ thấy trang "sắp ra mắt" thay cho giao diện Stitch.

### Chưa làm

- Điều hướng mobile, gồm menu hamburger cho màn hình nhỏ.
- Form newsletter, voucher và "báo khi mở bán" của Flash Sale chưa có backend.
- Mục sắp xếp "Ưu đãi tốt nhất" đang dẫn sang trang Flash Sale.

## Cách kiểm tra trước khi thay đổi

1. Chạy Docker preview.
2. Mở `http://localhost:8088/` ở viewport 1920px và 1433px.
3. So sánh trực tiếp header với ảnh tham chiếu: menu phải một hàng, logo và nhãn bên trái, menu ở giữa, tìm kiếm và biểu tượng ở bên phải.
4. Kiểm tra `/cua-hang/`, một trang sản phẩm, `/gio-hang/`, `/thanh-toan/`, `/tai-khoan/` và các trang nội dung ở trên để bảo đảm header đồng nhất.
5. Sau khi thay PHP, kiểm tra cú pháp toàn bộ theme:

```powershell
docker compose -f docker-compose.preview.yml exec -T wordpress find /var/www/html/wp-content/themes/nexxi-kids -name "*.php" -exec php -l "{}" ";"
```

6. Đóng gói lại theme sau thay đổi. Zip cần có thư mục gốc `nexxi-kids/` và đường dẫn dùng dấu `/`. `Compress-Archive` của Windows PowerShell 5 ghi đường dẫn bằng `\`, nên hãy dùng đoạn sau:

```powershell
Add-Type -A System.IO.Compression, System.IO.Compression.FileSystem
$src = (Resolve-Path 'wp-content\themes\nexxi-kids').Path; $base = Split-Path $src -Parent; $zip = "$PWD\nexxi-kids-theme.zip"
if (Test-Path $zip) { Remove-Item $zip }
$z = [IO.Compression.ZipFile]::Open($zip, 'Create')
Get-ChildItem $src -Recurse -File | % { [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($z, $_.FullName, $_.FullName.Substring($base.Length + 1).Replace('\', '/'), 'Optimal') }
$z.Dispose()
```


## Quy tắc làm việc

- Giao tiếp và nội dung website bằng tiếng Việt.
- Ưu tiên thiết kế Stitch và ảnh tham chiếu trước mọi suy đoán thiết kế.
- Không xóa dữ liệu, Docker volume hoặc file ngoài `D:\Code web\Nexxi`.
- Chỉ sửa phần cần thiết, sau đó kiểm tra preview thực tế.
- Giữ toàn bộ nội dung, luồng WooCommerce và cấu trúc WordPress đang hoạt động.
