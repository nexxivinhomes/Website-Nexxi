<?php
/** Single blog post rendered inside the shared Stitch shell. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( function_exists( 'is_product' ) && is_product() ) {
	nexxi_kids_render_stitch( 'product' );
	return;
}
nexxi_kids_render_stitch( 'single' );
