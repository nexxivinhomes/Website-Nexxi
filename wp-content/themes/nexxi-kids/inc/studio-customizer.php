<?php
/**
 * NEXXI Studio – Customizer panels, sections and controls (Giao diện → Tùy biến).
 * One section per page region; the preview highlights the region being edited.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nexxi_studio_define_controls() {
	if ( class_exists( 'Nexxi_Studio_Heading_Control' ) ) { return; }
	/** Visual group heading inside a section (no setting). */
	class Nexxi_Studio_Heading_Control extends WP_Customize_Control {
		public $type = 'nexxi-heading';
		public function render_content() {
			echo '<div class="nxs-heading"><span>' . esc_html( $this->label ) . '</span></div>';
			if ( $this->description ) { echo '<p class="nxs-heading__desc">' . wp_kses_post( $this->description ) . '</p>'; }
		}
	}

	/** Static help / links block. */
	class Nexxi_Studio_Html_Control extends WP_Customize_Control {
		public $type = 'nexxi-html';
		public $html = '';
		public function render_content() {
			echo '<div class="nxs-help">' . wp_kses_post( $this->html ) . '</div>';
		}
	}

	/** Interactive drag-and-drop + up/down sorter for Hero Banner slides (1..5). */
	class Nexxi_Studio_Hero_Order_Control extends WP_Customize_Control {
		public $type = 'nexxi-hero-order';
		public function render_content() {
			?>
			<div class="nxs-hero-sorter-wrap">
				<div class="nxs-hero-sorter__head">
					<span class="nxs-hero-sorter__title">⇅ Sắp xếp thứ tự ảnh Banner</span>
					<button type="button" class="button-link nxs-hero-sorter__reset" data-nxs-hero-order-reset>Đặt lại 1 → 5</button>
				</div>
				<p class="nxs-hero-sorter__desc">Kéo thả các dòng hoặc bấm nút <b>↑ / ↓</b> để đổi thứ tự chạy slide (5s/ảnh):</p>
				<input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr( $this->value() ); ?>" data-nxs-hero-order-input>
				<ul class="nxs-hero-sorter" data-nxs-hero-sorter></ul>
			</div>
			<?php
		}
	}
}

function nexxi_studio_sanitize_hero_order( $value ) {
	return implode( ',', nexxi_studio_hero_slide_order( $value ) );
}

function nexxi_studio_sanitize_text( $value ) {
	return sanitize_textarea_field( (string) $value );
}
function nexxi_studio_sanitize_link( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) { return ''; }
	if ( '#' === $value[0] || ( '/' === $value[0] && ( ! isset( $value[1] ) || '/' !== $value[1] ) ) ) { return sanitize_text_field( $value ); }
	return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
}
function nexxi_studio_sanitize_check( $value ) {
	return ( $value && 'false' !== $value ) ? '1' : '';
}
function nexxi_studio_sanitize_badge( $value ) {
	return in_array( $value, array( 'soft', 'hot', 'pink' ), true ) ? $value : 'soft';
}
function nexxi_studio_sanitize_font_choice( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) { return ''; }
	if ( in_array( $value, array( 'custom_1', 'custom_2', 'custom_3' ), true ) ) { return $value; }
	$google = nexxi_studio_google_fonts();
	return isset( $google[ $value ] ) ? $value : '';
}
function nexxi_studio_sanitize_font_file( $value ) {
	$url = esc_url_raw( trim( (string) $value ) );
	if ( '' === $url ) { return ''; }
	$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
	return in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ? $url : '';
}

