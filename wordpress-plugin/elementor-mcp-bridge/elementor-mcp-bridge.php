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

final class Elementor_MCP_Bridge {
	private const NS = 'elementor-mcp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/health', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ) );
		register_rest_route( self::NS, '/globals', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'globals' ), 'permission_callback' => array( __CLASS__, 'can_edit' ) ) );
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
