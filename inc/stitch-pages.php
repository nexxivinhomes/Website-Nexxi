<?php
/**
 * Content pages in the Stitch shell: generic WP-editor pages (Đổi trả, Vận chuyển…),
 * Giới thiệu, Liên hệ (form + map) and the Yêu thích (wishlist) page.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Which Stitch page template a WordPress page uses. */
function nexxi_kids_page_kind( $post = null ) {
	$post = get_post( $post );
	if ( ! $post ) { return 'page'; }
	$map = array( 'lien-he' => 'contact', 'gioi-thieu' => 'about', 'yeu-thich' => 'wishlist' );
	return isset( $map[ $post->post_name ] ) ? $map[ $post->post_name ] : 'page';
}

/** Shared wrapper: breadcrumb, eyebrow, title and the page body. */
function nexxi_kids_page_shell( $title, $eyebrow, $body, $intro = '' ) {
	$crumbs = '<nav aria-label="Breadcrumb" class="flex items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant mb-space-md flex-wrap"><a class="hover:text-secondary transition-colors flex items-center gap-1" href="' . esc_url( home_url( '/' ) ) . '"><span class="material-symbols-outlined text-[16px]">home</span> Trang Chủ</a><span class="text-outline-variant font-body-sm">/</span><span class="text-primary font-bold bg-surface-container-high px-space-sm py-0.5 rounded-full">' . esc_html( $title ) . '</span></nav>';
	$head = '<div class="mb-space-lg max-w-3xl"><span class="font-label-sm text-label-sm text-secondary bg-secondary-fixed px-space-md py-1 rounded-full uppercase">' . esc_html( $eyebrow ) . '</span><h1 class="font-display-lg text-display-lg-mobile md:text-display-lg text-primary tracking-tight mt-space-sm">' . esc_html( $title ) . '</h1>' . ( $intro ? '<p class="font-body-lg text-body-lg text-on-surface-variant mt-space-xs">' . esc_html( $intro ) . '</p>' : '' ) . '</div>';
	return '<main class="w-full pt-28 md:pt-36 bg-background min-h-screen"><div class="w-full max-w-[1440px] mx-auto px-margin-mobile md:px-margin py-space-lg">' . $crumbs . $head . $body . '</div></main>';
}

