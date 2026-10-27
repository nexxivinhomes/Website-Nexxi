<?php
/**
 * NEXXI Zalo Bot Warehouse Order Notification System.
 *
 * Automatically sends detailed order picking slips (customer info, address, SKU,
 * color/size classification, quantity, total amount, and optional product photos)
 * via Zalo Bot API (bot-api.zapps.me / bot-api.zaloplatforms.com) and/or custom
 * Webhook whenever a new WooCommerce order is placed.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve Zalo Warehouse Notification settings.
 *
 * @return array{
 *   enabled: string,
 *   bot_token: string,
 *   chat_ids: string,
 *   send_photos: string,
 *   webhook_url: string,
 *   secret_token: string,
 *   bot_info: array
 * }
 */
function nexxi_kids_zalo_notify_settings() {
	$secret = trim( (string) get_option( 'nexxi_zalo_bot_secret_token', '' ) );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 24, false, false );
		update_option( 'nexxi_zalo_bot_secret_token', $secret, false );
	}
	$bot_info = get_option( 'nexxi_zalo_bot_info', array() );
	return array(
		'enabled'          => (string) get_option( 'nexxi_zalo_notify_enabled', '1' ),
		'bot_token'        => trim( (string) get_option( 'nexxi_zalo_bot_token', '' ) ),
		'chat_ids'         => trim( (string) get_option( 'nexxi_zalo_chat_ids', '' ) ),
		'send_photos'      => (string) get_option( 'nexxi_zalo_send_photos', '1' ),
		'webhook_url'      => trim( (string) get_option( 'nexxi_zalo_webhook_url', '' ) ),
		'webhook_auto_add' => (string) get_option( 'nexxi_zalo_webhook_auto_add', '0' ),
		'secret_token'     => $secret,
		'bot_info'         => is_array( $bot_info ) ? $bot_info : array(),
	);
}

/**
 * Parse the saved Chat IDs string into a deduplicated array of IDs.
 *
 * @param string|null $raw Raw comma/newline-separated chat IDs.
 * @return string[]
 */
