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
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( self::convert_node( $preview['document'] ) ) ) ) );
		update_post_meta( $id, '_wp_page_template', 'elementor_canvas' ); clean_post_cache( $id );
		self::redirect( 'draft-created', array( 'page' => $id ) );
	}

	private static function convert( array $node ): bool { return null !== self::convert_node( $node ); }
	private static function convert_node( array $node ): ?array {
		if ( false === ( $node['visible'] ?? true ) ) return null;
		if ( 'TEXT' === ( $node['type'] ?? '' ) ) {
			$style = (array) ( $node['style'] ?? array() ); $size = (float) ( $style['fontSize'] ?? 16 ); $heading = $size >= 24;
			return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'widget', 'widgetType' => $heading ? 'heading' : 'text-editor', 'settings' => array( $heading ? 'title' : 'editor' => $node['characters'] ?? '', 'typography_typography' => 'custom', 'typography_font_family' => $style['fontFamily'] ?? '', 'typography_font_weight' => (string) ( $style['fontWeight'] ?? 400 ), 'typography_font_size' => array( 'unit' => 'px', 'size' => $size, 'sizes' => array() ) ), 'elements' => array() );
		}
		$children = array(); foreach ( (array) ( $node['children'] ?? array() ) as $child ) { if ( is_array( $child ) && ( $element = self::convert_node( $child ) ) ) $children[] = $element; }
		return array( 'id' => self::id( $node['id'] ?? wp_generate_uuid4() ), 'elType' => 'container', 'isInner' => true, 'settings' => array( 'content_width' => 'full', 'flex_direction' => 'HORIZONTAL' === ( $node['layoutMode'] ?? '' ) ? 'row' : 'column', 'padding' => array( 'unit' => 'px', 'top' => (string) ( $node['paddingTop'] ?? 0 ), 'right' => (string) ( $node['paddingRight'] ?? 0 ), 'bottom' => (string) ( $node['paddingBottom'] ?? 0 ), 'left' => (string) ( $node['paddingLeft'] ?? 0 ), 'isLinked' => false ) ), 'elements' => $children );
	}
	private static function id( string $value ): string { return substr( sha1( $value ), 0, 7 ); }
	private static function redirect( string $notice, array $args = array() ): void { wp_safe_redirect( add_query_arg( array_merge( array( 'elementor_mcp_notice' => $notice ), $args ), admin_url( 'admin.php?page=elementor-mcp-import' ) ) ); exit; }
}
