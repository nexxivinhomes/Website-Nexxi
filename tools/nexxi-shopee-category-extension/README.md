# NEXXI Shopee Category Sync Extension (Cốc Cốc / Chrome MV3)

Extension liên kết trực tiếp với trang quản trị **🥞 Pancake SKU** (`https://nexxi.vn/wp-admin/admin.php?page=nexxi-pancake-sync`) để tự động lấy **Danh mục Tầng 2 (Danh mục Cha)** và **Danh mục Tầng 3 (Danh mục Con)** từ link sản phẩm Shopee tương ứng với từng mã SKU trên Pancake POS.

## Cách cài đặt nhanh trên Cốc Cốc (1 lần duy nhất)

1. Mở trình duyệt **Cốc Cốc**, gõ lên thanh địa chỉ: `coccoc://extensions` rồi nhấn **Enter** *(hoặc `chrome://extensions` nếu dùng Chrome)*.
2. Bật công tắc **Chế độ dành cho nhà phát triển** (*Developer mode*) ở góc trên bên phải.
3. Nhấn nút **Tải tiện ích đã giải nén** (*Load unpacked*).
4. Chọn đúng thư mục:
   `D:\Code web\Nexxi\tools\nexxi-shopee-category-extension`
5. Tải lại (F5) trang **🥞 Pancake SKU** trên `https://nexxi.vn/wp-admin/admin.php?page=nexxi-pancake-sync` — góc trên bên phải sẽ hiện huy hiệu xanh **`🧩 Extension Cốc Cốc: Đã kết nối (v1.0.0)`**.

## Cơ chế hoạt động

1. Từ SKU trên Website / Pancake, hệ thống tra cứu link sản phẩm Shopee tương ứng trên Pancake POS (ưu tiên gian hàng chính `71134111`).
2. Extension tự động mở **tab mới** tới link sản phẩm Shopee ngay trên phiên Cốc Cốc đang dùng.
3. Đọc đường dẫn danh mục (**Tầng 2 → Cha**, **Tầng 3 → Con**).
4. **Đóng ngay tab Shopee** vừa mở và gán cặp Danh mục Cha/Con về cho sản phẩm trên `nexxi.vn`.
5. Nếu Shopee yêu cầu đăng nhập hoặc xác minh CAPTCHA, Extension tự **Tạm dừng** và chuyển sang tab đó để bạn xác minh thủ công, xong bấm **Tiếp tục** để chạy tiếp.