function nexxi_kids_zalo_parse_chat_ids( $raw = null ) {
	if ( null === $raw ) {
		$cfg = nexxi_kids_zalo_notify_settings();
		$raw = $cfg['chat_ids'];
	}
	$parts = preg_split( '/[\s,;]+/', (string) $raw );
	$out   = array();
	foreach ( (array) $parts as $p ) {
		$p = trim( (string) $p );
		if ( '' !== $p && ! in_array( $p, $out, true ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

/**
 * Add a discovered Chat ID to the saved list and Subscriber log.
 *
 * @param string $chat_id      Zalo Chat ID.
 * @param string $display_name Sender or group display name.
 * @return bool True if newly added.
 */
function nexxi_kids_zalo_add_chat_id( $chat_id, $display_name = '' ) {
	$chat_id = trim( (string) $chat_id );
	if ( '' === $chat_id ) {
		return false;
	}

	// Save subscriber directory for friendly display in WP Admin.
	$subs = get_option( 'nexxi_zalo_known_chats', array() );
	$subs = is_array( $subs ) ? $subs : array();
	$subs[ $chat_id ] = array(
		'chat_id'    => $chat_id,
		'name'       => '' !== $display_name ? $display_name : ( isset( $subs[ $chat_id ]['name'] ) ? $subs[ $chat_id ]['name'] : 'Zalo #' . $chat_id ),
		'updated_at' => current_time( 'mysql' ),
	);
	update_option( 'nexxi_zalo_known_chats', $subs, false );

	$current = nexxi_kids_zalo_parse_chat_ids();
	if ( ! in_array( $chat_id, $current, true ) ) {
		$current[] = $chat_id;
		update_option( 'nexxi_zalo_chat_ids', implode( "\n", $current ) );
		return true;
	}
	return false;
}

/**
 * Call a Zalo Bot API method (`getMe`, `sendMessage`, `sendPhoto`, `getUpdates`, `setWebhook`, `deleteWebhook`).
 * Automatically tries `bot-api.zapps.me` and falls back to `bot-api.zaloplatforms.com`.
 *
 * @param string $method    API method name.
 * @param array  $payload   JSON body parameters.
 * @param string $bot_token Optional override token.
 * @param int    $timeout   HTTP timeout in seconds.
 * @return array|WP_Error Decoded JSON response or WP_Error.
 */
function nexxi_kids_zalo_bot_request( $method, array $payload = array(), $bot_token = '', $timeout = 12 ) {
	if ( '' === $bot_token ) {
		$cfg       = nexxi_kids_zalo_notify_settings();
		$bot_token = $cfg['bot_token'];
	}
	$bot_token = trim( (string) $bot_token );
	// Strip leading "bot" if user pasted "bot123456:abc" instead of "123456:abc".
	if ( 0 === stripos( $bot_token, 'bot' ) && false !== strpos( $bot_token, ':' ) ) {
		$bot_token = substr( $bot_token, 3 );
	}
	if ( '' === $bot_token ) {
		return new WP_Error( 'missing_token', 'Chưa nhập Zalo Bot Token.' );
	}

	$hosts = array(
		'https://bot-api.zapps.me',
		'https://bot-api.zaloplatforms.com',
	);

	$last_err = null;
	foreach ( $hosts as $host ) {
		$url = $host . '/bot' . $bot_token . '/' . ltrim( $method, '/' );
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => max( 5, (int) $timeout ),
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => ! empty( $payload ) ? wp_json_encode( $payload ) : '{}',
			)
		);

		if ( is_wp_error( $res ) ) {
			$last_err = $res;
			continue;
		}

		$raw  = wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$last_err = new WP_Error( 'invalid_json', 'Phản hồi không hợp lệ từ Zalo Bot API (' . wp_remote_retrieve_response_code( $res ) . ').' );
	}

	return $last_err ? $last_err : new WP_Error( 'request_failed', 'Không thể kết nối tới Zalo Bot API.' );
}

/**
 * Extract chat_id and sender/chat display name from a single Zalo Bot update object.
 *
 * @param array $update Update item from `getUpdates` or Webhook.
 * @return array{chat_id:string,name:string,text:string}|null
 */
function nexxi_kids_zalo_extract_chat_from_update( array $update ) {
	$msg = null;
	if ( isset( $update['message'] ) && is_array( $update['message'] ) ) {
		$msg = $update['message'];
	} elseif ( isset( $update['result']['message'] ) && is_array( $update['result']['message'] ) ) {
		$msg = $update['result']['message'];
	} elseif ( isset( $update['chat'] ) || isset( $update['from'] ) ) {
		$msg = $update;
	}
	if ( ! is_array( $msg ) ) {
		return null;
	}

	$chat_id = '';
	if ( ! empty( $msg['chat']['id'] ) ) {
		$chat_id = (string) $msg['chat']['id'];
	} elseif ( ! empty( $msg['from']['id'] ) ) {
		$chat_id = (string) $msg['from']['id'];
	} elseif ( ! empty( $msg['sender']['id'] ) ) {
		$chat_id = (string) $msg['sender']['id'];
	}
	if ( '' === $chat_id ) {
		return null;
	}

	$name = '';
	if ( ! empty( $msg['from']['display_name'] ) ) {
		$name = (string) $msg['from']['display_name'];
	} elseif ( ! empty( $msg['from']['name'] ) ) {
		$name = (string) $msg['from']['name'];
	} elseif ( ! empty( $msg['chat']['title'] ) ) {
		$name = (string) $msg['chat']['title'];
	} elseif ( ! empty( $msg['from']['first_name'] ) ) {
		$name = trim( (string) $msg['from']['first_name'] . ' ' . (string) ( $msg['from']['last_name'] ?? '' ) );
	}

	$text = isset( $msg['text'] ) ? trim( (string) $msg['text'] ) : '';

	return array(
		'chat_id' => $chat_id,
		'name'    => $name,
		'text'    => $text,
	);
}

/**
 * Register REST API Webhook endpoint (`/wp-json/nexxi/v1/zalo-bot-webhook`)
 * so when anyone messages the Zalo Bot (e.g. `/id`, `/start`, `kho`), the website
 * automatically captures their `chat_id` and replies on Zalo!
 */
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'nexxi/v1',
			'/zalo-bot-webhook',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$body = $request->get_json_params();
					if ( ! is_array( $body ) || empty( $body ) ) {
						$raw  = $request->get_body();
						$body = json_decode( (string) $raw, true );
					}
					if ( ! is_array( $body ) ) {
						return new WP_REST_Response( array( 'ok' => true ), 200 );
					}

					$cfg = nexxi_kids_zalo_notify_settings();
					if ( '1' !== $cfg['webhook_auto_add'] ) {
						return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'webhook_auto_add_disabled' ), 200 );
					}

					$extracted = nexxi_kids_zalo_extract_chat_from_update( $body );
					if ( $extracted && '' !== $extracted['chat_id'] ) {
						$chat_id  = $extracted['chat_id'];
						$name     = $extracted['name'];
						$is_added = nexxi_kids_zalo_add_chat_id( $chat_id, $name );

						$reply = "✅ NEXXI Kids Kho: Đã ghi nhận kết nối thông báo đơn hàng mới!\n"
							. "• Tên: " . ( '' !== $name ? $name : 'Thành viên Kho' ) . "\n"
							. "• Chat ID: " . $chat_id . "\n"
							. ( $is_added ? "🎉 Đã tự động thêm vào danh sách nhận thông báo đơn hàng mới trên Website!" : "✔️ Chat ID này đã nằm trong danh sách nhận thông báo đơn hàng của Kho." );

						nexxi_kids_zalo_bot_request(
							'sendMessage',
							array(
								'chat_id' => $chat_id,
								'text'    => $reply,
							)
						);
					}

					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);
	}
);

/**
 * Format a WooCommerce Order into a clear, warehouse-friendly Zalo message + list of product photos.
 *
 * @param WC_Order $order WooCommerce Order.
 * @return array{text:string,photos:array<int,array{photo:string,caption:string}>,payload:array}
 */
