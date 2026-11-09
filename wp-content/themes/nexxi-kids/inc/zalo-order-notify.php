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
function nexxi_kids_zalo_notify_settings( $force_refresh = false ) {
	static $cached = null;
	if ( null !== $cached && ! $force_refresh ) {
		return $cached;
	}
	$secret = trim( (string) get_option( 'nexxi_zalo_bot_secret_token', '' ) );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 24, false, false );
		update_option( 'nexxi_zalo_bot_secret_token', $secret, false );
	}
	$bot_info = get_option( 'nexxi_zalo_bot_info', array() );
	$start    = trim( (string) get_option( 'nexxi_zalo_active_start_time', '08:00' ) );
	$end      = trim( (string) get_option( 'nexxi_zalo_active_end_time', '17:30' ) );
	$cached   = array(
		'enabled'              => (string) get_option( 'nexxi_zalo_notify_enabled', '1' ),
		'bot_token'            => trim( (string) get_option( 'nexxi_zalo_bot_token', '' ) ),
		'chat_ids'             => trim( (string) get_option( 'nexxi_zalo_chat_ids', '' ) ),
		'send_photos'          => (string) get_option( 'nexxi_zalo_send_photos', '0' ),
		'webhook_url'          => trim( (string) get_option( 'nexxi_zalo_webhook_url', '' ) ),
		'webhook_auto_add'     => (string) get_option( 'nexxi_zalo_webhook_auto_add', '0' ),
		'scan_interval'        => (string) get_option( 'nexxi_zalo_scan_interval', '0' ),
		'active_hours_enabled' => (string) get_option( 'nexxi_zalo_active_hours_enabled', '0' ),
		'active_start_time'    => '' !== $start ? $start : '08:00',
		'active_end_time'      => '' !== $end ? $end : '17:30',
		'secret_token'         => $secret,
		'bot_info'             => is_array( $bot_info ) ? $bot_info : array(),
	);
	return $cached;
}

/**
 * Convert "HH:MM" time string to minutes from 00:00 (0..1439).
 *
 * @param string $hhmm Time string in HH:MM format.
 * @param int    $default Fallback minutes.
 * @return int
 */
function nexxi_kids_zalo_time_to_minutes( $hhmm, $default = 480 ) {
	if ( preg_match( '/^(\d{1,2}):(\d{2})/', trim( (string) $hhmm ), $m ) ) {
		$h = max( 0, min( 23, (int) $m[1] ) );
		$i = max( 0, min( 59, (int) $m[2] ) );
		return $h * 60 + $i;
	}
	return $default;
}

/**
 * Check if the current local time (WordPress timezone) is within the configured active notification hours.
 *
 * @param array|null $cfg Optional settings array.
 * @return bool
 */
function nexxi_kids_zalo_is_within_active_hours( $cfg = null ) {
	if ( ! is_array( $cfg ) ) {
		$cfg = nexxi_kids_zalo_notify_settings();
	}
	if ( '1' !== (string) $cfg['active_hours_enabled'] ) {
		return true;
	}
	$now_str   = wp_date( 'H:i' );
	$now_min   = nexxi_kids_zalo_time_to_minutes( $now_str, 0 );
	$start_min = nexxi_kids_zalo_time_to_minutes( $cfg['active_start_time'], 480 );
	$end_min   = nexxi_kids_zalo_time_to_minutes( $cfg['active_end_time'], 1050 );

	if ( $start_min <= $end_min ) {
		return ( $now_min >= $start_min && $now_min <= $end_min );
	}
	// Overnight window (e.g. 20:00 -> 06:00).
	return ( $now_min >= $start_min || $now_min <= $end_min );
}

/**
 * Calculate the next clock-aligned scan timestamp (Unix timestamp).
 *
 * Examples when active hours = 08:00 -> 17:00 and scan_interval = 30 mins:
 * - At 09:12 -> returns 09:30 today
 * - At 09:30 -> returns 10:00 today
 * - At 17:00 (or 21:00) -> returns 08:00 tomorrow (for single overnight summary at 08:00)
 * - At 06:30 -> returns 08:00 today
 *
 * @param array|null $cfg    Settings array.
 * @param int|null   $now_ts Reference Unix timestamp (defaults to current time).
 * @return int Next scan Unix timestamp.
 */
function nexxi_kids_get_next_aligned_scan_timestamp( $cfg = null, $now_ts = null ) {
	if ( ! is_array( $cfg ) ) {
		$cfg = nexxi_kids_zalo_notify_settings();
	}
	if ( null === $now_ts ) {
		$now_ts = time();
	}

	$tz           = wp_timezone();
	$now_dt       = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz );
	$interval_min = max( 1, (int) $cfg['scan_interval'] > 0 ? (int) $cfg['scan_interval'] : 15 );
	$step_sec     = $interval_min * MINUTE_IN_SECONDS;

	if ( '1' === (string) $cfg['active_hours_enabled'] ) {
		$start_min = nexxi_kids_zalo_time_to_minutes( $cfg['active_start_time'], 480 );
		$end_min   = nexxi_kids_zalo_time_to_minutes( $cfg['active_end_time'], 1020 );

		$start_h = (int) floor( $start_min / 60 );
		$start_i = (int) ( $start_min % 60 );
		$end_h   = (int) floor( $end_min / 60 );
		$end_i   = (int) ( $end_min % 60 );

		$today_start = $now_dt->setTime( $start_h, $start_i, 0 )->getTimestamp();
		$today_end   = $now_dt->setTime( $end_h, $end_i, 0 )->getTimestamp();

		if ( $today_end >= $today_start ) {
			// Before today's active start time (e.g. 06:30 < 08:00) -> next scan is at 08:00 today.
			if ( $now_ts < $today_start ) {
				return $today_start;
			}
			// Within today's active hours -> next aligned slot from today_start.
			if ( $now_ts < $today_end ) {
				$elapsed = $now_ts - $today_start;
				$slots   = (int) floor( $elapsed / $step_sec ) + 1;
				$next_ts = $today_start + ( $slots * $step_sec );
				if ( $next_ts <= $today_end ) {
					return $next_ts;
				}
			}
			// At or after today's active end time (e.g. >= 17:00) -> next scan is 08:00 tomorrow!
			return $now_dt->modify( '+1 day' )->setTime( $start_h, $start_i, 0 )->getTimestamp();
		}
	}

	// 24/24 mode: align to clock from 00:00 today.
	$midnight_ts = $now_dt->setTime( 0, 0, 0 )->getTimestamp();
	$elapsed     = max( 0, $now_ts - $midnight_ts );
	$slots       = (int) floor( $elapsed / $step_sec ) + 1;
	return $midnight_ts + ( $slots * $step_sec );
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
 * Split a long Zalo text message into safe chunks strictly under Zalo Bot API's 2,000-character limit.
 * Splits preferentially on double-newline (`\n\n`), then single-newline (`\n`), then whitespace.
 *
 * @param string $text      Full message text.
 * @param int    $max_chars Maximum characters per chunk (default 1850, Zalo hard limit is 2000).
 * @return string[]
 */
function nexxi_kids_zalo_split_message_chunks( $text, $max_chars = 1850 ) {
	$text      = trim( (string) $text );
	$max_chars = max( 300, min( 1950, (int) $max_chars ) );
	if ( '' === $text ) {
		return array();
	}
	$len_fn = function_exists( 'mb_strlen' )
		? static function ( $s ) { return (int) mb_strlen( (string) $s, 'UTF-8' ); }
		: static function ( $s ) { return (int) strlen( (string) $s ); };
	$sub_fn = function_exists( 'mb_substr' )
		? static function ( $s, $start, $len = null ) {
			return null === $len
				? (string) mb_substr( (string) $s, (int) $start, null, 'UTF-8' )
				: (string) mb_substr( (string) $s, (int) $start, (int) $len, 'UTF-8' );
		}
		: static function ( $s, $start, $len = null ) {
			return null === $len
				? (string) substr( (string) $s, (int) $start )
				: (string) substr( (string) $s, (int) $start, (int) $len );
		};

	if ( $len_fn( $text ) <= $max_chars ) {
		return array( $text );
	}

	$chunks    = array();
	$remaining = $text;

	while ( '' !== $remaining ) {
		if ( $len_fn( $remaining ) <= $max_chars ) {
			$chunks[] = $remaining;
			break;
		}

		$slice    = $sub_fn( $remaining, 0, $max_chars );
		$cut_pos  = -1;
		$dbl_pos  = strrpos( $slice, "\n\n" );
		if ( false !== $dbl_pos && $dbl_pos >= 200 ) {
			$cut_char = $len_fn( substr( $slice, 0, $dbl_pos ) );
			if ( $cut_char > 0 ) {
				$cut_pos = $cut_char;
			}
		}
		if ( $cut_pos <= 0 ) {
			$nl_pos = strrpos( $slice, "\n" );
			if ( false !== $nl_pos && $nl_pos >= 120 ) {
				$cut_char = $len_fn( substr( $slice, 0, $nl_pos ) );
				if ( $cut_char > 0 ) {
					$cut_pos = $cut_char;
				}
			}
		}
		if ( $cut_pos <= 0 ) {
			$sp_pos = strrpos( $slice, ' ' );
			if ( false !== $sp_pos && $sp_pos >= 120 ) {
				$cut_char = $len_fn( substr( $slice, 0, $sp_pos ) );
				if ( $cut_char > 0 ) {
					$cut_pos = $cut_char;
				}
			}
		}
		if ( $cut_pos <= 0 ) {
			$cut_pos = $max_chars;
		}

		$part = trim( $sub_fn( $remaining, 0, $cut_pos ) );
		if ( '' !== $part ) {
			$chunks[] = $part;
		}
		$remaining = ltrim( $sub_fn( $remaining, $cut_pos ) );
	}

	return ! empty( $chunks ) ? $chunks : array( $sub_fn( $text, 0, $max_chars ) );
}

