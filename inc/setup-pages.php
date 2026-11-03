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

const NEXXI_KIDS_PAGES_VERSION = '3';

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
<h3>Bao lâu thì mẹ nhận được hàng?</h3><p>Nội thành TP.HCM: 1–2 ngày làm việc, các tỉnh thành khác: 3–5 ngày làm việc.</p>
<h3>Bé mặc không vừa thì làm sao?</h3><p>Ba mẹ được hỗ trợ đổi size hoặc đổi mẫu trong vòng 15 ngày kể từ khi nhận hàng, sản phẩm còn nguyên tem mác.</p>
<h3>NEXXI có kiểm hàng trước khi thanh toán không?</h3><p>Có, ba mẹ được mở kiện hàng kiểm tra sản phẩm trước khi thanh toán cho shipper.</p>',
		),
		'doi-tra' => array(
			'title'   => 'Chính Sách Đổi Hàng 15 Ngày',
			'content' => '<h2>Hỗ trợ đổi size, đổi mẫu trong vòng 15 ngày</h2>
<p>Kể từ ngày nhận hàng, ba mẹ có 15 ngày để đổi size hoặc đổi sang mẫu khác. NEXXI cam kết hoàn tiền 100% nếu sản phẩm nhận được không giống với mô tả.</p>
<h3>Điều kiện đổi hàng</h3><ul><li>Sản phẩm còn nguyên tem mác, chưa qua giặt tẩy.</li><li>Không có mùi lạ, vết bẩn hoặc hư hỏng do sử dụng.</li><li>Kèm hóa đơn hoặc mã đơn hàng.</li></ul>
<h3>Quy trình</h3><ol><li>Liên hệ hotline/Zalo 0942 958 091 hoặc gửi lời nhắn tại trang Liên hệ.</li><li>Đóng gói sản phẩm cần đổi theo hướng dẫn của tư vấn viên NEXXI.</li><li>Nhận sản phẩm đổi mới (Nội thành TP.HCM: 1–2 ngày làm việc, các tỉnh thành khác: 3–5 ngày làm việc).</li></ol>',
		),
		'van-chuyen' => array(
			'title'   => 'Chính Sách Vận Chuyển',
			'content' => '<h2>Phí giao hàng</h2>
<p><strong>NEXXI miễn phí ship toàn quốc</strong> cho các đơn hàng đặt mua tại website.</p>
<h2>Thời gian giao hàng</h2><ul><li>Nội thành TP.HCM: 1–2 ngày làm việc.</li><li>Các tỉnh thành khác: 3–5 ngày làm việc.</li></ul>
<h2>Đóng gói</h2><p>Mỗi đơn hàng đều được NEXXI kiểm tra kỹ lưỡng và đóng gói chỉn chu trước khi giao đến tay ba mẹ và bé yêu.</p>',
		),
		'chinh-sach-bao-mat' => array(
			'title'   => 'Chính Sách Bảo Mật Thông Tin & Dữ Liệu Khách Hàng',
			'content' => '<p><strong>Cập nhật hiệu lực:</strong> Tháng 11/2026 — Áp dụng cho toàn bộ hoạt động thương mại điện tử trên website <strong>https://nexxi.vn</strong> (Thương hiệu <strong>NEXXI – Nexxi Kids Fashion</strong>).</p>

<h2>1. Phạm vi áp dụng &amp; Đối tượng khách hàng (Từ đủ 18 tuổi trở lên)</h2>
<p>Website thương mại điện tử <strong>https://nexxi.vn</strong> chuyên kinh doanh <strong>bán lẻ và bán sỉ các sản phẩm trang phục, quần áo may mặc</strong>. Mọi hoạt động giao dịch, đăng ký tài khoản, nhận bản tin và đặt mua hàng hóa trên website <strong>chỉ dành riêng cho khách hàng là người trưởng thành từ đủ 18 tuổi trở lên</strong> có đầy đủ năng lực hành vi dân sự (bao gồm phụ huynh, người giám hộ hợp pháp mua sắm cho gia đình, người mua quà tặng và đối tác đại lý sỉ).</p>

<h2>2. Cam kết bảo vệ trẻ em &amp; Không thu thập dữ liệu trẻ em dưới 13 tuổi</h2>
<p>Tuân thủ nghiêm ngặt quy định pháp luật Việt Nam về bảo vệ dữ liệu cá nhân (Nghị định 13/2023/NĐ-CP) và các tiêu chuẩn quốc tế về bảo vệ quyền riêng tư trực tuyến của trẻ em (bao gồm Điều khoản Công cụ Kinh doanh của Meta &amp; nguyên tắc COPPA):</p>
<ul>
<li>Website <strong>https://nexxi.vn KHÔNG hướng tới đối tượng trẻ em tự truy cập hoặc tự thực hiện giao dịch</strong>, không cung cấp trò chơi hay nội dung giải trí dành cho trẻ em.</li>
<li>NEXXI <strong>tuyệt đối không thu thập, không lưu trữ và không chia sẻ dữ liệu định danh cá nhân (PII) của trẻ em dưới 13 tuổi</strong> cho bất kỳ bên thứ ba hay nền tảng quảng cáo nào.</li>
<li>Công cụ bảng quy đổi kích cỡ trang phục (cân nặng/chiều cao tham khảo) trên website hoạt động hoàn toàn cục bộ trên trình duyệt của phụ huynh để tra cứu size quần áo, <strong>không lưu trữ trên máy chủ và không gửi đi bất kỳ dữ liệu nào</strong>.</li>
<li>Nếu phát hiện bất kỳ thông tin cá nhân nào do người dưới 18 tuổi/trẻ em dưới 13 tuổi tự ý gửi lên khi chưa có sự đồng ý của cha mẹ hoặc người giám hộ hợp pháp, NEXXI sẽ xóa bỏ ngay lập tức khỏi hệ thống.</li>
</ul>

<h2>3. Thông tin khách hàng trưởng thành (18+) được thu thập &amp; Mục đích sử dụng</h2>
<p>Khi Quý phụ huynh / đối tác (18+) đặt hàng hoặc liên hệ hỗ trợ tại <strong>https://nexxi.vn</strong>, chúng tôi chỉ thu thập các thông tin giao dịch cần thiết của người mua hàng trưởng thành:</p>
<ul>
<li><strong>Thông tin thu thập:</strong> Họ và tên người mua/người nhận hàng (18+), Số điện thoại liên hệ giao hàng, Địa chỉ email (nếu có), Địa chỉ nhận hàng và Lịch sử đơn hàng.</li>
<li><strong>Mục đích sử dụng:</strong> Xác nhận đơn hàng, vận chuyển hàng hóa toàn quốc, hỗ trợ đổi size/đổi mẫu trong vòng 15 ngày, giải quyết khiếu nại và gửi thông báo trạng thái đơn hàng.</li>
</ul>

<h2>4. Cookie &amp; Công cụ đo lường hiệu quả thương mại điện tử</h2>
<p>Website sử dụng Cookie tiêu chuẩn và các công cụ phân tích, đo lường hiệu quả thương mại điện tử (như Google Tag và Meta Pixel) nhằm thống kê lưu lượng truy cập và ghi nhận các sự kiện mua sắm tiêu chuẩn của người mua trưởng thành (xem sản phẩm, thêm vào giỏ hàng, thanh toán). Các công cụ này tuyệt đối không được gắn trên khu vực quản lý hồ sơ tài khoản cá nhân và không thu thập dữ liệu nhạy cảm hay thông tin của trẻ em. Quý khách có thể tùy chỉnh hoặc từ chối Cookie bất cứ lúc nào trong phần cài đặt trình duyệt.</p>

<h2>5. Bảo mật, Lưu trữ &amp; Quyền của chủ thể dữ liệu</h2>
<ul>
<li>Thông tin đơn hàng của Quý khách được bảo vệ bằng kết nối mã hóa SSL (HTTPS) và chỉ chia sẻ trong phạm vi tối thiểu cho đơn vị vận chuyển để giao kiện hàng đến đúng địa chỉ.</li>
<li>Quý khách có toàn quyền yêu cầu kiểm tra, cập nhật, điều chỉnh hoặc xóa bỏ thông tin cá nhân của mình khỏi hệ thống NEXXI bất cứ lúc nào.</li>
<li><strong>Kênh tiếp nhận yêu cầu về dữ liệu &amp; quyền riêng tư:</strong> Hotline/Zalo <strong>0942 958 091</strong> (8h – 17h) hoặc Email <strong>nexxivinhomes@gmail.com</strong>.</li>
</ul>

<hr>
<h2>6. English Compliance Summary (Adult-Operated E-Commerce &amp; Children’s Privacy Policy)</h2>
<blockquote>
<p><strong>18+ Adult Purchaser &amp; Child-Data Non-Collection Notice:</strong><br>
<strong>https://nexxi.vn</strong> (operated by <strong>NEXXI</strong>) is a retail and wholesale apparel e-commerce store strictly directed at <strong>adult purchasers aged 18 and older</strong> (parents, legal guardians, gift buyers, and wholesale business partners). Our website is <strong>NOT</strong> directed at children under the age of 13, does not allow children to register accounts or place orders, and <strong>strictly does NOT knowingly collect, store, or transmit any personal data (PII) of children under 13</strong> to any third-party analytics or advertising platforms (including Meta Business Tools / Meta Pixel and Google Analytics), in full compliance with Vietnamese Personal Data Protection Decree 13/2023/ND-CP, Meta Business Tools Terms, and COPPA principles. All garment size lookup calculators operate purely client-side within the browser without storing or transmitting user inputs. For privacy inquiries or data removal requests, please contact <strong>nexxivinhomes@gmail.com</strong> or <strong>+84 942 958 091</strong>.</p>
</blockquote>',
		),
		'dieu-khoan-su-dung' => array(
			'title'   => 'Điều Khoản Sử Dụng & Mua Hàng',
			'content' => '<p>Chào mừng Quý phụ huynh và Đối tác đến với website thương mại điện tử chính thức <strong>https://nexxi.vn</strong> của thương hiệu thời trang <strong>NEXXI</strong>.</p>

<h2>1. Điều kiện chủ thể giao dịch (Từ đủ 18 tuổi trở lên)</h2>
<ul>
<li>Website <strong>https://nexxi.vn</strong> chỉ cung cấp dịch vụ mua bán hàng hóa trực tuyến cho <strong>khách hàng là cá nhân từ đủ 18 tuổi trở lên</strong> có đầy đủ năng lực hành vi dân sự theo quy định của pháp luật Việt Nam, hoặc các tổ chức/đại lý kinh doanh hợp pháp.</li>
<li>Bằng việc truy cập, đăng ký tài khoản hoặc đặt hàng trên <strong>https://nexxi.vn</strong>, Quý khách xác nhận mình từ đủ 18 tuổi trở lên và chịu trách nhiệm về tính chính xác của thông tin giao nhận đã cung cấp.</li>
</ul>

<h2>2. Quy trình đặt hàng, Giá bán &amp; Thanh toán</h2>
<ul>
<li>Giá bán niêm yết trên website là giá bằng Đồng Việt Nam (VNĐ). Các chương trình ưu đãi Giảm giá sản phẩm, Flash Sale hoặc Combo mua nhiều giảm thêm được hiển thị minh bạch ngay tại trang sản phẩm, giỏ hàng và trang thanh toán.</li>
<li><strong>Miễn phí vận chuyển toàn quốc</strong> cho các đơn hàng đặt mua trực tiếp trên website.</li>
<li><strong>Đồng kiểm khi nhận hàng:</strong> Quý khách được quyền mở kiện hàng kiểm tra đúng mẫu mã, kích cỡ và số lượng trước khi thanh toán cho nhân viên giao hàng (COD).</li>
</ul>

<h2>3. Chính sách giao hàng &amp; Đổi hàng 15 ngày</h2>
<ul>
<li><strong>Thời gian giao hàng dự kiến:</strong> Nội thành TP.HCM từ 1–2 ngày làm việc; các tỉnh thành khác từ 3–5 ngày làm việc (xem chi tiết tại <a href="/van-chuyen/">Chính sách vận chuyển</a>).</li>
<li><strong>Đổi hàng thuận tiện 15 ngày:</strong> Hỗ trợ đổi size hoặc đổi mẫu trong vòng 15 ngày kể từ ngày nhận hàng đối với sản phẩm còn nguyên tem mác, chưa qua giặt tẩy; cam kết hoàn tiền 100% nếu sản phẩm không đúng với mô tả (xem chi tiết tại <a href="/doi-tra/">Chính sách đổi hàng 15 ngày</a>).</li>
</ul>

<h2>4. Bảo mật thông tin &amp; Hỗ trợ khách hàng</h2>
<p>Mọi thông tin giao dịch của Quý khách được bảo vệ nghiêm ngặt theo <a href="/chinh-sach-bao-mat/">Chính sách bảo mật thông tin</a> của NEXXI. Khi cần tư vấn sản phẩm, hợp tác sỉ hoặc hỗ trợ đơn hàng, Quý khách vui lòng liên hệ Hotline/Zalo <strong>0942 958 091</strong> (8h – 17h) hoặc Email <strong>nexxivinhomes@gmail.com</strong>.</p>

<blockquote><strong>Adult-Only E-Commerce Terms Summary:</strong> Transactions and account registrations on <strong>https://nexxi.vn</strong> are strictly restricted to adults aged 18 and older (parents, legal guardians, and wholesale buyers). By placing an order, the buyer confirms they are at least 18 years of age and agrees to our <a href="/chinh-sach-bao-mat/">Privacy Policy</a>.</blockquote>',
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
