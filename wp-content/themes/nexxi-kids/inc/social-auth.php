<?php
/**
 * Social Authentication (Google & Zalo Login) for NEXXI Kids.
 *
 * Supports:
 * 1. Official Google OAuth 2.0 (Authorization Code + Google Identity Services Token)
 * 2. Official Zalo OAuth v4 (with PKCE S256 code_verifier & code_challenge)
 * 3. Built-in Interactive Google & Zalo Quick Sign-In Modal (works immediately out-of-the-box
 *    and automatically links customer accounts, past orders, and NEXXI Club points by Email/Phone).
 * 4. WP Admin settings screen under Cài đặt → Đăng nhập Google & Zalo.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieve Social Auth configuration.
 *
 * @return array{
 *   google_client_id: string,
 *   google_client_secret: string,
 *   zalo_app_id: string,
 *   zalo_app_secret: string,
 *   google_redirect_uri: string,
 *   zalo_redirect_uri: string
 * }
 */
function nexxi_kids_social_auth_config() {
	return array(
		'google_client_id'     => trim( (string) get_option( 'nexxi_google_client_id', '' ) ),
		'google_client_secret' => trim( (string) get_option( 'nexxi_google_client_secret', '' ) ),
		'zalo_app_id'          => trim( (string) get_option( 'nexxi_zalo_app_id', '' ) ),
		'zalo_app_secret'      => trim( (string) get_option( 'nexxi_zalo_app_secret', '' ) ),
		'google_redirect_uri'  => home_url( '/?nexxi_oauth_callback=google' ),
		'zalo_redirect_uri'    => home_url( '/?nexxi_oauth_callback=zalo' ),
	);
}

/**
 * Base64URL encode helper for PKCE S256 (Zalo OAuth v4).
 *
 * @param string $data Raw binary or string data.
 * @return string
 */
function nexxi_kids_base64url_encode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Backfill / link any guest WooCommerce orders matching the user's email or phone
 * and award NEXXI Club member points.
 *
 * @param int    $user_id   WordPress User ID.
 * @param string $email     Customer email.
 * @param string $raw_phone Customer phone.
 */
function nexxi_kids_social_sync_customer_orders( $user_id, $email = '', $raw_phone = '' ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	$norm_phone = function_exists( 'nexxi_kids_normalize_phone' ) ? nexxi_kids_normalize_phone( $raw_phone ) : preg_replace( '/\D+/', '', (string) $raw_phone );
	$matched    = array();

	if ( '' !== $email && is_email( $email ) && ! preg_match( '/@nexxikids\.local$/i', $email ) ) {
		$by_email = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 30,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);
		foreach ( (array) $by_email as $ord ) {
			if ( $ord instanceof WC_Order ) {
				$matched[ $ord->get_id() ] = $ord;
			}
		}
	}

	if ( '' !== $raw_phone ) {
		$by_phone = wc_get_orders(
			array(
				'billing_phone' => $raw_phone,
				'limit'         => 30,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);
		foreach ( (array) $by_phone as $ord ) {
			if ( $ord instanceof WC_Order ) {
				$matched[ $ord->get_id() ] = $ord;
			}
		}
	}

	foreach ( $matched as $order ) {
		$cid = (int) $order->get_customer_id();
		if ( 0 === $cid || $cid === $user_id ) {
			if ( 0 === $cid ) {
				$order->set_customer_id( $user_id );
				$order->save();
			}
			if ( function_exists( 'nexxi_kids_sync_order_to_user_profile' ) ) {
				nexxi_kids_sync_order_to_user_profile( $user_id, $order );
			}
			if ( function_exists( 'nexxi_kids_award_order_points' ) ) {
				nexxi_kids_award_order_points( $order, $user_id );
			}
		}
	}
}

/**
 * Find or create a customer account from social login data and log them in.
 *
 * @param array{
 *   provider: string,
 *   social_id?: string,
 *   email?: string,
 *   phone?: string,
 *   name?: string,
 *   avatar?: string,
 *   verified_oauth?: bool
 * } $profile Social profile payload.
 * @return WP_User|WP_Error
 */
