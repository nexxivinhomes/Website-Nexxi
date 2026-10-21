<?php
/**
 * Account screen (logged-in): the Stitch "Hồ Sơ & Thiết Lập Tài Khoản" layout
 * driven by real WooCommerce data. Every endpoint (orders, view-order, addresses,
 * account details, downloads, payment methods, plugin endpoints) still renders
 * through WooCommerce's own hooks so forms, nonces and redirects keep working.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nexxi_kids_stitch_account( $html ) {
	if ( ! function_exists( 'WC' ) || ! is_user_logged_in() ) { return $html; }
	$user = wp_get_current_user();
	$endpoint = WC()->query->get_current_endpoint();

	$html = nexxi_dom_set_outer( $html, '<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">', nexxi_kids_account_heading( $user, $endpoint ) );
	$aside = nexxi_dom_get_inner( $html, '<aside class="lg:col-span-4' );
	$html = nexxi_dom_set_inner( $html, '<aside class="lg:col-span-4', nexxi_kids_account_sidebar( $user ) . nexxi_kids_tail_from( (string) $aside, '<!-- Quick Assistance Box' ) );
	$html = nexxi_dom_set_inner( $html, '<main class="lg:col-span-8', nexxi_kids_account_content( $user, $endpoint ) );
	return $html;
}

function nexxi_kids_account_name( WP_User $user ) {
	$name = trim( $user->first_name . ' ' . $user->last_name );
	return $name ? $name : $user->display_name;
}

/** Section card header in the Stitch "Mục 01" style. */
function nexxi_kids_account_section_head( $kicker, $title, $action = '' ) {
	return '<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm mb-space-md pb-space-sm bg-surface-container-low/50 -mx-space-lg -mt-space-lg px-space-lg pt-space-lg rounded-t-xl"><div><span class="font-label-sm text-label-sm text-secondary uppercase font-bold tracking-wider">' . esc_html( $kicker ) . '</span><h3 class="font-title-md text-title-md text-primary font-bold">' . esc_html( $title ) . '</h3></div>' . $action . '</div>';
}

function nexxi_kids_account_heading( WP_User $user, $endpoint ) {
	$title = $endpoint ? WC()->query->get_endpoint_title( $endpoint ) : '';
	$h1 = $endpoint ? ( $title ? $title : 'Tài khoản của mẹ' ) : 'Xin chào, ' . nexxi_kids_account_name( $user ) . '!';
	$sub = $endpoint ? 'Quản lý đơn hàng, sổ địa chỉ và thông tin tài khoản NEXXI Club của mẹ.' : 'Theo dõi đơn hàng, sổ địa chỉ và thiết lập tài khoản của mẹ tại một nơi.';
	$crumb = $endpoint ? '<a class="hover:text-secondary transition-colors" href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">Tài Khoản NEXXI Club</a><span>/</span><span class="text-primary font-bold">' . esc_html( wp_strip_all_tags( $h1 ) ) . '</span>' : '<span class="text-primary font-bold">Tài Khoản NEXXI Club</span>';
	$since = $user->user_registered ? mysql2date( 'm/Y', $user->user_registered ) : '';
	ob_start(); ?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md mb-space-lg">
<div>
<div class="flex items-center gap-space-xs text-on-surface-variant font-label-sm text-label-sm mb-space-xs"><?php echo $crumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
<h1 class="font-headline-lg text-headline-lg text-primary tracking-tight"><?php echo esc_html( wp_strip_all_tags( $h1 ) ); ?></h1>
<p class="font-body-md text-body-md text-on-surface-variant mt-1"><?php echo esc_html( $sub ); ?></p>
</div>
<div class="flex items-center gap-space-sm bg-surface-container-lowest px-space-md py-space-xs rounded-full shadow-sm self-start md:self-auto">
<div class="w-7 h-7 rounded-full bg-secondary-fixed flex items-center justify-center text-secondary"><span class="material-symbols-outlined text-[18px]">verified</span></div>
<div class="flex flex-col"><span class="font-label-sm text-label-sm text-secondary uppercase tracking-wider">Thành viên NEXXI Club</span><span class="font-label-md text-label-md text-primary font-bold"><?php echo $since ? esc_html( 'Đồng hành từ ' . $since ) : 'Chào mừng mẹ'; ?></span></div>
</div>
</div>
	<?php
	return ob_get_clean();
}