/**
 * Call a Zalo Bot API method (`getMe`, `sendMessage`, `sendPhoto`, `getUpdates`, `setWebhook`, `deleteWebhook`).
 * Automatically tries `bot-api.zapps.me` and falls back to `bot-api.zaloplatforms.com`.
 * Automatically splits `sendMessage` payloads exceeding 1,850 characters so Zalo's 2,000-char limit is never exceeded.
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

	$clean_method = ltrim( (string) $method, '/' );
	if ( 'sendmessage' === strtolower( $clean_method ) && ! empty( $payload['text'] ) ) {
		$chunks = nexxi_kids_zalo_split_message_chunks( (string) $payload['text'], 1850 );
		if ( count( $chunks ) > 1 ) {
			$last_ok_res = array( 'ok' => true );
			foreach ( $chunks as $idx => $chunk_text ) {
				if ( $idx > 0 ) {
					usleep( 200000 );
				}
				$chunk_payload         = $payload;
				$chunk_payload['text'] = $chunk_text;
				$chunk_res             = nexxi_kids_zalo_bot_request( $clean_method, $chunk_payload, $bot_token, $timeout );
				if ( is_wp_error( $chunk_res ) ) {
					return $chunk_res;
				}
				if ( isset( $chunk_res['ok'] ) && false === $chunk_res['ok'] ) {
					return $chunk_res;
				}
				$last_ok_res = $chunk_res;
			}
			return $last_ok_res;
		}
	}

	$hosts = array(
		'https://bot-api.zapps.me',
		'https://bot-api.zaloplatforms.com',
	);

	$last_err = null;
	foreach ( $hosts as $host ) {
		$url = $host . '/bot' . $bot_token . '/' . $clean_method;
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
 * Supports both Zalo Bot Platform (`result.chat.id`, `result.sender.display_name`)
 * and Telegram-style (`message.chat.id`, `message.from.display_name`) structures.
 *
 * @param array $update Update item from `getUpdates` or Webhook.
 * @return array{chat_id:string,name:string,text:string,chat_type:string}|null
 */
function nexxi_kids_zalo_extract_chat_from_update( array $update ) {
	$root = ( isset( $update['result'] ) && is_array( $update['result'] ) && ( isset( $update['result']['chat'] ) || isset( $update['result']['message'] ) || isset( $update['result']['sender'] ) ) )
		? $update['result']
		: $update;

	$msg    = isset( $root['message'] ) && is_array( $root['message'] ) ? $root['message'] : $root;
	$chat   = isset( $root['chat'] ) && is_array( $root['chat'] ) ? $root['chat'] : ( isset( $msg['chat'] ) && is_array( $msg['chat'] ) ? $msg['chat'] : array() );
	$sender = isset( $root['sender'] ) && is_array( $root['sender'] ) ? $root['sender'] : ( isset( $root['from'] ) && is_array( $root['from'] ) ? $root['from'] : ( isset( $msg['from'] ) && is_array( $msg['from'] ) ? $msg['from'] : ( isset( $msg['sender'] ) && is_array( $msg['sender'] ) ? $msg['sender'] : array() ) ) );

	$chat_id = '';
	if ( ! empty( $chat['id'] ) ) {
		$chat_id = (string) $chat['id'];
	} elseif ( ! empty( $sender['id'] ) ) {
		$chat_id = (string) $sender['id'];
	}
	if ( '' === $chat_id ) {
		return null;
	}

	$chat_type = ! empty( $chat['chat_type'] ) ? strtoupper( (string) $chat['chat_type'] ) : ( ! empty( $chat['type'] ) ? strtoupper( (string) $chat['type'] ) : '' );

	$sender_name = '';
	if ( ! empty( $sender['display_name'] ) ) {
		$sender_name = (string) $sender['display_name'];
	} elseif ( ! empty( $sender['name'] ) ) {
		$sender_name = (string) $sender['name'];
	} elseif ( ! empty( $sender['first_name'] ) ) {
		$sender_name = trim( (string) $sender['first_name'] . ' ' . (string) ( $sender['last_name'] ?? '' ) );
	}

	$chat_title = ! empty( $chat['title'] ) ? (string) $chat['title'] : ( ! empty( $chat['name'] ) ? (string) $chat['name'] : '' );
	if ( 'GROUP' === $chat_type ) {
		$name = '' !== $chat_title ? 'Nhóm: ' . $chat_title : ( '' !== $sender_name ? 'Nhóm Zalo (qua ' . $sender_name . ')' : 'Nhóm Zalo Kho' );
	} else {
		$name = '' !== $sender_name ? $sender_name : $chat_title;
	}

	$text = isset( $msg['text'] ) ? trim( (string) $msg['text'] ) : '';

	return array(
		'chat_id'   => $chat_id,
		'name'      => $name,
		'text'      => $text,
		'chat_type' => $chat_type,
	);
}

/**
 * Register REST API Webhook endpoint (`/wp-json/nexxi/v1/zalo-bot-webhook`)
 * and Scanner trigger endpoint (`/wp-json/nexxi/v1/zalo-bot-scan`)
 * so when anyone messages the Zalo Bot (e.g. `/id`, `/start`, `kho`), the website
 * automatically captures their `chat_id` and replies on Zalo, and external cron/monitors
 * can trigger due scans even when frontend pages are served from LiteSpeed Cache.
 */
add_action(
	'rest_api_init',
	static function () {
		$scan_callback = static function ( WP_REST_Request $request ) {
			$force = false;
			$sec   = trim( (string) $request->get_param( 'secret' ) );
			$cfg   = nexxi_kids_zalo_notify_settings();
			if ( '' !== $sec && '' !== $cfg['secret_token'] && hash_equals( $cfg['secret_token'], $sec ) ) {
				$force = true;
			}
			$res       = nexxi_kids_run_zalo_order_scan( $force );
			$last_ts   = (int) get_option( 'nexxi_zalo_last_scan_ts', 0 );
			$next_ts   = (int) get_option( 'nexxi_zalo_next_scan_ts', 0 );
			$last_err  = (string) get_option( 'nexxi_zalo_last_scan_error', '' );
			$rem_count = count( nexxi_kids_get_pending_zalo_orders( 100 ) );

			return new WP_REST_Response(
				array(
					'ok'            => empty( $res['errors'] ),
					'processed'     => (int) ( $res['processed'] ?? 0 ),
					'sent_orders'   => (int) ( $res['sent_orders'] ?? 0 ),
					'pending_count' => $rem_count,
					'skipped_hours' => ! empty( $res['skipped_hours'] ),
					'window_str'    => (string) ( $res['window_str'] ?? '' ),
					'last_scan_ts'  => $last_ts,
					'last_scan'     => (string) get_option( 'nexxi_zalo_last_scan_time', '' ),
					'next_scan_ts'  => $next_ts,
					'next_scan'     => $next_ts > 0 ? wp_date( 'H:i - d/m/Y', $next_ts ) : '',
					'errors_count'  => is_array( $res['errors'] ?? null ) ? count( $res['errors'] ) : 0,
					'last_error'    => $last_err,
				),
				200
			);
		};

		register_rest_route(
			'nexxi/v1',
			'/zalo-bot-scan',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'permission_callback' => '__return_true',
				'callback'            => $scan_callback,
			)
		);

		register_rest_route(
			'nexxi/v1',
			'/zalo-bot-webhook',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) use ( $scan_callback ) {
					if ( 'trigger_scan' === sanitize_key( (string) $request->get_param( 'action' ) ) ) {
						return call_user_func( $scan_callback, $request );
					}

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
				'caption' => '#' . $order_number . ' • Ảnh SP ' . $idx . ' (SL: x' . $qty . ')',
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
	$lines[] = '🛍️ Tổng SL cần nhặt: ' . $total_qty . ' sản phẩm';
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
		$order->update_meta_data( '_nexxi_zalo_warehouse_pending', 'no' );
		$order->update_meta_data( '_nexxi_zalo_warehouse_notified_at', current_time( 'mysql' ) );
		$order->save_meta_data();
		$order->add_order_note( sprintf( 'NEXXI Kho Zalo: Đã gửi thông báo đóng hàng tới %d kênh Zalo/Webhook.', $sent ) );
	}

	return array(
		'sent'   => $sent,
		'errors' => $errors,
	);
}

