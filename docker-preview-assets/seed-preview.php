<?php
/** Populate the disposable Docker preview with local demo catalog content. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) { exit( 1 ); }

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

function nexxi_preview_page( $slug, $title, $content ) {
	$page = get_page_by_path( $slug );
	if ( $page ) { return $page->ID; }
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	return $id;
}

function nexxi_preview_image( $index, $title ) {
	$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'meta_key' => '_nexxi_preview_image', 'meta_value' => (string) $index ) );
	if ( $existing ) { return $existing[0]->ID; }
	$source = '/preview-import/stitch-photo-' . $index . '.jpg';
	if ( ! is_readable( $source ) ) { throw new RuntimeException( 'Missing preview image: ' . $source ); }
	$upload = wp_upload_bits( 'nexxi-preview-' . $index . '.jpg', null, file_get_contents( $source ) );
	if ( ! empty( $upload['error'] ) ) { throw new RuntimeException( $upload['error'] ); }
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $title, 'post_status' => 'inherit' ), $upload['file'] );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	update_post_meta( $id, '_nexxi_preview_image', (string) $index );
	return $id;
}

$home_id = nexxi_preview_page( 'trang-chu', 'Trang chủ', '' );
$shop_id = nexxi_preview_page( 'cua-hang', 'Cửa hàng', '' );
$cart_id = nexxi_preview_page( 'gio-hang', 'Giỏ hàng', '[woocommerce_cart]' );
$checkout_id = nexxi_preview_page( 'thanh-toan', 'Thanh toán', '[woocommerce_checkout]' );
$account_id = nexxi_preview_page( 'tai-khoan', 'Tài khoản', '[woocommerce_my_account]' );
nexxi_preview_page( 'doi-tra', 'Chính sách đổi trả', 'Nội dung chính sách đổi trả đang được cập nhật cho bản xem trước.' );
nexxi_preview_page( 'van-chuyen', 'Chính sách giao hàng', 'Nội dung chính sách giao hàng đang được cập nhật cho bản xem trước.' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
update_option( 'woocommerce_shop_page_id', $shop_id );
update_option( 'woocommerce_cart_page_id', $cart_id );
update_option( 'woocommerce_checkout_page_id', $checkout_id );
update_option( 'woocommerce_myaccount_page_id', $account_id );
update_option( 'woocommerce_currency', 'VND' );
update_option( 'woocommerce_default_country', 'VN' );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_specific_allowed_countries', array( 'VN' ) );
update_option( 'woocommerce_default_customer_address', 'base' );
update_option( 'woocommerce_price_num_decimals', '0' );
update_option( 'woocommerce_price_thousand_sep', '.' );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_currency_pos', 'right_space' );
update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
update_option( 'woocommerce_registration_generate_username', 'yes' );
update_option( 'woocommerce_registration_generate_password', 'no' );
if ( ! get_theme_mod( 'nexxi_kids_flash_sale_end' ) ) { set_theme_mod( 'nexxi_kids_flash_sale_end', gmdate( 'c', time() + 3 * HOUR_IN_SECONDS ) ); }

$zone = new WC_Shipping_Zone( 0 );
$method_ids = array();
foreach ( $zone->get_shipping_methods() as $method ) { $method_ids[ $method->id ] = $method->instance_id; }
if ( ! isset( $method_ids['free_shipping'] ) ) { $method_ids['free_shipping'] = $zone->add_shipping_method( 'free_shipping' ); }
if ( ! isset( $method_ids['flat_rate'] ) ) { $method_ids['flat_rate'] = $zone->add_shipping_method( 'flat_rate' ); }
update_option( 'woocommerce_free_shipping_' . $method_ids['free_shipping'] . '_settings', array( 'title' => 'Miễn phí giao hàng', 'enabled' => 'yes', 'requires' => 'min_amount', 'min_amount' => '299000', 'ignore_discounts' => 'no' ) );
update_option( 'woocommerce_flat_rate_' . $method_ids['flat_rate'] . '_settings', array( 'title' => 'Giao hàng tiêu chuẩn', 'enabled' => 'yes', 'cost' => '30000', 'tax_status' => 'none' ) );
$cod = get_option( 'woocommerce_cod_settings', array() );
$cod['enabled'] = 'yes';
update_option( 'woocommerce_cod_settings', $cod );

$category_data = array(
	array( 'Bé gái', 'be-gai', 6 ),
	array( 'Bé trai', 'be-trai', 7 ),
	array( 'Sơ sinh', 'so-sinh', 5 ),
	array( 'Đồ mặc nhà', 'do-mac-nha', 8 ),
);
$categories = array();
foreach ( $category_data as $item ) {
	$term = term_exists( $item[1], 'product_cat' );
	if ( ! $term ) { $term = wp_insert_term( $item[0], 'product_cat', array( 'slug' => $item[1] ) ); }
	if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
	$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	$categories[ $item[1] ] = $term_id;
	update_term_meta( $term_id, 'thumbnail_id', nexxi_preview_image( $item[2], $item[0] ) );
}


/* ---------- Attribute terms ---------- */
if ( class_exists( 'Nexxi_Kids_Catalog' ) ) { ( new Nexxi_Kids_Catalog() )->maybe_install(); }
function nexxi_preview_term( $taxonomy, $slug, $name, $order = null, $hex = '' ) {
	if ( ! taxonomy_exists( $taxonomy ) ) { register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) ); }
	$term = term_exists( $slug, $taxonomy );
	if ( ! $term ) { $term = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) ); }
	if ( is_wp_error( $term ) ) { throw new RuntimeException( $taxonomy . ': ' . $term->get_error_message() ); }
	$id = (int) $term['term_id'];
	if ( null !== $order ) { update_term_meta( $id, 'order', $order ); }
	if ( $hex ) { update_term_meta( $id, 'nexxi_color_hex', $hex ); }
	return $id;
}
$colors = array(
	'hong-phan'    => array( 'Hồng phấn', '#f6c1c8' ),
	'xanh-mint'    => array( 'Xanh mint', '#bfe6d6' ),
	'vang-kem'     => array( 'Vàng kem', '#f6e3a8' ),
	'trang-sua'    => array( 'Trắng sữa', '#faf6ee' ),
	'xanh-duong'   => array( 'Xanh dương nhạt', '#bcd4f2' ),
	'tim-lavender' => array( 'Tím lavender', '#d9cdf1' ),
	'be'           => array( 'Be', '#e8d9c4' ),
);
$order = 0;
foreach ( $colors as $slug => $color ) { nexxi_preview_term( 'pa_mau-sac', $slug, $color[0], ++$order, $color[1] ); }
foreach ( array( 'cotton-organic' => 'Cotton Organic', 'soi-tre-bamboo' => 'Sợi tre Bamboo', 'linen' => 'Linen', 'dui-mat' => 'Đũi mát', 'kaki-mem' => 'Kaki mềm' ) as $slug => $name ) { nexxi_preview_term( 'pa_chat-lieu', $slug, $name ); }
$order = 0;
foreach ( array( '0-12-thang' => '0–12 Tháng', '1-3-tuoi' => '1–3 Tuổi', '4-8-tuoi' => '4–8 Tuổi', '9-15-tuoi' => '9–15 Tuổi' ) as $slug => $name ) { nexxi_preview_term( 'pa_do-tuoi', $slug, $name, ++$order ); }
foreach ( array( 'bodysuit' => 'Bodysuit', 'dam-vay' => 'Đầm / Váy', 'yem' => 'Yếm', 'bo-ngu' => 'Bộ ngủ', 'romper' => 'Romper', 'set-bo' => 'Set bộ' ) as $slug => $name ) { nexxi_preview_term( 'pa_kieu-dang', $slug, $name ); }