function nexxi_studio_customize( WP_Customize_Manager $wp ) {
	nexxi_studio_define_controls();
	$p = 0;
	foreach ( nexxi_studio_panels() as $id => $panel ) {
		$wp->add_panel( $id, array( 'title' => $panel[0], 'description' => $panel[1] . '<br><br>💡 Rê chuột lên trang xem trước để thấy từng vùng, bấm nhãn <b>✎</b> để mở đúng phần chỉnh sửa.', 'priority' => 1 + ( $p++ ) ) );
	}

	// Quick guide (top of the Customizer).
	$wp->add_section( 'nxs_guide', array( 'title' => '★ Hướng dẫn NEXXI Studio', 'priority' => 0 ) );
	$wp->add_control( new Nexxi_Studio_Html_Control( $wp, 'nxs_guide_html', array(
		'section'  => 'nxs_guide',
		'settings' => array(),
		'html'     => '<p><b>Bộ công cụ chỉnh sửa toàn bộ website NEXXI.</b></p><ol><li>Chọn khu vực cần sửa trong danh sách (đánh số ① → ⑧ theo vị trí trên trang).</li><li>Trang xem trước bên phải tự chuyển tới đúng trang và <b>đóng khung cam</b> vùng đang sửa.</li><li>Bấm vào một ô nhập: chữ/ảnh tương ứng trên trang sẽ nhấp nháy để bạn biết đang sửa chỗ nào.</li><li>Hoặc rê chuột lên trang xem trước, bấm nhãn <b>✎ Tên vùng</b> để mở thẳng phần chỉnh sửa.</li><li>Bấm <b>Xuất bản</b> ở trên cùng để lưu cho khách thấy. Chưa bấm Xuất bản thì khách vẫn thấy bản cũ.</li></ol><p>Muốn quay về chữ gốc: xóa trống ô nhập rồi Xuất bản.<br>Ảnh thay thế nên cùng tỉ lệ với ảnh gốc để bố cục giữ nguyên.<br>Sản phẩm, giá, tồn kho: sửa trong <a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '" target="_blank">Sản phẩm</a>.</p>',
	) ) );

	$regions = nexxi_studio_regions();
	$by_region = array();
	foreach ( nexxi_studio_fields() as $key => $field ) { $by_region[ $field['r'] ][ $key ] = $field; }

	$s = 0;
	foreach ( $regions as $region => $meta ) {
		$section = 'nxs_' . $region;
		$desc = $meta[4] ? '📍 ' . $meta[4] : '';
		$wp->add_section( $section, array( 'title' => $meta[1], 'panel' => $meta[0], 'priority' => 10 + ( $s++ ), 'description' => $desc ) );
		$priority = 1;

		if ( $meta[3] ) {
			$wp->add_setting( 'nexxi_studio[show_' . $region . ']', array( 'type' => 'theme_mod', 'default' => '1', 'sanitize_callback' => 'nexxi_studio_sanitize_check', 'transport' => 'refresh' ) );
			$wp->add_control( 'nexxi_studio[show_' . $region . ']', array( 'label' => 'Hiển thị khu vực này', 'section' => $section, 'type' => 'checkbox', 'priority' => $priority++ ) );
		}

		$group = null;
		foreach ( isset( $by_region[ $region ] ) ? $by_region[ $region ] : array() as $key => $field ) {
			$label = nexxi_studio_group_label( $field['g'] );
			if ( $label !== $group && '' !== $label ) {
				$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_h_' . $key, array( 'label' => $label, 'section' => $section, 'settings' => array(), 'priority' => $priority++ ) ) );
				if ( 'home-hero__bg1' === $key ) {
					$wp->add_setting( 'nexxi_studio[home-hero__order]', array(
						'type'              => 'theme_mod',
						'default'           => '1,2,3,4,5',
						'sanitize_callback' => 'nexxi_studio_sanitize_hero_order',
						'transport'         => 'postMessage',
					) );
					$wp->add_control( new Nexxi_Studio_Hero_Order_Control( $wp, 'nexxi_studio[home-hero__order]', array(
						'section'  => $section,
						'priority' => $priority++,
					) ) );
				}
			}
			$group = $label;
			$field_priority = ( 0 === strpos( $key, 'home-hero__bg' ) ) ? ( 10 + (int) substr( $key, -1 ) ) : $priority++;
			nexxi_studio_add_field( $wp, $key, $field, $section, $field_priority );
		}

		// Region specific extras.
		if ( 'header-menu' === $region ) { nexxi_studio_menu_controls( $wp, $section, $priority ); }
		if ( 'footer-social' === $region ) { nexxi_studio_social_controls( $wp, $section, $priority ); }
		if ( 'global-colors' === $region ) { nexxi_studio_color_controls( $wp, $section, $priority ); }
		if ( 'global-fonts' === $region ) { nexxi_studio_font_controls( $wp, $section, $priority ); }
		if ( 'global-pages' === $region ) { nexxi_studio_pages_control( $wp, $section, $priority ); }
	}

	// Existing store section lives in the global panel.
	$store = $wp->get_section( 'nexxi_kids_store' );
	if ( $store ) {
		$store->panel = 'nxs_global';
		$store->title = 'Thông tin cửa hàng (hotline, email, địa chỉ, Flash Sale)';
		$store->priority = 11;
	}
}
add_action( 'customize_register', 'nexxi_studio_customize', 40 );

