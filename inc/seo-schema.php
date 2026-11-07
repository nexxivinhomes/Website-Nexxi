<?php
/**
 * NEXXI Kids — E-commerce & Brand Structured Data (Schema.org JSON-LD).
 *
 * - Outputs Google Merchant-compliant @type="ProductGroup" (variable products with hasVariant)
 *   and @type="Product" + "Offer" (with StrikethroughPrice, mpn, PeopleAudience) on single product pages.
 * - Sanitizes Yoast SEO runtime schema graph (WebPage, BreadcrumbList, ImageObject, Organization).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip raw POS batch prefixes (e.g. "2610.11", "2609.8", "2503.8") and HTML tags from titles/names.
 *
 * @param string $raw_text Raw title or breadcrumb label.
 * @return string
 */
function nexxi_kids_schema_clean_pos_prefix( $raw_text ) {
	$raw_clean = trim( wp_strip_all_tags( (string) $raw_text ) );
	if ( '' === $raw_clean ) {
		return '';
	}

	$decoded = html_entity_decode( $raw_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$cleaned = preg_replace( '/^\s*\d{3,4}\.\d+(?:\s*[-–:]\s*|\s*)/u', '', $decoded );
	$cleaned = trim( preg_replace( '/\s+/u', ' ', (string) $cleaned ) );

	if ( '' === $cleaned ) {
		return $raw_clean;
	}

	if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) && function_exists( 'mb_strtolower' ) ) {
		$first = mb_substr( $cleaned, 0, 1, 'UTF-8' );
		if ( $first === mb_strtolower( $first, 'UTF-8' ) && $first !== mb_strtoupper( $first, 'UTF-8' ) ) {
			$cleaned = mb_strtoupper( $first, 'UTF-8' ) . mb_substr( $cleaned, 1, null, 'UTF-8' );
		}
	}

	return $cleaned;
}

/**
 * Normalize a SKU string by stripping tags, replacing internal whitespace with hyphens,
 * and trimming leading/trailing whitespace or hyphens.
 *
 * @param mixed $raw_sku Raw SKU value.
 * @return string
 */
function nexxi_kids_schema_normalize_sku( $raw_sku ) {
	$sku = trim( wp_strip_all_tags( (string) $raw_sku ) );
	if ( '' === $sku ) {
		return '';
	}
	$sku = preg_replace( '/\s+/u', '-', $sku );
	$sku = preg_replace( '/-+/', '-', (string) $sku );
	$sku = trim( (string) $sku, " \t\n\r\0\x0B-" );
	return $sku;
}

/**
 * Resolve clean product SKU with strict fallback chain:
 * $product->get_sku() -> _nexxi_pancake_sku -> first child variation _sku -> 'NEXXI-' . $product->get_id().
 *
 * @param WC_Product $product WooCommerce product instance.
 * @return string
 */
function nexxi_kids_schema_resolve_sku( WC_Product $product ) {
	$product_id = (int) $product->get_id();

	$sku = nexxi_kids_schema_normalize_sku( $product->get_sku() );
	if ( '' !== $sku ) {
		return $sku;
	}

	$pancake_sku = nexxi_kids_schema_normalize_sku( get_post_meta( $product_id, '_nexxi_pancake_sku', true ) );
	if ( '' !== $pancake_sku ) {
		return $pancake_sku;
	}

	if ( $product->is_type( 'variable' ) ) {
		$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
		if ( ! empty( $children ) && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $children );
		}
		foreach ( $children as $child_id ) {
			$child_sku = nexxi_kids_schema_normalize_sku( get_post_meta( $child_id, '_sku', true ) );
			if ( '' !== $child_sku ) {
				return $child_sku;
			}
		}
	}

	return 'NEXXI-' . $product_id;
}

/**
 * Ensure a URL is an absolute HTTP(S) URL and reject inline data URIs.
 *
 * @param mixed $url Candidate URL.
 * @return string
 */
function nexxi_kids_schema_ensure_absolute_url( $url ) {
	if ( ! is_string( $url ) ) {
		return '';
	}
	$url = trim( $url );
	if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
		return '';
	}
	if ( 0 === strpos( $url, '//' ) ) {
		$url = 'https:' . $url;
	} elseif ( 0 === strpos( $url, '/' ) ) {
		$url = home_url( $url );
	}
	if ( ! preg_match( '~^https?://~i', $url ) ) {
		return '';
	}
	return esc_url_raw( $url );
}

/**
 * Check whether an image URL is a WooCommerce placeholder, SVG icon, WP emoji, or data URI.
 *
 * @param mixed $url Candidate image URL.
 * @return bool
 */