function nexxi_preview_attribute( $slug, array $term_slugs, $variation, $position ) {
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	$attribute = new WC_Product_Attribute();
	$attribute->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
	$attribute->set_name( $taxonomy );
	$ids = array();
	foreach ( $term_slugs as $term_slug ) {
		$term = get_term_by( 'slug', $term_slug, $taxonomy );
		if ( $term ) { $ids[] = (int) $term->term_id; }
	}
	$attribute->set_options( $ids );
	$attribute->set_position( $position );
	$attribute->set_visible( true );
	$attribute->set_variation( $variation );
	return $attribute;
}

/* ---------- Variable products ----------
 * name, sku, image, category, regular, sale (0 = not on sale), sizes [from, to], colours, material, ages, style, gallery, sold */
$products = array(
	array( 'Bodysuit Mint Organic Cho Bé', 'nexxi-preview-01', 5, 'so-sinh', 249000, 199000, array( 1, 4 ), array( 'xanh-mint', 'trang-sua' ), 'cotton-organic', array( '0-12-thang', '1-3-tuoi' ), 'bodysuit', array( 12, 1 ), 128 ),
	array( 'Đầm Hoa Hồng Tay Bèo', 'nexxi-preview-02', 6, 'be-gai', 329000, 259000, array( 2, 7 ), array( 'hong-phan', 'trang-sua' ), 'cotton-organic', array( '1-3-tuoi', '4-8-tuoi' ), 'dam-vay', array( 9, 15 ), 86 ),
	array( 'Yếm Linen Vàng Nắng Bé Trai', 'nexxi-preview-03', 7, 'be-trai', 359000, 0, array( 2, 6 ), array( 'vang-kem', 'be' ), 'linen', array( '1-3-tuoi', '4-8-tuoi' ), 'yem', array( 10, 6 ), 64 ),
	array( 'Bộ Ngủ Bamboo Mây Tím', 'nexxi-preview-04', 8, 'do-mac-nha', 299000, 239000, array( 1, 8 ), array( 'tim-lavender', 'xanh-duong' ), 'soi-tre-bamboo', array( '0-12-thang', '1-3-tuoi', '4-8-tuoi' ), 'bo-ngu', array( 3, 11 ), 152 ),
	array( 'Đầm Hoa Kem Cổ Bèo', 'nexxi-preview-05', 9, 'be-gai', 389000, 319000, array( 3, 10 ), array( 'vang-kem', 'hong-phan' ), 'dui-mat', array( '1-3-tuoi', '4-8-tuoi', '9-15-tuoi' ), 'dam-vay', array( 7, 13 ), 41 ),
	array( 'Yếm Kaki Bé Trai', 'nexxi-preview-06', 10, 'be-trai', 379000, 0, array( 3, 9 ), array( 'be', 'xanh-duong' ), 'kaki-mem', array( '4-8-tuoi' ), 'yem', array( 4, 14 ), 37 ),
	array( 'Đầm Công Chúa Màu Đào', 'nexxi-preview-07', 15, 'be-gai', 279000, 219000, array( 2, 8 ), array( 'hong-phan', 'trang-sua', 'tim-lavender' ), 'cotton-organic', array( '1-3-tuoi', '4-8-tuoi' ), 'dam-vay', array( 1, 2 ), 93 ),
	array( 'Romper Bamboo Xanh Lá', 'nexxi-preview-08', 12, 'so-sinh', 269000, 229000, array( 1, 4 ), array( 'xanh-mint', 'vang-kem' ), 'soi-tre-bamboo', array( '0-12-thang', '1-3-tuoi' ), 'romper', array( 5, 8 ), 210 ),
);
$descriptions = array(
	'so-sinh'    => "<p>Thiết kế dành riêng cho làn da non nớt của bé sơ sinh: đường may lật ra ngoài, cúc bấm mềm không cấn và cổ áo rộng giúp mẹ thay đồ thật nhanh.</p><ul><li>Vải dệt kim co giãn 4 chiều, thấm hút mồ hôi tốt</li><li>Nhuộm màu an toàn, không chứa formaldehyde</li><li>Càng giặt càng mềm, giữ phom sau nhiều lần giặt máy</li></ul>",
	'be-gai'     => "<p>Phom dáng xòe nhẹ, tay bèo điệu đà giúp bé gái tự tin xoay vòng trong những buổi dạo phố, tiệc sinh nhật hay chụp ảnh gia đình.</p><ul><li>Lớp lót cotton mềm mát bên trong</li><li>Khóa kéo giấu sau lưng, bé mặc và cởi dễ dàng</li><li>Chân váy may 2 lớp, không lo hở hang khi vận động</li></ul>",
	'be-trai'    => "<p>Kiểu dáng năng động, quai đeo điều chỉnh được nhiều nấc để bé mặc vừa vặn lâu hơn khi lớn nhanh.</p><ul><li>Túi trước tiện lợi, bé thích thú cất đồ chơi nhỏ</li><li>Đường may chắc chắn, chịu được những buổi chạy nhảy cả ngày</li><li>Màu nhuộm bền, không phai khi giặt</li></ul>",
	'do-mac-nha' => "<p>Bộ đồ ngủ êm ái giúp bé ngủ sâu giấc hơn nhờ khả năng điều hòa nhiệt và thoáng khí vượt trội của sợi tre.</p><ul><li>Kháng khuẩn tự nhiên, hạn chế mùi hôi</li><li>Lưng thun bọc vải, không hằn bụng bé</li><li>Mát vào mùa hè, ấm nhẹ vào mùa đông</li></ul>",
);
$lookup = null;
if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore' ) ) {
	try { $lookup = wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::class ); } catch ( Throwable $e ) { $lookup = null; }
}
$product_ids = array();
foreach ( $products as $index => $item ) {
	list( $name, $sku, $image, $category, $regular, $sale, $range, $color_slugs, $material, $ages, $style, $gallery, $sold ) = $item;
	$existing_id = wc_get_product_id_by_sku( $sku );
	if ( $existing_id && 'variable' !== WC_Product_Factory::get_product_type( $existing_id ) ) {
		// Convert the earlier simple demo product in place so its ID, reviews and links stay valid.
		wp_set_object_terms( $existing_id, 'variable', 'product_type' );
		wp_cache_delete( WC_Cache_Helper::get_cache_prefix( 'product_' . $existing_id ) . '_type_' . $existing_id, 'products' );
		foreach ( array( '_regular_price', '_sale_price', '_price', '_stock', '_manage_stock' ) as $meta ) { delete_post_meta( $existing_id, $meta ); }
	}
	$product = $existing_id ? new WC_Product_Variable( $existing_id ) : new WC_Product_Variable();
	$product->set_name( $name );
	$product->set_slug( sanitize_title( $name ) );
	$product->set_status( 'publish' );
	$product->set_sku( $sku );
	$product->set_description( $descriptions[ $category ] );
	$product->set_short_description( 'Chất liệu mềm dịu · Đường may êm · Dễ mặc dễ thay cho bé mỗi ngày' );
	$product->set_manage_stock( false );
	$product->set_category_ids( array( $categories[ $category ] ) );
	$product->set_image_id( nexxi_preview_image( $image, $name ) );
	$product->set_gallery_image_ids( array_map( static function ( $i ) use ( $name ) { return nexxi_preview_image( $i, $name ); }, $gallery ) );
	$product->set_reviews_allowed( true );
	$product->set_total_sales( $sold );
	$size_slugs = array_map( static function ( $n ) { return 'size-' . $n; }, range( $range[0], $range[1] ) );
	$product->set_attributes( array(
		nexxi_preview_attribute( 'size', $size_slugs, true, 0 ),
		nexxi_preview_attribute( 'mau-sac', $color_slugs, true, 1 ),
		nexxi_preview_attribute( 'chat-lieu', array( $material ), false, 2 ),
		nexxi_preview_attribute( 'do-tuoi', $ages, false, 3 ),
		nexxi_preview_attribute( 'kieu-dang', array( $style ), false, 4 ),
	) );
	$parent_id = $product->save();
	update_post_meta( $parent_id, '_nexxi_care', 'Giặt tay hoặc giặt máy chế độ nhẹ ở 30°C, lộn trái sản phẩm và phơi nơi thoáng mát để vải luôn mềm mịn.' );
	$product_ids[] = $parent_id;

	foreach ( range( $range[0], $range[1] ) as $size ) {
		foreach ( $color_slugs as $color_index => $color_slug ) {
			$variation_sku = $sku . '-' . $size . '-' . $color_slug;
			$variation_id = wc_get_product_id_by_sku( $variation_sku );
			$variation = $variation_id ? new WC_Product_Variation( $variation_id ) : new WC_Product_Variation();
			$step = (int) floor( ( $size - 1 ) / 3 ) * 20000;
			$variation->set_parent_id( $parent_id );
			$variation->set_sku( $variation_sku );
			$variation->set_status( 'publish' );
			$variation->set_attributes( array( 'pa_size' => 'size-' . $size, 'pa_mau-sac' => $color_slug ) );
			$variation->set_regular_price( (string) ( $regular + $step ) );
			$variation->set_sale_price( $sale ? (string) ( $sale + $step ) : '' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( ( $size * 7 + $color_index * 5 + $index * 3 ) % 13 );
			$variation->save();
		}
	}
	WC_Product_Variable::sync( $parent_id );
	wc_delete_product_transients( $parent_id );
	if ( $lookup && method_exists( $lookup, 'create_data_for_product' ) ) {
		try { $lookup->create_data_for_product( wc_get_product( $parent_id ) ); } catch ( Throwable $e ) { /* filtering falls back to taxonomy queries */ }
	}
}

/* ---------- Reviews ---------- */
$reviews = array(
	array( 0, 'Mẹ Thùy Chi', 5, 'Vải mềm thật sự, bé nhà mình mặc ngủ cả đêm không bị đổ mồ hôi lưng. Size chuẩn theo bảng.' ),
	array( 0, 'Mẹ Hà My', 4, 'Màu mint ngoài đời dịu hơn trong ảnh một chút nhưng rất xinh, đóng gói cẩn thận.' ),
	array( 1, 'Mẹ Bảo Ngọc', 5, 'Bé mặc đi sinh nhật ai cũng khen, tay bèo không bị xẹp sau khi giặt.' ),
	array( 3, 'Mẹ Mai Anh', 5, 'Bộ ngủ sợi tre mát lắm, mình đã mua thêm màu xanh dương cho bé thứ hai.' ),
	array( 3, 'Bố Minh Khang', 5, 'Giao nhanh, có hộp quà xinh. Bé thích chất vải mềm mềm này.' ),
	array( 6, 'Mẹ Thu Trang', 4, 'Đầm đẹp, chân váy 2 lớp nên rất kín đáo. Mình chọn lớn hơn một size theo tư vấn.' ),
	array( 7, 'Mẹ Lan Hương', 5, 'Romper co giãn tốt, cúc bấm dễ thay tã. Rất đáng tiền!' ),
);
foreach ( $reviews as $key => $review ) {
	$product_id = $product_ids[ $review[0] ];
	$exists = get_comments( array( 'post_id' => $product_id, 'meta_key' => '_nexxi_preview_review', 'meta_value' => (string) $key, 'count' => true ) );
	if ( $exists ) { continue; }
	$comment_id = wp_insert_comment( array( 'comment_post_ID' => $product_id, 'comment_author' => $review[1], 'comment_author_email' => 'preview' . $key . '@nexxi.local', 'comment_content' => $review[3], 'comment_type' => 'review', 'comment_approved' => 1, 'comment_date' => gmdate( 'Y-m-d H:i:s', time() - ( $key + 1 ) * DAY_IN_SECONDS ) ) );
	if ( $comment_id ) {
		add_comment_meta( $comment_id, 'rating', $review[2], true );
		add_comment_meta( $comment_id, '_nexxi_preview_review', (string) $key, true );
	}
}
foreach ( $product_ids as $product_id ) { WC_Comments::clear_transients( $product_id ); }

/* ---------- Content pages ---------- */
function nexxi_preview_content_page( $slug, $title, $content, $excerpt = '' ) {
	$id = nexxi_preview_page( $slug, $title, $content );
	$post = get_post( $id );
	// Refresh only untouched placeholder copy from earlier preview builds.
	if ( '' === trim( $post->post_content ) || false !== strpos( $post->post_content, 'đang được cập nhật cho bản xem trước' ) || get_post_meta( $id, '_nexxi_preview_page', true ) ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt ) );
		update_post_meta( $id, '_nexxi_preview_page', 1 );
	}
	return $id;
}
nexxi_preview_content_page( 'gioi-thieu', 'Giới Thiệu NEXXI Kids', "<h2>Câu chuyện bắt đầu từ chiếc áo đầu tiên của con</h2>\n<p>NEXXI Kids ra đời từ mong muốn rất giản dị của những người làm cha mẹ: tìm được bộ quần áo vừa xinh xắn, vừa thật sự an toàn cho làn da non nớt của bé. Chúng tôi chọn từng cuộn vải organic, sợi tre và linen tự nhiên, thử nghiệm qua hàng chục lần giặt trước khi đưa vào sản xuất.</p>\n<h2>Điều NEXXI luôn giữ gìn</h2>\n<ul><li><strong>An toàn trước tiên:</strong> vải đạt chuẩn OEKO-TEX, nhuộm màu không chứa kim loại nặng.</li><li><strong>Vừa vặn từng giai đoạn:</strong> bảng size 1–16 xây dựng theo cân nặng và chiều cao thực tế của trẻ em Việt Nam.</li><li><strong>Tận tâm sau bán hàng:</strong> đổi size, đổi mẫu miễn phí trong 30 ngày.</li></ul>\n<blockquote>“Mỗi đường may là một lời hứa nâng niu nụ cười của bé.”</blockquote>", 'Thời trang dịu êm, đồng hành cùng những năm tháng đầu đời của bé.' );
nexxi_preview_content_page( 'lien-he', 'Liên Hệ Với NEXXI', "<h2>Câu hỏi thường gặp</h2>\n<h3>Bao lâu thì mẹ nhận được hàng?</h3><p>Nội thành Hà Nội và TP.HCM 1–2 ngày, các tỉnh thành khác 2–4 ngày làm việc.</p>\n<h3>Bé mặc không vừa thì làm sao?</h3><p>Mẹ được đổi size hoặc đổi mẫu miễn phí trong 30 ngày, sản phẩm còn nguyên tem mác.</p>\n<h3>NEXXI có kiểm hàng trước khi thanh toán không?</h3><p>Có, mẹ được mở hộp kiểm tra sản phẩm trước khi thanh toán cho shipper.</p>" );
nexxi_preview_content_page( 'yeu-thich', 'Danh Sách Yêu Thích', '' );
nexxi_preview_content_page( 'doi-tra', 'Chính Sách Đổi Trả & Bảo Hành', "<h2>Đổi size, đổi mẫu miễn phí trong 30 ngày</h2>\n<p>Kể từ ngày nhận hàng, mẹ có 30 ngày để đổi size hoặc đổi sang mẫu khác cùng giá trị. NEXXI hỗ trợ phí vận chuyển cho lần đổi đầu tiên.</p>\n<h3>Điều kiện đổi hàng</h3><ul><li>Sản phẩm còn nguyên tem mác, chưa qua giặt tẩy.</li><li>Không có mùi lạ, vết bẩn hoặc hư hỏng do sử dụng.</li><li>Kèm hóa đơn hoặc mã đơn hàng.</li></ul>\n<h3>Quy trình</h3><ol><li>Liên hệ hotline hoặc gửi lời nhắn tại trang Liên hệ.</li><li>Đóng gói sản phẩm, NEXXI gửi shipper đến nhận tận nhà.</li><li>Nhận sản phẩm mới trong 2–4 ngày làm việc.</li></ol>\n<h2 id=\"bao-hanh\">Bảo hành an toàn</h2><p>NEXXI bảo hành đường may và cúc bấm trong 90 ngày. Nếu sản phẩm bung chỉ, tuột cúc do lỗi sản xuất, chúng tôi sửa hoặc đổi mới miễn phí.</p>" );
nexxi_preview_content_page( 'van-chuyen', 'Chính Sách Vận Chuyển', "<h2>Phí giao hàng</h2>\n<table><thead><tr><th>Giá trị đơn hàng</th><th>Phí giao hàng</th></tr></thead><tbody><tr><td>Từ 299.000₫</td><td>Miễn phí toàn quốc</td></tr><tr><td>Dưới 299.000₫</td><td>30.000₫</td></tr></tbody></table>\n<h2>Thời gian giao hàng</h2><ul><li>Nội thành Hà Nội, TP.HCM: 1–2 ngày làm việc.</li><li>Các tỉnh thành khác: 2–4 ngày làm việc.</li></ul>\n<h2>Đóng gói</h2><p>Mỗi đơn hàng được gói trong hộp quà NEXXI kèm thiệp viết tay — sẵn sàng làm quà tặng cho bé.</p>" );

/* ---------- Store contact (preview defaults, editable in the Customizer) ---------- */
foreach ( array( 'nexxi_kids_hotline' => '1900 8888', 'nexxi_kids_email' => 'hello@nexxikids.vn', 'nexxi_kids_address' => '123 Nguyễn Trãi, Phường Bến Thành, Quận 1, TP. Hồ Chí Minh', 'nexxi_kids_hours' => '8:00 – 21:00 hằng ngày (cả T7, CN)' ) as $key => $value ) {
	if ( ! get_theme_mod( $key ) ) { set_theme_mod( $key, $value ); }
}

/* Preview only: keep the store public so logged-out sessions do not get WooCommerce's coming-soon page. */
update_option( 'woocommerce_coming_soon', 'no' );

flush_rewrite_rules();
update_option( '_nexxi_preview_seeded', 2 );
echo "Preview pages, attributes, variable products and reviews are up to date.\n";