function nexxi_kids_account_order_count( $user_id ) {
	return function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user_id ) : 0;
}

function nexxi_kids_account_wishlist_count( $user_id ) {
	return class_exists( 'Nexxi_Kids_Catalog' ) ? count( Nexxi_Kids_Catalog::wishlist_ids( $user_id ) ) : 0;
}

function nexxi_kids_account_sidebar( WP_User $user ) {
	$orders = nexxi_kids_account_order_count( $user->ID );
	$wishlist = nexxi_kids_account_wishlist_count( $user->ID );
	$wishlist_url = nexxi_kids_page_url( 'yeu-thich' );
	$icons = array( 'dashboard' => 'dashboard', 'orders' => 'shopping_bag', 'downloads' => 'download', 'edit-address' => 'home_pin', 'payment-methods' => 'credit_card', 'edit-account' => 'manage_accounts' );
	$link_idle = 'flex items-center justify-between px-space-md py-space-sm rounded-lg text-on-surface-variant hover:bg-surface-container-low hover:text-primary transition-colors';
	$link_active = 'flex items-center justify-between px-space-md py-space-sm rounded-lg bg-secondary-fixed text-on-secondary-fixed font-bold shadow-sm';
	$items = '';
	foreach ( wc_get_account_menu_items() as $key => $label ) {
		if ( 'customer-logout' === $key ) { continue; }
		$active = wc_is_current_account_menu_item( $key );
		$icon = isset( $icons[ $key ] ) ? $icons[ $key ] : 'chevron_right';
		$badge = 'orders' === $key && $orders ? '<span class="bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-2 py-0.5 rounded-full font-bold">' . (int) $orders . ' đơn</span>' : ( $active ? '<span class="w-2 h-2 rounded-full bg-secondary"></span>' : '<span class="material-symbols-outlined text-[18px] text-outline">chevron_right</span>' );
		$items .= '<a class="' . esc_attr( $active ? $link_active : $link_idle ) . '" href="' . esc_url( wc_get_account_endpoint_url( $key ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '><div class="flex items-center gap-space-sm"><span class="material-symbols-outlined text-[20px] ' . ( $active ? 'text-secondary' : 'text-outline' ) . '">' . esc_html( $icon ) . '</span><span class="font-label-md text-label-md">' . esc_html( $label ) . '</span></div>' . $badge . '</a>';
		if ( 'orders' === $key && $wishlist_url ) {
			$items .= '<a class="' . esc_attr( $link_idle ) . '" href="' . esc_url( $wishlist_url ) . '"><div class="flex items-center gap-space-sm"><span class="material-symbols-outlined text-[20px] text-outline">favorite</span><span class="font-label-md text-label-md">Mẫu đồ bé yêu thích</span></div>' . ( $wishlist ? '<span class="bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm px-2 py-0.5 rounded-full font-bold">' . (int) $wishlist . '</span>' : '<span class="material-symbols-outlined text-[18px] text-outline">chevron_right</span>' ) . '</a>';
		}
	}
	ob_start(); ?>
<div class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm flex flex-col items-center text-center relative overflow-hidden">
<div class="absolute -top-12 -right-12 w-28 h-28 bg-secondary-fixed/50 rounded-full blur-xl pointer-events-none"></div>
<div class="relative mt-2 mb-space-sm"><div class="w-24 h-24 rounded-full p-1 bg-secondary-fixed/60 shadow-inner"><img class="w-full h-full object-cover rounded-full shadow-sm" src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 192 ) ) ); ?>" alt="<?php echo esc_attr( nexxi_kids_account_name( $user ) ); ?>"></div></div>
<h2 class="font-headline-sm text-headline-sm text-primary mb-0.5"><?php echo esc_html( nexxi_kids_account_name( $user ) ); ?></h2>
<p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm break-all"><?php echo esc_html( $user->user_email ); ?></p>
<div class="w-full grid grid-cols-2 gap-space-xs pt-space-sm mt-space-xs bg-surface-container-low rounded-lg p-space-sm">
<div class="flex flex-col items-center"><span class="font-label-sm text-label-sm text-outline">Đơn hàng</span><span class="font-label-md text-label-md text-primary font-bold"><?php echo (int) $orders; ?> đơn</span></div>
<div class="flex flex-col items-center"><span class="font-label-sm text-label-sm text-outline">Yêu thích</span><span class="font-label-md text-label-md text-secondary font-bold" data-nexxi-wishlist-count><?php echo (int) $wishlist; ?></span></div>
</div>
</div>
<nav class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex flex-col gap-1" aria-label="Tài khoản">
<?php echo $items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="pt-space-xs mt-space-xs"><a class="w-full flex items-center gap-space-sm px-space-md py-space-sm rounded-lg text-error hover:bg-error-container/40 transition-colors text-left font-label-md text-label-md" href="<?php echo esc_url( wc_logout_url() ); ?>"><span class="material-symbols-outlined text-[20px]">logout</span><span>Đăng xuất tài khoản</span></a></div>
</nav>
	<?php
	return ob_get_clean();
}