/**
 * Retrieve orders created since the last scan checkpoint (`$since_ts` -> `$until_ts`)
 * that have not yet been notified to the Zalo Warehouse group.
 *
 * Strictly only returns orders generated from the scan checkpoint onwards (never old historical orders).
 *
 * @param int      $limit    Max orders to fetch.
 * @param int|null $since_ts Start Unix timestamp (defaults to `nexxi_zalo_last_scan_ts`).
 * @param int|null $until_ts End Unix timestamp (defaults to current time).
 * @return WC_Order[]
 */
function nexxi_kids_get_pending_zalo_orders( $limit = 100, $since_ts = null, $until_ts = null ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	if ( null === $since_ts ) {
		$since_ts = (int) get_option( 'nexxi_zalo_last_scan_ts', 0 );
	}
	if ( $since_ts <= 0 ) {
		$since_ts = time();
		update_option( 'nexxi_zalo_last_scan_ts', $since_ts, false );
		return array();
	}
	if ( null === $until_ts ) {
		$until_ts = time();
	}
	if ( $until_ts < $since_ts ) {
		$until_ts = $since_ts;
	}

	$orders = wc_get_orders(
		array(
			'status'       => array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed' ),
			'limit'        => max( 1, (int) $limit ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'date_created' => $since_ts . '...' . $until_ts,
		)
	);

	$out = array();
	foreach ( (array) $orders as $ord ) {
		if ( ! $ord instanceof WC_Order ) {
			continue;
		}
		if ( 'yes' === (string) $ord->get_meta( '_nexxi_zalo_warehouse_notified' ) ) {
			continue;
		}
		$created_dt = $ord->get_date_created();
		$created_ts = $created_dt ? $created_dt->getTimestamp() : 0;
		if ( $created_ts > 0 && $created_ts < $since_ts ) {
			continue;
		}
		$out[] = $ord;
	}
	return $out;
}

/**
 * Build one or more consolidated Zalo notification messages for a batch of orders,
 * automatically splitting into numbered parts (`Phần 1/N`, `Phần 2/N`...) so every
 * message stays strictly <= 1,750 characters (well below Zalo Bot API's 2,000-character limit)
 * and 100% of orders in the batch are shown in full without truncation.
 *
 * @param WC_Order[] $orders       Orders in this scan batch.
 * @param int        $from_ts      Window start Unix timestamp.
 * @param int        $to_ts        Window end Unix timestamp.
 * @param bool       $is_overnight True when summarizing off-hours/overnight orders at active start time.
 * @param int        $max_chars    Max characters per Zalo message part (default 1750).
 * @return array<int,array{orders:WC_Order[],text:string,payload:array,part_num:int,total_parts:int}>
 */
function nexxi_kids_build_zalo_batch_order_chunks( array $orders, $from_ts, $to_ts, $is_overnight = false, $max_chars = 1750 ) {
	$valid_orders = array();
	foreach ( $orders as $ord ) {
		if ( $ord instanceof WC_Order ) {
			$valid_orders[] = $ord;
		}
	}
	$order_count = count( $valid_orders );
	if ( $order_count <= 0 ) {
		return array();
	}

	$max_chars       = max( 800, min( 1850, (int) $max_chars ) );
	$display_from_ts = ( ! $is_overnight && ( $to_ts - $from_ts ) >= 120 && ( (int) wp_date( 'i', $from_ts ) % 5 === 0 ) )
		? ( $from_ts + 60 )
		: $from_ts;

	$same_day = ( wp_date( 'Y-m-d', $display_from_ts ) === wp_date( 'Y-m-d', $to_ts ) );
	if ( $same_day && ! $is_overnight ) {
		$window_str = wp_date( 'H:i', $display_from_ts ) . ' ➔ ' . wp_date( 'H:i', $to_ts ) . ' (' . wp_date( 'd/m/Y', $to_ts ) . ')';
	} else {
		$window_str = wp_date( 'H:i d/m', $from_ts ) . ' ➔ ' . wp_date( 'H:i d/m/Y', $to_ts );
	}

	$len_fn = function_exists( 'mb_strlen' )
		? static function ( $s ) { return (int) mb_strlen( (string) $s, 'UTF-8' ); }
		: static function ( $s ) { return (int) strlen( (string) $s ); };

	$total_items   = 0;
	$total_revenue = 0.0;
	$prepared      = array();
	$idx           = 1;

	foreach ( $valid_orders as $order ) {
		$order_number  = $order->get_order_number();
		$created       = $order->get_date_created();
		$order_time    = $created ? $created->setTimezone( wp_timezone() )->format( $same_day ? 'H:i' : 'H:i d/m' ) : wp_date( 'H:i' );
		$order_total   = (float) $order->get_total();
		$total_revenue += $order_total;

		$recipient = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $recipient ) {
			$recipient = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}
		$phone = trim( (string) $order->get_billing_phone() );

		$addr1      = trim( (string) $order->get_billing_address_1() );
		$ward       = trim( (string) $order->get_meta( '_billing_ward' ) );
		$city       = trim( (string) $order->get_billing_city() );
		$addr_parts = array_filter( array( $addr1, $ward, $city ) );
		if ( '' !== $ward && false !== stripos( $addr1, $ward ) ) {
			$addr_parts = array_filter( array( $addr1, $city ) );
		}
		$full_addr = implode( ', ', $addr_parts );

		$ord_qty    = 0;
		$item_brief = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$q            = (int) $item->get_quantity();
			$ord_qty     += $q;
			$total_items += $q;
			$prod         = $item->get_product();
			$sku          = $prod ? trim( (string) $prod->get_sku() ) : '';
			$sku_prefix   = '' !== $sku ? '[' . $sku . '] ' : '';

			$meta_parts = array();
			foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
				$v = trim( wp_strip_all_tags( (string) $meta->display_value ) );
				if ( '' !== $v ) {
					$meta_parts[] = $v;
				}
			}
			$var_txt      = ! empty( $meta_parts ) ? ' (' . implode( '/', $meta_parts ) . ')' : '';
			$item_brief[] = $sku_prefix . $item->get_name() . $var_txt . ' x' . $q;
		}

		$line_block = $idx . '. Đơn #' . $order_number . ' (' . $order_time . ') — ' . ( '' !== $recipient ? $recipient : 'Khách lẻ' ) . ( '' !== $phone ? ' - ' . $phone : '' );
		if ( '' !== $full_addr ) {
			$line_block .= "\n   • Đ/c: " . $full_addr;
		}
		$line_block .= "\n   • Tổng: " . number_format( $order_total, 0, ',', '.' ) . 'đ (' . ( $order->get_payment_method_title() ?: 'COD' ) . ')';

		$prepared[] = array(
			'order'      => $order,
			'line_block' => $line_block,
			'order_data' => array(
				'order_id'     => $order->get_id(),
				'order_number' => $order_number,
				'time'         => $order_time,
				'customer'     => $recipient,
				'phone'        => $phone,
				'address'      => $full_addr,
				'items_count'  => $ord_qty,
				'items_text'   => implode( '; ', $item_brief ),
				'total'        => $order_total,
			),
		);
		++$idx;
	}

	// Reserve ~360 characters for header + footer envelope so body + envelope <= $max_chars.
	$body_budget = max( 450, $max_chars - 360 );
	$raw_groups  = array();
	$cur_group   = array();
	$cur_len     = 0;

	foreach ( $prepared as $entry ) {
		$b_len = $len_fn( $entry['line_block'] );
		$add   = empty( $cur_group ) ? $b_len : ( $b_len + 2 );
		if ( ! empty( $cur_group ) && ( $cur_len + $add ) > $body_budget ) {
			$raw_groups[] = $cur_group;
			$cur_group    = array( $entry );
			$cur_len      = $b_len;
		} else {
			$cur_group[] = $entry;
			$cur_len    += $add;
		}
	}
	if ( ! empty( $cur_group ) ) {
		$raw_groups[] = $cur_group;
	}

	$total_parts = count( $raw_groups );
	$admin_link  = admin_url( 'edit.php?post_type=shop_order' );
	$out_chunks  = array();

	foreach ( $raw_groups as $g_idx => $group ) {
		$part_num    = $g_idx + 1;
		$part_suffix = $total_parts > 1 ? sprintf( ' (Phần %d/%d)', $part_num, $total_parts ) : '';
		$part_orders = array();
		$part_blocks = array();
		$part_data   = array();

		foreach ( $group as $entry ) {
			$part_orders[] = $entry['order'];
			$part_blocks[] = $entry['line_block'];
			$part_data[]   = $entry['order_data'];
		}

		$lines = array();
		if ( $is_overnight ) {
			$lines[] = '🌅 TỔNG HỢP ĐƠN HÀNG NGOÀI GIỜ HOẠT ĐỘNG' . $part_suffix;
			$lines[] = '⏰ Khung giờ: ' . $window_str;
			$lines[] = '📦 Phát sinh ngoài giờ: ' . $order_count . ' ĐƠN HÀNG MỚI' . ( $total_parts > 1 ? sprintf( ' (Phần %d/%d: %d đơn)', $part_num, $total_parts, count( $part_orders ) ) : '' );
		} else {
			$lines[] = '🔔 BÁO CÁO ĐƠN HÀNG MỚI ĐỢT QUÉT' . $part_suffix;
			$lines[] = '⏰ Khung giờ quét: ' . $window_str;
			$lines[] = '📦 Phát sinh mới: ' . $order_count . ' ĐƠN HÀNG' . ( $total_parts > 1 ? sprintf( ' (Phần %d/%d: %d đơn)', $part_num, $total_parts, count( $part_orders ) ) : '' );
		}
		$lines[] = '🛍️ Tổng SL cần nhặt: ' . $total_items . ' sản phẩm  |  💰 Tổng: ' . number_format( $total_revenue, 0, ',', '.' ) . 'đ';
		$lines[] = '────────────────────';
		$lines[] = implode( "\n\n", $part_blocks );
		$lines[] = '────────────────────';
		$lines[] = '👉 Kho vui lòng kiểm tra và xử lý đóng hàng cho ' . ( $total_parts > 1 ? sprintf( '%d/%d đơn trên!', count( $part_orders ), $order_count ) : ( $order_count . ' đơn trên!' ) );
		$lines[] = '🔗 Quản lý đơn Web: ' . $admin_link;

		$text = implode( "\n", $lines );

		$out_chunks[] = array(
			'orders'      => $part_orders,
			'text'        => $text,
			'part_num'    => $part_num,
			'total_parts' => $total_parts,
			'payload'     => array(
				'event'         => $is_overnight ? 'overnight_orders_summary' : 'batch_orders_scan',
				'part_num'      => $part_num,
				'total_parts'   => $total_parts,
				'window_from'   => wp_date( 'Y-m-d H:i:s', $from_ts ),
				'window_to'     => wp_date( 'Y-m-d H:i:s', $to_ts ),
				'orders_count'  => count( $part_orders ),
				'batch_orders'  => $order_count,
				'items_count'   => $total_items,
				'total_revenue' => $total_revenue,
				'orders'        => $part_data,
				'message_text'  => $text,
			),
		);
	}

	return $out_chunks;
}