function nexxi_kids_schema_is_placeholder_image_url( $url ) {
	if ( ! is_string( $url ) ) {
		return true;
	}
	$clean = strtolower( trim( $url ) );
	if ( '' === $clean || 0 === strpos( $clean, 'data:' ) ) {
		return true;
	}
	$path = (string) wp_parse_url( $clean, PHP_URL_PATH );
	if ( '' !== $path && preg_match( '~\.svg$~i', $path ) ) {
		return true;
	}
	$blocked_patterns = array(
		'woocommerce-placeholder',
		'/woocommerce/assets/images/placeholder',
		's.w.org/images/core/emoji',
		'/wp-includes/images/smilies/',
		'blank.gif',
		'spacer.gif',
	);
	foreach ( $blocked_patterns as $pattern ) {
		if ( false !== strpos( $clean, $pattern ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Extract valid, non-placeholder HTTP(S) image URLs from HTML content (description fallback).
 *
 * @param string $html Raw HTML content.
 * @return array<int, string>
 */
function nexxi_kids_schema_extract_html_image_urls( $html ) {
	if ( ! is_string( $html ) || '' === trim( $html ) || false === stripos( $html, '<img' ) ) {
		return array();
	}
	$found = array();
	if ( preg_match_all( '~<img\b[^>]*>~iu', $html, $img_tags ) ) {
		foreach ( $img_tags[0] as $img_tag ) {
			$candidate = '';
			foreach ( array( 'data-lazy-src', 'data-src', 'src' ) as $attr_name ) {
				if ( preg_match( '~\b' . preg_quote( $attr_name, '~' ) . '\s*=\s*(["\'])([^"\']+)\1~iu', $img_tag, $m ) ) {
					$decoded = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$abs     = nexxi_kids_schema_ensure_absolute_url( $decoded );
					if ( '' !== $abs && ! nexxi_kids_schema_is_placeholder_image_url( $abs ) ) {
						$candidate = $abs;
						break;
					}
				}
			}
			if ( '' !== $candidate ) {
				$found[] = $candidate;
			}
		}
	}
	return array_values( array_unique( $found ) );
}

/**
 * Collect unique, non-empty full-resolution product image URLs from main image,
 * gallery attachments, variation thumbnails, _nexxi_class_image_map, and HTML description fallback.
 * Never returns WooCommerce placeholder images.
 *
 * @param WC_Product $product WooCommerce product instance.
 * @return array<int, string>
 */
function nexxi_kids_schema_collect_product_images( WC_Product $product ) {
	$product_id  = (int) $product->get_id();
	$att_ids     = array();
	$direct_urls = array();

	$main_id = (int) $product->get_image_id();
	if ( $main_id > 0 ) {
		$att_ids[] = $main_id;
	}

	foreach ( (array) $product->get_gallery_image_ids() as $gallery_id ) {
		$gid = (int) $gallery_id;
		if ( $gid > 0 ) {
			$att_ids[] = $gid;
		}
	}

	if ( $product->is_type( 'variable' ) ) {
		$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
		if ( ! empty( $children ) && function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $children );
		}
		foreach ( $children as $child_id ) {
			$var_thumb_id = (int) get_post_meta( $child_id, '_thumbnail_id', true );
			if ( $var_thumb_id > 0 ) {
				$att_ids[] = $var_thumb_id;
			}
		}
	}

	$class_map = get_post_meta( $product_id, '_nexxi_class_image_map', true );
	if ( is_array( $class_map ) ) {
		foreach ( $class_map as $cinfo ) {
			if ( ! is_array( $cinfo ) ) {
				continue;
			}
			$mapped_url = '';
			if ( ! empty( $cinfo['att_id'] ) && (int) $cinfo['att_id'] > 0 ) {
				$mapped_url = wp_get_attachment_image_url( (int) $cinfo['att_id'], 'full' );
			}
			if ( ! $mapped_url && ! empty( $cinfo['url'] ) && is_string( $cinfo['url'] ) ) {
				$mapped_url = $cinfo['url'];
			}
			if ( $mapped_url ) {
				$direct_urls[] = $mapped_url;
			}
		}
	}

	$images = array();
	foreach ( array_unique( $att_ids ) as $att_id ) {
		$full_url = wp_get_attachment_image_url( $att_id, 'full' );
		$abs_url  = nexxi_kids_schema_ensure_absolute_url( $full_url );
		if ( '' !== $abs_url && ! nexxi_kids_schema_is_placeholder_image_url( $abs_url ) ) {
			$images[] = $abs_url;
		}
	}

	foreach ( $direct_urls as $raw_url ) {
		$abs_url = nexxi_kids_schema_ensure_absolute_url( $raw_url );
		if ( '' !== $abs_url && ! nexxi_kids_schema_is_placeholder_image_url( $abs_url ) ) {
			$images[] = $abs_url;
		}
	}

	$images = array_values( array_unique( array_filter( $images ) ) );

	// Fallback: scan real <img> URLs embedded in product description or short description.
	if ( empty( $images ) ) {
		$desc_imgs = array_merge(
			nexxi_kids_schema_extract_html_image_urls( (string) $product->get_description() ),
			nexxi_kids_schema_extract_html_image_urls( (string) $product->get_short_description() )
		);
		$images    = array_values( array_unique( array_filter( $desc_imgs ) ) );
	}

	return $images;
}

/**
 * Strip HTML tags, shortcodes, line breaks, and #hashtags from a raw description block.
 *
 * @param string $raw_html Raw HTML/text content.
 * @return string
 */
function nexxi_kids_schema_sanitize_description_text( $raw_html ) {
	$text = (string) $raw_html;
	if ( '' === trim( $text ) ) {
		return '';
	}
	if ( function_exists( 'strip_shortcodes' ) ) {
		$text = strip_shortcodes( $text );
	}
	$text = preg_replace( '~</?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|blockquote|section|article)\b[^>]*>~iu', ' ', $text );
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/(?<!\p{L})(?<!\p{N})#[\p{L}\p{N}_-]+/u', ' ', $text );
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	return $text;
}

/**
 * Build a clean product description (~45-60 words) from short_description or description.
 *
 * @param WC_Product $product    WooCommerce product instance.
 * @param string     $clean_name Clean product title for ultimate fallback.
 * @return string
 */
function nexxi_kids_schema_build_description( WC_Product $product, $clean_name = '' ) {
	$short_clean = nexxi_kids_schema_sanitize_description_text( $product->get_short_description() );
	if ( '' !== $short_clean ) {
		$words = preg_split( '/\s+/u', $short_clean, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $words ) && count( $words ) > 60 ) {
			return wp_trim_words( $short_clean, 55, '…' );
		}
		return $short_clean;
	}

	$full_clean = nexxi_kids_schema_sanitize_description_text( $product->get_description() );
	if ( '' !== $full_clean ) {
		return wp_trim_words( $full_clean, 52, '…' );
	}

	$label = '' !== trim( (string) $clean_name ) ? trim( (string) $clean_name ) : 'Trang phục trẻ em';
	return sprintf(
		'%s chính hãng NEXXI Kids Fashion, chất liệu mềm thoáng an toàn cho làn da của bé, miễn phí vận chuyển toàn quốc và hỗ trợ đổi hàng trong 15 ngày.',
		$label
	);
}

/**
 * Format category/attribute display labels cleanly (ALL-CAPS -> Title Case while preserving acronyms).
 *
 * @param string $raw_label Raw taxonomy term or attribute label.
 * @return string
 */
function nexxi_kids_schema_format_label( $raw_label ) {
	$clean = trim( wp_strip_all_tags( (string) $raw_label ) );
	if ( '' === $clean ) {
		return '';
	}
	$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$clean = trim( preg_replace( '/\s+/u', ' ', (string) $clean ) );
	if ( '' === $clean ) {
		return '';
	}

	if ( function_exists( 'nexxi_seo_format_term_name' ) ) {
		$formatted = trim( (string) nexxi_seo_format_term_name( $clean ) );
		if ( '' !== $formatted ) {
			return $formatted;
		}
	}

	if ( function_exists( 'mb_strtoupper' ) && function_exists( 'mb_strtolower' ) && function_exists( 'mb_convert_case' ) ) {
		$upper = mb_strtoupper( $clean, 'UTF-8' );
		$lower = mb_strtolower( $clean, 'UTF-8' );
		if ( $clean === $upper && $clean !== $lower && mb_strlen( $clean, 'UTF-8' ) > 2 ) {
			$titled = mb_convert_case( $lower, MB_CASE_TITLE, 'UTF-8' );
			$titled = preg_replace_callback(
				'/\b(Nexxi|Xs|Xl|Xxl|2xl|3xl|Uv|3d)\b/u',
				static function ( $m ) {
					return strtoupper( $m[1] );
				},
				(string) $titled
			);
			return trim( (string) $titled );
		}
	}

	return $clean;
}

/**
 * Strip Vietnamese diacritics to lowercase ASCII slug tokens for reliable gender/role matching.
 *
 * @param string $text Input UTF-8 string.
 * @return string
 */
function nexxi_kids_schema_ascii_slug( $text ) {
	$raw = trim( wp_strip_all_tags( (string) $text ) );
	if ( '' === $raw ) {
		return '';
	}
	if ( function_exists( 'remove_accents' ) ) {
		$raw = remove_accents( $raw );
	}
	$vi_map = array(
		'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
		'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
		'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
		'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
		'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
		'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
		'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
		'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
		'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
		'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
		'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
		'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
		'đ' => 'd', 'Đ' => 'd',
	);
	$raw    = strtr( function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw, 'UTF-8' ) : strtolower( $raw ), $vi_map );
	$slug   = preg_replace( '/[^a-z0-9]+/', '-', $raw );
	return trim( (string) $slug, '-' );
}

/**
 * Resolve normalized primary category (Title Case), PeopleAudience (gender + 1..13 yrs), and optional material.
 *
 * @param WC_Product $product    WooCommerce product instance.
 * @param string     $clean_name Clean product title.
 * @return array{category: string, audience: array<string, mixed>, material: string}
 */
function nexxi_kids_schema_resolve_merchandising_meta( WC_Product $product, $clean_name = '' ) {
	$product_id   = (int) $product->get_id();
	$category     = '';
	$signal_parts = array( $clean_name );

	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || empty( $term->slug ) || empty( $term->name ) ) {
				continue;
			}
			if ( in_array( $term->slug, array( 'chua-phan-loai', 'uncategorized' ), true ) ) {
				continue;
			}
			if ( '' === $category ) {
				$category = nexxi_kids_schema_format_label( (string) $term->name );
			}
			$signal_parts[] = (string) $term->slug;
			$signal_parts[] = (string) $term->name;
		}
	}

	$haystack  = '-' . nexxi_kids_schema_ascii_slug( implode( ' ', $signal_parts ) ) . '-';
	$is_unisex = ( false !== strpos( $haystack, '-unisex-' ) )
		|| ( false !== strpos( $haystack, '-be-gai-' ) && false !== strpos( $haystack, '-be-trai-' ) );
	$is_female = (bool) preg_match( '~-(be-gai|dam|vay|chan-vay|bup-be|cong-chua|co-yem|croptop|bikini)-~', $haystack );
	$is_male   = (bool) preg_match( '~-(be-trai|cong-tu|soai-ca|bo-vest)-~', $haystack );

	if ( $is_unisex || ( $is_female && $is_male ) ) {
		$suggested_gender = 'unisex';
	} elseif ( $is_female ) {
		$suggested_gender = 'https://schema.org/Female';
	} elseif ( $is_male ) {
		$suggested_gender = 'https://schema.org/Male';
	} else {
		$suggested_gender = 'unisex';
	}

	$material = '';
	$mat_terms = get_the_terms( $product_id, 'pa_chat-lieu' );
	if ( is_array( $mat_terms ) && ! is_wp_error( $mat_terms ) ) {
		$mat_names = array();
		foreach ( $mat_terms as $mt ) {
			if ( is_object( $mt ) && ! empty( $mt->name ) ) {
				$lbl = nexxi_kids_schema_format_label( (string) $mt->name );
				if ( '' !== $lbl ) {
					$mat_names[] = $lbl;
				}
			}
		}
		if ( ! empty( $mat_names ) ) {
			$material = implode( ', ', array_unique( $mat_names ) );
		}
	}

	return array(
		'category' => $category,
		'audience' => array(
			'@type'           => 'PeopleAudience',
			'suggestedGender' => $suggested_gender,
			'suggestedMinAge' => 1,
			'suggestedMaxAge' => 13,
		),
		'material' => $material,
	);
}