function nexxi_studio_add_field( WP_Customize_Manager $wp, $key, array $field, $section, $priority ) {
	$id = 'nexxi_studio[' . $key . ']';
	$default = nexxi_studio_asset( $field['d'] );
	switch ( $field['t'] ) {
		case 'image':
			$wp->add_setting( $id, array( 'type' => 'theme_mod', 'default' => $default, 'sanitize_callback' => 'esc_url_raw', 'transport' => 'postMessage' ) );
			$wp->add_control( new WP_Customize_Image_Control( $wp, $id, array( 'label' => $field['l'], 'section' => $section, 'priority' => $priority ) ) );
			return;
		case 'link':
			$wp->add_setting( $id, array( 'type' => 'theme_mod', 'default' => '', 'sanitize_callback' => 'nexxi_studio_sanitize_link', 'transport' => 'refresh' ) );
			$wp->add_control( $id, array( 'label' => $field['l'], 'section' => $section, 'priority' => $priority, 'type' => 'text', 'input_attrs' => array( 'placeholder' => 'Để trống = giữ link hiện tại · vd: /cua-hang/' ) ) );
			return;
		case 'lines':
			$wp->add_setting( $id, array( 'type' => 'theme_mod', 'default' => $default, 'sanitize_callback' => 'nexxi_studio_sanitize_text', 'transport' => 'refresh' ) );
			$wp->add_control( $id, array( 'label' => $field['l'], 'section' => $section, 'priority' => $priority, 'type' => 'textarea', 'input_attrs' => array( 'rows' => 6 ) ) );
			return;
	}
	$long = mb_strlen( $default ) > 60;
	$args = array( 'label' => $field['l'], 'section' => $section, 'priority' => $priority, 'type' => $long ? 'textarea' : 'text' );
	if ( 'icon' === $field['t'] ) { $args['description'] = 'Tên biểu tượng Material Symbols (vd: favorite, eco, local_shipping) – tra tại <a href="https://fonts.google.com/icons" target="_blank" rel="noopener">fonts.google.com/icons</a>.'; }
	if ( 'placeholder' === $field['t'] ) { $args['description'] = 'Chữ mờ hiển thị trong ô nhập khi chưa gõ.'; }
	if ( $long ) { $args['input_attrs'] = array( 'rows' => 3 ); }
	$wp->add_setting( $id, array( 'type' => 'theme_mod', 'default' => $default, 'sanitize_callback' => 'icon' === $field['t'] ? 'sanitize_key' : 'nexxi_studio_sanitize_text', 'transport' => 'postMessage' ) );
	$wp->add_control( $id, $args );
}

