<?php
/**
 * NEXXI Customer, Membership Tier & Order Management Suite (WP Admin + Storefront Loyalty Integration).
 *
 * Provides a top-level "Quản lý khách hàng" menu placed directly above "Khuyến mãi shop", with 3 submenus:
 *  1. 👤 Quản lý khách hàng (nexxi-customer-manager):
 *     - Full CRUD (Add, Edit, Delete single/bulk customer accounts)
 *     - Aggregated view: Points, Membership Tier, Total Orders, Total Spent, NEXXI Club Join Date, Baby's Birthday, etc.
 *  2. 👑 Hạng thành viên (nexxi-member-tiers):
 *     - Manage membership tiers per customer account (auto or manual lock)
 *     - Configure membership tiers & corresponding benefits (% bill discount, point multiplier, baby birthday gift)
 *     - Configure tier downgrade expiration period (thời hạn tụt hạng)
 *     - Configure tier upgrade thresholds (mức thăng hạng theo chi tiêu / điểm)
 *     - Configure Points = Money conversion (tích điểm & đổi điểm trừ tiền)
 *  3. 📦 Quản lý đơn hàng (nexxi-order-manager):
 *     - Order list management with search, status tabs & date filters
 *     - Single-row quick status switching
 *     - Bulk order actions (change status in bulk, award points, export CSV, delete)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 1. CORE DATA HELPERS: SETTINGS, TIERS, POINTS = MONEY & BABY PROFILE
 * ========================================================================== */

/**
 * Default Points = Money settings.
 *
 * @return array
 */
function nexxi_kids_get_points_settings() {
	$defaults = array(
		'earn_unit_vnd'     => 10000, // 10.000₫ chi tiêu = 1 điểm
		'redeem_enabled'    => '1',   // Cho phép dùng điểm trừ tiền tại Giỏ hàng / Thanh toán
		'point_value_vnd'   => 1000,  // 1 điểm = 1.000₫ khi đổi ưu đãi
		'min_points_redeem' => 10,    // Tối thiểu 10 điểm mới được đổi
		'max_redeem_pct'    => 50,    // Tối đa giảm 50% giá trị đơn hàng bằng điểm
	);
	$saved = get_option( 'nexxi_points_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$res = wp_parse_args( $saved, $defaults );
	$res['earn_unit_vnd']     = max( 1, (int) $res['earn_unit_vnd'] );
	$res['point_value_vnd']   = max( 1, (int) $res['point_value_vnd'] );
	$res['min_points_redeem'] = max( 1, (int) $res['min_points_redeem'] );
	$res['max_redeem_pct']    = min( 100, max( 1, (int) $res['max_redeem_pct'] ) );
	return $res;
}

// Connect configured earn_unit_vnd to nexxi_kids_calculate_order_points() in stitch-account.php.
add_filter(
	'nexxi_kids_points_per_unit',
	static function () {
		$pts = nexxi_kids_get_points_settings();
		return (int) $pts['earn_unit_vnd'];
	}
);

/**
 * Default Membership Tiers configuration.
 *
 * @return array<string,array>
 */
function nexxi_kids_get_member_tiers_config() {
	$defaults = array(
		'member'  => array(
			'slug'          => 'member',
			'name'          => 'Thành Viên Mới',
			'icon'          => '🌱',
			'color'         => '#475569',
			'bg_color'      => '#f1f5f9',
			'min_spent'     => 0,
			'min_points'    => 0,
			'discount_pct'  => 0,
			'point_mult'    => 1.0,
			'birthday_gift' => 20000,
			'perks'         => 'Tích điểm mọi đơn hàng, nhận ưu đãi sinh nhật bé 20.000₫',
		),
		'silver'  => array(
			'slug'          => 'silver',
			'name'          => 'Hạng Bạc (Silver)',
			'icon'          => '🥈',
			'color'         => '#1e40af',
			'bg_color'      => '#dbeafe',
			'min_spent'     => 2000000,
			'min_points'    => 200,
			'discount_pct'  => 3,
			'point_mult'    => 1.2,
			'birthday_gift' => 50000,
			'perks'         => 'Giảm thêm 3% mọi đơn hàng, nhân x1.2 điểm tích lũy, quà sinh nhật bé 50.000₫',
		),
		'gold'    => array(
			'slug'          => 'gold',
			'name'          => 'Hạng Vàng (Gold)',
			'icon'          => '🥇',
			'color'         => '#b45309',
			'bg_color'      => '#fef3c7',
			'min_spent'     => 5000000,
			'min_points'    => 500,
			'discount_pct'  => 5,
			'point_mult'    => 1.5,
			'birthday_gift' => 100000,
			'perks'         => 'Giảm thêm 5% mọi đơn hàng, nhân x1.5 điểm tích lũy, quà sinh nhật bé 100.000₫',
		),
		'diamond' => array(
			'slug'          => 'diamond',
			'name'          => 'Hạng Kim Cương (Diamond)',
			'icon'          => '💎',
			'color'         => '#9d174d',
			'bg_color'      => '#fce7f3',
			'min_spent'     => 12000000,
			'min_points'    => 1200,
			'discount_pct'  => 10,
			'point_mult'    => 2.0,
			'birthday_gift' => 200000,
			'perks'         => 'Giảm thêm 10% mọi đơn hàng, nhân x2.0 điểm tích lũy, quà sinh nhật bé 200.000₫',
		),
	);

	$saved = get_option( 'nexxi_member_tiers_config', null );
	if ( ! is_array( $saved ) || empty( $saved ) ) {
		return $defaults;
	}

	// Sort by min_spent ascending
	uasort(
		$saved,
		static function ( $a, $b ) {
			return ( (float) ( $a['min_spent'] ?? 0 ) ) <=> ( (float) ( $b['min_spent'] ?? 0 ) );
		}
	);
	return $saved;
}

/**
 * Tier Upgrade & Downgrade Rules configuration.
 *
 * @return array
 */
function nexxi_kids_get_tier_rules_settings() {
	$defaults = array(
		'upgrade_metric'          => 'either',      // 'spent' | 'points' | 'either' | 'both'
		'downgrade_enabled'       => '1',           // '1' = Bật xét tụt hạng, '0' = Không bao giờ tụt hạng
		'downgrade_months'        => 6,             // Thời hạn duy trì hạng (tháng) kể từ đơn hàng / lần xét hạng gần nhất
		'downgrade_mode'          => 'step_down_1', // 'step_down_1' (tụt 1 bậc) | 'period_spend' (tính theo chi tiêu trong chu kỳ) | 'reset_base' (về hạng đầu)
		'protect_manual_override' => '1',           // Giữ nguyên hạng cho tài khoản được Admin khóa hạng cố định
		'apply_tier_discount'     => '1',           // Tự động giảm % theo hạng thành viên trên hóa đơn
	);
	$saved = get_option( 'nexxi_tier_rules_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$res = wp_parse_args( $saved, $defaults );
	$res['downgrade_months'] = max( 1, (int) $res['downgrade_months'] );
	return $res;
}

/**
 * Calculate total spent & order count for a customer (including linked orders by user_id or billing_email).
 *
 * @param int $user_id WordPress User ID.
 * @return array{order_count:int,completed_count:int,total_spent:float,last_order_date:string,last_order_ts:int}
 */
function nexxi_kids_get_customer_order_stats( $user_id ) {
	static $runtime_cache = array();
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return array(
			'order_count'     => 0,
			'completed_count' => 0,
			'total_spent'     => 0.0,
			'last_order_date' => '',
			'last_order_ts'   => 0,
		);
	}
	if ( isset( $runtime_cache[ $user_id ] ) ) {
		return $runtime_cache[ $user_id ];
	}

	$order_count     = 0;
	$completed_count = 0;
	$total_spent     = 0.0;
	$last_order_date = '';
	$last_order_ts   = 0;

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ),
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		foreach ( $orders as $ord ) {
			if ( ! $ord instanceof WC_Order ) {
				continue;
			}
			$order_count++;
			$st = $ord->get_status();
			if ( in_array( $st, array( 'completed', 'processing', 'on-hold', 'pending' ), true ) ) {
				$total_spent += (float) $ord->get_total();
			}
			if ( 'completed' === $st ) {
				$completed_count++;
			}
			$dt = $ord->get_date_created();
			if ( $dt && 0 === $last_order_ts ) {
				$last_order_ts   = $dt->getTimestamp();
				$last_order_date = $dt->date_i18n( 'Y-m-d H:i:s' );
			}
		}
	}

	// Allow manual adjustment / historical spend override if admin set one
	$manual_base_spent = (float) get_user_meta( $user_id, 'nexxi_manual_spent_adjust', true );
	if ( $manual_base_spent > 0 && $total_spent < $manual_base_spent ) {
		$total_spent = $manual_base_spent;
	}

	$runtime_cache[ $user_id ] = array(
		'order_count'     => $order_count,
		'completed_count' => $completed_count,
		'total_spent'     => $total_spent,
		'last_order_date' => $last_order_date,
		'last_order_ts'   => $last_order_ts,
	);
	return $runtime_cache[ $user_id ];
}

/**
 * Determine which tier a user qualifies for based on spent & points, and evaluate downgrade expiration.
 *
 * @param int $user_id WordPress User ID.
 * @return array{
 *   tier_slug:string,
 *   tier:array,
 *   is_manual:bool,
 *   is_downgraded:bool,
 *   expires_ts:int,
 *   expires_label:string,
 *   days_left:int,
 *   next_tier:array|null,
 *   spent_to_next:float,
 *   points_to_next:int
 * }
 */
function nexxi_kids_get_user_tier_info( $user_id ) {
	$user_id = (int) $user_id;
	$tiers   = nexxi_kids_get_member_tiers_config();
	$rules   = nexxi_kids_get_tier_rules_settings();
	$keys    = array_keys( $tiers );
	$base_k  = ! empty( $keys[0] ) ? $keys[0] : 'member';

	if ( $user_id <= 0 ) {
		return array(
			'tier_slug'      => $base_k,
			'tier'           => $tiers[ $base_k ],
			'is_manual'      => false,
			'is_downgraded'  => false,
			'expires_ts'     => 0,
			'expires_label'  => 'Không giới hạn',
			'days_left'      => 999,
			'next_tier'      => isset( $keys[1] ) ? $tiers[ $keys[1] ] : null,
			'spent_to_next'  => isset( $keys[1] ) ? (float) $tiers[ $keys[1] ]['min_spent'] : 0.0,
			'points_to_next' => isset( $keys[1] ) ? (int) $tiers[ $keys[1] ]['min_points'] : 0,
		);
	}

	$stats      = nexxi_kids_get_customer_order_stats( $user_id );
	$spent      = (float) $stats['total_spent'];
	$points     = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $user_id ) : (int) get_user_meta( $user_id, 'nexxi_member_points', true );
	$manual_lck = ( '1' === (string) get_user_meta( $user_id, 'nexxi_tier_manual_lock', true ) );
	$saved_slug = (string) get_user_meta( $user_id, 'nexxi_member_tier', true );

	// Calculate earned tier from thresholds
	$metric      = $rules['upgrade_metric'];
	$earned_idx  = 0;
	foreach ( $keys as $idx => $k ) {
		$t     = $tiers[ $k ];
		$req_s = (float) ( $t['min_spent'] ?? 0 );
		$req_p = (int) ( $t['min_points'] ?? 0 );
		if ( $req_s <= 0 && $req_p <= 0 ) {
			$earned_idx = $idx;
			continue;
		}
		$ok = false;
		if ( 'spent' === $metric ) {
			$ok = ( $req_s > 0 && $spent >= $req_s );
		} elseif ( 'points' === $metric ) {
			$ok = ( $req_p > 0 && $points >= $req_p );
		} elseif ( 'both' === $metric ) {
			$ok = ( $spent >= $req_s && $points >= $req_p );
		} else {
			// 'either'
			$ok = ( ( $req_s > 0 && $spent >= $req_s ) || ( $req_p > 0 && $points >= $req_p ) );
		}
		if ( $ok ) {
			$earned_idx = $idx;
		}
	}

	$active_idx    = $earned_idx;
	$is_downgraded = false;

	if ( $manual_lck && isset( $tiers[ $saved_slug ] ) ) {
		$found = array_search( $saved_slug, $keys, true );
		if ( false !== $found ) {
			$active_idx = (int) $found;
		}
	}

	// Reference activity timestamp for downgrade expiration
	$user_obj      = get_userdata( $user_id );
	$joined_custom = (string) get_user_meta( $user_id, 'nexxi_club_joined_date', true );
	$reg_ts        = $joined_custom ? strtotime( $joined_custom ) : ( $user_obj && $user_obj->user_registered ? strtotime( $user_obj->user_registered ) : time() );
	$tier_upd_ts   = (int) get_user_meta( $user_id, 'nexxi_tier_updated_ts', true );
	$ref_ts        = max( (int) $stats['last_order_ts'], $tier_upd_ts, (int) $reg_ts );
	$months        = max( 1, (int) $rules['downgrade_months'] );
	$expires_ts    = strtotime( '+' . $months . ' months', $ref_ts );
	$days_left     = (int) ceil( ( $expires_ts - time() ) / DAY_IN_SECONDS );

	// Evaluate downgrade if enabled and user is above base tier
	if ( '1' === (string) $rules['downgrade_enabled'] && $active_idx > 0 ) {
		$protected = ( $manual_lck && '1' === (string) $rules['protect_manual_override'] );
		if ( ! $protected && $days_left < 0 ) {
			$overdue_cycles = max( 1, (int) floor( abs( $days_left ) / ( $months * 30 ) ) + 1 );
			if ( 'reset_base' === $rules['downgrade_mode'] ) {
				$active_idx    = 0;
				$is_downgraded = true;
			} elseif ( 'period_spend' === $rules['downgrade_mode'] ) {
				// No order in the last $months months -> period spend is 0 -> base tier
				$active_idx    = 0;
				$is_downgraded = true;
			} else {
				// step_down_1 per cycle
				$active_idx    = max( 0, $active_idx - $overdue_cycles );
				$is_downgraded = true;
			}
		}
	}

	$active_slug = isset( $keys[ $active_idx ] ) ? $keys[ $active_idx ] : $base_k;
	$active_tier = $tiers[ $active_slug ];

	// Persist current tier slug if changed
	if ( $saved_slug !== $active_slug && ! $manual_lck ) {
		update_user_meta( $user_id, 'nexxi_member_tier', $active_slug );
	}

	$next_tier      = isset( $keys[ $active_idx + 1 ] ) ? $tiers[ $keys[ $active_idx + 1 ] ] : null;
	$spent_to_next  = $next_tier ? max( 0.0, (float) $next_tier['min_spent'] - $spent ) : 0.0;
	$points_to_next = $next_tier ? max( 0, (int) $next_tier['min_points'] - $points ) : 0;

	$expires_label = 'Không giới hạn';
	if ( $active_idx > 0 && '1' === (string) $rules['downgrade_enabled'] ) {
		if ( $manual_lck && '1' === (string) $rules['protect_manual_override'] ) {
			$expires_label = 'Cố định vĩnh viễn';
		} elseif ( $days_left >= 0 ) {
			$expires_label = wp_date( 'd/m/Y', $expires_ts ) . ' (còn ' . $days_left . ' ngày)';
		} else {
			$expires_label = 'Quá hạn ' . abs( $days_left ) . ' ngày (Đã hạ hạng)';
		}
	}

	return array(
		'tier_slug'      => $active_slug,
		'tier'           => $active_tier,
		'is_manual'      => $manual_lck,
		'is_downgraded'  => $is_downgraded,
		'expires_ts'     => $expires_ts,
		'expires_label'  => $expires_label,
		'days_left'      => $days_left,
		'next_tier'      => $next_tier,
		'spent_to_next'  => $spent_to_next,
		'points_to_next' => $points_to_next,
	);
}

