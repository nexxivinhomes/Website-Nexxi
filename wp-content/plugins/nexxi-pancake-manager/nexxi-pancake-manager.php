<?php
/**
 * Plugin Name: NEXXI Pancake SKU & Image Manager
 * Description: Quản lý sản phẩm đã đồng bộ với Pancake POS bằng mã SKU, tự động quét ảnh trên Pancake để đưa về Website và lấy ảnh theo phân loại (Màu sắc) trên Pancake để set ảnh phân loại trên Website.
 * Version: 1.5.5
 * Author: NEXXI Kids
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: nexxi-pancake-manager
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nexxi_pancake_extract_sync_file_version' ) ) {
	/**
	 * Extract `const VERSION = '...'` from the header of a Pancake sync manager file without loading it.
	 */
	function nexxi_pancake_extract_sync_file_version( $file ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return '0.0.0';
		}
		$head = (string) @file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( preg_match( "/const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]/", $head, $m ) ) {
			return trim( $m[1] );
		}
		return '0.0.0';
	}
}

$nexxi_pancake_manager_boot = static function () {
	if ( defined( 'NEXXI_PANCAKE_SYNC_BOOTED_VER' ) ) {
		return;
	}
	$plugin_file = __DIR__ . '/includes/class-pancake-sync.php';
	$theme_file  = function_exists( 'get_template_directory' ) ? ( get_template_directory() . '/inc/pancake-sync-admin.php' ) : '';
	$plugin_ver  = nexxi_pancake_extract_sync_file_version( $plugin_file );
	$theme_ver   = $theme_file ? nexxi_pancake_extract_sync_file_version( $theme_file ) : '0.0.0';

	if ( version_compare( $plugin_ver, $theme_ver, '>=' ) ) {
		if ( $theme_file && version_compare( $plugin_ver, $theme_ver, '>' ) && file_exists( $theme_file ) && is_writable( $theme_file ) ) {
			@copy( $plugin_file, $theme_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $theme_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( is_readable( $plugin_file ) ) {
			require_once $plugin_file;
		}
	} elseif ( $theme_file && is_readable( $theme_file ) ) {
		if ( file_exists( $plugin_file ) && is_writable( $plugin_file ) ) {
			@copy( $theme_file, $plugin_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $plugin_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		require_once $theme_file;
	}
};

if ( function_exists( 'get_template_directory' ) ) {
	$nexxi_pancake_manager_boot();
} else {
	add_action( 'plugins_loaded', $nexxi_pancake_manager_boot, 1 );
}

add_action(
	'plugins_loaded',
	static function () use ( $nexxi_pancake_manager_boot ) {
		$nexxi_pancake_manager_boot();
		foreach ( array( 'Nexxi_Pancake_Sync_Manager_V7', 'Nexxi_Pancake_Sync_Manager_V6', 'Nexxi_Pancake_Sync_Manager' ) as $cls ) {
			if ( class_exists( $cls, false ) && method_exists( $cls, 'init' ) ) {
				$cls::init();
				break;
			}
		}
	},
	5
);
