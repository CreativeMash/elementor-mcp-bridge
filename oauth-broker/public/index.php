<?php

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/src/Broker.php';

try {
	$config_file = dirname( __DIR__ ) . '/config.php';
	if ( is_file( $config_file ) ) {
		$values = require $config_file;
		if ( ! is_array( $values ) ) {
			throw new RuntimeException( 'Broker configuration must return an array.' );
		}
		foreach ( $values as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) ) {
				putenv( $name . '=' . $value );
			}
		}
	}
	$broker = new Figma_Broker( Figma_Broker_Config::from_environment() );
	$broker->dispatch();
} catch ( Throwable $exception ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( array( 'error' => 'service_unavailable' ) );
}
