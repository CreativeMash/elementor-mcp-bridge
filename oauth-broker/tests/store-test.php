<?php

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/src/Broker.php';

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$directory = sys_get_temp_dir() . '/elementor-figma-broker-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700, true );

$config = new Figma_Broker_Config();
$config->state_dir = $directory;
$config->record_key = str_repeat( 'k', 32 );
$store = new Figma_Broker_Store( $config );
$id = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
$record = array( 'wordpress_state' => str_repeat( 'a', 64 ), 'access_token' => 'must-not-be-plaintext' );

$store->put( 'handoff', $id, $record, 60 );
$files = glob( $directory . '/handoff-*' );
expect( 1 === count( $files ), 'Expected one encrypted handoff record.' );
expect( false === str_contains( (string) file_get_contents( $files[0] ), 'must-not-be-plaintext' ), 'Authorization data was stored in plaintext.' );
expect( $record === array_diff_key( $store->consume( 'handoff', $id ) ?? array(), array( 'expires_at' => true ) ), 'The handoff record was not recovered.' );
expect( null === $store->consume( 'handoff', $id ), 'A handoff was consumed more than once.' );
rmdir( $directory );

echo "Broker store test passed\n";