function nexxi_kids_format_zalo_order_message( WC_Order $order ) {
	$order_number = $order->get_order_number();
	$created      = $order->get_date_created();
	$time_str     = $created ? $created->setTimezone( wp_timezone() )->format( 'H:i - d/m/Y' ) : wp_date( 'H:i - d/m/Y' );

	$recipient = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	if ( '' === $recipient ) {
		$recipient = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
	}
	$phone = trim( (string) $order->get_billing_phone() );
	$email = trim( (string) $order->get_billing_email() );

	$addr1 = trim( (string) $order->get_billing_address_1() );
	$ward  = trim( (string) $order->get_meta( '_billing_ward' ) );
	$city  = trim( (string) $order->get_billing_city() );

	$addr_parts = array_filter( array( $addr1, $ward, $city ) );
	// Avoid duplicating ward if already appended to addr1.
	if ( '' !== $ward && false !== stripos( $addr1, $ward ) ) {
		$addr_parts = array_filter( array( $addr1, $city ) );
	}
	$full_address = implode( ', ', $addr_parts );

	$payment_title = $order->get_payment_method_title();
	$customer_note = trim( (string) $order->get_customer_note() );
	$total_str     = number_format( (float) $order->get_total(), 0, ',', '.' ) . 'đ';
	$shipping_str  = (float) $order->get_shipping_total() > 0 ? number_format( (float) $order->get_shipping_total(), 0, ',', '.' ) . 'đ' : 'Miễn phí';
	$discount_str  = (float) $order->get_discount_total() > 0 ? '-' . number_format( (float) $order->get_discount_total(), 0, ',', '.' ) . 'đ' : '';

	$items_lines = array();
	$photos      = array();
	$items_data  = array();
	$total_qty   = 0;
	$idx         = 1;

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$product   = $item->get_product();
		$qty       = (int) $item->get_quantity();
		$total_qty += $qty;
		$sku       = $product ? trim( (string) $product->get_sku() ) : '';
		$name      = $item->get_name();
		$line_tot  = number_format( (float) $item->get_total(), 0, ',', '.' ) . 'đ';

		// Gather variation attributes (Color, Size, etc.).
		$meta_parts = array();
		$meta_data  = $item->get_formatted_meta_data( '_', true );
		foreach ( $meta_data as $meta ) {
			$k = wp_strip_all_tags( (string) $meta->display_key );
			$v = trim( wp_strip_all_tags( (string) $meta->display_value ) );
			if ( '' !== $k && '' !== $v ) {
				$meta_parts[] = $k . ': ' . $v;
			}
		}
		if ( empty( $meta_parts ) && $product && $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $attr_k => $attr_v ) {
				if ( '' === (string) $attr_v ) {
					continue;
				}
				$tax   = str_replace( 'attribute_', '', $attr_k );
				$label = wc_attribute_label( $tax, $product );
				if ( taxonomy_exists( $tax ) ) {
					$term = get_term_by( 'slug', $attr_v, $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						$attr_v = $term->name;
					}
				}
				$meta_parts[] = $label . ': ' . $attr_v;
			}
		}

		$variant_str = ! empty( $meta_parts ) ? implode( ' | ', $meta_parts ) : 'Mặc định';
		$sku_tag     = '' !== $sku ? '[SKU: ' . $sku . '] ' : '';

		$items_lines[] = $idx . '. ' . $sku_tag . $name . "\n"
			. '   • Phân loại: ' . $variant_str . "\n"
			. '   • Số lượng: x' . $qty . '  —  ' . $line_tot;

		// Product image URL for warehouse visual check.
		$img_url = '';
		if ( $product ) {
			$img_id = (int) $product->get_image_id();
			if ( $img_id <= 0 && $product->get_parent_id() > 0 ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$img_id = (int) $parent->get_image_id();
				}
			}
			if ( $img_id > 0 ) {
				$img_url = (string) wp_get_attachment_image_url( $img_id, 'woocommerce_single' );
			}
		}
		if ( '' !== $img_url && 0 === strpos( $img_url, 'http' ) ) {
			$photos[] = array(
				'photo'   => $img_url,
				'caption' => '#' . $order_number . ' • SP ' . $idx . ': ' . $sku_tag . $name . ' (' . $variant_str . ') — SL: x' . $qty,
			);
		}

		$items_data[] = array(
			'index'     => $idx,
			'sku'       => $sku,
			'name'      => $name,
			'variant'   => $variant_str,
			'quantity'  => $qty,
			'subtotal'  => (float) $item->get_total(),
			'image_url' => $img_url,
		);
		++$idx;
	}

	$edit_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

	$lines   = array();
	$lines[] = '📦 ĐƠN HÀNG MỚI #' . $order_number . ' — KHO XỬ LÝ ĐÓNG HÀNG';
	$lines[] = '⏰ Thời gian: ' . $time_str;
	$lines[] = '────────────────────';
	$lines[] = '👤 Người nhận: ' . ( '' !== $recipient ? $recipient : 'Khách lẻ' );
	$lines[] = '📞 Điện thoại: ' . ( '' !== $phone ? $phone : 'Chưa có' );
	if ( '' !== $email ) {
		$lines[] = '✉️ Email: ' . $email;
	}
	$lines[] = '📍 Địa chỉ giao: ' . ( '' !== $full_address ? $full_address : 'Chưa cập nhật' );
	$lines[] = '💳 Thanh toán: ' . ( '' !== $payment_title ? $payment_title : 'COD' );
	if ( '' !== $customer_note ) {
		$lines[] = '📝 Ghi chú của khách: ' . $customer_note;
	}
	$lines[] = '────────────────────';
	$lines[] = '🛍️ SẢN PHẨM CẦN NHẶT (' . $total_qty . ' món):';
	$lines[] = implode( "\n", $items_lines );
	$lines[] = '────────────────────';
	if ( '' !== $discount_str ) {
		$lines[] = '🏷️ Giảm giá: ' . $discount_str;
	}
	$lines[] = '🚚 Phí ship: ' . $shipping_str;
	$lines[] = '💰 TỔNG ĐƠN: ' . $total_str;
	$lines[] = '🔗 Xem đơn Web: ' . $edit_url;

	$text = implode( "\n", $lines );

	return array(
		'text'    => $text,
		'photos'  => $photos,
		'payload' => array(
			'event'        => 'new_order',
			'order_id'     => $order->get_id(),
			'order_number' => $order_number,
			'created_at'   => $time_str,
			'customer'     => array(
				'name'    => $recipient,
				'phone'   => $phone,
				'email'   => $email,
				'address' => $full_address,
				'note'    => $customer_note,
			),
			'payment'      => $payment_title,
			'total'        => (float) $order->get_total(),
			'total_text'   => $total_str,
			'items_count'  => $total_qty,
			'items'        => $items_data,
			'message_text' => $text,
			'admin_url'    => $edit_url,
		),
	);
}