function nexxi_kids_account_content( WP_User $user, $endpoint ) {
	global $wp;
	ob_start();
	echo '<div class="nexxi-account-notices">';
	wc_print_notices();
	echo '</div>';
	$raw_sub = ( 'edit-address' === $endpoint && ! empty( $wp->query_vars['edit-address'] ) ) ? sanitize_title( wp_unslash( $wp->query_vars['edit-address'] ) ) : '';
	$sub_address = ( '' !== $raw_sub && function_exists( 'wc_edit_address_i18n' ) ) ? wc_edit_address_i18n( $raw_sub, true ) : $raw_sub;
	if ( ! $endpoint ) {
		echo nexxi_kids_account_dashboard( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'edit-address' === $endpoint && '' === $sub_address ) {
		echo nexxi_kids_account_addresses_view( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		$title = WC()->query->get_endpoint_title( $endpoint );
		$back_btn = '';
		if ( 'edit-address' === $endpoint && '' !== $sub_address ) {
			$title = 'shipping' === $sub_address ? 'Cập nhật địa chỉ giao hàng' : 'Cập nhật địa chỉ thanh toán';
			$back_btn = '<a class="inline-flex items-center gap-1.5 px-space-md py-1.5 rounded-full bg-surface-container-lowest text-primary hover:bg-secondary-fixed hover:text-on-secondary-fixed transition-colors font-label-sm text-label-sm font-bold shadow-sm self-start sm:self-auto" href="' . esc_url( wc_get_account_endpoint_url( 'edit-address' ) ) . '"><span class="material-symbols-outlined text-[16px]">arrow_back</span><span>Quay lại sổ địa chỉ</span></a>';
		}
		echo '<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">' . nexxi_kids_account_section_head( 'NEXXI Club', wp_strip_all_tags( $title ? $title : 'Tài khoản' ), $back_btn ) . '<div class="woocommerce nexxi-account">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		do_action( 'woocommerce_account_content' );
		echo '</div></section>';
	}
	return ob_get_clean();
}

/** Stitch-styled overview for /tai-khoan/edit-address/ (Billing & Shipping cards). */
function nexxi_kids_account_addresses_view( WP_User $user ) {
	$uid = $user->ID;
	$customer = new WC_Customer( $uid );
	$cards = array(
		'billing' => array(
			'title'     => 'Địa chỉ thanh toán',
			'subtitle'  => 'Thông tin người đặt & nhận hóa đơn',
			'icon'      => 'receipt_long',
			'badge'     => 'THANH TOÁN',
			'badge_cls' => 'bg-primary text-on-primary',
			'formatted' => wc_get_account_formatted_address( 'billing' ),
			'name'      => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
			'phone'     => $customer->get_billing_phone(),
			'email'     => $customer->get_billing_email(),
			'foot_icon' => 'verified',
			'foot_text' => 'Tự động điền khi thanh toán đơn hàng',
		),
	);
	if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
		$cards['shipping'] = array(
			'title'     => 'Địa chỉ giao hàng',
			'subtitle'  => 'Nơi shipper giao đồ xinh tận tay mẹ & bé',
			'icon'      => 'local_shipping',
			'badge'     => 'MẶC ĐỊNH GIAO HÀNG',
			'badge_cls' => 'bg-secondary text-on-secondary',
			'formatted' => wc_get_account_formatted_address( 'shipping' ),
			'name'      => trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() ),
			'phone'     => method_exists( $customer, 'get_shipping_phone' ) ? $customer->get_shipping_phone() : '',
			'email'     => '',
			'foot_icon' => 'home_pin',
			'foot_text' => 'Ưu tiên dùng để tính phí vận chuyển',
		);
	}

	ob_start(); ?>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'NEXXI Club • Sổ địa chỉ', 'Địa Chỉ Thanh Toán & Giao Hàng' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<p class="font-body-md text-body-md text-on-surface-variant mb-space-md">Các địa chỉ bên dưới sẽ được tự động điền sẵn ở bước Thanh toán để mẹ đặt đồ cho bé nhanh chóng hơn.</p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<?php foreach ( $cards as $type => $card ) :
	$edit_url = wc_get_endpoint_url( 'edit-address', $type, wc_get_page_permalink( 'myaccount' ) );
	$has_addr = ! empty( $card['formatted'] );
	?>
<div class="bg-surface-container-low p-space-md md:p-space-lg rounded-xl flex flex-col justify-between shadow-sm relative overflow-hidden border border-outline-variant/20 hover:border-secondary-fixed transition-colors">
<div class="absolute top-0 right-0 <?php echo esc_attr( $card['badge_cls'] ); ?> text-[10px] font-bold px-3 py-1 rounded-bl-xl font-label-sm tracking-wider uppercase"><?php echo esc_html( $card['badge'] ); ?></div>
<div>
<div class="flex items-start gap-space-sm mb-space-sm pr-24">
<div class="w-10 h-10 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-[20px]"><?php echo esc_html( $card['icon'] ); ?></span>
</div>
<div>
<h4 class="font-title-md text-title-md text-primary font-bold leading-snug"><?php echo esc_html( $card['title'] ); ?></h4>
<span class="font-body-sm text-body-sm text-outline"><?php echo esc_html( $card['subtitle'] ); ?></span>
</div>
</div>
<?php if ( $has_addr ) : ?>
<div class="bg-surface-container-lowest/80 rounded-lg p-space-md mb-space-md space-y-1.5">
<?php if ( $card['name'] || $card['phone'] ) : ?>
<p class="font-label-md text-label-md text-primary font-bold flex flex-wrap items-center gap-1.5">
<?php if ( $card['name'] ) : ?><span><?php echo esc_html( $card['name'] ); ?></span><?php endif; ?>
<?php if ( $card['name'] && $card['phone'] ) : ?><span class="text-outline">•</span><?php endif; ?>
<?php if ( $card['phone'] ) : ?><span class="text-secondary"><?php echo esc_html( $card['phone'] ); ?></span><?php endif; ?>
</p>
<?php endif; ?>
<div class="font-body-md text-body-md text-on-surface-variant leading-relaxed"><?php echo wp_kses_post( $card['formatted'] ); ?></div>
<?php if ( $card['email'] ) : ?>
<p class="font-body-sm text-body-sm text-outline pt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[15px]">mail</span><span><?php echo esc_html( $card['email'] ); ?></span></p>
<?php endif; ?>
</div>
<?php else : ?>
<div class="bg-surface-container-lowest/60 rounded-lg p-space-md mb-space-md flex items-center gap-space-sm text-on-surface-variant">
<span class="material-symbols-outlined text-outline text-[22px]">add_location_alt</span>
<p class="font-body-sm text-body-sm m-0">Mẹ chưa thiết lập <?php echo esc_html( mb_strtolower( $card['title'] ) ); ?>. Hãy bấm nút bên dưới để lưu sẵn nhé!</p>
</div>
<?php endif; ?>
</div>
<div class="flex flex-wrap items-center justify-between gap-space-xs pt-space-sm bg-surface-container-lowest/70 -mx-space-md md:-mx-space-lg -mb-space-md md:-mb-space-lg px-space-md md:px-space-lg py-3 rounded-b-xl border-t border-outline-variant/15">
<span class="text-[12px] font-body-sm text-on-surface-variant flex items-center gap-1">
<span class="material-symbols-outlined text-[16px] text-secondary"><?php echo esc_html( $card['foot_icon'] ); ?></span>
<span><?php echo esc_html( $card['foot_text'] ); ?></span>
</span>
<a class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-full <?php echo $has_addr ? 'bg-surface-container-high text-primary hover:bg-primary hover:text-on-primary' : 'bg-secondary text-on-secondary hover:bg-secondary-container'; ?> transition-all font-label-sm text-label-sm font-bold shadow-sm whitespace-nowrap" href="<?php echo esc_url( $edit_url ); ?>">
<span class="material-symbols-outlined text-[16px]"><?php echo $has_addr ? 'edit' : 'add'; ?></span>
<span><?php echo $has_addr ? 'Chỉnh sửa' : 'Thêm địa chỉ'; ?></span>
</a>
</div>
</div>
<?php endforeach; ?>
</div>
</section>
	<?php
	return ob_get_clean();
}

