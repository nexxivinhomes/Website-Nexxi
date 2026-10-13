<?php if ( post_password_required() ) { return; } ?>
<section id="comments" class="comments-area">
	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title"><?php esc_html_e( 'Trao đổi', 'nexxi-kids' ); ?></h2>
		<ol class="comment-list"><?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size' => 40 ) ); ?></ol>
		<?php the_comments_navigation(); ?>
	<?php endif; ?>
	<?php if ( ! comments_open() && get_comments_number() ) : ?><p><?php esc_html_e( 'Phần bình luận đã đóng.', 'nexxi-kids' ); ?></p><?php endif; ?>
	<?php comment_form(); ?>
</section>
