<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM116_STAGE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Stage requires explicit disposable-project opt-in.' );
}
require_once __DIR__ . '/destructive-safety-lifecycle.php';
$lifecycle = new Wstm116_Lifecycle( '/var/www/html', dirname( __DIR__, 2 ), '/tmp/wstm116-stage', $argv[2] ?? '' );
switch ( $argv[1] ?? '' ) {
	case 'acquire':
		$lifecycle->acquire();
		break;
	case 'enabled':
	case 'disabled':
		$lifecycle->configure( $argv[1] );
		break;
	case 'restore':
		$lifecycle->restore();
		break;
	default:
		throw new RuntimeException( 'Unknown stage operation.' );
}
echo json_encode( array(
	'operation' => $argv[1], 'owner' => $argv[2],
	'config_sha256' => hash_file( 'sha256', '/var/www/html/wp-config.php' ),
	'http_fixture_present' => file_exists( '/var/www/html/wp-content/mu-plugins/wstm116-http.php' ),
	'individual_fixture_present' => file_exists( '/var/www/html/wp-content/mu-plugins/wstm116-individual.php' ),
), JSON_THROW_ON_ERROR ) . "\n";
