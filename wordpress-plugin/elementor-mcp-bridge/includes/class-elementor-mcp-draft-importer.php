<?php
/** Converts an approved, short-lived Figma preview into a new Elementor draft. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Elementor_MCP_Draft_Importer {
	public static function init(): void { add_action( 'admin_post_elementor_mcp_create_draft', array( __CLASS__, 'create' ) ); }

	public static function create(): void {
		if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You cannot create Elementor drafts.', 'elementor-mcp-bridge' ), 403 ); }
		check_admin_referer( 'elementor_mcp_create_draft' );
		$preview = Elementor_MCP_Figma_Analyzer::preview();
		if ( ! $preview || empty( $preview['document'] ) || true !== (bool) ( $_POST['confirm_draft'] ?? false ) || ! did_action( 'elementor/loaded' ) ) { self::redirect( 'draft-failed' ); }
		$content = self::convert( $preview['document'] );
		if ( ! $content ) { self::redirect( 'draft-failed' ); }
		$title = sanitize_text_field( $_POST['draft_title'] ?? ( $preview['source']['name'] ?? 'Figma import' ) );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title ?: 'Figma import', 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $id ) ) { self::redirect( 'draft-failed' ); }
		update_post_meta( $id, '_elementor_edit_mode', 'builder' ); update_post_meta( $id, '_elementor_template_type', 'wp-page' ); update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( self::convert_node( $preview['document'], null, true ) ) ) ) );
		update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); clean_post_cache( $id );
		self::redirect( 'draft-created', array( 'draft_id' => $id ) );
	}

	private static function convert( array $node ): bool { return null !== self::convert_node( $node, null, true ); }
	/**
	 * Auto Layout maps to Elementor flexbox. A Figma parent without Auto Layout
	 * retains its desktop coordinates using Elementor's native absolute controls.
	 */
	private static function convert_node( array $node, ?array $parent = null, bool $is_root = false ): ?array {
		if ( false === ( $node['visible'] ?? true ) ) return null;
		$layout_mode = $node['layoutMode'] ?? 'NONE';
		$positioning = self::positioning( $node, $parent, $is_root );
		if ( 'TEXT' === ( $node['type'] ?? '' ) ) {
			$style = (array) ( $node['style'] ?? array() ); $size = (float) ( $style['fontSize'] ?? 16 ); $heading = $size >= 24;
			return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => $heading ? 'heading' : 'text-editor', 'settings' => array_merge( self::visual( $node ), $positioning, array( $heading ? 'title' : 'editor' => $node['characters'] ?? '', 'text_color' => self::color( $node['fills'] ?? array() ), 'typography_typography' => 'custom', 'typography_font_family' => $style['fontFamily'] ?? '', 'typography_font_weight' => (string) ( $style['fontWeight'] ?? 400 ), 'typography_font_size' => self::size( $size ), 'typography_line_height' => self::size( $style['lineHeightPx'] ?? null ), 'typography_letter_spacing' => self::size( $style['letterSpacing'] ?? null ) ) ), 'elements' => array() );
		}
		$children = array(); foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) && ( $element = self::convert_node( $child, $node ) ) ) $children[] = $element; }
		$justify = array( 'MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'SPACE_BETWEEN' => 'space-between' ); $align = array( 'MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'BASELINE' => 'baseline' );
		$horizontal_size = $node['layoutSizingHorizontal'] ?? '';
		$width = ( 1 === (int) ( $node['layoutGrow'] ?? 0 ) || 'FILL' === $horizontal_size ) ? array( 'unit' => '%', 'size' => 100, 'sizes' => array() ) : ( 'FIXED' === $horizontal_size ? self::size( $node['absoluteBoundingBox']['width'] ?? null ) : null );
		if ( isset( $positioning['width'] ) ) $width = $positioning['width'];
		$height = 'FIXED' === ( $node['layoutSizingVertical'] ?? '' ) || 'NONE' === $layout_mode ? self::size( $node['absoluteBoundingBox']['height'] ?? null ) : null;
		if ( isset( $positioning['min_height'] ) ) $height = $positioning['min_height'];
		return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'container', 'isInner' => true, 'settings' => array_merge( self::visual( $node ), $positioning, array( 'content_width' => 'full', 'flex_direction' => 'HORIZONTAL' === $layout_mode ? 'row' : 'column', 'flex_justify_content' => $justify[ $node['primaryAxisAlignItems'] ?? '' ] ?? 'flex-start', 'flex_align_items' => $align[ $node['counterAxisAlignItems'] ?? '' ] ?? 'stretch', 'flex_gap' => isset( $node['itemSpacing'] ) ? array( 'column' => (string) $node['itemSpacing'], 'row' => (string) $node['itemSpacing'], 'isLinked' => true, 'unit' => 'px', 'size' => (float) $node['itemSpacing'] ) : null, 'padding' => self::box( $node['paddingTop'] ?? 0, $node['paddingRight'] ?? 0, $node['paddingBottom'] ?? 0, $node['paddingLeft'] ?? 0 ), 'width' => $width, 'min_height' => $height ) ), 'elements' => $children );
	}
	private static function positioning( array $node, ?array $parent, bool $is_root ): array {
		if ( $is_root || ! $parent || 'NONE' !== ( $parent['layoutMode'] ?? 'NONE' ) ) return array();
		$box = $node['absoluteBoundingBox'] ?? array(); $parent_box = $parent['absoluteBoundingBox'] ?? array();
		if ( ! isset( $box['x'], $box['y'], $box['width'], $box['height'], $parent_box['x'], $parent_box['y'] ) ) return array();
		$x = (float) $box['x'] - (float) $parent_box['x']; $y = (float) $box['y'] - (float) $parent_box['y'];
		if ( 'TEXT' === ( $node['type'] ?? '' ) ) return array( '_position' => 'absolute', '_offset_orientation_h' => 'start', '_offset_x' => self::size( $x ), '_offset_orientation_v' => 'start', '_offset_y' => self::size( $y ), '_element_width' => 'initial', '_element_custom_width' => self::size( $box['width'] ) );
		return array( 'position' => 'absolute', '_offset_orientation_h' => 'start', '_offset_x' => self::size( $x ), '_offset_orientation_v' => 'start', '_offset_y' => self::size( $y ), 'width' => self::size( $box['width'] ), 'min_height' => self::size( $box['height'] ) );
	}
	private static function visual( array $node ): array { $settings = array(); if ( $fill = self::color( $node['fills'] ?? array() ) ) { $settings['background_background'] = 'classic'; $settings['background_color'] = $fill; } if ( $stroke = self::color( $node['strokes'] ?? array() ) ) { $settings['border_border'] = 'solid'; $settings['border_color'] = $stroke; $settings['border_width'] = self::box( $node['strokeWeight'] ?? 1 ); } $radius = $node['cornerRadius'] ?? ( $node['rectangleCornerRadii'][0] ?? null ); if ( null !== $radius ) $settings['border_radius'] = self::box( $radius ); return $settings; }
	private static function color( array $paints ): ?string { foreach ( $paints as $paint ) { if ( is_array( $paint ) && 'SOLID' === ( $paint['type'] ?? '' ) && false !== ( $paint['visible'] ?? true ) && isset( $paint['color'] ) ) { $c = $paint['color']; return sprintf( '#%02x%02x%02x', round( 255 * $c['r'] ), round( 255 * $c['g'] ), round( 255 * $c['b'] ) ); } } return null; }
	private static function size( $value ): ?array { return null === $value ? null : array( 'unit' => 'px', 'size' => (float) $value, 'sizes' => array() ); }
	private static function box( $top, $right = null, $bottom = null, $left = null ): array { $right = $right ?? $top; $bottom = $bottom ?? $top; $left = $left ?? $right; return array( 'unit' => 'px', 'top' => (string) $top, 'right' => (string) $right, 'bottom' => (string) $bottom, 'left' => (string) $left, 'isLinked' => $top == $right && $right == $bottom && $bottom == $left ); }
	private static function id( string $value ): string { return substr( sha1( $value ), 0, 7 ); }
	private static function redirect( string $notice, array $args = array() ): void { wp_safe_redirect( add_query_arg( array_merge( array( 'elementor_mcp_notice' => $notice ), $args ), admin_url( 'admin.php?page=elementor-mcp-import' ) ) ); exit; }
}
