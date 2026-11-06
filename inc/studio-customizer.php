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

	/** Dynamic repeater control for adding, reordering, and deleting extra Footer links. */
	class Nexxi_Studio_Footer_Links_Control extends WP_Customize_Control {
		public $type = 'nexxi-footer-links';
		public function render_content() {
			?>
			<div class="nxs-fl-wrap" data-nxs-footer-links>
				<?php if ( $this->label ) : ?>
					<span class="customize-control-title nxs-fl__heading"><?php echo esc_html( $this->label ); ?></span>
				<?php endif; ?>
				<?php if ( $this->description ) : ?>
					<p class="description customize-control-description nxs-fl__desc"><?php echo wp_kses_post( $this->description ); ?></p>
				<?php endif; ?>
				<input type="hidden" <?php $this->link(); ?> value="<?php echo esc_attr( $this->value() ); ?>" data-nxs-fl-input>
				<div class="nxs-fl-empty" data-nxs-fl-empty>Chưa có liên kết thêm mới nào. Bấm nút bên dưới để thêm mục đầu tiên.</div>
				<div class="nxs-fl-list" data-nxs-fl-list></div>
				<button type="button" class="button nxs-fl-add-btn" data-nxs-fl-add>
					<span class="nxs-fl-add-btn__plus" aria-hidden="true">＋</span>
					<span>Thêm liên kết mới</span>
				</button>
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
			if ( in_array( $key, array( 'footer-col-1__t1', 'footer-col-2__t1', 'footer-bottom__t1' ), true ) ) {
				nexxi_studio_footer_extra_links_control( $wp, $region, $section, $priority );
			}
		}

		// Region specific extras.
		if ( 'header-menu' === $region ) { nexxi_studio_menu_controls( $wp, $section, $priority ); }
		if ( 'footer-social' === $region ) { nexxi_studio_social_controls( $wp, $section, $priority ); }
		if ( 'footer-col-1' === $region || 'footer-col-2' === $region ) { nexxi_studio_footer_hide_defaults_controls( $wp, $region, $section, $priority ); }
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

/** Add the "➕ Liên kết tùy chọn thêm" repeater right below the column heading in Footer sections. */
function nexxi_studio_footer_extra_links_control( WP_Customize_Manager $wp, $region, $section, &$priority ) {
	$map = array(
		'footer-col-1'  => array(
			'setting'      => 'footer_col_1_extra_links',
			'desc'         => 'Bấm <b>+ Thêm liên kết mới</b> để chèn thêm mục vào cột này (hiển thị nối tiếp bên dưới danh sách mặc định).',
			'next_heading' => '✏️ Liên kết mặc định có sẵn',
			'next_desc'    => 'Sửa chữ hiển thị hoặc đường dẫn của 5 mục mặc định (để trống ô Link = giữ đường dẫn tự động).',
		),
		'footer-col-2'  => array(
			'setting'      => 'footer_col_2_extra_links',
			'desc'         => 'Bấm <b>+ Thêm liên kết mới</b> để chèn thêm mục vào cột này (hiển thị nối tiếp bên dưới danh sách mặc định, ngay phía trên dòng Hotline).',
			'next_heading' => '✏️ Liên kết mặc định có sẵn',
			'next_desc'    => 'Sửa chữ hiển thị hoặc đường dẫn của các mục mặc định trong cột Chăm sóc khách hàng.',
		),
		'footer-bottom' => array(
			'setting'      => 'footer_bottom_extra_links',
			'desc'         => 'Bấm <b>+ Thêm liên kết mới</b> để chèn hàng liên kết phụ vào dòng bản quyền cuối trang (phân tách bằng dấu ·).',
			'next_heading' => '✏️ Huy hiệu cam kết bên phải',
			'next_desc'    => 'Biểu tượng và nhãn ngắn nằm bên phải dòng bản quyền.',
		),
	);
	if ( ! isset( $map[ $region ] ) ) {
		return;
	}
	$cfg        = $map[ $region ];
	$setting_id = 'nexxi_studio[' . $cfg['setting'] . ']';

	$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_fl_head_' . $region, array(
		'label'       => '➕ Liên kết tùy chọn thêm',
		'description' => $cfg['desc'],
		'section'     => $section,
		'settings'    => array(),
		'priority'    => $priority++,
	) ) );

	$wp->add_setting( $setting_id, array(
		'type'              => 'theme_mod',
		'default'           => '',
		'sanitize_callback' => 'nexxi_studio_sanitize_extra_links',
		'transport'         => 'refresh',
	) );
	$wp->add_control( new Nexxi_Studio_Footer_Links_Control( $wp, $setting_id, array(
		'section'  => $section,
		'priority' => $priority++,
	) ) );

	$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_fl_def_' . $region, array(
		'label'       => $cfg['next_heading'],
		'description' => $cfg['next_desc'],
		'section'     => $section,
		'settings'    => array(),
		'priority'    => $priority++,
	) ) );
}

