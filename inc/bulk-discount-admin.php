<?php
/**
 * NEXXI Bulk Product Discount & SKU File Upload Tool (WP Admin).
 *
 * Sets regular product sale prices (Giảm giá bán sản phẩm) across simple and
 * variable products WITHOUT putting them into the Flash Sale campaign.
 * Supports:
 *  1. Direct bulk discount & multi-row live editing on the tool table.
 *  2. File upload (.csv, .xlsx, .txt) or copy-paste by SKU.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Register unified "Khuyến mãi shop" top-level menu + submenus for Regular Product Bulk Discount. */
function nexxi_kids_bulk_discount_admin_menu() {
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	add_menu_page(
		'Khuyến mãi shop — NEXXI',
		'Khuyến mãi shop',
		'manage_woocommerce',
		'nexxi-bulk-discount',
		'nexxi_kids_bulk_discount_admin_page',
		'dashicons-tag',
		56
	);
	add_submenu_page(
		'nexxi-bulk-discount',
		'NEXXI Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU)',
		'🏷️ Giảm Giá SP',
		'manage_woocommerce',
		'nexxi-bulk-discount',
		'nexxi_kids_bulk_discount_admin_page'
	);
	add_submenu_page(
		'edit.php?post_type=product',
		'NEXXI Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU)',
		'🏷️ Giảm Giá SP (SKU)',
		'manage_woocommerce',
		'nexxi-bulk-discount',
		'nexxi_kids_bulk_discount_admin_page'
	);
}
add_action( 'admin_menu', 'nexxi_kids_bulk_discount_admin_menu', 20 );

/**
 * Extract a leading code from a NEXXI product title when _sku is empty
 * (e.g. "2608.5Bộ thun sát nách..." -> "2608.5", "2512.8Sét váy caro..." -> "2512.8").
 */
function nexxi_kids_extract_title_code( $title ) {
	if ( preg_match( '~^\s*([A-Za-z0-9]+(?:\.[A-Za-z0-9]+)+|[0-9]{3,}[A-Za-z0-9._-]*)~u', (string) $title, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

/** Get effective SKU of a product (stored _sku, or leading code from product title). */
function nexxi_kids_effective_sku( WC_Product $product ) {
	$sku = trim( (string) $product->get_sku() );
	if ( '' !== $sku ) {
		return $sku;
	}
	return nexxi_kids_extract_title_code( $product->get_name() );
}

/**
 * Resolve ALL parent product IDs matching a SKU, SKU prefix, or leading product code
 * (e.g. "2TBIHP01", "2607.8", or a group/batch prefix like "2607", "2601").
 *
 * @param string $raw_sku SKU or code prefix.
 * @return int[] Array of unique parent product IDs.
 */
function nexxi_kids_resolve_products_by_sku( $raw_sku ) {
	global $wpdb;
	$sku = trim( (string) $raw_sku );
	if ( '' === $sku ) {
		return array();
	}

	$matched = array();

	// 1. Explicit #ID (e.g. "#249")
	if ( preg_match( '~^#(\d+)$~', $sku, $m ) ) {
		$maybe_id = (int) $m[1];
		if ( 'product' === get_post_type( $maybe_id ) ) {
			return array( $maybe_id );
		}
	}

	// 2. Match WooCommerce _sku (exact or prefix match, case-insensitive) on product or variation
	$like_prefix = $wpdb->esc_like( $sku ) . '%';
	$sku_rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_parent
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'private', 'draft')
			   AND (LOWER(TRIM(pm.meta_value)) = LOWER(%s) OR pm.meta_value LIKE %s)",
			$sku,
			$like_prefix
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $sku_rows ) {
		foreach ( $sku_rows as $r ) {
			$pid = ( 'product_variation' === $r->post_type ) ? (int) $r->post_parent : (int) $r->ID;
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				$matched[ $pid ] = $pid;
			}
		}
	}

	// 3. Match leading code or prefix in product title (e.g., "2607" matches all "2607.8...", "2607.4...", etc.)
	$candidates = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status IN ('publish', 'private', 'draft')
			   AND post_title LIKE %s
			 ORDER BY ID DESC",
			$like_prefix
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $candidates ) {
		foreach ( $candidates as $cand ) {
			$title_trim = trim( (string) $cand->post_title );
			$code       = nexxi_kids_extract_title_code( $title_trim );
			if (
				0 === strcasecmp( $code, $sku ) ||
				0 === stripos( $code, $sku . '.' ) ||
				0 === stripos( $code, $sku . '-' ) ||
				0 === stripos( $title_trim, $sku )
			) {
				$pid             = (int) $cand->ID;
				$matched[ $pid ] = $pid;
			}
		}
	}

	// 4. Fallback: numeric product ID if nothing else matched
	if ( empty( $matched ) && preg_match( '~^\d+$~', $sku ) ) {
		$maybe_id = (int) $sku;
		if ( 'product' === get_post_type( $maybe_id ) ) {
			$matched[ $maybe_id ] = $maybe_id;
		}
	}

	return array_values( $matched );
}

/** Backwards-compatible helper returning the first matched parent product ID. */
function nexxi_kids_resolve_product_by_sku( $raw_sku ) {
	$ids = nexxi_kids_resolve_products_by_sku( $raw_sku );
	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Search parent product IDs by keyword, code prefix (e.g. "2607"), SKU, or #ID.
 *
 * @param string $search_term Search string.
 * @return int[] Matching parent product IDs.
 */
function nexxi_kids_search_product_ids( $search_term ) {
	global $wpdb;
	$term = trim( (string) $search_term );
	if ( '' === $term ) {
		return array();
	}

	$matched = array();

	// 1. Check SKU / code prefix resolver first
	foreach ( nexxi_kids_resolve_products_by_sku( $term ) as $pid ) {
		$matched[ $pid ] = $pid;
	}

	// 2. Substring match in product post_title
	$like_any   = '%' . $wpdb->esc_like( $term ) . '%';
	$title_pids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status IN ('publish', 'private', 'draft')
			   AND post_title LIKE %s",
			$like_any
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $title_pids as $pid ) {
		$pid = (int) $pid;
		if ( $pid > 0 ) {
			$matched[ $pid ] = $pid;
		}
	}

	// 3. Substring match in _sku on product or variation
	$sku_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_parent
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND p.post_status IN ('publish', 'private', 'draft')
			   AND pm.meta_value LIKE %s",
			$like_any
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $sku_rows ) {
		foreach ( $sku_rows as $r ) {
			$pid = ( 'product_variation' === $r->post_type ) ? (int) $r->post_parent : (int) $r->ID;
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				$matched[ $pid ] = $pid;
			}
		}
	}

	return array_values( $matched );
}

/** One-time cleanup for any product whose _sku was accidentally set to a bare 4-digit prefix instead of its full title code. */
add_action( 'init', static function () {
	if ( '2' !== get_option( 'nexxi_bd_sku_fix_rev' ) ) {
		update_option( 'nexxi_bd_sku_fix_rev', '2', true );
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, pm.meta_value AS sku
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
			 WHERE p.post_type = 'product' AND pm.meta_value REGEXP '^[0-9]{4}$'"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$full_code = nexxi_kids_extract_title_code( $r->post_title );
				if ( '' !== $full_code && $full_code !== $r->sku && 0 === strpos( $full_code, $r->sku . '.' ) ) {
					update_post_meta( (int) $r->ID, '_sku', $full_code );
				}
			}
		}
	}
}, 12 );

/**
 * Parse an uploaded .xlsx file into a 2D array of rows using native ZipArchive + SimpleXML.
 */