function nexxi_studio_menu_controls( WP_Customize_Manager $wp, $section, $priority ) {
	$wp->add_control( new Nexxi_Studio_Html_Control( $wp, 'nxs_menu_help', array( 'section' => $section, 'settings' => array(), 'priority' => $priority++, 'html' => 'Menu có 8 vị trí. Để trống “Tên mục” để không dùng vị trí đó. Nên giữ <b>tối đa 7 mục</b> để header nằm gọn trên một dòng. Link để trống = link tự động của mục gốc.' ) ) );
	foreach ( nexxi_studio_menu_defaults() as $n => $def ) {
		$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, "nxs_menu_h$n", array( 'label' => 'Mục ' . $n . ( $def[0] ? ' – ' . $def[0] : ' (trống)' ), 'section' => $section, 'settings' => array(), 'priority' => $priority++ ) ) );
		$fields = array(
			"menu_{$n}_label" => array( 'Tên mục', 'text', $def[0], 'nexxi_studio_sanitize_text' ),
			"menu_{$n}_url"   => array( 'Link', 'text', '', 'nexxi_studio_sanitize_link' ),
			"menu_{$n}_badge" => array( 'Nhãn nhỏ đi kèm (vd: Hot, Mới) – để trống nếu không cần', 'text', $def[2], 'nexxi_studio_sanitize_text' ),
			"menu_{$n}_style" => array( 'Kiểu nhãn', 'select', $def[3] ? $def[3] : 'soft', 'nexxi_studio_sanitize_badge' ),
			"menu_{$n}_hide"  => array( 'Ẩn mục này', 'checkbox', '', 'nexxi_studio_sanitize_check' ),
		);
		foreach ( $fields as $key => $f ) {
			$wp->add_setting( "nexxi_studio[$key]", array( 'type' => 'theme_mod', 'default' => $f[2], 'sanitize_callback' => $f[3], 'transport' => 'refresh' ) );
			$args = array( 'label' => $f[0], 'section' => $section, 'type' => $f[1], 'priority' => $priority++ );
			if ( 'select' === $f[1] ) { $args['choices'] = array( 'soft' => 'Vàng kem (như “Bé Yêu”)', 'hot' => 'Cam đậm nhấp nháy (như “⚡ Hot”)', 'pink' => 'Hồng phấn' ); }
			if ( strpos( $key, '_url' ) ) { $args['input_attrs'] = array( 'placeholder' => $def[1] ? 'Tự động' : 'vd: /cua-hang/ hoặc https://…' ); }
			$wp->add_control( "nexxi_studio[$key]", $args );
		}
	}
}

function nexxi_studio_social_controls( WP_Customize_Manager $wp, $section, $priority ) {
	foreach ( nexxi_studio_socials() as $key => $social ) {
		$wp->add_setting( "nexxi_studio[social_$key]", array( 'type' => 'theme_mod', 'default' => '', 'sanitize_callback' => 'zalo' === $key ? 'sanitize_text_field' : 'esc_url_raw', 'transport' => 'refresh' ) );
		$wp->add_control( "nexxi_studio[social_$key]", array( 'label' => $social[0], 'section' => $section, 'type' => 'zalo' === $key ? 'text' : 'url', 'priority' => $priority++, 'input_attrs' => array( 'placeholder' => 'zalo' === $key ? 'Số điện thoại Zalo hoặc link zalo.me/…' : 'https://…' ) ) );
	}
}

function nexxi_studio_color_controls( WP_Customize_Manager $wp, $section, $priority ) {
	$wp->add_control( new Nexxi_Studio_Html_Control( $wp, 'nxs_color_help', array( 'section' => $section, 'settings' => array(), 'priority' => $priority++, 'html' => 'Màu mặc định là màu của thiết kế Stitch. Bấm <b>Mặc định</b> trong bảng màu để quay về màu gốc.' ) ) );
	foreach ( nexxi_studio_colors() as $key => $color ) {
		$wp->add_setting( "nexxi_studio[$key]", array( 'type' => 'theme_mod', 'default' => $color[2], 'sanitize_callback' => 'sanitize_hex_color', 'transport' => 'refresh' ) );
		$wp->add_control( new WP_Customize_Color_Control( $wp, "nexxi_studio[$key]", array( 'label' => $color[0], 'section' => $section, 'priority' => $priority++ ) ) );
	}
}