/**
 * Format a batch of newly generated orders within a scan window (`$from_ts` -> `$to_ts`)
 * into a consolidated Zalo group notification message.
 *
 * @param WC_Order[] $orders       Orders in this scan batch.
 * @param int        $from_ts      Window start Unix timestamp.
 * @param int        $to_ts        Window end Unix timestamp.
 * @param bool       $is_overnight True when summarizing off-hours/overnight orders at active start time (e.g. 08:00 AM).
 * @return array{text:string,payload:array,chunks:array}
 */
function nexxi_kids_format_zalo_batch_orders_message( array $orders, $from_ts, $to_ts, $is_overnight = false ) {
	$chunks = nexxi_kids_build_zalo_batch_order_chunks( $orders, $from_ts, $to_ts, $is_overnight, 1750 );
	if ( empty( $chunks ) ) {
		return array(
			'text'    => '',
			'payload' => array(),
			'chunks'  => array(),
		);
	}
	return array(
		'text'    => $chunks[0]['text'],
		'payload' => $chunks[0]['payload'],
		'chunks'  => $chunks,
	);
}

/**
 * Send consolidated Zalo notifications for a batch of orders scanned in `[$from_ts, $to_ts]`.
 * Automatically splits large batches into sequential parts (each <= 1,750 chars, strictly below
 * Zalo's 2,000-character limit) and marks each part's orders as notified immediately upon success.
 *
 * @param WC_Order[] $orders       Orders in the batch.
 * @param int        $from_ts      Start timestamp.
 * @param int        $to_ts        End timestamp.
 * @param bool       $is_overnight True if overnight summary scan.
 * @return array{sent_channels:int,notified_orders:int,last_order_ts:int,errors:string[]}
 */
function nexxi_kids_send_zalo_batch_orders_notification( array $orders, $from_ts, $to_ts, $is_overnight = false ) {
	$chunks = nexxi_kids_build_zalo_batch_order_chunks( $orders, $from_ts, $to_ts, $is_overnight, 1750 );
	if ( empty( $chunks ) ) {
		return array(
			'sent_channels'   => 0,
			'notified_orders' => 0,
			'last_order_ts'   => 0,
			'errors'          => array(),
		);
	}

	$cfg             = nexxi_kids_zalo_notify_settings();
	$chat_ids        = nexxi_kids_zalo_parse_chat_ids( $cfg['chat_ids'] );
	$total_sent_ch   = 0;
	$notified_orders = 0;
	$last_order_ts   = 0;
	$errors          = array();
	$total_batch_cnt = count( $orders );

	foreach ( $chunks as $c_idx => $chunk ) {
		if ( $c_idx > 0 ) {
			usleep( 250000 );
		}

		$chunk_sent = 0;
		$part_label = $chunk['total_parts'] > 1 ? sprintf( ' [Phần %d/%d]', $chunk['part_num'], $chunk['total_parts'] ) : '';

		if ( '' !== $cfg['bot_token'] && ! empty( $chat_ids ) ) {
			foreach ( $chat_ids as $chat_id ) {
				$res = nexxi_kids_zalo_bot_request(
					'sendMessage',
					array(
						'chat_id' => $chat_id,
						'text'    => $chunk['text'],
					),
					$cfg['bot_token']
				);

				if ( is_wp_error( $res ) ) {
					$errors[] = 'Chat ID ' . $chat_id . $part_label . ': ' . $res->get_error_message();
					continue;
				}
				if ( isset( $res['ok'] ) && false === $res['ok'] ) {
					$errors[] = 'Chat ID ' . $chat_id . $part_label . ': ' . ( $res['description'] ?? 'Lỗi gửi tin nhắn.' );
					continue;
				}
				++$chunk_sent;
			}
		}

		if ( '' !== $cfg['webhook_url'] && 0 === strpos( $cfg['webhook_url'], 'http' ) ) {
			$wh_res = wp_remote_post(
				$cfg['webhook_url'],
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode( $chunk['payload'] ),
				)
			);
			if ( ! is_wp_error( $wh_res ) && wp_remote_retrieve_response_code( $wh_res ) < 400 ) {
				++$chunk_sent;
			} elseif ( is_wp_error( $wh_res ) ) {
				$errors[] = 'Webhook' . $part_label . ': ' . $wh_res->get_error_message();
			}
		}

		if ( $chunk_sent > 0 ) {
			$total_sent_ch = max( $total_sent_ch, $chunk_sent );
			$now_mysql     = current_time( 'mysql' );
			foreach ( $chunk['orders'] as $ord ) {
				if ( ! $ord instanceof WC_Order ) {
					continue;
				}
				$ord->update_meta_data( '_nexxi_zalo_warehouse_notified', 'yes' );
				$ord->update_meta_data( '_nexxi_zalo_warehouse_pending', 'no' );
				$ord->update_meta_data( '_nexxi_zalo_warehouse_notified_at', $now_mysql );
				$ord->save_meta_data();
				$ord->add_order_note(
					sprintf(
						'NEXXI Kho Zalo: Đã gửi thông báo tổng hợp đợt quét (%d đơn%s) tới %d kênh Zalo/Webhook.',
						$total_batch_cnt,
						$part_label,
						$chunk_sent
					)
				);
				$ord_dt = $ord->get_date_created();
				if ( $ord_dt && $ord_dt->getTimestamp() > $last_order_ts ) {
					$last_order_ts = (int) $ord_dt->getTimestamp();
				}
				++$notified_orders;
			}
		} else {
			// Stop sending subsequent parts in this cycle if the current part failed across all channels.
			break;
		}
	}

	return array(
		'sent_channels'   => $total_sent_ch,
		'notified_orders' => $notified_orders,
		'last_order_ts'   => $last_order_ts,
		'errors'          => $errors,
	);
}