/**
 * Official NEXXI 15-day exchange/return policy Schema object (VN).
 * Customer pays return postal shipping when exchanging size/style (matches /doi-tra/ policy).
 *
 * @param bool $include_id Whether to attach the canonical @id (used on Organization node).
 * @return array<string, mixed>
 */
function nexxi_kids_get_merchant_return_policy_schema( $include_id = false ) {
	$policy = array(
		'@type'                => 'MerchantReturnPolicy',
		'applicableCountry'    => 'VN',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
		'merchantReturnDays'   => 15,
		'returnMethod'         => 'https://schema.org/ReturnByMail',
		'returnFees'           => 'https://schema.org/ReturnFeesCustomerResponsibility',
		'merchantReturnLink'   => home_url( '/doi-tra/' ),
	);

	if ( $include_id ) {
		$policy = array( '@id' => home_url( '/doi-tra/#return-policy' ) ) + $policy;
	}

	return $policy;
}

/**
 * Official NEXXI Club loyalty program Schema object nested under Organization.
 *
 * @return array<string, mixed>
 */
function nexxi_kids_get_member_program_schema() {
	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/tai-khoan/' );
	$account_url = nexxi_kids_schema_ensure_absolute_url( $account_url );
	if ( '' === $account_url ) {
		$account_url = home_url( '/tai-khoan/' );
	}

	return array(
		'@type'       => 'MemberProgram',
		'@id'         => home_url( '/tai-khoan/#nexxi-club' ),
		'name'        => 'NEXXI Club',
		'description' => 'Chương trình thành viên NEXXI Club tích điểm tự động trên mọi đơn hàng (10.000₫ = 1 điểm thành viên).',
		'url'         => $account_url,
		'hasTiers'    => array(
			array(
				'@type'                  => 'MemberProgramTier',
				'@id'                    => home_url( '/tai-khoan/#nexxi-club-member' ),
				'name'                   => 'Thành viên NEXXI Club',
				'url'                    => $account_url,
				'hasTierBenefit'         => array(
					'https://schema.org/TierBenefitLoyaltyPoints',
				),
				'membershipPointsEarned' => array(
					'@type' => 'QuantitativeValue',
					'value' => 0.0001,
				),
			),
		),
	);
}

/**
 * Official NEXXI nationwide free shipping policy Schema object (VN).
 *
 * @return array<string, mixed>
 */
function nexxi_kids_get_offer_shipping_details_schema() {
	return array(
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => array(
			'@type'    => 'MonetaryAmount',
			'value'    => '0',
			'currency' => 'VND',
		),
		'shippingDestination' => array(
			'@type'          => 'DefinedRegion',
			'addressCountry' => 'VN',
		),
		'deliveryTime'        => array(
			'@type'        => 'ShippingDeliveryTime',
			'handlingTime' => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 0,
				'maxValue' => 1,
				'unitCode' => 'DAY',
			),
			'transitTime'  => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 1,
				'maxValue' => 5,
				'unitCode' => 'DAY',
			),
		),
	);
}

/**
 * Resolve ISO 8601 (YYYY-MM-DD) priceValidUntil date for a product or its variations.
 *
 * @param WC_Product               $product WooCommerce product instance.
 * @param array<string,mixed>|null $prog    Combined sale program data if available.
 * @return string
 */
function nexxi_kids_schema_resolve_price_valid_until( WC_Product $product, $prog = null ) {
	$price_valid_until = gmdate( 'Y-12-31', strtotime( '+1 year' ) );

	if ( is_array( $prog ) && ! empty( $prog['flash_on_sale'] ) && function_exists( 'nexxi_kids_flash_end' ) ) {
		$fs_end = (int) nexxi_kids_flash_end();
		if ( $fs_end > time() ) {
			return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $fs_end ) : gmdate( 'Y-m-d', $fs_end );
		}
	}

	if ( method_exists( $product, 'get_date_on_sale_to' ) ) {
		$sale_to = $product->get_date_on_sale_to();
		if ( is_object( $sale_to ) && method_exists( $sale_to, 'getTimestamp' ) && $sale_to->getTimestamp() > time() ) {
			return $sale_to->date( 'Y-m-d' );
		}
	}

	return $price_valid_until;
}

/**
 * Construct a single Google Merchant-compliant Offer node with StrikethroughPrice when regular > sale.
 *
 * @param string $offer_url         Offer landing URL (product or specific variant URL).
 * @param float  $sale_price        Active selling price in VND.
 * @param float  $regular_price     Original regular price in VND (0 if not discounted).
 * @param string $availability      Schema.org availability URI.
 * @param string $price_valid_until ISO YYYY-MM-DD date string.
 * @return array<string, mixed>|null
 */
function nexxi_kids_schema_build_offer_node( $offer_url, $sale_price, $regular_price, $availability, $price_valid_until ) {
	$price_int   = (int) round( (float) $sale_price );
	$regular_int = (int) round( (float) $regular_price );
	if ( $price_int <= 0 ) {
		return null;
	}

	$offer = array(
		'@type'           => 'Offer',
		'url'             => $offer_url,
		'priceCurrency'   => 'VND',
		'price'           => (string) $price_int,
		'priceValidUntil' => $price_valid_until,
	);

	if ( $regular_int > $price_int ) {
		$offer['priceSpecification'] = array(
			'@type'         => 'UnitPriceSpecification',
			'priceType'     => 'https://schema.org/StrikethroughPrice',
			'price'         => (string) $regular_int,
			'priceCurrency' => 'VND',
		);
	} elseif ( function_exists( 'nexxi_kids_calculate_order_points' ) ) {
		$pts = (int) nexxi_kids_calculate_order_points( $price_int );
		if ( $pts > 0 ) {
			$offer['priceSpecification'] = array(
				'@type'                  => 'UnitPriceSpecification',
				'membershipPointsEarned' => $pts,
				'validForMemberTier'     => array(
					'@type' => 'MemberProgramTier',
					'@id'   => home_url( '/tai-khoan/#nexxi-club-member' ),
				),
			);
		}
	}

	$offer['itemCondition']           = 'https://schema.org/NewCondition';
	$offer['availability']            = $availability;
	$offer['seller']                  = array(
		'@type' => 'Organization',
		'@id'   => home_url( '/#organization' ),
		'name'  => 'NEXXI Kids Fashion',
	);
	$offer['shippingDetails']         = nexxi_kids_get_offer_shipping_details_schema();
	$offer['hasMerchantReturnPolicy'] = nexxi_kids_get_merchant_return_policy_schema( false );

	return $offer;
}