/** Add checkboxes at the bottom of footer-col-1 & footer-col-2 to optionally hide built-in default links. */
function nexxi_studio_footer_hide_defaults_controls( WP_Customize_Manager $wp, $region, $section, &$priority ) {
	$defaults = nexxi_studio_footer_default_links();
	if ( empty( $defaults[ $region ] ) ) {
		return;
	}
	$prefix = 'footer-col-1' === $region ? 'footer_col_1_hide_' : 'footer_col_2_hide_';
	$wp->add_control( new Nexxi_Studio_Heading_Control( $wp, 'nxs_fl_hide_h_' . $region, array(
		'label'       => '👁 Ẩn / Hiện liên kết mặc định',
		'description' => 'Tích chọn mục bạn muốn <b>ẩn</b> khỏi cột chân trang này (bỏ tích để hiện lại):',
		'section'     => $section,
		'settings'    => array(),
		'priority'    => $priority++,
	) ) );

	foreach ( $defaults[ $region ] as $idx => $def ) {
		$setting_id = 'nexxi_studio[' . $prefix . $idx . ']';
		$wp->add_setting( $setting_id, array(
			'type'              => 'theme_mod',
			'default'           => '',
			'sanitize_callback' => 'nexxi_studio_sanitize_check',
			'transport'         => 'refresh',
		) );
		$wp->add_control( $setting_id, array(
			'label'    => 'Ẩn mục ' . $idx . ': “' . $def['label'] . '”',
			'section'  => $section,
			'type'     => 'checkbox',
			'priority' => $priority++,
		) );
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

/** Curated quick-select links (system routes, blog categories, product categories, and all published WordPress pages). */
function nexxi_studio_quick_links() {
	$orders_url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : nexxi_kids_stitch_url( 'account.html' );
	$links      = array(
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Trang chủ', 'title' => 'Trang chủ', 'url' => '/' ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Cửa hàng (Tất cả sản phẩm)', 'title' => 'Cửa hàng', 'url' => nexxi_studio_relative_url( nexxi_kids_shop_url() ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Thời trang Bé Gái', 'title' => 'Thời trang Bé Gái', 'url' => nexxi_studio_relative_url( add_query_arg( 'cat_group', 'dam-vay', nexxi_kids_shop_url() ) ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Thời trang Bé Trai', 'title' => 'Thời trang Bé Trai', 'url' => nexxi_studio_relative_url( add_query_arg( 'cat_group', 'ao-thoi-trang', nexxi_kids_shop_url() ) ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Set phối & Bộ đồ', 'title' => 'Set phối & Bộ đồ', 'url' => nexxi_studio_relative_url( add_query_arg( 'cat_group', 'set-bo', nexxi_kids_shop_url() ) ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Ưu đãi Flash Sale', 'title' => 'Ưu đãi Flash Sale', 'url' => nexxi_studio_relative_url( nexxi_kids_flash_url() ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Bảng size cho bé', 'title' => 'Cẩm nang chọn size', 'url' => '/#size-guide' ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Giỏ hàng', 'title' => 'Giỏ hàng', 'url' => nexxi_studio_relative_url( nexxi_kids_stitch_url( 'cart.html' ) ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Tài khoản khách hàng', 'title' => 'Tài khoản của mẹ', 'url' => nexxi_studio_relative_url( nexxi_kids_stitch_url( 'account.html' ) ) ),
		array( 'group' => 'Trang mua sắm & Hệ thống', 'label' => 'Tra cứu đơn hàng', 'title' => 'Tra cứu tình trạng đơn hàng', 'url' => nexxi_studio_relative_url( $orders_url ) ),
	);

	// Blog archive & post categories (if available).
	$posts_page_id = (int) get_option( 'page_for_posts' );
	if ( $posts_page_id > 0 ) {
		$posts_page = get_post( $posts_page_id );
		if ( $posts_page && 'publish' === $posts_page->post_status ) {
			$blog_title = trim( wp_strip_all_tags( $posts_page->post_title ) ) ?: 'Blog & Tin tức';
			$links[]    = array(
				'group' => 'Chuyên mục Blog / Bài viết',
				'label' => $blog_title . ' (Trang bài viết)',
				'title' => $blog_title,
				'url'   => nexxi_studio_relative_url( get_permalink( $posts_page_id ) ),
			);
		}
	}
	$blog_cats = get_categories( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
	if ( is_array( $blog_cats ) ) {
		foreach ( $blog_cats as $cat ) {
			if ( in_array( $cat->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) && (int) $cat->count <= 0 ) {
				continue;
			}
			$cat_link = get_category_link( $cat->term_id );
			if ( ! is_wp_error( $cat_link ) && $cat_link ) {
				$cat_name = trim( wp_strip_all_tags( $cat->name ) );
				$links[]  = array(
					'group' => 'Chuyên mục Blog / Bài viết',
					'label' => $cat_name,
					'title' => $cat_name,
					'url'   => nexxi_studio_relative_url( $cat_link ),
				);
			}
		}
	}

	// All published WordPress pages.
	$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order,post_title' ) );
	if ( is_array( $pages ) ) {
		foreach ( $pages as $page_obj ) {
			$p_title = trim( wp_strip_all_tags( $page_obj->post_title ) );
			if ( '' === $p_title ) {
				continue;
			}
			$links[] = array(
				'group' => 'Trang có sẵn trên Website',
				'label' => $p_title,
				'title' => $p_title,
				'url'   => nexxi_studio_relative_url( get_permalink( $page_obj->ID ) ),
			);
		}
	}

	// Product categories (WooCommerce) if present.
	if ( taxonomy_exists( 'product_cat' ) ) {
		$p_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 25 ) );
		if ( is_array( $p_cats ) && ! is_wp_error( $p_cats ) ) {
			foreach ( $p_cats as $p_cat ) {
				if ( in_array( $p_cat->slug, array( 'uncategorized', 'chua-phan-loai' ), true ) ) {
					continue;
				}
				$p_link = get_term_link( $p_cat );
				if ( ! is_wp_error( $p_link ) && $p_link ) {
					$pc_name = trim( wp_strip_all_tags( $p_cat->name ) );
					$links[] = array(
						'group' => 'Danh mục sản phẩm',
						'label' => $pc_name,
						'title' => $pc_name,
						'url'   => nexxi_studio_relative_url( $p_link ),
					);
				}
			}
		}
	}

	return $links;
}

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
	wp_localize_script( 'nexxi-studio-controls', 'NexxiStudioControls', array(
		'regions'      => $regions,
		'heroDefaults' => $hero_defaults,
		'quickLinks'   => nexxi_studio_quick_links(),
	) );
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
