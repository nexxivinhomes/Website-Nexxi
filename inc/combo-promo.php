<?php
/**
 * NEXXI Combo Promotion Manager ("Combo Khuyến Mãi" under "Khuyến mãi shop").
 *
 * Features:
 *  - Configure unlimited quantity-based combo discount tiers (e.g. Mua 2 giảm 5%, Mua 3 giảm 7%, Mua 4 giảm 10%).
 *  - Stacks cumulatively on top of Product Discounts (🏷️ Giảm Giá SP) and Flash Sale (⚡ Flash Sale).
 *  - Unlimited combos per order using largest-tier-first partitioning:
 *      + Buy 5 items -> 1× Combo 4 (-10%) + 1 single item (sản phẩm lẻ)
 *      + Buy 7 items -> 1× Combo 4 (-10%) + 1× Combo 3 (-7%)
 *  - Renders visual Combo frames ("Khung Combo + chữ COMBO -%") and bill notifications on Cart & Checkout.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const NEXXI_COMBO_PROMO_OPT = 'nexxi_combo_promo_settings';

/**
 * Retrieve Combo Promotion settings with sane defaults matching the store's standard tiers.
 *
 * @param bool $force_refresh Whether to bypass static runtime cache.
 * @return array
 */
function nexxi_kids_get_combo_promo_settings( $force_refresh = false ) {
	static $cached = null;
	if ( null !== $cached && ! $force_refresh ) {
		return $cached;
	}
	$raw = get_option( NEXXI_COMBO_PROMO_OPT, null );
	if ( ! is_array( $raw ) ) {
		$raw = array(
			'enabled' => '1',
			'tiers'   => array(
				array( 'qty' => 2, 'type' => 'percent', 'value' => 5 ),
				array( 'qty' => 3, 'type' => 'percent', 'value' => 7 ),
				array( 'qty' => 4, 'type' => 'percent', 'value' => 10 ),
			),
		);
	}
	$enabled = ! empty( $raw['enabled'] ) && '0' !== (string) $raw['enabled'];
	$tiers   = array();
	if ( ! empty( $raw['tiers'] ) && is_array( $raw['tiers'] ) ) {
		foreach ( $raw['tiers'] as $t ) {
			$qty  = isset( $t['qty'] ) ? absint( $t['qty'] ) : 0;
			$type = isset( $t['type'] ) && 'fixed' === $t['type'] ? 'fixed' : 'percent';
			$val  = isset( $t['value'] ) ? (float) $t['value'] : 0.0;
			if ( $qty >= 2 && $val > 0 ) {
				if ( 'percent' === $type ) {
					$val = min( 95.0, $val );
				}
				$tiers[ $qty ] = array(
					'qty'   => $qty,
					'type'  => $type,
					'value' => $val,
				);
			}
		}
	}
	ksort( $tiers, SORT_NUMERIC );
	$cached = array(
		'enabled' => $enabled,
		'tiers'   => array_values( $tiers ),
	);
	return $cached;
}

/**
 * Format a combo tier badge label (e.g. "COMBO -10%" or "COMBO -20.000₫").
 *
 * @param array $tier Tier array with qty, type, value.
 * @return string
 */
function nexxi_kids_combo_tier_badge_text( array $tier ) {
	if ( 'fixed' === ( $tier['type'] ?? 'percent' ) ) {
		return 'COMBO -' . number_format( (float) $tier['value'], 0, ',', '.' ) . '₫';
	}
	$v = (float) ( $tier['value'] ?? 0 );
	$v_str = ( floor( $v ) == $v ) ? (string) (int) $v : rtrim( rtrim( number_format( $v, 1, ',', '' ), '0' ), ',' );
	return 'COMBO -' . $v_str . '%';
}

/**
 * Calculate the combo breakdown for a WooCommerce Cart.
 *
 * Partitions cart units greedily from the largest applicable tier to the smallest tier:
 *  - Example with tiers [2 => 5%, 3 => 7%, 4 => 10%]:
 *      + 5 items -> 1× Combo 4 (-10%) + 1 lẻ
 *      + 7 items -> 1× Combo 4 (-10%) + 1× Combo 3 (-7%)
 *
 * @param WC_Cart|null $cart WooCommerce cart object.
 * @return array{
 *   enabled: bool,
 *   total_qty: int,
 *   tiers: array,
 *   combos: array,
 *   leftover_items: array,
 *   total_discount: float,
 *   summary_labels: string[],
 *   item_combo_badges: array,
 *   next_tier_hint: string
 * }
 */