function nexxi_kids_parse_xlsx_rows( $file_path ) {
	if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
		return array();
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file_path ) ) {
		return array();
	}
	$shared_strings = array();
	$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( false !== $ss_xml ) {
		$sx = @simplexml_load_string( $ss_xml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $sx && isset( $sx->si ) ) {
			foreach ( $sx->si as $si ) {
				if ( isset( $si->t ) ) {
					$shared_strings[] = (string) $si->t;
				} elseif ( isset( $si->r ) ) {
					$text = '';
					foreach ( $si->r as $run ) {
						$text .= (string) $run->t;
					}
					$shared_strings[] = $text;
				} else {
					$shared_strings[] = '';
				}
			}
		}
	}
	$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();
	if ( false === $sheet_xml ) {
		return array();
	}
	$sheet = @simplexml_load_string( $sheet_xml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $sheet || ! isset( $sheet->sheetData->row ) ) {
		return array();
	}

	$col_to_index = static function ( $cell_ref ) {
		if ( ! preg_match( '~^([A-Z]+)~', strtoupper( $cell_ref ), $m ) ) { return 0; }
		$letters = $m[1];
		$idx = 0;
		for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
			$idx = $idx * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $idx - 1 );
	};

	$rows = array();
	foreach ( $sheet->sheetData->row as $row ) {
		$r_cells = array();
		$max_c = -1;
		foreach ( $row->c as $c ) {
			$ref  = (string) $c['r'];
			$type = (string) $c['t'];
			$cidx = $col_to_index( $ref );
			$val  = '';
			if ( 's' === $type ) {
				$s_idx = (int) $c->v;
				$val   = isset( $shared_strings[ $s_idx ] ) ? $shared_strings[ $s_idx ] : '';
			} elseif ( 'inlineStr' === $type && isset( $c->is->t ) ) {
				$val = (string) $c->is->t;
			} elseif ( isset( $c->v ) ) {
				$val = (string) $c->v;
			}
			$r_cells[ $cidx ] = trim( $val );
			if ( $cidx > $max_c ) { $max_c = $cidx; }
		}
		if ( $max_c >= 0 ) {
			$line = array();
			for ( $i = 0; $i <= $max_c; $i++ ) {
				$line[] = isset( $r_cells[ $i ] ) ? $r_cells[ $i ] : '';
			}
			$rows[] = $line;
		}
	}
	return $rows;
}

/**
 * Parse CSV/TXT content or pasted text lines into a 2D array of rows.
 * Auto-detects delimiter (tab, semicolon, comma).
 */
function nexxi_kids_parse_text_rows( $content ) {
	$content = preg_replace( '~^\xEF\xBB\xBF~', '', (string) $content ); // Strip UTF-8 BOM
	$lines   = preg_split( '~\r\n|\r|\n~', trim( $content ) );
	$rows    = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) { continue; }
		if ( false !== strpos( $line, "\t" ) ) {
			$cols = array_map( 'trim', explode( "\t", $line ) );
		} elseif ( false !== strpos( $line, ';' ) ) {
			$cols = array_map( 'trim', str_getcsv( $line, ';' ) );
		} else {
			$cols = array_map( 'trim', str_getcsv( $line, ',' ) );
		}
		if ( ! empty( $cols ) && '' !== implode( '', $cols ) ) {
			$rows[] = $cols;
		}
	}
	return $rows;
}

/** Clean a numeric price/discount token (e.g. "125.000₫" -> 125000, "20%" -> 20). */
function nexxi_kids_clean_number_token( $raw, &$has_percent = false ) {
	$str = trim( (string) $raw );
	$has_percent = ( false !== strpos( $str, '%' ) );
	if ( '' === $str ) { return null; }
	if ( in_array( strtolower( $str ), array( 'clear', 'off', 'xoa', 'none', '0', '0%' ), true ) ) {
		return 0.0;
	}
	// If it looks like VND formatted with dots/commas as thousand separators (e.g. "125.000" or "125,000")
	$clean = preg_replace( '~[^\d.,-]~', '', $str );
	$clean = ltrim( $clean, '-' ); // "-20%" -> "20"
	if ( preg_match( '~^\d{1,3}([.,]\d{3})+$~', $clean ) ) {
		$clean = str_replace( array( '.', ',' ), '', $clean );
	} else {
		$clean = str_replace( ',', '.', $clean );
	}
	return is_numeric( $clean ) ? (float) $clean : null;
}

/**
 * Process a 2D array of SKU rows and apply Regular Product Discounts (_nexxi_is_flash_sale = '0').
 *
 * Supported row structures:
 *  - Header-mapped columns: sku, regular_price / gia_goc, discount_percent / phan_tram_giam, sale_price / gia_ban / gia_giam
 *  - Or 2 columns: [SKU, Discount (% or Sale Price VND)]
 *  - Or 3 columns: [SKU, Regular Price VND, Discount (% or Sale Price VND)]
 */