/**
 * Send order notification to all configured Zalo Bot Chat IDs and/or custom Webhook.
 *
 * @param int|WC_Order $order_or_id Order ID or WC_Order object.
 * @param bool         $force       Whether to send even if already notified for this order.
 * @return array{sent:int,errors:string[]}
 */
function nexxi_kids_send_zalo_order_notification( $order_or_id, $force = false ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order instanceof WC_Order ) {
		return array(
			'sent'   => 0,
			'errors' => array( 'Không tìm thấy đơn hàng.' ),
		);
	}

	$cfg = nexxi_kids_zalo_notify_settings();
	if ( ! $force && '1' !== $cfg['enabled'] ) {
		return array(
			'sent'   => 0,
			'errors' => array( 'Tính năng thông báo Zalo Kho đang tắt.' ),
		);
	}

	if ( ! $force && 'yes' === (string) $order->get_meta( '_nexxi_zalo_warehouse_notified' ) ) {
		return array(
			'sent'   => 1,
			'errors' => array(),
		);
	}

	$formatted = nexxi_kids_format_zalo_order_message( $order );
	$chat_ids  = nexxi_kids_zalo_parse_chat_ids( $cfg['chat_ids'] );
	$sent      = 0;
	$errors    = array();

	// 1. Send via Zalo Bot API (`sendMessage` + optional `sendPhoto`).
	if ( '' !== $cfg['bot_token'] && ! empty( $chat_ids ) ) {
		foreach ( $chat_ids as $chat_id ) {
			$res = nexxi_kids_zalo_bot_request(
				'sendMessage',
				array(
					'chat_id' => $chat_id,
					'text'    => $formatted['text'],
				),
				$cfg['bot_token']
			);

			if ( is_wp_error( $res ) ) {
				$errors[] = 'Chat ID ' . $chat_id . ': ' . $res->get_error_message();
				continue;
			}
			if ( isset( $res['ok'] ) && false === $res['ok'] ) {
				$desc     = isset( $res['description'] ) ? (string) $res['description'] : 'Lỗi gửi tin nhắn.';
				$errors[] = 'Chat ID ' . $chat_id . ': ' . $desc;
				continue;
			}

			++$sent;

			// Send product photos so warehouse staff can visually verify each item.
			if ( '1' === $cfg['send_photos'] && ! empty( $formatted['photos'] ) ) {
				foreach ( array_slice( $formatted['photos'], 0, 5 ) as $photo_item ) {
					nexxi_kids_zalo_bot_request(
						'sendPhoto',
						array(
							'chat_id' => $chat_id,
							'photo'   => $photo_item['photo'],
							'caption' => $photo_item['caption'],
						),
						$cfg['bot_token'],
						8
					);
				}
			}
		}
	}

	// 2. Send via Custom Webhook URL (if configured for n8n / Make / Zalo Group Forwarder).
	if ( '' !== $cfg['webhook_url'] && 0 === strpos( $cfg['webhook_url'], 'http' ) ) {
		$wh_res = wp_remote_post(
			$cfg['webhook_url'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $formatted['payload'] ),
			)
		);
		if ( ! is_wp_error( $wh_res ) && wp_remote_retrieve_response_code( $wh_res ) < 400 ) {
			++$sent;
		} elseif ( is_wp_error( $wh_res ) ) {
			$errors[] = 'Webhook: ' . $wh_res->get_error_message();
		}
	}

	if ( $sent > 0 ) {
		$order->update_meta_data( '_nexxi_zalo_warehouse_notified', 'yes' );
		$order->update_meta_data( '_nexxi_zalo_warehouse_notified_at', current_time( 'mysql' ) );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'NEXXI Kho Zalo: Đã gửi thông báo đóng hàng tới %d kênh Zalo/Webhook.', $sent ) );
	}

	return array(
		'sent'   => $sent,
		'errors' => $errors,
	);
}

// Trigger automatically when a new order is placed on Checkout.
add_action(
	'woocommerce_checkout_order_processed',
	static function ( $order_id ) {
		nexxi_kids_send_zalo_order_notification( $order_id, false );
	},
	25,
	1
);

add_action(
	'woocommerce_store_api_checkout_order_processed',
	static function ( $order ) {
		nexxi_kids_send_zalo_order_notification( $order, false );
	},
	25,
	1
);

/**
 * Add WP Admin submenu under WooCommerce AND Settings for Zalo Warehouse Notifications.
 */
add_action(
	'admin_menu',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				'Thông Báo Đơn Hàng Zalo Kho – NEXXI',
				'🔔 Thông báo Zalo Kho',
				'manage_options',
				'nexxi-zalo-warehouse',
				'nexxi_kids_render_zalo_warehouse_admin_page'
			);
		}
		add_options_page(
			'Thông Báo Đơn Hàng Zalo Kho – NEXXI',
			'Thông báo Zalo Kho',
			'manage_options',
			'nexxi-zalo-warehouse',
			'nexxi_kids_render_zalo_warehouse_admin_page'
		);
	},
	30
);

/**
 * AJAX Handler in WP Admin for:
 * - Scanning `getUpdates` to automatically fetch Chat IDs of users/groups who messaged the Bot
 * - Activating Webhook mode (`setWebhook`)
 * - Sending a realistic Test Order Notification to Zalo
 */
