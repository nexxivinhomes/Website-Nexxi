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
	$html = nexxi_dom_set_inner( $html, '<aside class="lg:col-span-4', nexxi_kids_account_sidebar( $user ) );
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
<div class="w-8 h-8 rounded-full bg-secondary-fixed flex items-center justify-center text-secondary"><span class="material-symbols-outlined text-[18px]">verified</span></div>
<div class="flex flex-col"><span class="font-label-sm text-label-sm text-outline uppercase tracking-wider">Thành Viên NEXXI Club</span><span class="font-label-md text-label-md text-primary font-bold"><?php echo $since ? esc_html( 'Đồng hành từ ' . $since ) : 'Chào mừng mẹ'; ?></span></div>
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

/* ------------------------------------------------------------------ NEXXI Member Points (Điểm thành viên NEXXI) */

/**
 * Calculate NEXXI member points for an order or amount (default: 10.000₫ = 1 điểm, min 1 điểm).
 *
 * @param WC_Order|float|int $order_or_total Order object or total amount in VND.
 * @return int Points earned.
 */
function nexxi_kids_calculate_order_points( $order_or_total ) {
	$total = ( $order_or_total instanceof WC_Order ) ? (float) $order_or_total->get_total() : (float) $order_or_total;
	if ( $total <= 0 ) {
		return 0;
	}
	$unit = (int) apply_filters( 'nexxi_kids_points_per_unit', 10000 );
	if ( $unit <= 0 ) {
		$unit = 10000;
	}
	return max( 1, (int) floor( $total / $unit ) );
}

/**
 * Award NEXXI member points for an order to the given user ID (idempotent per order).
 *
 * @param WC_Order $order   WooCommerce order.
 * @param int      $user_id Target WordPress user ID.
 * @return int Points awarded for this order.
 */
function nexxi_kids_award_order_points( WC_Order $order, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : (int) $order->get_customer_id();
	if ( $user_id <= 0 ) {
		return 0;
	}
	if ( 'yes' === (string) $order->get_meta( '_nexxi_points_awarded' ) ) {
		return (int) $order->get_meta( '_nexxi_points_earned' );
	}
	$points = nexxi_kids_calculate_order_points( $order );
	if ( $points <= 0 ) {
		return 0;
	}

	$current = (int) get_user_meta( $user_id, 'nexxi_member_points', true );
	$new_bal = max( 0, $current + $points );
	update_user_meta( $user_id, 'nexxi_member_points', $new_bal );

	$history = get_user_meta( $user_id, 'nexxi_points_history', true );
	$history = is_array( $history ) ? $history : array();
	array_unshift(
		$history,
		array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'points'       => $points,
			'total'        => (float) $order->get_total(),
			'date'         => current_time( 'mysql' ),
		)
	);
	update_user_meta( $user_id, 'nexxi_points_history', array_slice( $history, 0, 50 ) );

	$order->update_meta_data( '_nexxi_points_earned', $points );
	$order->update_meta_data( '_nexxi_points_awarded', 'yes' );
	$order->update_meta_data( '_nexxi_points_user_id', $user_id );
	$order->save_meta_data();

	$u = get_userdata( $user_id );
	$email_label = $u ? $u->user_email : ( '#' . $user_id );
	$order->add_order_note( sprintf( 'NEXXI Club: Đã cộng +%d điểm thành viên vào tài khoản %s (Tổng điểm: %d).', $points, $email_label, $new_bal ) );

	return $points;
}

/**
 * Get current NEXXI member points for a user (backfills from existing orders if not synced yet).
 *
 * @param int $user_id WordPress user ID.
 * @return int Current points balance.
 */
function nexxi_kids_get_user_points( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}
	if ( '1' !== (string) get_user_meta( $user_id, 'nexxi_points_backfilled', true ) && function_exists( 'wc_get_orders' ) ) {
		update_user_meta( $user_id, 'nexxi_points_backfilled', '1' );
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ),
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);
		foreach ( $orders as $ord ) {
			if ( $ord instanceof WC_Order && 'yes' !== (string) $ord->get_meta( '_nexxi_points_awarded' ) ) {
				nexxi_kids_award_order_points( $ord, $user_id );
			}
		}
	}
	return max( 0, (int) get_user_meta( $user_id, 'nexxi_member_points', true ) );
}

function nexxi_kids_account_sidebar( WP_User $user ) {
	$orders = nexxi_kids_account_order_count( $user->ID );
	$wishlist = nexxi_kids_account_wishlist_count( $user->ID );
	$points = nexxi_kids_get_user_points( $user->ID );
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
<div class="relative mb-space-sm"><div class="w-24 h-24 rounded-full bg-surface-container-high p-1 shadow-inner"><img class="w-full h-full object-cover rounded-full shadow-sm" src="<?php echo esc_url( get_avatar_url( $user->ID, array( 'size' => 192 ) ) ); ?>" alt="<?php echo esc_attr( nexxi_kids_account_name( $user ) ); ?>"></div></div>
<h2 class="font-headline-sm text-headline-sm text-primary mb-1"><?php echo esc_html( nexxi_kids_account_name( $user ) ); ?></h2>
<p class="font-body-sm text-body-sm text-on-surface-variant mb-space-sm break-all"><?php echo esc_html( $user->user_email ); ?></p>
<div class="w-full grid grid-cols-3 gap-space-xs pt-space-sm mt-space-xs bg-surface-container-low rounded-lg p-space-sm">
<div class="flex flex-col items-center"><span class="font-label-sm text-label-sm text-outline">Đơn hàng</span><span class="font-label-md text-label-md text-primary font-bold"><?php echo (int) $orders; ?> đơn</span></div>
<div class="flex flex-col items-center border-x border-outline-variant/25 px-1"><span class="font-label-sm text-label-sm text-outline">Điểm NEXXI</span><span class="font-label-md text-label-md text-secondary font-bold"><?php echo esc_html( number_format_i18n( $points ) ); ?> điểm</span></div>
<div class="flex flex-col items-center"><span class="font-label-sm text-label-sm text-outline">Yêu thích</span><span class="font-label-md text-label-md text-primary font-bold" data-nexxi-wishlist-count><?php echo (int) $wishlist; ?></span></div>
</div>
</div>
<nav class="bg-surface-container-lowest rounded-xl p-space-sm shadow-sm flex flex-col gap-1" aria-label="Tài khoản">
<?php echo $items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="pt-space-xs mt-space-xs"><a class="w-full flex items-center gap-space-sm px-space-md py-space-sm rounded-lg text-error hover:bg-error-container/40 transition-colors text-left font-label-md text-label-md" href="<?php echo esc_url( wc_logout_url() ); ?>"><span class="material-symbols-outlined text-[20px]">logout</span><span>Đăng xuất tài khoản</span></a></div>
</nav>
<?php
	$hotline = nexxi_kids_setting( 'nexxi_kids_hotline' );
	$hours   = nexxi_kids_setting( 'nexxi_kids_hours' );
	$chat    = nexxi_kids_setting( 'nexxi_kids_chat_url' );
?>
<!-- Quick Assistance Box -->
<div class="bg-surface-container-low rounded-xl p-space-md flex flex-col gap-space-xs">
<div class="flex items-center justify-between gap-space-xs">
	<div class="flex items-center gap-space-xs text-primary font-bold font-title-md text-title-md">
		<span class="material-symbols-outlined text-secondary">support_agent</span>
		<span>Chuyên viên tư vấn size</span>
	</div>
	<?php if ( $hours ) : ?>
		<span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-sm text-[11px] font-bold">
			<span class="material-symbols-outlined text-[13px]">schedule</span>
			<?php echo esc_html( $hours ); ?>
		</span>
	<?php endif; ?>
</div>
<p class="font-body-sm text-body-sm text-on-surface-variant">Mẹ băn khoăn về bảng size cho bé hoặc cần hỗ trợ đơn hàng? Đội ngũ NEXXI sẵn sàng tư vấn trong khung giờ <?php echo esc_html( $hours ? $hours : '8h - 17h' ); ?>.</p>
<div class="flex flex-col gap-2 pt-1">
	<?php if ( $hotline ) : ?>
		<a class="inline-flex items-center justify-between px-space-md py-2 rounded-lg bg-surface-container-lowest hover:bg-secondary-fixed/50 text-primary font-label-md text-label-md font-bold transition-colors shadow-sm" href="<?php echo esc_url( nexxi_kids_tel( $hotline ) ); ?>">
			<span class="inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-secondary text-[18px]">call</span><span>Hotline: <?php echo esc_html( $hotline ); ?></span></span>
			<span class="material-symbols-outlined text-[16px] text-secondary">arrow_forward</span>
		</a>
	<?php endif; ?>
	<?php if ( $chat ) : ?>
		<a class="inline-flex items-center justify-between px-space-md py-2 rounded-lg bg-secondary-container hover:bg-secondary text-on-secondary font-label-md text-label-md font-bold transition-colors shadow-sm" href="<?php echo esc_url( $chat ); ?>" target="_blank" rel="noopener">
			<span class="inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-[18px]">forum</span><span>Nhắn tin Zalo tư vấn</span></span>
			<span class="material-symbols-outlined text-[16px]">open_in_new</span>
		</a>
	<?php endif; ?>
</div>
</div>
	<?php
	return ob_get_clean();
}

