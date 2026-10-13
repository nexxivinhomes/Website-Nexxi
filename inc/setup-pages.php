<?php
/**
 * Content pages the theme links to (header, footer, account, Studio):
 * Giới thiệu, Liên hệ, Đổi trả, Vận chuyển, Yêu thích.
 *
 * Runs once per version in wp-admin and ONLY creates pages whose slug does
 * not exist yet (any status, including drafts and trash). Existing pages are
 * never modified, so content edited by the shop owner is always preserved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEXXI_KIDS_PAGES_VERSION = '1';

function nexxi_kids_default_pages() {
	return array(
		'gioi-thieu' => array(
			'title'   => 'Giới Thiệu NEXXI Kids',
			'content' => '<h2>Câu chuyện bắt đầu từ chiếc áo đầu tiên của con</h2>
<p>NEXXI Kids ra đời từ mong muốn rất giản dị của những người làm cha mẹ: tìm được bộ quần áo vừa xinh xắn, vừa thật sự an toàn cho làn da non nớt của bé. Chúng tôi chọn từng cuộn vải organic, sợi tre và linen tự nhiên, thử nghiệm qua hàng chục lần giặt trước khi đưa vào sản xuất.</p>
<h2>Điều NEXXI luôn giữ gìn</h2>
<ul><li><strong>An toàn trước tiên:</strong> vải đạt chuẩn OEKO-TEX, nhuộm màu không chứa kim loại nặng.</li><li><strong>Vừa vặn từng giai đoạn:</strong> bảng size 1–16 xây dựng theo cân nặng và chiều cao thực tế của trẻ em Việt Nam.</li><li><strong>Tận tâm sau bán hàng:</strong> đổi size, đổi mẫu miễn phí trong 30 ngày.</li></ul>
<blockquote>“Mỗi đường may là một lời hứa nâng niu nụ cười của bé.”</blockquote>',
		),
		'lien-he' => array(
			'title'   => 'Liên Hệ Với NEXXI',
			'content' => '<h2>Câu hỏi thường gặp</h2>
<h3>Bao lâu thì mẹ nhận được hàng?</h3><p>Nội thành Hà Nội và TP.HCM 1–2 ngày, các tỉnh thành khác 2–4 ngày làm việc.</p>
<h3>Bé mặc không vừa thì làm sao?</h3><p>Mẹ được đổi size hoặc đổi mẫu miễn phí trong 30 ngày, sản phẩm còn nguyên tem mác.</p>
<h3>NEXXI có kiểm hàng trước khi thanh toán không?</h3><p>Có, mẹ được mở hộp kiểm tra sản phẩm trước khi thanh toán cho shipper.</p>',
		),
		'doi-tra' => array(
			'title'   => 'Chính Sách Đổi Trả & Bảo Hành',
			'content' => '<h2>Đổi size, đổi mẫu miễn phí trong 30 ngày</h2>
<p>Kể từ ngày nhận hàng, mẹ có 30 ngày để đổi size hoặc đổi sang mẫu khác cùng giá trị. NEXXI hỗ trợ phí vận chuyển cho lần đổi đầu tiên.</p>
<h3>Điều kiện đổi hàng</h3><ul><li>Sản phẩm còn nguyên tem mác, chưa qua giặt tẩy.</li><li>Không có mùi lạ, vết bẩn hoặc hư hỏng do sử dụng.</li><li>Kèm hóa đơn hoặc mã đơn hàng.</li></ul>
<h3>Quy trình</h3><ol><li>Liên hệ hotline hoặc gửi lời nhắn tại trang Liên hệ.</li><li>Đóng gói sản phẩm, NEXXI gửi shipper đến nhận tận nhà.</li><li>Nhận sản phẩm mới trong 2–4 ngày làm việc.</li></ol>
<h2 id="bao-hanh">Bảo hành an toàn</h2><p>NEXXI bảo hành đường may và cúc bấm trong 90 ngày. Nếu sản phẩm bung chỉ, tuột cúc do lỗi sản xuất, chúng tôi sửa hoặc đổi mới miễn phí.</p>',
		),
		'van-chuyen' => array(
			'title'   => 'Chính Sách Vận Chuyển',
			'content' => '<h2>Phí giao hàng</h2>
<table><thead><tr><th>Giá trị đơn hàng</th><th>Phí giao hàng</th></tr></thead><tbody><tr><td>Từ 299.000₫</td><td>Miễn phí toàn quốc</td></tr><tr><td>Dưới 299.000₫</td><td>30.000₫</td></tr></tbody></table>
<h2>Thời gian giao hàng</h2><ul><li>Nội thành Hà Nội, TP.HCM: 1–2 ngày làm việc.</li><li>Các tỉnh thành khác: 2–4 ngày làm việc.</li></ul>
<h2>Đóng gói</h2><p>Mỗi đơn hàng được gói trong hộp quà NEXXI kèm thiệp viết tay — sẵn sàng làm quà tặng cho bé.</p>',
		),
		'yeu-thich' => array(
			'title'   => 'Danh Sách Yêu Thích',
			'content' => '',
		),
	);
}

/** True when any post of type page (any status) already uses the slug. */
function nexxi_kids_page_slug_taken( $slug ) {
	$found = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
	if ( $found ) { return true; }
	$trashed = get_posts( array( 'post_type' => 'page', 'name' => $slug . '__trashed', 'post_status' => 'trash', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
	return (bool) $trashed;
}

/** Create missing pages; returns the slugs created. */
function nexxi_kids_ensure_pages() {
	$created = array();
	foreach ( nexxi_kids_default_pages() as $slug => $page ) {
		if ( nexxi_kids_page_slug_taken( $slug ) ) { continue; }
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $page['title'],
			'post_content' => $page['content'],
			'post_author'  => get_current_user_id(),
		), true );
		if ( ! is_wp_error( $id ) ) { $created[] = $slug; }
	}
	return $created;
}

function nexxi_kids_maybe_setup_pages() {
	if ( NEXXI_KIDS_PAGES_VERSION === get_option( 'nexxi_kids_pages_version' ) || ! current_user_can( 'publish_pages' ) ) { return; }
	$created = nexxi_kids_ensure_pages();
	update_option( 'nexxi_kids_pages_version', NEXXI_KIDS_PAGES_VERSION, false );
	if ( $created ) {
		flush_rewrite_rules( false );
		set_transient( 'nexxi_kids_pages_created', $created, HOUR_IN_SECONDS );
	}
}
add_action( 'admin_init', 'nexxi_kids_maybe_setup_pages' );
add_action( 'after_switch_theme', static function () { delete_option( 'nexxi_kids_pages_version' ); } );

function nexxi_kids_pages_notice() {
	$created = get_transient( 'nexxi_kids_pages_created' );
	if ( ! $created || ! current_user_can( 'publish_pages' ) ) { return; }
	delete_transient( 'nexxi_kids_pages_created' );
	$links = array();
	foreach ( $created as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page ) { $links[] = '<a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>'; }
	}
	echo '<div class="notice notice-success is-dismissible"><p><strong>NEXXI Kids:</strong> đã tạo các trang còn thiếu: ' . wp_kses_post( implode( ', ', $links ) ) . '. Mẹ có thể sửa nội dung trong trình soạn thảo, khung giao diện vẫn theo thiết kế NEXXI.</p></div>';
}
add_action( 'admin_notices', 'nexxi_kids_pages_notice' );
