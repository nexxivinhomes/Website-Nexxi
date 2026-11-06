<?php
/**
 * Authenticated REST API Bridge for Yoast SEO Metadata & Indexables (Posts & Taxonomies).
 *
 * Route: /wp-json/nexxi/v1/yoast-seo (GET, POST)
 * Compatible with PHP 7.4 - 8.4+ and Yoast SEO v14.0 - v28.8+.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nexxi_Yoast_REST_Bridge {
	const VERSION        = '1.5.6';
	const REST_NAMESPACE = 'nexxi/v1';
	const ROUTE          = '/yoast-seo';

	/**
	 * Register WordPress hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register GET and POST endpoints under /wp-json/nexxi/v1/yoast-seo.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'check_read_permission' ),
					'args'                => array(
						'object_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'taxonomy'    => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'category',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => array( __CLASS__, 'check_write_permission' ),
					'args'                => array(
						'object_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'object_id'   => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'taxonomy'    => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'category',
							'sanitize_callback' => 'sanitize_key',
						),
						'auto_score'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Base capability check for reading Yoast SEO configuration via REST.
	 *
	 * @return true|WP_Error
	 */
	public static function check_read_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				'Bạn hiện chưa đăng nhập.',
				array( 'status' => 401 )
			);
		}

		if ( ! self::user_has_seo_or_editorial_role() ) {
			return new WP_Error(
				'nexxi_yoast_forbidden',
				'Tài khoản không có quyền truy cập cấu hình quản trị Yoast SEO.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Granular capability check for updating Yoast SEO metadata on a post or term.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|WP_Error
	 */
	public static function check_write_permission( WP_REST_Request $request ) {
		$base_check = self::check_read_permission();
		if ( is_wp_error( $base_check ) ) {
			return $base_check;
		}

		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );

		if ( $object_id <= 0 ) {
			return new WP_Error(
				'nexxi_yoast_invalid_id',
				'Tham số object_id phải là số nguyên dương.',
				array( 'status' => 400 )
			);
		}

		if ( 'post' === $object_type ) {
			$post = get_post( $object_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				return new WP_Error(
					'nexxi_yoast_post_not_found',
					sprintf( 'Không tìm thấy bài viết/trang ID=%d.', $object_id ),
					array( 'status' => 404 )
				);
			}
			$can_edit_post = current_user_can( 'edit_post', $object_id )
				|| current_user_can( 'wpseo_edit_advanced_metadata' )
				|| current_user_can( 'manage_options' );
			if ( ! $can_edit_post ) {
				return new WP_Error(
					'nexxi_yoast_post_forbidden',
					sprintf( 'Tài khoản không có quyền cập nhật SEO cho bài viết ID=%d.', $object_id ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		if ( 'term' === $object_type ) {
			$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error(
					'nexxi_yoast_invalid_taxonomy',
					sprintf( 'Taxonomy "%s" không tồn tại.', $taxonomy ),
					array( 'status' => 400 )
				);
			}
			$term = get_term( $object_id, $taxonomy );
			if ( ! ( $term instanceof WP_Term ) || is_wp_error( $term ) ) {
				return new WP_Error(
					'nexxi_yoast_term_not_found',
					sprintf( 'Không tìm thấy chuyên mục/term ID=%d trong taxonomy "%s".', $object_id, $taxonomy ),
					array( 'status' => 404 )
				);
			}

			$tax_obj       = get_taxonomy( $taxonomy );
			$edit_term_cap = ( $tax_obj && isset( $tax_obj->cap->edit_terms ) ) ? (string) $tax_obj->cap->edit_terms : 'manage_categories';
			$can_edit_term = current_user_can( 'manage_categories' )
				|| current_user_can( 'wpseo_edit_advanced_metadata' )
				|| current_user_can( 'manage_options' )
				|| current_user_can( $edit_term_cap )
				|| current_user_can( 'edit_terms', $taxonomy )
				|| current_user_can( 'edit_term', $object_id );

			if ( ! $can_edit_term ) {
				return new WP_Error(
					'nexxi_yoast_term_forbidden',
					sprintf( 'Tài khoản không có quyền cập nhật SEO cho term ID=%d (%s).', $object_id, $taxonomy ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		return new WP_Error(
			'nexxi_yoast_invalid_type',
			'Tham số object_type chỉ chấp nhận "term" hoặc "post".',
			array( 'status' => 400 )
		);
	}

	/**
	 * Check whether the current user holds an administrator, SEO manager, or senior editor capability.
	 *
	 * @return bool
	 */
	private static function user_has_seo_or_editorial_role() {
		return current_user_can( 'wpseo_edit_advanced_metadata' )
			|| current_user_can( 'wpseo_manage_options' )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'edit_others_posts' );
	}

	/**
	 * Handle GET /wp-json/nexxi/v1/yoast-seo
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( WP_REST_Request $request ) {
		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );

		if ( ! in_array( $object_type, array( 'term', 'post' ), true ) ) {
			return new WP_Error( 'nexxi_yoast_invalid_type', 'Tham số object_type chỉ chấp nhận "term" hoặc "post".', array( 'status' => 400 ) );
		}

		if ( $object_id <= 0 ) {
			return new WP_Error( 'nexxi_yoast_invalid_id', 'object_id không hợp lệ.', array( 'status' => 400 ) );
		}

		if ( 'term' === $object_type ) {
			$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'nexxi_yoast_invalid_taxonomy', 'Taxonomy không tồn tại.', array( 'status' => 400 ) );
			}
			$term = get_term( $object_id, $taxonomy );
			if ( ! ( $term instanceof WP_Term ) || is_wp_error( $term ) ) {
				return new WP_Error( 'nexxi_yoast_term_not_found', 'Không tìm thấy term.', array( 'status' => 404 ) );
			}
			return rest_ensure_response( self::build_term_snapshot( $term ) );
		}

		$post = get_post( $object_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error( 'nexxi_yoast_post_not_found', 'Không tìm thấy post.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::build_post_snapshot( $post ) );
	}

	/**
	 * Handle POST /wp-json/nexxi/v1/yoast-seo
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_post( WP_REST_Request $request ) {
		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		$params      = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( 'term' === $object_type ) {
			$taxonomy = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
			return self::update_term_yoast( $object_id, $taxonomy, $params );
		}

		return self::update_post_yoast( $object_id, $params );
	}

	/**
	 * Update Term HTML description (optional), Yoast Taxonomy Meta, Yoast Indexable, and LiteSpeed Cache.
	 *
	 * @param int   $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array $params   Request payload.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function update_term_yoast( $term_id, $taxonomy, array $params ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! ( $term instanceof WP_Term ) || is_wp_error( $term ) ) {
			return new WP_Error( 'nexxi_yoast_term_not_found', 'Không tìm thấy term.', array( 'status' => 404 ) );
		}

		// 1. Optional rich HTML description update via wp_kses_post (preserving <p>, <h2>, <h3>, <ul>, <li>, <strong>, <a href>).
		if ( array_key_exists( 'term_description', $params ) || array_key_exists( 'html_description', $params ) ) {
			$raw_desc  = array_key_exists( 'term_description', $params ) ? (string) $params['term_description'] : (string) $params['html_description'];
			$safe_desc = wp_kses_post( $raw_desc );

			$had_pre_kses  = has_filter( 'pre_term_description', 'wp_filter_kses' );
			$had_term_kses = has_filter( 'term_description', 'wp_kses_data' );

			if ( false !== $had_pre_kses ) {
				remove_filter( 'pre_term_description', 'wp_filter_kses', (int) $had_pre_kses );
			}
			if ( false !== $had_term_kses ) {
				remove_filter( 'term_description', 'wp_kses_data', (int) $had_term_kses );
			}

			try {
				$updated_term = wp_update_term(
					$term_id,
					$taxonomy,
					array( 'description' => $safe_desc )
				);
			} finally {
				if ( false !== $had_pre_kses ) {
					add_filter( 'pre_term_description', 'wp_filter_kses', (int) $had_pre_kses );
				}
				if ( false !== $had_term_kses ) {
					add_filter( 'term_description', 'wp_kses_data', (int) $had_term_kses );
				}
			}

			if ( is_wp_error( $updated_term ) ) {
				return $updated_term;
			}
			clean_term_cache( $term_id, $taxonomy );
			$term = get_term( $term_id, $taxonomy );
		}

		// 2. Read & merge wpseo_taxonomy_meta[$taxonomy][$term_id].
		$all_tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		if ( ! is_array( $all_tax_meta ) ) {
			$all_tax_meta = array();
		}
		if ( ! isset( $all_tax_meta[ $taxonomy ] ) || ! is_array( $all_tax_meta[ $taxonomy ] ) ) {
			$all_tax_meta[ $taxonomy ] = array();
		}
		$current_meta = isset( $all_tax_meta[ $taxonomy ][ $term_id ] ) && is_array( $all_tax_meta[ $taxonomy ][ $term_id ] )
			? $all_tax_meta[ $taxonomy ][ $term_id ]
			: array();

		$field_aliases = array(
			'wpseo_focuskw'               => array( 'focuskw', 'wpseo_focuskw', '_yoast_wpseo_focuskw' ),
			'wpseo_title'                 => array( 'title', 'seo_title', 'wpseo_title', '_yoast_wpseo_title' ),
			'wpseo_desc'                  => array( 'desc', 'metadesc', 'meta_description', 'wpseo_desc', '_yoast_wpseo_metadesc' ),
			'wpseo_canonical'             => array( 'canonical', 'wpseo_canonical', '_yoast_wpseo_canonical' ),
			'wpseo_bctitle'               => array( 'bctitle', 'wpseo_bctitle' ),
			'wpseo_opengraph-title'       => array( 'og_title', 'opengraph_title', 'wpseo_opengraph-title' ),
			'wpseo_opengraph-description' => array( 'og_description', 'opengraph_description', 'wpseo_opengraph-description' ),
			'wpseo_opengraph-image'       => array( 'og_image', 'opengraph_image', 'wpseo_opengraph-image' ),
		);

		foreach ( $field_aliases as $target_key => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( array_key_exists( $alias, $params ) ) {
					$val = trim( (string) $params[ $alias ] );
					if ( 'wpseo_canonical' === $target_key || 'wpseo_opengraph-image' === $target_key ) {
						$val = '' !== $val ? esc_url_raw( $val ) : '';
					} else {
						$val = sanitize_text_field( $val );
					}
					if ( '' === $val ) {
						unset( $current_meta[ $target_key ] );
					} else {
						$current_meta[ $target_key ] = $val;
					}
					break;
				}
			}
		}

		// OpenGraph Image ID
		foreach ( array( 'og_image_id', 'opengraph_image_id', 'wpseo_opengraph-image-id' ) as $img_id_alias ) {
			if ( array_key_exists( $img_id_alias, $params ) ) {
				$img_id = absint( $params[ $img_id_alias ] );
				if ( $img_id > 0 ) {
					$current_meta['wpseo_opengraph-image-id'] = (string) $img_id;
				} else {
					unset( $current_meta['wpseo_opengraph-image-id'] );
				}
				break;
			}
		}

		// Robots noindex ('default' | 'index' | 'noindex')
		foreach ( array( 'noindex', 'wpseo_noindex' ) as $noindex_alias ) {
			if ( array_key_exists( $noindex_alias, $params ) ) {
				$norm_noindex = self::normalize_term_noindex( $params[ $noindex_alias ] );
				if ( 'default' === $norm_noindex ) {
					unset( $current_meta['wpseo_noindex'] );
				} else {
					$current_meta['wpseo_noindex'] = $norm_noindex;
				}
				break;
			}
		}

		// 3. Honest Traffic-Light Score calculation or explicit override.
		$auto_score      = ! isset( $params['auto_score'] ) || rest_sanitize_boolean( $params['auto_score'] );
		$explicit_seo    = null;
		$explicit_read   = null;

		foreach ( array( 'linkdex', 'seo_score', 'wpseo_linkdex' ) as $k ) {
			if ( array_key_exists( $k, $params ) && null !== $params[ $k ] && '' !== $params[ $k ] ) {
				$explicit_seo = max( 0, min( 100, (int) $params[ $k ] ) );
				break;
			}
		}
		foreach ( array( 'content_score', 'readability_score', 'wpseo_content_score' ) as $k ) {
			if ( array_key_exists( $k, $params ) && null !== $params[ $k ] && '' !== $params[ $k ] ) {
				$explicit_read = max( 0, min( 100, (int) $params[ $k ] ) );
				break;
			}
		}

		$score_breakdown = null;
		if ( $auto_score || null !== $explicit_seo || null !== $explicit_read ) {
			$computed = self::calculate_honest_scores(
				'term',
				isset( $current_meta['wpseo_focuskw'] ) ? (string) $current_meta['wpseo_focuskw'] : '',
				isset( $current_meta['wpseo_title'] ) ? (string) $current_meta['wpseo_title'] : '',
				isset( $current_meta['wpseo_desc'] ) ? (string) $current_meta['wpseo_desc'] : '',
				(string) $term->name,
				(string) $term->slug,
				(string) $term->description
			);
			$score_breakdown = $computed['breakdown'];

			$final_linkdex = ( null !== $explicit_seo ) ? $explicit_seo : $computed['linkdex'];
			$final_read    = ( null !== $explicit_read ) ? $explicit_read : $computed['content_score'];

			$current_meta['wpseo_linkdex']       = (string) $final_linkdex;
			$current_meta['wpseo_content_score'] = (string) $final_read;
		}

		// Save via Yoast helper first if available, then enforce exact persistence in wpseo_taxonomy_meta.
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) && method_exists( 'WPSEO_Taxonomy_Meta', 'set_values' ) ) {
			try {
				WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $current_meta );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to direct option persistence below.
			}
		}

		$latest_tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		if ( ! is_array( $latest_tax_meta ) ) {
			$latest_tax_meta = array();
		}
		if ( ! isset( $latest_tax_meta[ $taxonomy ] ) || ! is_array( $latest_tax_meta[ $taxonomy ] ) ) {
			$latest_tax_meta[ $taxonomy ] = array();
		}
		$merged_persisted = isset( $latest_tax_meta[ $taxonomy ][ $term_id ] ) && is_array( $latest_tax_meta[ $taxonomy ][ $term_id ] )
			? array_merge( $latest_tax_meta[ $taxonomy ][ $term_id ], $current_meta )
			: $current_meta;

		$latest_tax_meta[ $taxonomy ][ $term_id ] = $merged_persisted;
		update_option( 'wpseo_taxonomy_meta', $latest_tax_meta );

		if ( class_exists( 'WPSEO_Options' ) && method_exists( 'WPSEO_Options', 'clear_cache' ) ) {
			try {
				WPSEO_Options::clear_cache();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		// 4. Sync wp_yoast_indexable (Yoast v14 - v28.8+) & purge LiteSpeed Cache.
		$indexable_sync = self::sync_term_indexable( $term );

		$term_link = get_term_link( $term_id, $taxonomy );
		if ( ! is_wp_error( $term_link ) && is_string( $term_link ) && '' !== $term_link ) {
			do_action( 'litespeed_purge_url', $term_link );
		}
		do_action( 'litespeed_purge', 'T.' . $term_id );

		$snapshot                   = self::build_term_snapshot( $term );
		$snapshot['updated']        = true;
		$snapshot['indexable_sync'] = $indexable_sync;
		if ( null !== $score_breakdown ) {
			$snapshot['score_breakdown'] = $score_breakdown;
		}

		return rest_ensure_response( $snapshot );
	}

	/**
	 * Update Post Yoast Meta, honest scores, Yoast Indexable, and LiteSpeed Cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $params  Request payload.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function update_post_yoast( $post_id, array $params ) {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error( 'nexxi_yoast_post_not_found', 'Không tìm thấy post.', array( 'status' => 404 ) );
		}

		$meta_aliases = array(
			'focuskw'               => array( 'focuskw', '_yoast_wpseo_focuskw', 'wpseo_focuskw' ),
			'title'                 => array( 'title', 'seo_title', '_yoast_wpseo_title', 'wpseo_title' ),
			'metadesc'              => array( 'metadesc', 'desc', 'meta_description', '_yoast_wpseo_metadesc', 'wpseo_desc' ),
			'canonical'             => array( 'canonical', '_yoast_wpseo_canonical', 'wpseo_canonical' ),
			'opengraph-title'       => array( 'og_title', 'opengraph_title', '_yoast_wpseo_opengraph-title' ),
			'opengraph-description' => array( 'og_description', 'opengraph_description', '_yoast_wpseo_opengraph-description' ),
			'opengraph-image'       => array( 'og_image', 'opengraph_image', '_yoast_wpseo_opengraph-image' ),
		);

		foreach ( $meta_aliases as $short_key => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( array_key_exists( $alias, $params ) ) {
					$val = trim( (string) $params[ $alias ] );
					if ( 'canonical' === $short_key || 'opengraph-image' === $short_key ) {
						$val = '' !== $val ? esc_url_raw( $val ) : '';
					} else {
						$val = sanitize_text_field( $val );
					}
					self::set_yoast_post_meta_value( $post_id, $short_key, $val );
					break;
				}
			}
		}

		foreach ( array( 'og_image_id', 'opengraph_image_id', '_yoast_wpseo_opengraph-image-id' ) as $img_id_alias ) {
			if ( array_key_exists( $img_id_alias, $params ) ) {
				$img_id = absint( $params[ $img_id_alias ] );
				self::set_yoast_post_meta_value( $post_id, 'opengraph-image-id', $img_id > 0 ? (string) $img_id : '' );
				break;
			}
		}

		foreach ( array( 'noindex', '_yoast_wpseo_meta-robots-noindex' ) as $noindex_alias ) {
			if ( array_key_exists( $noindex_alias, $params ) ) {
				$norm_post_noindex = self::normalize_post_noindex( $params[ $noindex_alias ] );
				self::set_yoast_post_meta_value( $post_id, 'meta-robots-noindex', $norm_post_noindex );
				break;
			}
		}

		$auto_score    = ! isset( $params['auto_score'] ) || rest_sanitize_boolean( $params['auto_score'] );
		$explicit_seo  = null;
		$explicit_read = null;

		foreach ( array( 'linkdex', 'seo_score', '_yoast_wpseo_linkdex' ) as $k ) {
			if ( array_key_exists( $k, $params ) && null !== $params[ $k ] && '' !== $params[ $k ] ) {
				$explicit_seo = max( 0, min( 100, (int) $params[ $k ] ) );
				break;
			}
		}
		foreach ( array( 'content_score', 'readability_score', '_yoast_wpseo_content_score' ) as $k ) {
			if ( array_key_exists( $k, $params ) && null !== $params[ $k ] && '' !== $params[ $k ] ) {
				$explicit_read = max( 0, min( 100, (int) $params[ $k ] ) );
				break;
			}
		}

		$score_breakdown = null;
		if ( $auto_score || null !== $explicit_seo || null !== $explicit_read ) {
			$focuskw  = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
			$title    = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
			$metadesc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );

			$computed = self::calculate_honest_scores(
				'post',
				$focuskw,
				$title,
				$metadesc,
				(string) $post->post_title,
				(string) $post->post_name,
				(string) $post->post_content
			);
			$score_breakdown = $computed['breakdown'];

			$final_linkdex = ( null !== $explicit_seo ) ? $explicit_seo : $computed['linkdex'];
			$final_read    = ( null !== $explicit_read ) ? $explicit_read : $computed['content_score'];

			self::set_yoast_post_meta_value( $post_id, 'linkdex', (string) $final_linkdex );
			self::set_yoast_post_meta_value( $post_id, 'content_score', (string) $final_read );
		}

		$indexable_sync = self::sync_post_indexable( $post );

		do_action( 'litespeed_purge_post', $post_id );
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			do_action( 'litespeed_purge_url', $permalink );
		}

		$snapshot                   = self::build_post_snapshot( $post );
		$snapshot['updated']        = true;
		$snapshot['indexable_sync'] = $indexable_sync;
		if ( null !== $score_breakdown ) {
			$snapshot['score_breakdown'] = $score_breakdown;
		}

		return rest_ensure_response( $snapshot );
	}

	/**
	 * Persist a Yoast post meta field cleanly.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $short_key Short Yoast meta key without _yoast_wpseo_ prefix.
	 * @param string $value     Sanitized value.
	 */
	private static function set_yoast_post_meta_value( $post_id, $short_key, $value ) {
		$full_key = '_yoast_wpseo_' . $short_key;
		if ( class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
			try {
				WPSEO_Meta::set_value( $short_key, $value, $post_id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
		if ( '' === $value && ! in_array( $short_key, array( 'linkdex', 'content_score' ), true ) ) {
			delete_post_meta( $post_id, $full_key );
		} else {
			update_post_meta( $post_id, $full_key, $value );
		}
	}

	/**
	 * Safely rebuild Yoast Indexable for a taxonomy term (Yoast v14 - v28.8+).
	 *
	 * @param WP_Term $term Term object.
	 * @return array<string, mixed>
	 */
	private static function sync_term_indexable( WP_Term $term ) {
		$methods_run = array();
		$errors      = array();

		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$container = YoastSEO();
				if ( is_object( $container ) && isset( $container->classes ) && method_exists( $container->classes, 'get' ) ) {
					$watcher_class = '\\Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Term_Watcher';
					if ( class_exists( $watcher_class ) ) {
						$watcher = $container->classes->get( $watcher_class );
						if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
							$watcher->build_indexable( (int) $term->term_id );
							$methods_run[] = 'Indexable_Term_Watcher::build_indexable';
						}
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}

		if ( empty( $methods_run ) ) {
			try {
				do_action( 'edited_term', (int) $term->term_id, (int) $term->term_taxonomy_id, (string) $term->taxonomy, (array) $term );
				$methods_run[] = 'do_action(edited_term)';
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return array(
			'synced'  => ! empty( $methods_run ),
			'methods' => $methods_run,
			'errors'  => $errors,
		);
	}

	/**
	 * Safely rebuild Yoast Indexable for a post (Yoast v14 - v28.8+).
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private static function sync_post_indexable( WP_Post $post ) {
		$methods_run = array();
		$errors      = array();

		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$container = YoastSEO();
				if ( is_object( $container ) && isset( $container->classes ) && method_exists( $container->classes, 'get' ) ) {
					$watcher_class = '\\Yoast\\WP\\SEO\\Integrations\\Watchers\\Indexable_Post_Watcher';
					if ( class_exists( $watcher_class ) ) {
						$watcher = $container->classes->get( $watcher_class );
						if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
							$watcher->build_indexable( (int) $post->ID );
							$methods_run[] = 'Indexable_Post_Watcher::build_indexable';
						}
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}

		if ( empty( $methods_run ) ) {
			try {
				do_action( 'save_post', (int) $post->ID, $post, true );
				$methods_run[] = 'do_action(save_post)';
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return array(
			'synced'  => ! empty( $methods_run ),
			'methods' => $methods_run,
			'errors'  => $errors,
		);
	}

	/**
	 * Build full diagnostic snapshot for a taxonomy term.
	 *
	 * @param WP_Term $term Term object.
	 * @return array<string, mixed>
	 */
	private static function build_term_snapshot( WP_Term $term ) {
		$all_tax_meta = get_option( 'wpseo_taxonomy_meta', array() );
		$term_meta    = ( is_array( $all_tax_meta ) && isset( $all_tax_meta[ $term->taxonomy ][ $term->term_id ] ) && is_array( $all_tax_meta[ $term->taxonomy ][ $term->term_id ] ) )
			? $all_tax_meta[ $term->taxonomy ][ $term->term_id ]
			: array();

		$linkdex       = isset( $term_meta['wpseo_linkdex'] ) ? (int) $term_meta['wpseo_linkdex'] : 0;
		$content_score = isset( $term_meta['wpseo_content_score'] ) ? (int) $term_meta['wpseo_content_score'] : 0;
		$term_link     = get_term_link( $term );

		return array(
			'object_type'      => 'term',
			'object_id'        => (int) $term->term_id,
			'taxonomy'         => (string) $term->taxonomy,
			'name'             => (string) $term->name,
			'slug'             => (string) $term->slug,
			'count'            => (int) $term->count,
			'link'             => is_wp_error( $term_link ) ? '' : (string) $term_link,
			'term_description' => (string) $term->description,
			'yoast_meta'       => array(
				'wpseo_focuskw'               => isset( $term_meta['wpseo_focuskw'] ) ? (string) $term_meta['wpseo_focuskw'] : '',
				'wpseo_title'                 => isset( $term_meta['wpseo_title'] ) ? (string) $term_meta['wpseo_title'] : '',
				'wpseo_desc'                  => isset( $term_meta['wpseo_desc'] ) ? (string) $term_meta['wpseo_desc'] : '',
				'wpseo_canonical'             => isset( $term_meta['wpseo_canonical'] ) ? (string) $term_meta['wpseo_canonical'] : '',
				'wpseo_noindex'               => isset( $term_meta['wpseo_noindex'] ) ? (string) $term_meta['wpseo_noindex'] : 'default',
				'wpseo_opengraph-title'       => isset( $term_meta['wpseo_opengraph-title'] ) ? (string) $term_meta['wpseo_opengraph-title'] : '',
				'wpseo_opengraph-description' => isset( $term_meta['wpseo_opengraph-description'] ) ? (string) $term_meta['wpseo_opengraph-description'] : '',
				'wpseo_opengraph-image'       => isset( $term_meta['wpseo_opengraph-image'] ) ? (string) $term_meta['wpseo_opengraph-image'] : '',
				'wpseo_opengraph-image-id'    => isset( $term_meta['wpseo_opengraph-image-id'] ) ? (string) $term_meta['wpseo_opengraph-image-id'] : '',
				'wpseo_linkdex'               => $linkdex,
				'wpseo_content_score'         => $content_score,
			),
			'traffic_lights'   => array(
				'seo_score'         => $linkdex,
				'seo_rank'          => self::score_to_rank( $linkdex ),
				'readability_score' => $content_score,
				'readability_rank'  => self::score_to_rank( $content_score ),
			),
			'indexable'        => self::read_indexable_row( (int) $term->term_id, 'term' ),
		);
	}

	/**
	 * Build full diagnostic snapshot for a post/page/product.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private static function build_post_snapshot( WP_Post $post ) {
		$pid           = (int) $post->ID;
		$linkdex       = (int) get_post_meta( $pid, '_yoast_wpseo_linkdex', true );
		$content_score = (int) get_post_meta( $pid, '_yoast_wpseo_content_score', true );

		return array(
			'object_type'    => 'post',
			'object_id'      => $pid,
			'post_type'      => (string) $post->post_type,
			'post_status'    => (string) $post->post_status,
			'title'          => (string) $post->post_title,
			'slug'           => (string) $post->post_name,
			'link'           => (string) get_permalink( $pid ),
			'yoast_meta'     => array(
				'_yoast_wpseo_focuskw'               => (string) get_post_meta( $pid, '_yoast_wpseo_focuskw', true ),
				'_yoast_wpseo_title'                 => (string) get_post_meta( $pid, '_yoast_wpseo_title', true ),
				'_yoast_wpseo_metadesc'              => (string) get_post_meta( $pid, '_yoast_wpseo_metadesc', true ),
				'_yoast_wpseo_canonical'             => (string) get_post_meta( $pid, '_yoast_wpseo_canonical', true ),
				'_yoast_wpseo_meta-robots-noindex'   => (string) get_post_meta( $pid, '_yoast_wpseo_meta-robots-noindex', true ),
				'_yoast_wpseo_opengraph-title'       => (string) get_post_meta( $pid, '_yoast_wpseo_opengraph-title', true ),
				'_yoast_wpseo_opengraph-description' => (string) get_post_meta( $pid, '_yoast_wpseo_opengraph-description', true ),
				'_yoast_wpseo_opengraph-image'       => (string) get_post_meta( $pid, '_yoast_wpseo_opengraph-image', true ),
				'_yoast_wpseo_opengraph-image-id'    => (string) get_post_meta( $pid, '_yoast_wpseo_opengraph-image-id', true ),
				'_yoast_wpseo_linkdex'               => $linkdex,
				'_yoast_wpseo_content_score'         => $content_score,
			),
			'traffic_lights' => array(
				'seo_score'         => $linkdex,
				'seo_rank'          => self::score_to_rank( $linkdex ),
				'readability_score' => $content_score,
				'readability_rank'  => self::score_to_rank( $content_score ),
			),
			'indexable'      => self::read_indexable_row( $pid, 'post' ),
		);
	}

	/**
	 * Read key columns from wp_yoast_indexable for verification (Read-Only).
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $object_type 'term' or 'post'.
	 * @return array<string, mixed>|null
	 */
	private static function read_indexable_row( $object_id, $object_type ) {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'yoast_indexable';
		// Check table existence safely cached per request.
		static $has_table = null;
		if ( null === $has_table ) {
			$found     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$has_table = ( $found === $table );
		}
		if ( ! $has_table ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, title, description, open_graph_title, open_graph_description, canonical, primary_focus_keyword, primary_focus_keyword_score, readability_score, is_robots_noindex FROM {$table} WHERE object_id = %d AND object_type = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$object_id,
				$object_type
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Map numeric Yoast score (0..100) to Yoast WPSEO_Rank label.
	 *
	 * @param int $score Numeric score.
	 * @return string 'na' | 'bad' | 'ok' | 'good'
	 */
	private static function score_to_rank( $score ) {
		$score = (int) $score;
		if ( $score <= 0 ) {
			return 'na';
		}
		if ( $score <= 40 ) {
			return 'bad';
		}
		if ( $score <= 70 ) {
			return 'ok';
		}
		return 'good';
	}

	/**
	 * Normalize term noindex input to Yoast taxonomy values ('default' | 'index' | 'noindex').
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function normalize_term_noindex( $raw ) {
		if ( is_bool( $raw ) ) {
			return $raw ? 'noindex' : 'index';
		}
		$str = strtolower( trim( (string) $raw ) );
		if ( in_array( $str, array( '1', 'true', 'yes', 'noindex' ), true ) ) {
			return 'noindex';
		}
		if ( in_array( $str, array( '0', 'false', 'no', 'index' ), true ) ) {
			return 'index';
		}
		return 'default';
	}

	/**
	 * Normalize post noindex input to Yoast post meta values ('' | '1' | '2').
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	private static function normalize_post_noindex( $raw ) {
		if ( is_bool( $raw ) ) {
			return $raw ? '1' : '2';
		}
		$str = strtolower( trim( (string) $raw ) );
		if ( in_array( $str, array( '1', 'true', 'yes', 'noindex' ), true ) ) {
			return '1';
		}
		if ( in_array( $str, array( '2', '0', 'false', 'no', 'index' ), true ) ) {
			return '2';
		}
		return '';
	}

	/**
	 * Honest content & SEO evaluation matching core Yoast assessments.
	 *
	 * @param string $object_type  'term' or 'post'.
	 * @param string $focuskw      Focus keyphrase.
	 * @param string $seo_title    Custom Yoast SEO title.
	 * @param string $meta_desc    Custom Yoast meta description.
	 * @param string $display_name Post title or Term name.
	 * @param string $slug         URL slug.
	 * @param string $html_content Raw HTML content or term description.
	 * @return array{linkdex: int, content_score: int, breakdown: array<string, mixed>}
	 */
	private static function calculate_honest_scores( $object_type, $focuskw, $seo_title, $meta_desc, $display_name, $slug, $html_content ) {
		// Strip <style>, <script>, and block comments before measuring text.
		$clean_html = (string) preg_replace( '~<(style|script)\b[^>]*>.*?</\1>~is', ' ', (string) $html_content );
		$clean_html = (string) preg_replace( '~<!--.*?-->~s', ' ', $clean_html );
		$plain_text = trim( (string) preg_replace( '~\s+~u', ' ', wp_strip_all_tags( html_entity_decode( $clean_html, ENT_QUOTES, 'UTF-8' ) ) ) );

		$words      = '' === $plain_text ? array() : preg_split( '~\s+~u', $plain_text, -1, PREG_SPLIT_NO_EMPTY );
		$word_count = is_array( $words ) ? count( $words ) : 0;

		$focus_norm      = self::mb_lower( trim( $focuskw ) );
		$effective_title = '' !== trim( $seo_title ) ? trim( $seo_title ) : trim( $display_name );
		$title_len       = self::mb_len( $effective_title );
		$desc_len        = self::mb_len( trim( $meta_desc ) );

		// 1. Honest SEO Score (linkdex 0..100; 0 if focuskw is empty).
		$seo_points = 0;
		$seo_notes  = array();

		if ( '' !== $focus_norm ) {
			$seo_points             += 12;
			$seo_notes['focuskw_set'] = 12;

			// Title contains keyphrase (exact or >=75% words)
			$title_lower = self::mb_lower( $effective_title . ' ' . $display_name );
			$kw_tokens   = array_values( array_filter( preg_split( '~\s+~u', $focus_norm, -1, PREG_SPLIT_NO_EMPTY ) ) );
			$matched_t   = 0;
			foreach ( $kw_tokens as $tk ) {
				if ( false !== self::mb_pos( $title_lower, $tk ) ) {
					++$matched_t;
				}
			}
			$title_ratio = count( $kw_tokens ) > 0 ? ( $matched_t / count( $kw_tokens ) ) : 0;
			if ( false !== self::mb_pos( self::mb_lower( $effective_title ), $focus_norm ) || $title_ratio >= 0.75 ) {
				$seo_points                += 16;
				$seo_notes['kw_in_title']   = 16;
			} elseif ( $title_ratio >= 0.5 ) {
				$seo_points                += 8;
				$seo_notes['kw_in_title']   = 8;
			} else {
				$seo_notes['kw_in_title'] = 0;
			}

			// Title length (35..65 chars optimal)
			if ( $title_len >= 35 && $title_len <= 65 ) {
				$seo_points               += 10;
				$seo_notes['title_length'] = 10;
			} elseif ( $title_len >= 20 && $title_len <= 75 ) {
				$seo_points               += 5;
				$seo_notes['title_length'] = 5;
			} else {
				$seo_notes['title_length'] = 0;
			}

			// Meta description length (110..165 chars optimal) & keyphrase presence
			if ( $desc_len >= 110 && $desc_len <= 165 ) {
				$seo_points              += 14;
				$seo_notes['desc_length'] = 14;
			} elseif ( $desc_len >= 60 && $desc_len <= 195 ) {
				$seo_points              += 8;
				$seo_notes['desc_length'] = 8;
			} else {
				$seo_notes['desc_length'] = 0;
			}

			$desc_lower = self::mb_lower( $meta_desc );
			if ( '' !== $desc_lower && false !== self::mb_pos( $desc_lower, $focus_norm ) ) {
				$seo_points            += 6;
				$seo_notes['kw_in_desc'] = 6;
			} else {
				$seo_notes['kw_in_desc'] = 0;
			}

			// Slug contains keyphrase tokens
			$kw_slug      = sanitize_title( remove_accents( $focus_norm ) );
			$kw_slug_toks = array_values( array_filter( explode( '-', $kw_slug ) ) );
			$slug_hits    = 0;
			foreach ( $kw_slug_toks as $st ) {
				if ( false !== strpos( $slug, $st ) ) {
					++$slug_hits;
				}
			}
			if ( count( $kw_slug_toks ) > 0 && ( $slug_hits / count( $kw_slug_toks ) ) >= 0.6 ) {
				$seo_points            += 8;
				$seo_notes['kw_in_slug'] = 8;
			} else {
				$seo_notes['kw_in_slug'] = 0;
			}

			// Word count threshold (Term >= 250, Post >= 300)
			$green_words = ( 'term' === $object_type ) ? 250 : 300;
			$ok_words    = ( 'term' === $object_type ) ? 120 : 180;
			if ( $word_count >= $green_words ) {
				$seo_points            += 12;
				$seo_notes['word_count'] = 12;
			} elseif ( $word_count >= $ok_words ) {
				$seo_points            += 6;
				$seo_notes['word_count'] = 6;
			} else {
				$seo_notes['word_count'] = 0;
			}

			// Keyphrase in introduction & body occurrences
			$plain_lower = self::mb_lower( $plain_text );
			$intro_slice = self::mb_sub( $plain_lower, 0, 260 );
			$kw_count    = substr_count( $plain_lower, $focus_norm );
			if ( false !== self::mb_pos( $intro_slice, $focus_norm ) && $kw_count >= 2 ) {
				$seo_points             += 12;
				$seo_notes['kw_in_intro'] = 12;
			} elseif ( $kw_count >= 1 ) {
				$seo_points             += 6;
				$seo_notes['kw_in_intro'] = 6;
			} else {
				$seo_notes['kw_in_intro'] = 0;
			}

			// Internal links (<a href)
			$site_host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$has_int_links = false;
			if ( preg_match_all( '~<a\b[^>]*href=["\']([^"\']+)["\']~i', $clean_html, $link_matches ) ) {
				foreach ( $link_matches[1] as $href ) {
					$href_host = (string) wp_parse_url( $href, PHP_URL_HOST );
					if ( '' === $href_host || 0 === strcasecmp( $href_host, $site_host ) ) {
						$has_int_links = true;
						break;
					}
				}
			}
			if ( $has_int_links ) {
				$seo_points                += 10;
				$seo_notes['internal_links'] = 10;
			} else {
				$seo_notes['internal_links'] = 0;
			}
		}

		// 2. Honest Readability Score (content_score 0..100).
		$read_points = 0;
		$read_notes  = array();

		if ( $word_count >= 30 ) {
			// Split blocks & sentences
			$blocks_raw = preg_split( '~(?:</p>|</h2>|</h3>|</h4>|</li>|</tr>|</figcaption>|</blockquote>|\n\s*\n)~i', $clean_html );
			$blocks     = array();
			$sentences  = array();
			if ( is_array( $blocks_raw ) ) {
				foreach ( $blocks_raw as $blk ) {
					$blk_txt = trim( (string) preg_replace( '~\s+~u', ' ', wp_strip_all_tags( html_entity_decode( $blk, ENT_QUOTES, 'UTF-8' ) ) ) );
					if ( '' === $blk_txt ) {
						continue;
					}
					$blocks[] = $blk_txt;
					$sents    = preg_split( '~[.!?]+~u', $blk_txt, -1, PREG_SPLIT_NO_EMPTY );
					if ( is_array( $sents ) ) {
						foreach ( $sents as $s ) {
							$s = trim( $s );
							if ( '' !== $s ) {
								$sentences[] = $s;
							}
						}
					}
				}
			}

			$long_sents = 0;
			foreach ( $sentences as $s ) {
				$sw = preg_split( '~\s+~u', $s, -1, PREG_SPLIT_NO_EMPTY );
				if ( is_array( $sw ) && count( $sw ) > 22 ) {
					++$long_sents;
				}
			}
			$long_ratio = count( $sentences ) > 0 ? ( $long_sents / count( $sentences ) ) : 0.0;
			if ( $long_ratio <= 0.25 ) {
				$read_points                 += 35;
				$read_notes['sentence_length'] = 35;
			} elseif ( $long_ratio <= 0.40 ) {
				$read_points                 += 20;
				$read_notes['sentence_length'] = 20;
			} else {
				$read_points                 += 8;
				$read_notes['sentence_length'] = 8;
			}

			$max_para_words = 0;
			foreach ( $blocks as $b ) {
				$bw             = preg_split( '~\s+~u', $b, -1, PREG_SPLIT_NO_EMPTY );
				$max_para_words = max( $max_para_words, is_array( $bw ) ? count( $bw ) : 0 );
			}
			if ( $max_para_words <= 150 ) {
				$read_points                  += 25;
				$read_notes['paragraph_length'] = 25;
			} elseif ( $max_para_words <= 220 ) {
				$read_points                  += 15;
				$read_notes['paragraph_length'] = 15;
			} else {
				$read_points                  += 5;
				$read_notes['paragraph_length'] = 5;
			}

			$has_subheadings = (bool) preg_match( '~<(h2|h3|table|ul|ol)\b~i', $clean_html )
				|| ( 'term' === $object_type && (bool) preg_match( '~\n\s*<strong>[^<]{15,120}</strong>\s*\n~u', $clean_html ) );
			if ( $has_subheadings || $word_count < 280 ) {
				$read_points                    += 25;
				$read_notes['subheading_struct'] = 25;
			} else {
				$read_points                    += 10;
				$read_notes['subheading_struct'] = 10;
			}

			if ( $word_count >= 100 ) {
				$read_points                 += 15;
				$read_notes['copy_sufficiency'] = 15;
			} else {
				$read_points                 += 8;
				$read_notes['copy_sufficiency'] = 8;
			}
		}

		$final_seo  = max( 0, min( 100, $seo_points ) );
		$final_read = max( 0, min( 100, $read_points ) );

		return array(
			'linkdex'       => $final_seo,
			'content_score' => $final_read,
			'breakdown'     => array(
				'word_count'        => $word_count,
				'title_length'      => $title_len,
				'desc_length'       => $desc_len,
				'seo_criteria'      => $seo_notes,
				'read_criteria'     => $read_notes,
			),
		);
	}

	/** UTF-8 safe lowercase helper. */
	private static function mb_lower( $str ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $str, 'UTF-8' ) : strtolower( (string) $str );
	}

	/** UTF-8 safe length helper. */
	private static function mb_len( $str ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $str, 'UTF-8' ) : strlen( (string) $str );
	}

	/** UTF-8 safe strpos helper. */
	private static function mb_pos( $haystack, $needle ) {
		return function_exists( 'mb_strpos' ) ? mb_strpos( (string) $haystack, (string) $needle, 0, 'UTF-8' ) : strpos( (string) $haystack, (string) $needle );
	}

	/** UTF-8 safe substr helper. */
	private static function mb_sub( $str, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $str, $start, $length, 'UTF-8' ) : substr( (string) $str, $start, $length );
	}
}
