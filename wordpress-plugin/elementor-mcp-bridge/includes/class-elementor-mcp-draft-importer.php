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
		$title = sanitize_text_field( $_POST['draft_title'] ?? ( $preview['source']['name'] ?? 'Figma import' ) );
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title ?: 'Figma import', 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $id ) ) { self::redirect( 'draft-failed' ); }
		$assets = self::import_assets( $preview, (int) $id );
		$content = self::convert( $preview['document'], $assets );
		if ( ! $content ) { self::redirect( 'draft-failed' ); }
		update_post_meta( $id, '_elementor_edit_mode', 'builder' ); update_post_meta( $id, '_elementor_template_type', 'wp-page' ); update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( self::convert_node( $preview['document'], null, true, $assets ) ) ) ) );
		update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); clean_post_cache( $id );
		self::redirect( 'draft-created', array( 'draft_id' => $id ) );
	}

	private static function convert( array $node, array $assets ): bool { return null !== self::convert_node( $node, null, true, $assets ); }
	/**
	 * Auto Layout maps to Elementor flexbox. A Figma parent without Auto Layout
	 * retains its desktop coordinates using Elementor's native absolute controls.
	 */
	private static function convert_node( array $node, ?array $parent = null, bool $is_root = false, array $assets = array() ): ?array {
		if ( false === ( $node['visible'] ?? true ) ) return null;
		$layout_mode = $node['layoutMode'] ?? 'NONE';
		$positioning = self::positioning( $node, $parent, $is_root, 'TEXT' === ( $node['type'] ?? '' ) );
		$node_id = (string) ( $node['id'] ?? '' );
		if ( isset( $assets[ $node_id ] ) && self::is_asset_node( $node ) ) return self::image_widget( $node, $parent, $is_root, (int) $assets[ $node_id ] );
		if ( 'TEXT' === ( $node['type'] ?? '' ) ) {
			$style = (array) ( $node['style'] ?? array() ); $size = (float) ( $style['fontSize'] ?? 16 ); $heading = $size >= 24;
			return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => $heading ? 'heading' : 'text-editor', 'settings' => array_merge( self::visual( $node ), $positioning, array( $heading ? 'title' : 'editor' => $node['characters'] ?? '', 'text_color' => self::color( $node['fills'] ?? array() ), 'typography_typography' => 'custom', 'typography_font_family' => $style['fontFamily'] ?? '', 'typography_font_weight' => (string) ( $style['fontWeight'] ?? 400 ), 'typography_font_size' => self::size( $size ), 'typography_line_height' => self::size( $style['lineHeightPx'] ?? null ), 'typography_letter_spacing' => self::size( $style['letterSpacing'] ?? null ) ) ), 'elements' => array() );
		}
		$children = array(); foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) && ( $element = self::convert_node( $child, $node, false, $assets ) ) ) $children[] = $element; }
		$justify = array( 'MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'SPACE_BETWEEN' => 'space-between' ); $align = array( 'MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'BASELINE' => 'baseline' );
		$horizontal_size = $node['layoutSizingHorizontal'] ?? '';
		$width = ( 1 === (int) ( $node['layoutGrow'] ?? 0 ) || 'FILL' === $horizontal_size ) ? array( 'unit' => '%', 'size' => 100, 'sizes' => array() ) : ( 'FIXED' === $horizontal_size ? self::size( $node['absoluteBoundingBox']['width'] ?? null ) : null );
		if ( isset( $positioning['width'] ) ) $width = $positioning['width'];
		$height = 'FIXED' === ( $node['layoutSizingVertical'] ?? '' ) || 'NONE' === $layout_mode ? self::size( $node['absoluteBoundingBox']['height'] ?? null ) : null;
		if ( isset( $positioning['min_height'] ) ) $height = $positioning['min_height'];
		$space_between = 'HORIZONTAL' === $layout_mode && 'SPACE_BETWEEN' === ( $node['primaryAxisAlignItems'] ?? '' );
		if ( $space_between && ! $width ) $width = self::size( $node['absoluteBoundingBox']['width'] ?? null );
		$native_alignment = $space_between ? array( 'direction' => 'row', 'justify_content' => 'space-between' ) : array();
		return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'container', 'isInner' => true, 'settings' => array_merge( self::visual( $node ), $positioning, array( 'content_width' => 'full', 'flex_direction' => 'HORIZONTAL' === $layout_mode ? 'row' : 'column', 'flex_justify_content' => $justify[ $node['primaryAxisAlignItems'] ?? '' ] ?? 'flex-start', 'flex_align_items' => $align[ $node['counterAxisAlignItems'] ?? '' ] ?? 'stretch', 'flex_gap' => isset( $node['itemSpacing'] ) ? array( 'column' => (string) $node['itemSpacing'], 'row' => (string) $node['itemSpacing'], 'isLinked' => true, 'unit' => 'px', 'size' => (float) $node['itemSpacing'] ) : null, 'padding' => self::box( $node['paddingTop'] ?? 0, $node['paddingRight'] ?? 0, $node['paddingBottom'] ?? 0, $node['paddingLeft'] ?? 0 ), 'width' => $width, 'min_height' => $height ), $native_alignment ), 'elements' => $children );
	}
	private static function positioning( array $node, ?array $parent, bool $is_root, bool $is_widget = false ): array {
		if ( $is_root || ! $parent ) return array();
		$box = $node['absoluteBoundingBox'] ?? array();
		if ( ! isset( $box['width'], $box['height'] ) ) return array();
		$parent_layout = $parent['layoutMode'] ?? 'NONE';
		$parent_space_between = 'HORIZONTAL' === $parent_layout && 'SPACE_BETWEEN' === ( $parent['primaryAxisAlignItems'] ?? '' );
		if ( 'NONE' !== $parent_layout ) {
			// Space-between needs real endpoint widths; Elementor otherwise lets both children grow.
			if ( ! $parent_space_between ) return array();
			if ( $is_widget ) return array( '_element_width' => 'initial', '_element_custom_width' => self::size( $box['width'] ) );
			return array( 'width' => self::size( $box['width'] ) );
		}
		$parent_box = $parent['absoluteBoundingBox'] ?? array();
		if ( ! isset( $box['x'], $box['y'], $parent_box['x'], $parent_box['y'] ) ) return array();
		$x = (float) $box['x'] - (float) $parent_box['x']; $y = (float) $box['y'] - (float) $parent_box['y'];
		if ( $is_widget ) return array( '_position' => 'absolute', '_offset_orientation_h' => 'start', '_offset_x' => self::size( $x ), '_offset_orientation_v' => 'start', '_offset_y' => self::size( $y ), '_element_width' => 'initial', '_element_custom_width' => self::size( $box['width'] ) );
		return array( 'position' => 'absolute', '_offset_orientation_h' => 'start', '_offset_x' => self::size( $x ), '_offset_orientation_v' => 'start', '_offset_y' => self::size( $y ), 'width' => self::size( $box['width'] ), 'min_height' => self::size( $box['height'] ) );
	}
	private static function is_vector_node( array $node ): bool { return in_array( $node['type'] ?? '', array( 'VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'POLYGON' ), true ); }
	private static function is_asset_node( array $node ): bool { if ( self::is_vector_node( $node ) ) return true; foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) if ( is_array( $fill ) && 'IMAGE' === ( $fill['type'] ?? '' ) && false !== ( $fill['visible'] ?? true ) ) return true; return false; }
	private static function image_widget( array $node, ?array $parent, bool $is_root, int $attachment_id ): array { return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array_merge( self::positioning( $node, $parent, $is_root, true ), array( 'image' => array( 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ) ), 'image_size' => 'full' ) ), 'elements' => array() ); }
	private static function import_assets( array $preview, int $post_id ): array {
		if ( ! current_user_can( 'upload_files' ) || empty( $preview['source']['file_key'] ) || ! ( $token = Elementor_MCP_Figma_Connection::access_token() ) ) return array();
		$nodes = array(); self::collect_assets( $preview['document'] ?? array(), $nodes ); if ( ! $nodes ) return array();
		$svg = array(); $png = array(); foreach ( $nodes as $id => $asset ) { if ( 'svg' === $asset['format'] ) $svg[] = $id; else $png[] = $id; }
		$attachments = array(); $fallback = self::import_asset_group( $svg, 'svg', $preview['source']['file_key'], $token, $nodes, $post_id, $attachments );
		self::import_asset_group( array_merge( $png, $fallback ), 'png', $preview['source']['file_key'], $token, $nodes, $post_id, $attachments );
		return $attachments;
	}
	private static function import_asset_group( array $ids, string $format, string $file_key, string $token, array $nodes, int $post_id, array &$attachments ): array { $fallback = array(); foreach ( array_chunk( $ids, 50 ) as $chunk ) { $response = wp_remote_get( add_query_arg( array( 'ids' => implode( ',', $chunk ), 'format' => $format, 'scale' => 1, 'svg_outline_text' => 'true' ), 'https://api.figma.com/v1/images/' . rawurlencode( $file_key ) ), array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => true, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) ); $payload = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : array(); foreach ( $chunk as $figma_id ) { $url = $payload['images'][ $figma_id ] ?? ''; $attachment = is_string( $url ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? self::sideload_asset( $url, $file_key, (string) $figma_id, $nodes[ $figma_id ]['name'] ?? 'Figma asset', $format, $post_id ) : 0; if ( $attachment ) $attachments[ $figma_id ] = $attachment; elseif ( 'svg' === $format ) $fallback[] = $figma_id; } } return $fallback; }
	private static function collect_assets( array $node, array &$assets ): void { if ( false === ( $node['visible'] ?? true ) ) return; if ( self::is_asset_node( $node ) && ! empty( $node['id'] ) ) $assets[ (string) $node['id'] ] = array( 'name' => sanitize_text_field( (string) ( $node['name'] ?? 'Figma asset' ) ), 'format' => self::is_vector_node( $node ) ? 'svg' : 'png' ); foreach ( (array) ( $node['children'] ?? array() ) as $child ) if ( is_array( $child ) ) self::collect_assets( $child, $assets ); }
	public static function allow_sanitized_svg( array $mimes ): array { $mimes['svg'] = 'image/svg+xml'; return $mimes; }
	private static function sideload_asset( string $url, string $file_key, string $figma_id, string $name, string $format, int $post_id ): int { $source = sanitize_text_field( $file_key . ':' . $figma_id . ':' . $format ); $existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_elementor_mcp_figma_asset', 'meta_value' => $source ) ); if ( $existing ) return (int) $existing[0]; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; $temporary = download_url( $url, 30 ); if ( is_wp_error( $temporary ) ) return 0; $svg = 'svg' === $format; if ( $svg && ! self::sanitize_svg( $temporary ) ) { @unlink( $temporary ); return 0; } if ( $svg ) add_filter( 'upload_mimes', array( __CLASS__, 'allow_sanitized_svg' ) ); $attachment = media_handle_sideload( array( 'name' => sanitize_file_name( $name ?: 'figma-asset' ) . '.' . $format, 'tmp_name' => $temporary ), $post_id ); if ( $svg ) remove_filter( 'upload_mimes', array( __CLASS__, 'allow_sanitized_svg' ) ); if ( is_wp_error( $attachment ) ) { @unlink( $temporary ); return 0; } update_post_meta( $attachment, '_elementor_mcp_figma_asset', $source ); update_post_meta( $attachment, '_wp_attachment_image_alt', $name ); return (int) $attachment; }
	private static function sanitize_svg( string $path ): bool { if ( ! class_exists( 'DOMDocument' ) || ! is_readable( $path ) || filesize( $path ) > MB_IN_BYTES ) return false; $svg = file_get_contents( $path ); if ( ! is_string( $svg ) || ! $svg || preg_match( '/<!|<\\?/i', $svg ) ) return false; $previous = libxml_use_internal_errors( true ); $document = new DOMDocument(); $document->resolveExternals = false; $document->substituteEntities = false; $loaded = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ); libxml_clear_errors(); libxml_use_internal_errors( $previous ); if ( ! $loaded || ! $document->documentElement || 'svg' !== strtolower( $document->documentElement->localName ) ) return false; $count = 0; if ( ! self::sanitize_svg_node( $document->documentElement, $count ) || $count > 5000 ) return false; $clean = $document->saveXML( $document->documentElement ); return is_string( $clean ) && '' !== $clean && false !== file_put_contents( $path, $clean, LOCK_EX ); }
	private static function sanitize_svg_node( DOMElement $node, int &$count ): bool { $elements = array_flip( array( 'svg', 'g', 'defs', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop', 'use', 'symbol', 'title', 'desc', 'filter', 'feGaussianBlur', 'feOffset', 'feColorMatrix', 'feBlend', 'feComposite', 'feFlood', 'feMerge', 'feMergeNode', 'feDropShadow' ) ); $attributes = array_flip( array( 'id', 'class', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'fill-opacity', 'stroke-opacity', 'transform', 'd', 'points', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewBox', 'preserveAspectRatio', 'xmlns', 'xmlns:xlink', 'gradientUnits', 'gradientTransform', 'offset', 'stop-color', 'stop-opacity', 'clip-path', 'mask', 'href', 'xlink:href', 'version', 'filterUnits', 'primitiveUnits', 'stdDeviation', 'dx', 'dy', 'result', 'in', 'in2', 'mode', 'operator', 'flood-color', 'flood-opacity' ) ); if ( ! isset( $elements[ $node->tagName ] ) || ++$count > 5000 ) return false; for ( $i = $node->attributes->length - 1; $i >= 0; $i-- ) { $attribute = $node->attributes->item( $i ); $name = $attribute->nodeName; $value = trim( $attribute->nodeValue ); if ( ! isset( $attributes[ $name ] ) || preg_match( '/(?:javascript:|data:|https?:|file:|\\\\|\\x00)/i', $value ) || ( false !== stripos( $value, 'url(' ) && ! preg_match( '/^url\\(\\s*#[A-Za-z0-9_.:-]+\\s*\\)$/', $value ) ) || ( in_array( $name, array( 'href', 'xlink:href' ), true ) && ! preg_match( '/^#[A-Za-z0-9_.:-]+$/', $value ) ) ) return false; } for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) { $child = $node->childNodes->item( $i ); if ( XML_ELEMENT_NODE === $child->nodeType ) { if ( ! self::sanitize_svg_node( $child, $count ) ) return false; } elseif ( XML_TEXT_NODE !== $child->nodeType || ! in_array( $node->tagName, array( 'title', 'desc' ), true ) ) { $node->removeChild( $child ); } } return true; }
	private static function visual( array $node ): array { $settings = array(); if ( $fill = self::color( $node['fills'] ?? array() ) ) { $settings['background_background'] = 'classic'; $settings['background_color'] = $fill; } if ( $stroke = self::color( $node['strokes'] ?? array() ) ) { $settings['border_border'] = ! empty( $node['strokeDashes'] ) ? 'dashed' : 'solid'; $settings['border_color'] = $stroke; $settings['border_width'] = self::box( $node['strokeWeight'] ?? 1 ); } $radius = $node['cornerRadius'] ?? ( $node['rectangleCornerRadii'][0] ?? null ); if ( null !== $radius ) $settings['border_radius'] = self::box( $radius ); return $settings; }
	private static function color( array $paints ): ?string { foreach ( $paints as $paint ) { if ( is_array( $paint ) && 'SOLID' === ( $paint['type'] ?? '' ) && false !== ( $paint['visible'] ?? true ) && isset( $paint['color'] ) ) { $c = $paint['color']; $red = max( 0, min( 255, (int) round( 255 * (float) ( $c['r'] ?? 0 ) ) ) ); $green = max( 0, min( 255, (int) round( 255 * (float) ( $c['g'] ?? 0 ) ) ) ); $blue = max( 0, min( 255, (int) round( 255 * (float) ( $c['b'] ?? 0 ) ) ) ); $opacity = max( 0, min( 1, (float) ( $paint['opacity'] ?? $c['a'] ?? 1 ) ) ); if ( 1 > $opacity ) return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, rtrim( rtrim( sprintf( '%.3F', $opacity ), '0' ), '.' ) ); return sprintf( '#%02x%02x%02x', $red, $green, $blue ); } } return null; }
	private static function size( $value ): ?array { return null === $value ? null : array( 'unit' => 'px', 'size' => (float) $value, 'sizes' => array() ); }
	private static function box( $top, $right = null, $bottom = null, $left = null ): array { $right = $right ?? $top; $bottom = $bottom ?? $top; $left = $left ?? $right; return array( 'unit' => 'px', 'top' => (string) $top, 'right' => (string) $right, 'bottom' => (string) $bottom, 'left' => (string) $left, 'isLinked' => $top == $right && $right == $bottom && $bottom == $left ); }
	private static function id( string $value ): string { return substr( sha1( $value ), 0, 7 ); }
	private static function redirect( string $notice, array $args = array() ): void { wp_safe_redirect( add_query_arg( array_merge( array( 'elementor_mcp_notice' => $notice ), $args ), admin_url( 'admin.php?page=elementor-mcp-import' ) ) ); exit; }
}