/**
 * Scan and send a consolidated Zalo notification for all new orders generated since the previous scan checkpoint.
 *
 * - Example 1: Scan batch 09:01 -> 09:30 has 10 orders => sends consolidated message(s) reporting 10 orders.
 * - Example 2: Scan batch 09:31 -> 10:00 has 2 orders  => sends 1 consolidated message reporting 2 orders.
 * - Example 3: Outside active hours (e.g. after 16:20/17:30 until 08:00 next morning) => holds all off-hours orders
 *   and sends consolidated summary message(s) at 08:00 AM the next day.
 *
 * @param bool $force_manual True when triggered manually by Admin button.
 * @return array{processed:int,sent_orders:int,skipped_hours:bool,window_str:string,errors:string[]}
 */
function nexxi_kids_run_zalo_order_scan( $force_manual = false ) {
	$cfg = nexxi_kids_zalo_notify_settings();
	if ( ! $force_manual && '1' !== $cfg['enabled'] ) {
		return array(
			'processed'     => 0,
			'sent_orders'   => 0,
			'skipped_hours' => false,
			'window_str'    => '',
			'errors'        => array(),
		);
	}

	if ( ! $force_manual && ! nexxi_kids_zalo_is_within_active_hours( $cfg ) ) {
		return array(
			'processed'     => 0,
			'sent_orders'   => 0,
			'skipped_hours' => true,
			'window_str'    => '',
			'errors'        => array(),
		);
	}

	$lock_key  = 'nexxi_zalo_scan_running_lock';
	$locked_ts = (int) get_transient( $lock_key );
	if ( ! $force_manual && $locked_ts > 0 && ( time() - $locked_ts ) < 90 ) {
		return array(
			'processed'     => 0,
			'sent_orders'   => 0,
			'skipped_hours' => false,
			'window_str'    => '',
			'errors'        => array(),
		);
	}
	set_transient( $lock_key, (string) time(), 90 );

	$now_ts  = time();
	$from_ts = (int) get_option( 'nexxi_zalo_last_scan_ts', 0 );
	if ( $from_ts <= 0 ) {
		$from_ts = $now_ts;
		update_option( 'nexxi_zalo_last_scan_ts', $now_ts, false );
	}

	// Determine if this scan is the morning summary of overnight/off-hours orders (e.g. 08:00 AM).
	$is_overnight = false;
	if ( '1' === (string) $cfg['active_hours_enabled'] ) {
		$tz          = wp_timezone();
		$now_dt      = ( new DateTimeImmutable( '@' . $now_ts ) )->setTimezone( $tz );
		$start_min   = nexxi_kids_zalo_time_to_minutes( $cfg['active_start_time'], 480 );
		$today_start = $now_dt->setTime( (int) floor( $start_min / 60 ), (int) ( $start_min % 60 ), 0 )->getTimestamp();
		if ( $from_ts < $today_start && $now_ts >= $today_start ) {
			$is_overnight = true;
		}
	}

	$pending       = nexxi_kids_get_pending_zalo_orders( 200, $from_ts, $now_ts );
	$pending_count = count( $pending );
	$window_str    = wp_date( 'H:i', $from_ts ) . ' ➔ ' . wp_date( 'H:i - d/m/Y', $now_ts );
	$sent_orders   = 0;
	$last_ord_ts   = 0;
	$errors        = array();

	if ( $pending_count > 0 ) {
		$batch_res   = nexxi_kids_send_zalo_batch_orders_notification( $pending, $from_ts, $now_ts, $is_overnight );
		$sent_orders = (int) $batch_res['notified_orders'];
		$last_ord_ts = (int) ( $batch_res['last_order_ts'] ?? 0 );
		$errors      = $batch_res['errors'];
	}

	// Advance checkpoint to $now_ts when there were no pending orders or all pending orders in the window were notified.
	if ( 0 === $pending_count || $sent_orders >= $pending_count ) {
		update_option( 'nexxi_zalo_last_scan_ts', $now_ts, false );
		update_option( 'nexxi_zalo_last_scan_time', wp_date( 'H:i:s - d/m/Y', $now_ts ), false );
		$next_ts = nexxi_kids_get_next_aligned_scan_timestamp( $cfg, $now_ts );
		update_option( 'nexxi_zalo_next_scan_ts', $next_ts, false );
		update_option( 'nexxi_zalo_last_scan_error', '', false );
	} else {
		if ( $sent_orders > 0 && $last_ord_ts > $from_ts ) {
			update_option( 'nexxi_zalo_last_scan_ts', $last_ord_ts, false );
			update_option( 'nexxi_zalo_last_scan_time', wp_date( 'H:i:s - d/m/Y', $now_ts ), false );
		}
		// Schedule a fast retry in 2 minutes so remaining orders are delivered promptly.
		update_option( 'nexxi_zalo_next_scan_ts', $now_ts + 120, false );
		if ( ! empty( $errors ) ) {
			update_option(
				'nexxi_zalo_last_scan_error',
				wp_date( 'H:i:s d/m/Y', $now_ts ) . ' — ' . implode( '; ', $errors ),
				false
			);
		}
	}

	delete_transient( $lock_key );

	return array(
		'processed'     => $pending_count,
		'sent_orders'   => $sent_orders,
		'skipped_hours' => false,
		'window_str'    => $window_str,
		'errors'        => $errors,
	);
}

/**
 * Queue a newly placed order and either send immediately (if interval=0 and within active hours)
 * or leave for the next scheduled batch scan / 08:00 AM overnight summary.
 *
 * @param int|WC_Order $order_or_id Order ID or WC_Order object.
 */
function nexxi_kids_handle_new_order_zalo_queue( $order_or_id ) {
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( 'yes' === (string) $order->get_meta( '_nexxi_zalo_warehouse_notified' ) ) {
		return;
	}

	// Ensure scan checkpoint is initialized so this new order is included in the current window.
	if ( (int) get_option( 'nexxi_zalo_last_scan_ts', 0 ) <= 0 ) {
		update_option( 'nexxi_zalo_last_scan_ts', time() - 5, false );
	}

	$order->update_meta_data( '_nexxi_zalo_warehouse_pending', 'yes' );
	$order->save_meta_data();

	$cfg = nexxi_kids_zalo_notify_settings();
	if ( '1' !== $cfg['enabled'] ) {
		return;
	}

	$interval_min = (int) $cfg['scan_interval'];
	$in_hours     = nexxi_kids_zalo_is_within_active_hours( $cfg );

	// Send immediately ONLY if interval is set to Instant (0) AND current time is within active hours.
	if ( $interval_min <= 0 && $in_hours ) {
		$oid_async = $order->get_id();
		if ( function_exists( 'nexxi_meta_capi_queue_job' ) && $oid_async > 0 ) {
			nexxi_meta_capi_queue_job( static function () use ( $oid_async ) {
				$fresh_order = wc_get_order( $oid_async );
				if ( $fresh_order instanceof WC_Order ) {
					nexxi_kids_send_zalo_order_notification( $fresh_order, false );
				}
			} );
		} else {
			nexxi_kids_send_zalo_order_notification( $order, false );
		}
	} else {
		$reason = ! $in_hours
			? sprintf( 'đặt ngoài khung giờ hoạt động (%s – %s), sẽ tổng hợp báo lúc %s sáng kế tiếp', $cfg['active_start_time'], $cfg['active_end_time'], $cfg['active_start_time'] )
			: sprintf( 'chờ tổng hợp ở đợt quét định kỳ (%d phút/lần)', $interval_min );
		$order->add_order_note( 'NEXXI Kho Zalo: Đơn hàng đã ghi nhận (' . $reason . ').' );
	}
}

// Trigger automatically when a new order is placed on Checkout.
add_action( 'woocommerce_checkout_order_processed', 'nexxi_kids_handle_new_order_zalo_queue', 25, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'nexxi_kids_handle_new_order_zalo_queue', 25, 1 );

/**
 * Register custom WP-Cron intervals & background scanner for Zalo order notifications.
 */
add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$mins = array( 5, 15, 30, 60, 120, 180 );
		foreach ( $mins as $m ) {
			$key = 'nexxi_zalo_every_' . $m . 'm';
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = array(
					'interval' => $m * MINUTE_IN_SECONDS,
					'display'  => sprintf( 'Mỗi %d phút (NEXXI Zalo Kho)', $m ),
				);
			}
		}
		return $schedules;
	}
);

add_action(
	'nexxi_zalo_order_scan_cron_event',
	static function () {
		nexxi_kids_run_zalo_order_scan( false );
	}
);

/**
 * Ensure WP-Cron schedule is aligned to exact clock boundaries (e.g. 08:00, 08:30, 09:00, 09:30...),
 * and run a lightweight timestamp check on `wp_loaded` so scans happen right on schedule without blocking page loads.
 *
 * @param bool $force_update True when settings were just saved in Admin.
 */