/**
 * Apply tier point multiplier when awarding order points, and apply Cart/Checkout tier discount + Points-to-Money redemption.
 */
add_action(
	'woocommerce_cart_calculate_fees',
	static function ( WC_Cart $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$uid       = get_current_user_id();
		$rules     = nexxi_kids_get_tier_rules_settings();
		$tier_info = nexxi_kids_get_user_tier_info( $uid );
		$tier      = $tier_info['tier'];

		// 1. Membership Tier Discount (% on post-sale subtotal)
		if ( '1' === (string) $rules['apply_tier_discount'] && ! empty( $tier['discount_pct'] ) && (float) $tier['discount_pct'] > 0 ) {
			$pct  = min( 90.0, (float) $tier['discount_pct'] );
			$base = (float) $cart->get_subtotal();
			if ( $base > 0 ) {
				$disc = round( $base * ( $pct / 100.0 ) );
				if ( $disc > 0 ) {
					$label = sprintf( '%s Ưu đãi %s (-%s%%)', $tier['icon'] ?? '👑', $tier['name'], rtrim( rtrim( number_format( $pct, 1 ), '0' ), '.' ) );
					$cart->add_fee( $label, -$disc, false );
				}
			}
		}

		// 2. Points = Money Redemption if customer opted to redeem points in session
		$pts_cfg = nexxi_kids_get_points_settings();
		if ( '1' === (string) $pts_cfg['redeem_enabled'] && WC()->session ) {
			$use_pts = (int) WC()->session->get( 'nexxi_redeem_points', 0 );
			if ( $use_pts > 0 ) {
				$avail_pts = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $uid ) : (int) get_user_meta( $uid, 'nexxi_member_points', true );
				$use_pts   = min( $use_pts, $avail_pts );
				if ( $use_pts >= (int) $pts_cfg['min_points_redeem'] ) {
					$base_sub    = max( 0.0, (float) $cart->get_subtotal() );
					$max_vnd     = round( $base_sub * ( (int) $pts_cfg['max_redeem_pct'] / 100.0 ) );
					$pt_val      = (int) $pts_cfg['point_value_vnd'];
					$redeem_vnd  = min( $max_vnd, $use_pts * $pt_val );
					$actual_pts  = $pt_val > 0 ? (int) floor( $redeem_vnd / $pt_val ) : 0;
					$redeem_vnd  = $actual_pts * $pt_val;
					if ( $actual_pts > 0 && $redeem_vnd > 0 ) {
						WC()->session->set( 'nexxi_redeem_points_actual', $actual_pts );
						$cart->add_fee(
							sprintf( '💎 Đổi %s điểm NEXXI Club (-%s)', number_format_i18n( $actual_pts ), nexxi_kids_money( $redeem_vnd ) ),
							-$redeem_vnd,
							false
						);
					}
				}
			}
		}
	},
	25
);

/**
 * Handle AJAX / POST toggle for redeeming NEXXI points on Cart & Checkout.
 */
add_action(
	'wp_loaded',
	static function () {
		if ( empty( $_POST['nexxi_points_redeem_action'] ) || ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$act = sanitize_key( wp_unslash( $_POST['nexxi_points_redeem_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$uid = get_current_user_id();
		if ( 'apply' === $act ) {
			$avail = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $uid ) : 0;
			$req   = isset( $_POST['nexxi_points_to_use'] ) ? absint( $_POST['nexxi_points_to_use'] ) : $avail; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			WC()->session->set( 'nexxi_redeem_points', min( $avail, max( 0, $req ) ) );
		} elseif ( 'cancel' === $act ) {
			WC()->session->set( 'nexxi_redeem_points', 0 );
			WC()->session->set( 'nexxi_redeem_points_actual', 0 );
		}
	},
	15
);

/**
 * Deduct redeemed points when an order is created at Checkout.
 */
add_action(
	'woocommerce_checkout_order_processed',
	static function ( $order_id, $posted_data = array(), $order = null ) {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$used_pts = (int) WC()->session->get( 'nexxi_redeem_points_actual', 0 );
		if ( $used_pts <= 0 ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order || 'yes' === (string) $order->get_meta( '_nexxi_points_redeemed_done' ) ) {
			return;
		}
		$uid     = get_current_user_id();
		$cur_pts = (int) get_user_meta( $uid, 'nexxi_member_points', true );
		$new_pts = max( 0, $cur_pts - $used_pts );
		update_user_meta( $uid, 'nexxi_member_points', $new_pts );

		$order->update_meta_data( '_nexxi_points_redeemed', $used_pts );
		$order->update_meta_data( '_nexxi_points_redeemed_done', 'yes' );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'NEXXI Club: Khách đã sử dụng %d điểm để trừ tiền đơn hàng (Số dư điểm còn lại: %d).', $used_pts, $new_pts ) );

		WC()->session->set( 'nexxi_redeem_points', 0 );
		WC()->session->set( 'nexxi_redeem_points_actual', 0 );
	},
	25,
	3
);

/**
 * Render Points = Money redemption widget on Checkout / Cart Totals if user has points.
 */
add_action(
	'woocommerce_review_order_before_order_total',
	static function () {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$pts_cfg = nexxi_kids_get_points_settings();
		if ( '1' !== (string) $pts_cfg['redeem_enabled'] ) {
			return;
		}
		$uid   = get_current_user_id();
		$avail = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $uid ) : 0;
		if ( $avail < (int) $pts_cfg['min_points_redeem'] ) {
			return;
		}
		$active_pts = (int) WC()->session->get( 'nexxi_redeem_points', 0 );
		$pt_val     = (int) $pts_cfg['point_value_vnd'];
		$max_vnd    = $avail * $pt_val;
		?>
		<tr class="nexxi-points-redeem-row">
			<td colspan="2" style="padding:6px 0;border:none;">
				<div style="background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:12px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
					<div style="font-size:12.5px;color:#0f172a;">
						💎 <strong>Điểm NEXXI Club:</strong> Bạn có <strong style="color:#fd6a49;"><?php echo esc_html( number_format_i18n( $avail ) ); ?> điểm</strong>
						(Quy đổi tối đa <strong><?php echo esc_html( nexxi_kids_money( $max_vnd ) ); ?></strong> · 1 điểm = <?php echo esc_html( nexxi_kids_money( $pt_val ) ); ?>)
					</div>
					<div>
						<?php if ( $active_pts > 0 ) : ?>
							<button type="button" onclick="nexxiTogglePointsRedeem('cancel', 0)" style="background:#ef4444;color:#fff;border:none;border-radius:999px;padding:4px 12px;font-size:11.5px;font-weight:700;cursor:pointer;">Bỏ dùng điểm</button>
						<?php else : ?>
							<button type="button" onclick="nexxiTogglePointsRedeem('apply', <?php echo (int) $avail; ?>)" style="background:#031444;color:#fff;border:none;border-radius:999px;padding:4px 12px;font-size:11.5px;font-weight:700;cursor:pointer;">Dùng điểm trừ tiền ngay</button>
						<?php endif; ?>
					</div>
				</div>
				<script>
				window.nexxiTogglePointsRedeem = function(action, pts) {
					var fd = new FormData();
					fd.append('nexxi_points_redeem_action', action);
					fd.append('nexxi_points_to_use', pts);
					fetch(window.location.href, { method: 'POST', credentials: 'same-origin', body: fd }).then(function() {
						if (window.jQuery) {
							window.jQuery(document.body).trigger('update_checkout');
						} else {
							window.location.reload();
						}
					});
				};
				</script>
			</td>
		</tr>
		<?php
	},
	15
);

/* ==========================================================================
 * 2. ADMIN MENU REGISTRATION (PLACED DIRECTLY ABOVE "KHUYẾN MÃI SHOP")
 * ========================================================================== */

function nexxi_kids_customer_crm_admin_menu() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_menu_page(
		'Quản lý khách hàng — NEXXI Club',
		'Quản lý khách hàng',
		'manage_woocommerce',
		'nexxi-customer-manager',
		'nexxi_kids_customer_manager_admin_page',
		'dashicons-groups',
		55.85
	);

	add_submenu_page(
		'nexxi-customer-manager',
		'Quản lý khách hàng — NEXXI Club',
		'👤 Quản lý khách hàng',
		'manage_woocommerce',
		'nexxi-customer-manager',
		'nexxi_kids_customer_manager_admin_page'
	);

	add_submenu_page(
		'nexxi-customer-manager',
		'Hạng thành viên & Điểm thưởng — NEXXI Club',
		'👑 Hạng thành viên',
		'manage_woocommerce',
		'nexxi-member-tiers',
		'nexxi_kids_member_tiers_admin_page'
	);

	add_submenu_page(
		'nexxi-customer-manager',
		'Quản lý đơn hàng — NEXXI',
		'📦 Quản lý đơn hàng',
		'manage_woocommerce',
		'nexxi-order-manager',
		'nexxi_kids_order_manager_admin_page'
	);
}
add_action( 'admin_menu', 'nexxi_kids_customer_crm_admin_menu', 19 );

/**
 * Guarantee "Quản lý khách hàng" (nexxi-customer-manager) always sits immediately above "Khuyến mãi shop" (nexxi-bulk-discount).
 */
add_filter( 'custom_menu_order', '__return_true', 999 );
add_filter(
	'menu_order',
	static function ( $menu_order ) {
		if ( ! is_array( $menu_order ) ) {
			return $menu_order;
		}
		$crm_slug   = 'nexxi-customer-manager';
		$promo_slug = 'nexxi-bulk-discount';
		if ( ! in_array( $crm_slug, $menu_order, true ) || ! in_array( $promo_slug, $menu_order, true ) ) {
			return $menu_order;
		}
		$filtered = array_values(
			array_filter(
				$menu_order,
				static function ( $item ) use ( $crm_slug ) {
					return $item !== $crm_slug;
				}
			)
		);
		$final = array();
		foreach ( $filtered as $item ) {
			if ( $item === $promo_slug ) {
				$final[] = $crm_slug;
			}
			$final[] = $item;
		}
		return $final;
	},
	999
);

/* ==========================================================================
 * 3. SHARED ADMIN NAVIGATION HEADER & STYLES
 * ========================================================================== */