add_action(
	'wp_ajax_nexxi_zalo_admin_action',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Không có quyền thực hiện.' ) );
		}
		check_ajax_referer( 'nexxi_zalo_admin_nonce', 'nonce' );

		$sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';
		$bot_token  = isset( $_POST['bot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) : '';
		$chat_ids   = isset( $_POST['chat_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['chat_ids'] ) ) : '';

		if ( '' !== $bot_token ) {
			update_option( 'nexxi_zalo_bot_token', $bot_token );
		}
		if ( isset( $_POST['chat_ids'] ) ) {
			update_option( 'nexxi_zalo_chat_ids', $chat_ids );
		}

		$cfg = nexxi_kids_zalo_notify_settings();

		// 1. Scan latest messages via `getUpdates` to auto-discover Chat IDs.
		if ( 'scan_updates' === $sub_action ) {
			// Delete webhook first so getUpdates is allowed by Zalo Bot API.
			nexxi_kids_zalo_bot_request( 'deleteWebhook', array(), $cfg['bot_token'], 8 );
			update_option( 'nexxi_zalo_webhook_auto_add', '0' );

			$me_res = nexxi_kids_zalo_bot_request( 'getMe', array(), $cfg['bot_token'], 8 );
			if ( is_array( $me_res ) && ! empty( $me_res['result'] ) ) {
				update_option( 'nexxi_zalo_bot_info', $me_res['result'], false );
			}

			$up_res = nexxi_kids_zalo_bot_request(
				'getUpdates',
				array( 'timeout' => 3 ),
				$cfg['bot_token'],
				10
			);

			if ( is_wp_error( $up_res ) ) {
				wp_send_json_error( array( 'message' => $up_res->get_error_message() ) );
			}

			$found = array();
			$list  = array();
			if ( isset( $up_res['result'] ) && is_array( $up_res['result'] ) ) {
				$list = isset( $up_res['result']['message'] ) ? array( $up_res['result'] ) : $up_res['result'];
			}

			foreach ( $list as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$ext = nexxi_kids_zalo_extract_chat_from_update( $item );
				if ( $ext && '' !== $ext['chat_id'] ) {
					nexxi_kids_zalo_add_chat_id( $ext['chat_id'], $ext['name'] );
					$found[ $ext['chat_id'] ] = $ext['name'] ? $ext['name'] . ' (' . $ext['chat_id'] . ')' : $ext['chat_id'];
				}
			}

			$all_ids = implode( "\n", nexxi_kids_zalo_parse_chat_ids() );
			if ( ! empty( $found ) ) {
				wp_send_json_success(
					array(
						'message'          => 'Đã tìm thấy và thêm tự động ' . count( $found ) . ' Chat ID: ' . implode( ', ', array_values( $found ) ),
						'chat_ids'         => $all_ids,
						'known'            => get_option( 'nexxi_zalo_known_chats', array() ),
						'webhook_auto_add' => '0',
					)
				);
			}

			wp_send_json_error(
				array(
					'message'          => 'Chưa thấy tin nhắn mới nào chờ trong hàng đợi. Bạn hãy mở Zalo, nhắn 1 tin bất kỳ (ví dụ: "kho") vào Bot Zalo của bạn rồi bấm nút Quét lại ngay nhé! (Hoặc bật chế độ Webhook tự động bên cạnh).',
					'webhook_auto_add' => '0',
				)
			);
		}

		// 2. Toggle Webhook auto-add mode (`enable_webhook` / `disable_webhook`).
		if ( 'enable_webhook' === $sub_action ) {
			$webhook_endpoint = rest_url( 'nexxi/v1/zalo-bot-webhook' );
			$res              = nexxi_kids_zalo_bot_request(
				'setWebhook',
				array(
					'url'          => $webhook_endpoint,
					'secret_token' => $cfg['secret_token'],
				),
				$cfg['bot_token'],
				10
			);
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			if ( isset( $res['ok'] ) && false === $res['ok'] ) {
				wp_send_json_error( array( 'message' => $res['description'] ?? 'Không thể đăng ký Webhook với Zalo.' ) );
			}
			update_option( 'nexxi_zalo_webhook_auto_add', '1' );
			wp_send_json_success(
				array(
					'message'          => '🟢 Đã BẬT chế độ Webhook tự động thêm nhân viên kho! Bây giờ bất kỳ nhân viên kho nào mở Zalo nhắn tin cho Bot (ví dụ: "xin chào"), Bot sẽ tự động lưu Chat ID của người đó vào danh sách nhận đơn và phản hồi ngay trên Zalo.',
					'webhook_auto_add' => '1',
				)
			);
		}

		if ( 'disable_webhook' === $sub_action ) {
			$res = nexxi_kids_zalo_bot_request( 'deleteWebhook', array(), $cfg['bot_token'], 10 );
			update_option( 'nexxi_zalo_webhook_auto_add', '0' );
			if ( is_wp_error( $res ) ) {
				wp_send_json_success(
					array(
						'message'          => '⚪ Đã TẮT chế độ Webhook tự động thêm nhân viên kho trên Website.',
						'webhook_auto_add' => '0',
					)
				);
			}
			wp_send_json_success(
				array(
					'message'          => '⚪ Đã TẮT chế độ Webhook tự động thêm nhân viên kho! Người lạ nhắn tin cho Bot sẽ không bị thêm vào danh sách nhận đơn của Kho nữa.',
					'webhook_auto_add' => '0',
				)
			);
		}

		// 3. Send a Test Order Notification to all configured Chat IDs.
		if ( 'send_test' === $sub_action ) {
			$ids = nexxi_kids_zalo_parse_chat_ids();
			if ( empty( $ids ) && '' === $cfg['webhook_url'] ) {
				wp_send_json_error( array( 'message' => 'Chưa có Chat ID người nhận nào. Hãy nhắn tin cho Bot trên Zalo rồi bấm "🔍 Quét & Lấy Chat ID tự động" trước nhé!' ) );
			}

			$test_text = "📦 [TEST] ĐƠN HÀNG MỚI #9999 — KHO XỬ LÝ ĐÓNG HÀNG\n"
				. "⏰ Thời gian: " . wp_date( 'H:i - d/m/Y' ) . "\n"
				. "────────────────────\n"
				. "👤 Người nhận: Mẹ Thanh Thảo (Đơn Test)\n"
				. "📞 Điện thoại: 0942 958 091\n"
				. "📍 Địa chỉ giao: 53 Bà Huyện Thanh Quan, Phường Xuân Hòa, TP. Hồ Chí Minh\n"
				. "💳 Thanh toán: Thanh toán khi nhận hàng (COD)\n"
				. "────────────────────\n"
				. "🛍️ SẢN PHẨM CẦN NHẶT (2 món):\n"
				. "1. [SKU: V1365] Đầm Bé Gái Hàn Quốc NEXXI\n"
				. "   • Phân loại: Màu: Hồng | Size: 2 (14-16kg)\n"
				. "   • Số lượng: x2  —  350.000đ\n"
				. "────────────────────\n"
				. "🚚 Phí ship: Miễn phí\n"
				. "💰 TỔNG ĐƠN: 350.000đ\n"
				. "✅ Kết nối Bot Zalo Kho NEXXI hoạt động thành công!";

			$sent_cnt = 0;
			$errs     = array();
			foreach ( $ids as $cid ) {
				$r = nexxi_kids_zalo_bot_request(
					'sendMessage',
					array(
						'chat_id' => $cid,
						'text'    => $test_text,
					),
					$cfg['bot_token'],
					10
				);
				if ( is_wp_error( $r ) ) {
					$errs[] = $cid . ': ' . $r->get_error_message();
				} elseif ( isset( $r['ok'] ) && false === $r['ok'] ) {
					$errs[] = $cid . ': ' . ( $r['description'] ?? 'Lỗi gửi tin' );
				} else {
					++$sent_cnt;
				}
			}

			if ( $sent_cnt > 0 ) {
				wp_send_json_success(
					array(
						'message' => '🚀 Đã gửi tin nhắn đơn hàng Test tới ' . $sent_cnt . ' Chat ID trên Zalo thành công! Bạn hãy mở Zalo kiểm tra tin nhắn nhé.',
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => 'Không gửi được tin nhắn Test. Chi tiết lỗi: ' . implode( '; ', $errs ),
				)
			);
		}

		wp_send_json_error( array( 'message' => 'Thao tác không hợp lệ.' ) );
	}
);

/**
 * Render the WP Admin page for Zalo Warehouse Order Notifications.
 */
function nexxi_kids_render_zalo_warehouse_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$saved = false;
	if ( isset( $_POST['nexxi_zalo_notify_save'] ) && check_admin_referer( 'nexxi_zalo_notify_settings' ) ) {
		update_option( 'nexxi_zalo_notify_enabled', isset( $_POST['nexxi_zalo_notify_enabled'] ) ? '1' : '0' );
		update_option( 'nexxi_zalo_bot_token', sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_bot_token'] ?? '' ) ) );
		update_option( 'nexxi_zalo_chat_ids', sanitize_textarea_field( wp_unslash( $_POST['nexxi_zalo_chat_ids'] ?? '' ) ) );
		update_option( 'nexxi_zalo_send_photos', isset( $_POST['nexxi_zalo_send_photos'] ) ? '1' : '0' );
		update_option( 'nexxi_zalo_webhook_url', esc_url_raw( wp_unslash( $_POST['nexxi_zalo_webhook_url'] ?? '' ) ) );
		$saved = true;
	}

	$cfg         = nexxi_kids_zalo_notify_settings();
	$known_chats = get_option( 'nexxi_zalo_known_chats', array() );
	$known_chats = is_array( $known_chats ) ? $known_chats : array();
	$nonce       = wp_create_nonce( 'nexxi_zalo_admin_nonce' );
	?>
	<div class="wrap" style="max-width:960px;margin-top:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<div style="background:linear-gradient(135deg,#031444 0%,#0068FF 100%);color:#fff;padding:24px 28px;border-radius:16px;box-shadow:0 10px 28px rgba(3,20,68,0.15);margin-bottom:20px;">
			<div style="display:inline-block;background:rgba(255,255,255,0.18);padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;margin-bottom:8px;">
				NEXXI Kids × Zalo Bot Platform
			</div>
			<h1 style="color:#fff;margin:0 0 6px;font-size:24px;font-weight:800;">🔔 Thông Báo Đơn Hàng Mới Qua Bot Zalo Cho Kho</h1>
			<p style="margin:0;color:rgba(255,255,255,0.88);font-size:14px;line-height:1.5;">
				Tự động bắn phiếu nhặt hàng chi tiết (Mã đơn, SĐT, Địa chỉ, Mã SKU, Màu/Size, Số lượng và Ảnh sản phẩm) vào Zalo của Kho ngay khi khách vừa đặt đơn trên Website.
			</p>
		</div>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><strong>✅ Đã lưu cài đặt Thông báo Zalo Kho thành công!</strong></p></div>
		<?php endif; ?>

		<!-- 3-STEP QUICK GUIDE -->
		<div style="background:#fff8f6;border:1.5px solid #ffdad2;border-radius:14px;padding:18px 22px;margin-bottom:20px;">
			<h3 style="margin:0 0 10px;color:#ae3115;font-size:15px;">📋 Hướng dẫn 3 bước kết nối Bot Zalo bạn vừa tạo (chỉ mất 30 giây):</h3>
			<ol style="margin:0;padding-left:20px;color:#1b1c1a;font-size:13.5px;line-height:1.7;">
				<li><strong>Bước 1:</strong> Sao chép mã <strong>Bot Token</strong> của Bot Zalo bạn vừa tạo dán vào ô <strong>Zalo Bot Token</strong> bên dưới.</li>
				<li><strong>Bước 2:</strong> Mở ứng dụng Zalo trên điện thoại/máy tính, tìm đến <strong>Bot Zalo của bạn</strong> (hoặc thêm Bot vào nhóm) và <strong>gửi 1 tin nhắn bất kỳ</strong> (ví dụ: <code>kho</code> hoặc <code>/start</code>).</li>
				<li><strong>Bước 3:</strong> Bấm nút <strong>"🔍 Quét &amp; Lấy Chat ID tự động"</strong> bên dưới → Hệ thống sẽ tự động điền mã <code>Chat ID</code> của bạn/nhóm kho vào ô người nhận → Bấm <strong>"🚀 Gửi tin nhắn Test thử"</strong> là xong!</li>
			</ol>
		</div>

		<div id="nexxi-zalo-ajax-notice" style="display:none;padding:14px 18px;border-radius:10px;margin-bottom:18px;font-weight:600;font-size:14px;"></div>

		<form method="post" style="background:#fff;padding:26px;border-radius:16px;box-shadow:0 4px 20px rgba(3,20,68,0.06);border:1px solid #e4e2de;">
			<?php wp_nonce_field( 'nexxi_zalo_notify_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Trạng thái hoạt động</th>
					<td>
						<label style="font-weight:600;color:#031444;">
							<input type="checkbox" name="nexxi_zalo_notify_enabled" value="1" <?php checked( '1', $cfg['enabled'] ); ?> />
							Bật tự động gửi thông báo Zalo cho Kho khi có đơn hàng mới trên Web
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="nexxi_zalo_bot_token">1. Zalo Bot Token <span style="color:#d63638;">*</span></label></th>
					<td>
						<input name="nexxi_zalo_bot_token" id="nexxi_zalo_bot_token" type="text" value="<?php echo esc_attr( $cfg['bot_token'] ); ?>" class="regular-text" style="width:100%;max-width:560px;font-family:monospace;padding:8px 12px;" placeholder="Dán Bot Token từ Zalo Bot Creator (VD: 123456789:abcDEF...)" />
						<p class="description">Mã Token được cấp khi bạn tạo Bot trên <code>bot.zapps.me</code> / Zalo Bot Manager.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="nexxi_zalo_chat_ids">2. Danh sách Chat ID nhận thông báo Kho <span style="color:#d63638;">*</span></label></th>
					<td>
						<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:10px;">
							<button type="button" id="btn-nexxi-zalo-scan" class="button button-secondary" style="background:#eef4ff;border-color:#0068FF;color:#0068FF;font-weight:700;padding:4px 14px;border-radius:8px;">
								🔍 Quét &amp; Lấy Chat ID tự động (từ tin nhắn mới gửi cho Bot)
							</button>
							<button type="button" id="btn-nexxi-zalo-webhook" data-webhook-active="<?php echo '1' === $cfg['webhook_auto_add'] ? '1' : '0'; ?>" class="button button-secondary" style="<?php echo '1' === $cfg['webhook_auto_add'] ? 'background:#e6f6ec;border-color:#1e8e3e;color:#0d5324;font-weight:700;padding:4px 14px;border-radius:8px;' : 'background:#f5f3ef;border-color:#031444;color:#031444;font-weight:600;padding:4px 14px;border-radius:8px;'; ?>">
								<?php if ( '1' === $cfg['webhook_auto_add'] ) : ?>
									🟢 Webhook tự động thêm nhân viên kho: ĐANG BẬT (Bấm để Tắt)
								<?php else : ?>
									⚪ Bật chế độ Webhook tự động thêm nhân viên kho (Đang Tắt)
								<?php endif; ?>
							</button>
						</div>

						<textarea name="nexxi_zalo_chat_ids" id="nexxi_zalo_chat_ids" rows="4" style="width:100%;max-width:560px;font-family:monospace;padding:10px;" placeholder="Mỗi dòng 1 mã Chat ID (bấm nút Quét ở trên để tự động lấy)..."><?php echo esc_textarea( $cfg['chat_ids'] ); ?></textarea>
						<p class="description">
							Có thể điền <strong>nhiều Chat ID</strong> (mỗi dòng 1 mã) để gửi đồng thời cho Quản lý + nhiều nhân viên kho. Khi bật <strong>Webhook tự động</strong>, bất kỳ nhân viên kho nào mở Zalo nhắn tin cho Bot cũng sẽ được tự động thêm vào danh sách này!
						</p>

						<?php if ( ! empty( $known_chats ) ) : ?>
							<div style="margin-top:10px;padding:10px 14px;background:#f5f3ef;border-radius:10px;max-width:560px;">
								<strong style="font-size:12px;color:#031444;display:block;margin-bottom:6px;">👥 Các tài khoản / nhóm Zalo đã kết nối với Bot:</strong>
								<?php foreach ( $known_chats as $kc ) : ?>
									<span style="display:inline-block;background:#fff;border:1px solid #c6c6d0;border-radius:999px;padding:3px 10px;font-size:12px;margin:2px 4px 2px 0;">
										<strong><?php echo esc_html( $kc['name'] ?? '' ); ?></strong>: <code><?php echo esc_html( $kc['chat_id'] ?? '' ); ?></code>
									</span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">3. Gửi kèm ảnh sản phẩm</th>
					<td>
						<label style="font-weight:600;color:#031444;">
							<input type="checkbox" name="nexxi_zalo_send_photos" value="1" <?php checked( '1', $cfg['send_photos'] ); ?> />
							Gửi kèm hình ảnh từng phân loại sản phẩm trong đơn vào Zalo để kho nhìn ảnh nhặt hàng chuẩn xác
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="nexxi_zalo_webhook_url">4. Webhook phụ trợ (Tùy chọn)</label></th>
					<td>
						<input name="nexxi_zalo_webhook_url" id="nexxi_zalo_webhook_url" type="url" value="<?php echo esc_attr( $cfg['webhook_url'] ); ?>" class="regular-text" style="width:100%;max-width:560px;" placeholder="https://... (nếu bạn dùng thêm n8n / Make / Google Sheet)" />
						<p class="description">Để trống nếu bạn chỉ gửi trực tiếp qua Zalo Bot Token ở mục 1 &amp; 2.</p>
					</td>
				</tr>
			</table>

			<div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:20px;padding-top:18px;border-top:1px solid #efeeea;">
				<button type="submit" name="nexxi_zalo_notify_save" value="1" class="button button-primary button-large" style="background:#fd6a49;border-color:#fd6a49;border-radius:999px;padding:6px 26px;font-weight:700;">
					💾 Lưu Cài Đặt Thông Báo Kho
				</button>
				<button type="button" id="btn-nexxi-zalo-test" class="button button-large" style="background:#0068FF;border-color:#0068FF;color:#fff;border-radius:999px;padding:6px 22px;font-weight:700;">
					🚀 Gửi Tin Nhắn Đơn Hàng Test Vào Zalo Ngay
				</button>
			</div>
		</form>
	</div>

	<script>
	(function() {
		const nonce = <?php echo wp_json_encode( $nonce ); ?>;
		const noticeBox = document.getElementById('nexxi-zalo-ajax-notice');
		const tokenInput = document.getElementById('nexxi_zalo_bot_token');
		const chatIdsInput = document.getElementById('nexxi_zalo_chat_ids');

		function showNotice(msg, isSuccess) {
			if (!noticeBox) return;
			noticeBox.style.display = 'block';
			noticeBox.style.background = isSuccess ? '#e6f6ec' : '#ffdad6';
			noticeBox.style.border = isSuccess ? '1px solid #34a853' : '1px solid #ba1a1a';
			noticeBox.style.color = isSuccess ? '#0d5324' : '#93000a';
			noticeBox.textContent = msg;
		}

		const whBtn = document.getElementById('btn-nexxi-zalo-webhook');

		function syncWebhookBtnUI(isActive) {
			if (!whBtn) return;
			const on = String(isActive) === '1';
			whBtn.setAttribute('data-webhook-active', on ? '1' : '0');
			if (on) {
				whBtn.style.background = '#e6f6ec';
				whBtn.style.borderColor = '#1e8e3e';
				whBtn.style.color = '#0d5324';
				whBtn.style.fontWeight = '700';
				whBtn.innerHTML = '🟢 Webhook tự động thêm nhân viên kho: ĐANG BẬT (Bấm để Tắt)';
			} else {
				whBtn.style.background = '#f5f3ef';
				whBtn.style.borderColor = '#031444';
				whBtn.style.color = '#031444';
				whBtn.style.fontWeight = '600';
				whBtn.innerHTML = '⚪ Bật chế độ Webhook tự động thêm nhân viên kho (Đang Tắt)';
			}
		}

		function runAction(subAction, btn, loadingText) {
			const orig = btn.innerHTML;
			btn.disabled = true;
			btn.innerHTML = loadingText;

			const fd = new FormData();
			fd.append('action', 'nexxi_zalo_admin_action');
			fd.append('nonce', nonce);
			fd.append('sub_action', subAction);
			fd.append('bot_token', tokenInput ? tokenInput.value.trim() : '');
			fd.append('chat_ids', chatIdsInput ? chatIdsInput.value.trim() : '');

			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd
			})
			.then(function(r) { return r.json(); })
			.then(function(res) {
				btn.disabled = false;
				btn.innerHTML = orig;
				if (res && res.data && typeof res.data.webhook_auto_add !== 'undefined') {
					syncWebhookBtnUI(res.data.webhook_auto_add);
				}
				if (res && res.success && res.data) {
					if (res.data.chat_ids && chatIdsInput) {
						chatIdsInput.value = res.data.chat_ids;
					}
					showNotice(res.data.message || 'Thành công!', true);
				} else {
					showNotice((res && res.data && res.data.message) ? res.data.message : 'Có lỗi xảy ra.', false);
				}
			})
			.catch(function() {
				btn.disabled = false;
				btn.innerHTML = orig;
				showNotice('Lỗi kết nối máy chủ. Vui lòng thử lại.', false);
			});
		}

		const scanBtn = document.getElementById('btn-nexxi-zalo-scan');
		if (scanBtn) {
			scanBtn.addEventListener('click', function() {
				runAction('scan_updates', scanBtn, '⏳ Đang quét tin nhắn từ Bot Zalo...');
			});
		}

		if (whBtn) {
			whBtn.addEventListener('click', function() {
				const isCurrentlyOn = whBtn.getAttribute('data-webhook-active') === '1';
				if (isCurrentlyOn) {
					runAction('disable_webhook', whBtn, '⏳ Đang tắt chế độ Webhook...');
				} else {
					runAction('enable_webhook', whBtn, '⏳ Đang bật chế độ Webhook...');
				}
			});
		}

		const testBtn = document.getElementById('btn-nexxi-zalo-test');
		if (testBtn) {
			testBtn.addEventListener('click', function() {
				runAction('send_test', testBtn, '⏳ Đang gửi tin nhắn Test vào Zalo...');
			});
		}
	})();
	</script>
	<?php
}
