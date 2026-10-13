# Cài theme và plugin NEXXI Kids

## Thành phần

- Theme: `nexxi-kids-theme.zip` hoặc `wp-content/themes/nexxi-kids/`
- Plugin: `nexxi-kids-commerce-plugin.zip` hoặc `wp-content/plugins/nexxi-kids-commerce/`
- WooCommerce cần được cài và kích hoạt.

## Cài đặt

1. Tải lên và kích hoạt theme NEXXI Kids tại **Giao diện → Giao diện**.
2. Cài WooCommerce, sau đó tải lên và kích hoạt plugin NEXXI Kids Commerce.
3. Tạo và gán các trang Cửa hàng, Giỏ hàng, Thanh toán, Tài khoản trong **WooCommerce → Cài đặt → Nâng cao**.
4. Thêm danh mục, sản phẩm, ảnh, giá và tồn kho trong WooCommerce. Các màn hình Stitch kết nối với dữ liệu này cho danh sách sản phẩm, trang sản phẩm, giỏ hàng và thanh toán.
5. Đặt trang chủ tĩnh tại **Cài đặt → Đọc** nếu WordPress chưa hiển thị trang chủ theme.
6. Cấu hình phương thức và mức phí giao hàng thật trong **WooCommerce → Cài đặt → Giao hàng**. Giao diện đang trình bày ngưỡng miễn phí 299.000₫; thông báo này không tự tạo quy tắc giao hàng.
7. Đặt thời điểm kết thúc Flash Sale tại **Giao diện → Tùy biến → Thông tin nhận dạng website**, dạng ISO 8601 có múi giờ, ví dụ `2026-10-15T21:00:00+07:00`.

## Bảng size

Shortcode `[nexxi_size_guide]` hiển thị bộ ước lượng và đủ 16 size theo ảnh tham khảo: cân nặng 8–55 kg, chiều cao 75–170 cm. Trang sản phẩm tích hợp bảng này. Kết quả chỉ để tham khảo; khi chiều cao và cân nặng cho hai size khác nhau, công cụ chọn size lớn hơn.

## Nội dung cần thay trước khi xuất bản

HTML Stitch gốc chứa hình gallery, lời nhận xét, số lượng bán, voucher, hotline và một số thông điệp quảng cáo mang tính minh họa. Hãy thay bằng dữ liệu và chính sách đã xác minh của cửa hàng. Nút đăng nhập mạng xã hội cần tích hợp dịch vụ đăng nhập riêng. Font chữ trong bản Stitch tải từ Google Fonts, nên cần kết nối Internet khi xem giao diện với đúng font.

## Preview Docker

Xem [DOCKER-PREVIEW.md](DOCKER-PREVIEW.md) để chạy cửa hàng mẫu tại `http://localhost:8088`.