function nexxi_kids_account_status_badge( $status ) {
	$colors = array( 'completed' => 'bg-primary-fixed text-primary', 'processing' => 'bg-secondary-fixed text-on-secondary-fixed', 'on-hold' => 'bg-tertiary-fixed text-on-tertiary-fixed', 'pending' => 'bg-tertiary-fixed text-on-tertiary-fixed', 'cancelled' => 'bg-surface-container-high text-on-surface-variant', 'refunded' => 'bg-surface-container-high text-on-surface-variant', 'failed' => 'bg-error-container text-on-error-container' );
	$class = isset( $colors[ $status ] ) ? $colors[ $status ] : 'bg-surface-container-high text-on-surface-variant';
	return '<span class="' . esc_attr( $class ) . ' font-label-sm text-label-sm px-2.5 py-0.5 rounded-full font-bold">' . esc_html( wc_get_order_status_name( $status ) ) . '</span>';
}

function nexxi_kids_account_dashboard( WP_User $user ) {
	$uid = $user->ID;
	$count = nexxi_kids_account_order_count( $uid );
	$spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $uid ) : 0;
	$open = wc_get_orders( array( 'customer_id' => $uid, 'status' => array( 'wc-pending', 'wc-processing', 'wc-on-hold' ), 'return' => 'ids', 'limit' => -1 ) );
	$recent = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 3, 'orderby' => 'date', 'order' => 'DESC' ) );
	$wishlist = nexxi_kids_account_wishlist_count( $uid );
	$orders_url = wc_get_account_endpoint_url( 'orders' );
	$address_url = wc_get_account_endpoint_url( 'edit-address' );
	$account_url = wc_get_account_endpoint_url( 'edit-account' );
	$wishlist_url = nexxi_kids_page_url( 'yeu-thich' );
	$stats = array(
		array( 'shopping_bag', 'Tổng đơn hàng', $count . ' đơn', $orders_url ),
		array( 'local_shipping', 'Đang xử lý', count( $open ) . ' đơn', $orders_url ),
		array( 'payments', 'Đã mua sắm', wc_price( $spent ), $orders_url ),
		array( 'favorite', 'Mẫu yêu thích', $wishlist . ' mẫu', $wishlist_url ? $wishlist_url : $orders_url ),
	);
	$customer = new WC_Customer( $uid );
	$addresses = array(
		'billing'  => array( 'Địa chỉ thanh toán', 'receipt_long', wc_get_account_formatted_address( 'billing' ), $customer->get_billing_phone() ),
	);
	if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
		$addresses['shipping'] = array( 'Địa chỉ giao hàng', 'home', wc_get_account_formatted_address( 'shipping' ), $customer->get_shipping_phone() );
	}
	ob_start(); ?>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'Mục 01', 'Tổng Quan Tài Khoản' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-space-sm">
