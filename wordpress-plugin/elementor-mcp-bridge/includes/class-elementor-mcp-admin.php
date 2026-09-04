<?php
/**
 * Native WordPress onboarding surface for the future Figma connection flow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_MCP_Admin {
	private const PAGE_SLUG = 'elementor-mcp-import';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	public static function register_page(): void {
		add_menu_page(
			__( 'Figma Import', 'elementor-mcp-bridge' ),
			__( 'Figma Import', 'elementor-mcp-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-art',
			58
		);
	}

	public static function styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( 'toplevel_page_' . self::PAGE_SLUG ) !== $screen->id ) {
			return;
		}
		?>
		<style>
			.elementor-mcp-wrap { max-width: 1040px; margin: 34px 20px 0 0; }
			.elementor-mcp-hero { background: linear-gradient(135deg, #172554, #0f766e); border-radius: 18px; color: #fff; padding: 36px 40px; }
			.elementor-mcp-hero h1 { color: #fff; font-size: 32px; margin: 0 0 10px; }
			.elementor-mcp-hero p { font-size: 16px; margin: 0; max-width: 650px; opacity: .9; }
			.elementor-mcp-grid { display: grid; gap: 18px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 24px; }
			.elementor-mcp-card { background: #fff; border: 1px solid #d8dee9; border-radius: 14px; box-sizing: border-box; min-height: 226px; padding: 24px; }
			.elementor-mcp-card h2 { font-size: 18px; margin: 10px 0; }
			.elementor-mcp-step { color: #0f766e; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
			.elementor-mcp-state { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 600; padding: 5px 9px; }
			.elementor-mcp-state-ready { background: #dcfce7; color: #166534; }
			.elementor-mcp-state-waiting { background: #fef3c7; color: #92400e; }
			.elementor-mcp-compatibility { background: #fff; border: 1px solid #d8dee9; border-radius: 14px; margin-top: 18px; padding: 24px; }
			.elementor-mcp-compatibility table { border-collapse: collapse; width: 100%; }
			.elementor-mcp-compatibility td { border-top: 1px solid #edf0f4; padding: 12px 0; }
			.elementor-mcp-compatibility td:last-child { text-align: right; }
			.elementor-mcp-card input[type="url"] { box-sizing: border-box; margin: 12px 0; max-width: 100%; width: 100%; }
			.elementor-mcp-preview { background: #f8fafc; border: 1px solid #d8dee9; border-radius: 10px; margin-top: 14px; padding: 12px; }
			.elementor-mcp-preview h3 { font-size: 14px; margin: 0 0 8px; }
			.elementor-mcp-preview p, .elementor-mcp-preview ul { font-size: 13px; margin: 8px 0; }
			.elementor-mcp-conversion-plan { margin: 12px 0 0; }
			.elementor-mcp-conversion-plan li { margin: 4px 0; }
			@media (max-width: 782px) { .elementor-mcp-grid { grid-template-columns: 1fr; } .elementor-mcp-hero { padding: 28px; } }
		</style>
		<?php
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Figma Import.', 'elementor-mcp-bridge' ) );
		}

		$elementor_ready = did_action( 'elementor/loaded' ) && defined( 'ELEMENTOR_VERSION' );
		$broker_ready = Elementor_MCP_Figma_Connection::broker_ready();
		$connection = Elementor_MCP_Figma_Connection::summary();
		$preview = Elementor_MCP_Figma_Analyzer::preview();
		?>
		<div class="wrap elementor-mcp-wrap">
			<?php self::render_notice(); ?>
			<section class="elementor-mcp-hero">
				<h1><?php esc_html_e( 'Figma Import', 'elementor-mcp-bridge' ); ?></h1>
				<p><?php esc_html_e( 'Turn a Figma frame into an editable Elementor draft, with a review step before anything changes on your site.', 'elementor-mcp-bridge' ); ?></p>
			</section>

			<div class="elementor-mcp-grid">
				<section class="elementor-mcp-card">
					<div class="elementor-mcp-step"><?php esc_html_e( 'Step 1', 'elementor-mcp-bridge' ); ?></div>
					<h2><?php esc_html_e( 'Connect Figma', 'elementor-mcp-bridge' ); ?></h2>
					<?php self::connection_card( $broker_ready, $connection ); ?>
				</section>

				<section class="elementor-mcp-card">
					<div class="elementor-mcp-step"><?php esc_html_e( 'Step 2', 'elementor-mcp-bridge' ); ?></div>
					<h2><?php esc_html_e( 'Analyze a frame', 'elementor-mcp-bridge' ); ?></h2>
					<?php self::analysis_card( $connection, $preview ); ?>
				</section>

				<section class="elementor-mcp-card">
					<div class="elementor-mcp-step"><?php esc_html_e( 'Step 3', 'elementor-mcp-bridge' ); ?></div>
					<h2><?php esc_html_e( 'Review and create a draft', 'elementor-mcp-bridge' ); ?></h2>
					<?php self::draft_card( $preview ); ?>
				</section>
			</div>

			<section class="elementor-mcp-compatibility">
				<h2><?php esc_html_e( 'Site compatibility', 'elementor-mcp-bridge' ); ?></h2>
				<table>
					<tr><td><?php esc_html_e( 'WordPress', 'elementor-mcp-bridge' ); ?></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Elementor', 'elementor-mcp-bridge' ); ?></td><td><?php self::state_badge( $elementor_ready, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : __( 'Ready', 'elementor-mcp-bridge' ), __( 'Not active', 'elementor-mcp-bridge' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Elementor Pro', 'elementor-mcp-bridge' ); ?></td><td><?php echo esc_html( defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : __( 'Not detected', 'elementor-mcp-bridge' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Figma authorization', 'elementor-mcp-bridge' ); ?></td><td><?php self::authorization_state( $broker_ready, $connection ); ?></td></tr>
				</table>
			</section>
		</div>
		<?php
	}

	private static function state_badge( bool $ready, string $ready_label, string $waiting_label ): void {
		$class = $ready ? 'elementor-mcp-state-ready' : 'elementor-mcp-state-waiting';
		$label = $ready ? $ready_label : $waiting_label;
		echo '<span class="elementor-mcp-state ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function connection_card( bool $broker_ready, ?array $connection ): void {
		if ( ! $broker_ready ) {
			self::state_badge( false, '', __( 'Service not configured', 'elementor-mcp-bridge' ) );
			echo '<p>' . esc_html__( 'A secure OAuth service must be provisioned before sign-in can begin. No Figma token is requested or stored while it is unavailable.', 'elementor-mcp-bridge' ) . '</p>';
			return;
		}

		if ( $connection && empty( $connection['expired'] ) ) {
			self::state_badge( true, __( 'Connected', 'elementor-mcp-bridge' ), '' );
			$handle = ! empty( $connection['handle'] ) ? sprintf( __( 'Connected as %s.', 'elementor-mcp-bridge' ), $connection['handle'] ) : __( 'Figma access is connected for this WordPress administrator.', 'elementor-mcp-bridge' );
			echo '<p>' . esc_html( $handle ) . '</p>';
			self::disconnect_form();
			return;
		}

		if ( $connection ) {
			self::state_badge( false, '', __( 'Connection expired', 'elementor-mcp-bridge' ) );
			echo '<p>' . esc_html__( 'Reconnect Figma to continue. The expired authorization can be removed below.', 'elementor-mcp-bridge' ) . '</p>';
			self::disconnect_form();
		}

		echo '<p>' . esc_html__( 'Sign in with Figma in your browser. This plugin never asks you to paste a personal access token.', 'elementor-mcp-bridge' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="elementor_mcp_start_oauth">
			<?php wp_nonce_field( 'elementor_mcp_start_oauth' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect Figma', 'elementor-mcp-bridge' ); ?></button>
		</form>
		<?php
	}

	private static function disconnect_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="elementor_mcp_disconnect_figma">
			<?php wp_nonce_field( 'elementor_mcp_disconnect_figma' ); ?>
			<button type="submit" class="button-link-delete"><?php esc_html_e( 'Disconnect Figma', 'elementor-mcp-bridge' ); ?></button>
		</form>
		<?php
	}

	private static function authorization_state( bool $broker_ready, ?array $connection ): void {
		if ( ! $broker_ready ) {
			self::state_badge( false, '', __( 'Service not configured', 'elementor-mcp-bridge' ) );
			return;
		}
		if ( $connection && empty( $connection['expired'] ) ) {
			self::state_badge( true, __( 'Connected', 'elementor-mcp-bridge' ), '' );
			return;
		}
		self::state_badge( false, '', $connection ? __( 'Connection expired', 'elementor-mcp-bridge' ) : __( 'Ready to connect', 'elementor-mcp-bridge' ) );
	}

	private static function analysis_card( ?array $connection, ?array $preview ): void {
		if ( ! $connection || ! empty( $connection['expired'] ) ) {
			self::state_badge( false, '', __( 'Available after connection', 'elementor-mcp-bridge' ) );
			echo '<p>' . esc_html__( 'Connect Figma first. Analysis is read-only and never changes WordPress content.', 'elementor-mcp-bridge' ) . '</p>';
			return;
		}

		self::state_badge( true, __( 'Connection ready', 'elementor-mcp-bridge' ), '' );
		?>
		<p><?php esc_html_e( 'Paste a Figma frame URL to preview its conversion. Nothing will be imported at this step.', 'elementor-mcp-bridge' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="elementor_mcp_analyze_figma">
			<?php wp_nonce_field( 'elementor_mcp_analyze_figma' ); ?>
			<label for="elementor-mcp-figma-url" class="screen-reader-text"><?php esc_html_e( 'Figma frame URL', 'elementor-mcp-bridge' ); ?></label>
			<input id="elementor-mcp-figma-url" name="figma_url" type="url" required placeholder="https://www.figma.com/design/...?...node-id=..." value="<?php echo esc_attr( $preview['source']['url'] ?? '' ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Analyze frame', 'elementor-mcp-bridge' ); ?></button>
		</form>
		<?php self::render_preview( $preview ); ?>
		<?php
	}

	private static function render_preview( ?array $preview ): void {
		if ( ! $preview ) {
			return;
		}
		$source = is_array( $preview['source'] ?? null ) ? $preview['source'] : array();
		$stats = is_array( $preview['stats'] ?? null ) ? $preview['stats'] : array();
		$warnings = is_array( $preview['warnings'] ?? null ) ? $preview['warnings'] : array();
		$layout_model = is_array( $preview['layout_model'] ?? null ) ? $preview['layout_model'] : array();
		?>
		<div class="elementor-mcp-preview">
			<h3><?php echo esc_html( sprintf( __( 'Preview: %s', 'elementor-mcp-bridge' ), $source['name'] ?? __( 'Figma frame', 'elementor-mcp-bridge' ) ) ); ?></h3>
			<p><?php echo esc_html( sprintf( __( '%1$d containers, %2$d text layers, %3$d image/vector layers, %4$d components.', 'elementor-mcp-bridge' ), absint( $stats['containers'] ?? 0 ), absint( $stats['text'] ?? 0 ), absint( $stats['images'] ?? 0 ), absint( $stats['components'] ?? 0 ) ) ); ?></p>
			<?php if ( $layout_model ) : ?><p><strong><?php echo esc_html( sprintf( __( 'Layout model v%s:', 'elementor-mcp-bridge' ), $layout_model['version'] ?? '1' ) ); ?></strong> <?php echo esc_html( sprintf( __( '%1$d native-flow containers and %2$d coordinate fallbacks.', 'elementor-mcp-bridge' ), absint( $layout_model['native_flow'] ?? 0 ), absint( $layout_model['coordinate_fallback'] ?? 0 ) ) ); ?></p><?php endif; ?>
			<?php if ( $warnings ) : ?>
				<ul><?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php $styles = is_array( $preview['styles'] ?? null ) ? $preview['styles'] : array(); ?>
			<?php if ( ! empty( $styles['colors'] ) ) : ?><p><strong><?php esc_html_e( 'Figma colors:', 'elementor-mcp-bridge' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( static function ( array $color ): string { return $color['value'] . ' (' . absint( $color['occurrences'] ) . ')'; }, $styles['colors'] ) ) ); ?></p><?php endif; ?>
			<?php if ( ! empty( $styles['typography'] ) ) : ?><p><strong><?php esc_html_e( 'Typography:', 'elementor-mcp-bridge' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( static function ( array $style ): string { return $style['font'] . ' ' . absint( $style['weight'] ) . ' ' . $style['size'] . 'px'; }, $styles['typography'] ) ) ); ?></p><?php endif; ?>
			<?php if ( ! empty( $styles['components'] ) ) : ?><p><strong><?php esc_html_e( 'Figma components:', 'elementor-mcp-bridge' ); ?></strong> <?php echo esc_html( implode( ', ', array_map( static function ( array $component ): string { return $component['name'] . ' (' . absint( $component['occurrences'] ) . ')'; }, $styles['components'] ) ) ); ?></p><?php endif; ?>
			<p><strong><?php esc_html_e( 'Conversion plan:', 'elementor-mcp-bridge' ); ?></strong></p>
			<ul class="elementor-mcp-conversion-plan">
				<li><?php esc_html_e( 'Frames and groups become editable Elementor containers.', 'elementor-mcp-bridge' ); ?></li>
				<li><?php esc_html_e( 'Text becomes Heading or Text Editor widgets; image fills import as media.', 'elementor-mcp-bridge' ); ?></li>
				<li><?php esc_html_e( 'Vectors import as sanitized SVG media, with a PNG fallback if needed.', 'elementor-mcp-bridge' ); ?></li>
				<?php if ( ! empty( $stats['components'] ) ) : ?><li><?php esc_html_e( 'Figma component instances are expanded into editable containers and child layers. Shared Elementor templates are not created yet.', 'elementor-mcp-bridge' ); ?></li><?php endif; ?>
				<?php if ( ! empty( $stats['variable_bindings'] ) ) : ?><li><?php echo esc_html( sprintf( _n( '%d Figma variable binding was detected. Global Elementor styles will not be changed.', '%d Figma variable bindings were detected. Global Elementor styles will not be changed.', absint( $stats['variable_bindings'] ), 'elementor-mcp-bridge' ), absint( $stats['variable_bindings'] ) ) ); ?></li><?php endif; ?>
			</ul>
		</div>
		<?php
	}

	private static function draft_card( ?array $preview ): void {
		if ( ! $preview ) { echo '<span class="elementor-mcp-state elementor-mcp-state-waiting">' . esc_html__( 'Analyze a frame first', 'elementor-mcp-bridge' ) . '</span><p>' . esc_html__( 'Creating a draft is available only after reviewing a fresh Figma analysis.', 'elementor-mcp-bridge' ) . '</p>'; return; }
		?>
		<span class="elementor-mcp-state elementor-mcp-state-waiting"><?php esc_html_e( 'Draft-only by default', 'elementor-mcp-bridge' ); ?></span>
		<p><?php esc_html_e( 'The draft preserves editable containers and text, imports image/vector assets, and never publishes or replaces existing content.', 'elementor-mcp-bridge' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="elementor_mcp_create_draft"><?php wp_nonce_field( 'elementor_mcp_create_draft' ); ?><input name="draft_title" type="text" value="<?php echo esc_attr( $preview['source']['name'] ?? 'Figma import' ); ?>"><p><label><input name="confirm_draft" type="checkbox" value="1" required> <?php esc_html_e( 'Create a new Elementor draft only. Do not publish or replace an existing page.', 'elementor-mcp-bridge' ); ?></label></p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create draft', 'elementor-mcp-bridge' ); ?></button></form>
		<?php
	}

	private static function render_notice(): void {
		$notice = isset( $_GET['elementor_mcp_notice'] ) ? sanitize_key( wp_unslash( $_GET['elementor_mcp_notice'] ) ) : '';
		$messages = array(
			'connection-service-unavailable' => array( 'error', __( 'The secure Figma connection service has not been configured.', 'elementor-mcp-bridge' ) ),
			'connection-failed'              => array( 'error', __( 'Figma could not be connected. No authorization data was saved; please try again or contact the site administrator.', 'elementor-mcp-bridge' ) ),
			'connection-complete'            => array( 'success', __( 'Figma is connected for your WordPress user.', 'elementor-mcp-bridge' ) ),
			'disconnected'                    => array( 'success', __( 'The saved Figma authorization has been removed from this WordPress user.', 'elementor-mcp-bridge' ) ),
			'analysis-complete'               => array( 'success', __( 'Figma frame analysis is ready to review. No WordPress content was changed.', 'elementor-mcp-bridge' ) ),
			'analysis-failed'                 => array( 'error', __( 'The Figma frame could not be analyzed. Check the selected frame URL and ensure it is shared with the connected Figma user.', 'elementor-mcp-bridge' ) ),
			'draft-created'                   => array( 'success', __( 'A new Elementor draft was created. It has not been published.', 'elementor-mcp-bridge' ) ),
			'draft-failed'                    => array( 'error', __( 'The draft could not be created. Analyze the frame again and confirm draft creation.', 'elementor-mcp-bridge' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $messages[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ][1] ) . '</p></div>';
	}
}
