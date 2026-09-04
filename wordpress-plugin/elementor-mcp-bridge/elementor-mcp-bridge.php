<?php
/**
 * Plugin Name: Elementor MCP Bridge
 * Description: Secure REST bridge for the Elementor Figma MCP server.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Matthew Reilly
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-elementor-mcp-figma-connection.php';
	require_once __DIR__ . '/includes/class-elementor-mcp-layout-model.php';
	require_once __DIR__ . '/includes/class-elementor-mcp-figma-analyzer.php';
	require_once __DIR__ . '/includes/class-elementor-mcp-draft-importer.php';
	require_once __DIR__ . '/includes/class-elementor-mcp-admin.php';
}

final class Elementor_MCP_Bridge {
	private const NS = 'elementor-mcp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_head', array( __CLASS__, 'render_import_responsive_css' ), 99 );
	}

	/** Print only importer-generated, page-scoped responsive rules on imported drafts. */
	public static function render_import_responsive_css(): void {
		if ( is_admin() || ! is_singular( 'page' ) ) return;
		$css = get_post_meta( get_queried_object_id(), '_elementor_mcp_responsive_css', true );
		if ( ! is_string( $css ) || '' === $css || strlen( $css ) > 20000 ) return;
		$css = preg_replace( '/[^a-zA-Z0-9#._,:;{}()%!@\\-\\s]/', '', $css );
		if ( ! $css ) return;
		echo '<style id="elementor-mcp-responsive-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/health', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ) );
		register_rest_route( self::NS, '/globals', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'globals' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ) );
		register_rest_route( self::NS, '/globals/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_globals' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
		register_rest_route( self::NS, '/pages', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'pages' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_page' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ),
		) );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_page' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_page' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ),
		) );
		register_rest_route( self::NS, '/media/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_media' ), 'permission_callback' => array( __CLASS__, 'can_upload' ) ) );
		register_rest_route( self::NS, '/maintenance/regenerate-css', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'regenerate_css' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ) );
	}

	public static function can_edit(): bool { return current_user_can( 'edit_pages' ); }
	public static function can_upload(): bool { return current_user_can( 'upload_files' ) && current_user_can( 'edit_pages' ); }
	public static function can_manage(): bool { return current_user_can( 'manage_options' ); }

	private static function require_elementor() {
		if ( ! did_action( 'elementor/loaded' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
			return new WP_Error( 'elementor_missing', 'Elementor is not active.', array( 'status' => 503 ) );
		}
		return true;
	}

	public static function health() {
		$ready = self::require_elementor();
		return array(
			'ready' => ! is_wp_error( $ready ),
			'wordpress_version' => get_bloginfo( 'version' ),
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementor_pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'authenticated_as' => wp_get_current_user()->user_login,
		);
	}

	public static function globals() {
		$ready = self::require_elementor();
		if ( is_wp_error( $ready ) ) return $ready;
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		$colors = array_merge(
			self::global_setting( $kit->get_settings_for_display( 'system_colors' ) ),
			self::global_setting( $kit->get_settings_for_display( 'custom_colors' ) )
		);
		$typography = array_merge(
			self::global_setting( $kit->get_settings_for_display( 'system_typography' ) ),
			self::global_setting( $kit->get_settings_for_display( 'custom_typography' ) )
		);
		return array(
			'activeKit' => array( 'id' => $kit->get_id() ?: null, 'title' => $kit->get_main_post() ? get_the_title( $kit->get_main_post() ) : null ),
			'colors' => $colors,
			'typography' => $typography,
		);
	}

	private static function global_setting( $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	public static function import_globals( WP_REST_Request $request ) {
		$ready = self::require_elementor();
		if ( is_wp_error( $ready ) ) return $ready;
		if ( true !== $request->get_param( 'confirm' ) ) return new WP_Error( 'confirmation_required', 'Set confirm=true after approving the selected global styles.', array( 'status' => 400 ) );
		$colors = self::selected_colors( $request->get_param( 'colors' ) );
		if ( is_wp_error( $colors ) ) return $colors;
		$typography = self::selected_typography( $request->get_param( 'typography' ) );
		if ( is_wp_error( $typography ) ) return $typography;
		if ( ! $colors && ! $typography ) return new WP_Error( 'empty_selection', 'Select at least one global style to import.', array( 'status' => 400 ) );

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		$current_colors = self::global_setting( $kit->get_settings( 'custom_colors' ) );
		$current_typography = self::global_setting( $kit->get_settings( 'custom_typography' ) );
		$added_colors = self::append_colors( $current_colors, $colors );
		$added_typography = self::append_typography( $current_typography, $typography );
		if ( ! $added_colors && ! $added_typography ) return array( 'activeKit' => $kit->get_id(), 'added' => array( 'colors' => array(), 'typography' => array() ), 'unchanged' => true );

		wp_save_post_revision( $kit->get_id() );
		$kit->update_settings( array( 'custom_colors' => $current_colors, 'custom_typography' => $current_typography ) );
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		return array( 'activeKit' => $kit->get_id(), 'added' => array( 'colors' => $added_colors, 'typography' => $added_typography ), 'unchanged' => false );
	}

	private static function selected_colors( $items ) {
		if ( ! is_array( $items ) || count( $items ) > 8 ) return new WP_Error( 'invalid_colors', 'Colors must be an array of up to eight selected styles.', array( 'status' => 400 ) );
		$selected = array();
		foreach ( $items as $item ) {
			$name = is_array( $item ) ? sanitize_text_field( $item['name'] ?? '' ) : '';
			$value = is_array( $item ) ? strtolower( trim( (string) ( $item['value'] ?? '' ) ) ) : '';
			if ( ! $name || ! preg_match( '/^#(?:[0-9a-f]{3}){1,2}$/i', $value ) ) return new WP_Error( 'invalid_color', 'Each selected color needs a name and hexadecimal value.', array( 'status' => 400 ) );
			$selected[] = array( 'name' => $name, 'value' => $value );
		}
		return $selected;
	}

	private static function selected_typography( $items ) {
		if ( ! is_array( $items ) || count( $items ) > 5 ) return new WP_Error( 'invalid_typography', 'Typography must be an array of up to five selected styles.', array( 'status' => 400 ) );
		$selected = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) return new WP_Error( 'invalid_typography', 'Each typography selection must be an object.', array( 'status' => 400 ) );
			$name = sanitize_text_field( $item['name'] ?? '' );
			$font = sanitize_text_field( $item['fontFamily'] ?? '' );
			$weight = absint( $item['fontWeight'] ?? 400 );
			$size = (float) ( $item['fontSize'] ?? 0 );
			$line_height = (float) ( $item['lineHeightPx'] ?? 0 );
			$letter_spacing = (float) ( $item['letterSpacing'] ?? 0 );
			if ( ! $name || ! $font || $weight < 100 || $weight > 900 || $size <= 0 || $size > 500 || $line_height < 0 || $line_height > 1000 || $letter_spacing < -100 || $letter_spacing > 100 ) return new WP_Error( 'invalid_typography', 'Each typography style must have valid font, weight, size, line height, and letter spacing values.', array( 'status' => 400 ) );
			$selected[] = array( 'name' => $name, 'fontFamily' => $font, 'fontWeight' => $weight, 'fontSize' => $size, 'lineHeightPx' => $line_height, 'letterSpacing' => $letter_spacing );
		}
		return $selected;
	}

	private static function append_colors( array &$current, array $selected ): array {
		$added = array();
		foreach ( $selected as $color ) {
			$title = 'Figma / ' . $color['name'];
			$exists = array_filter( $current, static function ( $item ) use ( $title, $color ) { return ( $item['title'] ?? '' ) === $title && strtolower( $item['color'] ?? '' ) === $color['value']; } );
			if ( $exists ) continue;
			$item = array( '_id' => self::global_id( $current ), 'title' => $title, 'color' => $color['value'] );
			$current[] = $item;
			$added[] = $item;
		}
		return $added;
	}

	private static function append_typography( array &$current, array $selected ): array {
		$added = array();
		foreach ( $selected as $style ) {
			$title = 'Figma / ' . $style['name'];
			$exists = array_filter( $current, static function ( $item ) use ( $title, $style ) { return ( $item['title'] ?? '' ) === $title && ( $item['typography_font_family'] ?? '' ) === $style['fontFamily']; } );
			if ( $exists ) continue;
			$item = array(
				'_id' => self::global_id( $current ), 'title' => $title, 'typography_typography' => 'custom',
				'typography_font_family' => $style['fontFamily'], 'typography_font_weight' => (string) $style['fontWeight'],
				'typography_font_size' => array( 'unit' => 'px', 'size' => $style['fontSize'], 'sizes' => array() ),
				'typography_line_height' => array( 'unit' => 'px', 'size' => $style['lineHeightPx'], 'sizes' => array() ),
				'typography_letter_spacing' => array( 'unit' => 'px', 'size' => $style['letterSpacing'], 'sizes' => array() ),
			);
			$current[] = $item;
			$added[] = $item;
		}
		return $added;
	}

	private static function global_id( array $items ): string {
		$ids = array_column( $items, '_id' );
		do { $id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 7 ); } while ( in_array( $id, $ids, true ) );
		return $id;
	}

	public static function pages( WP_REST_Request $request ): array {
		$query = new WP_Query( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ), 's' => sanitize_text_field( $request->get_param( 'search' ) ?: '' ), 'posts_per_page' => 50, 'meta_key' => '_elementor_edit_mode', 'meta_value' => 'builder' ) );
		return array_map( array( __CLASS__, 'page_summary' ), $query->posts );
	}

	private static function page_summary( WP_Post $post ): array {
		return array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'url' => get_permalink( $post ), 'edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ), 'modified' => $post->post_modified_gmt );
	}

	public static function get_page( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post || 'page' !== $post->post_type ) return new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return new WP_Error( 'forbidden', 'You cannot edit this page.', array( 'status' => 403 ) );
		return array_merge( self::page_summary( $post ), array( 'content' => json_decode( get_post_meta( $post->ID, '_elementor_data', true ) ?: '[]', true ), 'page_settings' => json_decode( get_post_meta( $post->ID, '_elementor_page_settings', true ) ?: '{}', true ) ) );
	}

	private static function validate_content( $content ) {
		if ( ! is_array( $content ) ) return new WP_Error( 'invalid_content', 'Elementor content must be an array.', array( 'status' => 400 ) );
		if ( strlen( wp_json_encode( $content ) ) > 10 * MB_IN_BYTES ) return new WP_Error( 'too_large', 'Elementor document exceeds the 10 MB limit.', array( 'status' => 413 ) );
		return $content;
	}

	private static function save_elementor_data( int $post_id, array $content, string $template = '' ): void {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $content ) ) );
		if ( $template ) update_post_meta( $post_id, '_wp_page_template', sanitize_key( $template ) );
		clean_post_cache( $post_id );
	}

	public static function create_page( WP_REST_Request $request ) {
		$ready = self::require_elementor(); if ( is_wp_error( $ready ) ) return $ready;
		$content = self::validate_content( $request->get_param( 'content' ) ); if ( is_wp_error( $content ) ) return $content;
		$status = 'draft'; // This endpoint intentionally never publishes.
		$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => sanitize_text_field( $request->get_param( 'title' ) ?: 'Figma import' ), 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $post_id ) ) return $post_id;
		self::save_elementor_data( $post_id, $content, $request->get_param( 'template' ) ?: 'elementor_canvas' );
		return new WP_REST_Response( self::page_summary( get_post( $post_id ) ), 201 );
	}

	public static function update_page( WP_REST_Request $request ) {
		$ready = self::require_elementor(); if ( is_wp_error( $ready ) ) return $ready;
		$post_id = absint( $request['id'] ); $post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type ) return new WP_Error( 'not_found', 'Page not found.', array( 'status' => 404 ) );
		if ( ! current_user_can( 'edit_post', $post_id ) ) return new WP_Error( 'forbidden', 'You cannot edit this page.', array( 'status' => 403 ) );
		$content = self::validate_content( $request->get_param( 'content' ) ); if ( is_wp_error( $content ) ) return $content;
		wp_save_post_revision( $post_id );
		$title = $request->get_param( 'title' );
		if ( $title ) { $result = wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $title ) ), true ); if ( is_wp_error( $result ) ) return $result; }
		self::save_elementor_data( $post_id, $content );
		return self::page_summary( get_post( $post_id ) );
	}

	public static function import_media( WP_REST_Request $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( ! $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) return new WP_Error( 'invalid_url', 'A valid HTTPS image URL is required.', array( 'status' => 400 ) );
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 ); if ( is_wp_error( $tmp ) ) return $tmp;
		$path = wp_parse_url( $url, PHP_URL_PATH ); $name = sanitize_file_name( basename( $path ?: 'figma-image.png' ) ); if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) $name .= '.png';
		$file = array( 'name' => $name, 'tmp_name' => $tmp ); $id = media_handle_sideload( $file, 0 ); if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
		$alt = sanitize_text_field( $request->get_param( 'alt' ) ?: '' ); if ( $alt ) update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return new WP_REST_Response( array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) ), 201 );
	}

	public static function regenerate_css() {
		$ready = self::require_elementor(); if ( is_wp_error( $ready ) ) return $ready;
		\Elementor\Plugin::$instance->files_manager->clear_cache();
		return array( 'success' => true, 'message' => 'Elementor CSS and data cache cleared.' );
	}
}

Elementor_MCP_Bridge::init();

if ( is_admin() && class_exists( 'Elementor_MCP_Admin' ) ) {
	Elementor_MCP_Admin::init();
}

if ( is_admin() && class_exists( 'Elementor_MCP_Figma_Connection' ) ) {
	Elementor_MCP_Figma_Connection::init();
}

if ( is_admin() && class_exists( 'Elementor_MCP_Figma_Analyzer' ) ) {
	Elementor_MCP_Figma_Analyzer::init();
}

if ( is_admin() && class_exists( 'Elementor_MCP_Draft_Importer' ) ) {
	Elementor_MCP_Draft_Importer::init();
}