<?php foreach ( $stats as $stat ) : ?>
<a class="bg-surface-container-low hover:bg-surface-container-high/60 rounded-lg p-space-md flex flex-col gap-1 transition-colors" href="<?php echo esc_url( $stat[3] ); ?>"><span class="w-9 h-9 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo esc_html( $stat[0] ); ?></span></span><span class="font-label-sm text-label-sm text-outline mt-1"><?php echo esc_html( $stat[1] ); ?></span><span class="font-title-md text-title-md text-primary font-bold"><?php echo wp_kses_post( $stat[2] ); ?></span></a>
<?php endforeach; ?>
</div>
</section>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'Mục 02', 'Đơn Hàng Gần Đây', $count ? '<a class="inline-flex items-center gap-1 font-label-md text-label-md text-secondary font-bold hover:underline self-start sm:self-auto" href="' . esc_url( $orders_url ) . '">Xem tất cả <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php if ( $recent ) : ?>
<div class="flex flex-col gap-space-sm">
<?php foreach ( $recent as $order ) :
	$items = $order->get_items();
	$first = $items ? reset( $items ) : null;
	$thumb = $first && $first->get_product() ? $first->get_product()->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover' ) ) : '';
	$more = count( $items ) > 1 ? ' và ' . ( count( $items ) - 1 ) . ' sản phẩm khác' : ''; ?>
