<?php
/**
 * NEXXI Kids — Meta Pixel (ID: 1019758880687993) & Sự kiện thương mại điện tử WooCommerce.
 *
 * 1. Gắn mã cơ sở Meta Pixel (PageView + <noscript>) vào giữa <head>...</head> trên mọi trang frontend.
 * 2. Tự động gửi các sự kiện chuẩn thương mại điện tử cho Meta Ads:
 *    - ViewContent      (xem chi tiết sản phẩm)
 *    - AddToCart        (thêm vào giỏ hàng qua AJAX hoặc form)
 *    - InitiateCheckout (vào trang thanh toán)
 *    - Purchase         (hoàn tất đặt hàng — chống trùng lặp bằng order meta + eventID)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NEXXI_META_PIXEL_ID' ) ) {
	define( 'NEXXI_META_PIXEL_ID', '1019758880687993' );
}

/** Kiểm tra xem có plugin Pixel bên thứ 3 nào đang tự bắn sự kiện TMĐT hay không để tránh nhân đôi. */
function nexxi_meta_pixel_external_plugin_active() {
	return class_exists( 'WC_Facebookcommerce' ) || defined( 'PYS_VERSION' ) || defined( 'PYS_FREE_VERSION' );
}

/** Lấy mã định danh nội dung chuẩn (ưu tiên Mã SKU cha, dự phòng ID sản phẩm). */
function nexxi_meta_pixel_content_id( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$target = $product;
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent ) {
			$target = $parent;
		}
	}
	$sku = trim( (string) $target->get_sku() );
	if ( '' === $sku && function_exists( 'nexxi_kids_effective_sku' ) ) {
		$sku = trim( (string) nexxi_kids_effective_sku( $target ) );
	}
	return '' !== $sku ? $sku : (string) $target->get_id();
}

/** Lấy giá bán thực tế sau giảm (Giảm Giá SP + Flash Sale) của sản phẩm. */
function nexxi_meta_pixel_effective_price( WC_Product $product ) {
	$target = $product;
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent ) {
			$target = $parent;
		}
	}
	if ( function_exists( 'nexxi_kids_get_program_sale' ) ) {
		$prog = nexxi_kids_get_program_sale( $target, 'combined' );
		if ( ! empty( $prog['sale'] ) && (float) $prog['sale'] > 0 ) {
			return (float) round( (float) $prog['sale'] );
		}
	}
	return (float) round( (float) $product->get_price() );
}

/**
 * 1. Xuất mã cơ sở Meta Pixel ngay trước </head> trên toàn bộ trang frontend.
 */
function nexxi_meta_pixel_print_head() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || defined( 'REST_REQUEST' ) ) {
		return;
	}
	if ( defined( 'NEXXI_META_PIXEL_PRINTED' ) ) {
		return;
	}
	define( 'NEXXI_META_PIXEL_PRINTED', true );
	$pixel_id = esc_js( NEXXI_META_PIXEL_ID );
	$tr_url   = 'https://www.facebook.com/tr?id=' . rawurlencode( NEXXI_META_PIXEL_ID ) . '&ev=PageView&noscript=1';
	?>
<!-- Meta Pixel Code -->
<script data-no-optimize="1" data-no-defer="1">
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo $pixel_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="<?php echo esc_url( $tr_url ); ?>"
/></noscript>
<!-- End Meta Pixel Code -->
	<?php
}
add_action( 'wp_head', 'nexxi_meta_pixel_print_head', 99 );

/**
 * 2. Ghi nhận sự kiện AddToCart vào phiên làm việc để gửi qua AJAX fragment hoặc lần tải trang kế tiếp (Mua ngay / POST form).
 */
function nexxi_meta_pixel_capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ) {
	if ( nexxi_meta_pixel_external_plugin_active() || ! function_exists( 'WC' ) ) {
		return;
	}
	$target_id = $variation_id ? (int) $variation_id : (int) $product_id;
	$product   = wc_get_product( $target_id );
	if ( ! $product ) {
		return;
	}
	$qty   = max( 1, (int) $quantity );
	$unit  = nexxi_meta_pixel_effective_price( $product );
	$cid   = nexxi_meta_pixel_content_id( $product );
	$curr  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND';
	$event = array(
		'content_ids'  => array( $cid ),
		'content_name' => wp_strip_all_tags( $product->get_title() ),
		'content_type' => 'product',
		'value'        => (float) round( $unit * $qty ),
		'currency'     => $curr,
		'num_items'    => $qty,
	);
	$GLOBALS['nexxi_pending_fbq_atc'] = $event;
	if ( WC()->session ) {
		$queue   = WC()->session->get( 'nexxi_fbq_atc_queue', array() );
		$queue   = is_array( $queue ) ? $queue : array();
		$queue[] = $event;
		if ( count( $queue ) > 5 ) {
			$queue = array_slice( $queue, -5 );
		}
		WC()->session->set( 'nexxi_fbq_atc_queue', $queue );
	}
}
add_action( 'woocommerce_add_to_cart', 'nexxi_meta_pixel_capture_add_to_cart', 20, 4 );

