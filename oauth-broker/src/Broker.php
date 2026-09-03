<?php
/**
 * Minimal Figma OAuth broker. Deploy this outside the WordPress web root.
 *
 * It performs only Figma OAuth code exchange and a short-lived handoff to a
 * registered WordPress callback. It never converts, stores, or inspects files.
 */

declare( strict_types=1 );

final class Figma_Broker_Config {
	public string $public_url;
	public string $callback_url;
	public string $client_id;
	public string $client_secret;
	public string $record_key;
	public string $state_dir;
	public array $allowed_callbacks;
	public array $scopes;

	public static function from_environment(): self {
		$config = new self();
		$config->public_url = self::required_url( 'BROKER_PUBLIC_URL' );
		$config->callback_url = $config->public_url . '/oauth/callback';
		$config->client_id = self::required( 'FIGMA_OAUTH_CLIENT_ID' );
		$config->client_secret = self::required( 'FIGMA_OAUTH_CLIENT_SECRET' );
		$config->record_key = self::required( 'BROKER_RECORD_KEY' );
		if ( strlen( $config->record_key ) < 32 ) {
			throw new RuntimeException( 'BROKER_RECORD_KEY must be at least 32 characters.' );
		}

		$config->state_dir = self::required( 'BROKER_STATE_DIR' );
		if ( ! is_dir( $config->state_dir ) && ! mkdir( $config->state_dir, 0700, true ) ) {
			throw new RuntimeException( 'Could not create BROKER_STATE_DIR.' );
		}
		if ( ! is_writable( $config->state_dir ) ) {
			throw new RuntimeException( 'BROKER_STATE_DIR is not writable.' );
		}

		$config->allowed_callbacks = array_values( array_filter( array_map( 'trim', explode( ',', self::required( 'WORDPRESS_CALLBACK_ALLOWLIST' ) ) ) ) );
		foreach ( $config->allowed_callbacks as $callback ) {
			self::validate_https_url( $callback );
		}
		$config->scopes = array_values( array_filter( array_map( 'trim', explode( ',', getenv( 'FIGMA_OAUTH_SCOPES' ) ?: 'file_content:read' ) ) ) );
		if ( empty( $config->scopes ) || array_diff( $config->scopes, array( 'file_content:read', 'file_variables:read' ) ) ) {
			throw new RuntimeException( 'Only the required Figma read scopes may be configured.' );
		}
		return $config;
	}

	private static function required( string $name ): string {
		$value = trim( (string) getenv( $name ) );
		if ( '' === $value ) {
			throw new RuntimeException( $name . ' is required.' );
		}
		return $value;
	}

	private static function required_url( string $name ): string {
		$url = rtrim( self::required( $name ), '/' );
		self::validate_https_url( $url );
		return $url;
	}

	private static function validate_https_url( string $url ): void {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new RuntimeException( 'Configured URLs must use HTTPS.' );
		}
	}
}

final class Figma_Broker_Store {
	private string $directory;
	private string $key;

	public function __construct( Figma_Broker_Config $config ) {
		$this->directory = rtrim( $config->state_dir, DIRECTORY_SEPARATOR );
		$this->key = hash( 'sha256', $config->record_key, true );
	}

