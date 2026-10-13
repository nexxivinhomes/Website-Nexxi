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

const NEXXI_KIDS_PAGES_VERSION = '2';

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

/**
 * Status of the page that owns a slug: 'publish', another status (draft,
 * private, pending, future) or '' when the slug is free. Trashed pages do not
 * count: WordPress renames their slug to "slug__trashed", so the link is broken.
 */
function nexxi_kids_page_slug_status( $slug ) {
	$found = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => 1, 'suppress_filters' => true ) );
	return $found ? $found[0]->post_status : '';
}

/** Create missing pages; returns array( created slugs, unpublished slugs ). */
function nexxi_kids_ensure_pages() {
	$created = array();
	$hidden = array();
	foreach ( nexxi_kids_default_pages() as $slug => $page ) {
		$status = nexxi_kids_page_slug_status( $slug );
		if ( 'publish' === $status ) { continue; }
		if ( $status ) { $hidden[] = $slug; continue; } // Never publish or edit the owner's own draft/private page.
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $page['title'],
			'post_content' => $page['content'],
			'post_author'  => get_current_user_id(),
		), true );
		if ( is_wp_error( $id ) ) { continue; }
		// Another post may already own the slug (e.g. a trashed page restored later): keep the link working.
		if ( get_post_field( 'post_name', $id ) !== $slug ) { wp_update_post( array( 'ID' => $id, 'post_name' => $slug ) ); }
		$created[] = $slug;
	}
	return array( $created, $hidden );
}

function nexxi_kids_maybe_setup_pages() {
	if ( ! current_user_can( 'publish_pages' ) ) { return; }
	if ( NEXXI_KIDS_PAGES_VERSION !== get_option( 'nexxi_kids_pages_version' ) ) {
		list( $created ) = nexxi_kids_ensure_pages();
		update_option( 'nexxi_kids_pages_version', NEXXI_KIDS_PAGES_VERSION, false );
		if ( $created ) {
			flush_rewrite_rules( false );
			set_transient( 'nexxi_kids_pages_created', $created, HOUR_IN_SECONDS );
		}
	}
}
add_action( 'admin_init', 'nexxi_kids_maybe_setup_pages' );
add_action( 'after_switch_theme', static function () { delete_option( 'nexxi_kids_pages_version' ); } );

function nexxi_kids_pages_notice() {
	if ( ! current_user_can( 'publish_pages' ) ) { return; }
	$created = get_transient( 'nexxi_kids_pages_created' );
	if ( $created ) {
		delete_transient( 'nexxi_kids_pages_created' );
		$links = array();
		foreach ( $created as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) { $links[] = '<a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>'; }
		}
		echo '<div class="notice notice-success is-dismissible"><p><strong>NEXXI Kids:</strong> đã tạo các trang còn thiếu: ' . wp_kses_post( implode( ', ', $links ) ) . '. Mẹ có thể sửa nội dung trong trình soạn thảo, khung giao diện vẫn theo thiết kế NEXXI.</p></div>';
	}
	// Pages the theme links to but which are not published: the owner decides.
	$links = array();
	foreach ( array_keys( nexxi_kids_default_pages() ) as $slug ) {
		$found = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => array( 'draft', 'pending', 'private', 'future' ), 'numberposts' => 1, 'suppress_filters' => true ) );
		if ( $found && ! get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) ) ) {
			$links[] = '<a href="' . esc_url( get_edit_post_link( $found[0]->ID ) ) . '">' . esc_html( get_the_title( $found[0] ) ) . '</a> (/' . esc_html( $slug ) . '/)';
		}
	}
	if ( $links ) {
		echo '<div class="notice notice-warning"><p><strong>NEXXI Kids:</strong> các trang sau đang được menu/footer liên kết nhưng <b>chưa xuất bản</b> nên khách sẽ thấy lỗi 404: ' . wp_kses_post( implode( ', ', $links ) ) . '. Mẹ mở trang và bấm <b>Xuất bản</b> nhé.</p></div>';
	}
}
add_action( 'admin_notices', 'nexxi_kids_pages_notice' );