<a class="flex items-center gap-space-md p-space-md bg-surface-container-low rounded-lg hover:bg-surface-container-high/60 transition-colors" href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
<span class="w-14 h-16 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0"><?php echo wp_kses_post( $thumb ); ?></span>
<span class="flex-1 min-w-0 flex flex-col gap-0.5"><span class="flex flex-wrap items-center gap-space-xs"><span class="font-label-lg text-label-lg text-primary">Đơn #<?php echo esc_html( $order->get_order_number() ); ?></span><?php echo nexxi_kids_account_status_badge( $order->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1"><?php echo esc_html( ( $first ? $first->get_name() : '' ) . $more ); ?></span><span class="font-body-sm text-body-sm text-outline"><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></span></span>
<span class="text-right shrink-0"><span class="block font-title-md text-title-md text-secondary font-bold"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span><span class="font-label-sm text-label-sm text-primary">Xem chi tiết →</span></span>
</a>
<?php endforeach; ?>
</div>
<?php else : ?>
<div class="bg-surface-container-low rounded-lg p-space-lg text-center flex flex-col items-center gap-space-sm"><span class="material-symbols-outlined text-secondary text-[36px]">shopping_bag</span><p class="font-body-md text-body-md text-on-surface-variant">Mẹ chưa có đơn hàng nào. Cùng chọn những bộ đồ xinh cho bé nhé!</p><a class="bg-secondary text-on-secondary px-space-lg py-2.5 rounded-full font-label-md text-label-md hover:bg-secondary-container transition-all shadow-md" href="<?php echo esc_url( nexxi_kids_shop_url() ); ?>">Khám phá sản phẩm</a></div>
<?php endif; ?>
</section>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'Mục 03', 'Sổ Địa Chỉ Nhận Hàng', '<a class="inline-flex items-center gap-space-xs bg-primary text-on-primary px-space-md py-space-xs rounded-full font-label-md text-label-md hover:bg-primary-container transition-all shadow-sm self-start sm:self-auto" href="' . esc_url( $address_url ) . '"><span class="material-symbols-outlined text-[18px]">edit_location_alt</span><span>Quản lý địa chỉ</span></a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<?php foreach ( $addresses as $type => $address ) : ?>
<div class="bg-surface-container-low p-space-md rounded-xl flex flex-col justify-between shadow-sm relative overflow-hidden">
<?php if ( 'shipping' === $type || 1 === count( $addresses ) ) : ?><div class="absolute top-0 right-0 bg-secondary text-on-secondary text-[11px] font-bold px-3 py-1 rounded-bl-xl font-label-sm tracking-wide">MẶC ĐỊNH</div><?php endif; ?>
<div>
<div class="flex items-center gap-space-xs mb-2"><span class="material-symbols-outlined text-secondary text-[20px]"><?php echo esc_html( $address[1] ); ?></span><span class="font-label-lg text-label-lg text-primary font-bold"><?php echo esc_html( $address[0] ); ?></span></div>
<?php if ( $address[2] ) : ?><p class="font-body-md text-body-md text-on-surface-variant mb-1"><?php echo wp_kses_post( $address[2] ); ?></p><?php if ( $address[3] ) : ?><p class="font-label-md text-label-md text-on-surface mb-space-md"><?php echo esc_html( $address[3] ); ?></p><?php endif; ?>
<?php else : ?><p class="font-body-md text-body-md text-outline mb-space-md">Mẹ chưa lưu địa chỉ này.</p><?php endif; ?>
</div>
<div class="flex items-center justify-end pt-space-sm bg-surface-container-lowest/60 -mx-space-md -mb-space-md px-space-md py-space-xs rounded-b-xl"><a class="px-3 py-1 rounded-full text-primary hover:bg-surface-container-high transition-colors font-label-sm text-label-sm" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $type, wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php echo $address[2] ? 'Sửa' : '+ Thêm địa chỉ'; ?></a></div>
</div>
<?php endforeach; ?>
</div>
</section>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'Mục 04', 'Thông Tin & Bảo Mật Tài Khoản' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<a class="flex items-start gap-space-md p-space-md bg-surface-container-low rounded-lg hover:bg-surface-container-high/60 transition-colors" href="<?php echo esc_url( $account_url ); ?>"><span class="w-10 h-10 rounded-full bg-primary-fixed flex items-center justify-center text-primary shrink-0"><span class="material-symbols-outlined text-[20px]">manage_accounts</span></span><span><span class="block font-label-lg text-label-lg text-primary">Thông tin cá nhân</span><span class="block font-body-sm text-body-sm text-on-surface-variant">Cập nhật họ tên, tên hiển thị và email nhận hóa đơn.</span></span></a>
<a class="flex items-start gap-space-md p-space-md bg-surface-container-low rounded-lg hover:bg-surface-container-high/60 transition-colors" href="<?php echo esc_url( $account_url ); ?>#password_current"><span class="w-10 h-10 rounded-full bg-secondary-fixed flex items-center justify-center text-secondary shrink-0"><span class="material-symbols-outlined text-[20px]">lock</span></span><span><span class="block font-label-lg text-label-lg text-primary">Đổi mật khẩu</span><span class="block font-body-sm text-body-sm text-on-surface-variant">Đặt mật khẩu mới để bảo vệ tài khoản của mẹ.</span></span></a>
</div>
</section>
	<?php
	return ob_get_clean();
}