function nexxi_kids_sync_zalo_scan_cron( $force_update = false ) {
	$existing_next = (int) get_option( 'nexxi_zalo_next_scan_ts', 0 );
	if ( ! $force_update && $existing_next > 0 ) {
		return;
	}

	$cfg          = nexxi_kids_zalo_notify_settings( (bool) $force_update );
	$interval_min = (int) $cfg['scan_interval'];
	$eff_min      = $interval_min > 0 ? $interval_min : 15;
	$allowed      = array( 5, 15, 30, 60, 120, 180 );
	if ( ! in_array( $eff_min, $allowed, true ) ) {
		$eff_min = 15;
	}
	$sched_key = 'nexxi_zalo_every_' . $eff_min . 'm';
	$hook      = 'nexxi_zalo_order_scan_cron_event';

	if ( (int) get_option( 'nexxi_zalo_last_scan_ts', 0 ) <= 0 ) {
		update_option( 'nexxi_zalo_last_scan_ts', time(), false );
		update_option( 'nexxi_zalo_last_scan_time', wp_date( 'H:i:s - d/m/Y' ), false );
	}

	$next_aligned = nexxi_kids_get_next_aligned_scan_timestamp( $cfg, time() );
	update_option( 'nexxi_zalo_next_scan_ts', $next_aligned, false );

	if ( '1' === $cfg['enabled'] ) {
		$current_sched = wp_get_schedule( $hook );
		if ( $current_sched !== $sched_key || $force_update ) {
			wp_clear_scheduled_hook( $hook );
			wp_schedule_event( $next_aligned, $sched_key, $hook );
		}
	} else {
		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
add_action( 'admin_init', 'nexxi_kids_sync_zalo_scan_cron', 20 );

add_action(
	'wp_loaded',
	static function () {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( get_transient( 'nexxi_zalo_scan_running_lock' ) ) {
			return;
		}
		$cfg = nexxi_kids_zalo_notify_settings();
		if ( '1' !== $cfg['enabled'] || ! nexxi_kids_zalo_is_within_active_hours( $cfg ) ) {
			return;
		}
		$now_ts  = time();
		$next_ts = (int) get_option( 'nexxi_zalo_next_scan_ts', 0 );
		if ( $next_ts <= 0 ) {
			$next_ts = nexxi_kids_get_next_aligned_scan_timestamp( $cfg, $now_ts );
			update_option( 'nexxi_zalo_next_scan_ts', $next_ts, false );
			return;
		}
		if ( $now_ts >= $next_ts ) {
			// Set a short 90-second guard so concurrent page loads don't stampede while shutdown runs;
			// nexxi_kids_run_zalo_order_scan() will set the final aligned timestamp once completed.
			update_option( 'nexxi_zalo_next_scan_ts', $now_ts + 90, false );

			// Run the Zalo scan in shutdown AFTER flushing the HTTP response to the browser (zero page load delay)
			add_action(
				'shutdown',
				static function () {
					if ( function_exists( 'ignore_user_abort' ) ) {
						@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					if ( function_exists( 'litespeed_finish_request' ) ) {
						@litespeed_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
						@fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					nexxi_kids_run_zalo_order_scan( false );
				},
				999
			);
		}
	},
	30
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

		$cfg = nexxi_kids_zalo_notify_settings( true );

		// 0. Lightweight poll for newly added Chat IDs (when Webhook auto-add is active).
		if ( 'poll_saved_chats' === $sub_action ) {
			wp_send_json_success(
				array(
					'chat_ids'         => implode( "\n", nexxi_kids_zalo_parse_chat_ids() ),
					'known'            => get_option( 'nexxi_zalo_known_chats', array() ),
					'webhook_auto_add' => $cfg['webhook_auto_add'],
				)
			);
		}

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
				array( 'timeout' => 12 ),
				$cfg['bot_token'],
				16
			);

			if ( is_wp_error( $up_res ) ) {
				wp_send_json_error( array( 'message' => $up_res->get_error_message() ) );
			}

			$found = array();
			$list  = array();
			if ( isset( $up_res['result'] ) && is_array( $up_res['result'] ) ) {
				$list = ( isset( $up_res['result']['chat'] ) || isset( $up_res['result']['message'] ) || isset( $up_res['result']['sender'] ) )
					? array( $up_res['result'] )
					: $up_res['result'];
			}

			foreach ( $list as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$ext = nexxi_kids_zalo_extract_chat_from_update( $item );
				if ( $ext && '' !== $ext['chat_id'] ) {
					nexxi_kids_zalo_add_chat_id( $ext['chat_id'], $ext['name'] );
					$found[ $ext['chat_id'] ] = $ext['name'] ? $ext['name'] . ' (' . $ext['chat_id'] . ')' : $ext['chat_id'];
					nexxi_kids_zalo_bot_request(
						'sendMessage',
						array(
							'chat_id' => $ext['chat_id'],
							'text'    => "✅ NEXXI Kids Kho: Đã kết nối nhận thông báo đơn hàng thành công!\n• Mã Chat ID: " . $ext['chat_id'],
						),
						$cfg['bot_token'],
						8
					);
				}
			}

			$all_ids = implode( "\n", nexxi_kids_zalo_parse_chat_ids() );
			if ( ! empty( $found ) ) {
				wp_send_json_success(
					array(
						'message'          => '🎉 Đã bắt được tin nhắn và tự động điền Chat ID vào khung: ' . implode( ', ', array_values( $found ) ) . '. Hãy bấm Lưu cài đặt nhé!',
						'chat_ids'         => $all_ids,
						'known'            => get_option( 'nexxi_zalo_known_chats', array() ),
						'webhook_auto_add' => '0',
					)
				);
			}

			wp_send_json_error(
				array(
					'message'          => 'Chưa bắt được tin nhắn trong 12 giây vừa qua. Lưu ý: Trong Group Zalo, bạn phải gõ @Tên_Bot kèm nội dung (ví dụ: "@Tên_Bot kho") thì Bot mới nhận được tin nhắn nhé! (Cách nhanh nhất: Bật nút Webhook tự động bên cạnh rồi vào Group Zalo gõ @Tên_Bot kho).',
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

			if ( (int) $cfg['scan_interval'] > 0 ) {
				$test_from = time() - ( (int) $cfg['scan_interval'] * 60 ) + 60;
				$test_text = "🔔 [TEST] BÁO CÁO ĐƠN HÀNG MỚI ĐỢT QUÉT\n"
					. '⏰ Khung giờ quét: ' . wp_date( 'H:i', $test_from ) . ' ➔ ' . wp_date( 'H:i (d/m/Y)' ) . "\n"
					. "📦 Phát sinh mới: 1 ĐƠN HÀNG\n"
					. "🛍️ Tổng SL cần nhặt: 2 sản phẩm  |  💰 Tổng: 350.000đ\n"
					. "────────────────────\n"
					. '1. Đơn #9999 (' . wp_date( 'H:i' ) . ") — Mẹ Thanh Thảo (Đơn Test) - 0942958091\n"
					. "   • Đ/c: 53 Bà Huyện Thanh Quan, Phường Xuân Hòa, TP. Hồ Chí Minh\n"
					. "   • Tổng: 350.000đ (Thanh toán khi nhận hàng (COD))\n"
					. "────────────────────\n"
					. "👉 Kho vui lòng kiểm tra và xử lý đóng hàng cho 1 đơn trên!\n"
					. '🔗 Quản lý đơn Web: ' . admin_url( 'edit.php?post_type=shop_order' );
			} else {
				$test_text = "📦 [TEST] ĐƠN HÀNG MỚI #9999 — KHO XỬ LÝ ĐÓNG HÀNG\n"
					. '⏰ Thời gian: ' . wp_date( 'H:i - d/m/Y' ) . "\n"
					. "────────────────────\n"
					. "👤 Người nhận: Mẹ Thanh Thảo (Đơn Test)\n"
					. "📞 Điện thoại: 0942 958 091\n"
					. "📍 Địa chỉ giao: 53 Bà Huyện Thanh Quan, Phường Xuân Hòa, TP. Hồ Chí Minh\n"
					. "💳 Thanh toán: Thanh toán khi nhận hàng (COD)\n"
					. "────────────────────\n"
					. "🛍️ Tổng SL cần nhặt: 2 sản phẩm\n"
					. "🚚 Phí ship: Miễn phí\n"
					. "💰 TỔNG ĐƠN: 350.000đ\n"
					. '✅ Kết nối Bot Zalo Kho NEXXI hoạt động thành công!';
			}

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

		// 4. Manually scan & send all pending un-notified orders right now.
		if ( 'scan_pending_orders' === $sub_action ) {
			$scan_res  = nexxi_kids_run_zalo_order_scan( true );
			$rem_count = count( nexxi_kids_get_pending_zalo_orders( 200 ) );
			$last_time = (string) get_option( 'nexxi_zalo_last_scan_time', wp_date( 'H:i:s - d/m/Y' ) );
			$next_ts   = (int) get_option( 'nexxi_zalo_next_scan_ts', 0 );
			$next_time = $next_ts > 0 ? wp_date( 'H:i - d/m/Y', $next_ts ) : '';

			if ( $scan_res['processed'] <= 0 ) {
				wp_send_json_success(
					array(
						'message'       => '✔️ Đã quét kiểm tra (' . ( $scan_res['window_str'] ?? '' ) . '): Không có đơn hàng mới phát sinh từ mốc quét trước.',
						'pending_count' => $rem_count,
						'last_scan'     => $last_time,
						'next_scan'     => $next_time,
					)
				);
			}

			if ( $scan_res['sent_orders'] > 0 ) {
				$msg = sprintf( '🚀 Đã tổng hợp và gửi thành công báo cáo %d đơn hàng mới phát sinh (%s) vào Group Zalo Kho!', $scan_res['sent_orders'], $scan_res['window_str'] ?? '' );
				if ( ! empty( $scan_res['errors'] ) ) {
					$msg .= ' (Còn ' . $rem_count . ' đơn chờ gửi tiếp: ' . implode( '; ', $scan_res['errors'] ) . ')';
				}
				wp_send_json_success(
					array(
						'message'       => $msg,
						'pending_count' => $rem_count,
						'last_scan'     => $last_time,
						'next_scan'     => $next_time,
					)
				);
			}

			wp_send_json_error(
				array(
					'message'       => 'Không thể gửi thông báo cho đơn đang chờ. Chi tiết: ' . implode( '; ', $scan_res['errors'] ),
					'pending_count' => $rem_count,
					'last_scan'     => $last_time,
					'next_scan'     => $next_time,
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

		$interval_val = sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_scan_interval'] ?? '0' ) );
		if ( ! in_array( $interval_val, array( '0', '5', '15', '30', '60', '120', '180' ), true ) ) {
			$interval_val = '0';
		}
		update_option( 'nexxi_zalo_scan_interval', $interval_val );

		update_option( 'nexxi_zalo_active_hours_enabled', isset( $_POST['nexxi_zalo_active_hours_enabled'] ) ? '1' : '0' );
		$start_t = sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_active_start_time'] ?? '08:00' ) );
		$end_t   = sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_active_end_time'] ?? '17:30' ) );
		update_option( 'nexxi_zalo_active_start_time', preg_match( '/^\d{1,2}:\d{2}$/', $start_t ) ? $start_t : '08:00' );
		update_option( 'nexxi_zalo_active_end_time', preg_match( '/^\d{1,2}:\d{2}$/', $end_t ) ? $end_t : '17:30' );

		// Reset scan checkpoint to current time when saving so only orders generated from now onwards are scanned.
		update_option( 'nexxi_zalo_last_scan_ts', time(), false );
		update_option( 'nexxi_zalo_last_scan_time', wp_date( 'H:i:s - d/m/Y' ), false );
		update_option( 'nexxi_zalo_last_scan_error', '', false );

		nexxi_kids_sync_zalo_scan_cron( true );
		$saved = true;
	}

	$cfg             = nexxi_kids_zalo_notify_settings( $saved );
	$known_chats     = get_option( 'nexxi_zalo_known_chats', array() );
	$known_chats     = is_array( $known_chats ) ? $known_chats : array();
	$pending_cnt     = count( nexxi_kids_get_pending_zalo_orders( 200 ) );
	$last_scan_str   = (string) get_option( 'nexxi_zalo_last_scan_time', 'Chưa quét lần nào' );
	$last_scan_error = (string) get_option( 'nexxi_zalo_last_scan_error', '' );
	$next_scan_ts    = (int) get_option( 'nexxi_zalo_next_scan_ts', 0 );
	if ( $next_scan_ts <= 0 ) {
		$next_scan_ts = nexxi_kids_get_next_aligned_scan_timestamp( $cfg, time() );
	}
	$next_scan_str = wp_date( 'H:i - d/m/Y', $next_scan_ts );
	$in_hours_now  = nexxi_kids_zalo_is_within_active_hours( $cfg );
	$now_hm        = wp_date( 'H:i' );
	$nonce         = wp_create_nonce( 'nexxi_zalo_admin_nonce' );
	?>
	<div class="wrap" style="max-width:960px;margin-top:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<div style="background:linear-gradient(135deg,#031444 0%,#0068FF 100%);color:#fff;padding:24px 28px;border-radius:16px;box-shadow:0 10px 28px rgba(3,20,68,0.15);margin-bottom:20px;">
			<div style="display:inline-block;background:rgba(255,255,255,0.18);padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;margin-bottom:8px;">
				NEXXI Kids × Zalo Bot Platform
			</div>
			<h1 style="color:#fff;margin:0 0 6px;font-size:24px;font-weight:800;">🔔 Thông Báo Đơn Hàng Mới Qua Bot Zalo Cho Kho</h1>
			<p style="margin:0;color:rgba(255,255,255,0.88);font-size:14px;line-height:1.5;">
				Tự động gửi thông báo đơn hàng mới (Mã đơn, Khách hàng, SĐT, Địa chỉ giao, Tổng đơn &amp; Thanh toán) vào Zalo của Kho khi có đơn hàng mới trên Website.
			</p>
		</div>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><strong>✅ Đã lưu cài đặt Thông báo Zalo Kho thành công!</strong></p></div>
		<?php endif; ?>

		<?php if ( '' !== $last_scan_error ) : ?>
			<div class="notice notice-warning is-dismissible"><p><strong>⚠️ Lần quét Zalo gần nhất ghi nhận cảnh báo:</strong> <?php echo esc_html( $last_scan_error ); ?> — Bạn có thể bấm nút <strong>"🔄 Quét &amp; Gửi Ngay Các Đơn Đang Chờ"</strong> bên dưới để đẩy ngay các đơn đang chờ.</p></div>
		<?php endif; ?>

		<!-- 3-STEP QUICK GUIDE -->
		<div style="background:#fff8f6;border:1.5px solid #ffdad2;border-radius:14px;padding:18px 22px;margin-bottom:20px;">
			<h3 style="margin:0 0 10px;color:#ae3115;font-size:15px;">📋 Cách lấy Chat ID của Group Zalo (hoặc Cá nhân) tự động vào khung:</h3>
			<ol style="margin:0;padding-left:20px;color:#1b1c1a;font-size:13.5px;line-height:1.75;">
				<li><strong>Bước 1:</strong> Dán mã <strong>Bot Token</strong> vào ô số 1 rồi bấm nút <strong>"⚪ Bật chế độ Webhook tự động thêm nhân viên kho"</strong> (để nút chuyển sang màu xanh lá <strong>🟢 ĐANG BẬT</strong>).</li>
				<li><strong>Bước 2:</strong> Mở <strong>Group Zalo của Kho</strong> → Bấm <strong>Thêm thành viên</strong> để thêm con Bot Zalo của bạn vào Group.</li>
				<li><strong>Bước 3 (Quan trọng):</strong> Trong Group Zalo, bạn gõ <strong><code>@Tên_Bot kho</code></strong> (bắt buộc phải tag <code>@Tên_Bot</code> thì Bot trong Group mới nhận được lệnh). Ngay lập tức:
					<ul style="margin:4px 0 0;padding-left:18px;list-style:disc;">
						<li>Bot sẽ trả lời ngay trong Group Zalo mã <strong>Chat ID của Group</strong>.</li>
						<li>Và mã <strong>Chat ID của Group sẽ tự động nhảy vào khung số 2</strong> bên dưới (sau đó bạn có thể bấm Tắt chế độ Webhook và bấm <strong>💾 Lưu Cài Đặt</strong>).</li>
					</ul>
				</li>
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
					<th scope="row"><label for="nexxi_zalo_scan_interval">3. Tần suất quét &amp; thông báo đơn hàng</label></th>
					<td>
						<div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
							<select name="nexxi_zalo_scan_interval" id="nexxi_zalo_scan_interval" style="min-width:300px;padding:6px 12px;border-radius:8px;font-weight:600;color:#031444;">
								<option value="0" <?php selected( '0', $cfg['scan_interval'] ); ?>>⚡ Thông báo tức thì ngay khi khách đặt đơn (0 phút)</option>
								<option value="5" <?php selected( '5', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 5 phút</option>
								<option value="15" <?php selected( '15', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 15 phút</option>
								<option value="30" <?php selected( '30', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 30 phút</option>
								<option value="60" <?php selected( '60', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 1 giờ (60 phút)</option>
								<option value="120" <?php selected( '120', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 2 giờ (120 phút)</option>
								<option value="180" <?php selected( '180', $cfg['scan_interval'] ); ?>>⏱️ Quét &amp; thông báo mỗi 3 giờ (180 phút)</option>
							</select>

							<button type="button" id="btn-nexxi-zalo-scan-orders" class="button button-secondary" style="background:#fff4f0;border-color:#fd6a49;color:#ae3115;font-weight:700;padding:4px 14px;border-radius:8px;">
								🔄 Quét &amp; Gửi Ngay Các Đơn Đang Chờ (<span id="nexxi-zalo-pending-cnt"><?php echo (int) $pending_cnt; ?></span> đơn)
							</button>
						</div>
						<p class="description" style="margin-top:6px;line-height:1.6;">
							Chỉ thông báo những đơn hàng <strong>mới phát sinh kể từ thời điểm quét trước</strong> (VD: Đợt 1 từ <code>09:01 ➔ 09:30</code> có 10 đơn sẽ báo tổng hợp 10 đơn; Đợt 2 từ <code>09:31 ➔ 10:00</code> có 2 đơn sẽ báo tổng hợp 2 đơn).
							<br/>• Lần quét gần nhất: <strong id="nexxi-zalo-last-scan"><?php echo esc_html( $last_scan_str ); ?></strong>
							&nbsp;|&nbsp; • Đợt quét kế tiếp dự kiến: <strong id="nexxi-zalo-next-scan" style="color:#0068FF;"><?php echo esc_html( $next_scan_str ); ?></strong>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">4. Thời gian hoạt động của thông báo</th>
					<td>
						<div style="margin-bottom:10px;">
							<label style="font-weight:600;color:#031444;">
								<input type="checkbox" name="nexxi_zalo_active_hours_enabled" id="nexxi_zalo_active_hours_enabled" value="1" <?php checked( '1', $cfg['active_hours_enabled'] ); ?> />
								Bật giới hạn khung giờ hoạt động thông báo trong ngày (Tránh báo đơn làm phiền Kho vào ban đêm)
							</label>
						</div>

						<div id="nexxi-zalo-hours-wrap" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 16px;background:#f5f3ef;border-radius:10px;max-width:620px;<?php echo '1' === $cfg['active_hours_enabled'] ? '' : 'opacity:0.65;'; ?>">
							<span style="font-weight:700;color:#031444;">Hoạt động từ:</span>
							<input type="time" name="nexxi_zalo_active_start_time" id="nexxi_zalo_active_start_time" value="<?php echo esc_attr( $cfg['active_start_time'] ); ?>" style="padding:4px 10px;border-radius:8px;font-weight:700;color:#031444;" />
							<span style="font-weight:700;color:#031444;">đến:</span>
							<input type="time" name="nexxi_zalo_active_end_time" id="nexxi_zalo_active_end_time" value="<?php echo esc_attr( $cfg['active_end_time'] ); ?>" style="padding:4px 10px;border-radius:8px;font-weight:700;color:#031444;" />

							<span id="nexxi-zalo-hours-badge" data-now-hm="<?php echo esc_attr( $now_hm ); ?>" style="<?php
								if ( '1' === $cfg['active_hours_enabled'] ) {
									echo $in_hours_now
										? 'background:#e6f6ec;color:#0d5324;border:1px solid #34a853;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;'
										: 'background:#fff4e5;color:#8c4b00;border:1px solid #f59e0b;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;';
								} else {
									echo 'background:#eef4ff;color:#0068FF;border:1px solid #0068FF;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;';
								}
							?>">
								<?php if ( '1' === $cfg['active_hours_enabled'] ) : ?>
									<?php if ( $in_hours_now ) : ?>
										🟢 Đang trong giờ hoạt động (Hiện tại: <?php echo esc_html( $now_hm ); ?>)
									<?php else : ?>
										🌙 Ngoài giờ hoạt động • Sẽ tổng hợp báo 1 lần lúc <?php echo esc_html( $cfg['active_start_time'] ); ?> sáng kế tiếp
									<?php endif; ?>
								<?php else : ?>
									🌐 Đang hoạt động 24/24
								<?php endif; ?>
							</span>
						</div>
						<p class="description" style="margin-top:6px;">
							Các đơn hàng phát sinh <strong>ngoài khung giờ hoạt động</strong> sẽ được hệ thống <strong>tự động tổng hợp và thông báo 1 lần duy nhất vào lúc <?php echo esc_html( $cfg['active_start_time'] ); ?> sáng ngày kế tiếp</strong>!
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">5. Gửi kèm ảnh sản phẩm</th>
					<td>
						<label style="font-weight:600;color:#031444;">
							<input type="checkbox" name="nexxi_zalo_send_photos" value="1" <?php checked( '1', $cfg['send_photos'] ); ?> />
							Gửi kèm hình ảnh từng phân loại sản phẩm trong đơn vào Zalo để kho nhìn ảnh nhặt hàng chuẩn xác
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="nexxi_zalo_webhook_url">6. Webhook phụ trợ (Tùy chọn)</label></th>
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
					if (typeof res.data.pending_count !== 'undefined') {
						const pEl = document.getElementById('nexxi-zalo-pending-cnt');
						if (pEl) pEl.textContent = res.data.pending_count;
					}
					if (res.data.last_scan) {
						const lEl = document.getElementById('nexxi-zalo-last-scan');
						if (lEl) lEl.textContent = res.data.last_scan;
					}
					if (res.data.next_scan) {
						const nEl = document.getElementById('nexxi-zalo-next-scan');
						if (nEl) nEl.textContent = res.data.next_scan;
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

		const scanOrdersBtn = document.getElementById('btn-nexxi-zalo-scan-orders');
		if (scanOrdersBtn) {
			scanOrdersBtn.addEventListener('click', function() {
				runAction('scan_pending_orders', scanOrdersBtn, '⏳ Đang quét & gửi các đơn chờ...');
			});
		}

		const hoursChk = document.getElementById('nexxi_zalo_active_hours_enabled');
		const hoursWrap = document.getElementById('nexxi-zalo-hours-wrap');
		const startInput = document.getElementById('nexxi_zalo_active_start_time');
		const endInput = document.getElementById('nexxi_zalo_active_end_time');
		const hoursBadge = document.getElementById('nexxi-zalo-hours-badge');

		function toMin(hhmm, fallback) {
			if (!hhmm || typeof hhmm !== 'string') return fallback;
			const parts = hhmm.split(':');
			if (parts.length < 2) return fallback;
			return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
		}

		function syncHoursBadgeUI() {
			if (!hoursChk || !hoursWrap || !hoursBadge) return;
			const enabled = hoursChk.checked;
			hoursWrap.style.opacity = enabled ? '1' : '0.65';
			if (!enabled) {
				hoursBadge.style.background = '#eef4ff';
				hoursBadge.style.color = '#0068FF';
				hoursBadge.style.border = '1px solid #0068FF';
				hoursBadge.textContent = '🌐 Đang hoạt động 24/24';
				return;
			}
			const nowHm = hoursBadge.getAttribute('data-now-hm') || '12:00';
			const nowMin = toMin(nowHm, 720);
			const startStr = (startInput && startInput.value) ? startInput.value : '08:00';
			const endStr = (endInput && endInput.value) ? endInput.value : '17:00';
			const startMin = toMin(startStr, 480);
			const endMin = toMin(endStr, 1020);
			const inHours = startMin <= endMin
				? (nowMin >= startMin && nowMin <= endMin)
				: (nowMin >= startMin || nowMin <= endMin);

			if (inHours) {
				hoursBadge.style.background = '#e6f6ec';
				hoursBadge.style.color = '#0d5324';
				hoursBadge.style.border = '1px solid #34a853';
				hoursBadge.textContent = '🟢 Đang trong giờ hoạt động (' + startStr + ' – ' + endStr + ' | Hiện tại: ' + nowHm + ')';
			} else {
				hoursBadge.style.background = '#fff4e5';
				hoursBadge.style.color = '#8c4b00';
				hoursBadge.style.border = '1px solid #f59e0b';
				hoursBadge.textContent = '🌙 Ngoài giờ hoạt động • Sẽ tổng hợp báo 1 lần lúc ' + startStr + ' sáng kế tiếp';
			}
		}

		if (hoursChk) hoursChk.addEventListener('change', syncHoursBadgeUI);
		if (startInput) startInput.addEventListener('change', syncHoursBadgeUI);
		if (endInput) endInput.addEventListener('change', syncHoursBadgeUI);
		syncHoursBadgeUI();

		// Auto-poll every 3.5s while Webhook mode is ON so newly @mentioned Group/Staff Chat IDs appear in the box automatically!
		setInterval(function() {
			if (!whBtn || whBtn.getAttribute('data-webhook-active') !== '1') return;
			const fd = new FormData();
			fd.append('action', 'nexxi_zalo_admin_action');
			fd.append('nonce', nonce);
			fd.append('sub_action', 'poll_saved_chats');
			fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res && res.success && res.data && typeof res.data.chat_ids === 'string' && chatIdsInput) {
						const serverIds = res.data.chat_ids.trim();
						if (serverIds !== '' && serverIds !== chatIdsInput.value.trim()) {
							chatIdsInput.value = serverIds;
							showNotice('🎉 Đã tự động nhận diện và điền Chat ID mới từ Zalo vào khung! Bạn hãy bấm Lưu cài đặt nhé.', true);
						}
					}
				})
				.catch(function() {});
		}, 3500);
	})();
	</script>
	<?php
}