function nexxi_kids_calculate_cart_combos( $cart = null ) {
	if ( null === $cart && function_exists( 'WC' ) && WC()->cart ) {
		$cart = WC()->cart;
	}
	$settings = nexxi_kids_get_combo_promo_settings();
	$empty_result = array(
		'enabled'           => $settings['enabled'],
		'total_qty'         => 0,
		'tiers'             => $settings['tiers'],
		'combos'            => array(),
		'leftover_items'    => array(),
		'leftover_count'    => 0,
		'total_discount'    => 0.0,
		'summary_labels'    => array(),
		'item_combo_badges' => array(),
		'next_tier_hint'    => '',
	);

	if ( ! $settings['enabled'] || empty( $settings['tiers'] ) || ! $cart || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
		return $empty_result;
	}

	$cart_contents = $cart->get_cart();
	if ( empty( $cart_contents ) ) {
		return $empty_result;
	}

	// Expand cart lines into individual unit slots in cart order so we can partition into exact combos
	$unit_slots = array();
	foreach ( $cart_contents as $cart_item_key => $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		$qty     = isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
		if ( ! $product || ! is_object( $product ) || ! $product->exists() || $qty <= 0 ) {
			continue;
		}
		// Compute exact post-discount selling price (after Giảm Giá SP + Flash Sale, if any)
		$eff = function_exists( 'nexxi_kids_get_cart_item_effective_prices' )
			? nexxi_kids_get_cart_item_effective_prices( $cart_item )
			: array(
				'regular_price'   => (float) $product->get_regular_price(),
				'effective_price' => (float) $product->get_price(),
				'on_sale'         => false,
				'pct'             => 0,
				'has_prod_sale'   => false,
				'prod_pct'        => 0,
				'has_flash_sale'  => false,
				'flash_pct'       => 0,
			);
		$unit_price = (float) $eff['effective_price'];
		$reg_price  = (float) $eff['regular_price'];
		if ( $unit_price <= 0 ) {
			$unit_price = (float) $product->get_price();
			if ( $unit_price <= 0 ) {
				$unit_price = (float) get_post_meta( $product->get_id(), '_price', true );
			}
		}
		if ( $reg_price <= 0 || $reg_price < $unit_price ) {
			$reg_price = $unit_price;
		}
		// Keep cart item WC_Product price in sync with the active post-sale price
		if ( $unit_price > 0 ) {
			$product->set_regular_price( (string) round( $reg_price ) );
			if ( $unit_price < $reg_price ) {
				$product->set_sale_price( (string) round( $unit_price ) );
			}
			$product->set_price( (string) round( $unit_price ) );
		}
		for ( $i = 0; $i < $qty; $i++ ) {
			$unit_slots[] = array(
				'cart_item_key'  => $cart_item_key,
				'cart_item'      => $cart_item,
				'product'        => $product,
				'unit_price'     => max( 0.0, $unit_price ), // Giá sau giảm SP + Flash Sale (nếu có)
				'reg_price'      => max( 0.0, $reg_price ),  // Giá gốc ban đầu
				'prod_fs_pct'    => (int) ( $eff['pct'] ?? 0 ),
				'has_prod_sale'  => ! empty( $eff['has_prod_sale'] ),
				'prod_pct'       => (int) ( $eff['prod_pct'] ?? 0 ),
				'has_flash_sale' => ! empty( $eff['has_flash_sale'] ),
				'flash_pct'      => (int) ( $eff['flash_pct'] ?? 0 ),
			);
		}
	}

	$total_qty = count( $unit_slots );
	$empty_result['total_qty'] = $total_qty;
	if ( $total_qty <= 0 ) {
		return $empty_result;
	}

	// Sort all unit slots by active unit_price DESCENDING (highest price first)
	// so the highest-priced items are always placed into the highest-discount Combo first,
	// and the lowest-priced item(s) remain as single items (sản phẩm lẻ) — maximizing buyer savings!
	usort(
		$unit_slots,
		static function ( $a, $b ) {
			if ( (float) $b['unit_price'] !== (float) $a['unit_price'] ) {
				return (float) $b['unit_price'] <=> (float) $a['unit_price'];
			}
			return strcmp( (string) $a['cart_item_key'], (string) $b['cart_item_key'] );
		}
	);

	// Sort tiers descending by qty for greedy largest-combo-first matching
	$tiers_desc = $settings['tiers'];
	usort(
		$tiers_desc,
		static function ( $a, $b ) {
			return (int) $b['qty'] <=> (int) $a['qty'];
		}
	);
	$min_tier_qty = (int) end( $tiers_desc )['qty'];

	// Determine the list of matched combo tiers first, then sort them so the tier with the highest discount %
	// receives the most expensive unit_slots first!
	$chosen_tiers = array();
	$sim_rem      = $total_qty;
	while ( $sim_rem >= $min_tier_qty ) {
		$matched_tier = null;
		foreach ( $tiers_desc as $t ) {
			if ( (int) $t['qty'] <= $sim_rem ) {
				$matched_tier = $t;
				break;
			}
		}
		if ( ! $matched_tier ) {
			break;
		}
		$chosen_tiers[] = $matched_tier;
		$sim_rem       -= (int) $matched_tier['qty'];
	}

	if ( count( $chosen_tiers ) > 1 ) {
		usort(
			$chosen_tiers,
			static function ( $a, $b ) {
				$a_is_pct = ( 'fixed' !== ( $a['type'] ?? 'percent' ) );
				$b_is_pct = ( 'fixed' !== ( $b['type'] ?? 'percent' ) );
				if ( $a_is_pct && $b_is_pct && (float) $b['value'] !== (float) $a['value'] ) {
					return (float) $b['value'] <=> (float) $a['value'];
				}
				return (int) $b['qty'] <=> (int) $a['qty'];
			}
		);
	}

	$combos            = array();
	$item_combo_badges = array(); // cart_item_key => array of badge texts + counts
	$total_discount    = 0.0;
	$cursor            = 0;
	$combo_index       = 1;

	foreach ( $chosen_tiers as $matched_tier ) {
		$take_qty    = (int) $matched_tier['qty'];
		$slice_units = array_slice( $unit_slots, $cursor, $take_qty );
		$cursor     += $take_qty;

		// Aggregate units inside this combo by cart_item_key so identical items in the same combo display cleanly
		$grouped_lines      = array();
		$combo_subtotal     = 0.0; // Tổng giá sau giảm SP + Flash Sale (cơ sở tính Combo)
		$combo_reg_subtotal = 0.0; // Tổng giá gốc ban đầu
		foreach ( $slice_units as $u ) {
			$ck                  = $u['cart_item_key'];
			$combo_subtotal     += $u['unit_price'];
			$combo_reg_subtotal += $u['reg_price'];
			if ( ! isset( $grouped_lines[ $ck ] ) ) {
				$grouped_lines[ $ck ] = array(
					'cart_item_key'  => $ck,
					'cart_item'      => $u['cart_item'],
					'product'        => $u['product'],
					'qty_in_combo'   => 0,
					'unit_price'     => $u['unit_price'], // Đơn giá sau giảm SP + Flash Sale
					'reg_price'      => $u['reg_price'],  // Đơn giá gốc
					'prod_fs_pct'    => (int) ( $u['prod_fs_pct'] ?? 0 ),
					'has_prod_sale'  => ! empty( $u['has_prod_sale'] ),
					'prod_pct'       => (int) ( $u['prod_pct'] ?? 0 ),
					'has_flash_sale' => ! empty( $u['has_flash_sale'] ),
					'flash_pct'      => (int) ( $u['flash_pct'] ?? 0 ),
					'line_subtotal'  => 0.0,              // Thành tiền sau giảm SP + Flash Sale
					'line_reg_total' => 0.0,              // Thành tiền giá gốc
				);
			}
			$grouped_lines[ $ck ]['qty_in_combo']++;
			$grouped_lines[ $ck ]['line_subtotal']  += $u['unit_price'];
			$grouped_lines[ $ck ]['line_reg_total'] += $u['reg_price'];
		}

		// Combo discount is ALWAYS calculated strictly on $combo_subtotal (giá sau giảm của SP + Flash Sale)
		if ( 'fixed' === $matched_tier['type'] ) {
			$combo_discount = min( $combo_subtotal, round( (float) $matched_tier['value'] ) );
		} else {
			$combo_discount = round( $combo_subtotal * ( (float) $matched_tier['value'] / 100.0 ) );
		}
		$combo_discount = max( 0.0, $combo_discount );
		$total_discount += $combo_discount;

		$badge_text = nexxi_kids_combo_tier_badge_text( $matched_tier );

		foreach ( $grouped_lines as $ck => &$gline ) {
			if ( 'fixed' === $matched_tier['type'] ) {
				$line_disc = $combo_subtotal > 0 ? round( $combo_discount * ( $gline['line_subtotal'] / $combo_subtotal ) ) : 0.0;
			} else {
				$line_disc = round( $gline['line_subtotal'] * ( (float) $matched_tier['value'] / 100.0 ) );
			}
			$gline['line_discount']    = $line_disc;
			$gline['line_after_combo'] = max( 0.0, $gline['line_subtotal'] - $line_disc );
			$gline['badge_text']       = $badge_text;

			if ( ! isset( $item_combo_badges[ $ck ] ) ) {
				$item_combo_badges[ $ck ] = array();
			}
			if ( ! isset( $item_combo_badges[ $ck ][ $badge_text ] ) ) {
				$item_combo_badges[ $ck ][ $badge_text ] = 0;
			}
			$item_combo_badges[ $ck ][ $badge_text ] += $gline['qty_in_combo'];
		}
		unset( $gline );

		$combos[] = array(
			'index'          => $combo_index++,
			'tier'           => $matched_tier,
			'qty'            => $take_qty,
			'badge_text'     => $badge_text,
			'title'          => sprintf( 'Combo %d sản phẩm (%s)', $take_qty, $badge_text ),
			'reg_subtotal'   => $combo_reg_subtotal,
			'subtotal'       => $combo_subtotal,
			'discount'       => $combo_discount,
			'total_after'    => max( 0.0, $combo_subtotal - $combo_discount ),
			'lines'          => array_values( $grouped_lines ),
		);
	}

	// Aggregate any remaining leftover units (< min_tier_qty)
	$leftover_grouped = array();
	while ( $cursor < $total_qty ) {
		$u  = $unit_slots[ $cursor ];
		$ck = $u['cart_item_key'];
		$cursor++;
		if ( ! isset( $leftover_grouped[ $ck ] ) ) {
			$leftover_grouped[ $ck ] = array(
				'cart_item_key'  => $ck,
				'cart_item'      => $u['cart_item'],
				'product'        => $u['product'],
				'qty_leftover'   => 0,
				'unit_price'     => $u['unit_price'],
				'reg_price'      => $u['reg_price'],
				'prod_fs_pct'    => (int) ( $u['prod_fs_pct'] ?? 0 ),
				'has_prod_sale'  => ! empty( $u['has_prod_sale'] ),
				'prod_pct'       => (int) ( $u['prod_pct'] ?? 0 ),
				'has_flash_sale' => ! empty( $u['has_flash_sale'] ),
				'flash_pct'      => (int) ( $u['flash_pct'] ?? 0 ),
				'line_subtotal'  => 0.0,
				'line_reg_total' => 0.0,
			);
		}
		$leftover_grouped[ $ck ]['qty_leftover']++;
		$leftover_grouped[ $ck ]['line_subtotal']  += $u['unit_price'];
		$leftover_grouped[ $ck ]['line_reg_total'] += $u['reg_price'];
	}

	// Build concise summary labels (e.g. "1 Combo 4 giảm 10% + 1 Combo 3 giảm 7%")
	$counts_by_tier = array();
	foreach ( $combos as $c ) {
		$tk = (int) $c['qty'];
		if ( ! isset( $counts_by_tier[ $tk ] ) ) {
			$counts_by_tier[ $tk ] = array(
				'count' => 0,
				'tier'  => $c['tier'],
			);
		}
		$counts_by_tier[ $tk ]['count']++;
	}
	$summary_labels = array();
	foreach ( $counts_by_tier as $tk => $info ) {
		$t_obj = $info['tier'];
		$val_s = ( 'fixed' === $t_obj['type'] )
			? number_format( (float) $t_obj['value'], 0, ',', '.' ) . '₫'
			: ( ( floor( $t_obj['value'] ) == $t_obj['value'] ? (int) $t_obj['value'] : $t_obj['value'] ) . '%' );
		$summary_labels[] = sprintf( '%d Combo %d giảm %s', (int) $info['count'], (int) $tk, $val_s );
	}
	$leftover_count = 0;
	foreach ( $leftover_grouped as $lg ) {
		$leftover_count += (int) $lg['qty_leftover'];
	}

	// Hint for upselling next combo tier
	$next_tier_hint = '';
	if ( empty( $combos ) && $total_qty < $min_tier_qty ) {
		$need     = $min_tier_qty - $total_qty;
		$min_tier = end( $tiers_desc );
		$NextBadge = nexxi_kids_combo_tier_badge_text( $min_tier );
		$next_tier_hint = sprintf( 'Mua thêm %d sản phẩm nữa để được hưởng ngay %s (Combo %d món)!', $need, $NextBadge, $min_tier_qty );
	} elseif ( $leftover_count > 0 ) {
		$need     = $min_tier_qty - $leftover_count;
		$min_tier = end( $tiers_desc );
		$NextBadge = nexxi_kids_combo_tier_badge_text( $min_tier );
		$next_tier_hint = sprintf( 'Đang có %d sản phẩm lẻ — Mua thêm %d món nữa để tạo thêm 1 gói %s!', $leftover_count, $need, $NextBadge );
	}

	return array(
		'enabled'           => true,
		'total_qty'         => $total_qty,
		'tiers'             => $settings['tiers'],
		'combos'            => $combos,
		'leftover_items'    => array_values( $leftover_grouped ),
		'leftover_count'    => $leftover_count,
		'total_discount'    => $total_discount,
		'summary_labels'    => $summary_labels,
		'item_combo_badges' => $item_combo_badges,
		'next_tier_hint'    => $next_tier_hint,
	);
}