/* ------------------------------------------------------------------ Billing & Shipping address field rules */

// Phone field is always required across WooCommerce forms.
add_filter( 'pre_option_woocommerce_checkout_phone_field', static function () {
	return 'required';
} );

// Default country is always Vietnam (VN).
add_filter( 'woocommerce_countries_base_country', static function () { return 'VN'; } );
add_filter( 'default_checkout_billing_country', static function () { return 'VN'; } );
add_filter( 'default_checkout_shipping_country', static function () { return 'VN'; } );
add_filter( 'woocommerce_customer_get_billing_country', static function ( $value ) { return $value ? $value : 'VN'; } );
add_filter( 'woocommerce_customer_get_shipping_country', static function ( $value ) { return $value ? $value : 'VN'; } );
add_filter( 'woocommerce_my_account_edit_address_field_value', static function ( $value, $key ) {
	if ( in_array( $key, array( 'billing_country', 'shipping_country' ), true ) && empty( $value ) ) {
		return 'VN';
	}
	return $value;
}, 10, 2 );

// Remove postcode from default address fields and set default country to VN.
add_filter( 'woocommerce_default_address_fields', static function ( $fields ) {
	unset( $fields['postcode'] );
	if ( isset( $fields['country'] ) ) {
		$fields['country']['default'] = 'VN';
	}
	return $fields;
}, 20 );

