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
		update_post_meta( $id, '_elementor_mcp_responsive_css', self::responsive_css( $preview['document'], (int) $id ) );
		update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); clean_post_cache( $id );
		self::redirect( 'draft-created', array( 'draft_id' => $id ) );
	}

	private static function convert( array $node, array $assets ): bool { return null !== self::convert_node( $node, null, true, $assets ); }
	/**
	 * Generate a conservative fallback for desktop-only Figma application shells.
	 * Exact tablet/mobile frames can later replace these rules with Figma-specific values.
	 */
	private static function responsive_css( array $document, int $post_id ): string {
		$shell = self::responsive_shell( $document );
		if ( ! $shell ) return '';
		$box = (array) ( $shell['absoluteBoundingBox'] ?? array() );
		$width = (float) ( $box['width'] ?? 0 ); $height = (float) ( $box['height'] ?? 0 );
		if ( $width < 640 || $height < 300 ) return '';
		$header = null; $sidebar = null; $content = null;
		foreach ( (array) ( $shell['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) || ! ( $child_box = $child['absoluteBoundingBox'] ?? null ) ) continue;
			$x = (float) ( $child_box['x'] ?? 0 ); $y = (float) ( $child_box['y'] ?? 0 ); $child_width = (float) ( $child_box['width'] ?? 0 ); $child_height = (float) ( $child_box['height'] ?? 0 );
			if ( ! $header && $y <= (float) ( $box['y'] ?? 0 ) + 12 && $child_width >= $width * 0.7 && $child_height <= 160 ) $header = $child;
			elseif ( ! $sidebar && $x <= (float) ( $box['x'] ?? 0 ) + 12 && $child_width <= $width * 0.33 && $child_height >= $height * 0.4 ) $sidebar = $child;
			elseif ( ! $content && $child_width >= $width * 0.4 ) $content = $child;
		}
		if ( ! $header || ! $sidebar || ! $content ) return '';
		$reset = array( $shell, $header, $sidebar, $content );
		foreach ( (array) ( $sidebar['children'] ?? array() ) as $child ) if ( is_array( $child ) && 'NONE' !== ( $child['layoutMode'] ?? 'NONE' ) ) $reset[] = $child;
		$spacers = array(); $rows = array(); $fixed_items = array();
		self::responsive_nodes( $shell, $spacers, $rows, $fixed_items );
		$selector = static function ( array $node ) use ( $post_id ): string { return '.elementor-' . $post_id . ' .elementor-element-' . self::id( (string) ( $node['id'] ?? '' ) ); };
		$selectors = static function ( array $nodes ) use ( $selector ): string { return implode( ',', array_map( $selector, $nodes ) ); };
		$css = '@media (max-width:1024px){' . $selectors( $reset ) . '{--position:relative!important;--width:100%!important;--min-height:initial!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;}' . $selector( $header ) . '{--flex-wrap:wrap!important;}' . ( $spacers ? $selectors( $spacers ) . '{display:none!important;}' : '' ) . '}';
		$css .= '@media (max-width:767px){' . ( $rows ? $selectors( $rows ) . '{--flex-direction:column!important;--flex-wrap:nowrap!important;}' : '' ) . ( $fixed_items ? $selectors( $fixed_items ) . '{--flex-shrink:0!important;flex-shrink:0!important;}' : '' ) . '}';
		return $css;
	}
	private static function responsive_shell( array $node ): ?array { $box = (array) ( $node['absoluteBoundingBox'] ?? array() ); if ( ! empty( $node['children'] ) && (float) ( $box['width'] ?? 0 ) >= 640 && (float) ( $box['height'] ?? 0 ) >= 300 ) return $node; foreach ( (array) ( $node['children'] ?? array() ) as $child ) if ( is_array( $child ) && ( $shell = self::responsive_shell( $child ) ) ) return $shell; return null; }
	private static function responsive_nodes( array $node, array &$spacers, array &$rows, array &$fixed_items ): void { $children = (array) ( $node['children'] ?? array() ); $name = (string) ( $node['name'] ?? '' ); if ( ! $children && preg_match( '/\\bspacer\\b/i', $name ) ) $spacers[] = $node; if ( preg_match( '/(?:grid|tiles|cards)/i', $name ) ) foreach ( $children as $child ) if ( is_array( $child ) && 'HORIZONTAL' === ( $child['layoutMode'] ?? '' ) && count( (array) ( $child['children'] ?? array() ) ) > 1 ) $rows[] = $child; $box = (array) ( $node['absoluteBoundingBox'] ?? array() ); if ( 'INSTANCE' === ( $node['type'] ?? '' ) && (float) ( $box['width'] ?? 0 ) <= 64 && (float) ( $box['height'] ?? 0 ) <= 64 ) $fixed_items[] = $node; foreach ( $children as $child ) if ( is_array( $child ) ) self::responsive_nodes( $child, $spacers, $rows, $fixed_items ); }
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
			$content = $heading ? array( 'title' => $node['characters'] ?? '', 'title_color' => self::color( $node['fills'] ?? array() ) ) : array( 'editor' => $node['characters'] ?? '', 'text_color' => self::color( $node['fills'] ?? array() ) );
			return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => $heading ? 'heading' : 'text-editor', 'settings' => array_merge( self::navigator_title( $node ), self::visual( $node ), $positioning, $content, array( 'align' => self::text_alignment( $style['textAlignHorizontal'] ?? 'LEFT' ), 'typography_typography' => 'custom', 'typography_font_family' => $style['fontFamily'] ?? '', 'typography_font_weight' => (string) ( $style['fontWeight'] ?? 400 ), 'typography_font_size' => self::size( $size ), 'typography_line_height' => self::size( $style['lineHeightPx'] ?? null ), 'typography_letter_spacing' => self::size( $style['letterSpacing'] ?? null ) ) ), 'elements' => array() );
		}
		if ( self::button_parts( $node ) ) {
			return self::button_widget( $node, $parent, $is_root, $assets );
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
		return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'container', 'isInner' => true, 'settings' => array_merge( self::navigator_title( $node ), self::visual( $node ), $positioning, array( 'content_width' => 'full', 'flex_direction' => 'HORIZONTAL' === $layout_mode ? 'row' : 'column', 'flex_justify_content' => $justify[ $node['primaryAxisAlignItems'] ?? '' ] ?? 'flex-start', 'flex_align_items' => $align[ $node['counterAxisAlignItems'] ?? '' ] ?? 'stretch', 'flex_gap' => isset( $node['itemSpacing'] ) ? array( 'column' => (string) $node['itemSpacing'], 'row' => (string) $node['itemSpacing'], 'isLinked' => true, 'unit' => 'px', 'size' => (float) $node['itemSpacing'] ) : null, 'padding' => self::box( $node['paddingTop'] ?? 0, $node['paddingRight'] ?? 0, $node['paddingBottom'] ?? 0, $node['paddingLeft'] ?? 0 ), 'width' => $width, 'min_height' => $height ), $native_alignment ), 'elements' => $children );
	}
	/** Use a native Button only when its label and optional icon are unambiguous. */
	private static function button_parts( array $node ): ?array {
		$recognition = Elementor_MCP_Layout_Model::recognition_for( $node );
		if ( 'button' !== ( $recognition['type'] ?? '' ) || (float) ( $recognition['confidence'] ?? 0 ) < 0.9 ) {
			return null;
		}
		$text = null;
		$icon = null;
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) || false === ( $child['visible'] ?? true ) ) {
				continue;
			}
			if ( 'TEXT' === ( $child['type'] ?? '' ) && ! $text ) {
				$text = $child;
				continue;
			}
			if ( self::is_vector_node( $child ) && ! $icon ) {
				$icon = $child;
				continue;
			}
			return null;
		}
		return $text && '' !== trim( (string) ( $text['characters'] ?? '' ) ) ? array( 'text' => $text, 'icon' => $icon ) : null;
	}

	private static function button_widget( array $node, ?array $parent, bool $is_root, array $assets ): array {
		$parts = self::button_parts( $node );
		$label = (array) $parts['text'];
		$style = (array) ( $label['style'] ?? array() );
		$text = sanitize_text_field( (string) ( $label['characters'] ?? '' ) );
		$icon = self::button_icon( $parts['icon'], $assets, (float) ( $node['itemSpacing'] ?? 0 ) );
		if ( ! $icon && preg_match( '/^\+\s+(.+)$/u', $text, $matches ) ) {
			$text = sanitize_text_field( $matches[1] );
			$icon = array( 'selected_icon' => array( 'value' => 'fas fa-plus', 'library' => 'fa-solid' ), 'icon_align' => 'row', 'icon_indent' => self::size( 6 ) );
		}
		return array(
			'id'         => self::id( $node['id'] ?? wp_generate_uuid4() ),
			'elType'     => 'widget',
			'widgetType' => 'button',
			'settings'   => array_merge(
				self::navigator_title( $node ),
				self::positioning( $node, $parent, $is_root, true ),
				array(
					'text'                      => $text,
					'align'                     => self::text_alignment( (string) ( $style['textAlignHorizontal'] ?? 'CENTER' ) ),
					'button_text_color'         => self::color( (array) ( $label['fills'] ?? array() ) ),
					'background_background'     => 'classic',
					'background_color'          => self::color( (array) ( $node['fills'] ?? array() ) ),
					'border_radius'             => self::box( $node['cornerRadius'] ?? ( $node['rectangleCornerRadii'][0] ?? 0 ) ),
					'text_padding'              => self::box( $node['paddingTop'] ?? 0, $node['paddingRight'] ?? 0, $node['paddingBottom'] ?? 0, $node['paddingLeft'] ?? 0 ),
					'typography_typography'     => 'custom',
					'typography_font_family'    => $style['fontFamily'] ?? '',
					'typography_font_weight'    => (string) ( $style['fontWeight'] ?? 400 ),
					'typography_font_size'      => self::size( $style['fontSize'] ?? null ),
					'typography_line_height'    => self::size( $style['lineHeightPx'] ?? null ),
					'typography_letter_spacing' => self::size( $style['letterSpacing'] ?? null ),
				),
				$icon
			),
			'elements'   => array(),
		);
	}

	private static function button_icon( ?array $icon, array $assets, float $gap ): array {
		if ( ! $icon || empty( $icon['id'] ) || empty( $assets[ (string) $icon['id'] ] ) ) {
			return array();
		}
		$attachment_id = (int) $assets[ (string) $icon['id'] ];
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return array();
		}
		return array( 'selected_icon' => array( 'value' => array( 'id' => $attachment_id, 'url' => $url ), 'library' => 'svg' ), 'icon_align' => 'row', 'icon_indent' => self::size( max( 0, $gap ) ) );
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
	private static function image_widget( array $node, ?array $parent, bool $is_root, int $attachment_id ): array { return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array_merge( self::navigator_title( $node ), self::positioning( $node, $parent, $is_root, true ), array( 'image' => array( 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ) ), 'image_size' => 'full' ) ), 'elements' => array() ); }
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
	private static function visual( array $node ): array { $settings = array(); if ( $fill = self::color( $node['fills'] ?? array() ) ) { $settings['background_background'] = 'classic'; $settings['background_color'] = $fill; } if ( $stroke = self::color( $node['strokes'] ?? array() ) ) { $settings['border_border'] = ! empty( $node['strokeDashes'] ) ? 'dashed' : 'solid'; $settings['border_color'] = $stroke; $settings['border_width'] = self::box( $node['strokeWeight'] ?? 1 ); } $radius = $node['cornerRadius'] ?? ( $node['rectangleCornerRadii'][0] ?? null ); if ( null !== $radius ) $settings['border_radius'] = self::box( $radius ); if ( $shadow = self::shadow( $node ) ) { $settings['box_shadow_box_shadow_type'] = 'yes'; $settings['box_shadow_box_shadow'] = $shadow; } return $settings; }
	/** Keep the Elementor Navigator aligned with the source Figma layer tree. */
	private static function navigator_title( array $node ): array { $name = sanitize_text_field( wp_strip_all_tags( (string) ( $node['name'] ?? '' ) ) ); return '' === $name ? array() : array( '_title' => substr( $name, 0, 120 ) ); }
	/** Map the first visible Figma drop shadow to Elementor's native shadow control. */
	private static function shadow( array $node ): ?array { foreach ( (array) ( $node['effects'] ?? array() ) as $effect ) { if ( ! is_array( $effect ) || false === ( $effect['visible'] ?? true ) || 'DROP_SHADOW' !== ( $effect['type'] ?? '' ) ) continue; $offset = (array) ( $effect['offset'] ?? array() ); return array( 'horizontal' => (float) ( $offset['x'] ?? 0 ), 'vertical' => (float) ( $offset['y'] ?? 4 ), 'blur' => (float) ( $effect['radius'] ?? 12 ), 'spread' => (float) ( $effect['spread'] ?? 0 ), 'color' => self::effect_color( (array) ( $effect['color'] ?? array() ) ) ?? 'rgba(0, 0, 0, 0.15)' ); } return null; }
	private static function effect_color( array $color ): ?string { if ( ! isset( $color['r'], $color['g'], $color['b'] ) ) return null; $red = max( 0, min( 255, (int) round( 255 * (float) $color['r'] ) ) ); $green = max( 0, min( 255, (int) round( 255 * (float) $color['g'] ) ) ); $blue = max( 0, min( 255, (int) round( 255 * (float) $color['b'] ) ) ); $opacity = max( 0, min( 1, (float) ( $color['a'] ?? 1 ) ) ); return 1 > $opacity ? sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, rtrim( rtrim( sprintf( '%.3F', $opacity ), '0' ), '.' ) ) : sprintf( '#%02x%02x%02x', $red, $green, $blue ); }
	private static function text_alignment( string $value ): string { $alignments = array( 'LEFT' => 'left', 'CENTER' => 'center', 'RIGHT' => 'right', 'JUSTIFIED' => 'justify' ); return $alignments[ $value ] ?? 'left'; }
	private static function color( array $paints ): ?string { foreach ( $paints as $paint ) { if ( is_array( $paint ) && 'SOLID' === ( $paint['type'] ?? '' ) && false !== ( $paint['visible'] ?? true ) && isset( $paint['color'] ) ) { $c = $paint['color']; $red = max( 0, min( 255, (int) round( 255 * (float) ( $c['r'] ?? 0 ) ) ) ); $green = max( 0, min( 255, (int) round( 255 * (float) ( $c['g'] ?? 0 ) ) ) ); $blue = max( 0, min( 255, (int) round( 255 * (float) ( $c['b'] ?? 0 ) ) ) ); $opacity = max( 0, min( 1, (float) ( $paint['opacity'] ?? $c['a'] ?? 1 ) ) ); if ( 1 > $opacity ) return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, rtrim( rtrim( sprintf( '%.3F', $opacity ), '0' ), '.' ) ); return sprintf( '#%02x%02x%02x', $red, $green, $blue ); } } return null; }
	private static function size( $value ): ?array { return null === $value ? null : array( 'unit' => 'px', 'size' => (float) $value, 'sizes' => array() ); }
	private static function box( $top, $right = null, $bottom = null, $left = null ): array { $right = $right ?? $top; $bottom = $bottom ?? $top; $left = $left ?? $right; return array( 'unit' => 'px', 'top' => (string) $top, 'right' => (string) $right, 'bottom' => (string) $bottom, 'left' => (string) $left, 'isLinked' => $top == $right && $right == $bottom && $bottom == $left ); }
	private static function id( string $value ): string { return substr( sha1( $value ), 0, 7 ); }
	private static function redirect( string $notice, array $args = array() ): void { wp_safe_redirect( add_query_arg( array_merge( array( 'elementor_mcp_notice' => $notice ), $args ), admin_url( 'admin.php?page=elementor-mcp-import' ) ) ); exit; }
}