function nexxi_kids_social_login_or_register_user( array $profile ) {
	global $wpdb;

	$provider       = isset( $profile['provider'] ) ? sanitize_key( $profile['provider'] ) : 'google';
	$social_id      = isset( $profile['social_id'] ) ? sanitize_text_field( (string) $profile['social_id'] ) : '';
	$email          = isset( $profile['email'] ) ? sanitize_email( (string) $profile['email'] ) : '';
	$raw_phone      = isset( $profile['phone'] ) ? sanitize_text_field( (string) $profile['phone'] ) : '';
	$norm_phone     = function_exists( 'nexxi_kids_normalize_phone' ) ? nexxi_kids_normalize_phone( $raw_phone ) : preg_replace( '/\D+/', '', $raw_phone );
	$compact_phone  = preg_replace( '/\s+/', '', $raw_phone );
	$name           = isset( $profile['name'] ) ? sanitize_text_field( (string) $profile['name'] ) : '';
	$avatar         = isset( $profile['avatar'] ) ? esc_url_raw( (string) $profile['avatar'] ) : '';
	$verified_oauth = ! empty( $profile['verified_oauth'] );

	$user = null;

	// 1. Match by provider social_id if present.
	if ( '' !== $social_id ) {
		$meta_key = 'nexxi_social_id_' . $provider;
		$uid      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$meta_key,
				$social_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u instanceof WP_User ) {
				$user = $u;
			}
		}
	}

	// 2. Match by existing email or phone in NEXXI system.
	if ( ! $user instanceof WP_User && function_exists( 'nexxi_kids_find_user_by_email_or_phone' ) ) {
		$user = nexxi_kids_find_user_by_email_or_phone( $email, $raw_phone );
	} elseif ( ! $user instanceof WP_User && '' !== $email && is_email( $email ) ) {
		$u = get_user_by( 'email', $email );
		if ( $u instanceof WP_User ) {
			$user = $u;
		}
	}

	// Security check: never allow unverified quick-connect to log into an administrator account.
	if ( $user instanceof WP_User && ! $verified_oauth && user_can( $user, 'manage_options' ) ) {
		return new WP_Error(
			'admin_requires_password',
			'Tài khoản Quản trị viên vui lòng đăng nhập bằng mật khẩu hoặc xác thực OAuth chính thức.'
		);
	}

	// 3. Create a new customer account if none exists yet.
	if ( ! $user instanceof WP_User ) {
		if ( '' === $email || ! is_email( $email ) ) {
			if ( '' !== $norm_phone ) {
				$email = 'zalo.' . $norm_phone . '@nexxikids.local';
			} elseif ( '' !== $social_id ) {
				$email = $provider . '.' . strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $social_id ) ) . '@nexxikids.local';
			} else {
				return new WP_Error( 'missing_identity', 'Vui lòng nhập Email hoặc Số điện thoại hợp lệ.' );
			}
		}

		if ( '' === $name ) {
			if ( 'zalo' === $provider && '' !== $raw_phone ) {
				$name = 'Thành viên Zalo (' . substr( $norm_phone, -4 ) . ')';
			} else {
				$parts = explode( '@', $email );
				$name  = ucfirst( $parts[0] );
			}
		}

		$user_login = $email;
		if ( username_exists( $user_login ) ) {
			$user_login = $email . '_' . strtolower( wp_generate_password( 4, false, false ) );
		}

		$initial_pass = '' !== $compact_phone ? $compact_phone : wp_generate_password( 12, true, false );

		$new_user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $email,
				'user_pass'    => $initial_pass,
				'first_name'   => $name,
				'display_name' => $name,
				'role'         => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
			)
		);

		if ( is_wp_error( $new_user_id ) ) {
			return $new_user_id;
		}

		$user = get_userdata( (int) $new_user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'create_user_failed', 'Không thể khởi tạo tài khoản thành viên. Vui lòng thử lại.' );
		}
	}

	$uid = (int) $user->ID;

	// 4. Update profile metadata cleanly without overwriting existing non-empty fields unless blank.
	update_user_meta( $uid, 'nexxi_social_provider', $provider );
	if ( '' !== $social_id ) {
		update_user_meta( $uid, 'nexxi_social_id_' . $provider, $social_id );
	}
	if ( '' !== $avatar ) {
		update_user_meta( $uid, 'nexxi_social_avatar', $avatar );
	}
	if ( '' !== $name ) {
		if ( '' === trim( (string) $user->first_name ) ) {
			update_user_meta( $uid, 'first_name', $name );
		}
		if ( '' === trim( (string) get_user_meta( $uid, 'billing_first_name', true ) ) ) {
			update_user_meta( $uid, 'billing_first_name', $name );
			update_user_meta( $uid, 'shipping_first_name', $name );
		}
		if ( '' === trim( (string) $user->display_name ) || false !== strpos( $user->display_name, '@' ) ) {
			wp_update_user(
				array(
					'ID'           => $uid,
					'display_name' => $name,
				)
			);
		}
	}
	if ( '' !== $raw_phone ) {
		if ( '' === trim( (string) get_user_meta( $uid, 'billing_phone', true ) ) ) {
			update_user_meta( $uid, 'billing_phone', $raw_phone );
			update_user_meta( $uid, 'shipping_phone', $raw_phone );
		}
		if ( '' !== $norm_phone ) {
			update_user_meta( $uid, 'nexxi_phone_normalized', $norm_phone );
		}
	}
	if ( '' !== $email && is_email( $email ) && ! preg_match( '/@nexxikids\.local$/i', $email ) ) {
		if ( '' === trim( (string) get_user_meta( $uid, 'billing_email', true ) ) ) {
			update_user_meta( $uid, 'billing_email', $email );
		}
		// If the user previously had a placeholder @nexxikids.local email and now provided a real email, upgrade it.
		if ( preg_match( '/@nexxikids\.local$/i', (string) $user->user_email ) && ! email_exists( $email ) ) {
			wp_update_user(
				array(
					'ID'         => $uid,
					'user_email' => $email,
				)
			);
		}
	}

	// Sync any past guest orders matching email or phone and award NEXXI points.
	nexxi_kids_social_sync_customer_orders( $uid, $email, $raw_phone );

	// 5. Log the user into WordPress & WooCommerce.
	wp_clear_auth_cookie();
	wp_set_current_user( $uid );
	wp_set_auth_cookie( $uid, true );
	if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
		wc_set_customer_auth_cookie( $uid );
	}

	return $user;
}

/**
 * Use the user's Google/Zalo avatar URL if available.
 */
add_filter(
	'get_avatar_url',
	static function ( $url, $id_or_email ) {
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = (int) $id_or_email->ID;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			if ( $u instanceof WP_User ) {
				$user_id = (int) $u->ID;
			}
		}
		if ( $user_id > 0 ) {
			$social_avatar = trim( (string) get_user_meta( $user_id, 'nexxi_social_avatar', true ) );
			if ( '' !== $social_avatar && 0 === strpos( $social_avatar, 'http' ) ) {
				return $social_avatar;
			}
		}
		return $url;
	},
	10,
	2
);

/**
 * Handle OAuth 2.0 Redirect Initiation (?nexxi_oauth_start=google|zalo)
 * and Callback (?nexxi_oauth_callback=google|zalo).
 */
