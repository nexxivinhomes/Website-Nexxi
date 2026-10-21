<?php
/**
 * NEXXI Flash Sale & Product Discount Manager (WP Admin).
 *
 * Solves two problems for the shop owner:
 *  1. Easy visual setup of the Flash Sale countdown timer (00:00:00 when inactive).
 *  2. 1-click discount / sale price setup for Variable Products (which otherwise
 *     hide sale price fields inside dozens of individual size/color variations).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Register top-level + Products submenu for Flash Sale & Discounts. */
function nexxi_kids_flash_sale_admin_menu() {
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	add_menu_page(
		'NEXXI Flash Sale & Giảm Giá',
		'⚡ Flash Sale & Sale',
		'manage_woocommerce',
		'nexxi-flash-sale',
		'nexxi_kids_flash_sale_admin_page',
		'dashicons-megaphone',
		56
	);
	add_submenu_page(
		'edit.php?post_type=product',
		'NEXXI Flash Sale & Giảm Giá',
		'⚡ Flash Sale & Giảm Giá',
		'manage_woocommerce',
		'nexxi-flash-sale',
		'nexxi_kids_flash_sale_admin_page'
	);
}
add_action( 'admin_menu', 'nexxi_kids_flash_sale_admin_menu', 20 );

/** Apply a discount (% or fixed sale price or clear) to a product and all its variations. */
function nexxi_kids_apply_product_discount( $product_id, $mode, $amount = 0, $regular_override = 0 ) {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
	if ( ! $product ) { return false; }

	$apply_to_item = static function ( WC_Product $item ) use ( $mode, $amount, $regular_override ) {
		$reg = (float) $item->get_regular_price( 'edit' );
		if ( $regular_override > 0 ) {
			$reg = (float) $regular_override;
			$item->set_regular_price( (string) round( $reg ) );
		}
		if ( 'clear' === $mode || $amount <= 0 ) {
			$item->set_sale_price( '' );
			$item->set_date_on_sale_from( null );
			$item->set_date_on_sale_to( null );
			if ( $reg > 0 ) {
				$item->set_price( (string) round( $reg ) );
			}
			$item->save();
			return;
		}
		if ( $reg <= 0 ) {
			return;
		}
		if ( 'percent' === $mode ) {
			$pct = min( 95, max( 1, (float) $amount ) );
			$sale = round( $reg * ( 100 - $pct ) / 100, -3 ); // Round to nearest 1.000₫
			if ( $sale <= 0 || $sale >= $reg ) {
				$sale = max( 1000, round( $reg * ( 100 - $pct ) / 100 ) );
			}
		} else {
			$sale = (float) $amount;
		}
		if ( $sale > 0 && $sale < $reg ) {
			$item->set_sale_price( (string) round( $sale ) );
			$item->set_price( (string) round( $sale ) );
		} else {
			$item->set_sale_price( '' );
			$item->set_price( (string) round( $reg ) );
		}
		$item->save();
	};

	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$apply_to_item( $variation );
			}
		}
		WC_Product_Variable::sync( $product->get_id() );
		$product->save();
	} else {
		$apply_to_item( $product );
	}

	wc_delete_product_transients( $product->get_id() );
	return true;
}

/** Flush WooCommerce on-sale cache so homepage & Flash Sale page reflect changes immediately. */
function nexxi_kids_flush_sale_cache() {
	delete_transient( 'wc_products_onsale' );
	if ( function_exists( 'wc_Cache_Helper' ) || class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'product', true );
	}
}