	public function put( string $type, string $id, array $record, int $ttl ): void {
		$record['expires_at'] = time() + $ttl;
		$plaintext = json_encode( $record, JSON_THROW_ON_ERROR );
		$nonce = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Could not encrypt authorization record.' );
		}
		$stored = json_encode(
			array(
				'v' => 1,
				'n' => base64_encode( $nonce ),
				't' => base64_encode( $tag ),
				'c' => base64_encode( $ciphertext ),
			),
			JSON_THROW_ON_ERROR
		);
		$path = $this->path( $type, $id );
		if ( false === file_put_contents( $path, $stored, LOCK_EX ) ) {
			throw new RuntimeException( 'Could not store authorization record.' );
		}
		chmod( $path, 0600 );
	}

	/** Consume records once, even when two requests arrive at once. */
	public function consume( string $type, string $id ): ?array {
		$path = $this->path( $type, $id );
		$lock = fopen( $path . '.lock', 'c' );
		if ( false === $lock ) {
			return null;
		}
		flock( $lock, LOCK_EX );
		$stored = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( false !== $stored ) {
			unlink( $path );
		}
		flock( $lock, LOCK_UN );
		fclose( $lock );
		@unlink( $path . '.lock' );
		if ( false === $stored ) {
			return null;
		}

		try {
			$data = json_decode( $stored, true, 512, JSON_THROW_ON_ERROR );
			if ( 1 !== (int) ( $data['v'] ?? 0 ) ) {
				return null;
			}
			$nonce = base64_decode( $data['n'] ?? '', true );
			$tag = base64_decode( $data['t'] ?? '', true );
			$ciphertext = base64_decode( $data['c'] ?? '', true );
			if ( false === $nonce || false === $tag || false === $ciphertext ) {
				return null;
			}
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag );
			$record = false === $plaintext ? null : json_decode( $plaintext, true, 512, JSON_THROW_ON_ERROR );
			return is_array( $record ) && (int) ( $record['expires_at'] ?? 0 ) >= time() ? $record : null;
		} catch ( Throwable $exception ) {
			return null;
		}
	}

	private function path( string $type, string $id ): string {
		if ( ! preg_match( '/\A(?:oauth|handoff)\z/', $type ) || ! preg_match( '/\A[a-zA-Z0-9_-]{32,128}\z/', $id ) ) {
			throw new InvalidArgumentException( 'Invalid authorization record key.' );
		}
		return $this->directory . DIRECTORY_SEPARATOR . $type . '-' . hash( 'sha256', $id );
	}
}

final class Figma_Broker {
	private Figma_Broker_Config $config;
	private Figma_Broker_Store $store;