/**
 * Build Google Merchant-compliant Offer object (never AggregateOffer) for a single/simple product.
 *
 * @param WC_Product $product   WooCommerce product instance.
 * @param string     $permalink Canonical product permalink.
 * @return array<string, mixed>|null
 */
function nexxi_kids_schema_build_offer( WC_Product $product, $permalink ) {
	if ( function_exists( 'nexxi_kids_normalize_variable_sale' ) ) {
		nexxi_kids_normalize_variable_sale( $product );
	}

	$prog        = function_exists( 'nexxi_kids_get_program_sale' ) ? nexxi_kids_get_program_sale( $product, 'combined' ) : null;
	$raw_price   = 0.0;
	$regular_raw = 0.0;

	if ( is_array( $prog ) ) {
		if ( isset( $prog['sale'] ) && (float) $prog['sale'] > 0 ) {
			$raw_price = (float) $prog['sale'];
		}
		if ( isset( $prog['regular'] ) && (float) $prog['regular'] > 0 ) {
			$regular_raw = (float) $prog['regular'];
		}
	}

	if ( $raw_price <= 0 ) {
		$raw_price = (float) $product->get_price();
		if ( $raw_price <= 0 && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$raw_price = (float) $product->get_variation_price( 'min', true );
		}
		if ( $raw_price <= 0 ) {
			$raw_price = (float) $product->get_regular_price();
		}
	}

	if ( $regular_raw <= 0 ) {
		$regular_raw = (float) $product->get_regular_price();
		if ( $regular_raw <= 0 && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_regular_price' ) ) {
			$regular_raw = (float) $product->get_variation_regular_price( 'min', true );
		}
	}

	$price_valid_until = nexxi_kids_schema_resolve_price_valid_until( $product, $prog );

	$stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
	if ( 'onbackorder' === $stock_status ) {
		$availability = 'https://schema.org/BackOrder';
	} elseif ( $product->is_in_stock() ) {
		$availability = 'https://schema.org/InStock';
	} else {
		$availability = 'https://schema.org/OutOfStock';
	}

	return nexxi_kids_schema_build_offer_node( $permalink, $raw_price, $regular_raw, $availability, $price_valid_until );
}

/**
 * Retrieve variation attribute definitions (delegating to Nexxi_Kids_Catalog when active, with WC fallback).
 *
 * @param WC_Product $product WooCommerce variable product.
 * @return array<int, array<string, mixed>>
 */
function nexxi_kids_schema_get_variation_attribute_defs( WC_Product $product ) {
	if ( class_exists( 'Nexxi_Kids_Catalog' ) && method_exists( 'Nexxi_Kids_Catalog', 'variation_attributes' ) ) {
		$defs = Nexxi_Kids_Catalog::variation_attributes( $product );
		if ( is_array( $defs ) && ! empty( $defs ) ) {
			return $defs;
		}
	}

	if ( ! method_exists( $product, 'get_attributes' ) ) {
		return array();
	}

	$list  = array();
	$taken = array();
	foreach ( (array) $product->get_attributes() as $attr ) {
		if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_variation' ) || ! $attr->get_variation() ) {
			continue;
		}
		$name   = (string) $attr->get_name();
		$label  = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $name, $product ) : $name;
		$is_tax = method_exists( $attr, 'is_taxonomy' ) ? (bool) $attr->is_taxonomy() : ( 0 === strpos( $name, 'pa_' ) );
		$hay    = '-' . nexxi_kids_schema_ascii_slug( $name . ' ' . $label ) . '-';
		$role   = '';
		if ( preg_match( '~(size|kich-co|kich-thuoc|-co-)~', $hay ) ) {
			$role = 'size';
		} elseif ( preg_match( '~(mau|color|colour)~', $hay ) ) {
			$role = 'color';
		}
		if ( '' !== $role && isset( $taken[ $role ] ) ) {
			$role = '';
		}
		if ( '' !== $role ) {
			$taken[ $role ] = true;
		}

		$options = array();
		if ( $is_tax ) {
			$terms = get_the_terms( (int) $product->get_id(), $name );
			if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					if ( is_object( $t ) && ! empty( $t->slug ) ) {
						$options[] = array(
							'value' => (string) $t->slug,
							'name'  => (string) $t->name,
						);
					}
				}
			}
		} elseif ( method_exists( $attr, 'get_options' ) ) {
			foreach ( (array) $attr->get_options() as $opt ) {
				$opt_str   = trim( (string) $opt );
				$options[] = array(
					'value' => sanitize_title( $opt_str ),
					'name'  => $opt_str,
				);
			}
		}

		$list[] = array(
			'key'      => sanitize_title( $name ),
			'label'    => $label,
			'role'     => $role,
			'taxonomy' => $is_tax,
			'options'  => $options,
		);
	}

	return $list;
}

/**
 * Build @type="Product" variant nodes and variesBy dimension URIs for a WooCommerce variable product.
 *
 * @param WC_Product               $product           Variable WooCommerce product.
 * @param string                   $permalink         Canonical parent product URL.
 * @param string                   $clean_name        Clean parent product title.
 * @param string                   $description       Clean parent product description.
 * @param string                   $parent_sku        Normalized parent SKU / productGroupID.
 * @param array<int, string>       $images            Verified real parent/gallery image URLs.
 * @param array<string,mixed>|null $prog              Combined sale program metadata.
 * @param string                   $price_valid_until ISO YYYY-MM-DD date string.
 * @return array{variants: array<int, array<string, mixed>>, varies_by: array<int, string>}
 */