/**
 * Apply Combo Promotion discount on the WooCommerce Cart / Checkout bill (stacks with Product Sale & Flash Sale).
 */
function nexxi_kids_apply_combo_promo_cart_fee( WC_Cart $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	$res = nexxi_kids_calculate_cart_combos( $cart );
	if ( empty( $res['enabled'] ) || empty( $res['combos'] ) || $res['total_discount'] <= 0 ) {
		return;
	}
	$label_detail = implode( ' + ', $res['summary_labels'] );
	if ( ! empty( $res['leftover_count'] ) ) {
		$label_detail .= sprintf( ' và %d SP lẻ', (int) $res['leftover_count'] );
	}
	$fee_name = sprintf( '🎁 Ưu đãi Combo (%s)', $label_detail );
	$cart->add_fee( $fee_name, -1 * (float) $res['total_discount'], false );
}
add_action( 'woocommerce_cart_calculate_fees', 'nexxi_kids_apply_combo_promo_cart_fee', 20 );

/**
 * Append "COMBO -X%" badge onto cart/checkout line item chips so every item in a combo clearly shows its badge.
 */
function nexxi_kids_combo_item_data_badge( $item_data, $cart_item ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $item_data;
	}
	$res = nexxi_kids_calculate_cart_combos( WC()->cart );
	if ( empty( $res['enabled'] ) || empty( $res['combos'] ) ) {
		return $item_data;
	}
	// Match by product_id + variation_id or cart_item_key
	$matched_badges = array();
	foreach ( $res['combos'] as $c ) {
		foreach ( $c['lines'] as $line ) {
			$lp = $line['cart_item'];
			if (
				( isset( $cart_item['key'] ) && $cart_item['key'] === $line['cart_item_key'] ) ||
				( (int) ( $lp['product_id'] ?? 0 ) === (int) ( $cart_item['product_id'] ?? -1 ) && (int) ( $lp['variation_id'] ?? 0 ) === (int) ( $cart_item['variation_id'] ?? -1 ) )
			) {
				$b_txt = $c['badge_text'];
				if ( ! isset( $matched_badges[ $b_txt ] ) ) {
					$matched_badges[ $b_txt ] = 0;
				}
				$matched_badges[ $b_txt ] += (int) $line['qty_in_combo'];
			}
		}
	}
	if ( ! empty( $matched_badges ) ) {
		$parts = array();
		foreach ( $matched_badges as $b_txt => $cnt ) {
			$parts[] = $b_txt . ' (' . $cnt . ' món)';
		}
		$item_data[] = array(
			'key'     => 'Ưu đãi Combo',
			'value'   => implode( ', ', $parts ),
			'display' => implode( ', ', $parts ),
		);
	}
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'nexxi_kids_combo_item_data_badge', 25, 2 );

