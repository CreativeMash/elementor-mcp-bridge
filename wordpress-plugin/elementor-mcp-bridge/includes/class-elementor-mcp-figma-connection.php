<?php
/**
 * Owns the WordPress half of the Figma OAuth hand-off.
 *
 * The broker keeps Figma's confidential client secret. This plugin stores only
 * the current WordPress user's encrypted authorization data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_MCP_Figma_Connection {
	private const CONNECTION_META = '_elementor_mcp_figma_connection';
	private const STATE_PREFIX = 'elementor_mcp_figma_state_';

	public static function init(): void {
		add_action( 'admin_post_elementor_mcp_start_oauth', array( __CLASS__, 'start_oauth' ) );
		add_action( 'admin_post_elementor_mcp_oauth_callback', array( __CLASS__, 'complete_oauth' ) );
		add_action( 'admin_post_elementor_mcp_disconnect_figma', array( __CLASS__, 'disconnect' ) );
	}

	public static function broker_url(): string {
		if ( ! defined( 'ELEMENTOR_MCP_FIGMA_OAUTH_BROKER_URL' ) ) {
			return '';
		}

		$url = esc_url_raw( (string) ELEMENTOR_MCP_FIGMA_OAUTH_BROKER_URL );
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && wp_http_validate_url( $url ) ? untrailingslashit( $url ) : '';
	}

	public static function broker_ready(): bool {
		return '' !== self::broker_url();
	}

	/**
	 * Return connection metadata safe to render; authorization tokens never leave this class.
	 */
	public static function summary( int $user_id = 0 ): ?array {
		$connection = self::connection( $user_id );
		if ( ! $connection ) {
			return null;
		}

		return array(
			'handle'     => sanitize_text_field( (string) ( $connection['handle'] ?? '' ) ),
			'scopes'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $connection['scopes'] ?? array() ) ) ) ),
			'expires_at' => absint( $connection['expires_at'] ?? 0 ),
			'expired'    => ! empty( $connection['expires_at'] ) && absint( $connection['expires_at'] ) <= time(),
		);
	}

	/**
	 * Server-side use only. This value must never be rendered or returned by a REST endpoint.
	 */
	public static function access_token(): ?string {
		$connection = self::connection();
		if ( ! $connection || ( ! empty( $connection['expires_at'] ) && absint( $connection['expires_at'] ) <= time() ) ) {
			return null;
		}
		return is_string( $connection['access_token'] ?? null ) ? $connection['access_token'] : null;
	}

	public static function start_oauth(): void {
		self::require_admin();
		check_admin_referer( 'elementor_mcp_start_oauth' );

		$broker_url = self::broker_url();
		if ( ! $broker_url ) {
			self::redirect( 'connection-service-unavailable' );
		}

		try {
			$state = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			self::redirect( 'connection-failed' );
		}

		set_transient(
			self::STATE_PREFIX . $state,
			array( 'user_id' => get_current_user_id() ),
			10 * MINUTE_IN_SECONDS
		);

		$authorize_url = add_query_arg(
			array(
				'state'      => $state,
				'return_url' => admin_url( 'admin-post.php?action=elementor_mcp_oauth_callback' ),
			),
			$broker_url . '/v1/wordpress/authorize'
		);

		wp_redirect( $authorize_url, 302 );
		exit;
	}

	public static function complete_oauth(): void {
		self::require_admin();

		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$handoff = isset( $_GET['handoff'] ) ? sanitize_text_field( wp_unslash( $_GET['handoff'] ) ) : '';
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $state ) || strlen( $handoff ) > 4096 ) {
			self::redirect( 'connection-failed' );
		}
		$pending = $state ? get_transient( self::STATE_PREFIX . $state ) : false;

		if ( ! is_array( $pending ) || get_current_user_id() !== absint( $pending['user_id'] ?? 0 ) || ! $handoff || ! self::broker_ready() ) {
			self::redirect( 'connection-failed' );
		}

		delete_transient( self::STATE_PREFIX . $state );
		$response = wp_remote_post(
			self::broker_url() . '/v1/wordpress/exchange',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'state'        => $state,
						'handoff'      => $handoff,
						'callback_url' => admin_url( 'admin-post.php?action=elementor_mcp_oauth_callback' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			self::redirect( 'connection-failed' );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) || empty( $payload['access_token'] ) || ! is_string( $payload['access_token'] ) ) {
			self::redirect( 'connection-failed' );
		}

		$connection = array(
			'access_token'  => $payload['access_token'],
			'refresh_token' => isset( $payload['refresh_token'] ) && is_string( $payload['refresh_token'] ) ? $payload['refresh_token'] : '',
			'expires_at'    => ! empty( $payload['expires_in'] ) ? time() + min( absint( $payload['expires_in'] ), YEAR_IN_SECONDS ) : 0,
			'handle'        => sanitize_text_field( (string) ( $payload['handle'] ?? '' ) ),
			'scopes'        => array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['scopes'] ?? array() ) ) ) ),
		);
		$encrypted = self::encrypt( $connection );
		if ( ! $encrypted ) {
			self::redirect( 'connection-failed' );
		}

		update_user_meta( get_current_user_id(), self::CONNECTION_META, $encrypted );
		self::redirect( 'connection-complete' );
	}

	public static function disconnect(): void {
		self::require_admin();
		check_admin_referer( 'elementor_mcp_disconnect_figma' );
		delete_user_meta( get_current_user_id(), self::CONNECTION_META );
		self::redirect( 'disconnected' );
	}

	private static function connection( int $user_id = 0 ): ?array {
		$user_id = $user_id ?: get_current_user_id();
		$encrypted = get_user_meta( $user_id, self::CONNECTION_META, true );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return null;
		}

		$connection = self::decrypt( $encrypted );
		return is_array( $connection ) && ! empty( $connection['access_token'] ) ? $connection : null;
	}

	private static function encrypt( array $connection ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$tag = '';
		$ciphertext = openssl_encrypt( wp_json_encode( $connection ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $ciphertext || '' === $tag ) {
			return '';
		}

		return wp_json_encode(
			array(
				'v'          => 1,
				'nonce'      => base64_encode( $nonce ),
				'tag'        => base64_encode( $tag ),
				'ciphertext' => base64_encode( $ciphertext ),
			)
		);
	}

	private static function decrypt( string $encrypted ): ?array {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		$stored = json_decode( $encrypted, true );
		if ( ! is_array( $stored ) || 1 !== absint( $stored['v'] ?? 0 ) ) {
			return null;
		}

		$nonce = base64_decode( (string) ( $stored['nonce'] ?? '' ), true );
		$tag = base64_decode( (string) ( $stored['tag'] ?? '' ), true );
		$ciphertext = base64_decode( (string) ( $stored['ciphertext'] ?? '' ), true );
		if ( false === $nonce || false === $tag || false === $ciphertext ) {
			return null;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$plain = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		$connection = false === $plain ? null : json_decode( $plain, true );
		return is_array( $connection ) ? $connection : null;
	}

	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Figma Import.', 'elementor-mcp-bridge' ), 403 );
		}
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				'elementor_mcp_notice',
				rawurlencode( $notice ),
				admin_url( 'admin.php?page=elementor-mcp-import' )
			)
		);
		exit;
	}
}
