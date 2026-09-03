<?php

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/src/Broker.php';

try {
	$broker = new Figma_Broker( Figma_Broker_Config::from_environment() );
	$broker->dispatch();
} catch ( Throwable $exception ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( array( 'error' => 'service_unavailable' ) );
}