function nexxi_kids_process_sku_discount_rows( array $rows, $interpret_mode = 'auto' ) {
	$report = array(
		'updated'   => array(),
		'not_found' => array(),
		'skipped'   => 0,
	);
	if ( empty( $rows ) ) {
		return $report;
	}

	// Detect optional header row
	$col_map = array(
		'sku'      => 0,
		'regular'  => -1,
		'percent'  => -1,
		'sale'     => -1,
		'val_col'  => 1,
	);
	$first = array_map( static function ( $c ) {
		return strtolower( trim( (string) $c ) );
	}, $rows[0] );

	$is_header = false;
	foreach ( $first as $idx => $h ) {
		if ( in_array( $h, array( 'sku', 'mã sku', 'ma sku', 'ma_sku', 'mã sp', 'ma sp', 'mã sản phẩm', 'code' ), true ) ) {
			$col_map['sku'] = $idx;
			$is_header = true;
		} elseif ( false !== strpos( $h, 'regular' ) || false !== strpos( $h, 'gia_goc' ) || false !== strpos( $h, 'giá gốc' ) || false !== strpos( $h, 'gia goc' ) ) {
			$col_map['regular'] = $idx;
			$is_header = true;
		} elseif ( false !== strpos( $h, 'percent' ) || false !== strpos( $h, 'phan_tram' ) || false !== strpos( $h, 'phần trăm' ) || false !== strpos( $h, '% giảm' ) || '%' === $h ) {
			$col_map['percent'] = $idx;
			$is_header = true;
		} elseif ( false !== strpos( $h, 'sale' ) || false !== strpos( $h, 'gia_ban' ) || false !== strpos( $h, 'giá bán' ) || false !== strpos( $h, 'gia_giam' ) || false !== strpos( $h, 'giá giảm' ) || false !== strpos( $h, 'khuyen_mai' ) || false !== strpos( $h, 'khuyến mãi' ) || false !== strpos( $h, 'discount' ) || false !== strpos( $h, 'giam_gia' ) || false !== strpos( $h, 'giảm giá' ) ) {
			$col_map['sale'] = $idx;
			$is_header = true;
		}
	}
	if ( $is_header ) {
		array_shift( $rows );
	}

	foreach ( $rows as $row ) {
		$raw_sku = isset( $row[ $col_map['sku'] ] ) ? trim( (string) $row[ $col_map['sku'] ] ) : '';
		if ( '' === $raw_sku ) {
			$report['skipped']++;
			continue;
		}

		$reg_override = 0.0;
		$mode         = '';
		$amount       = null;

		if ( $is_header && ( $col_map['percent'] >= 0 || $col_map['sale'] >= 0 || $col_map['regular'] >= 0 ) ) {
			if ( $col_map['regular'] >= 0 && isset( $row[ $col_map['regular'] ] ) ) {
				$hp = false;
				$rv = nexxi_kids_clean_number_token( $row[ $col_map['regular'] ], $hp );
				if ( null !== $rv && $rv > 0 ) { $reg_override = $rv; }
			}
			// Prefer explicit sale price column if filled, otherwise percent column
			$sale_raw = ( $col_map['sale'] >= 0 && isset( $row[ $col_map['sale'] ] ) ) ? trim( (string) $row[ $col_map['sale'] ] ) : '';
			$pct_raw  = ( $col_map['percent'] >= 0 && isset( $row[ $col_map['percent'] ] ) ) ? trim( (string) $row[ $col_map['percent'] ] ) : '';

			if ( '' !== $sale_raw ) {
				$has_pct = false;
				$num = nexxi_kids_clean_number_token( $sale_raw, $has_pct );
				if ( null !== $num ) {
					$amount = $num;
					if ( 0.0 === $num ) {
						$mode = 'clear';
					} elseif ( 'auto' === $interpret_mode ) {
						$mode = ( $has_pct || $num <= 95 ) ? 'percent' : 'fixed';
					} else {
						$mode = $interpret_mode;
					}
				}
			} elseif ( '' !== $pct_raw ) {
				$has_pct = false;
				$num = nexxi_kids_clean_number_token( $pct_raw, $has_pct );
				if ( null !== $num ) {
					$amount = $num;
					$mode   = ( 0.0 === $num ) ? 'clear' : 'percent';
				}
			}
		} else {
			// Positional columns:
			// 2 columns: [SKU, Value]
			// 3+ columns: [SKU, RegularPrice, Value]
			if ( count( $row ) >= 3 && '' !== trim( (string) $row[2] ) ) {
				$hp1 = false;
				$rv  = nexxi_kids_clean_number_token( $row[1], $hp1 );
				if ( null !== $rv && $rv >= 1000 ) {
					$reg_override = $rv;
				}
				$val_raw = $row[2];
			} else {
				$val_raw = isset( $row[1] ) ? $row[1] : '';
			}
			$has_pct = false;
			$num     = nexxi_kids_clean_number_token( $val_raw, $has_pct );
			if ( null !== $num ) {
				$amount = $num;
				if ( 0.0 === $num ) {
					$mode = 'clear';
				} elseif ( 'auto' === $interpret_mode ) {
					$mode = ( $has_pct || $num <= 95 ) ? 'percent' : 'fixed';
				} else {
					$mode = $interpret_mode;
				}
			}
		}

		if ( '' === $mode && $reg_override <= 0 ) {
			$report['skipped']++;
			continue;
		}

		$pids = nexxi_kids_resolve_products_by_sku( $raw_sku );
		if ( empty( $pids ) ) {
			$report['not_found'][] = $raw_sku;
			continue;
		}

		if ( '' === $mode && $reg_override > 0 ) {
			$mode   = 'clear';
			$amount = 0;
		}

		// Apply discount as Regular Product Sale (_nexxi_has_prod_sale = '1', NOT Flash Sale) to ALL matched products
		foreach ( $pids as $pid ) {
			if ( nexxi_kids_apply_product_discount( $pid, $mode, (float) $amount, (float) $reg_override, '0' ) ) {
				$prod    = wc_get_product( $pid );
				$prog    = $prod ? nexxi_kids_get_program_sale( $prod, 'regular_admin' ) : array( 'regular' => 0, 'sale' => 0, 'pct' => 0 );
				$eff_sku = $prod ? nexxi_kids_effective_sku( $prod ) : $raw_sku;
				$report['updated'][] = array(
					'id'      => $pid,
					'sku'     => $eff_sku && $eff_sku !== $raw_sku ? $raw_sku . ' → ' . $eff_sku : $raw_sku,
					'name'    => $prod ? $prod->get_name() : '#' . $pid,
					'regular' => $prog['regular'],
					'sale'    => $prog['sale'],
					'pct'     => $prog['pct'],
				);
			}
		}
	}

	if ( ! empty( $report['updated'] ) ) {
		nexxi_kids_flush_sale_cache();
	}
	return $report;
}