function nexxi_kids_account_content( WP_User $user, $endpoint ) {
	ob_start();
	echo '<div class="nexxi-account-notices">';
	wc_print_notices();
	echo '</div>';
	if ( ! $endpoint ) {
		echo nexxi_kids_account_dashboard( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( 'edit-address' === $endpoint ) {
		echo nexxi_kids_account_addresses_view( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		$title = WC()->query->get_endpoint_title( $endpoint );
		echo '<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">' . nexxi_kids_account_section_head( 'NEXXI Club', wp_strip_all_tags( $title ? $title : 'Tài khoản' ) ) . '<div class="woocommerce nexxi-account">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		do_action( 'woocommerce_account_content' );
		echo '</div></section>';
	}
	return ob_get_clean();
}

/* ------------------------------------------------------------------ Vietnam 2025 Administrative Divisions (34 Tỉnh/Thành phố & Phường/Xã mới) */

/**
 * Load the 34 provinces/cities and their wards/communes after Vietnam's 2025 administrative reform.
 *
 * @return array<int,array{name:string,wards:string[]}>
 */
function nexxi_kids_vn_addresses_2025() {
	static $data = null;
	if ( null !== $data ) {
		return $data;
	}
	$file = get_template_directory() . '/assets/stitch/vn-addresses-2025.json';
	if ( is_readable( $file ) ) {
		$raw     = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded = json_decode( (string) $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded ) ) {
			$data = $decoded;
			return $data;
		}
	}
	$data = array();
	return $data;
}

/**
 * Options array for the Province / City (Tỉnh / Thành phố) dropdown select.
 *
 * @param string $current_val Optional current value to preserve if legacy.
 * @return array<string,string>
 */
function nexxi_kids_vn_provinces_options( $current_val = '' ) {
	$opts = array( '' => '-- Chọn Tỉnh / Thành phố --' );
	foreach ( nexxi_kids_vn_addresses_2025() as $prov ) {
		if ( ! empty( $prov['name'] ) ) {
			$name          = (string) $prov['name'];
			$opts[ $name ] = $name;
		}
	}
	$current_val = trim( (string) $current_val );
	if ( '' !== $current_val && ! isset( $opts[ $current_val ] ) ) {
		$opts[ $current_val ] = $current_val;
	}
	return $opts;
}

/**
 * Options array for the Ward / Commune (Phường / Xã) dropdown select.
 * Strictly requires a Province/City (Tỉnh / Thành phố) to be selected first.
 *
 * @param string $city_val Current province/city value.
 * @param string $ward_val Current ward value.
 * @return array<string,string>
 */
function nexxi_kids_vn_wards_options( $city_val = '', $ward_val = '' ) {
	$city_val = trim( (string) $city_val );
	$ward_val = trim( (string) $ward_val );

	if ( '' === $city_val && ! empty( $_POST['billing_city'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$city_val = sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	if ( '' === $ward_val && ! empty( $_POST['billing_ward'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ward_val = sanitize_text_field( wp_unslash( $_POST['billing_ward'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	if ( '' === $city_val ) {
		$opts = array( '' => '-- Vui lòng chọn Tỉnh / Thành phố trước --' );
		if ( '' !== $ward_val ) {
			$opts[ $ward_val ] = $ward_val;
		}
		return $opts;
	}

	$opts = array( '' => '-- Chọn Phường / Xã --' );
	foreach ( nexxi_kids_vn_addresses_2025() as $prov ) {
		if ( empty( $prov['name'] ) || empty( $prov['wards'] ) || ! is_array( $prov['wards'] ) ) {
			continue;
		}
		if ( 0 === strcasecmp( (string) $prov['name'], $city_val ) ) {
			foreach ( $prov['wards'] as $w ) {
				$w          = (string) $w;
				$opts[ $w ] = $w;
			}
			break;
		}
	}

	if ( '' !== $ward_val && ! isset( $opts[ $ward_val ] ) ) {
		$opts[ $ward_val ] = $ward_val;
	}
	if ( ! empty( $_POST['shipping_ward'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sw          = sanitize_text_field( wp_unslash( $_POST['shipping_ward'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$opts[ $sw ] = $sw;
	}
	return $opts;
}

/* ------------------------------------------------------------------ Multi-Address Book (Unified "Địa chỉ") */

/** Icon name for an address label. */
function nexxi_kids_address_icon( $label ) {
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $label ) : strtolower( (string) $label );
	if ( false !== strpos( $lower, 'cơ quan' ) || false !== strpos( $lower, 'văn phòng' ) || false !== strpos( $lower, 'công ty' ) ) {
		return 'apartment';
	}
	if ( false !== strpos( $lower, 'ông bà' ) || false !== strpos( $lower, 'quê' ) ) {
		return 'holiday_village';
	}
	return 'home';
}

/** Retrieve saved address book for a user; migrates existing WC billing/shipping address if empty. */
function nexxi_kids_get_address_book( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return array();
	}
	$book = get_user_meta( $user_id, 'nexxi_address_book', true );
	if ( is_array( $book ) && ! empty( $book ) ) {
		return array_values( $book );
	}
	// Migrate existing WooCommerce address if present.
	$migrated = array();
	$customer = new WC_Customer( $user_id );
	$ship_addr = trim( $customer->get_shipping_address_1() );
	$bill_addr = trim( $customer->get_billing_address_1() );
	$ship_ward = trim( (string) get_user_meta( $user_id, 'shipping_ward', true ) );
	$bill_ward = trim( (string) get_user_meta( $user_id, 'billing_ward', true ) );

	if ( '' !== $ship_addr ) {
		$name = trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() );
		$phone = method_exists( $customer, 'get_shipping_phone' ) ? $customer->get_shipping_phone() : '';
		$migrated[] = array(
			'id'         => 'addr_' . substr( md5( 'shipping_' . $user_id ), 0, 8 ),
			'label'      => 'Nhà riêng',
			'recipient'  => $name ? $name : trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
			'phone'      => $phone ? $phone : $customer->get_billing_phone(),
			'country'    => $customer->get_shipping_country() ? $customer->get_shipping_country() : 'VN',
			'city'       => $customer->get_shipping_city(),
			'ward'       => $ship_ward ? $ship_ward : $bill_ward,
			'address_1'  => $ship_addr,
			'address_2'  => '',
			'is_default' => true,
		);
	}
	if ( '' !== $bill_addr && $bill_addr !== $ship_addr ) {
		$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
		$migrated[] = array(
			'id'         => 'addr_' . substr( md5( 'billing_' . $user_id ), 0, 8 ),
			'label'      => empty( $migrated ) ? 'Nhà riêng' : 'Địa chỉ khác',
			'recipient'  => $name,
			'phone'      => $customer->get_billing_phone(),
			'country'    => $customer->get_billing_country() ? $customer->get_billing_country() : 'VN',
			'city'       => $customer->get_billing_city(),
			'ward'       => $bill_ward,
			'address_1'  => $bill_addr,
			'address_2'  => '',
			'is_default' => empty( $migrated ),
		);
	}
	if ( $migrated ) {
		update_user_meta( $user_id, 'nexxi_address_book', $migrated );
	}
	return $migrated;
}

/** Sync the selected/default address into WooCommerce's billing_* and shipping_* user meta. */
function nexxi_kids_sync_wc_address( $user_id, array $addr ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return;
	}
	$user = get_userdata( $user_id );
	$recipient = trim( isset( $addr['recipient'] ) ? (string) $addr['recipient'] : '' );
	$phone     = trim( isset( $addr['phone'] ) ? (string) $addr['phone'] : '' );
	$country   = ! empty( $addr['country'] ) ? (string) $addr['country'] : 'VN';
	$city      = trim( isset( $addr['city'] ) ? (string) $addr['city'] : '' );
	$ward      = trim( isset( $addr['ward'] ) ? (string) $addr['ward'] : '' );
	$address_1 = trim( isset( $addr['address_1'] ) ? (string) $addr['address_1'] : '' );
	$email     = $user ? $user->user_email : '';

	foreach ( array( 'billing', 'shipping' ) as $prefix ) {
		update_user_meta( $user_id, "{$prefix}_first_name", $recipient );
		update_user_meta( $user_id, "{$prefix}_last_name", '' );
		update_user_meta( $user_id, "{$prefix}_phone", $phone );
		update_user_meta( $user_id, "{$prefix}_country", $country );
		update_user_meta( $user_id, "{$prefix}_city", $city );
		update_user_meta( $user_id, "{$prefix}_ward", $ward );
		update_user_meta( $user_id, "{$prefix}_address_1", $address_1 );
		update_user_meta( $user_id, "{$prefix}_address_2", '' );
		update_user_meta( $user_id, "{$prefix}_postcode", '' );
	}
	if ( $email && ! get_user_meta( $user_id, 'billing_email', true ) ) {
		update_user_meta( $user_id, 'billing_email', $email );
	}
}

/** Save the address book and sync the default entry with WooCommerce. */
function nexxi_kids_save_address_book( $user_id, array $book ) {
	$book = array_values( $book );
	if ( $book ) {
		$has_default = false;
		foreach ( $book as $item ) {
			if ( ! empty( $item['is_default'] ) ) {
				$has_default = true;
				break;
			}
		}
		if ( ! $has_default ) {
			$book[0]['is_default'] = true;
		}
		foreach ( $book as $item ) {
			if ( ! empty( $item['is_default'] ) ) {
				nexxi_kids_sync_wc_address( $user_id, $item );
				break;
			}
		}
	}
	update_user_meta( (int) $user_id, 'nexxi_address_book', $book );
}

/** Handle POST actions for adding, editing, deleting, or selecting a saved shipping address. */
function nexxi_kids_handle_address_book_actions() {
	if ( ! is_user_logged_in() || empty( $_POST['nexxi_addr_action'] ) ) {
		return;
	}
	if ( ! isset( $_POST['nexxi_addr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_addr_nonce'] ) ), 'nexxi_addr_book' ) ) {
		return;
	}
	$uid    = get_current_user_id();
	$action = sanitize_key( wp_unslash( $_POST['nexxi_addr_action'] ) );
	$book   = nexxi_kids_get_address_book( $uid );
	$redirect = ! empty( $_POST['nexxi_addr_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['nexxi_addr_redirect'] ) ) : wc_get_account_endpoint_url( 'edit-address' );

	if ( 'save' === $action ) {
		$id        = ! empty( $_POST['addr_id'] ) ? sanitize_key( wp_unslash( $_POST['addr_id'] ) ) : '';
		$label     = ! empty( $_POST['addr_label'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_label'] ) ) : 'Nhà riêng';
		$recipient = ! empty( $_POST['addr_recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_recipient'] ) ) : '';
		$phone     = ! empty( $_POST['addr_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_phone'] ) ) : '';
		$country   = ! empty( $_POST['addr_country'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_country'] ) ) : 'VN';
		$city      = ! empty( $_POST['addr_city'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_city'] ) ) : '';
		$ward      = ! empty( $_POST['addr_ward'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_ward'] ) ) : '';
		$address_1 = ! empty( $_POST['addr_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_address_1'] ) ) : '';
		$make_def  = ! empty( $_POST['addr_is_default'] ) || empty( $book );

		if ( '' === $recipient || '' === $phone || '' === $city || '' === $ward || '' === $address_1 ) {
			wc_add_notice( 'Vui lòng điền đầy đủ Họ tên người nhận, Số điện thoại, Địa chỉ, Phường/Xã và Tỉnh/Thành phố.', 'error' );
			return;
		}

		if ( $make_def ) {
			foreach ( $book as &$b ) {
				$b['is_default'] = false;
			}
			unset( $b );
		}

		$entry = array(
			'id'         => $id ? $id : 'addr_' . strtolower( wp_generate_password( 8, false, false ) ),
			'label'      => $label ? $label : 'Nhà riêng',
			'recipient'  => $recipient,
			'phone'      => $phone,
			'country'    => $country ? $country : 'VN',
			'city'       => $city,
			'ward'       => $ward,
			'address_1'  => $address_1,
			'address_2'  => '',
			'is_default' => (bool) $make_def,
		);

		$updated = false;
		foreach ( $book as $idx => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $entry['id'] ) {
				$book[ $idx ] = $entry;
				$updated      = true;
				break;
			}
		}
		if ( ! $updated ) {
			$book[] = $entry;
		}
		nexxi_kids_save_address_book( $uid, $book );
		wc_add_notice( 'Đã lưu địa chỉ nhận hàng thành công!', 'success' );
		wp_safe_redirect( remove_query_arg( array( 'nexxi_addr_edit' ), $redirect ) );
		exit;
	}

	if ( 'set_default' === $action && ! empty( $_POST['addr_id'] ) ) {
		$target = sanitize_key( wp_unslash( $_POST['addr_id'] ) );
		foreach ( $book as &$b ) {
			$b['is_default'] = ( isset( $b['id'] ) && $b['id'] === $target );
		}
		unset( $b );
		nexxi_kids_save_address_book( $uid, $book );
		wc_add_notice( 'Đã chọn làm địa chỉ giao hàng mặc định!', 'success' );
		wp_safe_redirect( $redirect );
		exit;
	}

	if ( 'delete' === $action && ! empty( $_POST['addr_id'] ) ) {
		$target = sanitize_key( wp_unslash( $_POST['addr_id'] ) );
		$book   = array_values( array_filter( $book, static function ( $b ) use ( $target ) {
			return ! isset( $b['id'] ) || $b['id'] !== $target;
		} ) );
		nexxi_kids_save_address_book( $uid, $book );
		wc_add_notice( 'Đã xóa địa chỉ khỏi sổ địa chỉ.', 'success' );
		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'template_redirect', 'nexxi_kids_handle_address_book_actions', 5 );

/** Format a single address entry as a readable 1-2 line string. */
function nexxi_kids_format_address_entry( array $addr ) {
	$parts = array_filter( array(
		isset( $addr['address_1'] ) ? trim( $addr['address_1'] ) : '',
		isset( $addr['ward'] ) ? trim( $addr['ward'] ) : '',
		isset( $addr['city'] ) ? trim( $addr['city'] ) : '',
	) );
	return implode( ', ', $parts );
}

/** Render the grid of saved address cards (reused in /tai-khoan/edit-address/ and Dashboard Mục 03). */
function nexxi_kids_render_address_cards( array $book, $base_url ) {
	if ( empty( $book ) ) {
		$add_url = add_query_arg( 'nexxi_addr_edit', 'new', $base_url );
		ob_start(); ?>
<div class="bg-surface-container-low rounded-xl p-space-lg text-center flex flex-col items-center gap-space-sm">
<div class="w-12 h-12 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[26px]">add_location_alt</span></div>
<h4 class="font-title-md text-title-md text-primary font-bold">Mẹ chưa lưu địa chỉ nhận hàng nào</h4>
<p class="font-body-md text-body-md text-on-surface-variant max-w-md">Lưu sẵn một hoặc nhiều địa chỉ (Nhà riêng, Cơ quan, Nhà ông bà...) để chọn nhanh chỉ với 1 chạm khi đặt đồ cho bé.</p>
<a class="inline-flex items-center gap-1.5 bg-secondary text-on-secondary px-space-lg py-2.5 rounded-full font-label-md text-label-md font-bold hover:bg-secondary-container transition-all shadow-md mt-1" href="<?php echo esc_url( $add_url ); ?>">
<span class="material-symbols-outlined text-[18px]">add</span>
<span>Thêm địa chỉ đầu tiên</span>
</a>
</div>
		<?php
		return ob_get_clean();
	}

	ob_start(); ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<?php foreach ( $book as $addr ) :
	$id     = isset( $addr['id'] ) ? $addr['id'] : '';
	$is_def = ! empty( $addr['is_default'] );
	$label  = ! empty( $addr['label'] ) ? $addr['label'] : 'Nhà riêng';
	$icon   = nexxi_kids_address_icon( $label );
	$edit_u = add_query_arg( 'nexxi_addr_edit', $id, $base_url );
	?>
<div class="bg-surface-container-low p-space-md md:p-space-lg rounded-xl flex flex-col justify-between shadow-sm relative overflow-hidden border <?php echo $is_def ? 'border-secondary/40 ring-1 ring-secondary/20' : 'border-outline-variant/20 hover:border-secondary-fixed'; ?> transition-all">
<?php if ( $is_def ) : ?>
<div class="absolute top-0 right-0 bg-secondary text-on-secondary text-[10px] font-bold px-3 py-1 rounded-bl-xl font-label-sm tracking-wider uppercase flex items-center gap-1">
<span class="material-symbols-outlined text-[13px]">check_circle</span>
<span>Địa chỉ giao hàng đang chọn</span>
</div>
<?php endif; ?>
<div>
<div class="flex items-center gap-space-xs mb-2 <?php echo $is_def ? 'pr-40' : ''; ?>">
<span class="w-8 h-8 rounded-full <?php echo $is_def ? 'bg-secondary-fixed text-secondary' : 'bg-surface-container-high text-primary'; ?> flex items-center justify-center shrink-0">
<span class="material-symbols-outlined text-[18px]"><?php echo esc_html( $icon ); ?></span>
</span>
<span class="font-label-lg text-label-lg text-primary font-bold"><?php echo esc_html( $label ); ?></span>
</div>
<p class="font-label-md text-label-md text-on-surface font-bold mb-1 flex flex-wrap items-center gap-1.5">
<span><?php echo esc_html( $addr['recipient'] ); ?></span>
<?php if ( ! empty( $addr['phone'] ) ) : ?>
<span class="text-outline">•</span>
<span class="text-secondary"><?php echo esc_html( $addr['phone'] ); ?></span>
<?php endif; ?>
</p>
<p class="font-body-md text-body-md text-on-surface-variant mb-space-md leading-relaxed">
<?php echo esc_html( nexxi_kids_format_address_entry( $addr ) ); ?>
</p>
</div>
<div class="flex flex-wrap items-center justify-between gap-2 pt-space-sm bg-surface-container-lowest/70 -mx-space-md md:-mx-space-lg -mb-space-md md:-mb-space-lg px-space-md md:px-space-lg py-2.5 rounded-b-xl border-t border-outline-variant/15">
<?php if ( $is_def ) : ?>
<span class="text-[12px] font-body-sm text-secondary font-semibold flex items-center gap-1">
<span class="material-symbols-outlined text-[16px]">verified</span>
<span>Tự động áp dụng khi thanh toán</span>
</span>
<?php else : ?>
<form method="post" class="m-0 inline">
<?php wp_nonce_field( 'nexxi_addr_book', 'nexxi_addr_nonce' ); ?>
<input type="hidden" name="nexxi_addr_action" value="set_default">
<input type="hidden" name="addr_id" value="<?php echo esc_attr( $id ); ?>">
<input type="hidden" name="nexxi_addr_redirect" value="<?php echo esc_url( $base_url ); ?>">
<button type="submit" class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary-fixed/70 text-on-secondary-fixed hover:bg-secondary hover:text-on-secondary transition-colors font-label-sm text-label-sm font-bold cursor-pointer border-0">
<span class="material-symbols-outlined text-[15px]">local_shipping</span>
<span>Chọn giao đến đây</span>
</button>
</form>
<?php endif; ?>
<div class="flex items-center gap-1">
<a class="px-3 py-1 rounded-full text-primary hover:bg-surface-container-high transition-colors font-label-sm text-label-sm font-bold no-underline" href="<?php echo esc_url( $edit_u ); ?>">Sửa</a>
<form method="post" class="m-0 inline" onsubmit="return confirm('Mẹ có chắc muốn xóa địa chỉ này khỏi sổ địa chỉ không?');">
<?php wp_nonce_field( 'nexxi_addr_book', 'nexxi_addr_nonce' ); ?>
<input type="hidden" name="nexxi_addr_action" value="delete">
<input type="hidden" name="addr_id" value="<?php echo esc_attr( $id ); ?>">
<input type="hidden" name="nexxi_addr_redirect" value="<?php echo esc_url( $base_url ); ?>">
<button type="submit" class="px-2.5 py-1 rounded-full text-error hover:bg-error-container/40 transition-colors font-label-sm text-label-sm font-bold bg-transparent border-0 cursor-pointer">Xóa</button>
</form>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
	<?php
	return ob_get_clean();
}

/** Stitch-styled unified Address Book view for /tai-khoan/edit-address/. */
function nexxi_kids_account_addresses_view( WP_User $user ) {
	$uid      = $user->ID;
	$book     = nexxi_kids_get_address_book( $uid );
	$base_url = wc_get_account_endpoint_url( 'edit-address' );
	$edit_id  = isset( $_GET['nexxi_addr_edit'] ) ? sanitize_key( wp_unslash( $_GET['nexxi_addr_edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$editing = null;
	if ( '' !== $edit_id && 'new' !== $edit_id ) {
		foreach ( $book as $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $edit_id ) {
				$editing = $item;
				break;
			}
		}
	}

	$show_form = ( 'new' === $edit_id || null !== $editing );
	$add_btn   = $show_form
		? '<a class="inline-flex items-center gap-1.5 px-space-md py-1.5 rounded-full bg-surface-container-lowest text-primary hover:bg-secondary-fixed hover:text-on-secondary-fixed transition-colors font-label-sm text-label-sm font-bold shadow-sm self-start sm:self-auto no-underline" href="' . esc_url( $base_url ) . '"><span class="material-symbols-outlined text-[16px]">arrow_back</span><span>Quay lại sổ địa chỉ</span></a>'
		: '<a class="inline-flex items-center gap-space-xs bg-primary text-on-primary px-space-md py-space-xs rounded-full font-label-md text-label-md hover:bg-primary-container transition-all shadow-sm self-start sm:self-auto no-underline" href="' . esc_url( add_query_arg( 'nexxi_addr_edit', 'new', $base_url ) ) . '"><span class="material-symbols-outlined text-[18px]">add</span><span>+ Thêm Địa Chỉ Mới</span></a>';

	$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_allowed_countries() : array( 'VN' => 'Việt Nam' );
	if ( ! isset( $countries['VN'] ) ) {
		$countries = array( 'VN' => 'Việt Nam' ) + $countries;
	}

	$val_label     = $editing ? $editing['label'] : 'Nhà riêng';
	$val_recipient = $editing ? $editing['recipient'] : nexxi_kids_account_name( $user );
	$val_phone     = $editing ? $editing['phone'] : get_user_meta( $uid, 'billing_phone', true );
	$val_country   = ( $editing && ! empty( $editing['country'] ) ) ? $editing['country'] : 'VN';
	$val_city      = $editing && isset( $editing['city'] ) ? $editing['city'] : '';
	$val_ward      = $editing && isset( $editing['ward'] ) ? $editing['ward'] : '';
	$val_addr1     = $editing ? $editing['address_1'] : '';
	$val_def       = $editing ? ! empty( $editing['is_default'] ) : true;
	$input_cls     = 'w-full bg-surface-container-low border border-outline-variant/40 rounded-full px-4 py-3 font-body-md text-body-md text-on-surface outline-none focus:bg-surface-container-lowest focus:border-secondary-container transition-all';

	$provinces = nexxi_kids_vn_provinces_options( $val_city );
	$wards     = nexxi_kids_vn_wards_options( $val_city, $val_ward );

	ob_start(); ?>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'NEXXI Club • Sổ địa chỉ', $show_form ? ( $editing ? 'Chỉnh Sửa Địa Chỉ Nhận Hàng' : 'Thêm Địa Chỉ Nhận Hàng Mới' ) : 'Sổ Địa Chỉ Nhận Hàng Của Mẹ', $add_btn ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<?php if ( $show_form ) : ?>
<form method="post" action="<?php echo esc_url( $base_url ); ?>" class="space-y-space-md max-w-3xl">
<?php wp_nonce_field( 'nexxi_addr_book', 'nexxi_addr_nonce' ); ?>
<input type="hidden" name="nexxi_addr_action" value="save">
<input type="hidden" name="addr_id" value="<?php echo esc_attr( $editing ? $editing['id'] : '' ); ?>">
<input type="hidden" name="nexxi_addr_redirect" value="<?php echo esc_url( $base_url ); ?>">

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-label">Tên gợi nhớ địa chỉ</label>
<div class="flex flex-wrap items-center gap-2 mb-2">
<?php foreach ( array( 'Nhà riêng', 'Cơ quan', 'Nhà ông bà' ) as $preset ) : ?>
<button type="button" onclick="document.getElementById('nexxi-addr-label').value=this.getAttribute('data-val')" data-val="<?php echo esc_attr( $preset ); ?>" class="px-3.5 py-1.5 rounded-full bg-surface-container-low hover:bg-secondary-fixed text-primary font-label-sm text-label-sm font-bold transition-colors border border-outline-variant/30 cursor-pointer"><?php echo esc_html( $preset ); ?></button>
<?php endforeach; ?>
</div>
<input id="nexxi-addr-label" name="addr_label" type="text" value="<?php echo esc_attr( $val_label ); ?>" placeholder="Ví dụ: Nhà riêng, Cơ quan, Nhà ngoại..." class="<?php echo esc_attr( $input_cls ); ?>">
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-recipient">Họ và tên người nhận <span class="text-secondary">*</span></label>
<input id="nexxi-addr-recipient" name="addr_recipient" type="text" required value="<?php echo esc_attr( $val_recipient ); ?>" placeholder="Nhập họ và tên người nhận" class="<?php echo esc_attr( $input_cls ); ?>">
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-phone">Số điện thoại <span class="text-secondary">*</span></label>
<input id="nexxi-addr-phone" name="addr_phone" type="tel" required value="<?php echo esc_attr( $val_phone ); ?>" placeholder="Ví dụ: 0942 958 091" class="<?php echo esc_attr( $input_cls ); ?>">
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-line1">Địa chỉ <span class="text-secondary">*</span></label>
<input id="nexxi-addr-line1" name="addr_address_1" type="text" required value="<?php echo esc_attr( $val_addr1 ); ?>" placeholder="Số nhà, tên đường..." class="<?php echo esc_attr( $input_cls ); ?>">
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-city">Tỉnh / Thành phố <span class="text-secondary">*</span></label>
<select id="nexxi-addr-city" name="addr_city" required data-selected-city="<?php echo esc_attr( $val_city ); ?>" class="<?php echo esc_attr( $input_cls ); ?>">
<?php foreach ( $provinces as $p_val => $p_label ) : ?>
<option value="<?php echo esc_attr( $p_val ); ?>" <?php selected( $val_city, $p_val ); ?>><?php echo esc_html( $p_label ); ?></option>
<?php endforeach; ?>
</select>
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-ward">Phường / Xã <span class="text-secondary">*</span></label>
<select id="nexxi-addr-ward" name="addr_ward" required data-selected-ward="<?php echo esc_attr( $val_ward ); ?>" class="<?php echo esc_attr( $input_cls ); ?>">
<?php foreach ( $wards as $w_val => $w_label ) : ?>
<option value="<?php echo esc_attr( $w_val ); ?>" <?php selected( $val_ward, $w_val ); ?>><?php echo esc_html( $w_label ); ?></option>
<?php endforeach; ?>
</select>
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-country">Quốc gia / Khu vực <span class="text-secondary">*</span></label>
<select id="nexxi-addr-country" name="addr_country" required class="<?php echo esc_attr( $input_cls ); ?>">
<?php foreach ( $countries as $code => $cname ) : ?>
<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $val_country, $code ); ?>><?php echo esc_html( $cname ); ?></option>
<?php endforeach; ?>
</select>
</div>

<label class="flex items-center gap-2.5 cursor-pointer pt-1">
<input type="checkbox" name="addr_is_default" value="1" <?php checked( $val_def ); ?> class="w-4 h-4 accent-secondary rounded">
<span class="font-label-md text-label-md text-primary">Đặt làm địa chỉ giao hàng mặc định khi thanh toán</span>
</label>

<div class="flex flex-wrap items-center gap-space-sm pt-space-xs">
<button type="submit" class="inline-flex items-center gap-2 bg-secondary hover:bg-secondary-container text-on-secondary px-space-xl py-3 rounded-full font-label-lg text-label-lg font-bold shadow-md transition-all border-0 cursor-pointer">
<span class="material-symbols-outlined text-[20px]">check</span>
<span>Lưu địa chỉ</span>
</button>
<a href="<?php echo esc_url( $base_url ); ?>" class="inline-flex items-center px-space-lg py-3 rounded-full bg-surface-container-high text-primary hover:bg-surface-container-highest font-label-md text-label-md font-bold no-underline transition-colors">Hủy</a>
</div>
</form>
<?php else : ?>
<p class="font-body-md text-body-md text-on-surface-variant mb-space-md">Mẹ có thể lưu nhiều địa chỉ nhận hàng (Nhà riêng, Cơ quan, Nhà ông bà...) và chọn địa chỉ muốn giao đến bất cứ lúc nào.</p>
<?php echo nexxi_kids_render_address_cards( $book, $base_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php endif; ?>
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
	$ord_stats = function_exists( 'nexxi_kids_get_customer_order_stats' ) ? nexxi_kids_get_customer_order_stats( $uid ) : null;
	$count = $ord_stats ? (int) $ord_stats['order_count'] : nexxi_kids_account_order_count( $uid );
	$spent = $ord_stats ? (float) $ord_stats['total_spent'] : ( function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $uid ) : 0 );
	$open = wc_get_orders( array( 'customer_id' => $uid, 'status' => array( 'wc-pending', 'wc-processing', 'wc-on-hold' ), 'return' => 'ids', 'limit' => -1 ) );
	$recent = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 3, 'orderby' => 'date', 'order' => 'DESC' ) );
	$wishlist = nexxi_kids_account_wishlist_count( $uid );
	$points = nexxi_kids_get_user_points( $uid );
	$pts_cfg   = function_exists( 'nexxi_kids_get_points_settings' ) ? nexxi_kids_get_points_settings() : array( 'earn_unit_vnd' => 10000, 'point_value_vnd' => 1000 );
	$baby_name = (string) get_user_meta( $uid, 'nexxi_baby_name', true );
	$baby_bday = (string) get_user_meta( $uid, 'nexxi_baby_birthday', true );
	$baby_gen  = (string) get_user_meta( $uid, 'nexxi_baby_gender', true );
	$orders_url = wc_get_account_endpoint_url( 'orders' );
	$address_url = wc_get_account_endpoint_url( 'edit-address' );
	$account_url = wc_get_account_endpoint_url( 'edit-account' );
	$wishlist_url = nexxi_kids_page_url( 'yeu-thich' );
	$stats = array(
		array( 'stars', 'Điểm thành viên NEXXI', number_format_i18n( $points ) . ' điểm', '#nexxi-member-points' ),
		array( 'shopping_bag', 'Tổng đơn đã đặt', $count . ' đơn', $orders_url ),
		array( 'local_shipping', 'Đang xử lý', count( $open ) . ' đơn', $orders_url ),
		array( 'payments', 'Tổng tiền chi tiêu', wc_price( $spent ), $orders_url ),
	);
	$book = nexxi_kids_get_address_book( $uid );
	ob_start(); ?>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm" id="nexxi-member-points">
<?php echo nexxi_kids_account_section_head( 'Mục 01', 'Tổng Quan Tài Khoản & Điểm Thành Viên NEXXI Club' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-space-sm">
<?php foreach ( $stats as $stat ) : ?>
<a class="bg-surface-container-low hover:bg-surface-container-high/60 rounded-lg p-space-md flex flex-col gap-1 transition-colors" href="<?php echo esc_url( $stat[3] ); ?>"><span class="w-9 h-9 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center"><span class="material-symbols-outlined text-[20px]"><?php echo esc_html( $stat[0] ); ?></span></span><span class="font-label-sm text-label-sm text-outline mt-1"><?php echo esc_html( $stat[1] ); ?></span><span class="font-title-md text-title-md text-primary font-bold"><?php echo wp_kses_post( $stat[2] ); ?></span></a>
<?php endforeach; ?>
</div>
<div class="mt-space-md p-space-md rounded-xl bg-gradient-to-r from-secondary-fixed/60 via-surface-container-low to-primary-fixed/40 border border-secondary-fixed flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm">
	<div class="flex items-start sm:items-center gap-space-sm">
		<span class="w-11 h-11 rounded-full bg-secondary text-on-secondary flex items-center justify-center shrink-0 shadow-sm">
			<span class="material-symbols-outlined text-[22px]">loyalty</span>
		</span>
		<div>
			<span class="font-label-lg text-label-lg text-primary font-bold block">
				Điểm NEXXI hiện có: <span class="text-secondary"><?php echo esc_html( number_format_i18n( $points ) ); ?> điểm</span>
				(Quy đổi: <strong class="text-primary"><?php echo esc_html( nexxi_kids_money( $points * (int) $pts_cfg['point_value_vnd'] ) ); ?></strong>)
			</span>
			<span class="font-body-sm text-body-sm text-on-surface-variant block">
				Tích lũy tự động từ mọi đơn hàng (<?php echo esc_html( nexxi_kids_money( $pts_cfg['earn_unit_vnd'] ) ); ?> = 1 điểm) và dùng điểm trừ tiền trực tiếp khi thanh toán!
			</span>
		</div>
	</div>
	<a class="inline-flex items-center gap-1.5 bg-primary text-on-primary px-space-md py-2 rounded-full font-label-sm text-label-sm font-bold hover:bg-primary-container transition-all shadow-sm shrink-0 self-start sm:self-center no-underline" href="<?php echo esc_url( nexxi_kids_shop_url() ); ?>">
		<span class="material-symbols-outlined text-[16px]">redeem</span>
		<span>Mua sắm &amp; Tích điểm</span>
	</a>
</div>

<!-- Baby Birthday Profile Box -->
<form method="post" class="mt-space-md p-space-md rounded-xl bg-surface-container-low border border-outline-variant/30 flex flex-col md:flex-row md:items-end justify-between gap-space-sm">
	<?php wp_nonce_field( 'nexxi_save_baby', 'nexxi_baby_nonce' ); ?>
	<input type="hidden" name="nexxi_save_baby_profile" value="1">
	<div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
		<div>
			<label class="block font-label-sm text-label-sm text-primary font-bold mb-1">👶 Tên thân mật của bé</label>
			<input type="text" name="baby_name" value="<?php echo esc_attr( $baby_name ); ?>" placeholder="VD: Bé Bắp, Bé Sóc..." class="w-full bg-surface-container-lowest border border-outline-variant/40 rounded-full px-3.5 py-2 font-body-sm text-body-sm text-on-surface outline-none">
		</div>
		<div>
			<label class="block font-label-sm text-label-sm text-primary font-bold mb-1">🎂 Ngày sinh nhật của bé</label>
			<input type="date" name="baby_birthday" value="<?php echo esc_attr( $baby_bday ); ?>" class="w-full bg-surface-container-lowest border border-outline-variant/40 rounded-full px-3.5 py-2 font-body-sm text-body-sm text-on-surface outline-none">
		</div>
		<div>
			<label class="block font-label-sm text-label-sm text-primary font-bold mb-1">🎀 Giới tính của bé</label>
			<select name="baby_gender" class="w-full bg-surface-container-lowest border border-outline-variant/40 rounded-full px-3.5 py-2 font-body-sm text-body-sm text-on-surface outline-none">
				<option value="" <?php selected( $baby_gen, '' ); ?>>-- Chọn --</option>
				<option value="girl" <?php selected( $baby_gen, 'girl' ); ?>>👧 Bé gái</option>
				<option value="boy" <?php selected( $baby_gen, 'boy' ); ?>>👦 Bé trai</option>
				<option value="both" <?php selected( $baby_gen, 'both' ); ?>>👧👦 Cả hai</option>
			</select>
		</div>
	</div>
	<button type="submit" class="inline-flex items-center justify-center gap-1.5 bg-secondary text-on-secondary px-space-md py-2 rounded-full font-label-sm text-label-sm font-bold hover:bg-secondary-container transition-all shadow-sm shrink-0 border-0 cursor-pointer">
		<span class="material-symbols-outlined text-[16px]">cake</span>
		<span>Lưu sinh nhật bé</span>
	</button>
</form>
</section>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php echo nexxi_kids_account_section_head( 'Mục 02', 'Đơn Hàng Gần Đây', $count ? '<a class="inline-flex items-center gap-1 font-label-md text-label-md text-secondary font-bold hover:underline self-start sm:self-auto" href="' . esc_url( $orders_url ) . '">Xem tất cả <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php if ( $recent ) : ?>
<div class="flex flex-col gap-space-sm">
<?php foreach ( $recent as $order ) :
	$items = $order->get_items();
	$first = $items ? reset( $items ) : null;
	$thumb = $first && $first->get_product() ? $first->get_product()->get_image( 'woocommerce_thumbnail', array( 'class' => 'w-full h-full object-cover' ) ) : '';
	$more = count( $items ) > 1 ? ' và ' . ( count( $items ) - 1 ) . ' sản phẩm khác' : '';
	$ord_pts = (int) $order->get_meta( '_nexxi_points_earned' );
	if ( $ord_pts <= 0 ) {
		$ord_pts = nexxi_kids_calculate_order_points( $order );
	}
	?>
<a class="flex items-center gap-space-md p-space-md bg-surface-container-low rounded-lg hover:bg-surface-container-high/60 transition-colors" href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
<span class="w-14 h-16 rounded-DEFAULT bg-secondary-fixed/30 overflow-hidden shrink-0"><?php echo wp_kses_post( $thumb ); ?></span>
<span class="flex-1 min-w-0 flex flex-col gap-0.5"><span class="flex flex-wrap items-center gap-space-xs"><span class="font-label-lg text-label-lg text-primary">Đơn #<?php echo esc_html( $order->get_order_number() ); ?></span><?php echo nexxi_kids_account_status_badge( $order->get_status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php if ( $ord_pts > 0 ) : ?><span class="bg-secondary-fixed/70 text-secondary font-label-sm text-[11px] px-2 py-0.5 rounded-full font-bold">+<?php echo (int) $ord_pts; ?> điểm</span><?php endif; ?></span><span class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1"><?php echo esc_html( ( $first ? $first->get_name() : '' ) . $more ); ?></span><span class="font-body-sm text-body-sm text-outline"><?php echo esc_html( wc_format_datetime( $order->get_date_created(), 'd/m/Y H:i' ) ); ?></span></span>
<span class="text-right shrink-0"><span class="block font-title-md text-title-md text-secondary font-bold"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span><span class="font-label-sm text-label-sm text-primary">Xem chi tiết →</span></span>
</a>
<?php endforeach; ?>
</div>
<?php else : ?>
<div class="bg-surface-container-low rounded-lg p-space-lg text-center flex flex-col items-center gap-space-sm"><span class="material-symbols-outlined text-secondary text-[36px]">shopping_bag</span><p class="font-body-md text-body-md text-on-surface-variant">Mẹ chưa có đơn hàng nào. Cùng chọn những bộ đồ xinh cho bé nhé!</p><a class="bg-secondary text-on-secondary px-space-lg py-2.5 rounded-full font-label-md text-label-md hover:bg-secondary-container transition-all shadow-md" href="<?php echo esc_url( nexxi_kids_shop_url() ); ?>">Khám phá sản phẩm</a></div>
<?php endif; ?>
</section>
<section class="bg-surface-container-lowest rounded-xl p-space-lg shadow-sm">
<?php
$addr_actions = '<div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">'
	. '<a class="inline-flex items-center gap-1 bg-secondary text-on-secondary px-space-md py-space-xs rounded-full font-label-sm text-label-sm font-bold hover:bg-secondary-container transition-all shadow-sm no-underline" href="' . esc_url( add_query_arg( 'nexxi_addr_edit', 'new', $address_url ) ) . '"><span class="material-symbols-outlined text-[16px]">add</span><span>Thêm địa chỉ mới</span></a>'
	. '<a class="inline-flex items-center gap-1 bg-primary text-on-primary px-space-md py-space-xs rounded-full font-label-sm text-label-sm font-bold hover:bg-primary-container transition-all shadow-sm no-underline" href="' . esc_url( $address_url ) . '"><span class="material-symbols-outlined text-[16px]">edit_location_alt</span><span>Sổ địa chỉ</span></a>'
	. '</div>';
echo nexxi_kids_account_section_head( 'Mục 03', 'Sổ Địa Chỉ Nhận Hàng', $addr_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo nexxi_kids_render_address_cards( $book, $address_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
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

/* ------------------------------------------------------------------ Checkout: Unified Address & Saved Address Picker */

// Unify Checkout address into a single Shipping/Delivery address (no separate Billing vs Shipping toggle).
add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );

/** Render saved address selector cards on Checkout so customers can pick any saved address with 1 click. */
function nexxi_kids_checkout_saved_address_picker() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	$uid  = get_current_user_id();
	$book = nexxi_kids_get_address_book( $uid );
	$manage_url = wc_get_account_endpoint_url( 'edit-address' );

	if ( empty( $book ) ) {
		echo '<div class="mb-space-md p-space-md rounded-xl bg-surface-container-low border border-outline-variant/25 flex flex-wrap items-center justify-between gap-2">'
			. '<span class="font-body-sm text-body-sm text-on-surface-variant flex items-center gap-1.5"><span class="material-symbols-outlined text-secondary text-[18px]">home_pin</span>Mẹ có thể lưu nhiều địa chỉ trong Sổ địa chỉ để lần sau chọn nhanh hơn.</span>'
			. '<a class="font-label-sm text-label-sm text-secondary font-bold hover:underline" href="' . esc_url( add_query_arg( 'nexxi_addr_edit', 'new', $manage_url ) ) . '">+ Quản lý sổ địa chỉ</a>'
			. '</div>';
		return;
	}

	ob_start(); ?>
<div class="nexxi-checkout-address-picker mb-space-md p-space-md rounded-2xl bg-surface-container-low border border-secondary-fixed">
<div class="flex flex-wrap items-center justify-between gap-2 mb-2.5">
<div class="flex items-center gap-1.5">
<span class="material-symbols-outlined text-secondary text-[20px]">bookmark_added</span>
<span class="font-label-lg text-label-lg text-primary font-bold">Chọn địa chỉ giao hàng đã lưu</span>
</div>
<a class="font-label-sm text-label-sm text-secondary font-bold hover:underline" href="<?php echo esc_url( $manage_url ); ?>">+ Thêm / Quản lý địa chỉ</a>
</div>
<div class="grid grid-cols-1 gap-2">
<?php foreach ( $book as $idx => $addr ) :
	$is_def = ! empty( $addr['is_default'] ) || 0 === $idx;
	$payload = wp_json_encode( array(
		'recipient' => isset( $addr['recipient'] ) ? $addr['recipient'] : '',
		'phone'     => isset( $addr['phone'] ) ? $addr['phone'] : '',
		'country'   => ! empty( $addr['country'] ) ? $addr['country'] : 'VN',
		'city'      => isset( $addr['city'] ) ? $addr['city'] : '',
		'ward'      => isset( $addr['ward'] ) ? $addr['ward'] : '',
		'address_1' => isset( $addr['address_1'] ) ? $addr['address_1'] : '',
	) );
	?>
<label class="nexxi-saved-addr-option flex items-start gap-2.5 p-3 rounded-xl bg-surface-container-lowest border <?php echo $is_def ? 'border-secondary ring-1 ring-secondary/30' : 'border-outline-variant/30'; ?> cursor-pointer hover:border-secondary transition-all">
<input type="radio" name="nexxi_saved_address_choice" <?php checked( $is_def ); ?> class="mt-1 accent-secondary" data-addr="<?php echo esc_attr( $payload ); ?>" onchange="window.nexxiApplySavedAddress && window.nexxiApplySavedAddress(this)">
<span class="flex-1 min-w-0">
<span class="flex flex-wrap items-center gap-1.5 font-label-md text-label-md text-primary font-bold">
<span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed text-[11px]"><?php echo esc_html( ! empty( $addr['label'] ) ? $addr['label'] : 'Nhà riêng' ); ?></span>
<span><?php echo esc_html( $addr['recipient'] ); ?></span>
<?php if ( ! empty( $addr['phone'] ) ) : ?><span class="text-outline">•</span><span class="text-secondary"><?php echo esc_html( $addr['phone'] ); ?></span><?php endif; ?>
</span>
<span class="block font-body-sm text-body-sm text-on-surface-variant mt-0.5"><?php echo esc_html( nexxi_kids_format_address_entry( $addr ) ); ?></span>
</span>
</label>
<?php endforeach; ?>
</div>
</div>
<script>
window.nexxiApplySavedAddress = function(radio) {
	if (!radio) return;
	try {
		var d = JSON.parse(radio.getAttribute('data-addr') || '{}');
		var setVal = function(id, val) {
			var el = document.getElementById(id);
			if (el) {
				el.value = val || '';
				el.dispatchEvent(new Event('change', { bubbles: true }));
			}
		};
		setVal('billing_first_name', d.recipient || '');
		setVal('billing_last_name', '');
		setVal('billing_phone', d.phone || '');
		setVal('billing_address_1', d.address_1 || '');
		setVal('billing_country', d.country || 'VN');
		setVal('billing_city', d.city || '');
		if (window.nexxiSyncWardDropdown) {
			window.nexxiSyncWardDropdown('billing_city', 'billing_ward', d.ward || '');
		} else {
			setVal('billing_ward', d.ward || '');
		}
		setVal('shipping_first_name', d.recipient || '');
		setVal('shipping_last_name', '');
		setVal('shipping_phone', d.phone || '');
		setVal('shipping_address_1', d.address_1 || '');
		setVal('shipping_country', d.country || 'VN');
		setVal('shipping_city', d.city || '');
		if (window.nexxiSyncWardDropdown) {
			window.nexxiSyncWardDropdown('shipping_city', 'shipping_ward', d.ward || '');
		} else {
			setVal('shipping_ward', d.ward || '');
		}
		document.querySelectorAll('.nexxi-saved-addr-option').forEach(function(lbl) {
			lbl.classList.remove('border-secondary', 'ring-1', 'ring-secondary/30');
			lbl.classList.add('border-outline-variant/30');
		});
		var parent = radio.closest('.nexxi-saved-addr-option');
		if (parent) {
			parent.classList.remove('border-outline-variant/30');
			parent.classList.add('border-secondary', 'ring-1', 'ring-secondary/30');
		}
		if (window.jQuery) {
			window.jQuery(document.body).trigger('update_checkout');
		}
	} catch (e) {}
};
document.addEventListener('DOMContentLoaded', function() {
	var checked = document.querySelector('input[name="nexxi_saved_address_choice"]:checked');
	if (checked && window.nexxiApplySavedAddress) {
		window.nexxiApplySavedAddress(checked);
	}
});
</script>
	<?php
	echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_before_checkout_billing_form', 'nexxi_kids_checkout_saved_address_picker', 5 );

// Automatically save checkout address to the user's address book if they had none saved yet.
add_action( 'woocommerce_checkout_update_user_meta', static function ( $customer_id, $data ) {
	if ( ! $customer_id ) {
		return;
	}
	$ward = isset( $data['billing_ward'] ) ? sanitize_text_field( $data['billing_ward'] ) : ( isset( $_POST['billing_ward'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_ward'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' !== $ward ) {
		update_user_meta( $customer_id, 'billing_ward', $ward );
		update_user_meta( $customer_id, 'shipping_ward', $ward );
	}
	$book = get_user_meta( $customer_id, 'nexxi_address_book', true );
	if ( ! empty( $book ) && is_array( $book ) ) {
		return;
	}
	$recipient = trim( ( isset( $data['billing_first_name'] ) ? $data['billing_first_name'] : '' ) . ' ' . ( isset( $data['billing_last_name'] ) ? $data['billing_last_name'] : '' ) );
	$addr1     = isset( $data['billing_address_1'] ) ? trim( $data['billing_address_1'] ) : '';
	if ( '' !== $recipient && '' !== $addr1 ) {
		$entry = array(
			'id'         => 'addr_' . strtolower( wp_generate_password( 8, false, false ) ),
			'label'      => 'Nhà riêng',
			'recipient'  => $recipient,
			'phone'      => isset( $data['billing_phone'] ) ? $data['billing_phone'] : '',
			'country'    => ! empty( $data['billing_country'] ) ? $data['billing_country'] : 'VN',
			'city'       => isset( $data['billing_city'] ) ? $data['billing_city'] : '',
			'ward'       => $ward,
			'address_1'  => $addr1,
			'address_2'  => '',
			'is_default' => true,
		);
		nexxi_kids_save_address_book( $customer_id, array( $entry ) );
	}
}, 20, 2 );

/* ------------------------------------------------------------------ Billing & Shipping address field rules */

// Phone field is always required across WooCommerce forms.
add_filter( 'pre_option_woocommerce_checkout_phone_field', static function () {
	return 'required';
} );

// Hide 2nd address line option in WooCommerce so Address is always 1 single line.
add_filter( 'pre_option_woocommerce_checkout_address_2_field', static function () {
	return 'hidden';
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

/**
 * Shared field ordering & Vietnam 2025 dropdown definition:
 *  1. Địa chỉ email * (priority 10)
 *  2. Họ và tên người nhận * (priority 20)
 *  3. Số điện thoại * (priority 30 — dưới Họ và tên người nhận)
 *  4. Địa chỉ * (priority 40 — 1 dòng duy nhất)
 *  5. Tỉnh / Thành phố * (priority 50 — chọn Thành phố trước, dropdown select cho phép gõ tìm kiếm)
 *  6. Phường / Xã * (priority 60 — gắn với Thành phố đã chọn, dropdown select cho phép gõ tìm kiếm)
 *  7. Quốc gia / Khu vực * (priority 70 — dưới Thành phố & Phường/Xã)
 */
add_filter( 'woocommerce_default_address_fields', static function ( $fields ) {
	unset( $fields['postcode'], $fields['last_name'], $fields['company'], $fields['address_2'], $fields['state'] );

	if ( isset( $fields['first_name'] ) ) {
		$fields['first_name']['label']       = 'Họ và tên người nhận';
		$fields['first_name']['placeholder'] = 'Nhập họ và tên người nhận';
		$fields['first_name']['class']       = array( 'form-row-wide' );
		$fields['first_name']['priority']    = 20;
	}
	if ( isset( $fields['address_1'] ) ) {
		$fields['address_1']['label']       = 'Địa chỉ';
		$fields['address_1']['placeholder'] = 'Số nhà, tên đường...';
		$fields['address_1']['class']       = array( 'form-row-wide' );
		$fields['address_1']['priority']    = 40;
	}
	if ( isset( $fields['city'] ) ) {
		$fields['city']['label']    = 'Tỉnh / Thành phố';
		$fields['city']['required'] = true;
		$fields['city']['type']     = 'select';
		$fields['city']['class']    = array( 'form-row-wide', 'nexxi-city-field' );
		$fields['city']['options']  = nexxi_kids_vn_provinces_options();
		$fields['city']['priority'] = 50;
	}
	$fields['ward'] = array(
		'label'    => 'Phường / Xã',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-ward-field' ),
		'options'  => nexxi_kids_vn_wards_options(),
		'priority' => 60,
	);
	if ( isset( $fields['country'] ) ) {
		$fields['country']['label']    = 'Quốc gia / Khu vực';
		$fields['country']['default']  = 'VN';
		$fields['country']['class']    = array( 'form-row-wide' );
		$fields['country']['priority'] = 70;
	}
	return $fields;
}, 20 );

// Keep priorities & hidden fields consistent in WooCommerce's frontend country locale JS (wc-address-i18n).
add_filter( 'woocommerce_get_country_locale', static function ( $locale ) {
	foreach ( array_keys( $locale ) as $country ) {
		$locale[ $country ]['postcode']  = array( 'required' => false, 'hidden' => true );
		$locale[ $country ]['state']     = array( 'required' => false, 'hidden' => true );
		$locale[ $country ]['address_2'] = array( 'required' => false, 'hidden' => true );
		$locale[ $country ]['first_name']['priority'] = 20;
		$locale[ $country ]['phone']['priority']      = 30;
		$locale[ $country ]['address_1']['priority']  = 40;
		$locale[ $country ]['city']['priority']       = 50;
		$locale[ $country ]['ward']['priority']       = 60;
		$locale[ $country ]['country']['priority']    = 70;
	}
	$locale['VN'] = array(
		'first_name' => array( 'label' => 'Họ và tên người nhận', 'priority' => 20, 'required' => true ),
		'phone'      => array( 'label' => 'Số điện thoại', 'priority' => 30, 'required' => true ),
		'address_1'  => array( 'label' => 'Địa chỉ', 'placeholder' => 'Số nhà, tên đường...', 'priority' => 40, 'required' => true ),
		'address_2'  => array( 'required' => false, 'hidden' => true ),
		'city'       => array( 'label' => 'Tỉnh / Thành phố', 'priority' => 50, 'required' => true ),
		'ward'       => array( 'label' => 'Phường / Xã', 'priority' => 60, 'required' => true ),
		'country'    => array( 'label' => 'Quốc gia / Khu vực', 'priority' => 70, 'required' => true ),
		'postcode'   => array( 'required' => false, 'hidden' => true ),
		'state'      => array( 'required' => false, 'hidden' => true ),
	);
	return $locale;
}, 20 );

// Billing fields: enforce exact field types, options, and priorities.
add_filter( 'woocommerce_billing_fields', static function ( $fields ) {
	unset( $fields['billing_postcode'], $fields['billing_last_name'], $fields['billing_company'], $fields['billing_address_2'], $fields['billing_state'] );

	$uid       = get_current_user_id();
	$saved_city = $uid ? (string) get_user_meta( $uid, 'billing_city', true ) : '';
	$saved_ward = $uid ? (string) get_user_meta( $uid, 'billing_ward', true ) : '';

	if ( isset( $fields['billing_email'] ) ) {
		$fields['billing_email']['required']    = true;
		$fields['billing_email']['label']       = 'Địa chỉ email';
		$fields['billing_email']['placeholder'] = 'Nhập email để nhận thông tin đơn hàng & tích điểm';
		$fields['billing_email']['class']       = array( 'form-row-wide' );
		$fields['billing_email']['priority']    = 10;
	}
	if ( isset( $fields['billing_first_name'] ) ) {
		$fields['billing_first_name']['required']    = true;
		$fields['billing_first_name']['label']       = 'Họ và tên người nhận';
		$fields['billing_first_name']['placeholder'] = 'Nhập họ và tên người nhận';
		$fields['billing_first_name']['class']       = array( 'form-row-wide' );
		$fields['billing_first_name']['priority']    = 20;
	}
	$fields['billing_phone'] = array(
		'label'        => 'Số điện thoại',
		'placeholder'  => 'Ví dụ: 0942 958 091',
		'required'     => true,
		'type'         => 'tel',
		'class'        => array( 'form-row-wide' ),
		'validate'     => array( 'phone' ),
		'autocomplete' => 'tel',
		'priority'     => 30,
	);
	if ( isset( $fields['billing_address_1'] ) ) {
		$fields['billing_address_1']['required']    = true;
		$fields['billing_address_1']['label']       = 'Địa chỉ';
		$fields['billing_address_1']['placeholder'] = 'Số nhà, tên đường...';
		$fields['billing_address_1']['class']       = array( 'form-row-wide' );
		$fields['billing_address_1']['priority']    = 40;
	}
	$fields['billing_city'] = array(
		'label'    => 'Tỉnh / Thành phố',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-city-field' ),
		'options'  => nexxi_kids_vn_provinces_options( $saved_city ),
		'default'  => $saved_city,
		'priority' => 50,
	);
	$fields['billing_ward'] = array(
		'label'    => 'Phường / Xã',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-ward-field' ),
		'options'  => nexxi_kids_vn_wards_options( $saved_city, $saved_ward ),
		'default'  => $saved_ward,
		'priority' => 60,
	);
	if ( isset( $fields['billing_country'] ) ) {
		$fields['billing_country']['label']    = 'Quốc gia / Khu vực';
		$fields['billing_country']['default']  = 'VN';
		$fields['billing_country']['class']    = array( 'form-row-wide' );
		$fields['billing_country']['priority'] = 70;
	}
	return $fields;
}, 99 );

// Shipping fields: keep in sync with billing fields.
add_filter( 'woocommerce_shipping_fields', static function ( $fields ) {
	unset( $fields['shipping_postcode'], $fields['shipping_last_name'], $fields['shipping_company'], $fields['shipping_address_2'], $fields['shipping_state'] );

	$uid        = get_current_user_id();
	$saved_city = $uid ? (string) get_user_meta( $uid, 'shipping_city', true ) : '';
	$saved_ward = $uid ? (string) get_user_meta( $uid, 'shipping_ward', true ) : '';

	if ( isset( $fields['shipping_first_name'] ) ) {
		$fields['shipping_first_name']['label']    = 'Họ và tên người nhận';
		$fields['shipping_first_name']['class']    = array( 'form-row-wide' );
		$fields['shipping_first_name']['priority'] = 20;
	}
	$fields['shipping_phone'] = array(
		'label'        => 'Số điện thoại',
		'required'     => true,
		'type'         => 'tel',
		'class'        => array( 'form-row-wide' ),
		'validate'     => array( 'phone' ),
		'autocomplete' => 'tel',
		'priority'     => 30,
	);
	if ( isset( $fields['shipping_address_1'] ) ) {
		$fields['shipping_address_1']['label']       = 'Địa chỉ';
		$fields['shipping_address_1']['placeholder'] = 'Số nhà, tên đường...';
		$fields['shipping_address_1']['class']       = array( 'form-row-wide' );
		$fields['shipping_address_1']['priority']    = 40;
	}
	$fields['shipping_city'] = array(
		'label'    => 'Tỉnh / Thành phố',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-city-field' ),
		'options'  => nexxi_kids_vn_provinces_options( $saved_city ),
		'default'  => $saved_city,
		'priority' => 50,
	);
	$fields['shipping_ward'] = array(
		'label'    => 'Phường / Xã',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-ward-field' ),
		'options'  => nexxi_kids_vn_wards_options( $saved_city, $saved_ward ),
		'default'  => $saved_ward,
		'priority' => 60,
	);
	if ( isset( $fields['shipping_country'] ) ) {
		$fields['shipping_country']['label']    = 'Quốc gia / Khu vực';
		$fields['shipping_country']['default']  = 'VN';
		$fields['shipping_country']['class']    = array( 'form-row-wide' );
		$fields['shipping_country']['priority'] = 70;
	}
	return $fields;
}, 99 );

// My Account → Edit Address (/tai-khoan/edit-address/{billing|shipping}): enforce same order & dropdowns.
add_filter( 'woocommerce_address_to_edit', static function ( $address, $load_address ) {
	$load_address = in_array( $load_address, array( 'billing', 'shipping' ), true ) ? $load_address : 'billing';
	unset(
		$address[ $load_address . '_postcode' ],
		$address[ $load_address . '_last_name' ],
		$address[ $load_address . '_company' ],
		$address[ $load_address . '_address_2' ],
		$address[ $load_address . '_state' ]
	);

	$uid        = get_current_user_id();
	$saved_city = $uid ? (string) get_user_meta( $uid, $load_address . '_city', true ) : '';
	$saved_ward = $uid ? (string) get_user_meta( $uid, $load_address . '_ward', true ) : '';

	if ( isset( $address[ $load_address . '_first_name' ] ) ) {
		$address[ $load_address . '_first_name' ]['priority'] = 20;
	}

	$phone_key = $load_address . '_phone';
	$address[ $phone_key ] = array(
		'label'        => 'Số điện thoại',
		'required'     => true,
		'type'         => 'tel',
		'class'        => array( 'form-row-wide' ),
		'validate'     => array( 'phone' ),
		'autocomplete' => 'tel',
		'priority'     => 30,
		'value'        => $uid ? get_user_meta( $uid, $phone_key, true ) : '',
	);

	if ( isset( $address[ $load_address . '_address_1' ] ) ) {
		$address[ $load_address . '_address_1' ]['label']       = 'Địa chỉ';
		$address[ $load_address . '_address_1' ]['placeholder'] = 'Số nhà, tên đường...';
		$address[ $load_address . '_address_1' ]['priority']    = 40;
	}

	$city_key = $load_address . '_city';
	$address[ $city_key ] = array(
		'label'    => 'Tỉnh / Thành phố',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-city-field' ),
		'options'  => nexxi_kids_vn_provinces_options( $saved_city ),
		'priority' => 50,
		'value'    => $saved_city,
	);

	$ward_key = $load_address . '_ward';
	$address[ $ward_key ] = array(
		'label'    => 'Phường / Xã',
		'required' => true,
		'type'     => 'select',
		'class'    => array( 'form-row-wide', 'nexxi-ward-field' ),
		'options'  => nexxi_kids_vn_wards_options( $saved_city, $saved_ward ),
		'priority' => 60,
		'value'    => $saved_ward,
	);

	$country_key = $load_address . '_country';
	if ( isset( $address[ $country_key ] ) ) {
		$address[ $country_key ]['label']    = 'Quốc gia / Khu vực';
		$address[ $country_key ]['default']  = 'VN';
		$address[ $country_key ]['priority'] = 70;
		if ( empty( $address[ $country_key ]['value'] ) ) {
			$address[ $country_key ]['value'] = 'VN';
		}
	}

	return $address;
}, 99, 2 );

// Save Ward (Phường / Xã) into order meta and append to shipping/billing address_1 if not already present so Admin & POS see the full address.
add_action( 'woocommerce_checkout_create_order', static function ( WC_Order $order, $data ) {
	$ward = isset( $data['billing_ward'] ) ? sanitize_text_field( $data['billing_ward'] ) : ( isset( $_POST['billing_ward'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_ward'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' !== $ward ) {
		$order->update_meta_data( '_billing_ward', $ward );
		$order->update_meta_data( '_shipping_ward', $ward );
		$addr1 = trim( (string) $order->get_billing_address_1() );
		if ( '' !== $addr1 && false === mb_stripos( $addr1, $ward ) ) {
			$full_line = $addr1 . ', ' . $ward;
			$order->set_billing_address_1( $full_line );
			$order->set_shipping_address_1( $full_line );
		}
	}
}, 20, 2 );

/**
 * Output interactive Vietnam 2025 Searchable Select (Tỉnh/Thành phố -> Phường/Xã) & Checkout field order script.
 */
function nexxi_kids_vn_address_script() {
	if ( ! ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) ) {
		return;
	}
	$vn_data = nexxi_kids_vn_addresses_2025();
	if ( empty( $vn_data ) ) {
		return;
	}
	?>
	<script data-no-optimize="1" data-no-defer="1">
	(function() {
		window.NexxiVNAddresses2025 = <?php echo wp_json_encode( $vn_data ); ?>;

		function foldVN(str) {
			if (!str) return '';
			return String(str)
				.toLowerCase()
				.normalize('NFD')
				.replace(/[\u0300-\u036f]/g, '')
				.replace(/đ/g, 'd')
				.replace(/\s+/g, ' ')
				.trim();
		}

		function findProvince(cityName) {
			if (!cityName || !Array.isArray(window.NexxiVNAddresses2025)) return null;
			var target = foldVN(cityName);
			for (var i = 0; i < window.NexxiVNAddresses2025.length; i++) {
				var p = window.NexxiVNAddresses2025[i];
				if (p && p.name && foldVN(p.name) === target) {
					return p;
				}
			}
			return null;
		}

		var openSSelect = null;

		function closeAllSSelect() {
			if (openSSelect) {
				openSSelect.classList.remove('is-open');
				openSSelect = null;
			}
		}

		document.addEventListener('click', function(e) {
			if (openSSelect && !openSSelect.contains(e.target)) {
				closeAllSSelect();
			}
		});

		function destroyWCSelect2(selectEl) {
			if (!selectEl || !window.jQuery) return;
			try {
				var $el = window.jQuery(selectEl);
				if ($el.hasClass('select2-hidden-accessible') && typeof $el.select2 === 'function') {
					$el.select2('destroy');
				}
				var nextS2 = selectEl.parentNode ? selectEl.parentNode.querySelector('.select2-container') : null;
				if (nextS2 && nextS2.parentNode) {
					nextS2.parentNode.removeChild(nextS2);
				}
			} catch (err) {}
		}

		function createOrUpdateSearchableSelect(selectEl, opts) {
			if (!selectEl) return null;
			opts = opts || {};
			destroyWCSelect2(selectEl);
			selectEl.classList.add('nexxi-sselect-native-hidden');

			var wrapper = selectEl._nexxiSSelect;
			if (!wrapper || !wrapper.parentNode) {
				wrapper = document.createElement('div');
				wrapper.className = 'nexxi-sselect';

				var trigger = document.createElement('button');
				trigger.type = 'button';
				trigger.className = 'nexxi-sselect__trigger';
				trigger.innerHTML = '<span class="nexxi-sselect__label"></span><span class="nexxi-sselect__arrow"><span class="material-symbols-outlined" style="font-size:20px;">expand_more</span></span>';

				var popover = document.createElement('div');
				popover.className = 'nexxi-sselect__popover';
				popover.innerHTML =
					'<div class="nexxi-sselect__search-wrap">' +
						'<span class="material-symbols-outlined nexxi-sselect__search-icon">search</span>' +
						'<input type="text" class="nexxi-sselect__search" autocomplete="off" placeholder="' + (opts.searchPlaceholder || 'Gõ tên để tìm kiếm...') + '">' +
					'</div>' +
					'<ul class="nexxi-sselect__list" role="listbox"></ul>';

				wrapper.appendChild(trigger);
				wrapper.appendChild(popover);
				selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);
				selectEl._nexxiSSelect = wrapper;

				var searchInput = popover.querySelector('.nexxi-sselect__search');
				var listEl = popover.querySelector('.nexxi-sselect__list');

				trigger.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();

					if (wrapper.classList.contains('is-locked')) {
						if (typeof wrapper._onLockedClick === 'function') {
							wrapper._onLockedClick();
						}
						return;
					}

					if (wrapper.classList.contains('is-open')) {
						closeAllSSelect();
						return;
					}

					closeAllSSelect();
					wrapper.classList.add('is-open');
					openSSelect = wrapper;
					searchInput.value = '';
					wrapper._renderList('');
					setTimeout(function() {
						searchInput.focus();
					}, 20);
				});

				searchInput.addEventListener('click', function(e) {
					e.stopPropagation();
				});

				searchInput.addEventListener('input', function() {
					wrapper._renderList(searchInput.value);
				});

				searchInput.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') {
						closeAllSSelect();
						trigger.focus();
					} else if (e.key === 'Enter') {
						e.preventDefault();
						var firstOpt = listEl.querySelector('.nexxi-sselect__option');
						if (firstOpt) {
							firstOpt.click();
						}
					}
				});
			}

			var triggerEl = wrapper.querySelector('.nexxi-sselect__trigger');
			var labelEl = wrapper.querySelector('.nexxi-sselect__label');
			var listEl = wrapper.querySelector('.nexxi-sselect__list');
			var searchInputEl = wrapper.querySelector('.nexxi-sselect__search');
			if (opts.searchPlaceholder && searchInputEl) {
				searchInputEl.placeholder = opts.searchPlaceholder;
			}
			wrapper._onLockedClick = opts.onLockedClick || null;

			wrapper._syncDisplay = function() {
				var isLocked = !!opts.locked;
				wrapper.classList.toggle('is-locked', isLocked);
				var selectedOpt = selectEl.options[selectEl.selectedIndex];
				var val = selectEl.value;
				var txt = selectedOpt ? selectedOpt.textContent : '';
				if (!val) {
					labelEl.textContent = txt || opts.placeholder || '-- Chọn --';
					triggerEl.classList.add('is-placeholder');
				} else {
					labelEl.textContent = txt;
					triggerEl.classList.remove('is-placeholder');
				}
			};

			wrapper._renderList = function(query) {
				while (listEl.firstChild) {
					listEl.removeChild(listEl.firstChild);
				}
				var q = foldVN(query || '');
				var count = 0;
				var currentVal = selectEl.value;

				for (var i = 0; i < selectEl.options.length; i++) {
					var o = selectEl.options[i];
					if (!o.value) continue;
					var text = o.textContent || o.value;
					if (q && foldVN(text).indexOf(q) === -1) {
						continue;
					}
					count++;
					var li = document.createElement('li');
					li.className = 'nexxi-sselect__option' + (o.value === currentVal ? ' is-selected' : '');
					li.setAttribute('data-value', o.value);
					li.textContent = text;
					li.addEventListener('click', function(ev) {
						ev.stopPropagation();
						var chosen = this.getAttribute('data-value') || '';
						selectEl.value = chosen;
						selectEl.setAttribute('data-selected-city', chosen);
						selectEl.setAttribute('data-selected-ward', chosen);
						wrapper._syncDisplay();
						closeAllSSelect();
						selectEl.dispatchEvent(new Event('change', { bubbles: true }));
						if (window.jQuery) {
							window.jQuery(selectEl).trigger('change');
						}
					});
					listEl.appendChild(li);
				}

				if (count === 0) {
					var emptyLi = document.createElement('li');
					emptyLi.className = 'nexxi-sselect__empty';
					emptyLi.textContent = 'Không tìm thấy kết quả phù hợp';
					listEl.appendChild(emptyLi);
				}
			};

			wrapper._syncDisplay();
			return wrapper;
		}

		window.nexxiSyncWardDropdown = function(citySelectId, wardSelectId, preferredWard) {
			var cityEl = document.getElementById(citySelectId);
			var wardEl = document.getElementById(wardSelectId);
			if (!cityEl || !wardEl) return;

			var currentCity = cityEl.value || cityEl.getAttribute('data-selected-city') || '';
			if (currentCity && !cityEl.value) {
				cityEl.value = currentCity;
			}
			var currentWard = (typeof preferredWard === 'string') ? preferredWard : (wardEl.value || wardEl.getAttribute('data-selected-ward') || '');
			var prov = findProvince(currentCity);

			while (wardEl.firstChild) {
				wardEl.removeChild(wardEl.firstChild);
			}

			var placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = prov ? '-- Chọn Phường / Xã --' : '-- Vui lòng chọn Tỉnh / Thành phố trước --';
			wardEl.appendChild(placeholder);

			if (prov && Array.isArray(prov.wards)) {
				for (var i = 0; i < prov.wards.length; i++) {
					var w = prov.wards[i];
					var opt = document.createElement('option');
					opt.value = w;
					opt.textContent = w;
					if (currentWard && foldVN(w) === foldVN(currentWard)) {
						opt.selected = true;
					}
					wardEl.appendChild(opt);
				}
			} else {
				wardEl.value = '';
			}

			createOrUpdateSearchableSelect(cityEl, {
				placeholder: '-- Chọn Tỉnh / Thành phố --',
				searchPlaceholder: 'Gõ tên Tỉnh / Thành phố để tìm nhanh...',
				locked: false
			});

			createOrUpdateSearchableSelect(wardEl, {
				placeholder: prov ? '-- Chọn Phường / Xã --' : '-- Vui lòng chọn Tỉnh / Thành phố trước --',
				searchPlaceholder: prov ? ('Gõ tên Phường / Xã thuộc ' + prov.name + '...') : 'Vui lòng chọn Tỉnh / Thành phố trước',
				locked: !prov,
				onLockedClick: function() {
					if (cityEl._nexxiSSelect) {
						var cityTrigger = cityEl._nexxiSSelect.querySelector('.nexxi-sselect__trigger');
						if (cityTrigger) {
							cityTrigger.classList.add('nexxi-field-highlight-pulse');
							setTimeout(function() {
								cityTrigger.classList.remove('nexxi-field-highlight-pulse');
							}, 600);
							cityTrigger.click();
						}
					} else {
						cityEl.focus();
					}
				}
			});
		};

		function bindCityWardPair(citySelectId, wardSelectId) {
			var cityEl = document.getElementById(citySelectId);
			var wardEl = document.getElementById(wardSelectId);
			if (!cityEl || !wardEl) return;

			if (!cityEl.getAttribute('data-nexxi-bound')) {
				cityEl.setAttribute('data-nexxi-bound', '1');
				cityEl.addEventListener('change', function() {
					cityEl.setAttribute('data-selected-city', cityEl.value);
					wardEl.setAttribute('data-selected-ward', '');
					window.nexxiSyncWardDropdown(citySelectId, wardSelectId, '');
				});
			}

			window.nexxiSyncWardDropdown(citySelectId, wardSelectId);
		}

		function enforceCheckoutFieldOrder() {
			var prefixes = ['billing', 'shipping'];
			for (var p = 0; p < prefixes.length; p++) {
				var pre = prefixes[p];
				var wrapper = document.querySelector('.woocommerce-' + pre + '-fields__field-wrapper');
				if (!wrapper) continue;

				var addr2 = document.getElementById(pre + '_address_2_field');
				if (addr2 && addr2.parentNode) {
					addr2.parentNode.removeChild(addr2);
				}

				var order = [
					pre + '_email_field',
					pre + '_first_name_field',
					pre + '_phone_field',
					pre + '_address_1_field',
					pre + '_city_field',
					pre + '_ward_field',
					pre + '_country_field'
				];
				for (var i = 0; i < order.length; i++) {
					var fieldRow = document.getElementById(order[i]);
					if (fieldRow && fieldRow.parentNode === wrapper) {
						wrapper.appendChild(fieldRow);
					}
				}
			}
		}

		function initAll() {
			enforceCheckoutFieldOrder();
			bindCityWardPair('billing_city', 'billing_ward');
			bindCityWardPair('shipping_city', 'shipping_ward');
			bindCityWardPair('nexxi-addr-city', 'nexxi-addr-ward');
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initAll);
		} else {
			initAll();
		}

		if (window.jQuery) {
			window.jQuery(document.body).on('country_to_state_changing updated_checkout wc_address_i18n_ready', function() {
				setTimeout(initAll, 0);
			});
		}
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'nexxi_kids_vn_address_script', 30 );

/* ------------------------------------------------------------------ Guest Checkout Auto-Account, Existing Account Linking & Member Points */

// 1. Always allow guest checkout & disable WC's built-in checkout registration check so existing emails never trigger a blocking error.
add_filter( 'pre_option_woocommerce_enable_guest_checkout', static function () {
	return 'yes';
} );
add_filter( 'woocommerce_checkout_registration_enabled', '__return_false', 999 );
add_filter( 'woocommerce_checkout_registration_required', '__return_false', 999 );
add_filter( 'woocommerce_order_received_verify_known_shoppers', '__return_false', 999 );

// 2. Ensure COD ("Thanh toán khi nhận hàng (COD)") is enabled when no payment gateway is active.
add_action( 'init', static function () {
	if ( '1' !== get_option( 'nexxi_cod_auto_enabled_v1' ) ) {
		$cod = get_option( 'woocommerce_cod_settings', array() );
		if ( ! is_array( $cod ) || empty( $cod['enabled'] ) || 'yes' !== $cod['enabled'] ) {
			$cod = is_array( $cod ) ? $cod : array();
			$cod['enabled']            = 'yes';
			$cod['title']              = 'Thanh toán khi nhận hàng (COD)';
			$cod['description']        = 'Ba mẹ được kiểm tra sản phẩm trước khi thanh toán tiền mặt cho shipper.';
			$cod['instructions']       = 'Thanh toán tiền mặt cho nhân viên giao hàng sau khi kiểm tra sản phẩm.';
			$cod['enable_for_methods'] = array();
			$cod['enable_for_virtual'] = 'yes';
			update_option( 'woocommerce_cod_settings', $cod, true );
		}
		update_option( 'nexxi_cod_auto_enabled_v1', '1', true );
	}
}, 20 );

add_filter( 'woocommerce_available_payment_gateways', static function ( $available_gateways ) {
	if ( ! empty( $available_gateways ) ) {
		return $available_gateways;
	}
	if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
		$all = WC()->payment_gateways()->payment_gateways();
		if ( isset( $all['cod'] ) ) {
			$all['cod']->enabled        = 'yes';
			$all['cod']->title          = $all['cod']->title ? $all['cod']->title : 'Thanh toán khi nhận hàng (COD)';
			$all['cod']->description    = $all['cod']->description ? $all['cod']->description : 'Ba mẹ được kiểm tra sản phẩm trước khi thanh toán tiền mặt cho shipper.';
			$available_gateways['cod'] = $all['cod'];
		}
	}
	return $available_gateways;
}, 999 );

/**
 * Normalize a Vietnamese/international phone number to digits (converts leading 84 -> 0).
 *
 * @param string $phone Raw phone string.
 * @return string Normalized digits string.
 */
function nexxi_kids_normalize_phone( $phone ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	if ( '' === $digits ) {
		return '';
	}
	if ( 0 === strpos( $digits, '84' ) && strlen( $digits ) >= 11 ) {
		$digits = '0' . substr( $digits, 2 );
	}
	return $digits;
}

/**
 * Find an existing WordPress user by email address OR phone number.
 *
 * @param string $email Customer email.
 * @param string $phone Customer phone.
 * @return WP_User|null Matched user or null if neither email nor phone exists in the system.
 */
function nexxi_kids_find_user_by_email_or_phone( $email, $phone ) {
	global $wpdb;
	$email = sanitize_email( (string) $email );
	if ( '' !== $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$user = get_user_by( 'login', $email );
		if ( $user instanceof WP_User ) {
			return $user;
		}
	}

	$raw_phone     = trim( (string) $phone );
	$compact_phone = preg_replace( '/\s+/', '', $raw_phone );
	$norm_phone    = nexxi_kids_normalize_phone( $raw_phone );

	if ( '' !== $norm_phone && strlen( $norm_phone ) >= 8 ) {
		foreach ( array_unique( array_filter( array( $raw_phone, $compact_phone, $norm_phone ) ) ) as $login_candidate ) {
			$user = get_user_by( 'login', $login_candidate );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}

		// Fast exact match in usermeta first.
		$uid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key IN ('nexxi_phone_normalized', 'billing_phone', 'shipping_phone', 'phone_number')
				   AND meta_value IN (%s, %s, %s)
				 ORDER BY user_id ASC
				 LIMIT 1",
				$norm_phone,
				$compact_phone,
				$raw_phone
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u instanceof WP_User ) {
				return $u;
			}
		}

		// Fallback normalized scan in case stored phone had spaces, dots, or +84 prefix.
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta}
			 WHERE meta_key IN ('billing_phone', 'shipping_phone', 'phone_number')
			   AND meta_value != ''"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $rows ) {
			foreach ( $rows as $row ) {
				if ( nexxi_kids_normalize_phone( $row->meta_value ) === $norm_phone ) {
					$u = get_userdata( (int) $row->user_id );
					if ( $u instanceof WP_User ) {
						update_user_meta( $u->ID, 'nexxi_phone_normalized', $norm_phone );
						return $u;
					}
				}
			}
		}
	}

	return null;
}

/**
 * Sync order delivery & contact information into the matched or newly created user profile & address book.
 *
 * @param int      $user_id WordPress user ID.
 * @param WC_Order $order   WooCommerce order.
 */
function nexxi_kids_sync_order_to_user_profile( $user_id, WC_Order $order ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}
	$recipient  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$raw_phone  = trim( (string) $order->get_billing_phone() );
	$norm_phone = nexxi_kids_normalize_phone( $raw_phone );
	$email      = sanitize_email( $order->get_billing_email() );
	$country    = $order->get_billing_country() ? $order->get_billing_country() : 'VN';
	$city       = trim( (string) $order->get_billing_city() );
	$ward       = trim( (string) $order->get_meta( '_billing_ward' ) );
	if ( '' === $ward && ! empty( $_POST['billing_ward'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ward = sanitize_text_field( wp_unslash( $_POST['billing_ward'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	$addr1      = trim( (string) $order->get_billing_address_1() );
	if ( '' !== $ward && '' !== $addr1 ) {
		$suffix = ', ' . $ward;
		if ( substr( strtolower( $addr1 ), -strlen( strtolower( $suffix ) ) ) === strtolower( $suffix ) ) {
			$addr1 = trim( substr( $addr1, 0, -strlen( $suffix ) ) );
		}
	}

	if ( '' !== $norm_phone ) {
		update_user_meta( $user_id, 'nexxi_phone_normalized', $norm_phone );
	}
	if ( '' !== $raw_phone && '' === (string) get_user_meta( $user_id, 'billing_phone', true ) ) {
		update_user_meta( $user_id, 'billing_phone', $raw_phone );
		update_user_meta( $user_id, 'shipping_phone', $raw_phone );
	}
	if ( '' !== $ward ) {
		update_user_meta( $user_id, 'billing_ward', $ward );
		update_user_meta( $user_id, 'shipping_ward', $ward );
	}
	if ( '' !== $email && '' === (string) get_user_meta( $user_id, 'billing_email', true ) ) {
		update_user_meta( $user_id, 'billing_email', $email );
	}

	$book = get_user_meta( $user_id, 'nexxi_address_book', true );
	$book = is_array( $book ) ? array_values( $book ) : array();

	if ( '' !== $recipient && '' !== $addr1 ) {
		$already_saved = false;
		foreach ( $book as $b ) {
			if (
				isset( $b['address_1'], $b['city'] ) &&
				0 === strcasecmp( trim( (string) $b['address_1'] ), $addr1 ) &&
				0 === strcasecmp( trim( (string) $b['city'] ), $city )
			) {
				$already_saved = true;
				break;
			}
		}
		if ( ! $already_saved ) {
			$is_first = empty( $book );
			$entry    = array(
				'id'         => 'addr_' . strtolower( wp_generate_password( 8, false, false ) ),
				'label'      => $is_first ? 'Nhà riêng' : 'Địa chỉ nhận hàng',
				'recipient'  => $recipient,
				'phone'      => $raw_phone,
				'country'    => $country,
				'city'       => $city,
				'ward'       => $ward,
				'address_1'  => $addr1,
				'address_2'  => '',
				'is_default' => $is_first,
			);
			$book[] = $entry;
			nexxi_kids_save_address_book( $user_id, $book );
		}
	}
}

// Validate required Checkout fields (Name, Phone, Email, Address, City) before order creation.
add_action( 'woocommerce_after_checkout_validation', static function ( $data, $errors ) {
	$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
	$phone = isset( $data['billing_phone'] ) ? nexxi_kids_normalize_phone( $data['billing_phone'] ) : '';
	if ( '' === $email || ! is_email( $email ) ) {
		$errors->add( 'billing_email_required', 'Vui lòng nhập địa chỉ Email hợp lệ để nhận thông tin đơn hàng và tích điểm thành viên NEXXI.' );
	}
	if ( '' === $phone || strlen( $phone ) < 8 ) {
		$errors->add( 'billing_phone_required', 'Vui lòng nhập Số điện thoại nhận hàng hợp lệ.' );
	}
}, 10, 2 );

/**
 * Main Checkout handler:
 * - Guest with NO existing email/phone -> auto-create account (username = email, password = phone), link order, award points, auto-login.
 * - Guest with EXISTING email or phone -> link order to existing account, update order/profile info, award points, notify customer to log in.
 * - Logged-in user -> award points to current account.
 *
 * @param int           $order_id    Order ID.
 * @param array         $posted_data Posted checkout data.
 * @param WC_Order|null $order       Order object.
 */
function nexxi_kids_handle_checkout_account_and_points( $order_id, $posted_data = array(), $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( 'yes' === (string) $order->get_meta( '_nexxi_checkout_account_processed' ) ) {
		return;
	}

	$email         = sanitize_email( $order->get_billing_email() );
	$raw_phone     = trim( (string) $order->get_billing_phone() );
	$compact_phone = preg_replace( '/\s+/', '', $raw_phone );
	$norm_phone    = nexxi_kids_normalize_phone( $raw_phone );
	$recipient     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	// Case 1: Customer is already logged in when placing the order.
	if ( is_user_logged_in() ) {
		$uid = get_current_user_id();
		if ( (int) $order->get_customer_id() !== $uid ) {
			$order->set_customer_id( $uid );
		}
		nexxi_kids_sync_order_to_user_profile( $uid, $order );
		nexxi_kids_award_order_points( $order, $uid );
		$order->update_meta_data( '_nexxi_checkout_account_status', 'logged_in' );
		$order->update_meta_data( '_nexxi_checkout_account_processed', 'yes' );
		$order->save();
		return;
	}

	// Guest checkout: check if email or phone already exists in the account system.
	$existing_user = nexxi_kids_find_user_by_email_or_phone( $email, $raw_phone );

	// Case 2: Existing account found by Email OR Phone (Customer ordered without logging in).
	if ( $existing_user instanceof WP_User ) {
		$uid        = (int) $existing_user->ID;
		$acct_email = $existing_user->user_email ? $existing_user->user_email : $email;

		$order->set_customer_id( $uid );
		nexxi_kids_sync_order_to_user_profile( $uid, $order );
		$points = nexxi_kids_award_order_points( $order, $uid );

		$order->update_meta_data( '_nexxi_checkout_account_status', 'linked_existing' );
		$order->update_meta_data( '_nexxi_linked_account_email', $acct_email );
		$order->update_meta_data( '_nexxi_checkout_account_processed', 'yes' );
		$order->save();

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					'Đơn hàng đã được đặt, điểm thành viên Nexxi (+%d điểm) đã được cộng vào tài khoản %s. Vui lòng đăng nhập để kiểm tra điểm hoặc đổi ưu đãi (nếu có).',
					(int) $points,
					esc_html( $acct_email )
				),
				'success'
			);
		}
		return;
	}

	// Case 3: Neither Email nor Phone exists in the system -> Automatically create account!
	// Username = email, Password = phone number.
	if ( '' !== $email && is_email( $email ) && '' !== $compact_phone ) {
		$user_login = $email;
		if ( username_exists( $user_login ) ) {
			$user_login = $email . '_' . strtolower( wp_generate_password( 4, false, false ) );
		}

		$new_user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $email,
				'user_pass'    => $compact_phone,
				'first_name'   => $recipient ? $recipient : $email,
				'display_name' => $recipient ? $recipient : $email,
				'role'         => 'customer',
			)
		);

		if ( ! is_wp_error( $new_user_id ) && $new_user_id > 0 ) {
			update_user_meta( $new_user_id, 'billing_first_name', $recipient );
			update_user_meta( $new_user_id, 'billing_phone', $raw_phone );
			update_user_meta( $new_user_id, 'billing_email', $email );
			update_user_meta( $new_user_id, 'shipping_first_name', $recipient );
			update_user_meta( $new_user_id, 'shipping_phone', $raw_phone );
			update_user_meta( $new_user_id, 'nexxi_phone_normalized', $norm_phone );
			update_user_meta( $new_user_id, 'nexxi_auto_created_phone_pass', '1' );

			nexxi_kids_sync_order_to_user_profile( $new_user_id, $order );

			$order->set_customer_id( $new_user_id );
			$points = nexxi_kids_award_order_points( $order, $new_user_id );

			$order->update_meta_data( '_nexxi_checkout_account_status', 'created_new' );
			$order->update_meta_data( '_nexxi_created_account_email', $email );
			$order->update_meta_data( '_nexxi_created_account_phone', $compact_phone );
			$order->update_meta_data( '_nexxi_checkout_account_processed', 'yes' );
			$order->save();

			// Log the newly created customer in automatically so they can access their account immediately.
			if ( function_exists( 'wc_set_customer_auth_cookie' ) && ! headers_sent() ) {
				wc_set_customer_auth_cookie( $new_user_id );
			}
			wp_set_current_user( $new_user_id );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					sprintf(
						'Đơn hàng đã được đặt thành công! Tài khoản thành viên NEXXI của bạn đã được tạo tự động (Tên đăng nhập: %s | Mật khẩu: %s) và cộng +%d điểm thành viên.',
						esc_html( $email ),
						esc_html( $compact_phone ),
						(int) $points
					),
					'success'
				);
			}
		}
	}
}
add_action( 'woocommerce_checkout_order_processed', 'nexxi_kids_handle_checkout_account_and_points', 10, 3 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'nexxi_kids_handle_checkout_account_and_points', 10, 1 );

/**
 * Render prominent NEXXI Club Account & Points notification banner on the Order Received (Thank You) page.
 *
 * @param int $order_id Order ID.
 */
function nexxi_kids_render_thankyou_account_banner( $order_id ) {
	static $rendered = array();
	$order_id = (int) $order_id;
	if ( $order_id <= 0 || isset( $rendered[ $order_id ] ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$rendered[ $order_id ] = true;

	$status = (string) $order->get_meta( '_nexxi_checkout_account_status' );
	$points = (int) $order->get_meta( '_nexxi_points_earned' );
	if ( $points <= 0 ) {
		$points = nexxi_kids_calculate_order_points( $order );
	}
	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/tai-khoan/' );

	if ( 'linked_existing' === $status ) {
		$acct_email = (string) $order->get_meta( '_nexxi_linked_account_email' );
		if ( '' === $acct_email ) {
			$acct_email = $order->get_billing_email();
		}
		$login_url = add_query_arg( 'nexxi_login_email', rawurlencode( $acct_email ), $account_url );
		?>
		<div class="nexxi-thankyou-account-banner mb-space-lg p-space-lg rounded-2xl bg-gradient-to-r from-secondary-fixed/80 via-surface-container-lowest to-primary-fixed/50 border-2 border-secondary shadow-md">
			<div class="flex flex-col md:flex-row md:items-center justify-between gap-space-md">
				<div class="flex items-start gap-space-md">
					<span class="w-12 h-12 rounded-full bg-secondary text-on-secondary flex items-center justify-center shrink-0 shadow-sm mt-0.5">
						<span class="material-symbols-outlined text-[26px]">stars</span>
					</span>
					<div class="space-y-1.5">
						<div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm font-bold uppercase tracking-wider">
							<span>+<?php echo (int) $points; ?> Điểm Thành Viên NEXXI</span>
						</div>
						<p class="font-title-md text-title-md text-primary font-bold leading-relaxed m-0">
							Đơn hàng đã được đặt, điểm thành viên Nexxi đã được cộng vào tài khoản <span class="text-secondary underline decoration-2 underline-offset-2"><?php echo esc_html( $acct_email ); ?></span>. Vui lòng đăng nhập để kiểm tra điểm hoặc đổi ưu đãi ( nếu có ).
						</p>
						<p class="font-body-sm text-body-sm text-on-surface-variant m-0">
							Thông tin đơn hàng #<?php echo esc_html( $order->get_order_number() ); ?> và <strong>+<?php echo (int) $points; ?> điểm</strong> tích lũy đã được cập nhật tự động vào hồ sơ thành viên của bạn.
						</p>
					</div>
				</div>
				<a href="<?php echo esc_url( $login_url ); ?>" class="inline-flex items-center justify-center gap-2 bg-primary hover:bg-primary-container text-on-primary px-space-lg py-3.5 rounded-full font-label-lg text-label-lg font-bold shadow-md transition-all shrink-0 no-underline">
					<span class="material-symbols-outlined text-[20px]">login</span>
					<span>Đăng nhập kiểm tra điểm</span>
				</a>
			</div>
		</div>
		<?php
		return;
	}

	if ( 'created_new' === $status ) {
		$login_email = (string) $order->get_meta( '_nexxi_created_account_email' );
		$login_phone = (string) $order->get_meta( '_nexxi_created_account_phone' );
		if ( '' === $login_email ) {
			$login_email = $order->get_billing_email();
		}
		if ( '' === $login_phone ) {
			$login_phone = preg_replace( '/\s+/', '', (string) $order->get_billing_phone() );
		}
		?>
		<div class="nexxi-thankyou-account-banner mb-space-lg p-space-lg rounded-2xl bg-gradient-to-r from-secondary-fixed/80 via-surface-container-lowest to-primary-fixed/50 border-2 border-secondary shadow-md">
			<div class="flex flex-col md:flex-row md:items-center justify-between gap-space-md">
				<div class="flex items-start gap-space-md">
					<span class="w-12 h-12 rounded-full bg-secondary text-on-secondary flex items-center justify-center shrink-0 shadow-sm mt-0.5">
						<span class="material-symbols-outlined text-[26px]">how_to_reg</span>
					</span>
					<div class="space-y-2">
						<div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm font-bold uppercase tracking-wider">
							<span>Tài Khoản Thành Viên Mới • +<?php echo (int) $points; ?> Điểm NEXXI</span>
						</div>
						<p class="font-title-md text-title-md text-primary font-bold leading-relaxed m-0">
							Đơn hàng đã được đặt thành công! Hệ thống đã tự động tạo tài khoản thành viên NEXXI và cộng <span class="text-secondary">+<?php echo (int) $points; ?> điểm</span> cho bạn:
						</p>
						<div class="flex flex-wrap items-center gap-3 pt-1">
							<span class="px-3.5 py-1.5 rounded-xl bg-surface-container-lowest border border-outline-variant/40 font-body-md text-body-md text-on-surface">
								Tên đăng nhập (Email): <strong class="text-primary"><?php echo esc_html( $login_email ); ?></strong>
							</span>
							<span class="px-3.5 py-1.5 rounded-xl bg-surface-container-lowest border border-outline-variant/40 font-body-md text-body-md text-on-surface">
								Mật khẩu (Số điện thoại): <strong class="text-secondary"><?php echo esc_html( $login_phone ); ?></strong>
							</span>
						</div>
					</div>
				</div>
				<a href="<?php echo esc_url( $account_url ); ?>" class="inline-flex items-center justify-center gap-2 bg-primary hover:bg-primary-container text-on-primary px-space-lg py-3.5 rounded-full font-label-lg text-label-lg font-bold shadow-md transition-all shrink-0 no-underline">
					<span class="material-symbols-outlined text-[20px]">account_circle</span>
					<span>Quản lý tài khoản &amp; điểm</span>
				</a>
			</div>
		</div>
		<?php
		return;
	}

	if ( $points > 0 ) {
		?>
		<div class="nexxi-thankyou-account-banner mb-space-lg p-space-md rounded-2xl bg-surface-container-low border border-secondary-fixed flex flex-wrap items-center justify-between gap-space-sm">
			<div class="flex items-center gap-space-sm">
				<span class="w-10 h-10 rounded-full bg-secondary-fixed text-secondary flex items-center justify-center shrink-0">
					<span class="material-symbols-outlined text-[22px]">stars</span>
				</span>
				<span class="font-body-md text-body-md text-primary">
					Đơn hàng đã được đặt thành công! Tài khoản thành viên NEXXI của bạn vừa được cộng thêm <strong class="text-secondary">+<?php echo (int) $points; ?> điểm</strong>.
				</span>
			</div>
			<a href="<?php echo esc_url( $account_url ); ?>" class="font-label-md text-label-md text-secondary font-bold hover:underline">Kiểm tra điểm thành viên →</a>
		</div>
		<?php
	}
}
add_action( 'woocommerce_before_thankyou', 'nexxi_kids_render_thankyou_account_banner', 5 );
add_action( 'woocommerce_thankyou', 'nexxi_kids_render_thankyou_account_banner', 5 );

/* ------------------------------------------------------------------ Login & Registration Enhancements (Email or Phone Login) */

// Allow customers to log in using their Email OR Phone number, and handle phone-as-password with or without spaces.
add_filter( 'authenticate', static function ( $user, $username, $password ) {
	if ( $user instanceof WP_User ) {
		return $user;
	}
	$username = trim( (string) $username );
	$password = (string) $password;
	if ( '' === $username || '' === $password ) {
		return $user;
	}

	$candidate = nexxi_kids_find_user_by_email_or_phone(
		is_email( $username ) ? $username : '',
		! is_email( $username ) ? $username : ''
	);
	if ( ! $candidate instanceof WP_User ) {
		return $user;
	}

	// Check exact password first, then trimmed / space-normalized password (for phone number passwords).
	$pass_candidates = array_unique(
		array_filter(
			array(
				$password,
				trim( $password ),
				preg_replace( '/\s+/', '', $password ),
				nexxi_kids_normalize_phone( $password ),
			)
		)
	);
	foreach ( $pass_candidates as $try_pass ) {
		if ( wp_check_password( $try_pass, $candidate->user_pass, $candidate->ID ) ) {
			return $candidate;
		}
	}

	return $user;
}, 30, 3 );

// Save name and phone number when a customer registers directly on /tai-khoan/.
add_action( 'woocommerce_created_customer', static function ( $customer_id ) {
	if ( ! empty( $_POST['billing_first_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name = sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_user_meta( $customer_id, 'first_name', $name );
		update_user_meta( $customer_id, 'billing_first_name', $name );
		update_user_meta( $customer_id, 'shipping_first_name', $name );
		wp_update_user( array( 'ID' => $customer_id, 'display_name' => $name ) );
	}
	if ( ! empty( $_POST['billing_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_user_meta( $customer_id, 'billing_phone', $phone );
		update_user_meta( $customer_id, 'shipping_phone', $phone );
		update_user_meta( $customer_id, 'nexxi_phone_normalized', nexxi_kids_normalize_phone( $phone ) );
	}
}, 20 );


