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
			'address_1'  => $ship_addr,
			'address_2'  => $customer->get_shipping_address_2(),
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
			'address_1'  => $bill_addr,
			'address_2'  => $customer->get_billing_address_2(),
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
	$address_1 = trim( isset( $addr['address_1'] ) ? (string) $addr['address_1'] : '' );
	$address_2 = trim( isset( $addr['address_2'] ) ? (string) $addr['address_2'] : '' );
	$email     = $user ? $user->user_email : '';

	foreach ( array( 'billing', 'shipping' ) as $prefix ) {
		update_user_meta( $user_id, "{$prefix}_first_name", $recipient );
		update_user_meta( $user_id, "{$prefix}_last_name", '' );
		update_user_meta( $user_id, "{$prefix}_phone", $phone );
		update_user_meta( $user_id, "{$prefix}_country", $country );
		update_user_meta( $user_id, "{$prefix}_city", $city );
		update_user_meta( $user_id, "{$prefix}_address_1", $address_1 );
		update_user_meta( $user_id, "{$prefix}_address_2", $address_2 );
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
		$address_1 = ! empty( $_POST['addr_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_address_1'] ) ) : '';
		$address_2 = ! empty( $_POST['addr_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['addr_address_2'] ) ) : '';
		$make_def  = ! empty( $_POST['addr_is_default'] ) || empty( $book );

		if ( '' === $recipient || '' === $phone || '' === $city || '' === $address_1 ) {
			wc_add_notice( 'Mẹ vui lòng điền đầy đủ Họ tên người nhận, Số điện thoại, Tỉnh/Thành phố và Địa chỉ nhận hàng nhé.', 'error' );
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
			'address_1'  => $address_1,
			'address_2'  => $address_2,
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
		isset( $addr['address_2'] ) && '' !== trim( $addr['address_2'] ) ? '(' . trim( $addr['address_2'] ) . ')' : '',
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
	$val_city      = $editing ? $editing['city'] : '';
	$val_addr1     = $editing ? $editing['address_1'] : '';
	$val_addr2     = $editing && isset( $editing['address_2'] ) ? $editing['address_2'] : '';
	$val_def       = $editing ? ! empty( $editing['is_default'] ) : true;
	$input_cls     = 'w-full bg-surface-container-low border border-outline-variant/40 rounded-full px-4 py-3 font-body-md text-body-md text-on-surface outline-none focus:bg-surface-container-lowest focus:border-secondary-container transition-all';

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

<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-recipient">Họ và tên người nhận <span class="text-secondary">*</span></label>
<input id="nexxi-addr-recipient" name="addr_recipient" type="text" required value="<?php echo esc_attr( $val_recipient ); ?>" placeholder="Nhập họ tên người nhận hàng" class="<?php echo esc_attr( $input_cls ); ?>">
</div>
<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-phone">Số điện thoại nhận hàng <span class="text-secondary">*</span></label>
<input id="nexxi-addr-phone" name="addr_phone" type="tel" required value="<?php echo esc_attr( $val_phone ); ?>" placeholder="Ví dụ: 0938 123 882" class="<?php echo esc_attr( $input_cls ); ?>">
</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-space-md">
<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-country">Quốc gia / Khu vực <span class="text-secondary">*</span></label>
<select id="nexxi-addr-country" name="addr_country" required class="<?php echo esc_attr( $input_cls ); ?>">
<?php foreach ( $countries as $code => $cname ) : ?>
<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $val_country, $code ); ?>><?php echo esc_html( $cname ); ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-city">Tỉnh / Thành phố <span class="text-secondary">*</span></label>
<input id="nexxi-addr-city" name="addr_city" type="text" required value="<?php echo esc_attr( $val_city ); ?>" placeholder="Ví dụ: TP. Hồ Chí Minh, Hà Nội..." class="<?php echo esc_attr( $input_cls ); ?>">
</div>
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-line1">Địa chỉ nhận hàng cụ thể (Số nhà, đường, phường/xã, quận/huyện) <span class="text-secondary">*</span></label>
<input id="nexxi-addr-line1" name="addr_address_1" type="text" required value="<?php echo esc_attr( $val_addr1 ); ?>" placeholder="Số nhà, tên đường, phường/xã, quận/huyện..." class="<?php echo esc_attr( $input_cls ); ?>">
</div>

<div>
<label class="block font-label-md text-label-md text-primary font-bold mb-1.5" for="nexxi-addr-line2">Ghi chú địa chỉ (Tòa nhà, số căn hộ, giờ nhận... - không bắt buộc)</label>
<input id="nexxi-addr-line2" name="addr_address_2" type="text" value="<?php echo esc_attr( $val_addr2 ); ?>" placeholder="Căn hộ, tòa nhà, lời nhắn cho shipper (nếu có)..." class="<?php echo esc_attr( $input_cls ); ?>">
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
	$book = nexxi_kids_get_address_book( $uid );
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
		'address_1' => isset( $addr['address_1'] ) ? $addr['address_1'] : '',
		'address_2' => isset( $addr['address_2'] ) ? $addr['address_2'] : '',
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
		setVal('billing_country', d.country || 'VN');
		setVal('billing_city', d.city || '');
		setVal('billing_address_1', d.address_1 || '');
		setVal('billing_address_2', d.address_2 || '');
		setVal('shipping_first_name', d.recipient || '');
		setVal('shipping_last_name', '');
		setVal('shipping_phone', d.phone || '');
		setVal('shipping_country', d.country || 'VN');
		setVal('shipping_city', d.city || '');
		setVal('shipping_address_1', d.address_1 || '');
		setVal('shipping_address_2', d.address_2 || '');
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
			'address_1'  => $addr1,
			'address_2'  => isset( $data['billing_address_2'] ) ? $data['billing_address_2'] : '',
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

// Remove postcode & separate last_name from default address fields, and set default country to VN.
add_filter( 'woocommerce_default_address_fields', static function ( $fields ) {
	unset( $fields['postcode'], $fields['last_name'], $fields['company'] );
	if ( isset( $fields['first_name'] ) ) {
		$fields['first_name']['label'] = 'Họ và tên người nhận';
		$fields['first_name']['class'] = array( 'form-row-wide' );
	}
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