function nexxi_kids_schema_build_variants( WC_Product $product, $permalink, $clean_name, $description, $parent_sku, array $images, $prog, $price_valid_until ) {
	$children = array_values( array_filter( array_map( 'absint', (array) $product->get_children() ) ) );
	if ( empty( $children ) || empty( $images ) ) {
		return array(
			'variants'  => array(),
			'varies_by' => array(),
		);
	}

	if ( function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $children, false, true );
	} elseif ( function_exists( 'update_meta_cache' ) ) {
		update_meta_cache( 'post', $children );
	}

	$defs          = nexxi_kids_schema_get_variation_attribute_defs( $product );
	$color_img_map = array();
	$class_map     = get_post_meta( (int) $product->get_id(), '_nexxi_class_image_map', true );
	if ( is_array( $class_map ) ) {
		foreach ( $class_map as $ckey => $cinfo ) {
			if ( ! is_array( $cinfo ) ) {
				continue;
			}
			$c_url = '';
			if ( ! empty( $cinfo['att_id'] ) && (int) $cinfo['att_id'] > 0 ) {
				$c_url = wp_get_attachment_image_url( (int) $cinfo['att_id'], 'full' );
			}
			if ( ! $c_url && ! empty( $cinfo['url'] ) && is_string( $cinfo['url'] ) ) {
				$c_url = $cinfo['url'];
			}
			$abs_c_url = nexxi_kids_schema_ensure_absolute_url( $c_url );
			if ( '' !== $abs_c_url && ! nexxi_kids_schema_is_placeholder_image_url( $abs_c_url ) ) {
				$color_img_map[ (string) $ckey ]                        = $abs_c_url;
				$color_img_map[ nexxi_kids_schema_ascii_slug( $ckey ) ] = $abs_c_url;
			}
		}
	}

	$variants      = array();
	$used_skus     = array();
	$used_urls     = array();
	$has_size_dim  = false;
	$has_color_dim = false;
	$parent_in_stk = (bool) $product->is_in_stock();
	$prog_reg      = ( is_array( $prog ) && ! empty( $prog['regular'] ) ) ? (float) $prog['regular'] : 0.0;
	$prog_on_sale  = ( is_array( $prog ) && ! empty( $prog['on_sale'] ) && function_exists( 'nexxi_kids_calc_combined_variation_price' ) );

	foreach ( $children as $child_id ) {
		$status = function_exists( 'get_post_status' ) ? get_post_status( $child_id ) : 'publish';
		if ( false !== $status && 'publish' !== $status ) {
			continue;
		}

		$meta_reg   = (float) get_post_meta( $child_id, '_regular_price', true );
		$meta_price = (float) get_post_meta( $child_id, '_price', true );
		$var_reg    = $meta_reg > 0 ? $meta_reg : ( $meta_price > 0 ? $meta_price : $prog_reg );
		$var_sale   = ( $prog_on_sale && $var_reg > 0 )
			? (float) nexxi_kids_calc_combined_variation_price( $var_reg, $prog )
			: ( $meta_price > 0 ? $meta_price : $var_reg );

		if ( (int) round( $var_sale ) <= 0 ) {
			continue;
		}

		$manage_stock = 'yes' === (string) get_post_meta( $child_id, '_manage_stock', true );
		$stock_qty    = $manage_stock ? (int) get_post_meta( $child_id, '_stock', true ) : null;
		$backorders   = (string) get_post_meta( $child_id, '_backorders', true );
		$stock_status = (string) get_post_meta( $child_id, '_stock_status', true );
		$backorder_ok = in_array( $backorders, array( 'yes', 'notify' ), true );

		if ( 'onbackorder' === $stock_status || ( $manage_stock && $stock_qty <= 0 && $backorder_ok ) ) {
			$var_availability = 'https://schema.org/BackOrder';
		} elseif ( ( $manage_stock ? ( $stock_qty > 0 ) : ( 'outofstock' !== $stock_status ) ) && $parent_in_stk ) {
			$var_availability = 'https://schema.org/InStock';
		} else {
			$var_availability = 'https://schema.org/OutOfStock';
		}

		$size_label  = '';
		$color_label = '';
		$color_slug  = '';
		$other_parts = array();
		$query_args  = array();
		$sku_tokens  = array();

		if ( ! empty( $defs ) ) {
			foreach ( $defs as $def ) {
				$def_key  = isset( $def['key'] ) ? (string) $def['key'] : '';
				if ( '' === $def_key ) {
					continue;
				}
				$meta_key = 'attribute_' . $def_key;
				$raw_val  = trim( (string) get_post_meta( $child_id, $meta_key, true ) );
				if ( '' === $raw_val && 0 !== strpos( $def_key, 'pa_' ) ) {
					$alt_val = trim( (string) get_post_meta( $child_id, 'attribute_pa_' . $def_key, true ) );
					if ( '' !== $alt_val ) {
						$meta_key = 'attribute_pa_' . $def_key;
						$raw_val  = $alt_val;
					}
				}
				if ( '' === $raw_val ) {
					continue;
				}

				$query_args[ $meta_key ] = $raw_val;
				$norm_cmp                = ! empty( $def['taxonomy'] ) ? $raw_val : sanitize_title( $raw_val );
				$display_val             = $raw_val;

				if ( ! empty( $def['options'] ) && is_array( $def['options'] ) ) {
					foreach ( $def['options'] as $opt ) {
						if ( is_array( $opt ) && isset( $opt['value'] ) && (string) $opt['value'] === $norm_cmp && ! empty( $opt['name'] ) ) {
							$display_val = (string) $opt['name'];
							break;
						}
					}
				}

				$display_clean = nexxi_kids_schema_format_label( $display_val );
				$sku_tokens[]  = strtoupper( nexxi_kids_schema_ascii_slug( $display_clean ? $display_clean : $raw_val ) );

				$role = isset( $def['role'] ) ? (string) $def['role'] : '';
				if ( 'size' === $role ) {
					$size_label   = $display_clean;
					$has_size_dim = true;
				} elseif ( 'color' === $role ) {
					$color_label   = $display_clean;
					$color_slug    = $norm_cmp;
					$has_color_dim = true;
				} elseif ( '' !== $display_clean ) {
					$other_parts[] = $display_clean;
				}
			}
		} else {
			$all_meta = get_post_meta( $child_id );
			if ( is_array( $all_meta ) ) {
				foreach ( $all_meta as $mkey => $mvals ) {
					if ( 0 !== strpos( (string) $mkey, 'attribute_' ) || empty( $mvals[0] ) ) {
						continue;
					}
					$mval = trim( (string) $mvals[0] );
					if ( '' === $mval ) {
						continue;
					}
					$query_args[ (string) $mkey ] = $mval;
					$sku_tokens[]                 = strtoupper( nexxi_kids_schema_ascii_slug( $mval ) );
					$hay_attr                     = '-' . nexxi_kids_schema_ascii_slug( (string) $mkey ) . '-';
					if ( '' === $size_label && preg_match( '~(size|kich-co|kich-thuoc|-co-)~', $hay_attr ) ) {
						$size_label   = nexxi_kids_schema_format_label( $mval );
						$has_size_dim = true;
					} elseif ( '' === $color_label && preg_match( '~(mau|color|colour)~', $hay_attr ) ) {
						$color_label   = nexxi_kids_schema_format_label( $mval );
						$color_slug    = sanitize_title( $mval );
						$has_color_dim = true;
					} else {
						$other_parts[] = nexxi_kids_schema_format_label( $mval );
					}
				}
			}
		}

		$var_url = ! empty( $query_args )
			? add_query_arg( $query_args, $permalink )
			: add_query_arg( 'variation_id', $child_id, $permalink );
		if ( isset( $used_urls[ $var_url ] ) ) {
			$var_url = add_query_arg( 'variation_id', $child_id, $var_url );
		}
		$used_urls[ $var_url ] = true;

		$raw_child_sku = nexxi_kids_schema_normalize_sku( get_post_meta( $child_id, '_sku', true ) );
		$var_sku       = $raw_child_sku;
		if ( '' === $var_sku || ( count( $children ) > 1 && $var_sku === $parent_sku ) || isset( $used_skus[ $var_sku ] ) ) {
			$suffix  = implode( '-', array_filter( $sku_tokens ) );
			$suffix  = trim( preg_replace( '/-+/', '-', $suffix ), '-' );
			$var_sku = '' !== $suffix ? ( $parent_sku . '-' . $suffix ) : ( $parent_sku . '-' . $child_id );
			if ( isset( $used_skus[ $var_sku ] ) ) {
				$var_sku .= '-' . $child_id;
			}
		}
		$used_skus[ $var_sku ] = true;

		$var_image = '';
		$var_tid   = (int) get_post_meta( $child_id, '_thumbnail_id', true );
		if ( $var_tid > 0 ) {
			$cand_img = nexxi_kids_schema_ensure_absolute_url( wp_get_attachment_image_url( $var_tid, 'full' ) );
			if ( '' !== $cand_img && ! nexxi_kids_schema_is_placeholder_image_url( $cand_img ) ) {
				$var_image = $cand_img;
			}
		}
		if ( '' === $var_image && '' !== $color_slug ) {
			$ascii_color = nexxi_kids_schema_ascii_slug( $color_label ? $color_label : $color_slug );
			if ( isset( $color_img_map[ $color_slug ] ) ) {
				$var_image = $color_img_map[ $color_slug ];
			} elseif ( isset( $color_img_map[ $ascii_color ] ) ) {
				$var_image = $color_img_map[ $ascii_color ];
			}
		}
		if ( '' === $var_image ) {
			$var_image = $images[0];
		}

		$title_parts = array();
		if ( '' !== $color_label ) {
			$title_parts[] = $color_label;
		}
		if ( '' !== $size_label ) {
			$title_parts[] = preg_match( '/^\d+(?:\s*[-–]\s*\d+)?$/u', $size_label ) ? ( 'Size ' . $size_label ) : $size_label;
		}
		foreach ( $other_parts as $op ) {
			if ( '' !== $op ) {
				$title_parts[] = $op;
			}
		}
		$var_name = ! empty( $title_parts )
			? ( $clean_name . ' - ' . implode( ' - ', $title_parts ) )
			: $clean_name;

		$var_offer = nexxi_kids_schema_build_offer_node(
			$var_url,
			$var_sale,
			$var_reg,
			$var_availability,
			$price_valid_until
		);
		if ( null === $var_offer ) {
			continue;
		}

		$variant_node = array(
			'@type'       => 'Product',
			'@id'         => $permalink . '#variant-' . $child_id,
			'name'        => $var_name,
			'description' => $description,
			'sku'         => $var_sku,
			'mpn'         => $var_sku,
			'image'       => $var_image,
			'url'         => $var_url,
		);
		if ( '' !== $size_label ) {
			$variant_node['size'] = $size_label;
		}
		if ( '' !== $color_label ) {
			$variant_node['color'] = $color_label;
		}
		$variant_node['offers'] = $var_offer;

		$variants[] = $variant_node;
	}

	$varies_by = array();
	if ( $has_size_dim ) {
		$varies_by[] = 'https://schema.org/size';
	}
	if ( $has_color_dim ) {
		$varies_by[] = 'https://schema.org/color';
	}

	return array(
		'variants'  => $variants,
		'varies_by' => $varies_by,
	);
}