/** Handle CSV sample download, CSV product export, and POST actions on the Bulk Discount page. */
function nexxi_kids_handle_bulk_discount_admin_actions() {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// 1. Download Sample CSV
	if ( isset( $_GET['page'], $_GET['nexxi_bd_export'] ) && 'nexxi-bulk-discount' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_type = sanitize_key( wp_unslash( $_GET['nexxi_bd_export'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'sample' === $export_type ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="nexxi-mau-giam-gia-sku.csv"' );
			echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'sku', 'ten_san_pham_tham_khao', 'gia_goc', 'phan_tram_giam', 'gia_ban_giam' ) );
			fputcsv( $out, array( '2608.5', 'Bộ thun sát nách phối màu Boston (Ví dụ giảm theo %)', '165000', '20', '' ) );
			fputcsv( $out, array( '2604.8', 'Bộ thun ngắn tay viền sọc búp bê (Ví dụ đặt giá bán cố định)', '175000', '', '139000' ) );
			fputcsv( $out, array( '2512.8', 'Sét váy caro 3 tầng (Ví dụ tắt giảm giá về giá gốc)', '195000', '0', '' ) );
			fclose( $out );
			exit;
		} elseif ( 'catalog' === $export_type ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="nexxi-danh-sach-san-pham-sku-' . wp_date( 'Y-m-d' ) . '.csv"' );
			echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'sku', 'ten_san_pham', 'gia_goc', 'phan_tram_giam', 'gia_ban_giam' ) );
			$q = new WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			) );
			foreach ( $q->posts as $pid ) {
				$prod = wc_get_product( $pid );
				if ( ! $prod ) { continue; }
				$sku = nexxi_kids_effective_sku( $prod );
				if ( '' === $sku ) {
					$sku = '#' . $pid;
				}
				$prog    = nexxi_kids_get_program_sale( $prod, 'regular_admin' );
				$on_sale = $prog['on_sale'];
				$pct     = $on_sale ? $prog['pct'] : '';
				$sale_v  = $on_sale ? round( $prog['sale'] ) : '';
				fputcsv( $out, array(
					$sku,
					$prod->get_name(),
					$prog['regular'] > 0 ? round( $prog['regular'] ) : '',
					$pct,
					$sale_v,
				) );
			}
			fclose( $out );
			exit;
		}
	}

	// 2. Handle POST actions
	if ( empty( $_POST['nexxi_bd_action'] ) ) {
		return;
	}
	check_admin_referer( 'nexxi_bulk_discount_admin', 'nexxi_bd_nonce' );
	$action = sanitize_key( wp_unslash( $_POST['nexxi_bd_action'] ) );

	if ( 'upload_sku_file' === $action ) {
		$interpret_mode = isset( $_POST['sku_interpret_mode'] ) ? sanitize_key( wp_unslash( $_POST['sku_interpret_mode'] ) ) : 'auto';
		$rows = array();

		// Check uploaded file first
		if ( ! empty( $_FILES['sku_file']['tmp_name'] ) && is_uploaded_file( $_FILES['sku_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$filename = isset( $_FILES['sku_file']['name'] ) ? strtolower( sanitize_file_name( wp_unslash( $_FILES['sku_file']['name'] ) ) ) : '';
			$tmp_path = $_FILES['sku_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( preg_match( '~\.xlsx$~i', $filename ) ) {
				$rows = nexxi_kids_parse_xlsx_rows( $tmp_path );
			} else {
				$raw_content = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$rows        = nexxi_kids_parse_text_rows( $raw_content );
			}
		}

		// Also merge pasted SKU lines if provided
		if ( ! empty( $_POST['sku_paste_text'] ) ) {
			$pasted = nexxi_kids_parse_text_rows( wp_unslash( $_POST['sku_paste_text'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$rows   = array_merge( $rows, $pasted );
		}

		if ( empty( $rows ) ) {
			add_settings_error( 'nexxi_bd', 'empty_upload', 'Vui lòng chọn file (.csv, .xlsx) hoặc dán danh sách mã SKU vào ô bên dưới.', 'error' );
			return;
		}

		$report = nexxi_kids_process_sku_discount_rows( $rows, $interpret_mode );
		set_transient( 'nexxi_bd_last_report_' . get_current_user_id(), $report, 300 );

		$updated_count = count( $report['updated'] );
		$missing_count = count( $report['not_found'] );
		if ( $updated_count > 0 ) {
			$msg = 'Đã cập nhật giảm giá bán thành công cho ' . $updated_count . ' sản phẩm theo SKU (không đưa vào Flash Sale)!';
			if ( $missing_count > 0 ) {
				$msg .= ' Có ' . $missing_count . ' mã SKU không tìm thấy (xem chi tiết bên dưới).';
			}
			add_settings_error( 'nexxi_bd', 'sku_success', $msg, 'updated' );
		} else {
			add_settings_error( 'nexxi_bd', 'sku_none', 'Không tìm thấy sản phẩm nào khớp với mã SKU trong dữ liệu tải lên. Vui lòng kiểm tra báo cáo chi tiết bên dưới.', 'error' );
		}
	} elseif ( 'bulk_apply_direct' === $action ) {
		$target_scope = isset( $_POST['bulk_scope'] ) ? sanitize_key( wp_unslash( $_POST['bulk_scope'] ) ) : 'selected';
		$bulk_mode    = isset( $_POST['bulk_mode'] ) ? sanitize_key( wp_unslash( $_POST['bulk_mode'] ) ) : 'percent';
		$bulk_val     = isset( $_POST['bulk_value'] ) ? (float) preg_replace( '~[^\d.]~', '', wp_unslash( $_POST['bulk_value'] ) ) : 0;
		$pids         = array();

		if ( 'selected' === $target_scope ) {
			$pids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) : array();
		} elseif ( 'filtered' === $target_scope ) {
			$f_search = isset( $_POST['current_s_prod'] ) ? sanitize_text_field( wp_unslash( $_POST['current_s_prod'] ) ) : '';
			if ( '' !== $f_search ) {
				$pids = nexxi_kids_search_product_ids( $f_search );
			}
		} elseif ( 'category' === $target_scope ) {
			$cat_slug = isset( $_POST['bulk_target_cat'] ) ? sanitize_title( wp_unslash( $_POST['bulk_target_cat'] ) ) : '';
			if ( '' !== $cat_slug ) {
				$q = new WP_Query( array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'slug',
							'terms'    => $cat_slug,
						),
					),
				) );
				$pids = array_map( 'absint', (array) $q->posts );
			}
		} elseif ( 'all_shop' === $target_scope ) {
			$q = new WP_Query( array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			$pids = array_map( 'absint', (array) $q->posts );
		}

		$count = 0;
		foreach ( $pids as $pid ) {
			if ( $pid && nexxi_kids_apply_product_discount( $pid, $bulk_mode, $bulk_val, 0, '0' ) ) {
				$count++;
			}
		}
		if ( $count > 0 ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_bd', 'bulk_direct_ok', 'Đã áp dụng giảm giá bán sản phẩm cho ' . $count . ' mẫu (hiển thị giá giảm trên cửa hàng, không đưa vào Flash Sale)!', 'updated' );
		} else {
			add_settings_error( 'nexxi_bd', 'bulk_direct_empty', 'Vui lòng tích chọn ít nhất 1 sản phẩm hoặc chọn danh mục cần áp dụng.', 'error' );
		}
	} elseif ( 'save_table_rows' === $action ) {
		$row_pids = isset( $_POST['row_pids'] ) && is_array( $_POST['row_pids'] ) ? array_map( 'absint', wp_unslash( $_POST['row_pids'] ) ) : array();
		$row_skus = isset( $_POST['row_sku'] ) && is_array( $_POST['row_sku'] ) ? wp_unslash( $_POST['row_sku'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$row_regs = isset( $_POST['row_reg'] ) && is_array( $_POST['row_reg'] ) ? wp_unslash( $_POST['row_reg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$row_pcts = isset( $_POST['row_pct'] ) && is_array( $_POST['row_pct'] ) ? wp_unslash( $_POST['row_pct'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$row_sale = isset( $_POST['row_sale'] ) && is_array( $_POST['row_sale'] ) ? wp_unslash( $_POST['row_sale'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$only_pid = isset( $_POST['single_save_pid'] ) ? absint( $_POST['single_save_pid'] ) : 0;

		$saved_count = 0;
		foreach ( $row_pids as $pid ) {
			if ( ! $pid ) { continue; }
			if ( $only_pid > 0 && $pid !== $only_pid ) { continue; }

			// Update SKU if edited
			if ( isset( $row_skus[ $pid ] ) ) {
				$new_sku = sanitize_text_field( trim( (string) $row_skus[ $pid ] ) );
				$cur_sku = (string) get_post_meta( $pid, '_sku', true );
				if ( '' !== $new_sku && $new_sku !== $cur_sku ) {
					update_post_meta( $pid, '_sku', $new_sku );
				}
			}

			$hp_r = false;
			$hp_p = false;
			$hp_s = false;
			$reg_val  = isset( $row_regs[ $pid ] ) ? nexxi_kids_clean_number_token( $row_regs[ $pid ], $hp_r ) : null;
			$pct_val  = isset( $row_pcts[ $pid ] ) ? nexxi_kids_clean_number_token( $row_pcts[ $pid ], $hp_p ) : null;
			$sale_val = isset( $row_sale[ $pid ] ) ? nexxi_kids_clean_number_token( $row_sale[ $pid ], $hp_s ) : null;

			$reg_override = ( null !== $reg_val && $reg_val > 0 ) ? $reg_val : 0;

			if ( null !== $sale_val && $sale_val > 0 ) {
				nexxi_kids_apply_product_discount( $pid, 'fixed', $sale_val, $reg_override, '0' );
				$saved_count++;
			} elseif ( null !== $pct_val && $pct_val > 0 ) {
				nexxi_kids_apply_product_discount( $pid, 'percent', $pct_val, $reg_override, '0' );
				$saved_count++;
			} else {
				// Neither sale price nor percent is > 0: clear regular product discount if currently active or if saving single row or updating regular price
				$prod = wc_get_product( $pid );
				$prog = $prod ? nexxi_kids_get_program_sale( $prod, 'regular_admin' ) : array( 'on_sale' => false );
				if ( $prog['on_sale'] || $only_pid === $pid || $reg_override > 0 ) {
					nexxi_kids_apply_product_discount( $pid, 'clear', 0, $reg_override, '0' );
					$saved_count++;
				}
			}
		}

		if ( $saved_count > 0 ) {
			nexxi_kids_flush_sale_cache();
			add_settings_error( 'nexxi_bd', 'rows_saved', 'Đã lưu cập nhật giá bán cho ' . $saved_count . ' sản phẩm!', 'updated' );
		} else {
			add_settings_error( 'nexxi_bd', 'rows_nochange', 'Không có thay đổi giá nào cần lưu.', 'updated' );
		}
	}
}
add_action( 'admin_init', 'nexxi_kids_handle_bulk_discount_admin_actions' );

/** Render the Bulk Product Discount & SKU Upload Admin screen. */
function nexxi_kids_bulk_discount_admin_page() {
	$regular_sale_ids = nexxi_kids_regular_sale_parent_ids();

	$last_report = get_transient( 'nexxi_bd_last_report_' . get_current_user_id() );
	if ( false !== $last_report ) {
		delete_transient( 'nexxi_bd_last_report_' . get_current_user_id() );
	}

	// Filters for direct table
	$search   = isset( $_GET['s_prod'] ) ? sanitize_text_field( wp_unslash( $_GET['s_prod'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cat      = isset( $_GET['cat_slug'] ) ? sanitize_title( wp_unslash( $_GET['cat_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status   = isset( $_GET['sale_filter'] ) ? sanitize_key( wp_unslash( $_GET['sale_filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 25; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $per_page, array( 25, 50, 100, 500 ), true ) ) { $per_page = 25; }
	$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$query_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	$filter_post_in     = null;
	$filter_post_not_in = array();

	if ( '' !== $search ) {
		$matched_ids    = nexxi_kids_search_product_ids( $search );
		$filter_post_in = ! empty( $matched_ids ) ? $matched_ids : array( 0 );
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
	if ( 'regular_sale' === $status ) {
		$sale_set       = $regular_sale_ids ? $regular_sale_ids : array( 0 );
		$filter_post_in = ( null !== $filter_post_in ) ? array_values( array_intersect( $filter_post_in, $sale_set ) ) : $sale_set;
		if ( empty( $filter_post_in ) ) {
			$filter_post_in = array( 0 );
		}
	} elseif ( 'no_sale' === $status && $regular_sale_ids ) {
		if ( null !== $filter_post_in ) {
			$filter_post_in = array_values( array_diff( $filter_post_in, $regular_sale_ids ) );
			if ( empty( $filter_post_in ) ) {
				$filter_post_in = array( 0 );
			}
		} else {
			$filter_post_not_in = $regular_sale_ids;
		}
	}

	if ( null !== $filter_post_in ) {
		$query_args['post__in'] = $filter_post_in;
	} elseif ( ! empty( $filter_post_not_in ) ) {
		$query_args['post__not_in'] = $filter_post_not_in;
	}

	$wp_q       = new WP_Query( $query_args );
	// Nạp sẵn meta biến thể & ảnh của cả trang trong vài truy vấn (tránh mỗi dòng SP phát sinh nhiều truy vấn).
	if ( function_exists( 'nexxi_kids_prime_products_cache' ) && ! empty( $wp_q->posts ) ) {
		nexxi_kids_prime_products_cache( array_filter( array_map( 'wc_get_product', wp_list_pluck( $wp_q->posts, 'ID' ) ) ) );
	}
	$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$sample_url = add_query_arg( array( 'page' => 'nexxi-bulk-discount', 'nexxi_bd_export' => 'sample' ), admin_url( 'admin.php' ) );
	$export_url = add_query_arg( array( 'page' => 'nexxi-bulk-discount', 'nexxi_bd_export' => 'catalog' ), admin_url( 'admin.php' ) );
	?>
	<div class="wrap" style="max-width:1340px;margin-top:18px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
		<!-- Unified "Khuyến mãi shop" Tab Bar -->
		<div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #e4e2de;padding-bottom:10px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#031444;color:#fff;font-weight:700;font-size:13.5px;text-decoration:none;box-shadow:0 2px 8px rgba(3,20,68,0.18);">
				🏷️ Giảm Giá Bán Sản Phẩm (Hàng Loạt / SKU)
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				⚡ Chiến Dịch Flash Sale &amp; Đếm Ngược
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-combo-promo' ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;background:#fff;color:#031444;border:1px solid #d5d3ce;font-weight:700;font-size:13.5px;text-decoration:none;">
				🎁 Combo Khuyến Mãi (Theo Số Lượng Bill)
			</a>
		</div>
		<!-- Header -->
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
			<div>
				<h1 style="margin:0;font-size:24px;font-weight:800;color:#031444;display:flex;align-items:center;gap:8px;">
					<span>🏷️ Công Cụ Giảm Giá Bán Sản Phẩm Hàng Loạt &amp; Theo SKU</span>
				</h1>
				<p style="margin:4px 0 0;color:#45464f;font-size:13.5px;">
					Set giá khuyến mãi trực tiếp trên bảng hoặc tải file Excel / CSV theo <b>Mã SKU</b>. Các sản phẩm giảm giá tại đây <b>chỉ hiển thị giảm giá bán trên cửa hàng (Trang chủ, Trang Sản phẩm, Chi tiết SP)</b> — <b>KHÔNG</b> tự động đưa vào kệ Flash Sale.
				</p>
			</div>
			<div style="display:flex;gap:8px;flex-wrap:wrap;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-flash-sale' ) ); ?>">⚡ Quản Lý Chiến Dịch Flash Sale</a>
				<a class="button button-primary" href="<?php echo esc_url( nexxi_kids_shop_url() ); ?>" target="_blank" style="background:#031444;border-color:#031444;">Xem Trang Sản Phẩm ↗</a>
			</div>
		</div>

		<?php settings_errors( 'nexxi_bd' ); ?>

		<?php if ( is_array( $last_report ) ) : ?>
			<!-- Upload / Paste SKU Execution Report -->
			<div style="background:#fff;border:2px solid #0b6b2f;border-radius:14px;padding:18px 22px;margin-bottom:20px;box-shadow:0 4px 14px rgba(11,107,47,0.08);">
				<h3 style="margin:0 0 10px;color:#0b6b2f;font-size:16px;">
					✅ Kết Quả Cập Nhật Giảm Giá Theo SKU: Thành công <?php echo count( $last_report['updated'] ); ?> sản phẩm
					<?php if ( ! empty( $last_report['not_found'] ) ) : ?>
						· <span style="color:#ba1a1a;">Không tìm thấy <?php echo count( $last_report['not_found'] ); ?> mã SKU</span>
					<?php endif; ?>
				</h3>
				<?php if ( ! empty( $last_report['updated'] ) ) : ?>
					<div style="max-height:220px;overflow-y:auto;border:1px solid #e4e2de;border-radius:8px;margin-bottom:10px;">
						<table class="widefat striped" style="border:none;">
							<thead>
								<tr>
									<th>Mã SKU</th>
									<th>Tên sản phẩm</th>
									<th>Giá gốc</th>
									<th>Giá bán sau giảm</th>
									<th>Mức giảm</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $last_report['updated'] as $u ) : ?>
									<tr>
										<td><code><?php echo esc_html( $u['sku'] ); ?></code></td>
										<td><strong><?php echo esc_html( $u['name'] ); ?></strong></td>
										<td><?php echo esc_html( nexxi_kids_money( $u['regular'] ) ); ?></td>
										<td><strong style="color:#ae3115;"><?php echo esc_html( nexxi_kids_money( $u['sale'] ) ); ?></strong></td>
										<td><?php echo $u['pct'] > 0 ? '<span style="background:#fd6a49;color:#fff;padding:2px 8px;border-radius:999px;font-weight:700;font-size:11px;">-' . (int) $u['pct'] . '%</span>' : '<span style="color:#666;">Về giá gốc</span>'; ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $last_report['not_found'] ) ) : ?>
					<div style="background:#fff4f1;border:1px solid #ffdad2;padding:10px 14px;border-radius:8px;color:#ae3115;font-size:13px;">
						<strong>⚠️ Các mã SKU không tìm thấy trong cửa hàng:</strong>
						<code><?php echo esc_html( implode( ', ', $last_report['not_found'] ) ); ?></code>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- CARD 1: UPLOAD FILE (.CSV / .XLSX) OR PASTE BY SKU -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;margin-bottom:22px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f0eee9;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">1. Upload File Giảm Giá Hàng Loạt Theo SKU (.CSV / Excel .XLSX) Hoặc Dán Nhanh</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						Hỗ trợ khớp <b>Mã SKU chuẩn</b> hoặc <b>Mã số ở đầu tên sản phẩm</b> (VD: <code>2608.5</code>, <code>2604.8</code>). Tự động áp dụng đồng bộ cho toàn bộ biến thể Size / Màu của sản phẩm.
					</p>
				</div>
				<div style="display:flex;gap:8px;flex-wrap:wrap;">
					<a class="button" href="<?php echo esc_url( $sample_url ); ?>">📥 Tải File CSV Mẫu</a>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>" style="background:#f5f3ef;border-color:#c6c6d0;font-weight:600;color:#031444;">📤 Xuất Toàn Bộ SP Hiện Tại Ra CSV (Để Điền Giá &amp; Upload Lại)</a>
				</div>
			</div>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'nexxi_bulk_discount_admin', 'nexxi_bd_nonce' ); ?>
				<input type="hidden" name="nexxi_bd_action" value="upload_sku_file">

				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;align-items:start;">
					<!-- Left Box: File Upload -->
					<div style="background:#f9f8f5;border:1px dashed #b8b6b0;border-radius:12px;padding:16px 18px;">
						<label style="display:block;font-weight:700;color:#031444;font-size:14px;margin-bottom:8px;">
							📂 Cách A: Chọn File Danh Sách SKU (.csv, .xlsx, .txt)
						</label>
						<input type="file" name="sku_file" accept=".csv,.xlsx,.txt" style="width:100%;padding:8px;background:#fff;border:1px solid #d5d3ce;border-radius:8px;margin-bottom:12px;">
						<div style="font-size:12.5px;color:#555;line-height:1.55;">
							<strong>Cấu trúc cột hỗ trợ linh hoạt:</strong>
							<ul style="margin:4px 0 0 18px;padding:0;">
								<li><b>File 2 cột:</b> <code>Cột A: Mã SKU</code> | <code>Cột B: % Giảm hoặc Giá bán giảm (₫)</code></li>
								<li><b>File 3 cột:</b> <code>Cột A: Mã SKU</code> | <code>Cột B: Giá gốc (₫)</code> | <code>Cột C: % Giảm hoặc Giá giảm (₫)</code></li>
								<li><b>Hoặc File xuất từ nút "Xuất Toàn Bộ SP Hiện Tại Ra CSV":</b> Mở bằng Excel, điền cột <code>phan_tram_giam</code> (VD: <code>20</code>) hoặc <code>gia_ban_giam</code> (VD: <code>139000</code>), lưu lại và upload lên đây!</li>
							</ul>
						</div>
					</div>

					<!-- Right Box: Quick Paste from Excel -->
					<div style="background:#f9f8f5;border:1px solid #e4e2de;border-radius:12px;padding:16px 18px;">
						<label style="display:block;font-weight:700;color:#031444;font-size:14px;margin-bottom:6px;">
							📋 Cách B: Dán Nhanh Từ Excel / Google Sheets (Không cần lưu file)
						</label>
						<textarea name="sku_paste_text" rows="5" placeholder="Copy 2 cột [SKU] và [Mức giảm] từ Excel dán vào đây, mỗi sản phẩm 1 dòng. Ví dụ:&#10;2608.5, 20%&#10;2604.8, 135000&#10;2512.8   0" style="width:100%;border-radius:8px;border:1px solid #d5d3ce;padding:8px 10px;font-family:Consolas,monospace;font-size:12.5px;"></textarea>
					</div>
				</div>

				<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #f0eee9;">
					<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
						<label style="font-weight:700;color:#031444;font-size:13px;">Cách hiểu cột giá trị giảm:</label>
						<select name="sku_interpret_mode" style="min-height:36px;border-radius:8px;border:1px solid #c6c6d0;font-weight:600;color:#031444;">
							<option value="auto">✨ Tự động thông minh (Số ≤ 95 hoặc có % = Giảm theo %; Số ≥ 1000 = Giá bán sau giảm ₫; 0 = Tắt giảm)</option>
							<option value="percent">Luôn tính là Phần trăm giảm (%)</option>
							<option value="fixed">Luôn tính là Giá bán sau khi giảm (₫)</option>
							<option value="amount_off">Luôn tính là Số tiền giảm trừ trực tiếp so với giá gốc (₫)</option>
						</select>
					</div>

					<button type="submit" class="button button-primary" style="min-height:40px;padding:0 24px;border-radius:10px;font-weight:700;font-size:14px;background:#fd6a49;border-color:#fd6a49;box-shadow:0 4px 12px rgba(253,106,73,0.28);">
						🚀 Chạy Cập Nhật Giảm Giá Theo SKU
					</button>
				</div>
			</form>
		</div>

		<!-- CARD 2: DIRECT BULK DISCOUNT & MULTI-ROW LIVE EDITOR -->
		<div style="background:#fff;border:1px solid #e4e2de;border-radius:16px;padding:22px 24px;box-shadow:0 4px 16px rgba(3,20,68,0.04);">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
				<div>
					<h2 style="margin:0;font-size:17px;font-weight:700;color:#031444;">
						2. Set Giảm Giá Hàng Loạt Trực Tiếp Trên Công Cụ
						<span style="background:#e6f4ea;color:#0b6b2f;font-size:12px;padding:3px 10px;border-radius:999px;margin-left:6px;">
							Đang giảm giá bán: <?php echo count( $regular_sale_ids ); ?> mẫu
						</span>
					</h2>
					<p style="margin:4px 0 0;color:#666;font-size:13px;">
						Bạn có thể <b>tích chọn nhiều sản phẩm / chọn cả danh mục</b> để giảm giá hàng loạt, hoặc <b>nhập trực tiếp % giảm / giá bán giảm trên nhiều dòng cùng lúc</b> rồi bấm <b>Lưu Tất Cả Thay Đổi Trên Bảng</b>.
					</p>
				</div>
			</div>

			<!-- Filter & Search Bar -->
			<form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f5f3ef;padding:12px 14px;border-radius:12px;margin-bottom:14px;">
				<input type="hidden" name="page" value="nexxi-bulk-discount">
				<input type="search" name="s_prod" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm theo Mã SKU hoặc tên sản phẩm..." style="min-width:240px;min-height:34px;border-radius:8px;border:1px solid #d5d3ce;padding:0 10px;">
				<select name="cat_slug" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="">— Tất cả danh mục —</option>
					<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $c_term ) : ?>
						<option value="<?php echo esc_attr( $c_term->slug ); ?>" <?php selected( $cat, $c_term->slug ); ?>><?php echo esc_html( $c_term->name ); ?> (<?php echo (int) $c_term->count; ?>)</option>
					<?php endforeach; endif; ?>
				</select>
				<select name="sale_filter" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="all" <?php selected( $status, 'all' ); ?>>Tất cả trạng thái</option>
					<option value="regular_sale" <?php selected( $status, 'regular_sale' ); ?>>🏷️ Đang giảm giá bán SP (<?php echo count( $regular_sale_ids ); ?>)</option>
					<option value="no_sale" <?php selected( $status, 'no_sale' ); ?>>Chưa giảm giá SP</option>
				</select>
				<select name="per_page" style="min-height:34px;border-radius:8px;border:1px solid #d5d3ce;">
					<option value="25" <?php selected( $per_page, 25 ); ?>>25 mẫu / trang</option>
					<option value="50" <?php selected( $per_page, 50 ); ?>>50 mẫu / trang</option>
					<option value="100" <?php selected( $per_page, 100 ); ?>>100 mẫu / trang</option>
					<option value="500" <?php selected( $per_page, 500 ); ?>>Hiển thị tất cả (tối đa 500)</option>
				</select>
				<button type="submit" class="button" style="min-height:34px;font-weight:600;">🔍 Lọc sản phẩm</button>
				<?php if ( '' !== $search || '' !== $cat || 'all' !== $status ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=nexxi-bulk-discount' ) ); ?>" style="min-height:34px;">Xóa lọc</a>
				<?php endif; ?>
			</form>

			<!-- Master Form wrapping both Bulk Bar & Multi-row Table -->
			<form id="nexxi-bd-master-form" method="post">
				<?php wp_nonce_field( 'nexxi_bulk_discount_admin', 'nexxi_bd_nonce' ); ?>
				<input type="hidden" name="nexxi_bd_action" id="nexxi-bd-action-field" value="save_table_rows">
				<input type="hidden" name="single_save_pid" id="nexxi-bd-single-pid" value="0">
				<input type="hidden" name="current_s_prod" value="<?php echo esc_attr( $search ); ?>">

				<!-- Bulk Action Bar -->
				<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;background:#eef4ff;border:1px solid #c8dafc;border-radius:12px;margin-bottom:14px;">
					<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
						<strong style="color:#031444;font-size:13px;">🏷️ Áp dụng hàng loạt:</strong>
						<select name="bulk_scope" id="nexxi-bd-scope" onchange="document.getElementById('nexxi-bd-cat-select').style.display=(this.value==='category'?'inline-block':'none');" style="min-height:34px;border-radius:8px;border:1px solid #b5c7eb;font-weight:600;">
							<?php if ( '' !== $search ) : ?>
								<option value="filtered" selected>Cho tất cả <?php echo (int) $wp_q->found_posts; ?> sản phẩm khớp tìm kiếm "<?php echo esc_html( $search ); ?>"</option>
							<?php endif; ?>
							<option value="selected">Cho các sản phẩm đã tích chọn bên dưới</option>
							<option value="category">Cho toàn bộ 1 Danh mục sản phẩm</option>
							<option value="all_shop">Cho TẤT CẢ sản phẩm toàn cửa hàng</option>
						</select>
						<select name="bulk_target_cat" id="nexxi-bd-cat-select" style="display:none;min-height:34px;border-radius:8px;border:1px solid #b5c7eb;">
							<option value="">— Chọn danh mục —</option>
							<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $c_term ) : ?>
								<option value="<?php echo esc_attr( $c_term->slug ); ?>"><?php echo esc_html( $c_term->name ); ?> (<?php echo (int) $c_term->count; ?>)</option>
							<?php endforeach; endif; ?>
						</select>
						<select name="bulk_mode" id="nexxi-bd-bulk-mode" style="min-height:34px;border-radius:8px;border:1px solid #b5c7eb;">
							<option value="percent">Giảm theo % (so với giá gốc)</option>
							<option value="amount_off">Giảm trừ số tiền (₫) so với giá gốc</option>
							<option value="fixed">Đặt đồng giá bán sau giảm (₫)</option>
							<option value="clear">Tắt giảm giá (Khôi phục giá gốc)</option>
						</select>
						<input type="number" name="bulk_value" id="nexxi-bd-bulk-val" value="20" min="0" step="1" placeholder="Nhập % hoặc số tiền ₫" style="width:115px;min-height:34px;border-radius:8px;border:1px solid #b5c7eb;padding:0 10px;font-weight:700;">
						<div style="display:flex;gap:4px;">
							<button type="button" class="button button-small" onclick="nexxiSetBulkPreset('percent',10)">-10%</button>
							<button type="button" class="button button-small" onclick="nexxiSetBulkPreset('percent',15)">-15%</button>
							<button type="button" class="button button-small" onclick="nexxiSetBulkPreset('percent',20)">-20%</button>
							<button type="button" class="button button-small" onclick="nexxiSetBulkPreset('percent',30)">-30%</button>
							<button type="button" class="button button-small" onclick="nexxiSetBulkPreset('percent',50)">-50%</button>
						</div>
						<button type="button" class="button button-primary" style="min-height:34px;background:#031444;border-color:#031444;font-weight:700;" onclick="nexxiSubmitBulkBar()">
							Áp Dụng Hàng Loạt
						</button>
					</div>

					<button type="button" class="button button-primary" style="min-height:36px;padding:0 18px;background:#0b6b2f;border-color:#0b6b2f;font-weight:700;" onclick="nexxiSaveAllRows(0)">
						💾 Lưu Tất Cả Thay Đổi Trên Bảng
					</button>
				</div>

				<!-- Direct Multi-Row Edit Table -->
				<table class="wp-list-table widefat fixed striped" style="border-radius:10px;overflow:hidden;border:1px solid #e4e2de;">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column" style="width:34px;">
								<input type="checkbox" onclick="document.querySelectorAll('.nexxi-bd-cb').forEach(c=>c.checked=this.checked)">
							</td>
							<th style="width:54px;">Ảnh</th>
							<th style="width:125px;">Mã SKU</th>
							<th>Tên sản phẩm</th>
							<th style="width:130px;">Giá gốc (₫)</th>
							<th style="width:95px;">% Giảm</th>
							<th style="width:140px;">Giá bán giảm (₫)</th>
							<th style="width:140px;">Trạng thái hiện tại</th>
							<th style="width:200px;">Thao tác nhanh</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $wp_q->have_posts() ) : ?>
							<tr><td colspan="9" style="padding:24px;text-align:center;color:#666;">Không tìm thấy sản phẩm nào phù hợp.</td></tr>
						<?php else : foreach ( $wp_q->posts as $post_obj ) :
							$prod = wc_get_product( $post_obj->ID );
							if ( ! $prod ) { continue; }
							$pid      = $prod->get_id();
							$sku      = nexxi_kids_effective_sku( $prod );
							$prog     = nexxi_kids_get_program_sale( $prod, 'regular_admin' );
							$on_sale  = $prog['on_sale'];
							$pct      = $prog['pct'];
							$thumb    = wp_get_attachment_image_url( $prod->get_image_id(), 'thumbnail' );
							$cat_term = nexxi_kids_product_category( $prod );
							$children_count = $prod->is_type( 'variable' ) ? count( $prod->get_children() ) : 0;
						?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" class="nexxi-bd-cb" name="product_ids[]" value="<?php echo (int) $pid; ?>">
									<input type="hidden" name="row_pids[]" value="<?php echo (int) $pid; ?>">
								</th>
								<td>
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #eee;">
									<?php else : ?>
										<div style="width:44px;height:44px;border-radius:8px;background:#f5f3ef;"></div>
									<?php endif; ?>
								</td>
								<td>
									<input type="text" name="row_sku[<?php echo (int) $pid; ?>]" value="<?php echo esc_attr( $sku ); ?>" placeholder="Nhập SKU..." style="width:100%;min-height:30px;font-family:Consolas,monospace;font-size:12.5px;font-weight:700;color:#031444;border-radius:6px;border:1px solid #d5d3ce;padding:0 6px;">
								</td>
								<td>
									<strong style="font-size:13.5px;"><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>" style="color:#031444;text-decoration:none;"><?php echo esc_html( $prod->get_name() ); ?></a></strong>
									<div style="font-size:11.5px;color:#767680;margin-top:2px;">
										ID: #<?php echo (int) $pid; ?> ·
										<?php echo $cat_term ? esc_html( $cat_term->name ) . ' · ' : ''; ?>
										<?php echo $children_count ? (int) $children_count . ' biến thể' : 'SP đơn'; ?>
										· <a href="<?php echo esc_url( $prod->get_permalink() ); ?>" target="_blank">Xem ↗</a>
									</div>
								</td>
								<td>
									<input type="number" id="nx-bd-reg-<?php echo (int) $pid; ?>" name="row_reg[<?php echo (int) $pid; ?>]" value="<?php echo $prog['regular'] > 0 ? (int) round( $prog['regular'] ) : ''; ?>" min="0" step="any" oninput="nexxiRecalcRowFromPct(<?php echo (int) $pid; ?>)" onkeydown="if(event.key==='Enter'){event.preventDefault();nexxiSaveAllRows(<?php echo (int) $pid; ?>);}" style="width:100%;min-height:30px;font-weight:600;border-radius:6px;border:1px solid #d5d3ce;padding:0 6px;">
								</td>
								<td>
									<div style="position:relative;display:flex;align-items:center;">
										<input type="number" id="nx-bd-pct-<?php echo (int) $pid; ?>" name="row_pct[<?php echo (int) $pid; ?>]" value="<?php echo $pct > 0 ? (int) $pct : ''; ?>" min="0" max="95" step="any" placeholder="%" oninput="nexxiRecalcRowFromPct(<?php echo (int) $pid; ?>)" onkeydown="if(event.key==='Enter'){event.preventDefault();nexxiSaveAllRows(<?php echo (int) $pid; ?>);}" style="width:100%;min-height:30px;font-weight:700;color:#ae3115;border-radius:6px;border:1px solid #d5d3ce;padding:0 20px 0 6px;">
										<span style="position:absolute;right:6px;font-size:11px;color:#888;pointer-events:none;">%</span>
									</div>
								</td>
								<td>
									<input type="number" id="nx-bd-sale-<?php echo (int) $pid; ?>" name="row_sale[<?php echo (int) $pid; ?>]" value="<?php echo $on_sale ? (int) round( $prog['sale'] ) : ''; ?>" min="0" step="any" placeholder="Giá sau giảm" oninput="nexxiRecalcRowFromSale(<?php echo (int) $pid; ?>)" onkeydown="if(event.key==='Enter'){event.preventDefault();nexxiSaveAllRows(<?php echo (int) $pid; ?>);}" style="width:100%;min-height:30px;font-weight:800;color:#0b6b2f;border-radius:6px;border:1px solid #d5d3ce;padding:0 6px;">
								</td>
								<td>
									<?php if ( $on_sale ) : ?>
										<div style="font-weight:800;color:#ae3115;font-size:13px;">
											<?php echo esc_html( nexxi_kids_money( $prog['sale'] ) ); ?>
											<span style="background:#fd6a49;color:#fff;font-size:10.5px;padding:1px 6px;border-radius:999px;">-<?php echo (int) $pct; ?>%</span>
										</div>
										<span style="display:inline-block;font-size:11px;color:#0b6b2f;background:#e6f4ea;padding:1px 6px;border-radius:4px;margin-top:2px;">🏷️ Đang giảm giá bán SP</span>
										<?php if ( ! empty( $prog['other_active'] ) ) : ?>
											<div style="font-size:11px;color:#ae3115;background:#fff4f1;border:1px solid #ffdad2;padding:2px 6px;border-radius:4px;margin-top:3px;font-weight:700;">
												🔥 Cộng dồn Flash Sale (-<?php echo (int) $prog['other_pct']; ?>%): <?php echo esc_html( nexxi_kids_money( $prog['combined_sale'] ) ); ?> (-<?php echo (int) $prog['combined_pct']; ?>%)
											</div>
										<?php endif; ?>
									<?php else : ?>
										<span style="color:#767680;font-size:12.5px;">Giá gốc (Chưa giảm)</span>
									<?php endif; ?>
								</td>
								<td>
									<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
										<button type="button" class="button button-small" onclick="nexxiSetRowPct(<?php echo (int) $pid; ?>, 15)">-15%</button>
										<button type="button" class="button button-small" onclick="nexxiSetRowPct(<?php echo (int) $pid; ?>, 20)">-20%</button>
										<button type="button" class="button button-small" onclick="nexxiSetRowPct(<?php echo (int) $pid; ?>, 30)">-30%</button>
										<button type="button" class="button button-small button-primary" style="background:#031444;border-color:#031444;" onclick="nexxiSaveAllRows(<?php echo (int) $pid; ?>)">Lưu</button>
										<?php if ( $on_sale ) : ?>
											<button type="button" class="button button-small" style="color:#ba1a1a;border-color:#ffdad6;" onclick="nexxiClearRowSale(<?php echo (int) $pid; ?>)">Xóa</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>

				<div style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
					<div>
						<?php if ( $wp_q->max_num_pages > 1 ) : ?>
							<span style="color:#666;font-size:13px;margin-right:8px;">Trang <?php echo (int) $paged; ?> / <?php echo (int) $wp_q->max_num_pages; ?> (Tổng <?php echo (int) $wp_q->found_posts; ?> sản phẩm)</span>
							<?php for ( $i = 1; $i <= $wp_q->max_num_pages; $i++ ) :
								$link = add_query_arg( 'paged', $i ); ?>
								<a class="button <?php echo $i === $paged ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $link ); ?>" style="margin-right:3px;"><?php echo (int) $i; ?></a>
							<?php endfor; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button button-primary" style="min-height:38px;padding:0 22px;background:#0b6b2f;border-color:#0b6b2f;font-weight:700;" onclick="nexxiSaveAllRows(0)">
						💾 Lưu Tất Cả Thay Đổi Trên Bảng
					</button>
				</div>
			</form>
		</div>
	</div>

	<script>
	function nexxiSetBulkPreset(mode, val) {
		document.getElementById('nexxi-bd-bulk-mode').value = mode;
		document.getElementById('nexxi-bd-bulk-val').value = val;
	}
	function nexxiSubmitBulkBar() {
		document.getElementById('nexxi-bd-action-field').value = 'bulk_apply_direct';
		document.getElementById('nexxi-bd-master-form').submit();
	}
	function nexxiSaveAllRows(onlyPid) {
		document.getElementById('nexxi-bd-action-field').value = 'save_table_rows';
		document.getElementById('nexxi-bd-single-pid').value = onlyPid || 0;
		document.getElementById('nexxi-bd-master-form').submit();
	}
	function nexxiRecalcRowFromPct(pid) {
		var regEl  = document.getElementById('nx-bd-reg-' + pid);
		var pctEl  = document.getElementById('nx-bd-pct-' + pid);
		var saleEl = document.getElementById('nx-bd-sale-' + pid);
		var reg = parseFloat(regEl.value) || 0;
		var pct = parseFloat(pctEl.value) || 0;
		if (reg > 0 && pct > 0 && pct <= 95) {
			var sale = Math.round((reg * (100 - pct) / 100) / 1000) * 1000;
			if (sale <= 0 || sale >= reg) {
				sale = Math.max(1000, Math.round(reg * (100 - pct) / 100));
			}
			saleEl.value = sale;
		} else if (pct <= 0) {
			saleEl.value = '';
		}
	}
	function nexxiRecalcRowFromSale(pid) {
		var regEl  = document.getElementById('nx-bd-reg-' + pid);
		var pctEl  = document.getElementById('nx-bd-pct-' + pid);
		var saleEl = document.getElementById('nx-bd-sale-' + pid);
		var reg  = parseFloat(regEl.value) || 0;
		var sale = parseFloat(saleEl.value) || 0;
		if (reg > 0 && sale > 0 && sale < reg) {
			pctEl.value = Math.round((1 - sale / reg) * 100);
		} else if (!sale || sale <= 0) {
			pctEl.value = '';
		}
	}
	function nexxiSetRowPct(pid, pct) {
		var pctEl = document.getElementById('nx-bd-pct-' + pid);
		pctEl.value = pct;
		nexxiRecalcRowFromPct(pid);
		nexxiSaveAllRows(pid);
	}
	function nexxiClearRowSale(pid) {
		document.getElementById('nx-bd-pct-' + pid).value = '';
		document.getElementById('nx-bd-sale-' + pid).value = '';
		nexxiSaveAllRows(pid);
	}
	</script>
	<?php
}