	public function __construct( Figma_Broker_Config $config ) {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'openssl_encrypt' ) ) {
			throw new RuntimeException( 'The cURL and OpenSSL PHP extensions are required.' );
		}
		$this->config = $config;
		$this->store = new Figma_Broker_Store( $config );
	}

	public function dispatch(): void {
		$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		if ( 'GET' === $method && '/v1/wordpress/authorize' === $path ) {
			$this->authorize();
		}
		if ( 'GET' === $method && '/oauth/callback' === $path ) {
			$this->callback();
		}
		if ( 'POST' === $method && '/v1/wordpress/exchange' === $path ) {
			$this->exchange();
		}
		$this->json( 404, array( 'error' => 'not_found' ) );
	}

	private function authorize(): void {
		$state = $this->query( 'state' );
		$return_url = $this->query( 'return_url' );
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $state ) || ! in_array( $return_url, $this->config->allowed_callbacks, true ) ) {
			$this->json( 400, array( 'error' => 'invalid_request' ) );
		}

		$oauth_state = self::base64url( random_bytes( 32 ) );
		$verifier = self::base64url( random_bytes( 64 ) );
		$this->store->put(
			'oauth',
			$oauth_state,
			array( 'wordpress_state' => $state, 'return_url' => $return_url, 'code_verifier' => $verifier ),
			10 * 60
		);

		$query = http_build_query(
			array(
				'client_id'             => $this->config->client_id,
				'redirect_uri'          => $this->config->callback_url,
				'scope'                 => implode( ',', $this->config->scopes ),
				'state'                 => $oauth_state,
				'response_type'         => 'code',
				'code_challenge'        => self::base64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$this->redirect( 'https://www.figma.com/oauth?' . $query );
	}

	private function callback(): void {
		$oauth_state = $this->query( 'state' );
		$code = $this->query( 'code' );
		if ( ! preg_match( '/\A[a-zA-Z0-9_-]{32,128}\z/', $oauth_state ) ) {
			$this->json( 400, array( 'error' => 'invalid_authorization' ) );
		}
		$pending = $this->store->consume( 'oauth', $oauth_state );
		if ( ! $pending ) {
			$this->json( 400, array( 'error' => 'invalid_authorization' ) );
		}
		if ( '' === $code ) {
			$this->return_failure( $pending );
		}

		try {
			$tokens = $this->exchange_code( $code, (string) $pending['code_verifier'] );
			$handoff = self::base64url( random_bytes( 32 ) );
			$this->store->put(
				'handoff',
				$handoff,
				array(
					'wordpress_state' => $pending['wordpress_state'],
					'return_url'      => $pending['return_url'],
					'tokens'          => $tokens,
				),
				60
			);
			$this->redirect( $this->with_query( (string) $pending['return_url'], array( 'state' => $pending['wordpress_state'], 'handoff' => $handoff ) ) );
		} catch ( Throwable $exception ) {
			$this->return_failure( $pending );
		}
	}

	private function exchange(): void {
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$state = is_array( $body ) ? (string) ( $body['state'] ?? '' ) : '';
		$handoff = is_array( $body ) ? (string) ( $body['handoff'] ?? '' ) : '';
		$callback_url = is_array( $body ) ? (string) ( $body['callback_url'] ?? '' ) : '';
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $state ) || ! preg_match( '/\A[a-zA-Z0-9_-]{32,128}\z/', $handoff ) ) {
			$this->json( 400, array( 'error' => 'invalid_handoff' ) );
		}
		$record = $this->store->consume( 'handoff', $handoff );
		if ( ! $record || ! hash_equals( (string) $record['wordpress_state'], $state ) || ! hash_equals( (string) $record['return_url'], $callback_url ) ) {
			$this->json( 400, array( 'error' => 'invalid_handoff' ) );
		}

		$this->json( 200, $record['tokens'], true );
	}

	private function exchange_code( string $code, string $verifier ): array {
		$body = http_build_query(
			array(
				'redirect_uri'  => $this->config->callback_url,
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'code_verifier' => $verifier,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$tokens = $this->post_form( 'https://api.figma.com/v1/oauth/token', $body );
		if ( empty( $tokens['access_token'] ) || ! is_string( $tokens['access_token'] ) ) {
			throw new RuntimeException( 'Figma did not return an access token.' );
		}

		return array(
			'access_token'  => $tokens['access_token'],
			'refresh_token' => isset( $tokens['refresh_token'] ) && is_string( $tokens['refresh_token'] ) ? $tokens['refresh_token'] : '',
			'expires_in'    => isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 0,
			'scopes'        => $this->config->scopes,
		);
	}

	private function post_form( string $url, string $body ): array {
		$curl = curl_init( $url );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/x-www-form-urlencoded',
					'Authorization: Basic ' . base64_encode( $this->config->client_id . ':' . $this->config->client_secret ),
				),
			)
		);
		$response = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_close( $curl );
		if ( false === $response || 200 !== $status ) {
			throw new RuntimeException( 'Figma token exchange failed.' );
		}
		$data = json_decode( $response, true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Figma token response was invalid.' );
		}
		return $data;
	}

	private function query( string $key ): string {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( $_GET[ $key ] ) : '';
	}

	private function return_failure( array $pending ): void {
		$this->redirect( $this->with_query( (string) $pending['return_url'], array( 'state' => $pending['wordpress_state'] ) ) );
	}

	private function with_query( string $url, array $query ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	private static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function redirect( string $url ): void {
		header( 'Cache-Control: no-store' );
		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	private function json( int $status, array $body, bool $no_store = false ): void {
		http_response_code( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( $no_store ) {
			header( 'Cache-Control: no-store' );
		}
		echo json_encode( $body, JSON_UNESCAPED_SLASHES );
		exit;
	}
}