/** Đính kèm payload AddToCart vào phản hồi AJAX add_to_cart (và xóa khỏi hàng đợi session để không bắn lại lần 2). */
function nexxi_meta_pixel_atc_fragment( $fragments ) {
	if ( ! empty( $GLOBALS['nexxi_pending_fbq_atc'] ) ) {
		$fragments['nexxi-fbq-atc'] = $GLOBALS['nexxi_pending_fbq_atc'];
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'nexxi_fbq_atc_queue', array() );
		}
	}
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'nexxi_meta_pixel_atc_fragment', 20 );

/**
 * 3. Xuất các sự kiện ViewContent, AddToCart (từ form POST), InitiateCheckout và Purchase ở chân trang.
 */
function nexxi_meta_pixel_print_ecommerce_events() {
	if ( is_admin() || nexxi_meta_pixel_external_plugin_active() || ! function_exists( 'WC' ) ) {
		return;
	}
	$calls = array();
	$curr  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND';

	// A. Hàng đợi AddToCart từ thao tác POST form (ví dụ nút Mua Ngay / thêm biến thể đặc biệt)
	if ( WC()->session ) {
		$queued = WC()->session->get( 'nexxi_fbq_atc_queue', array() );
		if ( ! empty( $queued ) && is_array( $queued ) ) {
			foreach ( $queued as $ev ) {
				if ( is_array( $ev ) ) {
					$calls[] = array( 'AddToCart', $ev, null );
				}
			}
			WC()->session->set( 'nexxi_fbq_atc_queue', array() );
		}
	}

	// B. ViewContent trên trang chi tiết sản phẩm
	if ( function_exists( 'is_product' ) && is_product() ) {
		$prod = function_exists( 'nexxi_kids_current_product' ) ? nexxi_kids_current_product() : wc_get_product( get_queried_object_id() );
		if ( $prod instanceof WC_Product ) {
			$calls[] = array(
				'ViewContent',
				array(
					'content_ids'  => array( nexxi_meta_pixel_content_id( $prod ) ),
					'content_name' => wp_strip_all_tags( $prod->get_name() ),
					'content_type' => 'product',
					'value'        => nexxi_meta_pixel_effective_price( $prod ),
					'currency'     => $curr,
				),
				null,
			);
		}
	}

	// C. Purchase trên trang hoàn tất đơn hàng (order-received) — chỉ bắn đúng 1 lần duy nhất cho mỗi đơn
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id && isset( $_GET['key'] ) && function_exists( 'wc_get_order_id_by_order_key' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wc_get_order_id_by_order_key( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order && ! $order->has_status( array( 'failed', 'cancelled' ) ) && '1' !== (string) $order->get_meta( '_nexxi_fbq_purchase_tracked', true ) ) {
			$cids      = array();
			$num_items = 0;
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$p = $item->get_product();
				if ( $p ) {
					$cids[] = nexxi_meta_pixel_content_id( $p );
				} else {
					$cids[] = (string) $item->get_product_id();
				}
				$num_items += max( 1, (int) $item->get_quantity() );
			}
			$calls[] = array(
				'Purchase',
				array(
					'content_ids'  => array_values( array_unique( array_filter( $cids ) ) ),
					'content_type' => 'product',
					'value'        => (float) round( (float) $order->get_total() ),
					'currency'     => $order->get_currency() ? $order->get_currency() : $curr,
					'num_items'    => $num_items,
				),
				array( 'eventID' => 'nexxi_order_' . $order->get_id() ),
			);
			$order->update_meta_data( '_nexxi_fbq_purchase_tracked', '1' );
			$order->save_meta_data();
		}
	} elseif ( function_exists( 'is_checkout' ) && is_checkout() && WC()->cart && ! WC()->cart->is_empty() ) {
		// D. InitiateCheckout trên trang thanh toán
		$cids      = array();
		$num_items = 0;
		foreach ( WC()->cart->get_cart() as $ci ) {
			$p = isset( $ci['data'] ) && $ci['data'] instanceof WC_Product ? $ci['data'] : null;
			if ( $p ) {
				$cids[] = nexxi_meta_pixel_content_id( $p );
			} elseif ( ! empty( $ci['product_id'] ) ) {
				$cids[] = (string) $ci['product_id'];
			}
			$num_items += max( 1, (int) ( $ci['quantity'] ?? 1 ) );
		}
		$val = (float) WC()->cart->get_total( 'edit' );
		if ( $val <= 0 ) {
			$val = (float) WC()->cart->get_subtotal();
		}
		$calls[] = array(
			'InitiateCheckout',
			array(
				'content_ids'  => array_values( array_unique( array_filter( $cids ) ) ),
				'content_type' => 'product',
				'value'        => (float) round( $val ),
				'currency'     => $curr,
				'num_items'    => $num_items,
			),
			null,
		);
	}

	if ( empty( $calls ) ) {
		return;
	}

	echo "<script data-no-optimize=\"1\" data-no-defer=\"1\">\n";
	echo "if (typeof window.fbq === 'function') {\n";
	foreach ( $calls as $c ) {
		$ev_name = wp_json_encode( $c[0] );
		$ev_data = wp_json_encode( $c[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $c[2] ) ) {
			$ev_opts = wp_json_encode( $c[2], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			echo "\twindow.fbq('track', {$ev_name}, {$ev_data}, {$ev_opts});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo "\twindow.fbq('track', {$ev_name}, {$ev_data});\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	echo "}\n</script>\n";
}
add_action( 'wp_footer', 'nexxi_meta_pixel_print_ecommerce_events', 40 );
