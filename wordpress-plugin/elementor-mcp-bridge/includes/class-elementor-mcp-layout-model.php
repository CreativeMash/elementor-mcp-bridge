<?php
/**
 * Converts a Figma node tree into a renderer-neutral layout model.
 *
 * The model is analysis-only for now. Keeping it separate from the Elementor
 * renderer lets future renderers make deliberate decisions from the same input.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_MCP_Layout_Model {
	public const VERSION = '1.0';

	private const ASSET_TYPES = array( 'VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'POLYGON', 'ELLIPSE', 'RECTANGLE', 'LINE' );

	/** Build a portable model for a single Figma node and its descendants. */
	public static function build( array $node ): array {
		$children = array();
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$children[] = self::build( $child );
			}
		}

		$type = sanitize_key( (string) ( $node['type'] ?? 'unknown' ) );
		$name = sanitize_text_field( (string) ( $node['name'] ?? $type ) );
		$kind = self::kind( $node, $children );

		return array(
			'version'     => self::VERSION,
			'source'      => array(
				'figma_id' => sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
				'name'     => $name,
				'type'     => $type,
				'bounds'   => self::bounds( $node ),
			),
			'kind'        => $kind,
			'content'     => 'text' === $kind ? sanitize_textarea_field( (string) ( $node['characters'] ?? '' ) ) : '',
			'layout'       => self::layout( $node, $children ),
			'recognition' => self::recognition_for( $node ),
			'children'     => $children,
		);
	}

	/** Return compact, preview-safe counts without persisting a second full node tree. */
	public static function summary( array $node ): array {
		$summary = array(
			'version'                    => self::VERSION,
			'native_flow'                => 0,
			'coordinate_fallback'        => 0,
			'high_confidence_components' => 0,
			'component_fallbacks'        => 0,
		);
		self::summarize( self::build( $node ), $summary );
		return $summary;
	}

	private static function summarize( array $model, array &$summary ): void {
		$strategy = $model['layout']['strategy'] ?? '';
		if ( 'native-flow' === $strategy ) {
			$summary['native_flow']++;
		} elseif ( 'coordinate-fallback' === $strategy ) {
			$summary['coordinate_fallback']++;
		}
		if ( 'component' === ( $model['kind'] ?? '' ) ) {
			if ( ! empty( $model['recognition']['type'] ) && (float) ( $model['recognition']['confidence'] ?? 0 ) >= 0.9 ) {
				$summary['high_confidence_components']++;
			} else {
				$summary['component_fallbacks']++;
			}
		}
		foreach ( (array) ( $model['children'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				self::summarize( $child, $summary );
			}
		}
	}

	private static function kind( array $node, array $children ): string {
		$type = (string) ( $node['type'] ?? '' );
		if ( 'TEXT' === $type ) {
			return 'text';
		}
		if ( in_array( $type, array( 'COMPONENT', 'INSTANCE' ), true ) ) {
			return 'component';
		}
		if ( in_array( $type, self::ASSET_TYPES, true ) || self::has_image_fill( $node ) ) {
			return 'asset';
		}
		if ( 'CANVAS' === $type || 'SECTION' === $type ) {
			return 'section';
		}
		return $children ? 'container' : 'unknown';
	}

	private static function layout( array $node, array $children ): array {
		$mode = (string) ( $node['layoutMode'] ?? 'NONE' );
		$is_auto_layout = in_array( $mode, array( 'HORIZONTAL', 'VERTICAL' ), true );
		return array(
			'strategy' => $is_auto_layout ? 'native-flow' : ( $children ? 'coordinate-fallback' : 'none' ),
			'direction' => 'HORIZONTAL' === $mode ? 'row' : ( 'VERTICAL' === $mode ? 'column' : null ),
			'gap'       => $is_auto_layout ? (float) ( $node['itemSpacing'] ?? 0 ) : null,
			'padding'   => $is_auto_layout ? array(
				'top'    => (float) ( $node['paddingTop'] ?? 0 ),
				'right'  => (float) ( $node['paddingRight'] ?? 0 ),
				'bottom' => (float) ( $node['paddingBottom'] ?? 0 ),
				'left'   => (float) ( $node['paddingLeft'] ?? 0 ),
			) : null,
			'width'     => self::sizing( (string) ( $node['layoutSizingHorizontal'] ?? '' ), $node['absoluteBoundingBox']['width'] ?? null ),
			'height'    => self::sizing( (string) ( $node['layoutSizingVertical'] ?? '' ), $node['absoluteBoundingBox']['height'] ?? null ),
			'justify'   => sanitize_key( (string) ( $node['primaryAxisAlignItems'] ?? '' ) ),
			'align'     => sanitize_key( (string) ( $node['counterAxisAlignItems'] ?? '' ) ),
			'wrap'      => 'WRAP' === ( $node['layoutWrap'] ?? 'NO_WRAP' ),
		);
	}

	private static function sizing( string $mode, $size ): ?string {
		if ( 'FILL' === $mode ) {
			return 'fill';
		}
		if ( 'HUG' === $mode ) {
			return 'hug';
		}
		return is_numeric( $size ) ? 'fixed' : null;
	}

	/** Return a conservative component recipe candidate for a Figma node. */
	public static function recognition_for( array $node ): array {
		if ( ! in_array( $node['type'] ?? '', array( 'COMPONENT', 'INSTANCE' ), true ) ) {
			return array();
		}
		$name = strtolower( trim( explode( '/', (string) ( $node['name'] ?? '' ) )[0] ) );
		if ( preg_match( '/\\bbutton\\b/', $name ) ) {
			return array( 'type' => 'button', 'confidence' => 0.98, 'source' => 'component-name' );
		}
		return array();
	}

	private static function bounds( array $node ): ?array {
		$bounds = $node['absoluteBoundingBox'] ?? null;
		if ( ! is_array( $bounds ) ) {
			return null;
		}
		return array(
			'x'      => (float) ( $bounds['x'] ?? 0 ),
			'y'      => (float) ( $bounds['y'] ?? 0 ),
			'width'  => (float) ( $bounds['width'] ?? 0 ),
			'height' => (float) ( $bounds['height'] ?? 0 ),
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
}