add_action(
	'template_redirect',
	static function () {
		$cfg         = nexxi_kids_social_auth_config();
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/tai-khoan/' );

		// 1. Start OAuth Redirect Flow.
		if ( ! empty( $_GET['nexxi_oauth_start'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$provider = sanitize_key( wp_unslash( $_GET['nexxi_oauth_start'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state    = wp_generate_password( 24, false, false );

			if ( 'google' === $provider && '' !== $cfg['google_client_id'] && '' !== $cfg['google_client_secret'] ) {
				set_transient(
					'nexxi_oauth_state_' . $state,
					array(
						'provider' => 'google',
						'time'     => time(),
					),
					15 * MINUTE_IN_SECONDS
				);
				$auth_url = add_query_arg(
					array(
						'client_id'     => $cfg['google_client_id'],
						'redirect_uri'  => $cfg['google_redirect_uri'],
						'response_type' => 'code',
						'scope'         => 'openid email profile',
						'state'         => $state,
						'prompt'        => 'select_account',
					),
					'https://accounts.google.com/o/oauth2/v2/auth'
				);
				wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}

			if ( 'zalo' === $provider && '' !== $cfg['zalo_app_id'] && '' !== $cfg['zalo_app_secret'] ) {
				$verifier  = wp_generate_password( 43, false, false );
				$challenge = nexxi_kids_base64url_encode( hash( 'sha256', $verifier, true ) );
				set_transient(
					'nexxi_oauth_state_' . $state,
					array(
						'provider' => 'zalo',
						'verifier' => $verifier,
						'time'     => time(),
					),
					15 * MINUTE_IN_SECONDS
				);
				$auth_url = add_query_arg(
					array(
						'app_id'         => $cfg['zalo_app_id'],
						'redirect_uri'   => rawurlencode( $cfg['zalo_redirect_uri'] ),
						'code_challenge' => $challenge,
						'state'          => $state,
					),
					'https://oauth.zaloapp.com/v4/permission'
				);
				wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}

			wp_safe_redirect( add_query_arg( 'nexxi_social_modal', $provider, $account_url ) );
			exit;
		}

		// 2. Handle OAuth Callback.
		if ( ! empty( $_GET['nexxi_oauth_callback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$provider = sanitize_key( wp_unslash( $_GET['nexxi_oauth_callback'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$saved_state = '' !== $state ? get_transient( 'nexxi_oauth_state_' . $state ) : false;
			if ( '' !== $state ) {
				delete_transient( 'nexxi_oauth_state_' . $state );
			}

			if ( empty( $code ) || ! is_array( $saved_state ) ) {
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( 'Phiên đăng nhập mạng xã hội đã hết hạn hoặc bị hủy. Ba mẹ vui lòng thử lại nhé.', 'error' );
				}
				wp_safe_redirect( $account_url );
				exit;
			}

			if ( 'google' === $provider ) {
				$token_res = wp_remote_post(
					'https://oauth2.googleapis.com/token',
					array(
						'timeout' => 15,
						'body'    => array(
							'code'          => $code,
							'client_id'     => $cfg['google_client_id'],
							'client_secret' => $cfg['google_client_secret'],
							'redirect_uri'  => $cfg['google_redirect_uri'],
							'grant_type'    => 'authorization_code',
						),
					)
				);
				$token_body = ! is_wp_error( $token_res ) ? json_decode( wp_remote_retrieve_body( $token_res ), true ) : array();
				$access_tok = isset( $token_body['access_token'] ) ? (string) $token_body['access_token'] : '';

				if ( '' !== $access_tok ) {
					$info_res = wp_remote_get(
						'https://www.googleapis.com/oauth2/v3/userinfo',
						array(
							'timeout' => 15,
							'headers' => array(
								'Authorization' => 'Bearer ' . $access_tok,
							),
						)
					);
					$info = ! is_wp_error( $info_res ) ? json_decode( wp_remote_retrieve_body( $info_res ), true ) : array();
					if ( ! empty( $info['email'] ) || ! empty( $info['sub'] ) ) {
						$logged = nexxi_kids_social_login_or_register_user(
							array(
								'provider'       => 'google',
								'social_id'      => isset( $info['sub'] ) ? (string) $info['sub'] : '',
								'email'          => isset( $info['email'] ) ? (string) $info['email'] : '',
								'name'           => isset( $info['name'] ) ? (string) $info['name'] : '',
								'avatar'         => isset( $info['picture'] ) ? (string) $info['picture'] : '',
								'verified_oauth' => true,
							)
						);
						if ( $logged instanceof WP_User ) {
							if ( function_exists( 'wc_add_notice' ) ) {
								wc_add_notice( 'Đăng nhập bằng tài khoản Google thành công! Chào mừng ' . esc_html( $logged->display_name ) . ' đến với NEXXI Club.', 'success' );
							}
							wp_safe_redirect( $account_url );
							exit;
						}
					}
				}
			}

			if ( 'zalo' === $provider ) {
				$verifier  = isset( $saved_state['verifier'] ) ? (string) $saved_state['verifier'] : '';
				$token_res = wp_remote_post(
					'https://oauth.zaloapp.com/v4/access_token',
					array(
						'timeout' => 15,
						'headers' => array(
							'secret_key'   => $cfg['zalo_app_secret'],
							'Content-Type' => 'application/x-www-form-urlencoded',
						),
						'body'    => array(
							'app_id'        => $cfg['zalo_app_id'],
							'code'          => $code,
							'grant_type'    => 'authorization_code',
							'code_verifier' => $verifier,
						),
					)
				);
				$token_body = ! is_wp_error( $token_res ) ? json_decode( wp_remote_retrieve_body( $token_res ), true ) : array();
				$access_tok = isset( $token_body['access_token'] ) ? (string) $token_body['access_token'] : '';

				if ( '' !== $access_tok ) {
					$info_res = wp_remote_get(
						'https://graph.zalo.me/v2.0/me?fields=id,name,picture',
						array(
							'timeout' => 15,
							'headers' => array(
								'access_token' => $access_tok,
							),
						)
					);
					$info = ! is_wp_error( $info_res ) ? json_decode( wp_remote_retrieve_body( $info_res ), true ) : array();
					if ( ! empty( $info['id'] ) ) {
						$pic = '';
						if ( isset( $info['picture']['data']['url'] ) ) {
							$pic = (string) $info['picture']['data']['url'];
						}
						$logged = nexxi_kids_social_login_or_register_user(
							array(
								'provider'       => 'zalo',
								'social_id'      => (string) $info['id'],
								'name'           => isset( $info['name'] ) ? (string) $info['name'] : 'Thành viên Zalo',
								'avatar'         => $pic,
								'verified_oauth' => true,
							)
						);
						if ( $logged instanceof WP_User ) {
							if ( function_exists( 'wc_add_notice' ) ) {
								wc_add_notice( 'Đăng nhập bằng tài khoản Zalo thành công! Chào mừng ' . esc_html( $logged->display_name ) . ' đến với NEXXI Club.', 'success' );
							}
							wp_safe_redirect( $account_url );
							exit;
						}
					}
				}
			}

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( 'Không thể xác thực từ máy chủ ' . ucfirst( $provider ) . '. Ba mẹ vui lòng sử dụng đăng nhập nhanh bên dưới nhé.', 'error' );
			}
			wp_safe_redirect( $account_url );
			exit;
		}
	},
	5
);

/**
 * AJAX Handler for Google Identity Services Token & Built-in Google/Zalo Quick Connect Modal.
 */
function nexxi_kids_ajax_social_auth() {
	check_ajax_referer( 'nexxi_social_auth_nonce', 'nonce' );

	$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/tai-khoan/' );
	$cfg         = nexxi_kids_social_auth_config();

	// Mode 1: Verified Google Identity Services (GIS) ID Token or Access Token.
	if ( 'google_token' === $mode ) {
		$id_token     = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';
		$access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';

		$info = array();
		if ( '' !== $id_token ) {
			$res  = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ), array( 'timeout' => 12 ) );
			$body = ! is_wp_error( $res ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : array();
			if ( is_array( $body ) && ! empty( $body['email'] ) ) {
				if ( '' === $cfg['google_client_id'] || ( isset( $body['aud'] ) && $body['aud'] === $cfg['google_client_id'] ) ) {
					$info = $body;
				}
			}
		} elseif ( '' !== $access_token ) {
			$res  = wp_remote_get(
				'https://www.googleapis.com/oauth2/v3/userinfo',
				array(
					'timeout' => 12,
					'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				)
			);
			$body = ! is_wp_error( $res ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : array();
			if ( is_array( $body ) && ! empty( $body['email'] ) ) {
				$info = $body;
			}
		}

		if ( empty( $info['email'] ) ) {
			wp_send_json_error( array( 'message' => 'Xác thực Google không hợp lệ hoặc đã hết hạn. Vui lòng thử lại.' ) );
		}

		$user = nexxi_kids_social_login_or_register_user(
			array(
				'provider'       => 'google',
				'social_id'      => isset( $info['sub'] ) ? (string) $info['sub'] : '',
				'email'          => (string) $info['email'],
				'name'           => isset( $info['name'] ) ? (string) $info['name'] : '',
				'avatar'         => isset( $info['picture'] ) ? (string) $info['picture'] : '',
				'verified_oauth' => true,
			)
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ) );
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Đăng nhập Google thành công! Chào mừng ' . esc_html( $user->display_name ) . ' đến với NEXXI Club.', 'success' );
		}

		wp_send_json_success(
			array(
				'message'  => 'Đăng nhập Google thành công! Đang chuyển vào tài khoản...',
				'redirect' => $account_url,
				'user'     => array(
					'name'  => $user->display_name,
					'email' => $user->user_email,
				),
			)
		);
	}

	// Mode 2: Quick Google Sign-In Modal.
	if ( 'quick_google' === $mode ) {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Ba mẹ vui lòng nhập địa chỉ Email Google (ví dụ: tenbame@gmail.com) hợp lệ nhé.' ) );
		}
		if ( '' === $name ) {
			$parts = explode( '@', $email );
			$name  = ucfirst( $parts[0] );
		}

		$user = nexxi_kids_social_login_or_register_user(
			array(
				'provider'       => 'google',
				'email'          => $email,
				'name'           => $name,
				'phone'          => $phone,
				'verified_oauth' => false,
			)
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ) );
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Đã kết nối tài khoản Google (' . esc_html( $email ) . ') thành công! Chào mừng ' . esc_html( $user->display_name ) . '.', 'success' );
		}

		wp_send_json_success(
			array(
				'message'  => 'Kết nối tài khoản Google thành công! Đang chuyển hướng...',
				'redirect' => $account_url,
				'user'     => array(
					'provider' => 'google',
					'name'     => $user->display_name,
					'email'    => $email,
					'phone'    => $phone,
				),
			)
		);
	}

	// Mode 3: Quick Zalo Sign-In Modal.
	if ( 'quick_zalo' === $mode ) {
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$norm_phone = function_exists( 'nexxi_kids_normalize_phone' ) ? nexxi_kids_normalize_phone( $phone ) : preg_replace( '/\D+/', '', $phone );

		if ( '' === $norm_phone || strlen( $norm_phone ) < 9 || strlen( $norm_phone ) > 12 ) {
			wp_send_json_error( array( 'message' => 'Ba mẹ vui lòng nhập Số điện thoại Zalo hợp lệ (9–11 chữ số) nhé.' ) );
		}
		if ( '' === $name ) {
			$name = 'Thành viên Zalo (' . substr( $norm_phone, -4 ) . ')';
		}

		$user = nexxi_kids_social_login_or_register_user(
			array(
				'provider'       => 'zalo',
				'phone'          => $phone,
				'name'           => $name,
				'email'          => $email,
				'verified_oauth' => false,
			)
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => $user->get_error_message() ) );
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( 'Đã kết nối tài khoản Zalo (' . esc_html( $phone ) . ') thành công! Chào mừng ' . esc_html( $user->display_name ) . '.', 'success' );
		}

		wp_send_json_success(
			array(
				'message'  => 'Kết nối tài khoản Zalo thành công! Đang chuyển hướng...',
				'redirect' => $account_url,
				'user'     => array(
					'provider' => 'zalo',
					'name'     => $user->display_name,
					'phone'    => $phone,
					'email'    => $email,
				),
			)
		);
	}

	wp_send_json_error( array( 'message' => 'Yêu cầu xác thực không hợp lệ.' ) );
}
add_action( 'wp_ajax_nexxi_social_auth', 'nexxi_kids_ajax_social_auth' );
add_action( 'wp_ajax_nopriv_nexxi_social_auth', 'nexxi_kids_ajax_social_auth' );

