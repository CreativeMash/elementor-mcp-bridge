<?php
/** Site-level mappings between named Figma components and safe Elementor recipes. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_MCP_Component_Recipes {
	private const OPTION = 'elementor_mcp_component_recipes';

	public static function init(): void {
		add_action( 'admin_post_elementor_mcp_save_component_recipes', array( __CLASS__, 'save' ) );
	}

	/** Return the resolved recipe only for Figma component or instance nodes. */
	public static function recipe_for( array $node ): array {
		if ( ! in_array( $node['type'] ?? '', array( 'COMPONENT', 'INSTANCE' ), true ) ) {
			return array();
		}
		$key = self::key( (string) ( $node['name'] ?? '' ) );
		$stored = self::stored();
		if ( isset( $stored[ $key ] ) ) {
			return array( 'type' => $stored[ $key ], 'confidence' => 0.99, 'source' => 'site-mapping' );
		}
		if ( 'button' === $key ) {
			return array( 'type' => 'button', 'confidence' => 0.98, 'source' => 'built-in' );
		}
		if ( 'avatar' === $key ) {
			return array( 'type' => 'avatar', 'confidence' => 0.98, 'source' => 'built-in' );
		}
		return array();
	}

	public static function selection_for( string $name ): string {
		return self::stored()[ self::key( $name ) ] ?? '';
	}

	public static function choices(): array {
		return array(
			''          => __( 'Automatic', 'elementor-mcp-bridge' ),
			'button'    => __( 'Elementor Button', 'elementor-mcp-bridge' ),
			'avatar'    => __( 'Avatar Container', 'elementor-mcp-bridge' ),
			'container' => __( 'Container fallback', 'elementor-mcp-bridge' ),
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save component recipes.', 'elementor-mcp-bridge' ), 403 );
		}
		check_admin_referer( 'elementor_mcp_save_component_recipes' );
		$choices = self::choices();
		$selected = self::stored();
		foreach ( array_slice( (array) ( $_POST['recipes'] ?? array() ), 0, 30, true ) as $key => $recipe ) {
			$key = sanitize_title( wp_unslash( (string) $key ) );
			$recipe = sanitize_key( wp_unslash( (string) $recipe ) );
			if ( $key && isset( $choices[ $recipe ] ) && '' !== $recipe ) {
				$selected[ $key ] = $recipe;
			} elseif ( $key ) {
				unset( $selected[ $key ] );
			}
		}
		update_option( self::OPTION, $selected, false );
		wp_safe_redirect( add_query_arg( 'elementor_mcp_notice', 'recipes-saved', admin_url( 'admin.php?page=elementor-mcp-import' ) ) );
		exit;
	}

	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$choices = self::choices();
		return array_filter( $stored, static function ( $recipe ) use ( $choices ): bool {
			return is_string( $recipe ) && isset( $choices[ $recipe ] ) && '' !== $recipe;
		} );
	}

	private static function key( string $name ): string {
		$base_name = trim( explode( '/', $name )[0] );
		return sanitize_title( $base_name );
	}
}