/**
 * Render the Combo Promotion Notification & Framed Combo Breakdown Box for Cart Totals / Checkout Bill.
 *
 * @param string $context 'cart' or 'checkout'.
 * @return string HTML markup.
 */
function nexxi_kids_render_combo_bill_notice_html( $context = 'cart' ) {
	$res = nexxi_kids_calculate_cart_combos();
	if ( empty( $res['enabled'] ) || empty( $res['tiers'] ) ) {
		return '';
	}

	ob_start();
	$tiers_text_parts = array();
	foreach ( $res['tiers'] as $t ) {
		$v_s = 'fixed' === $t['type']
			? number_format( (float) $t['value'], 0, ',', '.' ) . '₫'
			: ( ( floor( $t['value'] ) == $t['value'] ? (int) $t['value'] : $t['value'] ) . '%' );
		$tiers_text_parts[] = sprintf( 'Mua %d giảm %s', (int) $t['qty'], $v_s );
	}
	$tiers_banner = implode(' · ', $tiers_text_parts );
	?>
	<div class="nexxi-combo-bill-box" style="margin:12px 0 16px;padding:14px 16px;border-radius:14px;background:linear-gradient(135deg,#fff8f5 0%,#fff 100%);border:2px dashed #fd6a49;box-shadow:0 4px 14px rgba(253,106,73,0.08);">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
			<div style="display:flex;align-items:center;gap:6px;font-weight:800;font-size:13.5px;color:#031444;">
				<span style="background:#fd6a49;color:#fff;padding:2px 8px;border-radius:999px;font-size:11px;letter-spacing:0.03em;">COMBO KHUYẾN MÃI</span>
				<span>Cộng dồn Giảm Giá SP &amp; Flash Sale</span>
			</div>
			<span style="font-size:11.5px;font-weight:700;color:#ae3115;background:#ffdad2;padding:2px 9px;border-radius:999px;">
				<?php echo esc_html( $tiers_banner ); ?>
			</span>
		</div>

		<?php if ( ! empty( $res['combos'] ) ) : ?>
			<div style="background:#e6f9ed;border:1px solid #86efac;color:#0b6b2f;padding:9px 12px;border-radius:10px;font-size:12.5px;line-height:1.45;margin-bottom:10px;">
				🎉 <strong>Đã áp dụng ưu đãi Combo trên hóa đơn (Tính trên giá sau giảm SP + Flash Sale):</strong>
				Khách mua <strong><?php echo (int) $res['total_qty']; ?> món</strong> &rarr; Được áp
				<strong><?php echo esc_html( implode( ' + ', $res['summary_labels'] ) ); ?></strong><?php if ( ! empty( $res['leftover_count'] ) ) : ?> và <strong><?php echo (int) $res['leftover_count']; ?> sản phẩm lẻ</strong><?php endif; ?>.
				Tiết kiệm thêm: <strong style="color:#ae3115;font-size:13.5px;">-<?php echo esc_html( nexxi_kids_money( $res['total_discount'] ) ); ?></strong>!
			</div>

			<?php if ( 'checkout' === $context ) : ?>
				<!-- Framed Combo Breakdown on Checkout Bill -->
				<div style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
					<?php foreach ( $res['combos'] as $combo ) : ?>
						<div style="border:1.5px solid #fd6a49;background:#fff;border-radius:10px;padding:9px 12px;position:relative;">
							<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:6px;padding-bottom:5px;border-bottom:1px dashed #ffdad2;">
								<span style="font-weight:800;font-size:12px;color:#031444;">
									📦 Khung Combo #<?php echo (int) $combo['index']; ?> (<?php echo (int) $combo['qty']; ?> sản phẩm · Giá sau giảm SP/FS: <?php echo esc_html( nexxi_kids_money( $combo['subtotal'] ) ); ?>)
								</span>
								<span style="background:#fd6a49;color:#fff;font-weight:800;font-size:11px;padding:2px 8px;border-radius:999px;">
									<?php echo esc_html( $combo['badge_text'] ); ?> (-<?php echo esc_html( nexxi_kids_money( $combo['discount'] ) ); ?> &rarr; Còn <?php echo esc_html( nexxi_kids_money( $combo['total_after'] ) ); ?>)
								</span>
							</div>
							<div style="display:flex;flex-direction:column;gap:5px;font-size:12px;color:#45464f;">
								<?php foreach ( $combo['lines'] as $cline ) :
									$c_title = function_exists( 'nexxi_kids_cart_item_title' ) ? nexxi_kids_cart_item_title( $cline['cart_item'] ) : $cline['product']->get_name();
									?>
									<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
										<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
											• <?php echo esc_html( $c_title ); ?> <strong>× <?php echo (int) $cline['qty_in_combo']; ?></strong>
										</span>
										<span style="white-space:nowrap;font-weight:700;color:#ae3115;font-size:12.5px;text-align:right;">
											<?php echo esc_html( nexxi_kids_money( $cline['line_after_combo'] ) ); ?>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<?php if ( ! empty( $res['leftover_items'] ) ) : ?>
						<div style="border:1px dashed #cbd5e1;background:#f8fafc;border-radius:10px;padding:8px 12px;font-size:12px;color:#475569;">
							<div style="font-weight:700;color:#031444;margin-bottom:3px;">🛍️ Sản phẩm lẻ ngoài Combo (<?php echo (int) $res['leftover_count']; ?> món):</div>
							<?php foreach ( $res['leftover_items'] as $litem ) :
								$l_title = function_exists( 'nexxi_kids_cart_item_title' ) ? nexxi_kids_cart_item_title( $litem['cart_item'] ) : $litem['product']->get_name();
								?>
								<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
									<span>• <?php echo esc_html( $l_title ); ?> × <?php echo (int) $litem['qty_leftover']; ?></span>
									<span style="font-weight:600;"><?php echo esc_html( nexxi_kids_money( $litem['line_subtotal'] ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $res['next_tier_hint'] ) ) : ?>
			<div style="margin-top:6px;font-size:12px;color:#9a3412;background:#ffedd5;border:1px solid #fed7aa;padding:6px 10px;border-radius:8px;font-weight:600;">
				💡 <?php echo esc_html( $res['next_tier_hint'] ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Format WooCommerce cart item price & subtotal in Checkout review-order / Mini-cart to show Original vs Post-Sale price clearly.
 */
add_filter(
	'woocommerce_cart_item_price',
	static function ( $price_html, $cart_item, $cart_item_key ) {
		if ( ! function_exists( 'nexxi_kids_get_cart_item_effective_prices' ) || ! is_array( $cart_item ) ) {
			return $price_html;
		}
		$eff = nexxi_kids_get_cart_item_effective_prices( $cart_item );
		if ( $eff['reg_price'] > $eff['unit_price'] && $eff['unit_price'] > 0 ) {
			return '<del style="color:#94a3b8;font-size:0.88em;margin-right:4px;">' . wc_price( $eff['reg_price'] ) . '</del> <strong style="color:#0f172a;">' . wc_price( $eff['unit_price'] ) . '</strong>';
		}
		return $price_html;
	},
	20,
	3
);

add_filter(
	'woocommerce_cart_item_subtotal',
	static function ( $subtotal_html, $cart_item, $cart_item_key ) {
		if ( ! function_exists( 'nexxi_kids_get_cart_item_effective_prices' ) || ! is_array( $cart_item ) ) {
			return $subtotal_html;
		}
		$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
		$eff = nexxi_kids_get_cart_item_effective_prices( $cart_item );
		if ( $eff['reg_price'] > $eff['unit_price'] && $eff['unit_price'] > 0 ) {
			return '<del style="color:#94a3b8;font-size:0.88em;margin-right:4px;">' . wc_price( $eff['reg_price'] * $qty ) . '</del> <strong style="color:#0f172a;">' . wc_price( $eff['unit_price'] * $qty ) . '</strong>';
		}
		return $subtotal_html;
	},
	20,
	3
);

/**
 * Display the Combo Promotion notification & framed breakdown on the Checkout bill & Cart Totals sidebar.
 */
add_action(
	'woocommerce_review_order_before_order_total',
	static function () {
		$html = nexxi_kids_render_combo_bill_notice_html( 'checkout' );
		if ( '' !== $html ) {
			echo '<tr class="nexxi-combo-checkout-row"><td colspan="2" style="padding:6px 0;border:none;">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
);

add_action(
	'woocommerce_cart_totals_before_order_total',
	static function () {
		$html = nexxi_kids_render_combo_bill_notice_html( 'cart' );
		if ( '' !== $html ) {
			echo '<tr class="nexxi-combo-cart-row"><td colspan="2" style="padding:6px 0;border:none;">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
);

/**
 * Save Combo Promotion breakdown metadata onto the WooCommerce Order when checkout completes.
 */
add_action(
	'woocommerce_checkout_create_order',
	static function ( WC_Order $order ) {
		$res = nexxi_kids_calculate_cart_combos();
		if ( ! empty( $res['enabled'] ) && ! empty( $res['combos'] ) && $res['total_discount'] > 0 ) {
			$order->update_meta_data( '_nexxi_combo_summary', implode( ' + ', $res['summary_labels'] ) );
			$order->update_meta_data( '_nexxi_combo_discount_total', (string) round( $res['total_discount'] ) );
		}
	},
	20,
	1
);

/* ==========================================================================
 * ADMIN MENU & CONFIGURATION PAGE ("Khuyến mãi shop" -> "🎁 Combo Khuyến Mãi")
 * ========================================================================== */

function nexxi_kids_combo_promo_admin_menu() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	add_submenu_page(
		'nexxi-bulk-discount',
		'NEXXI Combo Khuyến Mãi Theo Số Lượng',
		'🎁 Combo Khuyến Mãi',
		'manage_woocommerce',
		'nexxi-combo-promo',
		'nexxi_kids_combo_promo_admin_page'
	);
	add_submenu_page(
		'edit.php?post_type=product',
		'NEXXI Combo Khuyến Mãi Theo Số Lượng',
		'🎁 Combo Khuyến Mãi',
		'manage_woocommerce',
		'nexxi-combo-promo',
		'nexxi_kids_combo_promo_admin_page'
	);
}
add_action( 'admin_menu', 'nexxi_kids_combo_promo_admin_menu', 22 );

/**
 * Handle saving Combo Promotion settings in WP Admin.
 */
function nexxi_kids_handle_combo_promo_admin_post() {
	if ( empty( $_POST['nexxi_combo_promo_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_combo_promo_save', 'nexxi_combo_promo_nonce' );

	$enabled = ! empty( $_POST['combo_enabled'] ) ? '1' : '0';
	$qtys    = isset( $_POST['tier_qty'] ) && is_array( $_POST['tier_qty'] ) ? array_map( 'absint', wp_unslash( $_POST['tier_qty'] ) ) : array();
	$types   = isset( $_POST['tier_type'] ) && is_array( $_POST['tier_type'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['tier_type'] ) ) : array();
	$vals    = isset( $_POST['tier_value'] ) && is_array( $_POST['tier_value'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tier_value'] ) ) : array();

	$tiers = array();
	foreach ( $qtys as $idx => $q ) {
		$q   = (int) $q;
		$t   = isset( $types[ $idx ] ) && 'fixed' === $types[ $idx ] ? 'fixed' : 'percent';
		$raw = isset( $vals[ $idx ] ) ? str_replace( ',', '.', trim( (string) $vals[ $idx ] ) ) : '0';
		$v   = is_numeric( $raw ) ? (float) $raw : 0.0;
		if ( $q >= 2 && $v > 0 ) {
			if ( 'percent' === $t ) {
				$v = min( 95.0, $v );
			}
			$tiers[ $q ] = array(
				'qty'   => $q,
				'type'  => $t,
				'value' => $v,
			);
		}
	}
	ksort( $tiers, SORT_NUMERIC );

	update_option(
		NEXXI_COMBO_PROMO_OPT,
		array(
			'enabled' => $enabled,
			'tiers'   => array_values( $tiers ),
		),
		true
	);
	nexxi_kids_get_combo_promo_settings( true );

	add_settings_error(
		'nexxi_combo_promo',
		'saved',
		sprintf( 'Đã lưu cấu hình Combo Khuyến Mãi thành công (%d mốc Combo đang hoạt động).', count( $tiers ) ),
		'updated'
	);
}
add_action( 'admin_init', 'nexxi_kids_handle_combo_promo_admin_post' );

/**
 * Render the WP Admin page for Combo Promotion ("Khuyến mãi shop" -> "🎁 Combo Khuyến Mãi").
 */
function nexxi_kids_combo_promo_admin_page() {
	$settings = nexxi_kids_get_combo_promo_settings( true );
	$enabled  = $settings['enabled'];
	$tiers    = $settings['tiers'];
	if ( empty( $tiers ) ) {
		$tiers = array(
			array( 'qty' => 2, 'type' => 'percent', 'value' => 5 ),
			array( 'qty' => 3, 'type' => 'percent', 'value' => 7 ),
			array( 'qty' => 4, 'type' => 'percent', 'value' => 10 ),
		);
	}
	?>
	<div class="wrap" style="max-width:1180px;margin-top:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<!-- Unified "Khuyến mãi shop" Tab Bar -->
		<div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #e4e2de;padding-bottom:10px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				🏷️ Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU)
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				⚡ Chiến Dịch Flash Sale &amp; Đếm Ngược
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-combo-promo' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#0b6b2f;color:#fff;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 2px 8px rgba(11,107,47,0.22);">
				🎁 Combo Khuyến Mãi (Theo Số Lượng Bill)
			</a>
		</div>

		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
			<div>
				<h1 style="margin:0;font-size:24px;font-weight:800;color:#031444;display:flex;align-items:center;gap:8px;">
					<span>🎁 Thiết Lập Combo Khuyến Mãi Theo Số Lượng Trên Bill</span>
				</h1>
				<p style="margin:4px 0 0;color:#45464f;font-size:13.5px;">
					Giảm giá thêm trực tiếp trên hóa đơn khi khách mua đủ số lượng sản phẩm. <strong>Cộng dồn với cả Giảm Giá SP và Flash Sale</strong>, <strong>không giới hạn số lượng Combo</strong> trên 1 đơn hàng!
				</p>
			</div>
			<div style="display:flex;gap:8px;">
				<a class="button" href="<?php echo esc_url( wc_get_cart_url() ); ?>" target="_blank">Xem Giỏ Hàng ↗</a>
				<a class="button button-primary" href="<?php echo esc_url( wc_get_checkout_url() ); ?>" target="_blank" style="background:#031444;border-color:#031444;">Xem Trang Thanh Toán ↗</a>
			</div>
		</div>

		<?php settings_errors( 'nexxi_combo_promo' ); ?>

		<form method="post" style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:24px;box-shadow:0 4px 16px rgba(3,20,68,0.04);margin-bottom:22px;">
			<?php wp_nonce_field( 'nexxi_combo_promo_save', 'nexxi_combo_promo_nonce' ); ?>
			<input type="hidden" name="nexxi_combo_promo_action" value="save">

			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding-bottom:16px;margin-bottom:18px;border-bottom:1px solid #f0eee9;">
				<label style="display:inline-flex;align-items:center;gap:10px;background:#eef9f2;border:1.5px solid #86efac;padding:10px 16px;border-radius:12px;cursor:pointer;font-weight:700;font-size:14px;color:#0b6b2f;">
					<input type="checkbox" name="combo_enabled" value="1" <?php checked( $enabled ); ?> style="width:18px;height:18px;">
					<span>Bật áp dụng chương trình Combo Khuyến Mãi trên Giỏ Hàng &amp; Thanh Toán</span>
				</label>

				<button type="button" class="button" onclick="nexxiAddComboTierRow()" style="font-weight:700;color:#031444;border-color:#031444;min-height:36px;">
					➕ Thêm Mốc Combo Mới
				</button>
			</div>

			<table class="widefat striped" id="nexxi-combo-tiers-table" style="border-radius:12px;overflow:hidden;border:1px solid #e4e2de;margin-bottom:18px;">
				<thead>
					<tr style="background:#f8fafc;">
						<th style="width:220px;font-weight:700;color:#031444;">Số lượng sản phẩm / 1 Combo</th>
						<th style="width:240px;font-weight:700;color:#031444;">Hình thức giảm thêm trên Bill</th>
						<th style="width:200px;font-weight:700;color:#031444;">Mức giảm (% hoặc ₫)</th>
						<th style="font-weight:700;color:#031444;">Nhãn hiển thị trên Khung Combo</th>
						<th style="width:90px;text-align:center;font-weight:700;color:#031444;">Xóa</th>
					</tr>
				</thead>
				<tbody id="nexxi-combo-tiers-body">
					<?php foreach ( $tiers as $t ) :
						$q   = (int) $t['qty'];
						$tp  = $t['type'];
						$val = (float) $t['value'];
						?>
						<tr class="nexxi-combo-tier-row">
							<td>
								<div style="display:flex;align-items:center;gap:6px;">
									<span>Mua đủ</span>
									<input type="number" name="tier_qty[]" value="<?php echo (int) $q; ?>" min="2" max="100" step="1" required oninput="nexxiUpdateComboPreview(this)" style="width:76px;font-weight:800;color:#031444;border-radius:8px;border:1px solid #cbd5e1;padding:4px 8px;">
									<span>sản phẩm</span>
								</div>
							</td>
							<td>
								<select name="tier_type[]" onchange="nexxiUpdateComboPreview(this)" style="width:100%;border-radius:8px;border:1px solid #cbd5e1;font-weight:600;">
									<option value="percent" <?php selected( $tp, 'percent' ); ?>>Giảm thêm theo % trên Combo</option>
									<option value="fixed" <?php selected( $tp, 'fixed' ); ?>>Giảm số tiền cố định (₫) / Combo</option>
								</select>
							</td>
							<td>
								<input type="number" name="tier_value[]" value="<?php echo esc_attr( $val ); ?>" min="1" step="any" required oninput="nexxiUpdateComboPreview(this)" style="width:120px;font-weight:800;color:#ae3115;border-radius:8px;border:1px solid #cbd5e1;padding:4px 8px;">
							</td>
							<td>
								<span class="nexxi-combo-badge-preview" style="display:inline-flex;align-items:center;gap:6px;background:#fd6a49;color:#fff;font-weight:800;font-size:12px;padding:4px 12px;border-radius:999px;">
									📦 Combo <?php echo (int) $q; ?> SP · <?php echo esc_html( nexxi_kids_combo_tier_badge_text( $t ) ); ?>
								</span>
							</td>
							<td style="text-align:center;">
								<button type="button" class="button button-small" style="color:#ba1a1a;border-color:#ffdad6;" onclick="this.closest('tr').remove();">Xóa</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#334155;line-height:1.6;">
				<strong style="color:#031444;">📌 Quy tắc ghép Combo tự động (Không giới hạn số lượng Combo trên 1 đơn hàng):</strong>
				<ul style="margin:6px 0 0 18px;list-style:disc;">
					<li>Hệ thống tự động ưu tiên ghép từ <strong>mốc Combo lớn nhất xuống nhỏ nhất</strong> để khách hàng nhận được mức chiết khấu tốt nhất.</li>
					<li><strong>Ví dụ với cài đặt Mua 2 giảm 5% · Mua 3 giảm 7% · Mua 4 giảm 10%:</strong>
						<br>• Khách mua <strong>5 món</strong> &rarr; Tự động áp dụng <strong>1 Khung Combo 4 (COMBO -10%)</strong> + <strong>1 sản phẩm lẻ</strong>.
						<br>• Khách mua <strong>7 món</strong> &rarr; Tự động áp dụng <strong>1 Khung Combo 4 (COMBO -10%)</strong> + <strong>1 Khung Combo 3 (COMBO -7%)</strong>.
						<br>• Khách mua <strong>8 món</strong> &rarr; Tự động áp dụng <strong>2 Khung Combo 4 (COMBO -10%)</strong>.
					</li>
					<li>Khi khách đạt đủ số lượng, các sản phẩm trong Combo sẽ được <strong>đóng khung Combo riêng kèm nhãn <code>COMBO -%</code></strong> và hiển thị dòng thông báo chi tiết trênทั้ง <strong>Giỏ hàng</strong> và <strong>Bill trang Thanh toán</strong>.</li>
				</ul>
			</div>

			<button type="submit" class="button button-primary" style="min-height:42px;padding:0 28px;border-radius:10px;font-size:14px;font-weight:800;background:#031444;border-color:#031444;">
				💾 Lưu Cấu Hình Combo Khuyến Mãi
			</button>
		</form>
	</div>

	<script>
	function nexxiAddComboTierRow() {
		var tbody = document.getElementById('nexxi-combo-tiers-body');
		var rows = tbody.querySelectorAll('tr.nexxi-combo-tier-row');
		var nextQty = 2;
		rows.forEach(function(r) {
			var qInput = r.querySelector('input[name="tier_qty[]"]');
			if (qInput && Number(qInput.value) >= nextQty) {
				nextQty = Number(qInput.value) + 1;
			}
		});
		var tr = document.createElement('tr');
		tr.className = 'nexxi-combo-tier-row';
		tr.innerHTML =
			'<td><div style="display:flex;align-items:center;gap:6px;"><span>Mua đủ</span>' +
			'<input type="number" name="tier_qty[]" value="' + nextQty + '" min="2" max="100" step="1" required oninput="nexxiUpdateComboPreview(this)" style="width:76px;font-weight:800;color:#031444;border-radius:8px;border:1px solid #cbd5e1;padding:4px 8px;">' +
			'<span>sản phẩm</span></div></td>' +
			'<td><select name="tier_type[]" onchange="nexxiUpdateComboPreview(this)" style="width:100%;border-radius:8px;border:1px solid #cbd5e1;font-weight:600;">' +
			'<option value="percent">Giảm thêm theo % trên Combo</option>' +
			'<option value="fixed">Giảm số tiền cố định (₫) / Combo</option></select></td>' +
			'<td><input type="number" name="tier_value[]" value="12" min="1" step="any" required oninput="nexxiUpdateComboPreview(this)" style="width:120px;font-weight:800;color:#ae3115;border-radius:8px;border:1px solid #cbd5e1;padding:4px 8px;"></td>' +
			'<td><span class="nexxi-combo-badge-preview" style="display:inline-flex;align-items:center;gap:6px;background:#fd6a49;color:#fff;font-weight:800;font-size:12px;padding:4px 12px;border-radius:999px;">📦 Combo ' + nextQty + ' SP · COMBO -12%</span></td>' +
			'<td style="text-align:center;"><button type="button" class="button button-small" style="color:#ba1a1a;border-color:#ffdad6;" onclick="this.closest(\'tr\').remove();">Xóa</button></td>';
		tbody.appendChild(tr);
	}
	function nexxiUpdateComboPreview(el) {
		var tr = el.closest('tr');
		if (!tr) return;
		var q = tr.querySelector('input[name="tier_qty[]"]')?.value || 2;
		var tp = tr.querySelector('select[name="tier_type[]"]')?.value || 'percent';
		var v = tr.querySelector('input[name="tier_value[]"]')?.value || 0;
		var badge = tr.querySelector('.nexxi-combo-badge-preview');
		if (!badge) return;
		var suffix = tp === 'fixed' ? (Number(v).toLocaleString('vi-VN') + '₫') : (v + '%');
		badge.textContent = '📦 Combo ' + q + ' SP · COMBO -' + suffix;
	}
	</script>
	<?php
}