function nexxi_kids_render_crm_top_nav( $active_slug ) {
	$tabs = array(
		'nexxi-customer-manager' => array(
			'label' => '👤 1. Quản lý khách hàng',
			'desc'  => 'Tài khoản, Điểm, Hạng, Đơn hàng, Chi tiêu & Sinh nhật bé',
		),
		'nexxi-member-tiers'     => array(
			'label' => '👑 2. Hạng thành viên',
			'desc'  => 'Cài đặt Hạng & Ưu đãi, Mức thăng hạng, Thời hạn tụt hạng & Điểm = Tiền',
		),
		'nexxi-order-manager'    => array(
			'label' => '📦 3. Quản lý đơn hàng',
			'desc'  => 'Danh sách đơn hàng, Đổi trạng thái nhanh & Thao tác hàng loạt',
		),
	);
	?>
	<style>
		.nexxi-crm-wrap { max-width: 1460px; margin: 18px 20px 40px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #0f172a; }
		.nexxi-crm-nav { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
		.nexxi-crm-nav-item { display: flex; flex-direction: column; gap: 3px; padding: 14px 18px; border-radius: 14px; background: #fff; border: 2px solid #e2e8f0; text-decoration: none; color: #0f172a; transition: all .15s ease; box-shadow: 0 2px 6px rgba(15,23,42,.03); }
		.nexxi-crm-nav-item:hover { border-color: #fd6a49; color: #0f172a; transform: translateY(-1px); }
		.nexxi-crm-nav-item.is-active { background: linear-gradient(135deg, #031444 0%, #1e293b 100%); color: #fff; border-color: #031444; box-shadow: 0 8px 20px rgba(3,20,68,.16); }
		.nexxi-crm-nav-item strong { font-size: 15px; font-weight: 800; }
		.nexxi-crm-nav-item span { font-size: 12px; opacity: 0.8; }
		.nexxi-crm-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 4px 14px rgba(15,23,42,.04); }
		.nexxi-crm-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 20px; }
		.nexxi-crm-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px 18px; display: flex; flex-direction: column; gap: 4px; box-shadow: 0 2px 8px rgba(15,23,42,.03); }
		.nexxi-crm-kpi small { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .03em; }
		.nexxi-crm-kpi strong { font-size: 22px; font-weight: 800; color: #031444; }
		.nexxi-crm-kpi span { font-size: 12px; color: #475569; }
		.nexxi-crm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
		.nexxi-crm-table th { background: #f8fafc; color: #031444; font-weight: 800; text-align: left; padding: 11px 12px; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
		.nexxi-crm-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
		.nexxi-crm-table tr:hover td { background: #fcfcfd; }
		.nexxi-crm-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 15px; border-radius: 999px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; text-decoration: none; transition: all .15s; line-height: 1.3; }
		.nexxi-crm-btn--primary { background: #fd6a49; color: #fff !important; }
		.nexxi-crm-btn--primary:hover { background: #e05332; }
		.nexxi-crm-btn--navy { background: #031444; color: #fff !important; }
		.nexxi-crm-btn--navy:hover { background: #1e293b; }
		.nexxi-crm-btn--ghost { background: #f1f5f9; color: #0f172a !important; border: 1px solid #cbd5e1; }
		.nexxi-crm-btn--ghost:hover { background: #e2e8f0; }
		.nexxi-crm-btn--danger { background: #fee2e2; color: #b91c1c !important; border: 1px solid #fecaca; }
		.nexxi-crm-btn--danger:hover { background: #fecaca; }
		.nexxi-crm-input, .nexxi-crm-select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; color: #0f172a; background: #fff; min-height: 36px; box-sizing: border-box; }
		.nexxi-crm-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 800; white-space: nowrap; }
		.nexxi-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
		.nexxi-form-field { display: flex; flex-direction: column; gap: 5px; }
		.nexxi-form-field label { font-size: 12.5px; font-weight: 700; color: #1e293b; }
	</style>
	<div class="nexxi-crm-nav">
		<?php foreach ( $tabs as $slug => $info ) :
			$url = admin_url( 'admin.php?page=' . $slug );
			$act = ( $slug === $active_slug );
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="nexxi-crm-nav-item <?php echo $act ? 'is-active' : ''; ?>">
				<strong><?php echo esc_html( $info['label'] ); ?></strong>
				<span><?php echo esc_html( $info['desc'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ==========================================================================
 * 4. SUBMENU 1: QUẢN LÝ KHÁCH HÀNG (ADD / EDIT / DELETE / AGGREGATED CRM)
 * ========================================================================== */

function nexxi_kids_handle_customer_manager_post() {
	if ( empty( $_POST['nexxi_crm_customer_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_crm_customer_nonce', 'nexxi_crm_nonce' );
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$action = sanitize_key( wp_unslash( $_POST['nexxi_crm_customer_action'] ) );

	// 1. Add or Update Customer Account
	if ( 'save_customer' === $action ) {
		$edit_uid      = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$full_name     = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone         = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$password      = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$address_1     = isset( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '';
		$ward          = isset( $_POST['ward'] ) ? sanitize_text_field( wp_unslash( $_POST['ward'] ) ) : '';
		$city          = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
		$points        = isset( $_POST['member_points'] ) ? max( 0, (int) $_POST['member_points'] ) : 0;
		$tier_slug     = isset( $_POST['member_tier'] ) ? sanitize_key( wp_unslash( $_POST['member_tier'] ) ) : 'member';
		$tier_lock     = ! empty( $_POST['tier_manual_lock'] ) ? '1' : '0';
		$joined_date   = isset( $_POST['club_joined_date'] ) ? sanitize_text_field( wp_unslash( $_POST['club_joined_date'] ) ) : '';
		$baby_name     = isset( $_POST['baby_name'] ) ? sanitize_text_field( wp_unslash( $_POST['baby_name'] ) ) : '';
		$baby_birthday = isset( $_POST['baby_birthday'] ) ? sanitize_text_field( wp_unslash( $_POST['baby_birthday'] ) ) : '';
		$baby_gender   = isset( $_POST['baby_gender'] ) ? sanitize_text_field( wp_unslash( $_POST['baby_gender'] ) ) : '';
		$spent_adjust  = isset( $_POST['manual_spent_adjust'] ) ? max( 0.0, (float) str_replace( array( '.', ',' ), '', sanitize_text_field( wp_unslash( $_POST['manual_spent_adjust'] ) ) ) ) : 0.0;
		$cust_note     = isset( $_POST['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_note'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			add_settings_error( 'nexxi_crm', 'invalid_email', 'Vui lòng nhập địa chỉ Email hợp lệ cho tài khoản khách hàng.', 'error' );
			return;
		}

		if ( $edit_uid > 0 ) {
			// Update existing user
			$u_args = array(
				'ID'           => $edit_uid,
				'user_email'   => $email,
				'first_name'   => $full_name,
				'display_name' => $full_name ? $full_name : $email,
			);
			if ( '' !== trim( $password ) ) {
				$u_args['user_pass'] = trim( $password );
			}
			$res = wp_update_user( $u_args );
			if ( is_wp_error( $res ) ) {
				add_settings_error( 'nexxi_crm', 'update_err', $res->get_error_message(), 'error' );
				return;
			}
			$target_uid = $edit_uid;
		} else {
			// Create new customer account
			if ( email_exists( $email ) ) {
				add_settings_error( 'nexxi_crm', 'email_exists', 'Email này đã tồn tại trên hệ thống. Vui lòng tìm và sửa tài khoản hiện có.', 'error' );
				return;
			}
			$login = $email;
			if ( username_exists( $login ) ) {
				$login = $email . '_' . strtolower( wp_generate_password( 4, false, false ) );
			}
			$pass_to_use = '' !== trim( $password ) ? trim( $password ) : ( '' !== $phone ? preg_replace( '/\s+/', '', $phone ) : wp_generate_password( 10, false ) );
			$new_id      = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => $pass_to_use,
					'first_name'   => $full_name ? $full_name : $email,
					'display_name' => $full_name ? $full_name : $email,
					'role'         => 'customer',
				)
			);
			if ( is_wp_error( $new_id ) ) {
				add_settings_error( 'nexxi_crm', 'create_err', $new_id->get_error_message(), 'error' );
				return;
			}
			$target_uid = (int) $new_id;
		}

		// Save customer profile & NEXXI Club metadata
		$norm_phone = function_exists( 'nexxi_kids_normalize_phone' ) ? nexxi_kids_normalize_phone( $phone ) : preg_replace( '/\D+/', '', $phone );
		update_user_meta( $target_uid, 'first_name', $full_name );
		update_user_meta( $target_uid, 'billing_first_name', $full_name );
		update_user_meta( $target_uid, 'shipping_first_name', $full_name );
		update_user_meta( $target_uid, 'billing_email', $email );
		update_user_meta( $target_uid, 'billing_phone', $phone );
		update_user_meta( $target_uid, 'shipping_phone', $phone );
		update_user_meta( $target_uid, 'nexxi_phone_normalized', $norm_phone );
		update_user_meta( $target_uid, 'billing_address_1', $address_1 );
		update_user_meta( $target_uid, 'shipping_address_1', $address_1 );
		update_user_meta( $target_uid, 'billing_ward', $ward );
		update_user_meta( $target_uid, 'shipping_ward', $ward );
		update_user_meta( $target_uid, 'billing_city', $city );
		update_user_meta( $target_uid, 'shipping_city', $city );

		update_user_meta( $target_uid, 'nexxi_member_points', $points );
		update_user_meta( $target_uid, 'nexxi_points_backfilled', '1' );
		update_user_meta( $target_uid, 'nexxi_member_tier', $tier_slug );
		update_user_meta( $target_uid, 'nexxi_tier_manual_lock', $tier_lock );
		update_user_meta( $target_uid, 'nexxi_tier_updated_ts', time() );

		if ( '' !== $joined_date ) {
			update_user_meta( $target_uid, 'nexxi_club_joined_date', $joined_date );
		}
		update_user_meta( $target_uid, 'nexxi_baby_name', $baby_name );
		update_user_meta( $target_uid, 'nexxi_baby_birthday', $baby_birthday );
		update_user_meta( $target_uid, 'nexxi_baby_gender', $baby_gender );
		update_user_meta( $target_uid, 'nexxi_manual_spent_adjust', $spent_adjust );
		update_user_meta( $target_uid, 'nexxi_customer_note', $cust_note );

		// Sync default address book entry if address provided
		if ( '' !== $address_1 && function_exists( 'nexxi_kids_get_address_book' ) && function_exists( 'nexxi_kids_save_address_book' ) ) {
			$book = nexxi_kids_get_address_book( $target_uid );
			if ( empty( $book ) ) {
				$book[] = array(
					'id'         => 'addr_' . strtolower( wp_generate_password( 8, false, false ) ),
					'label'      => 'Nhà riêng',
					'recipient'  => $full_name ? $full_name : $email,
					'phone'      => $phone,
					'country'    => 'VN',
					'city'       => $city,
					'ward'       => $ward,
					'address_1'  => $address_1,
					'address_2'  => '',
					'is_default' => true,
				);
				nexxi_kids_save_address_book( $target_uid, $book );
			}
		}

		add_settings_error(
			'nexxi_crm',
			'saved_customer',
			$edit_uid > 0 ? 'Đã cập nhật thông tin tài khoản khách hàng #' . $target_uid . ' thành công!' : 'Đã tạo tài khoản khách hàng mới #' . $target_uid . ' thành công!',
			'updated'
		);
	} elseif ( 'delete_customer' === $action ) {
		$del_uid = isset( $_POST['delete_user_id'] ) ? absint( $_POST['delete_user_id'] ) : 0;
		if ( $del_uid > 0 && $del_uid !== get_current_user_id() && ! user_can( $del_uid, 'manage_options' ) ) {
			wp_delete_user( $del_uid );
			add_settings_error( 'nexxi_crm', 'deleted_customer', 'Đã xóa tài khoản khách hàng #' . $del_uid . '.', 'updated' );
		} else {
			add_settings_error( 'nexxi_crm', 'delete_blocked', 'Không thể xóa tài khoản Quản trị viên (Admin).', 'error' );
		}
	} elseif ( 'bulk_customers' === $action ) {
		$uids      = isset( $_POST['customer_ids'] ) && is_array( $_POST['customer_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['customer_ids'] ) ) : array();
		$bulk_type = isset( $_POST['bulk_customer_op'] ) ? sanitize_key( wp_unslash( $_POST['bulk_customer_op'] ) ) : '';
		$count     = 0;
		foreach ( $uids as $u_id ) {
			if ( $u_id <= 0 ) {
				continue;
			}
			if ( 'delete' === $bulk_type ) {
				if ( $u_id !== get_current_user_id() && ! user_can( $u_id, 'manage_options' ) ) {
					wp_delete_user( $u_id );
					$count++;
				}
			} elseif ( 0 === strpos( $bulk_type, 'set_tier_' ) ) {
				$t_slug = substr( $bulk_type, 9 );
				update_user_meta( $u_id, 'nexxi_member_tier', $t_slug );
				update_user_meta( $u_id, 'nexxi_tier_manual_lock', '1' );
				update_user_meta( $u_id, 'nexxi_tier_updated_ts', time() );
				$count++;
			} elseif ( 'unlock_tier' === $bulk_type ) {
				update_user_meta( $u_id, 'nexxi_tier_manual_lock', '0' );
				nexxi_kids_get_user_tier_info( $u_id );
				$count++;
			} elseif ( 'add_points' === $bulk_type ) {
				$delta = isset( $_POST['bulk_points_delta'] ) ? (int) $_POST['bulk_points_delta'] : 0;
				if ( 0 !== $delta ) {
					$cur = (int) get_user_meta( $u_id, 'nexxi_member_points', true );
					update_user_meta( $u_id, 'nexxi_member_points', max( 0, $cur + $delta ) );
					$count++;
				}
			}
		}
		add_settings_error( 'nexxi_crm', 'bulk_done', 'Đã thực hiện thao tác hàng loạt cho ' . $count . ' tài khoản khách hàng!', 'updated' );
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_customer_manager_post' );

function nexxi_kids_customer_manager_admin_page() {
	$tiers       = nexxi_kids_get_member_tiers_config();
	$pts_cfg     = nexxi_kids_get_points_settings();
	$search      = isset( $_GET['s_cust'] ) ? sanitize_text_field( wp_unslash( $_GET['s_cust'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filter_tier = isset( $_GET['f_tier'] ) ? sanitize_key( wp_unslash( $_GET['f_tier'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filter_bmonth = isset( $_GET['f_bmonth'] ) ? absint( $_GET['f_bmonth'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$sort_by     = isset( $_GET['sort_by'] ) ? sanitize_key( wp_unslash( $_GET['sort_by'] ) ) : 'joined_desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$edit_uid    = isset( $_GET['edit_customer'] ) ? absint( $_GET['edit_customer'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_new_form = isset( $_GET['new_customer'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$all_users = get_users(
		array(
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 500,
		)
	);

	$cur_month = (int) wp_date( 'n' );
	$rows      = array();
	$kpi_total_members = 0;
	$kpi_total_points  = 0;
	$kpi_total_spent   = 0.0;
	$kpi_bday_month    = 0;

	foreach ( $all_users as $u ) {
		$uid       = (int) $u->ID;
		$stats     = nexxi_kids_get_customer_order_stats( $uid );
		$points    = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $uid ) : (int) get_user_meta( $uid, 'nexxi_member_points', true );
		$tier_info = nexxi_kids_get_user_tier_info( $uid );
		$phone     = (string) get_user_meta( $uid, 'billing_phone', true );
		$addr1     = (string) get_user_meta( $uid, 'billing_address_1', true );
		$ward      = (string) get_user_meta( $uid, 'billing_ward', true );
		$city      = (string) get_user_meta( $uid, 'billing_city', true );
		$full_name = trim( $u->first_name . ' ' . $u->last_name );
		if ( '' === $full_name ) {
			$full_name = (string) get_user_meta( $uid, 'billing_first_name', true );
		}
		if ( '' === $full_name ) {
			$full_name = $u->display_name;
		}
		$joined_custom = (string) get_user_meta( $uid, 'nexxi_club_joined_date', true );
		$joined_raw    = $joined_custom ? $joined_custom : $u->user_registered;
		$joined_ts     = $joined_raw ? strtotime( $joined_raw ) : time();

		$baby_name  = (string) get_user_meta( $uid, 'nexxi_baby_name', true );
		$baby_bday  = (string) get_user_meta( $uid, 'nexxi_baby_birthday', true );
		$baby_gen   = (string) get_user_meta( $uid, 'nexxi_baby_gender', true );
		$cust_note  = (string) get_user_meta( $uid, 'nexxi_customer_note', true );
		$bday_month = 0;
		if ( '' !== $baby_bday ) {
			$bts = strtotime( $baby_bday );
			if ( $bts ) {
				$bday_month = (int) gmdate( 'n', $bts );
			}
		}

		$kpi_total_members++;
		$kpi_total_points += $points;
		$kpi_total_spent  += (float) $stats['total_spent'];
		if ( $bday_month === $cur_month ) {
			$kpi_bday_month++;
		}

		// Apply filters
		if ( '' !== $search ) {
			$hay = strtolower( $full_name . ' ' . $u->user_email . ' ' . $phone . ' ' . $baby_name . ' ' . $city );
			if ( false === strpos( $hay, strtolower( $search ) ) ) {
				continue;
			}
		}
		if ( '' !== $filter_tier && $tier_info['tier_slug'] !== $filter_tier ) {
			continue;
		}
		if ( $filter_bmonth > 0 && $bday_month !== $filter_bmonth ) {
			continue;
		}

		$rows[] = array(
			'user'        => $u,
			'uid'         => $uid,
			'full_name'   => $full_name,
			'email'       => $u->user_email,
			'phone'       => $phone,
			'addr_full'   => implode( ', ', array_filter( array( $addr1, $ward, $city ) ) ),
			'points'      => $points,
			'points_vnd'  => $points * (int) $pts_cfg['point_value_vnd'],
			'tier_info'   => $tier_info,
			'order_count' => (int) $stats['order_count'],
			'total_spent' => (float) $stats['total_spent'],
			'joined_ts'   => $joined_ts,
			'joined_str'  => wp_date( 'd/m/Y', $joined_ts ),
			'baby_name'   => $baby_name,
			'baby_bday'   => $baby_bday,
			'baby_gender' => $baby_gen,
			'bday_month'  => $bday_month,
			'note'        => $cust_note,
		);
	}

	// Sort rows
	usort(
		$rows,
		static function ( $a, $b ) use ( $sort_by ) {
			if ( 'spent_desc' === $sort_by ) {
				return $b['total_spent'] <=> $a['total_spent'];
			}
			if ( 'points_desc' === $sort_by ) {
				return $b['points'] <=> $a['points'];
			}
			if ( 'orders_desc' === $sort_by ) {
				return $b['order_count'] <=> $a['order_count'];
			}
			return $b['joined_ts'] <=> $a['joined_ts'];
		}
	);

	$editing_user = $edit_uid > 0 ? get_userdata( $edit_uid ) : null;
	$show_modal   = ( $is_new_form || $editing_user instanceof WP_User );
	?>
	<div class="wrap nexxi-crm-wrap">
		<?php nexxi_kids_render_crm_top_nav( 'nexxi-customer-manager' ); ?>
		<?php settings_errors( 'nexxi_crm' ); ?>

		<!-- KPI Summary Bar -->
		<div class="nexxi-crm-kpis">
			<div class="nexxi-crm-kpi">
				<small>👥 Tổng tài khoản thành viên</small>
				<strong><?php echo esc_html( number_format_i18n( $kpi_total_members ) ); ?> khách hàng</strong>
				<span>Tự động đồng bộ từ đơn hàng &amp; đăng ký</span>
			</div>
			<div class="nexxi-crm-kpi">
				<small>💎 Tổng điểm NEXXI lưu hành</small>
				<strong style="color:#fd6a49;"><?php echo esc_html( number_format_i18n( $kpi_total_points ) ); ?> điểm</strong>
				<span>Tương đương <?php echo esc_html( nexxi_kids_money( $kpi_total_points * (int) $pts_cfg['point_value_vnd'] ) ); ?></span>
			</div>
			<div class="nexxi-crm-kpi">
				<small>💰 Tổng chi tiêu tích lũy</small>
				<strong style="color:#0b6b2f;"><?php echo esc_html( nexxi_kids_money( $kpi_total_spent ) ); ?></strong>
				<span>Cơ sở xét thăng hạng NEXXI Club</span>
			</div>
			<div class="nexxi-crm-kpi">
				<small>🎂 Sinh nhật của bé tháng <?php echo (int) $cur_month; ?></small>
				<strong style="color:#9d174d;"><?php echo (int) $kpi_bday_month; ?> bé</strong>
				<span><a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager&f_bmonth=' . $cur_month ) ); ?>">Lọc danh sách bé sinh nhật tháng này &rarr;</a></span>
			</div>
		</div>

		<?php
		// Add / Edit Customer Form Card
		if ( $show_modal ) :
			$e_uid    = $editing_user ? (int) $editing_user->ID : 0;
			$e_name   = $editing_user ? trim( $editing_user->first_name . ' ' . $editing_user->last_name ) : '';
			if ( $editing_user && '' === $e_name ) {
				$e_name = (string) get_user_meta( $e_uid, 'billing_first_name', true );
			}
			$e_email  = $editing_user ? $editing_user->user_email : '';
			$e_phone  = $e_uid ? (string) get_user_meta( $e_uid, 'billing_phone', true ) : '';
			$e_addr1  = $e_uid ? (string) get_user_meta( $e_uid, 'billing_address_1', true ) : '';
			$e_ward   = $e_uid ? (string) get_user_meta( $e_uid, 'billing_ward', true ) : '';
			$e_city   = $e_uid ? (string) get_user_meta( $e_uid, 'billing_city', true ) : '';
			$e_pts    = $e_uid ? (int) nexxi_kids_get_user_points( $e_uid ) : 0;
			$e_tinfo  = $e_uid ? nexxi_kids_get_user_tier_info( $e_uid ) : null;
			$e_tier   = $e_tinfo ? $e_tinfo['tier_slug'] : 'member';
			$e_lock   = $e_uid ? ( '1' === (string) get_user_meta( $e_uid, 'nexxi_tier_manual_lock', true ) ) : false;
			$e_joined = $e_uid ? (string) get_user_meta( $e_uid, 'nexxi_club_joined_date', true ) : '';
			if ( '' === $e_joined && $editing_user && $editing_user->user_registered ) {
				$e_joined = gmdate( 'Y-m-d', strtotime( $editing_user->user_registered ) );
			} elseif ( '' === $e_joined ) {
				$e_joined = wp_date( 'Y-m-d' );
			}
			$e_bname  = $e_uid ? (string) get_user_meta( $e_uid, 'nexxi_baby_name', true ) : '';
			$e_bbday  = $e_uid ? (string) get_user_meta( $e_uid, 'nexxi_baby_birthday', true ) : '';
			$e_bgen   = $e_uid ? (string) get_user_meta( $e_uid, 'nexxi_baby_gender', true ) : '';
			$e_spent  = $e_uid ? (float) get_user_meta( $e_uid, 'nexxi_manual_spent_adjust', true ) : 0.0;
			$e_note   = $e_uid ? (string) get_user_meta( $e_uid, 'nexxi_customer_note', true ) : '';
			?>
			<div class="nexxi-crm-card" style="border:2px solid #fd6a49;background:#fffaf8;">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:10px;border-bottom:1px dashed #ffdad2;">
					<h2 style="margin:0;font-size:17px;color:#031444;">
						<?php echo $e_uid > 0 ? '✏️ Chỉnh sửa hồ sơ khách hàng #' . (int) $e_uid . ' — ' . esc_html( $e_name ) : '➕ Thêm tài khoản khách hàng mới vào NEXXI Club'; ?>
					</h2>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--ghost">✕ Đóng form</a>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>">
					<?php wp_nonce_field( 'nexxi_crm_customer_nonce', 'nexxi_crm_nonce' ); ?>
					<input type="hidden" name="nexxi_crm_customer_action" value="save_customer">
					<input type="hidden" name="customer_id" value="<?php echo (int) $e_uid; ?>">

					<div class="nexxi-form-grid">
						<div class="nexxi-form-field">
							<label>Họ và tên khách hàng (Mẹ/Ba) *</label>
							<input type="text" name="full_name" required class="nexxi-crm-input" value="<?php echo esc_attr( $e_name ); ?>" placeholder="VD: Nguyễn Thị Minh Anh">
						</div>
						<div class="nexxi-form-field">
							<label>Địa chỉ Email (Tên đăng nhập) *</label>
							<input type="email" name="email" required class="nexxi-crm-input" value="<?php echo esc_attr( $e_email ); ?>" placeholder="VD: minhanh@gmail.com">
						</div>
						<div class="nexxi-form-field">
							<label>Số điện thoại *</label>
							<input type="text" name="phone" class="nexxi-crm-input" value="<?php echo esc_attr( $e_phone ); ?>" placeholder="VD: 0942958091">
						</div>
						<div class="nexxi-form-field">
							<label><?php echo $e_uid > 0 ? 'Đặt mật khẩu mới (Để trống nếu giữ nguyên)' : 'Mật khẩu (Để trống = tự lấy SĐT làm mật khẩu)'; ?></label>
							<input type="text" name="password" class="nexxi-crm-input" value="" placeholder="<?php echo $e_uid > 0 ? 'Nhập nếu muốn đổi mật khẩu...' : 'Mặc định là Số điện thoại'; ?>">
						</div>

						<div class="nexxi-form-field">
							<label>💎 Điểm thành viên NEXXI hiện có</label>
							<input type="number" name="member_points" min="0" class="nexxi-crm-input" value="<?php echo (int) $e_pts; ?>">
						</div>
						<div class="nexxi-form-field">
							<label>👑 Hạng thành viên NEXXI Club</label>
							<select name="member_tier" class="nexxi-crm-select">
								<?php foreach ( $tiers as $t_slug => $t_info ) : ?>
									<option value="<?php echo esc_attr( $t_slug ); ?>" <?php selected( $e_tier, $t_slug ); ?>>
										<?php echo esc_html( ( $t_info['icon'] ?? '👑' ) . ' ' . $t_info['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<label style="font-weight:500;font-size:12px;margin-top:3px;display:inline-flex;align-items:center;gap:5px;">
								<input type="checkbox" name="tier_manual_lock" value="1" <?php checked( $e_lock ); ?>>
								Cố định hạng này (không tự động tụt hạng)
							</label>
						</div>
						<div class="nexxi-form-field">
							<label>📅 Ngày tham gia NEXXI Club</label>
							<input type="date" name="club_joined_date" class="nexxi-crm-input" value="<?php echo esc_attr( $e_joined ); ?>">
						</div>
						<div class="nexxi-form-field">
							<label>💰 Tổng chi tiêu ghi nhận thêm (VNĐ - nếu mua tại cửa hàng)</label>
							<input type="number" name="manual_spent_adjust" min="0" step="any" class="nexxi-crm-input" value="<?php echo esc_attr( (int) $e_spent ); ?>" placeholder="0">
						</div>

						<div class="nexxi-form-field">
							<label>👶 Tên của bé</label>
							<input type="text" name="baby_name" class="nexxi-crm-input" value="<?php echo esc_attr( $e_bname ); ?>" placeholder="VD: Bé Bắp / Bé Sóc">
						</div>
						<div class="nexxi-form-field">
							<label>🎂 Ngày sinh nhật của bé</label>
							<input type="date" name="baby_birthday" class="nexxi-crm-input" value="<?php echo esc_attr( $e_bbday ); ?>">
						</div>
						<div class="nexxi-form-field">
							<label>🎀 Giới tính của bé</label>
							<select name="baby_gender" class="nexxi-crm-select">
								<option value="" <?php selected( $e_bgen, '' ); ?>>-- Chọn giới tính --</option>
								<option value="girl" <?php selected( $e_bgen, 'girl' ); ?>>👧 Bé gái</option>
								<option value="boy" <?php selected( $e_bgen, 'boy' ); ?>>👦 Bé trai</option>
								<option value="both" <?php selected( $e_bgen, 'both' ); ?>>👧👦 Có cả bé trai &amp; bé gái</option>
							</select>
						</div>
						<div class="nexxi-form-field">
							<label>🏠 Địa chỉ nhận hàng (Số nhà, Đường)</label>
							<input type="text" name="address_1" class="nexxi-crm-input" value="<?php echo esc_attr( $e_addr1 ); ?>" placeholder="Số nhà, tên đường...">
						</div>
						<div class="nexxi-form-field">
							<label>📍 Phường / Xã</label>
							<input type="text" name="ward" class="nexxi-crm-input" value="<?php echo esc_attr( $e_ward ); ?>" placeholder="Phường / Xã">
						</div>
						<div class="nexxi-form-field">
							<label>🌆 Tỉnh / Thành phố</label>
							<input type="text" name="city" class="nexxi-crm-input" value="<?php echo esc_attr( $e_city ); ?>" placeholder="Tỉnh / Thành phố">
						</div>
						<div class="nexxi-form-field" style="grid-column: span 2;">
							<label>📝 Ghi chú chăm sóc khách hàng (Sở thích, chiều cao/cân nặng của bé...)</label>
							<input type="text" name="customer_note" class="nexxi-crm-input" value="<?php echo esc_attr( $e_note ); ?>" placeholder="VD: Bé 18kg cao 105cm, mẹ thích tông pastel...">
						</div>
					</div>

					<div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
						<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--primary">💾 <?php echo $e_uid > 0 ? 'Lưu cập nhật khách hàng' : 'Tạo tài khoản khách hàng'; ?></button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--ghost">Hủy</a>
					</div>
				</form>
			</div>
		<?php endif; ?>

		<!-- Filter & Search Bar -->
		<div class="nexxi-crm-card">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:1;">
					<input type="hidden" name="page" value="nexxi-customer-manager">
					<input type="search" name="s_cust" value="<?php echo esc_attr( $search ); ?>" class="nexxi-crm-input" style="min-width:250px;" placeholder="🔍 Tìm tên khách, SĐT, email, tên bé...">
					<select name="f_tier" class="nexxi-crm-select">
						<option value="">-- Tất cả hạng thành viên --</option>
						<?php foreach ( $tiers as $t_slug => $t_info ) : ?>
							<option value="<?php echo esc_attr( $t_slug ); ?>" <?php selected( $filter_tier, $t_slug ); ?>>
								<?php echo esc_html( ( $t_info['icon'] ?? '👑' ) . ' ' . $t_info['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="f_bmonth" class="nexxi-crm-select">
						<option value="0">-- Tháng sinh nhật của bé --</option>
						<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
							<option value="<?php echo (int) $m; ?>" <?php selected( $filter_bmonth, $m ); ?>>🎂 Sinh nhật tháng <?php echo (int) $m; ?></option>
						<?php endfor; ?>
					</select>
					<select name="sort_by" class="nexxi-crm-select">
						<option value="joined_desc" <?php selected( $sort_by, 'joined_desc' ); ?>>Sắp xếp: Mới tham gia</option>
						<option value="spent_desc" <?php selected( $sort_by, 'spent_desc' ); ?>>Sắp xếp: Chi tiêu cao nhất</option>
						<option value="points_desc" <?php selected( $sort_by, 'points_desc' ); ?>>Sắp xếp: Nhiều điểm nhất</option>
						<option value="orders_desc" <?php selected( $sort_by, 'orders_desc' ); ?>>Sắp xếp: Nhiều đơn nhất</option>
					</select>
					<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--navy">Lọc danh sách</button>
					<?php if ( '' !== $search || '' !== $filter_tier || $filter_bmonth > 0 ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--ghost">Xóa lọc</a>
					<?php endif; ?>
				</form>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager&new_customer=1' ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--primary">➕ Thêm tài khoản khách hàng</a>
			</div>

			<!-- Bulk Customer Form + Table -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>">
				<?php wp_nonce_field( 'nexxi_crm_customer_nonce', 'nexxi_crm_nonce' ); ?>
				<input type="hidden" name="nexxi_crm_customer_action" value="bulk_customers">

				<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#f8fafc;border-radius:10px;margin-bottom:12px;border:1px solid #e2e8f0;">
					<strong style="font-size:12.5px;color:#031444;">⚡ Thao tác hàng loạt:</strong>
					<select name="bulk_customer_op" class="nexxi-crm-select" onchange="document.getElementById('nx-bulk-pts-wrap').style.display=(this.value==='add_points'?'inline-flex':'none');">
						<option value="">-- Chọn thao tác --</option>
						<?php foreach ( $tiers as $t_slug => $t_info ) : ?>
							<option value="<?php echo esc_attr( 'set_tier_' . $t_slug ); ?>">Gán hạng cố định: <?php echo esc_html( $t_info['name'] ); ?></option>
						<?php endforeach; ?>
						<option value="unlock_tier">Mở khóa hạng (Tự động xét theo chi tiêu/điểm)</option>
						<option value="add_points">Cộng / Trừ điểm thành viên hàng loạt</option>
						<option value="delete">🗑️ Xóa các tài khoản đã chọn</option>
					</select>
					<span id="nx-bulk-pts-wrap" style="display:none;align-items:center;gap:6px;">
						<input type="number" name="bulk_points_delta" class="nexxi-crm-input" style="width:130px;" placeholder="VD: +50 hoặc -20">
					</span>
					<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--ghost" onclick="return confirm('Xác nhận thực hiện thao tác hàng loạt cho các khách hàng đã tích chọn?');">Áp dụng</button>
					<span style="margin-left:auto;font-size:12.5px;color:#64748b;">Hiển thị <strong><?php echo count( $rows ); ?></strong> khách hàng</span>
				</div>

				<div style="overflow-x:auto;">
					<table class="nexxi-crm-table">
						<thead>
							<tr>
								<th style="width:32px;"><input type="checkbox" onclick="document.querySelectorAll('.nx-cust-cb').forEach(c=>c.checked=this.checked)"></th>
								<th>Khách hàng &amp; Liên hệ</th>
								<th>Hạng thành viên</th>
								<th>Điểm NEXXI</th>
								<th>Tổng đơn đã đặt</th>
								<th>Tổng tiền chi tiêu</th>
								<th>Tham gia NEXXI Club</th>
								<th>Sinh nhật của bé</th>
								<th style="text-align:right;">Thao tác</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="9" style="text-align:center;padding:30px;color:#64748b;">Chưa có khách hàng nào khớp điều kiện lọc.</td></tr>
							<?php else : ?>
								<?php foreach ( $rows as $r ) :
									$ti   = $r['tier_info'];
									$tier = $ti['tier'];
									?>
									<tr>
										<td><input type="checkbox" class="nx-cust-cb" name="customer_ids[]" value="<?php echo (int) $r['uid']; ?>"></td>
										<td>
											<div style="font-weight:800;color:#031444;font-size:13.5px;">
												<?php echo esc_html( $r['full_name'] ); ?>
												<span style="color:#94a3b8;font-weight:500;font-size:11.5px;">#<?php echo (int) $r['uid']; ?></span>
											</div>
											<div style="color:#475569;font-size:12px;">
												📧 <?php echo esc_html( $r['email'] ); ?>
												<?php if ( '' !== $r['phone'] ) : ?>
													· 📞 <strong style="color:#fd6a49;"><?php echo esc_html( $r['phone'] ); ?></strong>
												<?php endif; ?>
											</div>
											<?php if ( '' !== $r['addr_full'] ) : ?>
												<div style="color:#64748b;font-size:11.5px;margin-top:2px;">📍 <?php echo esc_html( $r['addr_full'] ); ?></div>
											<?php endif; ?>
											<?php if ( '' !== $r['note'] ) : ?>
												<div style="color:#0369a1;background:#e0f2fe;display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;margin-top:3px;">📝 <?php echo esc_html( $r['note'] ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<span class="nexxi-crm-badge" style="background:<?php echo esc_attr( $tier['bg_color'] ?? '#f1f5f9' ); ?>;color:<?php echo esc_attr( $tier['color'] ?? '#0f172a' ); ?>;">
												<?php echo esc_html( ( $tier['icon'] ?? '👑' ) . ' ' . $tier['name'] ); ?>
											</span>
											<?php if ( $ti['is_manual'] ) : ?>
												<span style="font-size:10.5px;color:#7c3aed;font-weight:700;display:block;margin-top:3px;">🔒 Đã khóa hạng cố định</span>
											<?php endif; ?>
											<div style="font-size:11px;color:#64748b;margin-top:2px;">Hạn: <?php echo esc_html( $ti['expires_label'] ); ?></div>
										</td>
										<td>
											<strong style="color:#fd6a49;font-size:14px;"><?php echo esc_html( number_format_i18n( $r['points'] ) ); ?> điểm</strong>
											<div style="font-size:11px;color:#64748b;">≈ <?php echo esc_html( nexxi_kids_money( $r['points_vnd'] ) ); ?></div>
										</td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager&cust_id=' . $r['uid'] ) ); ?>" style="font-weight:800;color:#031444;text-decoration:underline;">
												<?php echo (int) $r['order_count']; ?> đơn hàng
											</a>
										</td>
										<td>
											<strong style="color:#0b6b2f;font-size:13.5px;"><?php echo esc_html( nexxi_kids_money( $r['total_spent'] ) ); ?></strong>
											<?php if ( $ti['next_tier'] ) : ?>
												<div style="font-size:11px;color:#64748b;">Còn <?php echo esc_html( nexxi_kids_money( $ti['spent_to_next'] ) ); ?> lên <?php echo esc_html( $ti['next_tier']['name'] ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<div style="font-weight:700;color:#1e293b;"><?php echo esc_html( $r['joined_str'] ); ?></div>
											<div style="font-size:11px;color:#64748b;">
												<?php
												$days = max( 1, (int) floor( ( time() - $r['joined_ts'] ) / DAY_IN_SECONDS ) );
												echo esc_html( $days . ' ngày đồng hành' );
												?>
											</div>
										</td>
										<td>
											<?php if ( '' !== $r['baby_name'] || '' !== $r['baby_bday'] ) : ?>
												<div style="font-weight:700;color:#031444;">
													<?php
													$g_icon = 'girl' === $r['baby_gender'] ? '👧 ' : ( 'boy' === $r['baby_gender'] ? '👦 ' : '👶 ' );
													echo esc_html( $g_icon . ( $r['baby_name'] ? $r['baby_name'] : 'Bé yêu' ) );
													?>
												</div>
												<?php if ( '' !== $r['baby_bday'] ) : ?>
													<div style="font-size:12px;color:#475569;">
														🎂 <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $r['baby_bday'] ) ) ); ?>
													</div>
													<?php if ( $r['bday_month'] === $cur_month ) : ?>
														<span class="nexxi-crm-badge" style="background:#fce7f3;color:#9d174d;margin-top:2px;">🎉 Sinh nhật tháng này!</span>
													<?php endif; ?>
												<?php endif; ?>
											<?php else : ?>
												<span style="color:#94a3b8;font-size:12px;">Chưa cập nhật</span>
											<?php endif; ?>
										</td>
										<td style="text-align:right;white-space:nowrap;">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager&edit_customer=' . $r['uid'] ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--ghost" style="padding:5px 11px;font-size:12px;">✏️ Sửa</a>
											<?php if ( $r['uid'] !== get_current_user_id() && ! user_can( $r['uid'], 'manage_options' ) ) : ?>
												<button type="button" onclick="if(confirm('Bạn có chắc chắn muốn xóa tài khoản khách hàng <?php echo esc_js( $r['full_name'] ); ?>?')){var f=document.getElementById('nx-single-del-form');f.delete_user_id.value='<?php echo (int) $r['uid']; ?>';f.submit();}" class="nexxi-crm-btn nexxi-crm-btn--danger" style="padding:5px 10px;font-size:12px;">🗑️ Xóa</button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>

			<form id="nx-single-del-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-customer-manager' ) ); ?>" style="display:none;">
				<?php wp_nonce_field( 'nexxi_crm_customer_nonce', 'nexxi_crm_nonce' ); ?>
				<input type="hidden" name="nexxi_crm_customer_action" value="delete_customer">
				<input type="hidden" name="delete_user_id" value="0">
			</form>
		</div>
	</div>
	<?php
}

/* ==========================================================================
 * 5. SUBMENU 2: HẠNG THÀNH VIÊN (TIERS, UPGRADE, DOWNGRADE, POINTS = MONEY)
 * ========================================================================== */

function nexxi_kids_handle_member_tiers_post() {
	if ( empty( $_POST['nexxi_crm_tiers_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_crm_tiers_nonce', 'nexxi_tiers_nonce' );

	$action = sanitize_key( wp_unslash( $_POST['nexxi_crm_tiers_action'] ) );

	// 1. Save Points = Money + Upgrade/Downgrade Settings + Tiers Table
	if ( 'save_all_tier_settings' === $action ) {
		$pts_settings = array(
			'earn_unit_vnd'     => isset( $_POST['earn_unit_vnd'] ) ? max( 1, absint( str_replace( array( '.', ',' ), '', sanitize_text_field( wp_unslash( $_POST['earn_unit_vnd'] ) ) ) ) ) : 10000,
			'redeem_enabled'    => ! empty( $_POST['redeem_enabled'] ) ? '1' : '0',
			'point_value_vnd'   => isset( $_POST['point_value_vnd'] ) ? max( 1, absint( str_replace( array( '.', ',' ), '', sanitize_text_field( wp_unslash( $_POST['point_value_vnd'] ) ) ) ) ) : 1000,
			'min_points_redeem' => isset( $_POST['min_points_redeem'] ) ? max( 1, absint( $_POST['min_points_redeem'] ) ) : 10,
			'max_redeem_pct'    => isset( $_POST['max_redeem_pct'] ) ? min( 100, max( 1, absint( $_POST['max_redeem_pct'] ) ) ) : 50,
		);
		update_option( 'nexxi_points_settings', $pts_settings );

		$rules_settings = array(
			'upgrade_metric'          => isset( $_POST['upgrade_metric'] ) ? sanitize_key( wp_unslash( $_POST['upgrade_metric'] ) ) : 'either',
			'downgrade_enabled'       => ! empty( $_POST['downgrade_enabled'] ) ? '1' : '0',
			'downgrade_months'        => isset( $_POST['downgrade_months'] ) ? max( 1, absint( $_POST['downgrade_months'] ) ) : 6,
			'downgrade_mode'          => isset( $_POST['downgrade_mode'] ) ? sanitize_key( wp_unslash( $_POST['downgrade_mode'] ) ) : 'step_down_1',
			'protect_manual_override' => ! empty( $_POST['protect_manual_override'] ) ? '1' : '0',
			'apply_tier_discount'     => ! empty( $_POST['apply_tier_discount'] ) ? '1' : '0',
		);
		update_option( 'nexxi_tier_rules_settings', $rules_settings );

		// Parse tiers array
		$slugs  = isset( $_POST['t_slug'] ) && is_array( $_POST['t_slug'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['t_slug'] ) ) : array();
		$names  = isset( $_POST['t_name'] ) && is_array( $_POST['t_name'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_name'] ) ) : array();
		$icons  = isset( $_POST['t_icon'] ) && is_array( $_POST['t_icon'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_icon'] ) ) : array();
		$colors = isset( $_POST['t_color'] ) && is_array( $_POST['t_color'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_color'] ) ) : array();
		$bgs    = isset( $_POST['t_bg'] ) && is_array( $_POST['t_bg'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_bg'] ) ) : array();
		$spents = isset( $_POST['t_spent'] ) && is_array( $_POST['t_spent'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_spent'] ) ) : array();
		$pts    = isset( $_POST['t_points'] ) && is_array( $_POST['t_points'] ) ? array_map( 'absint', wp_unslash( $_POST['t_points'] ) ) : array();
		$discs  = isset( $_POST['t_disc'] ) && is_array( $_POST['t_disc'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_disc'] ) ) : array();
		$mults  = isset( $_POST['t_mult'] ) && is_array( $_POST['t_mult'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_mult'] ) ) : array();
		$gifts  = isset( $_POST['t_gift'] ) && is_array( $_POST['t_gift'] ) ? array_map( 'absint', wp_unslash( $_POST['t_gift'] ) ) : array();
		$perks  = isset( $_POST['t_perks'] ) && is_array( $_POST['t_perks'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['t_perks'] ) ) : array();

		$new_tiers = array();
		foreach ( $names as $idx => $t_name ) {
			$t_name = trim( $t_name );
			if ( '' === $t_name ) {
				continue;
			}
			$slug = ! empty( $slugs[ $idx ] ) ? $slugs[ $idx ] : sanitize_title( $t_name );
			if ( '' === $slug ) {
				$slug = 'tier_' . $idx;
			}
			$raw_spent = isset( $spents[ $idx ] ) ? str_replace( array( '.', ',' ), '', $spents[ $idx ] ) : '0';
			$new_tiers[ $slug ] = array(
				'slug'          => $slug,
				'name'          => $t_name,
				'icon'          => ! empty( $icons[ $idx ] ) ? $icons[ $idx ] : '👑',
				'color'         => ! empty( $colors[ $idx ] ) ? $colors[ $idx ] : '#031444',
				'bg_color'      => ! empty( $bgs[ $idx ] ) ? $bgs[ $idx ] : '#f1f5f9',
				'min_spent'     => max( 0, (float) $raw_spent ),
				'min_points'    => isset( $pts[ $idx ] ) ? (int) $pts[ $idx ] : 0,
				'discount_pct'  => isset( $discs[ $idx ] ) ? min( 90.0, max( 0.0, (float) str_replace( ',', '.', $discs[ $idx ] ) ) ) : 0.0,
				'point_mult'    => isset( $mults[ $idx ] ) ? max( 1.0, (float) str_replace( ',', '.', $mults[ $idx ] ) ) : 1.0,
				'birthday_gift' => isset( $gifts[ $idx ] ) ? (int) $gifts[ $idx ] : 0,
				'perks'         => isset( $perks[ $idx ] ) ? $perks[ $idx ] : '',
			);
		}
		if ( ! empty( $new_tiers ) ) {
			uasort(
				$new_tiers,
				static function ( $a, $b ) {
					return ( (float) $a['min_spent'] ) <=> ( (float) $b['min_spent'] );
				}
			);
			update_option( 'nexxi_member_tiers_config', $new_tiers );
		}

		add_settings_error( 'nexxi_tiers', 'tiers_saved', 'Đã lưu toàn bộ cài đặt Hạng thành viên, Mức thăng hạng, Thời hạn tụt hạng và Quy đổi Điểm = Tiền!', 'updated' );
	} elseif ( 'update_account_tier_row' === $action ) {
		$uid       = isset( $_POST['account_uid'] ) ? absint( $_POST['account_uid'] ) : 0;
		$tier_slug = isset( $_POST['account_tier'] ) ? sanitize_key( wp_unslash( $_POST['account_tier'] ) ) : 'member';
		$mode      = isset( $_POST['account_mode'] ) ? sanitize_key( wp_unslash( $_POST['account_mode'] ) ) : 'auto';
		$pts       = isset( $_POST['account_points'] ) ? max( 0, (int) $_POST['account_points'] ) : 0;
		if ( $uid > 0 ) {
			update_user_meta( $uid, 'nexxi_member_points', $pts );
			update_user_meta( $uid, 'nexxi_member_tier', $tier_slug );
			update_user_meta( $uid, 'nexxi_tier_manual_lock', 'manual' === $mode ? '1' : '0' );
			update_user_meta( $uid, 'nexxi_tier_updated_ts', time() );
			if ( 'auto' === $mode ) {
				nexxi_kids_get_user_tier_info( $uid );
			}
			add_settings_error( 'nexxi_tiers', 'acc_updated', 'Đã cập nhật hạng thành viên & điểm cho tài khoản #' . $uid . '!', 'updated' );
		}
	} elseif ( 'recalc_all_tiers' === $action ) {
		$users = get_users( array( 'fields' => 'ID', 'number' => 1000 ) );
		$cnt   = 0;
		foreach ( $users as $u_id ) {
			nexxi_kids_get_user_tier_info( (int) $u_id );
			$cnt++;
		}
		add_settings_error( 'nexxi_tiers', 'recalc_done', 'Đã rà soát thăng hạng & xét thời hạn tụt hạng cho ' . $cnt . ' tài khoản khách hàng!', 'updated' );
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_member_tiers_post' );

function nexxi_kids_member_tiers_admin_page() {
	$tiers   = nexxi_kids_get_member_tiers_config();
	$rules   = nexxi_kids_get_tier_rules_settings();
	$pts_cfg = nexxi_kids_get_points_settings();
	$users   = get_users( array( 'orderby' => 'registered', 'order' => 'DESC', 'number' => 200 ) );
	?>
	<div class="wrap nexxi-crm-wrap">
		<?php nexxi_kids_render_crm_top_nav( 'nexxi-member-tiers' ); ?>
		<?php settings_errors( 'nexxi_tiers' ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-member-tiers' ) ); ?>">
			<?php wp_nonce_field( 'nexxi_crm_tiers_nonce', 'nexxi_tiers_nonce' ); ?>
			<input type="hidden" name="nexxi_crm_tiers_action" value="save_all_tier_settings">

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;">
				<!-- Card 1: Cài đặt Điểm = Tiền -->
				<div class="nexxi-crm-card">
					<h2 style="margin:0 0 12px;font-size:16.5px;color:#031444;">💎 1. Cài đặt Tích Điểm &amp; Quy Đổi Điểm = Tiền</h2>
					<div class="nexxi-form-grid">
						<div class="nexxi-form-field">
							<label>Mức tích điểm (Bao nhiêu VNĐ = 1 điểm)</label>
							<input type="number" name="earn_unit_vnd" min="1" step="any" class="nexxi-crm-input" value="<?php echo (int) $pts_cfg['earn_unit_vnd']; ?>">
							<span style="font-size:11.5px;color:#64748b;">VD: Nhập <code>1000</code> hoặc <code>10000</code> nghĩa là khách mua bấy nhiêu VNĐ được +1 điểm.</span>
						</div>
						<div class="nexxi-form-field">
							<label>Giá trị tiêu điểm (1 Điểm = Bao nhiêu VNĐ)</label>
							<input type="number" name="point_value_vnd" min="1" step="any" class="nexxi-crm-input" value="<?php echo (int) $pts_cfg['point_value_vnd']; ?>">
							<span style="font-size:11.5px;color:#64748b;">VD: Nhập <code>1000</code> nghĩa là 1 điểm trừ trực tiếp 1.000₫ trên đơn.</span>
						</div>
						<div class="nexxi-form-field">
							<label>Số điểm tối thiểu để được đổi</label>
							<input type="number" name="min_points_redeem" min="1" step="any" class="nexxi-crm-input" value="<?php echo (int) $pts_cfg['min_points_redeem']; ?>">
						</div>
						<div class="nexxi-form-field">
							<label>Giới hạn trừ bằng điểm tối đa (% đơn hàng)</label>
							<input type="number" name="max_redeem_pct" min="1" max="100" step="any" class="nexxi-crm-input" value="<?php echo (int) $pts_cfg['max_redeem_pct']; ?>">
						</div>
					</div>
					<label style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;font-weight:700;color:#031444;cursor:pointer;">
						<input type="checkbox" name="redeem_enabled" value="1" <?php checked( $pts_cfg['redeem_enabled'], '1' ); ?>>
						Cho phép khách hàng bấm "Dùng điểm trừ tiền ngay" tại trang Thanh toán
					</label>
				</div>

				<!-- Card 2: Cài đặt Mức Thăng Hạng & Thời Hạn Tụt Hạng -->
				<div class="nexxi-crm-card">
					<h2 style="margin:0 0 12px;font-size:16.5px;color:#031444;">⏳ 2. Cài đặt Điều Kiện Thăng Hạng &amp; Thời Hạn Tụt Hạng</h2>
					<div class="nexxi-form-grid">
						<div class="nexxi-form-field">
							<label>Tiêu chí xét thăng hạng thành viên</label>
							<select name="upgrade_metric" class="nexxi-crm-select">
								<option value="either" <?php selected( $rules['upgrade_metric'], 'either' ); ?>>Đạt 1 trong 2: Tổng chi tiêu HOẶC Điểm tích lũy</option>
								<option value="spent" <?php selected( $rules['upgrade_metric'], 'spent' ); ?>>Chỉ xét theo Tổng tiền chi tiêu (VNĐ)</option>
								<option value="points" <?php selected( $rules['upgrade_metric'], 'points' ); ?>>Chỉ xét theo Điểm tích lũy</option>
								<option value="both" <?php selected( $rules['upgrade_metric'], 'both' ); ?>>Cần đạt cả Tổng chi tiêu VÀ Điểm tích lũy</option>
							</select>
						</div>
						<div class="nexxi-form-field">
							<label>Thời hạn duy trì hạng trước khi tụt hạng (Tháng)</label>
							<input type="number" name="downgrade_months" min="1" max="60" class="nexxi-crm-input" value="<?php echo (int) $rules['downgrade_months']; ?>">
							<span style="font-size:11.5px;color:#64748b;">Nếu quá số tháng này không phát sinh đơn mới sẽ xét tụt hạng.</span>
						</div>
						<div class="nexxi-form-field">
							<label>Hình thức tụt hạng khi hết thời hạn</label>
							<select name="downgrade_mode" class="nexxi-crm-select">
								<option value="step_down_1" <?php selected( $rules['downgrade_mode'], 'step_down_1' ); ?>>Hạ xuống 1 bậc hạng liền kề</option>
								<option value="period_spend" <?php selected( $rules['downgrade_mode'], 'period_spend' ); ?>>Xét lại hạng theo chi tiêu trong chu kỳ</option>
								<option value="reset_base" <?php selected( $rules['downgrade_mode'], 'reset_base' ); ?>>Hạ về hạng Thành Viên Mới ban đầu</option>
							</select>
						</div>
						<div class="nexxi-form-field" style="justify-content:center;">
							<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
								<input type="checkbox" name="downgrade_enabled" value="1" <?php checked( $rules['downgrade_enabled'], '1' ); ?>>
								Bật tự động kiểm tra &amp; tụt hạng khi quá hạn
							</label>
							<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-top:4px;">
								<input type="checkbox" name="protect_manual_override" value="1" <?php checked( $rules['protect_manual_override'], '1' ); ?>>
								Không tụt hạng các tài khoản do Admin khóa cố định
							</label>
							<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;margin-top:4px;">
								<input type="checkbox" name="apply_tier_discount" value="1" <?php checked( $rules['apply_tier_discount'], '1' ); ?>>
								Tự động giảm thêm % ưu đãi hạng trên hóa đơn
							</label>
						</div>
					</div>
				</div>
			</div>

			<!-- Card 3: Bảng Cài Đặt Hạng Thành Viên, Mức Thăng Hạng & Ưu Đãi -->
			<div class="nexxi-crm-card">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
					<div>
						<h2 style="margin:0;font-size:16.5px;color:#031444;">👑 3. Cài đặt Các Hạng Thành Viên, Mức Thăng Hạng &amp; Ưu Đãi Tương Ứng</h2>
						<p style="margin:3px 0 0;font-size:12.5px;color:#64748b;">Chỉnh sửa mức chi tiêu/điểm thăng hạng và ưu đãi riêng của từng hạng, hoặc bấm "+ Thêm hạng mới".</p>
					</div>
					<button type="button" class="nexxi-crm-btn nexxi-crm-btn--ghost" onclick="nexxiAddTierRow()">+ Thêm hạng thành viên mới</button>
				</div>

				<div style="overflow-x:auto;">
					<table class="nexxi-crm-table" id="nexxi-tiers-config-table">
						<thead>
							<tr>
								<th>Biểu tượng</th>
								<th>Tên Hạng Thành Viên</th>
								<th>Mức thăng hạng (Chi tiêu VNĐ)</th>
								<th>Mức thăng hạng (Điểm)</th>
								<th>Ưu đãi giảm trên Bill (%)</th>
								<th>Hệ số nhân điểm</th>
								<th>Quà sinh nhật bé (VNĐ)</th>
								<th>Mô tả đặc quyền hiển thị cho khách</th>
								<th>Màu chữ / nền</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tiers as $slug => $t ) : ?>
								<tr>
									<td>
										<input type="hidden" name="t_slug[]" value="<?php echo esc_attr( $slug ); ?>">
										<input type="text" name="t_icon[]" value="<?php echo esc_attr( $t['icon'] ?? '👑' ); ?>" class="nexxi-crm-input" style="width:52px;text-align:center;">
									</td>
									<td><input type="text" name="t_name[]" value="<?php echo esc_attr( $t['name'] ); ?>" class="nexxi-crm-input" style="width:170px;font-weight:700;"></td>
									<td><input type="number" name="t_spent[]" value="<?php echo esc_attr( (int) $t['min_spent'] ); ?>" min="0" step="any" class="nexxi-crm-input" style="width:135px;"></td>
									<td><input type="number" name="t_points[]" value="<?php echo (int) $t['min_points']; ?>" min="0" step="any" class="nexxi-crm-input" style="width:95px;"></td>
									<td><input type="number" name="t_disc[]" value="<?php echo esc_attr( $t['discount_pct'] ); ?>" min="0" max="90" step="any" class="nexxi-crm-input" style="width:90px;"></td>
									<td><input type="number" name="t_mult[]" value="<?php echo esc_attr( $t['point_mult'] ); ?>" min="1" max="10" step="any" class="nexxi-crm-input" style="width:80px;"></td>
									<td><input type="number" name="t_gift[]" value="<?php echo (int) $t['birthday_gift']; ?>" min="0" step="any" class="nexxi-crm-input" style="width:115px;"></td>
									<td><input type="text" name="t_perks[]" value="<?php echo esc_attr( $t['perks'] ); ?>" class="nexxi-crm-input" style="width:250px;"></td>
									<td style="white-space:nowrap;">
										<input type="color" name="t_color[]" value="<?php echo esc_attr( $t['color'] ?? '#031444' ); ?>" title="Màu chữ">
										<input type="color" name="t_bg[]" value="<?php echo esc_attr( $t['bg_color'] ?? '#f1f5f9' ); ?>" title="Màu nền">
									</td>
									<td>
										<button type="button" onclick="if(document.querySelectorAll('#nexxi-tiers-config-table tbody tr').length>1)this.closest('tr').remove();" class="nexxi-crm-btn nexxi-crm-btn--danger" style="padding:4px 8px;">✕</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
					<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--primary">💾 Lưu toàn bộ cài đặt Hạng &amp; Điểm thưởng</button>
				</div>
			</div>
		</form>

		<script>
		function nexxiAddTierRow() {
			var tb = document.querySelector('#nexxi-tiers-config-table tbody');
			if (!tb) return;
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="hidden" name="t_slug[]" value=""><input type="text" name="t_icon[]" value="👑" class="nexxi-crm-input" style="width:52px;text-align:center;"></td>' +
				'<td><input type="text" name="t_name[]" value="Hạng VIP Mới" class="nexxi-crm-input" style="width:170px;font-weight:700;"></td>' +
				'<td><input type="number" name="t_spent[]" value="20000000" min="0" step="any" class="nexxi-crm-input" style="width:135px;"></td>' +
				'<td><input type="number" name="t_points[]" value="2000" min="0" step="any" class="nexxi-crm-input" style="width:95px;"></td>' +
				'<td><input type="number" name="t_disc[]" value="12" min="0" max="90" step="any" class="nexxi-crm-input" style="width:90px;"></td>' +
				'<td><input type="number" name="t_mult[]" value="2.5" min="1" max="10" step="any" class="nexxi-crm-input" style="width:80px;"></td>' +
				'<td><input type="number" name="t_gift[]" value="300000" min="0" step="any" class="nexxi-crm-input" style="width:115px;"></td>' +
				'<td><input type="text" name="t_perks[]" value="Ưu đãi đặc quyền VIP" class="nexxi-crm-input" style="width:250px;"></td>' +
				'<td style="white-space:nowrap;"><input type="color" name="t_color[]" value="#7c2d12"><input type="color" name="t_bg[]" value="#ffedd5"></td>' +
				'<td><button type="button" onclick="this.closest(\'tr\').remove()" class="nexxi-crm-btn nexxi-crm-btn--danger" style="padding:4px 8px;">✕</button></td>';
			tb.appendChild(tr);
		}
		</script>

		<!-- Card 4: Quản lý Hạng Thành Viên Theo Tài Khoản -->
		<div class="nexxi-crm-card">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0;font-size:16.5px;color:#031444;">👥 4. Quản Lý Hạng Thành Viên Theo Tài Khoản Khách Hàng</h2>
					<p style="margin:3px 0 0;font-size:12.5px;color:#64748b;">Đổi hạng trực tiếp, chỉnh sửa điểm hoặc khóa/mở khóa hạng cho từng tài khoản khách hàng ngay trên bảng.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-member-tiers' ) ); ?>" style="margin:0;">
					<?php wp_nonce_field( 'nexxi_crm_tiers_nonce', 'nexxi_tiers_nonce' ); ?>
					<input type="hidden" name="nexxi_crm_tiers_action" value="recalc_all_tiers">
					<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--navy">🔄 Đồng bộ &amp; Xét hạng toàn bộ tài khoản ngay</button>
				</form>
			</div>

			<div style="overflow-x:auto;">
				<table class="nexxi-crm-table">
					<thead>
						<tr>
							<th>Tài khoản khách hàng</th>
							<th>Tổng chi tiêu</th>
							<th>Điểm hiện tại</th>
							<th>Hạng hiện tại &amp; Ưu đãi</th>
							<th>Thời hạn duy trì / Tụt hạng</th>
							<th>Thiết lập Hạng &amp; Chế độ</th>
							<th style="text-align:right;">Lưu nhanh</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users as $u ) :
							$uid   = (int) $u->ID;
							$stats = nexxi_kids_get_customer_order_stats( $uid );
							$pts   = function_exists( 'nexxi_kids_get_user_points' ) ? (int) nexxi_kids_get_user_points( $uid ) : (int) get_user_meta( $uid, 'nexxi_member_points', true );
							$ti    = nexxi_kids_get_user_tier_info( $uid );
							$tier  = $ti['tier'];
							$name  = trim( $u->first_name . ' ' . $u->last_name );
							if ( '' === $name ) {
								$name = $u->display_name;
							}
							$phone = (string) get_user_meta( $uid, 'billing_phone', true );
							$fid   = 'nx-tier-row-' . $uid;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $name ); ?></strong> <span style="color:#94a3b8;">#<?php echo (int) $uid; ?></span>
									<div style="font-size:11.5px;color:#64748b;"><?php echo esc_html( $u->user_email ); ?><?php echo $phone ? ' · ' . esc_html( $phone ) : ''; ?></div>
								</td>
								<td><strong style="color:#0b6b2f;"><?php echo esc_html( nexxi_kids_money( $stats['total_spent'] ) ); ?></strong> (<?php echo (int) $stats['order_count']; ?> đơn)</td>
								<td>
									<input type="number" form="<?php echo esc_attr( $fid ); ?>" name="account_points" value="<?php echo (int) $pts; ?>" min="0" class="nexxi-crm-input" style="width:95px;font-weight:700;color:#fd6a49;">
								</td>
								<td>
									<span class="nexxi-crm-badge" style="background:<?php echo esc_attr( $tier['bg_color'] ?? '#f1f5f9' ); ?>;color:<?php echo esc_attr( $tier['color'] ?? '#0f172a' ); ?>;">
										<?php echo esc_html( ( $tier['icon'] ?? '👑' ) . ' ' . $tier['name'] ); ?>
									</span>
									<div style="font-size:11px;color:#475569;margin-top:3px;">
										Giảm bill: <strong>-<?php echo esc_html( $tier['discount_pct'] ); ?>%</strong> · Nhân điểm: <strong>x<?php echo esc_html( $tier['point_mult'] ); ?></strong>
									</div>
								</td>
								<td>
									<div style="font-size:12px;font-weight:600;color:<?php echo $ti['is_downgraded'] ? '#b91c1c' : '#334155'; ?>;">
										<?php echo esc_html( $ti['expires_label'] ); ?>
									</div>
								</td>
								<td>
									<div style="display:flex;gap:6px;align-items:center;">
										<select form="<?php echo esc_attr( $fid ); ?>" name="account_tier" class="nexxi-crm-select">
											<?php foreach ( $tiers as $t_slug => $t_info ) : ?>
												<option value="<?php echo esc_attr( $t_slug ); ?>" <?php selected( $ti['tier_slug'], $t_slug ); ?>>
													<?php echo esc_html( ( $t_info['icon'] ?? '👑' ) . ' ' . $t_info['name'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<select form="<?php echo esc_attr( $fid ); ?>" name="account_mode" class="nexxi-crm-select">
											<option value="auto" <?php selected( ! $ti['is_manual'] ); ?>>🔄 Tự động</option>
											<option value="manual" <?php selected( $ti['is_manual'] ); ?>>🔒 Cố định hạng</option>
										</select>
									</div>
								</td>
								<td style="text-align:right;">
									<form id="<?php echo esc_attr( $fid ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-member-tiers' ) ); ?>" style="margin:0;">
										<?php wp_nonce_field( 'nexxi_crm_tiers_nonce', 'nexxi_tiers_nonce' ); ?>
										<input type="hidden" name="nexxi_crm_tiers_action" value="update_account_tier_row">
										<input type="hidden" name="account_uid" value="<?php echo (int) $uid; ?>">
										<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--primary" style="padding:6px 12px;font-size:12px;">Lưu</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<?php
}

/* ==========================================================================
 * 6. SUBMENU 3: QUẢN LÝ ĐƠN HÀNG (LIST, STATUS SWITCH, BULK ACTIONS, CSV)
 * ========================================================================== */

function nexxi_kids_handle_order_manager_post() {
	if ( empty( $_POST['nexxi_crm_order_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'nexxi_crm_order_nonce', 'nexxi_order_nonce' );

	$action = sanitize_key( wp_unslash( $_POST['nexxi_crm_order_action'] ) );

	// Single order quick status change
	if ( 'single_status' === $action ) {
		$oid    = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
		$order  = $oid ? wc_get_order( $oid ) : null;
		if ( $order instanceof WC_Order && '' !== $status ) {
			$clean_st = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			$order->update_status( $clean_st, 'Cập nhật nhanh trạng thái từ Quản lý đơn hàng NEXXI.' );
			add_settings_error( 'nexxi_orders', 'single_st_ok', 'Đã chuyển trạng thái đơn hàng #' . $order->get_order_number() . ' sang "' . wc_get_order_status_name( $clean_st ) . '"!', 'updated' );
		}
	} elseif ( 'bulk_orders' === $action ) {
		$oids = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['order_ids'] ) ) : array();
		$op   = isset( $_POST['bulk_order_op'] ) ? sanitize_key( wp_unslash( $_POST['bulk_order_op'] ) ) : '';

		if ( empty( $oids ) || '' === $op ) {
			add_settings_error( 'nexxi_orders', 'bulk_empty', 'Vui lòng tích chọn ít nhất 1 đơn hàng và chọn thao tác cần thực hiện.', 'error' );
			return;
		}

		if ( 'export_csv' === $op ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="nexxi-orders-' . wp_date( 'Ymd-His' ) . '.csv"' );
			echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'Mã đơn', 'Ngày đặt', 'Khách hàng', 'Số điện thoại', 'Email', 'Địa chỉ giao hàng', 'Sản phẩm', 'Tổng tiền (VNĐ)', 'Trạng thái', 'Thanh toán' ) );
			foreach ( $oids as $oid ) {
				$ord = wc_get_order( $oid );
				if ( ! $ord instanceof WC_Order ) {
					continue;
				}
				$items_str = array();
				foreach ( $ord->get_items() as $item ) {
					$items_str[] = $item->get_name() . ' x' . $item->get_quantity();
				}
				$addr = implode( ', ', array_filter( array( $ord->get_billing_address_1(), $ord->get_billing_city() ) ) );
				fputcsv(
					$out,
					array(
						'#' . $ord->get_order_number(),
						$ord->get_date_created() ? $ord->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '',
						trim( $ord->get_billing_first_name() . ' ' . $ord->get_billing_last_name() ),
						$ord->get_billing_phone(),
						$ord->get_billing_email(),
						$addr,
						implode( ' | ', $items_str ),
						(float) $ord->get_total(),
						wc_get_order_status_name( $ord->get_status() ),
						$ord->get_payment_method_title(),
					)
				);
			}
			fclose( $out );
			exit;
		}

		$count = 0;
		foreach ( $oids as $oid ) {
			$ord = wc_get_order( $oid );
			if ( ! $ord instanceof WC_Order ) {
				continue;
			}
			if ( 0 === strpos( $op, 'status_' ) ) {
				$new_st = substr( $op, 7 );
				$new_st = 0 === strpos( $new_st, 'wc-' ) ? substr( $new_st, 3 ) : $new_st;
				$ord->update_status( $new_st, 'Thao tác hàng loạt từ Quản lý đơn hàng NEXXI.' );
				$count++;
			} elseif ( 'award_points' === $op && function_exists( 'nexxi_kids_award_order_points' ) ) {
				$cid = (int) $ord->get_customer_id();
				if ( $cid > 0 ) {
					nexxi_kids_award_order_points( $ord, $cid );
					$count++;
				}
			} elseif ( 'trash' === $op ) {
				$ord->delete( false );
				$count++;
			}
		}
		add_settings_error( 'nexxi_orders', 'bulk_ok', 'Đã thực hiện thao tác hàng loạt cho ' . $count . ' đơn hàng!', 'updated' );
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_order_manager_post' );

function nexxi_kids_order_manager_admin_page() {
	$statuses   = wc_get_order_statuses();
	$f_status   = isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$s_order    = isset( $_GET['s_order'] ) ? sanitize_text_field( wp_unslash( $_GET['s_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cust_id    = isset( $_GET['cust_id'] ) ? absint( $_GET['cust_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Status counts
	$status_counts = array( 'all' => 0 );
	foreach ( array_keys( $statuses ) as $st_key ) {
		$short = 0 === strpos( $st_key, 'wc-' ) ? substr( $st_key, 3 ) : $st_key;
		$cnt   = function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( $short ) : 0;
		$status_counts[ $st_key ] = $cnt;
		$status_counts['all']    += $cnt;
	}

	$q_args = array(
		'limit'   => 150,
		'orderby' => 'date',
		'order'   => 'DESC',
		'type'    => 'shop_order',
	);
	if ( '' !== $f_status && isset( $statuses[ $f_status ] ) ) {
		$q_args['status'] = array( $f_status );
	} else {
		$q_args['status'] = array_keys( $statuses );
	}
	if ( $cust_id > 0 ) {
		$q_args['customer_id'] = $cust_id;
	}
	if ( '' !== $date_from || '' !== $date_to ) {
		$from_str = '' !== $date_from ? $date_from : '2020-01-01';
		$to_str   = '' !== $date_to ? $date_to : wp_date( 'Y-m-d' );
		$q_args['date_created'] = $from_str . '...' . $to_str . ' 23:59:59';
	}

	$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( $q_args ) : array();

	if ( '' !== $s_order ) {
		$needle = strtolower( ltrim( trim( $s_order ), '#' ) );
		$orders = array_values(
			array_filter(
				$orders,
				static function ( $ord ) use ( $needle ) {
					if ( ! $ord instanceof WC_Order ) {
						return false;
					}
					$hay = strtolower(
						$ord->get_order_number() . ' ' .
						$ord->get_billing_first_name() . ' ' .
						$ord->get_billing_last_name() . ' ' .
						$ord->get_billing_phone() . ' ' .
						$ord->get_billing_email()
					);
					return false !== strpos( $hay, $needle );
				}
			)
		);
	}

	$badge_colors = array(
		'wc-completed'  => array( '#dcfce7', '#166534' ),
		'wc-processing' => array( '#dbeafe', '#1e40af' ),
		'wc-on-hold'    => array( '#fef3c7', '#92400e' ),
		'wc-pending'    => array( '#ffedd5', '#9a3412' ),
		'wc-cancelled'  => array( '#f1f5f9', '#475569' ),
		'wc-refunded'   => array( '#f3e8ff', '#6b21a8' ),
		'wc-failed'     => array( '#fee2e2', '#991b1b' ),
	);
	?>
	<div class="wrap nexxi-crm-wrap">
		<?php nexxi_kids_render_crm_top_nav( 'nexxi-order-manager' ); ?>
		<?php settings_errors( 'nexxi_orders' ); ?>

		<!-- Status Filter Pills -->
		<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager' ) ); ?>" class="nexxi-crm-btn <?php echo '' === $f_status ? 'nexxi-crm-btn--navy' : 'nexxi-crm-btn--ghost'; ?>">
				Tất cả đơn (<?php echo (int) $status_counts['all']; ?>)
			</a>
			<?php foreach ( $statuses as $st_key => $st_label ) :
				$cnt = isset( $status_counts[ $st_key ] ) ? (int) $status_counts[ $st_key ] : 0;
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager&f_status=' . $st_key ) ); ?>" class="nexxi-crm-btn <?php echo $f_status === $st_key ? 'nexxi-crm-btn--primary' : 'nexxi-crm-btn--ghost'; ?>">
					<?php echo esc_html( $st_label ); ?> (<?php echo (int) $cnt; ?>)
				</a>
			<?php endforeach; ?>
		</div>

		<div class="nexxi-crm-card">
			<!-- Search & Filter Form -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;">
				<input type="hidden" name="page" value="nexxi-order-manager">
				<?php if ( '' !== $f_status ) : ?>
					<input type="hidden" name="f_status" value="<?php echo esc_attr( $f_status ); ?>">
				<?php endif; ?>
				<input type="search" name="s_order" value="<?php echo esc_attr( $s_order ); ?>" class="nexxi-crm-input" style="min-width:250px;" placeholder="🔍 Tìm mã đơn #ID, tên khách, SĐT, email...">
				<label style="font-size:12px;font-weight:700;color:#475569;">Từ ngày:</label>
				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" class="nexxi-crm-input">
				<label style="font-size:12px;font-weight:700;color:#475569;">Đến ngày:</label>
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" class="nexxi-crm-input">
				<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--navy">Lọc đơn hàng</button>
				<?php if ( '' !== $s_order || '' !== $f_status || $cust_id > 0 || '' !== $date_from || '' !== $date_to ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager' ) ); ?>" class="nexxi-crm-btn nexxi-crm-btn--ghost">Xóa lọc</a>
				<?php endif; ?>
			</form>

			<!-- Bulk Actions Form + Orders Table -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager' ) ); ?>">
				<?php wp_nonce_field( 'nexxi_crm_order_nonce', 'nexxi_order_nonce' ); ?>
				<input type="hidden" name="nexxi_crm_order_action" value="bulk_orders">

				<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#f8fafc;border-radius:10px;margin-bottom:12px;border:1px solid #e2e8f0;">
					<strong style="font-size:12.5px;color:#031444;">⚡ Thao tác hàng loạt đơn hàng:</strong>
					<select name="bulk_order_op" class="nexxi-crm-select">
						<option value="">-- Chọn thao tác hàng loạt --</option>
						<?php foreach ( $statuses as $st_key => $st_label ) : ?>
							<option value="<?php echo esc_attr( 'status_' . $st_key ); ?>">Đổi trạng thái &rarr; <?php echo esc_html( $st_label ); ?></option>
						<?php endforeach; ?>
						<option value="award_points">💎 Cộng điểm NEXXI Club cho các đơn đã chọn</option>
						<option value="export_csv">📥 Xuất danh sách đơn đã chọn ra file Excel (CSV)</option>
						<option value="trash">🗑️ Chuyển các đơn đã chọn vào thùng rác</option>
					</select>
					<button type="submit" class="nexxi-crm-btn nexxi-crm-btn--primary">Áp dụng hàng loạt</button>
					<span style="margin-left:auto;font-size:12.5px;color:#64748b;">Hiển thị <strong><?php echo count( $orders ); ?></strong> đơn hàng</span>
				</div>

				<div style="overflow-x:auto;">
					<table class="nexxi-crm-table">
						<thead>
							<tr>
								<th style="width:32px;"><input type="checkbox" onclick="document.querySelectorAll('.nx-ord-cb').forEach(c=>c.checked=this.checked)"></th>
								<th>Mã đơn &amp; Thời gian</th>
								<th>Khách hàng &amp; Địa chỉ nhận</th>
								<th>Sản phẩm đặt mua</th>
								<th>Tổng thanh toán</th>
								<th>Điểm NEXXI</th>
								<th>Quản lý trạng thái đơn</th>
								<th style="text-align:right;">Chi tiết</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $orders ) ) : ?>
								<tr><td colspan="8" style="text-align:center;padding:30px;color:#64748b;">Chưa có đơn hàng nào khớp bộ lọc.</td></tr>
							<?php else : ?>
								<?php foreach ( $orders as $ord ) :
									$oid     = $ord->get_id();
									$st_full = 'wc-' . $ord->get_status();
									$c_pair  = isset( $badge_colors[ $st_full ] ) ? $badge_colors[ $st_full ] : array( '#f1f5f9', '#334155' );
									$c_name  = trim( $ord->get_billing_first_name() . ' ' . $ord->get_billing_last_name() );
									$phone   = $ord->get_billing_phone();
									$addr    = implode( ', ', array_filter( array( $ord->get_billing_address_1(), $ord->get_billing_city() ) ) );
									$pts     = (int) $ord->get_meta( '_nexxi_points_earned' );
									if ( $pts <= 0 && function_exists( 'nexxi_kids_calculate_order_points' ) ) {
										$pts = nexxi_kids_calculate_order_points( $ord );
									}
									$combo_sum = (string) $ord->get_meta( '_nexxi_combo_summary' );
									$edit_url  = $ord->get_edit_order_url();
									?>
									<tr>
										<td><input type="checkbox" class="nx-ord-cb" name="order_ids[]" value="<?php echo (int) $oid; ?>"></td>
										<td>
											<a href="<?php echo esc_url( $edit_url ); ?>" style="font-weight:800;font-size:14px;color:#031444;text-decoration:none;">
												#<?php echo esc_html( $ord->get_order_number() ); ?>
											</a>
											<div style="font-size:11.5px;color:#64748b;margin-top:2px;">
												🕒 <?php echo esc_html( $ord->get_date_created() ? $ord->get_date_created()->date_i18n( 'd/m/Y H:i' ) : '' ); ?>
											</div>
											<span class="nexxi-crm-badge" style="background:<?php echo esc_attr( $c_pair[0] ); ?>;color:<?php echo esc_attr( $c_pair[1] ); ?>;margin-top:4px;">
												<?php echo esc_html( wc_get_order_status_name( $ord->get_status() ) ); ?>
											</span>
										</td>
										<td>
											<div style="font-weight:700;color:#031444;">
												<?php echo esc_html( $c_name ? $c_name : 'Khách lẻ' ); ?>
												<?php if ( $phone ) : ?>
													· <strong style="color:#fd6a49;"><?php echo esc_html( $phone ); ?></strong>
												<?php endif; ?>
											</div>
											<?php if ( $addr ) : ?>
												<div style="font-size:11.5px;color:#64748b;max-width:260px;"><?php echo esc_html( $addr ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<div style="display:flex;flex-direction:column;gap:3px;max-width:300px;">
												<?php foreach ( $ord->get_items() as $item ) : ?>
													<div style="font-size:12px;color:#1e293b;">
														• <?php echo esc_html( $item->get_name() ); ?> <strong>× <?php echo (int) $item->get_quantity(); ?></strong>
													</div>
												<?php endforeach; ?>
												<?php if ( '' !== $combo_sum ) : ?>
													<span style="display:inline-block;background:#fff0ed;color:#ae3115;border:1px solid #ffdad2;font-size:10.5px;font-weight:700;padding:1px 6px;border-radius:4px;align-self:flex-start;">
														🎁 <?php echo esc_html( $combo_sum ); ?>
													</span>
												<?php endif; ?>
											</div>
										</td>
										<td>
											<strong style="font-size:14px;color:#0b6b2f;"><?php echo wp_kses_post( $ord->get_formatted_order_total() ); ?></strong>
											<div style="font-size:11px;color:#64748b;"><?php echo esc_html( $ord->get_payment_method_title() ); ?></div>
										</td>
										<td>
											<span class="nexxi-crm-badge" style="background:#fff0ed;color:#fd6a49;">+<?php echo (int) $pts; ?> điểm</span>
										</td>
										<td>
											<div style="display:flex;gap:5px;align-items:center;">
												<select id="nx-st-sel-<?php echo (int) $oid; ?>" class="nexxi-crm-select" style="padding:5px 8px;font-size:12px;">
													<?php foreach ( $statuses as $st_k => $st_lbl ) : ?>
														<option value="<?php echo esc_attr( $st_k ); ?>" <?php selected( $st_full, $st_k ); ?>>
															<?php echo esc_html( $st_lbl ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<button type="button" onclick="nexxiQuickSwitchOrderStatus(<?php echo (int) $oid; ?>)" class="nexxi-crm-btn nexxi-crm-btn--ghost" style="padding:5px 10px;font-size:12px;">Lưu</button>
											</div>
										</td>
										<td style="text-align:right;white-space:nowrap;">
											<a href="<?php echo esc_url( $edit_url ); ?>" class="nexxi-crm-btn nexxi-crm-btn--navy" style="padding:5px 11px;font-size:12px;">Xem / Sửa đơn</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</form>

			<form id="nx-single-order-st-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-order-manager' ) ); ?>" style="display:none;">
				<?php wp_nonce_field( 'nexxi_crm_order_nonce', 'nexxi_order_nonce' ); ?>
				<input type="hidden" name="nexxi_crm_order_action" value="single_status">
				<input type="hidden" name="order_id" value="0">
				<input type="hidden" name="new_status" value="">
			</form>
			<script>
			function nexxiQuickSwitchOrderStatus(orderId) {
				var sel = document.getElementById('nx-st-sel-' + orderId);
				var form = document.getElementById('nx-single-order-st-form');
				if (!sel || !form) return;
				form.order_id.value = orderId;
				form.new_status.value = sel.value;
				form.submit();
			}
			</script>
		</div>
	</div>
	<?php
}

/* ==========================================================================
 * 7. CUSTOMER ACCOUNT DASHBOARD: BABY BIRTHDAY & MEMBERSHIP TIER CARD
 * ========================================================================== */

/**
 * Handle saving Baby Birthday & Baby Name from customer's My Account dashboard.
 */
add_action(
	'template_redirect',
	static function () {
		if ( ! is_user_logged_in() || empty( $_POST['nexxi_save_baby_profile'] ) ) {
			return;
		}
		if ( ! isset( $_POST['nexxi_baby_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexxi_baby_nonce'] ) ), 'nexxi_save_baby' ) ) {
			return;
		}
		$uid   = get_current_user_id();
		$bname = isset( $_POST['baby_name'] ) ? sanitize_text_field( wp_unslash( $_POST['baby_name'] ) ) : '';
		$bbday = isset( $_POST['baby_birthday'] ) ? sanitize_text_field( wp_unslash( $_POST['baby_birthday'] ) ) : '';
		$bgen  = isset( $_POST['baby_gender'] ) ? sanitize_key( wp_unslash( $_POST['baby_gender'] ) ) : '';

		update_user_meta( $uid, 'nexxi_baby_name', $bname );
		update_user_meta( $uid, 'nexxi_baby_birthday', $bbday );
		update_user_meta( $uid, 'nexxi_baby_gender', $bgen );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Đã cập nhật thông tin & ngày sinh nhật của bé để nhận ưu đãi NEXXI Club!', 'success' );
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
);