/**
 * Build verified AggregateRating and approved Review array strictly when genuine reviews exist (> 0).
 * NEVER fabricates ratings or reviews.
 *
 * @param int   $product_id   Product post ID.
 * @param int   $review_count Approved review count.
 * @param float $avg_rating   Average star rating.
 * @return array<string, mixed>
 */
function nexxi_kids_schema_build_reviews_data( $product_id, $review_count, $avg_rating ) {
	if ( $review_count <= 0 || $avg_rating <= 0 ) {
		return array();
	}

	$clamped_rating = min( 5.0, max( 1.0, round( (float) $avg_rating, 2 ) ) );
	$result         = array(
		'aggregateRating' => array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $clamped_rating,
			'reviewCount' => (int) $review_count,
			'bestRating'  => '5',
			'worstRating' => '1',
		),
	);

	$comments = get_comments(
		array(
			'post_id' => (int) $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'number'  => 5,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		)
	);

	if ( is_array( $comments ) && ! empty( $comments ) ) {
		$review_items = array();
		foreach ( $comments as $comment ) {
			if ( ! is_object( $comment ) || empty( $comment->comment_ID ) ) {
				continue;
			}
			$star_rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
			if ( $star_rating < 1 || $star_rating > 5 ) {
				continue;
			}
			$author_name = trim( wp_strip_all_tags( (string) ( $comment->comment_author ?? '' ) ) );
			if ( '' === $author_name ) {
				continue;
			}

			$review_entry = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => $author_name,
				),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $star_rating,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
			);

			$date_iso = '';
			if ( ! empty( $comment->comment_date_gmt ) && '0000-00-00 00:00:00' !== $comment->comment_date_gmt ) {
				$ts = strtotime( $comment->comment_date_gmt . ' UTC' );
				if ( false !== $ts ) {
					$date_iso = gmdate( 'Y-m-d', $ts );
				}
			} elseif ( ! empty( $comment->comment_date ) ) {
				$ts = strtotime( $comment->comment_date );
				if ( false !== $ts ) {
					$date_iso = gmdate( 'Y-m-d', $ts );
				}
			}
			if ( '' !== $date_iso ) {
				$review_entry['datePublished'] = $date_iso;
			}

			$review_body = nexxi_kids_schema_sanitize_description_text( (string) ( $comment->comment_content ?? '' ) );
			if ( '' !== $review_body ) {
				$review_entry['reviewBody'] = $review_body;
			}

			$review_items[] = $review_entry;
		}

		if ( ! empty( $review_items ) ) {
			$result['review'] = $review_items;
		}
	}

	return $result;
}

/**
 * Build complete Schema.org ProductGroup (for variable products) or Product JSON-LD array.
 * Returns null if the product has no real non-placeholder images (avoiding GSC image errors/warnings).
 *
 * @param WC_Product $product WooCommerce product instance.
 * @return array<string, mixed>|null
 */
function nexxi_kids_build_product_schema( WC_Product $product ) {
	$product_id = (int) $product->get_id();
	if ( $product_id <= 0 ) {
		return null;
	}

	$permalink = get_permalink( $product_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		$permalink = $product->get_permalink();
	}
	$permalink = nexxi_kids_schema_ensure_absolute_url( $permalink );
	if ( '' === $permalink ) {
		return null;
	}

	$clean_name = nexxi_kids_schema_clean_pos_prefix( $product->get_name() );
	if ( '' === $clean_name ) {
		return null;
	}

	$images = nexxi_kids_schema_collect_product_images( $product );
	if ( empty( $images ) ) {
		// Do not output Product/ProductGroup with fake placeholder images or missing required "image".
		return null;
	}

	if ( function_exists( 'nexxi_kids_normalize_variable_sale' ) ) {
		nexxi_kids_normalize_variable_sale( $product );
	}
	$prog              = function_exists( 'nexxi_kids_get_program_sale' ) ? nexxi_kids_get_program_sale( $product, 'combined' ) : null;
	$price_valid_until = nexxi_kids_schema_resolve_price_valid_until( $product, $prog );
	$description       = nexxi_kids_schema_build_description( $product, $clean_name );
	$sku               = nexxi_kids_schema_resolve_sku( $product );
	$merch_meta        = nexxi_kids_schema_resolve_merchandising_meta( $product, $clean_name );

	// Strict anti-spam lock: ONLY attach aggregateRating/review when real customer ratings exist (> 0).
	$review_count = (int) $product->get_review_count();
	$avg_rating   = (float) $product->get_average_rating();
	$has_reviews  = ( $review_count > 0 && $avg_rating > 0 );
	$reviews_data = $has_reviews ? nexxi_kids_schema_build_reviews_data( $product_id, $review_count, $avg_rating ) : array();

	if ( $product->is_type( 'variable' ) ) {
		$variant_bundle = nexxi_kids_schema_build_variants(
			$product,
			$permalink,
			$clean_name,
			$description,
			$sku,
			$images,
			$prog,
			$price_valid_until
		);

		if ( ! empty( $variant_bundle['variants'] ) ) {
			$group = array(
				'@context'       => 'https://schema.org',
				'@type'          => 'ProductGroup',
				'@id'            => $permalink . '#product',
				'name'           => $clean_name,
				'description'    => $description,
				'url'            => $permalink,
				'image'          => $images,
				'productGroupID' => $sku,
				'brand'          => array(
					'@type' => 'Brand',
					'name'  => 'NEXXI Kids Fashion',
				),
			);

			if ( ! empty( $variant_bundle['varies_by'] ) ) {
				$group['variesBy'] = $variant_bundle['varies_by'];
			}
			if ( '' !== $merch_meta['category'] ) {
				$group['category'] = $merch_meta['category'];
			}
			$group['audience'] = $merch_meta['audience'];
			if ( '' !== $merch_meta['material'] ) {
				$group['material'] = $merch_meta['material'];
			}
			if ( ! empty( $reviews_data['aggregateRating'] ) ) {
				$group['aggregateRating'] = $reviews_data['aggregateRating'];
			}
			if ( ! empty( $reviews_data['review'] ) ) {
				$group['review'] = $reviews_data['review'];
			}
			$group['hasVariant'] = $variant_bundle['variants'];

			return $group;
		}
	}

	$offer = nexxi_kids_schema_build_offer( $product, $permalink );
	if ( null === $offer && ! $has_reviews ) {
		return null;
	}

	$schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'@id'         => $permalink . '#product',
		'name'        => $clean_name,
		'url'         => $permalink,
		'image'       => $images,
		'description' => $description,
		'sku'         => $sku,
		'mpn'         => $sku,
		'brand'       => array(
			'@type' => 'Brand',
			'name'  => 'NEXXI Kids Fashion',
		),
	);

	if ( '' !== $merch_meta['category'] ) {
		$schema['category'] = $merch_meta['category'];
	}
	$schema['audience'] = $merch_meta['audience'];
	if ( '' !== $merch_meta['material'] ) {
		$schema['material'] = $merch_meta['material'];
	}
	if ( null !== $offer ) {
		$schema['offers'] = $offer;
	}
	if ( ! empty( $reviews_data['aggregateRating'] ) ) {
		$schema['aggregateRating'] = $reviews_data['aggregateRating'];
	}
	if ( ! empty( $reviews_data['review'] ) ) {
		$schema['review'] = $reviews_data['review'];
	}

	return $schema;
}