// Keep postcode hidden/optional in WooCommerce's frontend country locale JS (wc-address-i18n).
add_filter( 'woocommerce_get_country_locale', static function ( $locale ) {
	foreach ( array_keys( $locale ) as $country ) {
		$locale[ $country ]['postcode']['required'] = false;
		$locale[ $country ]['postcode']['hidden']   = true;
	}
	$locale['VN']['postcode'] = array( 'required' => false, 'hidden' => true );
	$locale['VN']['state']    = array( 'required' => false );
	return $locale;
}, 20 );

// Billing fields: remove postcode, default country VN, phone required.
add_filter( 'woocommerce_billing_fields', static function ( $fields ) {
	unset( $fields['billing_postcode'] );
	if ( isset( $fields['billing_country'] ) ) {
		$fields['billing_country']['default'] = 'VN';
	}
	if ( isset( $fields['billing_phone'] ) ) {
		$fields['billing_phone']['required'] = true;
	}
	return $fields;
}, 20 );

// Shipping fields: remove postcode, default country VN, ensure shipping_phone exists & is required.
add_filter( 'woocommerce_shipping_fields', static function ( $fields ) {
	unset( $fields['shipping_postcode'] );
	if ( isset( $fields['shipping_country'] ) ) {
		$fields['shipping_country']['default'] = 'VN';
	}
	if ( ! isset( $fields['shipping_phone'] ) ) {
		$fields['shipping_phone'] = array(
			'label'        => 'Số điện thoại',
			'required'     => true,
			'type'         => 'tel',
			'class'        => array( 'form-row-wide' ),
			'validate'     => array( 'phone' ),
			'autocomplete' => 'tel',
			'priority'     => 100,
		);
	} else {
		$fields['shipping_phone']['required'] = true;
	}
	return $fields;
}, 20 );

// My Account → Edit Address (/tai-khoan/edit-address/{billing|shipping}): enforce no postcode, required phone, and default VN.
add_filter( 'woocommerce_address_to_edit', static function ( $address, $load_address ) {
	$load_address = in_array( $load_address, array( 'billing', 'shipping' ), true ) ? $load_address : 'billing';
	unset( $address[ $load_address . '_postcode' ] );

	$country_key = $load_address . '_country';
	if ( isset( $address[ $country_key ] ) ) {
		$address[ $country_key ]['default'] = 'VN';
		if ( empty( $address[ $country_key ]['value'] ) ) {
			$address[ $country_key ]['value'] = 'VN';
		}
	}

	$phone_key = $load_address . '_phone';
	if ( ! isset( $address[ $phone_key ] ) ) {
		$uid = get_current_user_id();
		$address[ $phone_key ] = array(
			'label'        => 'Số điện thoại',
			'required'     => true,
			'type'         => 'tel',
			'class'        => array( 'form-row-wide' ),
			'validate'     => array( 'phone' ),
			'autocomplete' => 'tel',
			'priority'     => 100,
			'value'        => $uid ? get_user_meta( $uid, $phone_key, true ) : '',
		);
	} else {
		$address[ $phone_key ]['required'] = true;
	}

	return $address;
}, 20, 2 );

