<?php
/**
 * Fallback template for posts and pages.
 *
 * @package Nexxi_Theme
 */

get_header();
?>
<main id="main-content" class="mx-auto min-h-[50vh] max-w-nexxi px-4 py-10 sm:px-6">
	<?php if ( have_posts() ) : ?>
		<?php if ( ! is_singular() ) : ?>
			<h1 class="mb-6 text-2xl font-semibold"><?php echo esc_html( is_home() ? __( 'Latest posts', 'nexxi-theme' ) : wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
		<?php endif; ?>
		<div class="space-y-10">
			<?php while ( have_posts() ) : ?>
				<?php the_post(); ?>
				<article <?php post_class( 'rounded-nexxi bg-white p-6 shadow-nexxi' ); ?> id="post-<?php the_ID(); ?>">
					<?php if ( is_singular() ) : ?>
						<h1 class="mb-4 text-2xl font-semibold"><?php echo esc_html( get_the_title() ); ?></h1>
						<div class="max-w-none"><?php the_content(); ?></div>
					<?php else : ?>
						<h2 class="mb-4 text-xl font-semibold"><a href="<?php echo esc_url( get_permalink() ); ?>"><?php echo esc_html( get_the_title() ); ?></a></h2>
						<div class="max-w-none"><?php the_excerpt(); ?></div>
					<?php endif; ?>
				</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<h1 class="text-2xl font-semibold"><?php esc_html_e( 'Content is coming soon.', 'nexxi-theme' ); ?></h1>
	<?php endif; ?>
</main>
<?php
get_footer();