function nexxi_studio_font_controls( WP_Customize_Manager $wp, $section, $priority ) {
	$wp->add_control( new Nexxi_Studio_Html_Control( $wp, 'nxs_font_help', array(
		'section'  => $section,
		'settings' => array(),
		'priority' => $priority++,
		'html'     => '<p><b>Chỉnh sửa Font chữ theo từng khu vực rõ ràng:</b></p>'
			. '<ul style="margin:6px 0 8px 16px;list-style:disc">'
			. '<li><b>Bấm vào ô chọn font bất kỳ:</b> trang xem trước bên phải sẽ <b>tự cuộn đến và nhấp nháy khung cam</b> đúng vị trí chữ đó để bạn dễ nhận biết.</li>'
			. '<li>Có sẵn <b>18 font Google chuẩn tiếng Việt</b> hoặc bạn có thể <b>tải file font riêng (.woff2, .woff, .ttf, .otf)</b> ở cuối mục này rồi chọn <b>📁 Font tải lên #1/#2/#3</b>.</li>'
			. '<li>Chọn <b>“— Mặc định theo thiết kế —”</b> để quay về font gốc của vùng đó.</li>'
			. '</ul>',
	) ) );

	$choices = nexxi_studio_font_choices();
	foreach ( nexxi_studio_font_zones() as $key => $zone ) {
		$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_fh_' . $key, array(
			'label'       => $zone[0],
			'description' => $zone[1],
			'section'     => $section,
			'settings'    => array(),
			'priority'    => $priority++,
		) ) );
		$wp->add_setting( "nexxi_studio[$key]", array(
			'type'              => 'theme_mod',
			'default'           => '',
			'sanitize_callback' => 'nexxi_studio_sanitize_font_choice',
			'transport'         => 'refresh',
		) );
		$wp->add_control( "nexxi_studio[$key]", array(
			'label'       => 'Chọn font cho khu vực này',
			'description' => '💡 ' . $zone[2],
			'section'     => $section,
			'type'        => 'select',
			'choices'     => $choices,
			'priority'    => $priority++,
		) );
	}

	// Custom font upload slots (1..3).
	$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_fh_upload_intro', array(
		'label'       => '📁 Tải lên Font riêng của bạn (Upload Font)',
		'description' => 'Hỗ trợ file <b>.woff2</b> (khuyên dùng vì nhẹ và nét nhất), <b>.woff</b>, <b>.ttf</b>, <b>.otf</b>. Sau khi tải file lên khe #1, #2 hoặc #3, hãy kéo lên trên và chọn <b>“📁 Font tải lên #1 / #2 / #3”</b> cho khu vực bạn muốn áp dụng.',
		'section'     => $section,
		'settings'    => array(),
		'priority'    => $priority++,
	) ) );

	for ( $slot = 1; $slot <= 3; $slot++ ) {
		$name_key = "custom_font_{$slot}_name";
		$file_key = "custom_font_{$slot}_file";

		$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, "nxs_fh_slot_{$slot}", array(
			'label'    => "Khe Font tải lên #{$slot}",
			'section'  => $section,
			'settings' => array(),
			'priority' => $priority++,
		) ) );

		$wp->add_setting( "nexxi_studio[$name_key]", array(
			'type'              => 'theme_mod',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'transport'         => 'postMessage',
		) );
		$wp->add_control( "nexxi_studio[$name_key]", array(
			'label'       => "Tên gợi nhớ Font #{$slot} (tùy chọn)",
			'section'     => $section,
			'type'        => 'text',
			'priority'    => $priority++,
			'input_attrs' => array( 'placeholder' => 'Vd: SVN Gilroy, UTM Avo, Font Banner...' ),
		) );

		$wp->add_setting( "nexxi_studio[$file_key]", array(
			'type'              => 'theme_mod',
			'default'           => '',
			'sanitize_callback' => 'nexxi_studio_sanitize_font_file',
			'transport'         => 'refresh',
		) );
		$wp->add_control( new WP_Customize_Upload_Control( $wp, "nexxi_studio[$file_key]", array(
			'label'       => "File Font #{$slot} (.woff2, .woff, .ttf, .otf)",
			'description' => 'Bấm “Chọn tập tin” để tải file font từ máy tính lên.',
			'section'     => $section,
			'priority'    => $priority++,
		) ) );
	}
}