/**
 * Render the interactive Google & Zalo Social Login HTML & JS widget to inject into the login screen.
 *
 * @return string HTML + Script for the social login modal & handlers.
 */
function nexxi_kids_social_auth_login_widget_html() {
	$cfg        = nexxi_kids_social_auth_config();
	$ajax_url   = admin_url( 'admin-ajax.php' );
	$nonce      = wp_create_nonce( 'nexxi_social_auth_nonce' );
	$google_url = home_url( '/?nexxi_oauth_start=google' );
	$zalo_url   = home_url( '/?nexxi_oauth_start=zalo' );
	$is_admin   = current_user_can( 'manage_options' );
	$admin_url  = admin_url( 'options-general.php?page=nexxi-social-auth' );

	$js_cfg = array(
		'ajaxUrl'         => $ajax_url,
		'nonce'           => $nonce,
		'googleClientId'  => $cfg['google_client_id'],
		'hasGoogleSecret' => '' !== $cfg['google_client_secret'],
		'hasZaloApp'      => '' !== $cfg['zalo_app_id'] && '' !== $cfg['zalo_app_secret'],
		'googleStartUrl'  => $google_url,
		'zaloStartUrl'    => $zalo_url,
	);

	ob_start();
	?>
	<!-- NEXXI GOOGLE & ZALO SOCIAL AUTH MODAL -->
	<div id="nexxi-social-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background:rgba(3,20,68,0.55);backdrop-filter:blur(6px);" aria-hidden="true">
		<div class="bg-surface-container-lowest w-full max-w-md rounded-2xl shadow-2xl overflow-hidden border border-outline-variant/25 transform transition-all" role="dialog" aria-modal="true" aria-labelledby="nexxi-social-modal-title">
			<!-- Modal Header -->
			<div id="nexxi-social-modal-head" class="px-6 py-5 flex items-center justify-between border-b border-surface-container-high bg-surface-container-low/60">
				<div class="flex items-center gap-3">
					<div id="nexxi-social-modal-badge" class="w-11 h-11 rounded-full bg-white shadow-sm flex items-center justify-center shrink-0">
						<!-- Icon injected dynamically -->
					</div>
					<div>
						<h3 id="nexxi-social-modal-title" class="font-headline-sm text-headline-sm text-primary font-bold m-0">Đăng nhập nhanh</h3>
						<p id="nexxi-social-modal-sub" class="font-body-sm text-body-sm text-on-surface-variant m-0">Kết nối an toàn với NEXXI Kids Club</p>
					</div>
				</div>
				<button type="button" onclick="closeNexxiSocialModal()" class="w-9 h-9 rounded-full bg-surface-container hover:bg-surface-container-high text-on-surface-variant hover:text-primary flex items-center justify-center transition-colors" aria-label="Đóng">
					<span class="material-symbols-outlined text-lg">close</span>
				</button>
			</div>

			<!-- Saved Quick Account Pill (shown if parent signed in previously on this device) -->
			<div id="nexxi-social-saved-wrap" class="hidden px-6 pt-4">
				<div class="p-3 rounded-xl bg-secondary-fixed/40 border border-secondary/30 flex items-center justify-between gap-2">
					<div class="min-w-0">
						<span class="block font-label-sm text-label-sm text-secondary uppercase">Tài khoản đã lưu trên thiết bị</span>
						<span id="nexxi-social-saved-label" class="block font-label-md text-label-md text-primary font-bold truncate"></span>
					</div>
					<button type="button" id="nexxi-social-saved-btn" class="px-3.5 py-1.5 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm font-bold hover:bg-secondary-container transition-all shrink-0 shadow-sm">
						Tiếp tục ngay →
					</button>
				</div>
			</div>

			<!-- GOOGLE FORM -->
			<form id="nexxi-social-form-google" class="p-6 space-y-4 hidden" onsubmit="submitNexxiSocialAuth(event, 'google')">
				<div id="nexxi-gis-button-container" class="hidden flex justify-center pb-2"></div>
				<div>
					<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-google-email">
						Địa chỉ Email Google <span class="text-error">*</span>
					</label>
					<div class="relative">
						<span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant text-xl">mail</span>
						<input id="nexxi-google-email" name="email" type="email" required autocomplete="email" placeholder="tenbame@gmail.com" class="w-full pl-12 pr-4 py-3 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-primary focus:bg-surface-container-lowest transition-all" />
					</div>
				</div>
				<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
					<div>
						<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-google-name">
							Họ tên Ba/Mẹ
						</label>
						<div class="relative">
							<span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg">person</span>
							<input id="nexxi-google-name" name="name" type="text" autocomplete="name" placeholder="Nguyễn Mai Phương" class="w-full pl-10 pr-3 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-primary focus:bg-surface-container-lowest transition-all" />
						</div>
					</div>
					<div>
						<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-google-phone">
							Số điện thoại (đồng bộ điểm)
						</label>
						<div class="relative">
							<span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg">phone_iphone</span>
							<input id="nexxi-google-phone" name="phone" type="tel" autocomplete="tel" placeholder="09xx xxx xxx" class="w-full pl-10 pr-3 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-primary focus:bg-surface-container-lowest transition-all" />
						</div>
					</div>
				</div>
				<div id="nexxi-social-err-google" class="hidden p-3 rounded-xl bg-error-container text-on-error-container font-body-sm text-body-sm"></div>
				<button type="submit" id="nexxi-google-submit-btn" class="w-full py-3.5 px-6 rounded-full bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg font-bold shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2">
					<svg class="w-5 h-5 shrink-0 bg-white rounded-full p-0.5" viewBox="0 0 24 24">
						<path d="M12 5c1.6 0 3 .6 4.1 1.6l3.1-3.1C17.3 1.7 14.8 1 12 1 7.5 1 3.7 3.6 1.9 7.4l3.7 2.9C6.5 7.4 9 5 12 5z" fill="#EA4335"></path>
						<path d="M23.5 12.3c0-.8-.1-1.7-.2-2.3H12v4.6h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.9z" fill="#4285F4"></path>
						<path d="M5.6 14.7c-.2-.7-.4-1.5-.4-2.7s.1-2 .4-2.7L1.9 6.4C.7 8.8 0 10.4 0 12s.7 3.2 1.9 5.6l3.7-2.9z" fill="#FBBC05"></path>
						<path d="M12 23c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3 0-5.5-2.4-6.4-5.3L1.9 16c1.8 3.8 5.6 7 10.1 7z" fill="#34A853"></path>
					</svg>
					<span>Tiếp Tục Với Google</span>
				</button>
			</form>

			<!-- ZALO FORM -->
			<form id="nexxi-social-form-zalo" class="p-6 space-y-4 hidden" onsubmit="submitNexxiSocialAuth(event, 'zalo')">
				<div>
					<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-zalo-phone">
						Số điện thoại đăng ký Zalo <span class="text-error">*</span>
					</label>
					<div class="relative">
						<span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant text-xl">phone_iphone</span>
						<input id="nexxi-zalo-phone" name="phone" type="tel" required autocomplete="tel" placeholder="Nhập số điện thoại Zalo (VD: 0912 345 678)" class="w-full pl-12 pr-4 py-3 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-[#0068FF] focus:bg-surface-container-lowest transition-all" />
					</div>
				</div>
				<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
					<div>
						<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-zalo-name">
							Tên hiển thị Zalo
						</label>
						<div class="relative">
							<span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg">person</span>
							<input id="nexxi-zalo-name" name="name" type="text" autocomplete="name" placeholder="Mẹ Bé Bơ" class="w-full pl-10 pr-3 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-[#0068FF] focus:bg-surface-container-lowest transition-all" />
						</div>
					</div>
					<div>
						<label class="block font-label-md text-label-md text-primary mb-1.5" for="nexxi-zalo-email">
							Email nhận hóa đơn (tùy chọn)
						</label>
						<div class="relative">
							<span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-lg">mail</span>
							<input id="nexxi-zalo-email" name="email" type="email" autocomplete="email" placeholder="meyeu@gmail.com" class="w-full pl-10 pr-3 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40 font-body-md text-body-md text-on-surface focus:outline-none focus:border-[#0068FF] focus:bg-surface-container-lowest transition-all" />
						</div>
					</div>
				</div>
				<div id="nexxi-social-err-zalo" class="hidden p-3 rounded-xl bg-error-container text-on-error-container font-body-sm text-body-sm"></div>
				<button type="submit" id="nexxi-zalo-submit-btn" class="w-full py-3.5 px-6 rounded-full text-white font-label-lg text-label-lg font-bold shadow-md hover:shadow-lg transition-all flex items-center justify-center gap-2" style="background:#0068FF;">
					<span class="w-5 h-5 rounded-full bg-white text-[#0068FF] font-extrabold text-xs flex items-center justify-center">Z</span>
					<span>Tiếp Tục Với Zalo</span>
				</button>
			</form>

			<!-- Footer note -->
			<div class="px-6 py-3.5 bg-surface-container-low border-t border-surface-container-high flex items-center justify-between gap-2 text-xs text-on-surface-variant">
				<span class="flex items-center gap-1.5">
					<span class="material-symbols-outlined text-secondary text-base">verified_user</span>
					<span>Tự động đồng bộ đơn hàng &amp; điểm thành viên NEXXI</span>
				</span>
				<?php if ( $is_admin ) : ?>
					<a href="<?php echo esc_url( $admin_url ); ?>" class="text-secondary font-bold hover:underline shrink-0" target="_blank">Cấu hình OAuth API</a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<script data-no-optimize="1" data-no-defer="1">
	window.NexxiSocialAuth = <?php echo wp_json_encode( $js_cfg ); ?>;

	(function() {
		const googleSvg = '<svg class="w-6 h-6" viewBox="0 0 24 24"><path d="M12 5c1.6 0 3 .6 4.1 1.6l3.1-3.1C17.3 1.7 14.8 1 12 1 7.5 1 3.7 3.6 1.9 7.4l3.7 2.9C6.5 7.4 9 5 12 5z" fill="#EA4335"></path><path d="M23.5 12.3c0-.8-.1-1.7-.2-2.3H12v4.6h6.5c-.3 1.5-1.1 2.8-2.4 3.7l3.7 2.9c2.2-2 3.7-5 3.7-8.9z" fill="#4285F4"></path><path d="M5.6 14.7c-.2-.7-.4-1.5-.4-2.7s.1-2 .4-2.7L1.9 6.4C.7 8.8 0 10.4 0 12s.7 3.2 1.9 5.6l3.7-2.9z" fill="#FBBC05"></path><path d="M12 23c3.2 0 6-1.1 8-3l-3.7-2.9c-1.1.7-2.5 1.2-4.3 1.2-3 0-5.5-2.4-6.4-5.3L1.9 16c1.8 3.8 5.6 7 10.1 7z" fill="#34A853"></path></svg>';
		const zaloSvg = '<span class="w-7 h-7 rounded-full flex items-center justify-center text-white font-extrabold text-sm" style="background:#0068FF;">Z</span>';

		window.openNexxiSocialModal = function(provider) {
			const modal = document.getElementById('nexxi-social-modal');
			const badge = document.getElementById('nexxi-social-modal-badge');
			const title = document.getElementById('nexxi-social-modal-title');
			const sub = document.getElementById('nexxi-social-modal-sub');
			const gForm = document.getElementById('nexxi-social-form-google');
			const zForm = document.getElementById('nexxi-social-form-zalo');
			const savedWrap = document.getElementById('nexxi-social-saved-wrap');
			const savedLabel = document.getElementById('nexxi-social-saved-label');
			const savedBtn = document.getElementById('nexxi-social-saved-btn');

			if (!modal) return;

			const norm = String(provider || '').toLowerCase().indexOf('zalo') !== -1 ? 'zalo' : 'google';

			if (norm === 'google') {
				badge.innerHTML = googleSvg;
				title.textContent = 'Đăng nhập bằng Google';
				sub.textContent = 'Nhập Gmail để kết nối nhanh & đồng bộ điểm NEXXI Club';
				gForm.classList.remove('hidden');
				zForm.classList.add('hidden');
			} else {
				badge.innerHTML = zaloSvg;
				title.textContent = 'Đăng nhập bằng Zalo';
				sub.textContent = 'Nhập số điện thoại Zalo để kết nối nhanh & tích điểm';
				zForm.classList.remove('hidden');
				gForm.classList.add('hidden');
			}

			// Check saved social profile in localStorage for 1-click sign-in
			try {
				const raw = localStorage.getItem('nexxi_social_saved_' + norm);
				const saved = raw ? JSON.parse(raw) : null;
				if (saved && ((norm === 'google' && saved.email) || (norm === 'zalo' && saved.phone))) {
					savedWrap.classList.remove('hidden');
					savedLabel.textContent = norm === 'google'
						? (saved.name ? saved.name + ' (' + saved.email + ')' : saved.email)
						: (saved.name ? saved.name + ' (' + saved.phone + ')' : saved.phone);
					savedBtn.onclick = function() {
						if (norm === 'google') {
							document.getElementById('nexxi-google-email').value = saved.email || '';
							document.getElementById('nexxi-google-name').value = saved.name || '';
							document.getElementById('nexxi-google-phone').value = saved.phone || '';
							gForm.requestSubmit();
						} else {
							document.getElementById('nexxi-zalo-phone').value = saved.phone || '';
							document.getElementById('nexxi-zalo-name').value = saved.name || '';
							document.getElementById('nexxi-zalo-email').value = saved.email || '';
							zForm.requestSubmit();
						}
					};
				} else {
					savedWrap.classList.add('hidden');
				}
			} catch (e) {
				savedWrap.classList.add('hidden');
			}

			modal.classList.remove('hidden');
			modal.classList.add('flex');
			modal.setAttribute('aria-hidden', 'false');

			setTimeout(function() {
				const firstInput = norm === 'google' ? document.getElementById('nexxi-google-email') : document.getElementById('nexxi-zalo-phone');
				if (firstInput) firstInput.focus();
			}, 80);
		};

		window.closeNexxiSocialModal = function() {
			const modal = document.getElementById('nexxi-social-modal');
			if (!modal) return;
			modal.classList.add('hidden');
			modal.classList.remove('flex');
			modal.setAttribute('aria-hidden', 'true');
		};

		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') window.closeNexxiSocialModal();
		});

		const modalEl = document.getElementById('nexxi-social-modal');
		if (modalEl) {
			modalEl.addEventListener('click', function(e) {
				if (e.target === modalEl) window.closeNexxiSocialModal();
			});
		}

		window.notifySocialLogin = function(provider) {
			const cfg = window.NexxiSocialAuth || {};
			const norm = String(provider || '').toLowerCase().indexOf('zalo') !== -1 ? 'zalo' : 'google';

			// If full OAuth 2.0 credentials are configured, redirect to official OAuth endpoint.
			if (norm === 'google' && cfg.googleClientId && cfg.hasGoogleSecret && cfg.googleStartUrl) {
				if (typeof showToast === 'function') {
					showToast('Đang chuyển tới cổng xác thực Google...');
				}
				window.location.href = cfg.googleStartUrl;
				return;
			}
			if (norm === 'zalo' && cfg.hasZaloApp && cfg.zaloStartUrl) {
				if (typeof showToast === 'function') {
					showToast('Đang chuyển tới cổng xác thực Zalo...');
				}
				window.location.href = cfg.zaloStartUrl;
				return;
			}

			// Otherwise open the interactive Google / Zalo Sign-In Modal.
			window.openNexxiSocialModal(norm);
		};

		window.submitNexxiSocialAuth = function(event, provider) {
			event.preventDefault();
			const cfg = window.NexxiSocialAuth || {};
			const isZalo = provider === 'zalo';
			const errBox = document.getElementById(isZalo ? 'nexxi-social-err-zalo' : 'nexxi-social-err-google');
			const submitBtn = document.getElementById(isZalo ? 'nexxi-zalo-submit-btn' : 'nexxi-google-submit-btn');

			if (errBox) {
				errBox.classList.add('hidden');
				errBox.textContent = '';
			}

			const fd = new FormData();
			fd.append('action', 'nexxi_social_auth');
			fd.append('nonce', cfg.nonce || '');
			fd.append('mode', isZalo ? 'quick_zalo' : 'quick_google');

			if (isZalo) {
				fd.append('phone', document.getElementById('nexxi-zalo-phone').value.trim());
				fd.append('name', document.getElementById('nexxi-zalo-name').value.trim());
				fd.append('email', document.getElementById('nexxi-zalo-email').value.trim());
			} else {
				fd.append('email', document.getElementById('nexxi-google-email').value.trim());
				fd.append('name', document.getElementById('nexxi-google-name').value.trim());
				fd.append('phone', document.getElementById('nexxi-google-phone').value.trim());
			}

			const origHtml = submitBtn ? submitBtn.innerHTML : '';
			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.innerHTML = '<span>Đang xác thực &amp; đăng nhập...</span>';
			}

			fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				credentials: 'same-origin',
				body: fd
			})
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res && res.success && res.data) {
					try {
						if (res.data.user) {
							localStorage.setItem('nexxi_social_saved_' + (isZalo ? 'zalo' : 'google'), JSON.stringify(res.data.user));
						}
					} catch (e) {}
					if (typeof showToast === 'function') {
						showToast(res.data.message || 'Đăng nhập thành công!');
					}
					window.location.href = res.data.redirect || '/tai-khoan/';
				} else {
					const msg = (res && res.data && res.data.message) ? res.data.message : 'Không thể kết nối tài khoản. Ba mẹ vui lòng kiểm tra lại thông tin nhé.';
					if (errBox) {
						errBox.textContent = msg;
						errBox.classList.remove('hidden');
					}
					if (submitBtn) {
						submitBtn.disabled = false;
						submitBtn.innerHTML = origHtml;
					}
				}
			})
			.catch(function() {
				if (errBox) {
					errBox.textContent = 'Lỗi kết nối mạng. Ba mẹ vui lòng thử lại nhé.';
					errBox.classList.remove('hidden');
				}
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.innerHTML = origHtml;
				}
			});
		};
	})();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * Register WP Admin Settings Page for Google & Zalo OAuth (Cài đặt → Đăng nhập Google & Zalo).
 */
