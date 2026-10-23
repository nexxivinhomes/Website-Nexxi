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
			'title'   => 'NEXXI – Phong cách khác, giá trị khác',
			'content' => '<p><strong>Một bộ trang phục đẹp để bé tự tin. Một lựa chọn chu đáo để ba mẹ an tâm.</strong></p>
<p>NEXXI là thương hiệu thời trang trẻ em nội địa Việt Nam, mang cảm hứng Hàn Quốc vào những thiết kế dành cho bé trai và bé gái. Chúng tôi hướng đến sự hài hòa giữa vẻ ngoài đáng yêu, tính ứng dụng và mức giá phù hợp với gia đình Việt.</p>
<p>Với NEXXI, trang phục cần đẹp khi nhìn và thoải mái khi mặc, để bé tự nhiên vui chơi, khám phá và thể hiện nét riêng mỗi ngày.</p>
<h2>Câu chuyện của NEXXI</h2>
<p>Trẻ em có cách cảm nhận thế giới rất riêng. Một ngày đi học, một buổi dạo phố hay một chuyến đi cùng gia đình đều có thể trở thành kỷ niệm đáng nhớ. NEXXI mong muốn đồng hành trong những khoảnh khắc ấy bằng các thiết kế dễ mặc, dễ phối và gần gũi với cuộc sống của bé.</p>
<p>Mang tinh thần “Phong cách khác, giá trị khác”, chúng tôi xây dựng dấu ấn từ sự chỉn chu trong từng lựa chọn: chất liệu mềm mại, phom dáng gọn gàng, chi tiết vừa đủ và phong cách Hàn Quốc đáng yêu, năng động.</p>
<p>Giá trị của một bộ trang phục còn nằm ở cảm giác bé muốn mặc thêm lần nữa, sự thuận tiện khi ba mẹ phối đồ và niềm vui khi tìm được món đồ phù hợp với con.</p>
<h2>Những điều NEXXI chú trọng</h2>
<h3>Thoải mái trong từng chuyển động</h3>
<p>NEXXI chú trọng lựa chọn chất liệu mềm mại và thiết kế dễ mặc, hướng đến sự dễ chịu trong sinh hoạt hằng ngày. Mỗi bộ trang phục cần tạo không gian để bé vận động và vui chơi tự nhiên.</p>
<h3>Phong cách có nét riêng</h3>
<p>Lấy cảm hứng từ thời trang Hàn Quốc, NEXXI kết hợp phom dáng tối giản với những điểm nhấn đáng yêu, năng động. Các thiết kế hướng đến vẻ ngoài chỉn chu nhưng vẫn giữ được sự hồn nhiên đúng lứa tuổi.</p>
<h3>Đa dạng để ba mẹ dễ lựa chọn</h3>
<p>Từ quần áo, đầm váy đến áo khoác và chân váy, NEXXI mang đến nhiều lựa chọn cho bé trai và bé gái. Dải sản phẩm có kích cỡ dành cho bé từ 12–45 kg, giúp ba mẹ tìm trang phục theo từng giai đoạn phát triển và nhu cầu sử dụng.</p>
<h3>Giá trị phù hợp với gia đình Việt</h3>
<p>Chúng tôi hướng đến mức giá phải chăng, đi cùng sự chăm chút về thiết kế và chất lượng, để ba mẹ có thêm lựa chọn khi xây dựng tủ đồ cho con.</p>
<h2>Đồng hành cùng khách hàng và đối tác</h2>
<p>NEXXI cung cấp sản phẩm bán lẻ và bán sỉ trên toàn quốc.</p>
<p>Với khách hàng, chúng tôi chú trọng sự rõ ràng trong thông tin sản phẩm và hỗ trợ lựa chọn trang phục phù hợp. Với đối tác, NEXXI đề cao uy tín, trách nhiệm và sự tin cậy trong quá trình hợp tác.</p>
<p>Chúng tôi tin rằng mối quan hệ lâu dài được xây dựng từ sự chỉn chu trong sản phẩm và cách phục vụ mỗi ngày.</p>
<h2>Cùng NEXXI chọn phong cách cho bé</h2>
<p>Dù là trang phục đi học, đi chơi hay dành cho những dịp đặc biệt, NEXXI mong được cùng ba mẹ chọn những bộ đồ giúp bé thoải mái và tự tin thể hiện nét riêng.</p>
<blockquote>NEXXI – Phong cách khác, giá trị khác.</blockquote>',
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
}
add_action( 'admin_notices', 'nexxi_kids_pages_notice' );