/** Handle POST actions from the Flash Sale Admin page. */
function nexxi_kids_handle_flash_sale_admin_post() {
	if ( empty( $_POST['nexxi_fs_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' );
	$action = sanitize_key( wp_unslash( $_POST['nexxi_fs_action'] ) );

	if ( 'save_timer' === $action ) {
		$enabled = ! empty( $_POST['nexxi_flash_sale_enabled'] ) ? '1' : '0';
		$end_raw = isset( $_POST['nexxi_flash_sale_end'] ) ? sanitize_text_field( wp_unslash( $_POST['nexxi_flash_sale_end'] ) ) : '';
		update_option( 'nexxi_flash_sale_enabled', $enabled );
		update_option( 'nexxi_flash_sale_end', $end_raw );
		set_theme_mod( 'nexxi_kids_flash_sale_end', $end_raw );
		add_settings_error( 'nexxi_fs', 'timer_saved', 'Đã lưu cài đặt bộ đếm giờ Flash Sale!', 'updated' );
	} elseif ( 'single_discount' === $action ) {
		$pid  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$mode = isset( $_POST['discount_mode'] ) ? sanitize_key( wp_unslash( $_POST['discount_mode'] ) ) : 'percent';
		$val  = isset( $_POST['discount_value'] ) ? (float) preg_replace( '~[^\d.]~', '', wp_unslash( $_POST['discount_value'] ) ) : 0;
		if ( $pid && nexxi_kids_apply_product_discount( $pid, $mode, $val ) ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_fs', 'single_saved', 'Đã cập nhật giá khuyến mãi cho sản phẩm #' . $pid . '!', 'updated' );
		}
	} elseif ( 'bulk_discount' === $action ) {
		$pids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) : array();
		$mode = isset( $_POST['bulk_mode'] ) ? sanitize_key( wp_unslash( $_POST['bulk_mode'] ) ) : 'percent';
		$val  = isset( $_POST['bulk_value'] ) ? (float) preg_replace( '~[^\d.]~', '', wp_unslash( $_POST['bulk_value'] ) ) : 0;
		$count = 0;
		foreach ( $pids as $pid ) {
			if ( $pid && nexxi_kids_apply_product_discount( $pid, $mode, $val ) ) {
				$count++;
			}
		}
		if ( $count > 0 ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_fs', 'bulk_saved', 'Đã áp dụng giảm giá cho ' . $count . ' sản phẩm!', 'updated' );
		} else {
			add_settings_error( 'nexxi_fs', 'bulk_empty', 'Vui lòng tích chọn ít nhất 1 sản phẩm trong bảng bên dưới.', 'error' );
		}
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_flash_sale_admin_post' );

/** Render the Flash Sale & Product Discount Admin screen. */
function nexxi_kids_flash_sale_admin_page() {
	$enabled = '0' !== (string) get_option( 'nexxi_flash_sale_enabled', '1' );
	$end_raw = trim( (string) get_option( 'nexxi_flash_sale_end', get_theme_mod( 'nexxi_kids_flash_sale_end', '' ) ) );
	$end_input_val = '';
	if ( '' !== $end_raw ) {
		try {
			$dt = new DateTimeImmutable( $end_raw, wp_timezone() );
			$end_input_val = $dt->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
		} catch ( Exception $e ) {
			$ts = strtotime( $end_raw );
			if ( $ts ) { $end_input_val = wp_date( 'Y-m-d\TH:i', $ts ); }
		}
	}

	$sale_parent_ids = function_exists( 'wc_get_product_ids_on_sale' ) ? nexxi_kids_sale_parent_ids( wc_get_product_ids_on_sale() ) : array();
	$active_ts = nexxi_kids_flash_end();

	// Filters for product table
	$search = isset( $_GET['s_prod'] ) ? sanitize_text_field( wp_unslash( $_GET['s_prod'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cat    = isset( $_GET['cat_slug'] ) ? sanitize_title( wp_unslash( $_GET['cat_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status = isset( $_GET['sale_filter'] ) ? sanitize_key( wp_unslash( $_GET['sale_filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per_page = 25;

	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( '' !== $search ) {
		$query_args['s'] = $search;
	}
	if ( '' !== $cat ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $cat,
			),
		);
	}
	if ( 'onsale' === $status ) {
		$query_args['post__in'] = $sale_parent_ids ? $sale_parent_ids : array( 0 );
	} elseif ( 'regular' === $status && $sale_parent_ids ) {
		$query_args['post__not_in'] = $sale_parent_ids;
	}

	$wp_q = new WP_Query( $query_args );
	$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	?>
	<div class="wrap" style="max-width:1280px;margin-top:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
			<div>
				<h1 style="margin:0;font-size:24px;font-weight:800;color:#031444;display:flex;align-items:center;gap:8px;">
					<span>⚡ Quản Lý Flash Sale &amp; Giảm Giá Sản Phẩm NEXXI</span>
				</h1>
				<p style="margin:4px 0 0;color:#555;font-size:13.5px;">
					Thiết lập đồng hồ đếm ngược Flash Sale và chỉnh giá khuyến mãi nhanh cho cả sản phẩm đơn lẫn sản phẩm có nhiều biến thể (Màu / Size).
				</p>
			</div>
			<div style="display:flex;gap:8px;">
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">Xem Trang Chủ ↗</a>
				<a class="button button-primary" href="<?php echo esc_url( nexxi_kids_flash_url() ); ?>" target="_blank" style="background:#fd6a49;border-color:#fd6a49;">Xem Trang Flash Sale ↗</a>
			</div>
		</div>

		<?php settings_errors( 'nexxi_fs' ); ?>

		<!-- CARD 1: FLASH SALE COUNTDOWN TIMER SETUP -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;margin-bottom:22px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f0eee9;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">1. Bộ Đếm Giờ Flash Sale (Trang Chủ &amp; Trang Flash Sale)</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						Khi <b>không có sản phẩm giảm giá</b> hoặc <b>chưa cài thời gian / đã hết giờ</b>, bộ đếm ngoài web tự động đứng ở <code>00 : 00 : 00</code>.
					</p>
				</div>
				<div>
					<?php if ( $active_ts > time() ) : ?>
						<span style="display:inline-flex;align-items:center;gap:6px;background:#e6f9ed;color:#0b6b2f;font-weight:700;font-size:12.5px;padding:6px 14px;border-radius:999px;border:1px solid #b6ecc8;">
							● ĐANG CHẠY ĐẾM NGƯỢC (Kết thúc: <?php echo esc_html( wp_date( 'H:i - d/m/Y', $active_ts ) ); ?> · <?php echo count( $sale_parent_ids ); ?> sản phẩm đang sale)
						</span>
					<?php else : ?>
						<span style="display:inline-flex;align-items:center;gap:6px;background:#fff4f1;color:#ae3115;font-weight:700;font-size:12.5px;padding:6px 14px;border-radius:999px;border:1px solid #ffdad2;">
							○ ĐỒNG HỒ ĐANG Ở 00:00:00 (<?php echo empty( $sale_parent_ids ) ? 'Chưa có sản phẩm nào giảm giá' : 'Chưa đặt giờ kết thúc hoặc đã hết giờ'; ?>)
						</span>
					<?php endif; ?>
				</div>
			</div>

			<form method="post" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:18px;">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="save_timer">

				<label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#031444;padding:8px 14px;background:#f5f3ef;border-radius:10px;cursor:pointer;">
					<input type="checkbox" name="nexxi_flash_sale_enabled" value="1" <?php checked( $enabled ); ?>>
					<span>Bật chạy đồng hồ Flash Sale</span>
				</label>

				<div>
					<label style="display:block;font-size:12.5px;font-weight:700;color:#031444;margin-bottom:5px;">Thời gian kết thúc Flash Sale (Giờ Việt Nam):</label>
					<input type="datetime-local" id="nexxi-fs-end-input" name="nexxi_flash_sale_end" value="<?php echo esc_attr( $end_input_val ); ?>" style="min-height:38px;padding:4px 12px;border-radius:8px;border:1px solid #c6c6d0;font-size:14px;font-weight:600;color:#031444;">
				</div>

				<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
					<span style="font-size:12px;color:#666;margin-right:2px;">Chọn nhanh:</span>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(3)">+3 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(6)">+6 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(12)">+12 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd(24)">+24 Giờ</button>
					<button type="button" class="button" onclick="nexxiSetQuickEnd('eod')">Cuối ngày hôm nay (23:59)</button>
					<button type="button" class="button" onclick="document.getElementById('nexxi-fs-end-input').value='';" style="color:#ae3115;">Về 00:00:00</button>
				</div>

				<button type="submit" class="button button-primary" style="min-height:38px;padding:0 20px;border-radius:8px;font-weight:700;background:#031444;border-color:#031444;">
					💾 Lưu Thời Gian Flash Sale
				</button>
			</form>
		</div>

		<!-- CARD 2: PRODUCT DISCOUNT & FLASH SALE TABLE -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">
						2. Thiết Lập Giảm Giá Sản Phẩm &amp; Đưa Lên Kệ Flash Sale
						<span style="background:#ffdad2;color:#3d0600;font-size:12px;padding:3px 10px;border-radius:999px;margin-left:6px;">Đang có <?php echo count( $sale_parent_ids ); ?> mẫu giảm giá</span>
					</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						💡 <b>Mẹo:</b> Bất kỳ sản phẩm nào được bật giảm giá tại đây sẽ <b>tự động hiển thị ở mục Flash Sale Trang Chủ</b>, <b>Trang Flash Sale</b> và có <b>nhãn -XX% SALE</b> ở Cửa hàng. Hỗ trợ tự động áp dụng cho toàn bộ biến thể Màu / Size!
					</p>
				</div>
			</div>

			<!-- Filter & Search Bar -->
			<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f5f3ef;padding:12px 14px;border-radius:12px;margin-bottom:14px;">
				<input type="hidden" name="page" value="nexxi-flash-sale">
				<input type="search" name="s_prod" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm tên hoặc mã sản phẩm..." style="min-width:220px;min-height:34px;border-radius:8px;border:1px solid #d5d3ce;padding:0 10px;">
				<select name="cat_slug" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="">— Tất cả danh mục —</option>
					<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $c_term ) : ?>
						<option value="<?php echo esc_attr( $c_term->slug ); ?>" <?php selected( $cat, $c_term->slug ); ?>><?php echo esc_html( $c_term->name ); ?> (<?php echo (int) $c_term->count; ?>)</option>
					<?php endforeach; endif; ?>
				</select>
				<select name="sale_filter" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="all" <?php selected( $status, 'all' ); ?>>Tất cả trạng thái</option>
					<option value="onsale" <?php selected( $status, 'onsale' ); ?>>🔥 Đang giảm giá (Flash Sale) (<?php echo count( $sale_parent_ids ); ?>)</option>
					<option value="regular" <?php selected( $status, 'regular' ); ?>>Chưa giảm giá</option>
				</select>
				<button type="submit" class="button" style="min-height:34px;">Lọc sản phẩm</button>
				<?php if ( '' !== $search || '' !== $cat || 'all' !== $status ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>" style="min-height:34px;">Xóa lọc</a>
				<?php endif; ?>
			</form>

			<!-- Bulk Discount Bar -->
			<form id="nexxi-bulk-sale-form" method="post">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="bulk_discount">
				<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 14px;background:#fff8f6;border:1px solid #ffdad2;border-radius:12px;margin-bottom:14px;">
					<strong style="color:#ae3115;font-size:13px;">⚡ Giảm giá hàng loạt (cho các mẫu đã tích chọn):</strong>
					<select name="bulk_mode" id="nexxi-bulk-mode" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
						<option value="percent">Giảm theo % (so với giá gốc)</option>
						<option value="fixed">Đặt đồng giá khuyến mãi (₫)</option>
						<option value="clear">Tắt giảm giá (Đưa về giá gốc)</option>
					</select>
					<input type="number" name="bulk_value" id="nexxi-bulk-val" value="25" min="0" step="1" placeholder="Nhập % hoặc số tiền" style="width:120px;min-height:34px;border-radius:8px;border:1px solid #d5d3ce;padding:0 10px;font-weight:700;">
					<div style="display:flex;gap:4px;">
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='25';">-25%</button>
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='40';">-40%</button>
						<button type="button" class="button" onclick="document.getElementById('nexxi-bulk-mode').value='percent';document.getElementById('nexxi-bulk-val').value='50';">-50%</button>
					</div>
					<button type="submit" class="button button-primary" style="min-height:34px;background:#fd6a49;border-color:#fd6a49;font-weight:700;">
						Áp dụng cho các sản phẩm đã chọn
					</button>
				</div>

				<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden;border:1px solid #e4e2de;">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column" style="width:36px;"><input type="checkbox" onclick="document.querySelectorAll('.nexxi-prod-cb').forEach(c=>c.checked=this.checked)"></td>
							<th style="width:60px;">Ảnh</th>
							<th>Sản phẩm</th>
							<th style="width:150px;">Giá gốc</th>
							<th style="width:170px;">Giá đang bán</th>
							<th style="width:380px;">Chỉnh nhanh Giảm Giá / Flash Sale</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $wp_q->have_posts() ) : ?>
							<tr><td colspan="6" style="padding:24px;text-align:center;color:#666;">Không tìm thấy sản phẩm nào phù hợp.</td></tr>
						<?php else : foreach ( $wp_q->posts as $post_obj ) :
							$prod = wc_get_product( $post_obj->ID );
							if ( ! $prod ) { continue; }
							$pid = $prod->get_id();
							$range = nexxi_kids_price_range( $prod );
							$on_sale = $prod->is_on_sale() && $range['regular'] > $range['min'];
							$discount_pct = $on_sale && $range['regular'] > 0 ? round( ( 1 - $range['min'] / $range['regular'] ) * 100 ) : 0;
							$thumb = wp_get_attachment_image_url( $prod->get_image_id(), 'thumbnail' );
							$cat_term = nexxi_kids_product_category( $prod );
							$children_count = $prod->is_type( 'variable' ) ? count( $prod->get_children() ) : 0;
						?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" class="nexxi-prod-cb" name="product_ids[]" value="<?php echo (int) $pid; ?>">
								</th>
								<td>
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:46px;height:46px;object-fit:cover;border-radius:8px;border:1px solid #eee;">
									<?php else : ?>
										<div style="width:46px;height:46px;border-radius:8px;background:#f5f3ef;"></div>
									<?php endif; ?>
								</td>
								<td>
									<strong style="font-size:14px;"><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="color:#031444;text-decoration:none;"><?php echo esc_html( $prod->get_name() ); ?></a></strong>
									<div style="font-size:12px;color:#767680;margin-top:2px;">
										<?php echo $cat_term ? esc_html( $cat_term->name ) . ' · ' : ''; ?>
										<?php echo $children_count ? 'Có ' . (int) $children_count . ' biến thể (Size/Màu)' : 'Sản phẩm đơn'; ?>
										· <a href="<?php echo esc_url( $prod->get_permalink() ); ?>" target="_blank">Xem ngoài web ↗</a>
									</div>
								</td>
								<td style="font-weight:600;color:#45464f;">
									<?php echo $range['regular'] > 0 ? esc_html( nexxi_kids_money( $range['regular'] ) ) : '<span style="color:#ba1a1a;">Chưa có giá gốc</span>'; ?>
								</td>
								<td>
									<?php if ( $on_sale ) : ?>
										<span style="font-weight:800;color:#ae3115;font-size:14px;"><?php echo esc_html( nexxi_kids_money( $range['min'] ) ); ?></span>
										<span style="display:inline-block;background:#fd6a49;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;margin-left:4px;">-<?php echo (int) $discount_pct; ?>%</span>
									<?php else : ?>
										<span style="color:#767680;"><?php echo $range['min'] > 0 ? esc_html( nexxi_kids_money( $range['min'] ) ) : '—'; ?></span>
									<?php endif; ?>
								</td>
								<td>
									<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',25)">-25%</button>
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',40)">-40%</button>
										<button type="button" class="button button-small" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'percent',50)">-50%</button>
										<input type="number" id="nx-val-<?php echo (int) $pid; ?>" placeholder="% hoặc Giá ₫" value="<?php echo $discount_pct > 0 ? (int) $discount_pct : ''; ?>" style="width:92px;min-height:28px;font-size:12px;padding:0 6px;border-radius:6px;border:1px solid #c6c6d0;">
										<button type="button" class="button button-small button-primary" style="background:#031444;border-color:#031444;" onclick="nexxiSubmitRowInput(<?php echo (int) $pid; ?>)">Lưu</button>
										<?php if ( $on_sale ) : ?>
											<button type="button" class="button button-small" style="color:#ba1a1a;border-color:#ffdad6;" onclick="nexxiQuickRowSale(<?php echo (int) $pid; ?>,'clear',0)">Tắt sale</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</form>

			<!-- Hidden single-product form -->
			<form id="nexxi-single-sale-form" method="post" style="display:none;">
				<?php wp_nonce_field( 'nexxi_flash_sale_admin', 'nexxi_fs_nonce' ); ?>
				<input type="hidden" name="nexxi_fs_action" value="single_discount">
				<input type="hidden" name="product_id" id="nx-single-pid" value="">
				<input type="hidden" name="discount_mode" id="nx-single-mode" value="">
				<input type="hidden" name="discount_value" id="nx-single-val" value="">
			</form>

			<?php if ( $wp_q->max_num_pages > 1 ) : ?>
				<div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;">
					<span style="color:#666;font-size:13px;">Trang <?php echo (int) $paged; ?> / <?php echo (int) $wp_q->max_num_pages; ?> (Tổng <?php echo (int) $wp_q->found_posts; ?> sản phẩm)</span>
					<div style="display:flex;gap:6px;">
						<?php for ( $i = 1; $i <= $wp_q->max_num_pages; $i++ ) :
							$link = add_query_arg( 'paged', $i ); ?>
							<a class="button <?php echo $i === $paged ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $link ); ?>"><?php echo (int) $i; ?></a>
						<?php endfor; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
	function nexxiSetQuickEnd(hours) {
		var now = new Date();
		if (hours === 'eod') {
			now.setHours(23, 59, 0, 0);
		} else {
			now.setTime(now.getTime() + hours * 3600 * 1000);
		}
		var pad = function(n) { return String(n).padStart(2, '0'); };
		var formatted = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()) + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
		document.getElementById('nexxi-fs-end-input').value = formatted;
	}
	function nexxiQuickRowSale(pid, mode, val) {
		document.getElementById('nx-single-pid').value = pid;
		document.getElementById('nx-single-mode').value = mode;
		document.getElementById('nx-single-val').value = val;
		document.getElementById('nexxi-single-sale-form').submit();
	}
	function nexxiSubmitRowInput(pid) {
		var raw = (document.getElementById('nx-val-' + pid).value || '').trim();
		var num = parseFloat(raw);
		if (!num || num <= 0) {
			nexxiQuickRowSale(pid, 'clear', 0);
			return;
		}
		// <= 95 is treated as percentage discount; > 95 is treated as fixed VND sale price
		var mode = num <= 95 ? 'percent' : 'fixed';
		nexxiQuickRowSale(pid, mode, num);
	}
	</script>
	<?php
}

/** Add a Quick Discount Metabox directly on the WooCommerce Product Edit screen. */
function nexxi_kids_add_product_discount_metabox() {
	add_meta_box(
		'nexxi_quick_discount_box',
		'⚡ NEXXI – Giảm Giá & Flash Sale Nhanh',
		'nexxi_kids_render_product_discount_metabox',
		'product',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'nexxi_kids_add_product_discount_metabox' );

function nexxi_kids_render_product_discount_metabox( WP_Post $post ) {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
	$range = $product ? nexxi_kids_price_range( $product ) : array( 'min' => 0, 'max' => 0, 'regular' => 0 );
	$on_sale = $product && $product->is_on_sale() && $range['regular'] > $range['min'];
	$pct = $on_sale && $range['regular'] > 0 ? round( ( 1 - $range['min'] / $range['regular'] ) * 100 ) : 0;
	wp_nonce_field( 'nexxi_product_quick_sale', 'nexxi_product_quick_sale_nonce' );
	?>
	<div style="font-size:12.5px;line-height:1.5;">
		<?php if ( $range['regular'] > 0 ) : ?>
			<p style="margin:0 0 8px;">
				Giá gốc hiện tại: <strong><?php echo esc_html( nexxi_kids_money( $range['regular'] ) ); ?></strong><br>
				<?php if ( $on_sale ) : ?>
					Đang khuyến mãi: <strong style="color:#ae3115;"><?php echo esc_html( nexxi_kids_money( $range['min'] ) ); ?> (-<?php echo (int) $pct; ?>%)</strong>
				<?php else : ?>
					Trạng thái: <span style="color:#666;">Chưa giảm giá</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<p style="margin:0 0 6px;color:#444;">Áp dụng đồng loạt cho <b>tất cả biến thể (Size/Màu)</b> của sản phẩm này khi bấm <b>Cập nhật</b>:</p>
		<label style="display:block;margin-bottom:8px;">
			<span style="font-weight:600;display:block;margin-bottom:2px;">Đặt lại Giá gốc chung (₫) (tùy chọn):</span>
			<input type="number" name="nexxi_quick_regular_price" value="" placeholder="Để trống = giữ nguyên giá gốc" style="width:100%;" min="0" step="1000">
		</label>
		<label style="display:block;margin-bottom:8px;">
			<span style="font-weight:600;display:block;margin-bottom:2px;">Giảm giá (% hoặc Giá khuyến mãi ₫):</span>
			<input type="number" id="nexxi-mb-sale-val" name="nexxi_quick_sale_val" value="" placeholder="VD: 25 (giảm 25%) hoặc 120000" style="width:100%;" min="0">
		</label>
		<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px;">
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='25'">-25%</button>
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='40'">-40%</button>
			<button type="button" class="button button-small" onclick="document.getElementById('nexxi-mb-sale-val').value='50'">-50%</button>
			<button type="button" class="button button-small" style="color:#ba1a1a;" onclick="document.getElementById('nexxi-mb-sale-val').value='0'">Tắt sale</button>
		</div>
		<p style="margin:8px 0 0;padding-top:8px;border-top:1px solid #eee;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>">⚡ Mở trang Quản lý Flash Sale toàn shop →</a>
		</p>
	</div>
	<?php
}

function nexxi_kids_save_product_discount_metabox( $post_id ) {
	if ( empty( $_POST['nexxi_product_quick_sale_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_product_quick_sale_nonce'] ) ), 'nexxi_product_quick_sale' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

	$reg_raw  = isset( $_POST['nexxi_quick_regular_price'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexxi_quick_regular_price'] ) ) ) : '';
	$sale_raw = isset( $_POST['nexxi_quick_sale_val'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexxi_quick_sale_val'] ) ) ) : '';
	if ( '' === $reg_raw && '' === $sale_raw ) {
		return;
	}
	$reg = '' !== $reg_raw ? (float) $reg_raw : 0;
	if ( '0' === $sale_raw ) {
		nexxi_kids_apply_product_discount( $post_id, 'clear', 0, $reg );
		nexxi_kids_flush_sale_cache();
	} elseif ( '' !== $sale_raw ) {
		$num = (float) $sale_raw;
		$mode = $num <= 95 ? 'percent' : 'fixed';
		nexxi_kids_apply_product_discount( $post_id, $mode, $num, $reg );
		nexxi_kids_flush_sale_cache();
	} elseif ( $reg > 0 ) {
		nexxi_kids_apply_product_discount( $post_id, 'clear', 0, $reg );
	}
}
add_action( 'woocommerce_process_product_meta', 'nexxi_kids_save_product_discount_metabox', 99 );
