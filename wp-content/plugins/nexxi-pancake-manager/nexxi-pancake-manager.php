<?php
/**
 * Plugin Name: NEXXI Pancake SKU & Image Manager
 * Description: Quản lý sản phẩm đã đồng bộ với Pancake POS bằng mã SKU, tự động quét ảnh trên Pancake để đưa về Website và lấy ảnh theo phân loại (Màu sắc) trên Pancake để set ảnh phân loại trên Website.
 * Version: 1.0.0
 * Author: NEXXI Kids
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: nexxi-pancake-manager
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-pancake-sync.php';

add_action( 'plugins_loaded', function() {
	if ( class_exists( 'Nexxi_Pancake_Sync_Manager' ) ) {
		Nexxi_Pancake_Sync_Manager::init();
	}
} );
