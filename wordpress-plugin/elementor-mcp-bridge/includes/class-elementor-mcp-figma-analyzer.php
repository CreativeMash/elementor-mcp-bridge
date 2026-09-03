<?php
/**
 * Read-only Figma frame analysis for the native WordPress import workflow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_MCP_Figma_Analyzer {
	private const PREVIEW_PREFIX = 'elementor_mcp_figma_preview_';

	public static function init(): void {
		add_action( 'admin_post_elementor_mcp_analyze_figma', array( __CLASS__, 'analyze' ) );
	}

	public static function preview(): ?array {
		$preview = get_transient( self::PREVIEW_PREFIX . get_current_user_id() );
		return is_array( $preview ) ? $preview : null;
	}

	public static function analyze(): void {
		self::require_admin();
		check_admin_referer( 'elementor_mcp_analyze_figma' );
		$reference = self::parse_url( isset( $_POST['figma_url'] ) ? esc_url_raw( wp_unslash( $_POST['figma_url'] ) ) : '' );
		$token = Elementor_MCP_Figma_Connection::access_token();
		if ( ! $reference || ! $token ) {
			self::redirect( 'analysis-failed' );
		}

		$response = wp_remote_get(
			'https://api.figma.com/v1/files/' . rawurlencode( $reference['file_key'] ) . '/nodes?ids=' . rawurlencode( $reference['node_id'] ) . '&geometry=paths',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::redirect( 'analysis-failed' );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$node = is_array( $payload ) ? ( $payload['nodes'][ $reference['node_id'] ]['document'] ?? null ) : null;
		if ( ! is_array( $node ) ) {
			self::redirect( 'analysis-failed' );
		}

		set_transient( self::PREVIEW_PREFIX . get_current_user_id(), self::build_preview( $reference, $node ), 15 * MINUTE_IN_SECONDS );
		self::redirect( 'analysis-complete' );
	}

	private static function parse_url( string $url ): ?array {
		$parts = wp_parse_url( $url );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! preg_match( '/(^|\\.)figma\\.com\\z/', $host ) || empty( $parts['path'] ) || empty( $parts['query'] ) ) {
			return null;
		}
		if ( ! preg_match( '#^/(?:file|design)/([^/]+)#', $parts['path'], $matches ) ) {
			return null;
		}
		parse_str( $parts['query'], $query );
		$node_id = isset( $query['node-id'] ) ? str_replace( '-', ':', sanitize_text_field( (string) $query['node-id'] ) ) : '';
		if ( ! preg_match( '/\\A\\d+:\\d+\\z/', $node_id ) ) {
			return null;
		}
		return array( 'url' => $url, 'file_key' => sanitize_text_field( $matches[1] ), 'node_id' => $node_id );
	}

	private static function build_preview( array $reference, array $root ): array {
		$stats = array( 'containers' => 0, 'text' => 0, 'images' => 0, 'components' => 0, 'skipped' => 0, 'auto_layout' => 0 );
		$unsupported = array();
		$without_auto_layout = 0;
		$walk = static function ( array $node ) use ( &$walk, &$stats, &$unsupported, &$without_auto_layout ): void {
			if ( false === ( $node['visible'] ?? true ) ) {
				$stats['skipped']++;
				return;
			}
			$type = (string) ( $node['type'] ?? 'UNKNOWN' );
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			if ( 'TEXT' === $type ) {
				$stats['text']++;
			} elseif ( $children || in_array( $type, array( 'FRAME', 'GROUP', 'SECTION', 'COMPONENT', 'INSTANCE' ), true ) ) {
				$stats['containers']++;
				if ( in_array( $type, array( 'COMPONENT', 'INSTANCE' ), true ) ) {
					$stats['components']++;
				}
				if ( in_array( $node['layoutMode'] ?? 'NONE', array( 'HORIZONTAL', 'VERTICAL' ), true ) ) {
					$stats['auto_layout']++;
				} elseif ( $children ) {
					$without_auto_layout++;
				}
			} elseif ( in_array( $type, array( 'RECTANGLE', 'ELLIPSE', 'VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'POLYGON' ), true ) || self::has_image_fill( $node ) ) {
				$stats['images']++;
			} else {
				$stats['skipped']++;
				$unsupported[ $type ] = true;
			}
			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		$walk( $root );

		$warnings = array();
		if ( $without_auto_layout ) {
			$warnings[] = sprintf( _n( '%d container has no Auto Layout and will need a layout fallback.', '%d containers have no Auto Layout and will need layout fallbacks.', $without_auto_layout, 'elementor-mcp-bridge' ), $without_auto_layout );
		}
		if ( $unsupported ) {
			$warnings[] = sprintf( 'Unsupported Figma layer types: %s.', implode( ', ', array_slice( array_keys( $unsupported ), 0, 5 ) ) );
		}

		return array(
			'source' => array( 'url' => $reference['url'], 'file_key' => $reference['file_key'], 'node_id' => $reference['node_id'], 'name' => sanitize_text_field( (string) ( $root['name'] ?? 'Figma frame' ) ) ),
			'stats' => $stats,
			'warnings' => $warnings,
		);
	}

	private static function has_image_fill( array $node ): bool {
		foreach ( (array) ( $node['fills'] ?? array() ) as $fill ) {
			if ( is_array( $fill ) && 'IMAGE' === ( $fill['type'] ?? '' ) && false !== ( $fill['visible'] ?? true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Figma Import.', 'elementor-mcp-bridge' ), 403 );
		}
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'elementor_mcp_notice', rawurlencode( $notice ), admin_url( 'admin.php?page=elementor-mcp-import' ) ) );
		exit;
	}
}