/**
 * Output Product + Offer JSON-LD in <head> on single product pages.
 */
function nexxi_kids_output_product_schema() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product = function_exists( 'nexxi_kids_current_product' ) ? nexxi_kids_current_product() : null;
	if ( ! ( $product instanceof WC_Product ) && function_exists( 'wc_get_product' ) ) {
		$queried_id = (int) get_queried_object_id();
		if ( $queried_id <= 0 ) {
			$queried_id = (int) get_the_ID();
		}
		if ( $queried_id > 0 ) {
			$product = wc_get_product( $queried_id );
		}
	}

	if ( ! ( $product instanceof WC_Product ) ) {
		return;
	}

	$schema = nexxi_kids_build_product_schema( $product );
	if ( empty( $schema ) || ! is_array( $schema ) ) {
		return;
	}

	$json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) || '' === $json ) {
		return;
	}

	?>
<script type="application/ld+json"><?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
<?php
}
add_action( 'wp_head', 'nexxi_kids_output_product_schema', 20 );

/**
 * Collect stale product URL prefixes (from cached Yoast data or _wp_old_slug) to replace with live permalink.
 *
 * @param int                  $post_id   Product post ID.
 * @param string               $permalink Current canonical permalink.
 * @param array<string, mixed> $data      Current Yoast schema piece.
 * @return array<int, string>
 */
function nexxi_kids_schema_collect_stale_product_urls( $post_id, $permalink, array $data = array() ) {
	$candidates = array();
	$home_base  = rtrim( home_url( '/' ), '/' );
	$norm_live  = rtrim( $permalink, '/' );

	foreach ( array( 'url', '@id' ) as $key ) {
		if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
			$base = explode( '#', trim( $data[ $key ] ) )[0];
			$norm = rtrim( $base, '/' );
			if ( '' !== $norm && $norm !== $norm_live && $norm !== $home_base && 0 === strpos( $norm, $home_base . '/' ) ) {
				$candidates[] = $norm;
			}
		}
	}

	if ( ! empty( $data['breadcrumb']['@id'] ) && is_string( $data['breadcrumb']['@id'] ) ) {
		$bc_base = rtrim( explode( '#', trim( $data['breadcrumb']['@id'] ) )[0], '/' );
		if ( '' !== $bc_base && $bc_base !== $norm_live && $bc_base !== $home_base && 0 === strpos( $bc_base, $home_base . '/' ) ) {
			$candidates[] = $bc_base;
		}
	}

	$old_slugs = get_post_meta( (int) $post_id, '_wp_old_slug', false );
	if ( is_array( $old_slugs ) && ! empty( $old_slugs ) ) {
		$live_slug = (string) get_post_field( 'post_name', (int) $post_id );
		foreach ( $old_slugs as $old_slug ) {
			$old_slug = trim( (string) $old_slug, '/ ' );
			if ( '' === $old_slug ) {
				continue;
			}
			if ( '' !== $live_slug && false !== strpos( $norm_live, '/' . $live_slug ) ) {
				$rebuilt = preg_replace( '~/' . preg_quote( $live_slug, '~' ) . '$~', '/' . $old_slug, $norm_live );
				if ( is_string( $rebuilt ) && $rebuilt !== $norm_live ) {
					$candidates[] = $rebuilt;
				}
			}
			$candidates[] = $home_base . '/san-pham/' . $old_slug;
		}
	}

	return array_values( array_unique( array_filter( $candidates ) ) );
}

/**
 * Recursively replace stale cached product URLs inside a Yoast schema piece.
 *
 * @param mixed              $node        Schema value or nested array.
 * @param array<int, string> $stale_bases Normalized stale URLs without trailing slash.
 * @param string             $permalink   Live product permalink.
 * @return mixed
 */
function nexxi_kids_schema_replace_stale_urls( $node, array $stale_bases, $permalink ) {
	if ( empty( $stale_bases ) ) {
		return $node;
	}

	if ( is_array( $node ) ) {
		foreach ( $node as $k => $v ) {
			$node[ $k ] = nexxi_kids_schema_replace_stale_urls( $v, $stale_bases, $permalink );
		}
		return $node;
	}

	if ( ! is_string( $node ) || '' === $node ) {
		return $node;
	}

	$live_slash   = rtrim( $permalink, '/' ) . '/';
	$live_noslash = rtrim( $permalink, '/' );

	foreach ( $stale_bases as $stale_noslash ) {
		$stale_slash = $stale_noslash . '/';
		if ( 0 === strpos( $node, $stale_slash ) ) {
			return $live_slash . substr( $node, strlen( $stale_slash ) );
		}
		if ( $node === $stale_noslash ) {
			return $permalink;
		}
		if ( 0 === strpos( $node, $stale_noslash . '#' ) ) {
			return $live_slash . substr( $node, strlen( $stale_noslash ) );
		}
	}

	return $node;
}

/**
 * Sanitize Yoast SEO WebPage schema piece at runtime:
 * - Front page: normalize CollectionPage -> WebPage.
 * - Single product: replace cached stale POS slug URLs, strip POS batch prefixes from name,
 *   and link mainEntity -> $permalink . '#product'.
 *
 * @param array<string, mixed> $data    Yoast WebPage schema array.
 * @param mixed                $context Yoast meta surface context (optional).
 * @return array<string, mixed>
 */
