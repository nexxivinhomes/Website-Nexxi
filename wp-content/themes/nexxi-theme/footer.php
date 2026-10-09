<?php
/**
 * Site footer.
 *
 * @package Nexxi_Theme
 */
?>
<footer class="border-t border-nexxi-line bg-white px-4 py-8 text-center text-sm sm:px-6">
	<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