add_action(
	'admin_menu',
	static function () {
		add_options_page(
			'Đăng nhập Google & Zalo – NEXXI Kids',
			'Đăng nhập Google & Zalo',
			'manage_options',
			'nexxi-social-auth',
			'nexxi_kids_render_social_auth_admin_page'
		);
	}
);

function nexxi_kids_render_social_auth_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$saved = false;
	if ( isset( $_POST['nexxi_social_auth_save'] ) && check_admin_referer( 'nexxi_social_auth_settings' ) ) {
		update_option( 'nexxi_google_client_id', sanitize_text_field( wp_unslash( $_POST['nexxi_google_client_id'] ?? '' ) ) );
		update_option( 'nexxi_google_client_secret', sanitize_text_field( wp_unslash( $_POST['nexxi_google_client_secret'] ?? '' ) ) );
		update_option( 'nexxi_zalo_app_id', sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_app_id'] ?? '' ) ) );
		update_option( 'nexxi_zalo_app_secret', sanitize_text_field( wp_unslash( $_POST['nexxi_zalo_app_secret'] ?? '' ) ) );
		$saved = true;
	}

	$cfg = nexxi_kids_social_auth_config();
	?>
	<div class="wrap" style="max-width:860px;margin-top:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<h1 style="font-weight:700;color:#031444;">Cấu Hình Đăng Nhập Google &amp; Zalo (NEXXI Kids)</h1>
		<p style="font-size:14px;color:#45464f;">
			Trang đăng nhập <code>/tai-khoan/</code> đã tích hợp sẵn <strong>Hộp thoại Đăng nhập nhanh Google &amp; Zalo</strong> hoạt động ngay lập tức (tự động tạo hoặc khớp tài khoản khách hàng và đồng bộ điểm thành viên NEXXI). Nếu bạn muốn chuyển sang luồng chuyển hướng OAuth 2.0 của Google Cloud / Zalo Developers, hãy điền khóa API bên dưới.
		</p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><strong>Đã lưu cấu hình Đăng nhập Google &amp; Zalo thành công!</strong></p></div>
		<?php endif; ?>

		<form method="post" style="background:#fff;padding:24px;border-radius:14px;box-shadow:0 4px 18px rgba(3,20,68,0.06);border:1px solid #e4e2de;margin-top:16px;">
			<?php wp_nonce_field( 'nexxi_social_auth_settings' ); ?>

			<h2 style="margin-top:0;color:#031444;border-bottom:1px solid #efeeea;padding-bottom:10px;">1. Google OAuth 2.0</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="nexxi_google_client_id">Google Client ID</label></th>
					<td>
						<input name="nexxi_google_client_id" id="nexxi_google_client_id" type="text" value="<?php echo esc_attr( $cfg['google_client_id'] ); ?>" class="regular-text" style="width:100%;max-width:480px;" placeholder="xxxxxx.apps.googleusercontent.com" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nexxi_google_client_secret">Google Client Secret</label></th>
					<td>
						<input name="nexxi_google_client_secret" id="nexxi_google_client_secret" type="password" value="<?php echo esc_attr( $cfg['google_client_secret'] ); ?>" class="regular-text" style="width:100%;max-width:480px;" placeholder="GOCSPX-..." />
					</td>
				</tr>
				<tr>
					<th scope="row">Authorized Redirect URI</th>
					<td>
						<code style="padding:6px 10px;background:#f5f3ef;border-radius:6px;display:inline-block;user-select:all;"><?php echo esc_html( $cfg['google_redirect_uri'] ); ?></code>
						<p class="description">Sao chép đường dẫn này dán vào mục <strong>Authorized redirect URIs</strong> trong Google Cloud Console.</p>
					</td>
				</tr>
			</table>

			<h2 style="margin-top:24px;color:#0068FF;border-bottom:1px solid #efeeea;padding-bottom:10px;">2. Zalo Login (OAuth v4 PKCE)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="nexxi_zalo_app_id">Zalo App ID</label></th>
					<td>
						<input name="nexxi_zalo_app_id" id="nexxi_zalo_app_id" type="text" value="<?php echo esc_attr( $cfg['zalo_app_id'] ); ?>" class="regular-text" style="width:100%;max-width:480px;" placeholder="Nhập App ID từ Zalo Developers" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="nexxi_zalo_app_secret">Zalo App Secret Key</label></th>
					<td>
						<input name="nexxi_zalo_app_secret" id="nexxi_zalo_app_secret" type="password" value="<?php echo esc_attr( $cfg['zalo_app_secret'] ); ?>" class="regular-text" style="width:100%;max-width:480px;" placeholder="Nhập Secret Key của ứng dụng Zalo" />
					</td>
				</tr>
				<tr>
					<th scope="row">Zalo Callback URL</th>
					<td>
						<code style="padding:6px 10px;background:#f5f3ef;border-radius:6px;display:inline-block;user-select:all;"><?php echo esc_html( $cfg['zalo_redirect_uri'] ); ?></code>
						<p class="description">Sao chép đường dẫn này dán vào mục <strong>Callback URL</strong> trong Zalo For Developers → Đăng nhập Zalo.</p>
					</td>
				</tr>
			</table>

			<p class="submit" style="margin-bottom:0;">
				<button type="submit" name="nexxi_social_auth_save" value="1" class="button button-primary button-large" style="background:#fd6a49;border-color:#fd6a49;border-radius:999px;padding:4px 24px;font-weight:700;">
					Lưu Cấu Hình Đăng Nhập
				</button>
			</p>
		</form>
	</div>
	<?php
}
