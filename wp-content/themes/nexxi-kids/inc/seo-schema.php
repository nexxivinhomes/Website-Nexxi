<?php
/**
 * NEXXI Kids — E-commerce & Brand Structured Data (Schema.org JSON-LD).
 *
 * - Restores Google Merchant-compliant @type="Product" + "Offer" JSON-LD on 100% of single product pages.
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
 * Normalize a SKU string by trimming whitespace and trailing hyphens.
 *
 * @param mixed $raw_sku Raw SKU value.
 * @return string
 */
function nexxi_kids_schema_normalize_sku( $raw_sku ) {
	$sku = trim( (string) $raw_sku );
	if ( '' === $sku ) {
		return '';
	}
	$sku = ltrim( $sku, " \t\n\r\0\x0B-" );
	$sku = rtrim( $sku, "- \t\n\r\0\x0B" );
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
 * Collect unique, non-empty full-resolution product image URLs from main image,
 * gallery attachments, variation thumbnails, and _nexxi_class_image_map.
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
		if ( '' !== $abs_url ) {
			$images[] = $abs_url;
		}
	}

	foreach ( $direct_urls as $raw_url ) {
		$abs_url = nexxi_kids_schema_ensure_absolute_url( $raw_url );
		if ( '' !== $abs_url ) {
			$images[] = $abs_url;
		}
	}

	$images = array_values( array_unique( array_filter( $images ) ) );

	if ( empty( $images ) && function_exists( 'wc_placeholder_img_src' ) ) {
		$placeholder = nexxi_kids_schema_ensure_absolute_url( wc_placeholder_img_src( 'full' ) );
		if ( '' !== $placeholder ) {
			$images[] = $placeholder;
		}
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
 * Official NEXXI 15-day exchange/return policy Schema object (VN).
 *
 * @return array<string, mixed>
 */
function nexxi_kids_get_merchant_return_policy_schema() {
	return array(
		'@type'                => 'MerchantReturnPolicy',
		'applicableCountry'    => 'VN',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
		'merchantReturnDays'   => 15,
		'returnMethod'         => 'https://schema.org/ReturnByMail',
		'returnFees'           => 'https://schema.org/ReturnShippingFees',
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
 * Build Google Merchant-compliant Offer object (never AggregateOffer) matching exact storefront display price.
 *
 * @param WC_Product $product   WooCommerce product instance.
 * @param string     $permalink Canonical product permalink.
 * @return array<string, mixed>|null
 */
function nexxi_kids_schema_build_offer( WC_Product $product, $permalink ) {
	if ( function_exists( 'nexxi_kids_normalize_variable_sale' ) ) {
		nexxi_kids_normalize_variable_sale( $product );
	}

	$prog      = function_exists( 'nexxi_kids_get_program_sale' ) ? nexxi_kids_get_program_sale( $product, 'combined' ) : null;
	$raw_price = 0.0;

	if ( is_array( $prog ) && isset( $prog['sale'] ) && (float) $prog['sale'] > 0 ) {
		$raw_price = (float) $prog['sale'];
	}

	// Fallback when program sale helper is unavailable or returned 0 (including variable min!=max without program override).
	if ( $raw_price <= 0 ) {
		$raw_price = (float) $product->get_price();
		if ( $raw_price <= 0 && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$raw_price = (float) $product->get_variation_price( 'min', true );
		}
		if ( $raw_price <= 0 ) {
			$raw_price = (float) $product->get_regular_price();
		}
	}

	$price_int = (int) round( $raw_price );
	if ( $price_int <= 0 ) {
		return null;
	}

	$price_valid_until = gmdate( 'Y-12-31', strtotime( '+1 year' ) );
	if ( is_array( $prog ) && ! empty( $prog['flash_on_sale'] ) && function_exists( 'nexxi_kids_flash_end' ) ) {
		$fs_end = (int) nexxi_kids_flash_end();
		if ( $fs_end > time() ) {
			$price_valid_until = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d', $fs_end ) : gmdate( 'Y-m-d', $fs_end );
		}
	} elseif ( method_exists( $product, 'get_date_on_sale_to' ) ) {
		$sale_to = $product->get_date_on_sale_to();
		if ( is_object( $sale_to ) && method_exists( $sale_to, 'getTimestamp' ) && $sale_to->getTimestamp() > time() ) {
			$price_valid_until = $sale_to->date( 'Y-m-d' );
		}
	}

	$stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
	if ( 'onbackorder' === $stock_status ) {
		$availability = 'https://schema.org/BackOrder';
	} elseif ( $product->is_in_stock() ) {
		$availability = 'https://schema.org/InStock';
	} else {
		$availability = 'https://schema.org/OutOfStock';
	}

	return array(
		'@type'                   => 'Offer',
		'url'                     => $permalink,
		'priceCurrency'           => 'VND',
		'price'                   => (string) $price_int,
		'priceValidUntil'         => $price_valid_until,
		'itemCondition'           => 'https://schema.org/NewCondition',
		'availability'            => $availability,
		'seller'                  => array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => 'NEXXI Kids Fashion',
		),
		'shippingDetails'         => nexxi_kids_get_offer_shipping_details_schema(),
		'hasMerchantReturnPolicy' => nexxi_kids_get_merchant_return_policy_schema(),
	);
}

/**
 * Build complete Schema.org Product JSON-LD array for a WooCommerce product.
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

	$offer        = nexxi_kids_schema_build_offer( $product, $permalink );
	$review_count = (int) $product->get_review_count();
	$avg_rating   = (float) $product->get_average_rating();
	$has_reviews  = ( $review_count > 0 && $avg_rating > 0 );

	// Google Rich Results requires at least one of offers, review, or aggregateRating on Product.
	if ( null === $offer && ! $has_reviews ) {
		return null;
	}

	$images      = nexxi_kids_schema_collect_product_images( $product );
	$description = nexxi_kids_schema_build_description( $product, $clean_name );
	$sku         = nexxi_kids_schema_resolve_sku( $product );

	$schema = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'@id'         => $permalink . '#product',
		'name'        => $clean_name,
		'url'         => $permalink,
		'image'       => $images,
		'description' => $description,
		'sku'         => $sku,
		'brand'       => array(
			'@type' => 'Brand',
			'name'  => 'NEXXI Kids Fashion',
		),
	);

	// Attach non-placeholder product category when available.
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || empty( $term->slug ) || empty( $term->name ) ) {
				continue;
			}
			if ( in_array( $term->slug, array( 'chua-phan-loai', 'uncategorized' ), true ) ) {
				continue;
			}
			$schema['category'] = trim( wp_strip_all_tags( (string) $term->name ) );
			break;
		}
	}

	if ( null !== $offer ) {
		$schema['offers'] = $offer;
	}

	// Strictly attach aggregateRating & approved reviews ONLY when genuine ratings exist (> 0).
	if ( $has_reviews ) {
		$clamped_rating            = min( 5.0, max( 1.0, round( $avg_rating, 2 ) ) );
		$schema['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $clamped_rating,
			'reviewCount' => $review_count,
			'bestRating'  => '5',
			'worstRating' => '1',
		);

		$comments = get_comments(
			array(
				'post_id' => $product_id,
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
				$schema['review'] = $review_items;
			}
		}
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
 * Enrich Yoast SEO Organization schema with verified NEXXI contact details, social/gov profiles, and 15-day return policy.
 *
 * @param array<string, mixed> $data    Yoast Organization schema array.
 * @param mixed                $context Yoast meta surface context (optional).
 * @return array<string, mixed>
 */
function nexxi_kids_filter_wpseo_schema_organization( $data, $context = null ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	if ( empty( $data['name'] ) ) {
		$data['name'] = 'NEXXI Kids Fashion';
	}
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
	$data['hasMerchantReturnPolicy'] = nexxi_kids_get_merchant_return_policy_schema();

	return $data;
}
add_filter( 'wpseo_schema_organization', 'nexxi_kids_filter_wpseo_schema_organization', 99, 2 );