function nexxi_kids_filter_wpseo_schema_webpage( $data, $context = null ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	if ( function_exists( 'is_front_page' ) && is_front_page() ) {
		if ( isset( $data['@type'] ) ) {
			if ( 'CollectionPage' === $data['@type'] ) {
				$data['@type'] = 'WebPage';
			} elseif ( is_array( $data['@type'] ) && in_array( 'CollectionPage', $data['@type'], true ) ) {
				$mapped = array_map(
					static function ( $type ) {
						return 'CollectionPage' === $type ? 'WebPage' : $type;
					},
					$data['@type']
				);
				$mapped        = array_values( array_unique( array_filter( $mapped ) ) );
				$data['@type'] = ( 1 === count( $mapped ) ) ? $mapped[0] : ( ! empty( $mapped ) ? $mapped : 'WebPage' );
			}
		}
	}

	if ( function_exists( 'is_singular' ) && is_singular( 'product' ) ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 && is_object( $context ) && ! empty( $context->id ) ) {
			$post_id = (int) $context->id;
		}
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$had_webpage_hash = isset( $data['@id'] ) && is_string( $data['@id'] ) && false !== strpos( $data['@id'], '#webpage' );
				$stale_bases      = nexxi_kids_schema_collect_stale_product_urls( $post_id, $permalink, $data );

				if ( ! empty( $stale_bases ) ) {
					$data = nexxi_kids_schema_replace_stale_urls( $data, $stale_bases, $permalink );
				}

				$data['url']        = $permalink;
				$data['@id']        = $had_webpage_hash ? ( $permalink . '#webpage' ) : $permalink;
				$data['mainEntity'] = array(
					'@id' => $permalink . '#product',
				);

				if ( ! empty( $data['name'] ) && is_string( $data['name'] ) ) {
					$data['name'] = nexxi_kids_schema_clean_pos_prefix( $data['name'] );
				}
			}
		}
	}

	return $data;
}
add_filter( 'wpseo_schema_webpage', 'nexxi_kids_filter_wpseo_schema_webpage', 99, 2 );

/**
 * Keep Yoast BreadcrumbList @id and item names/URLs synchronized with the live product permalink & clean title.
 *
 * @param array<string, mixed> $data    Yoast BreadcrumbList schema array.
 * @param mixed                $context Yoast meta surface context (optional).
 * @return array<string, mixed>
 */
function nexxi_kids_filter_wpseo_schema_breadcrumb( $data, $context = null ) {
	if ( ! is_array( $data ) || ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
		return $data;
	}

	$post_id = (int) get_queried_object_id();
	if ( $post_id <= 0 && is_object( $context ) && ! empty( $context->id ) ) {
		$post_id = (int) $context->id;
	}
	if ( $post_id <= 0 ) {
		return $data;
	}

	$permalink = get_permalink( $post_id );
	if ( ! is_string( $permalink ) || '' === $permalink ) {
		return $data;
	}

	$stale_bases = nexxi_kids_schema_collect_stale_product_urls( $post_id, $permalink, $data );
	if ( ! empty( $stale_bases ) ) {
		$data = nexxi_kids_schema_replace_stale_urls( $data, $stale_bases, $permalink );
	}

	$data['@id'] = $permalink . '#breadcrumb';

	if ( ! empty( $data['itemListElement'] ) && is_array( $data['itemListElement'] ) ) {
		$last_index = count( $data['itemListElement'] ) - 1;
		$live_title = nexxi_kids_schema_clean_pos_prefix( get_the_title( $post_id ) );

		foreach ( $data['itemListElement'] as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['name'] ) && is_string( $item['name'] ) ) {
				if ( $idx === $last_index && '' !== $live_title && preg_match( '/^\s*\d{3,4}\.\d+/u', $item['name'] ) ) {
					$data['itemListElement'][ $idx ]['name'] = $live_title;
				} else {
					$data['itemListElement'][ $idx ]['name'] = nexxi_kids_schema_clean_pos_prefix( $item['name'] );
				}
			}
		}
	}

	return $data;
}
add_filter( 'wpseo_schema_breadcrumb', 'nexxi_kids_filter_wpseo_schema_breadcrumb', 99, 2 );

/**
 * Synchronize Yoast primary ImageObject @id on single product pages when indexables hold a stale slug.
 *
 * @param array<string, mixed> $data    Yoast ImageObject schema array.
 * @param mixed                $context Yoast meta surface context (optional).
 * @return array<string, mixed>
 */
function nexxi_kids_filter_wpseo_schema_main_image( $data, $context = null ) {
	if ( ! is_array( $data ) || ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
		return $data;
	}

	$post_id = (int) get_queried_object_id();
	if ( $post_id <= 0 && is_object( $context ) && ! empty( $context->id ) ) {
		$post_id = (int) $context->id;
	}
	if ( $post_id > 0 ) {
		$permalink = get_permalink( $post_id );
		if ( is_string( $permalink ) && '' !== $permalink && isset( $data['@id'] ) && is_string( $data['@id'] ) && false !== strpos( $data['@id'], '#primaryimage' ) ) {
			$data['@id'] = $permalink . '#primaryimage';
		}
	}

	return $data;
}
add_filter( 'wpseo_schema_main_image', 'nexxi_kids_filter_wpseo_schema_main_image', 99, 2 );

/**
 * Enrich Yoast SEO Organization schema with unified brand name, verified NEXXI contact details,
 * social/gov profiles, 15-day return policy (@id), and NEXXI Club loyalty program.
 *
 * @param array<string, mixed> $data    Yoast Organization schema array.
 * @param mixed                $context Yoast meta surface context (optional).
 * @return array<string, mixed>
 */
function nexxi_kids_filter_wpseo_schema_organization( $data, $context = null ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	$data['name'] = 'NEXXI Kids Fashion';
	if ( empty( $data['url'] ) ) {
		$data['url'] = home_url( '/' );
	}

	$data['telephone'] = '+84-942-958-091';
	$data['email']     = 'nexxivinhomes@gmail.com';

	$data['contactPoint'] = array(
		'@type'             => 'ContactPoint',
		'telephone'         => '+84-942-958-091',
		'email'             => 'nexxivinhomes@gmail.com',
		'contactType'       => 'customer service',
		'areaServed'        => 'VN',
		'availableLanguage' => array( 'Vietnamese', 'vi' ),
	);

	$required_same_as = array(
		'https://www.facebook.com/NexxiKidsFashion',
		'https://www.tiktok.com/@nexxikidsfashion',
		'https://online.gov.vn/nen-tang/7e10ce7d-19e7-47bc-a785-5cf68d3a1247',
	);

	$existing_same_as = array();
	if ( isset( $data['sameAs'] ) ) {
		if ( is_array( $data['sameAs'] ) ) {
			$existing_same_as = $data['sameAs'];
		} elseif ( is_string( $data['sameAs'] ) && '' !== trim( $data['sameAs'] ) ) {
			$existing_same_as = array( $data['sameAs'] );
		}
	}

	$merged_same_as = array();
	$seen_keys      = array();

	foreach ( array_merge( $existing_same_as, $required_same_as ) as $profile_url ) {
		if ( ! is_string( $profile_url ) ) {
			continue;
		}
		$clean_url = trim( $profile_url );
		if ( '' === $clean_url || ! preg_match( '~^https?://~i', $clean_url ) ) {
			continue;
		}
		$norm_key = rtrim( strtolower( $clean_url ), '/' );
		if ( isset( $seen_keys[ $norm_key ] ) ) {
			if ( in_array( $clean_url, $required_same_as, true ) ) {
				$merged_same_as[ $seen_keys[ $norm_key ] ] = $clean_url;
			}
			continue;
		}
		$seen_keys[ $norm_key ] = count( $merged_same_as );
		$merged_same_as[]       = $clean_url;
	}

	$data['sameAs']                  = array_values( $merged_same_as );
	$data['hasMerchantReturnPolicy'] = nexxi_kids_get_merchant_return_policy_schema( true );
	if ( function_exists( 'nexxi_kids_calculate_order_points' ) ) {
		$data['hasMemberProgram'] = nexxi_kids_get_member_program_schema();
	}

	return $data;
}
add_filter( 'wpseo_schema_organization', 'nexxi_kids_filter_wpseo_schema_organization', 99, 2 );