function nexxi_studio_pages_control( WP_Customize_Manager $wp, $section, $priority ) {
	$rows = '';
	foreach ( array( 'gioi-thieu' => 'Giới thiệu', 'lien-he' => 'Liên hệ (FAQ bên dưới form)', 'doi-tra' => 'Đổi trả & Bảo hành', 'van-chuyen' => 'Vận chuyển', 'yeu-thich' => 'Yêu thích' ) as $slug => $title ) {
		$page = get_page_by_path( $slug );
		if ( ! $page ) { continue; }
		$rows .= '<li><b>' . esc_html( $title ) . '</b><br><a href="' . esc_url( get_edit_post_link( $page->ID, 'raw' ) ) . '" target="_blank">✎ Sửa nội dung</a> · <a href="' . esc_url( get_permalink( $page ) ) . '" class="nxs-preview-link" data-url="' . esc_url( get_permalink( $page ) ) . '">👁 Xem trước</a></li>';
	}
	$wp->add_control( new Nexxi_Studio_Html_Control( $wp, 'nxs_pages_html', array(
		'section'  => $section,
		'settings' => array(),
		'priority' => $priority,
		'html'     => '<p>Nội dung các trang phụ được soạn trong trình soạn thảo WordPress (mở ở tab mới). Header, footer và khung trang vẫn theo thiết kế NEXXI.</p><ul class="nxs-pages">' . $rows . '</ul><p>Thông tin liên hệ trên trang Liên hệ lấy từ “Thông tin cửa hàng”.</p>',
	) ) );
}

/* ------------------------------------------------------------------ scripts */

function nexxi_studio_controls_assets() {
	$base = get_template_directory_uri() . '/assets/studio/';
	$dir = get_template_directory() . '/assets/studio/';
	wp_enqueue_style( 'nexxi-studio-controls', $base . 'studio-controls.css', array(), filemtime( $dir . 'studio-controls.css' ) );
	wp_enqueue_script( 'nexxi-studio-controls', $base . 'studio-controls.js', array( 'customize-controls', 'jquery' ), filemtime( $dir . 'studio-controls.js' ), true );
	$regions = array();
	foreach ( nexxi_studio_regions() as $id => $meta ) {
		$regions[ $id ] = array( 'url' => nexxi_studio_page_url( $meta[2] ), 'title' => $meta[1] );
	}
	$hero_defaults = array();
	for ( $slot = 1; $slot <= 5; $slot++ ) {
		$hero_defaults[ $slot ] = nexxi_studio_default( 'home-hero__bg' . $slot );
	}
	wp_localize_script( 'nexxi-studio-controls', 'NexxiStudioControls', array( 'regions' => $regions, 'heroDefaults' => $hero_defaults ) );
}
add_action( 'customize_controls_enqueue_scripts', 'nexxi_studio_controls_assets' );

function nexxi_studio_preview_assets() {
	$base = get_template_directory_uri() . '/assets/studio/';
	$dir = get_template_directory() . '/assets/studio/';
	wp_enqueue_script( 'nexxi-studio-preview', $base . 'studio-preview.js', array( 'customize-preview' ), filemtime( $dir . 'studio-preview.js' ), true );
	$panels = nexxi_studio_panels();
	$titles = array();
	foreach ( nexxi_studio_regions() as $id => $meta ) {
		$titles[ $id ] = preg_replace( '~^[^\s]+\s~u', '', $panels[ $meta[0] ][0] ) . ' › ' . preg_replace( '~^\d+\.\s*~', '', $meta[1] );
	}
	$defaults = array();
	foreach ( nexxi_studio_fields() as $key => $field ) {
		if ( 'link' !== $field['t'] && 'lines' !== $field['t'] ) { $defaults[ $key ] = nexxi_studio_asset( $field['d'] ); }
	}
	wp_localize_script( 'nexxi-studio-preview', 'NexxiStudioPreview', array( 'titles' => $titles, 'defaults' => $defaults ) );
}
add_action( 'customize_preview_init', static function () {
	add_action( 'wp_enqueue_scripts', 'nexxi_studio_preview_assets', 30 );
} );