/** Rendered editor content of the current page. */
function nexxi_kids_page_content( $post ) {
	$content = apply_filters( 'the_content', get_post_field( 'post_content', $post ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	return trim( wp_strip_all_tags( $content ) ) ? '<div class="bg-surface-container-lowest rounded-xl p-space-lg md:p-space-xl shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] nexxi-prose">' . $content . '</div>' : '';
}

/** Main element for a page screen. */
function nexxi_kids_page_main( $post = null ) {
	if ( is_404() ) {
		return nexxi_kids_page_shell( 'Không tìm thấy trang', 'Lỗi 404', nexxi_kids_empty_state( 'Trang mẹ tìm không tồn tại', 'Đường dẫn có thể đã thay đổi hoặc trang đã bị gỡ. Mẹ quay lại cửa hàng để xem những mẫu mới nhất nhé.', 'Về cửa hàng', nexxi_kids_shop_url() ) );
	}
	$post = get_post( $post );
	if ( ! $post ) { return ''; }
	$kind = nexxi_kids_page_kind( $post );
	$title = get_the_title( $post );
	$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
	switch ( $kind ) {
		case 'contact':
			return nexxi_kids_page_shell( $title, 'Luôn lắng nghe mẹ', nexxi_kids_contact_body( $post ), $excerpt ? $excerpt : 'NEXXI luôn sẵn sàng tư vấn size, đơn hàng và đổi trả cho mẹ.' );
		case 'about':
			return nexxi_kids_page_shell( $title, 'Câu chuyện NEXXI', nexxi_kids_page_content( $post ) . '<div class="mt-space-xl -mx-margin-mobile md:-mx-margin">' . nexxi_kids_home_commitments() . '</div>', $excerpt );
		case 'wishlist':
			return nexxi_kids_page_shell( $title, 'Tủ đồ mơ ước của bé', nexxi_kids_wishlist_body( $post ), $excerpt ? $excerpt : 'Những mẫu mẹ đã thả tim – lưu lại để chọn size và đặt hàng bất cứ lúc nào.' );
	}
	$body = nexxi_kids_page_content( $post );
	return nexxi_kids_page_shell( $title, 'NEXXI Kids Boutique', $body ? $body : nexxi_kids_empty_state( 'Nội dung đang được cập nhật', 'NEXXI sẽ sớm bổ sung thông tin cho trang này.', 'Về trang chủ', home_url( '/' ) ), $excerpt );
}

/** Contact page: info cards, form, editor content and map. */
function nexxi_kids_contact_body( $post ) {
	$hotline = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$email = nexxi_kids_setting( 'nexxi_kids_email' );
	$address = nexxi_kids_setting( 'nexxi_kids_address' );
	$hours = nexxi_kids_setting( 'nexxi_kids_hours' );
	$chat = nexxi_kids_setting( 'nexxi_kids_chat_url' );
	$map = nexxi_kids_setting( 'nexxi_kids_map_embed' );
	$cards = array( array( 'call', 'Hotline tư vấn', $hotline, nexxi_kids_tel( $hotline ) ), array( 'mail', 'Email', $email, 'mailto:' . $email ) );
	if ( $address ) { $cards[] = array( 'storefront', 'Cửa hàng', $address, $map ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address ) ); }
	if ( $hours ) { $cards[] = array( 'schedule', 'Giờ mở cửa', $hours, '' ); }
	if ( $chat ) { $cards[] = array( 'forum', 'Chat với stylist', 'Zalo / Messenger', $chat ); }

	$status = isset( $_GET['nexxi_contact'] ) ? sanitize_key( wp_unslash( $_GET['nexxi_contact'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice = '';
	if ( 'sent' === $status ) {
		$notice = '<div class="bg-secondary-fixed/50 text-on-secondary-fixed rounded-lg p-space-md font-label-md text-label-md flex items-center gap-space-xs" role="status"><span class="material-symbols-outlined text-secondary">check_circle</span>Cảm ơn mẹ! NEXXI đã nhận được lời nhắn và sẽ phản hồi sớm nhất.</div>';
	} elseif ( 'error' === $status ) {
		$notice = '<div class="bg-error-container text-on-error-container rounded-lg p-space-md font-label-md text-label-md flex items-center gap-space-xs" role="alert"><span class="material-symbols-outlined">error</span>Mẹ kiểm tra lại họ tên, số điện thoại/email và lời nhắn giúp NEXXI nhé.</div>';
	}
	$input = 'w-full bg-surface-container-low px-space-md py-3 rounded-lg font-body-md text-body-md text-on-surface outline-none focus:ring-2 focus:ring-secondary-container';

	ob_start(); ?>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
<div class="lg:col-span-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-space-md">
<?php foreach ( $cards as $card ) :
	$tag = $card[3] ? 'a' : 'div'; ?>
<<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="bg-surface-container-lowest rounded-xl p-space-md shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] flex items-start gap-space-sm<?php echo $card[3] ? ' hover:shadow-lg transition-all' : ''; ?>"<?php echo $card[3] ? ' href="' . esc_url( $card[3] ) . '"' . ( 0 === strpos( $card[3], 'http' ) ? ' target="_blank" rel="noopener"' : '' ) : ''; ?>>
<span class="w-11 h-11 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center shrink-0"><span class="material-symbols-outlined text-[22px]"><?php echo esc_html( $card[0] ); ?></span></span>
<span class="block"><span class="block font-label-sm text-label-sm text-outline uppercase"><?php echo esc_html( $card[1] ); ?></span><span class="block font-title-md text-title-md text-primary font-bold mt-0.5"><?php echo nl2br( esc_html( $card[2] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></span>
</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<?php endforeach; ?>
</div>
<form class="lg:col-span-7 bg-surface-container-lowest rounded-xl p-space-lg md:p-space-xl shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] space-y-space-md" id="contact" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
<div><span class="font-label-sm text-label-sm text-secondary uppercase tracking-widest font-bold">Gửi lời nhắn</span><h2 class="font-headline-md text-headline-md text-primary mt-space-xs">Mẹ cần NEXXI hỗ trợ điều gì?</h2></div>
<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-space-md">
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Họ tên của mẹ *</span><input class="<?php echo esc_attr( $input ); ?>" type="text" name="nexxi_name" required maxlength="80" autocomplete="name"></label>
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Số điện thoại *</span><input class="<?php echo esc_attr( $input ); ?>" type="tel" name="nexxi_phone" required maxlength="20" autocomplete="tel" inputmode="tel"></label>
</div>
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Email</span><input class="<?php echo esc_attr( $input ); ?>" type="email" name="nexxi_email" maxlength="120" autocomplete="email"></label>
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Chủ đề</span><select class="<?php echo esc_attr( $input ); ?>" name="nexxi_topic"><option>Tư vấn chọn size</option><option>Đơn hàng &amp; giao hàng</option><option>Đổi trả &amp; bảo hành</option><option>Hợp tác</option><option>Khác</option></select></label>
<label class="block"><span class="block font-label-md text-label-md text-primary mb-1">Lời nhắn *</span><textarea class="<?php echo esc_attr( $input ); ?>" name="nexxi_message" rows="5" required maxlength="3000" placeholder="Bé nhà mình 2 tuổi, nặng 13kg, cao 90cm..."></textarea></label>
<label class="hidden" aria-hidden="true">Website<input type="text" name="nexxi_website" tabindex="-1" autocomplete="off"></label>
<input type="hidden" name="action" value="nexxi_contact"><input type="hidden" name="nexxi_return" value="<?php echo esc_url( get_permalink( $post ) ); ?>">
<?php wp_nonce_field( 'nexxi_contact', 'nexxi_contact_nonce' ); ?>
<button class="bg-secondary-container hover:bg-secondary text-on-secondary px-space-xl py-3.5 rounded-full font-label-lg text-label-lg font-bold transition-all shadow-[0_12px_28px_-6px_rgba(253,106,73,0.4)] inline-flex items-center gap-space-xs" type="submit"><span class="material-symbols-outlined text-[20px]">send</span> Gửi cho NEXXI</button>
</form>
</div>
<?php
	$html = ob_get_clean();
	$content = nexxi_kids_page_content( $post );
	if ( $content ) { $html .= '<div class="mt-space-xl" id="faq">' . $content . '</div>'; }
	if ( $map ) {
		$html .= '<div class="mt-space-xl rounded-xl overflow-hidden shadow-[0_8px_24px_-4px_rgba(27,42,89,0.05)] bg-surface-container-low"><iframe class="w-full h-[380px] border-0 block" src="' . esc_url( $map ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="Bản đồ cửa hàng NEXXI"></iframe></div>';
	}
	return $html;
}

/** Wishlist page: cards are rendered by stitch-interactions.js via the cards REST route. */
function nexxi_kids_wishlist_body( $post ) {
	$cards = '';
	if ( is_user_logged_in() && class_exists( 'Nexxi_Kids_Catalog' ) ) {
		foreach ( Nexxi_Kids_Catalog::wishlist_ids( get_current_user_id() ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->is_visible() ) { $cards .= nexxi_kids_card_shop( $product ); }
		}
	}
	$empty = nexxi_kids_empty_state( 'Chưa có mẫu nào trong danh sách', 'Mẹ bấm biểu tượng trái tim trên sản phẩm để lưu lại những mẫu bé yêu thích nhé.', 'Khám phá sản phẩm', nexxi_kids_shop_url() );
	$out = '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-gutter" data-nexxi-wishlist-grid>' . $cards . '</div>';
	$out .= '<div class="grid grid-cols-1' . ( $cards ? ' hidden' : '' ) . '" data-nexxi-wishlist-empty>' . $empty . '</div>';
	$content = nexxi_kids_page_content( $post );
	return $out . ( $content ? '<div class="mt-space-xl">' . $content . '</div>' : '' );
}

/** Contact form handler (admin-post.php). */
function nexxi_kids_handle_contact() {
	$return = isset( $_POST['nexxi_return'] ) ? esc_url_raw( wp_unslash( $_POST['nexxi_return'] ) ) : home_url( '/' );
	$return = wp_validate_redirect( $return, home_url( '/' ) );
	$fail = static function () use ( $return ) {
		wp_safe_redirect( add_query_arg( 'nexxi_contact', 'error', $return ) . '#contact' );
		exit;
	};
	if ( ! isset( $_POST['nexxi_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_contact_nonce'] ) ), 'nexxi_contact' ) ) { $fail(); }
	if ( ! empty( $_POST['nexxi_website'] ) ) { wp_safe_redirect( add_query_arg( 'nexxi_contact', 'sent', $return ) . '#contact' ); exit; } // Honeypot.
	$name = isset( $_POST['nexxi_name'] ) ? sanitize_text_field( wp_unslash( $_POST['nexxi_name'] ) ) : '';
	$phone = isset( $_POST['nexxi_phone'] ) ? preg_replace( '~[^0-9+ ]~', '', sanitize_text_field( wp_unslash( $_POST['nexxi_phone'] ) ) ) : '';
	$email = isset( $_POST['nexxi_email'] ) ? sanitize_email( wp_unslash( $_POST['nexxi_email'] ) ) : '';
	$topic = isset( $_POST['nexxi_topic'] ) ? sanitize_text_field( wp_unslash( $_POST['nexxi_topic'] ) ) : '';
	$message = isset( $_POST['nexxi_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nexxi_message'] ) ) : '';
	if ( '' === $name || strlen( preg_replace( '~\D~', '', $phone ) ) < 9 || '' === trim( $message ) ) { $fail(); }

	// Light rate limit: 5 messages per IP per 10 minutes.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'nexxi_contact_' . md5( $ip );
	$hits = (int) get_transient( $key );
	if ( $hits >= 5 ) { $fail(); }
	set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

	$to = nexxi_kids_setting( 'nexxi_kids_email' );
	$subject = sprintf( '[NEXXI] %s – %s', $topic ? $topic : 'Liên hệ', $name );
	$body = "Họ tên: {$name}\nĐiện thoại: {$phone}\nEmail: {$email}\nChủ đề: {$topic}\n\n{$message}\n\n— Gửi từ " . home_url( '/' );
	$headers = $email ? array( 'Reply-To: ' . $name . ' <' . $email . '>' ) : array();
	wp_mail( $to, $subject, $body, $headers );
	do_action( 'nexxi_kids_contact_submitted', compact( 'name', 'phone', 'email', 'topic', 'message' ) );
	wp_safe_redirect( add_query_arg( 'nexxi_contact', 'sent', $return ) . '#contact' );
	exit;
}
add_action( 'admin_post_nexxi_contact', 'nexxi_kids_handle_contact' );
add_action( 'admin_post_nopriv_nexxi_contact', 'nexxi_kids_handle_contact' );

/** REST: Stitch-styled shop cards for a list of product IDs (wishlist page for guests). */
function nexxi_kids_register_card_route() {
	register_rest_route( 'nexxi/v1', '/cards', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'ids' => array( 'type' => 'string', 'required' => true ) ),
		'callback'            => static function ( WP_REST_Request $request ) {
			$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) ), 0, 60 );
			$html = '';
			$found = array();
			foreach ( $ids as $id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( ! $product || ! $product->is_visible() || 'publish' !== $product->get_status() ) { continue; }
				$html .= nexxi_kids_card_shop( $product );
				$found[] = $id;
			}
			return rest_ensure_response( array( 'ids' => $found, 'html' => $html ) );
		},
	) );
}
add_action( 'rest_api_init', 'nexxi_kids_register_card_route' );
