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
		$stats = array( 'containers' => 0, 'text' => 0, 'images' => 0, 'components' => 0, 'variable_bindings' => 0, 'skipped' => 0, 'auto_layout' => 0 );
		$unsupported = array();
		$colors = array();
		$typography = array();
		$components = array();
		$without_auto_layout = 0;
		$walk = static function ( array $node ) use ( &$walk, &$stats, &$unsupported, &$colors, &$typography, &$components, &$without_auto_layout ): void {
			if ( false === ( $node['visible'] ?? true ) ) {
				$stats['skipped']++;
				return;
			}
			$type = (string) ( $node['type'] ?? 'UNKNOWN' );
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
			$stats['variable_bindings'] += count( (array) ( $node['boundVariables'] ?? array() ) );
			foreach ( array_merge( (array) ( $node['fills'] ?? array() ), (array) ( $node['strokes'] ?? array() ) ) as $paint ) {
				$color = is_array( $paint ) ? self::paint_color( $paint ) : null;
				if ( $color ) {
					$colors[ $color ] = ( $colors[ $color ] ?? 0 ) + 1;
				}
			}
			if ( 'TEXT' === $type ) {
				$stats['text']++;
				$style = is_array( $node['style'] ?? null ) ? $node['style'] : array();
				if ( ! empty( $style['fontFamily'] ) ) {
					$key = implode( '|', array( $style['fontFamily'], $style['fontWeight'] ?? '', $style['fontSize'] ?? '' ) );
					$typography[ $key ] = array( 'font' => sanitize_text_field( (string) $style['fontFamily'] ), 'weight' => absint( $style['fontWeight'] ?? 400 ), 'size' => (float) ( $style['fontSize'] ?? 0 ), 'occurrences' => ( $typography[ $key ]['occurrences'] ?? 0 ) + 1 );
				}
			} elseif ( $children || in_array( $type, array( 'FRAME', 'GROUP', 'SECTION', 'COMPONENT', 'INSTANCE' ), true ) ) {
				$stats['containers']++;
				if ( in_array( $type, array( 'COMPONENT', 'INSTANCE' ), true ) ) {
					$stats['components']++;
					$key = $type . '|' . (string) ( $node['name'] ?? $type );
					$components[ $key ] = array( 'name' => sanitize_text_field( (string) ( $node['name'] ?? $type ) ), 'type' => $type, 'occurrences' => ( $components[ $key ]['occurrences'] ?? 0 ) + 1 );
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
		arsort( $colors );
		usort( $typography, static function ( array $left, array $right ): int { return $right['occurrences'] <=> $left['occurrences']; } );
		usort( $components, static function ( array $left, array $right ): int { return $right['occurrences'] <=> $left['occurrences']; } );

		return array(
			'source' => array( 'url' => $reference['url'], 'file_key' => $reference['file_key'], 'node_id' => $reference['node_id'], 'name' => sanitize_text_field( (string) ( $root['name'] ?? 'Figma frame' ) ) ),
			'document' => $root,
			'stats' => $stats,
			'layout_model' => Elementor_MCP_Layout_Model::summary( $root ),
			'styles' => array( 'colors' => array_slice( array_map( static function ( $value, $occurrences ): array { return array( 'value' => $value, 'occurrences' => $occurrences ); }, array_keys( $colors ), array_values( $colors ) ), 0, 8 ), 'typography' => array_slice( $typography, 0, 6 ), 'components' => array_slice( $components, 0, 6 ) ),
			'warnings' => $warnings,
		);
	}

	private static function paint_color( array $paint ): ?string {
		if ( 'SOLID' !== ( $paint['type'] ?? '' ) || false === ( $paint['visible'] ?? true ) || ! is_array( $paint['color'] ?? null ) ) {
			return null;
		}
		$channels = array_map( static function ( string $channel ) use ( $paint ): int { return max( 0, min( 255, (int) round( 255 * (float) ( $paint['color'][ $channel ] ?? 0 ) ) ) ); }, array( 'r', 'g', 'b' ) );
		$opacity = max( 0, min( 1, (float) ( $paint['opacity'] ?? $paint['color']['a'] ?? 1 ) ) );
		if ( 1 > $opacity ) {
			return sprintf( 'rgba(%d, %d, %d, %s)', $channels[0], $channels[1], $channels[2], rtrim( rtrim( sprintf( '%.3F', $opacity ), '0' ), '.' ) );
		}
		return sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );
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
