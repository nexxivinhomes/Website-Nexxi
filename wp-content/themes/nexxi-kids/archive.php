<?php
/** Category, tag, author and date archives rendered inside the shared Stitch shell. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
	require get_template_directory() . '/woocommerce.php';
	return;
}
nexxi_kids_render_stitch( 'blog' );
