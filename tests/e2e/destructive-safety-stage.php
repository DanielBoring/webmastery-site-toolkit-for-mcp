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
$verify = static function ( array $identity, array $expected, array $stale ) use ( $argv ): array {
	return wstm116_converge_boot(
		static function ( float $timeout ) use ( $argv ): array {
			$response = wp_remote_get( 'http://localhost/wp-json/wstm116/boot', array(
				'headers' => array( 'X-WSTM116-Stage' => $argv[2] ), 'timeout' => $timeout, 'redirection' => 0,
			) );
			return is_wp_error( $response ) ? array( 'status' => 0, 'body' => '' )
				: array( 'status' => wp_remote_retrieve_response_code( $response ), 'body' => wp_remote_retrieve_body( $response ) );
		},
		static function ( array $attempt ) use ( $argv ): void {
			echo json_encode( array( 'operation' => $argv[1], 'phase' => 'http-convergence', 'attempt' => $attempt ), JSON_THROW_ON_ERROR ) . "\n";
		},
		$identity, $expected, $stale,
		static function ( int $seconds ): void { sleep( $seconds ); },
		static fn(): float => hrtime( true ) / 1e9
	);
};
$attestation = null;
switch ( $argv[1] ?? '' ) {
	case 'acquire':
		$lifecycle->acquire();
		break;
	case 'prepare':
		$_SERVER['HTTP_HOST'] = 'localhost';
		require_once '/var/www/html/wp-load.php';
		$lifecycle->prepare( wstm116_runtime_configuration() );
		$attestation = $lifecycle->attest( 'original', wstm116_runtime_configuration(), $verify );
		break;
	case 'enabled':
	case 'disabled':
		$lifecycle->configure( $argv[1] );
		$_SERVER['HTTP_HOST'] = 'localhost';
		require_once '/var/www/html/wp-load.php';
		$attestation = $lifecycle->attest( $argv[1], wstm116_runtime_configuration(), $verify );
		break;
	case 'restore':
		$lifecycle->restore();
		if ( $lifecycle->has_original_runtime() ) {
			$_SERVER['HTTP_HOST'] = 'localhost';
			require_once '/var/www/html/wp-load.php';
			$attestation = $lifecycle->attest( 'original', wstm116_runtime_configuration(), $verify );
		}
		$lifecycle->finalize_restore( $attestation );
		break;
	default:
		throw new RuntimeException( 'Unknown stage operation.' );
}
echo json_encode( array(
	'operation' => $argv[1], 'owner' => $argv[2],
	'config_sha256' => hash_file( 'sha256', '/var/www/html/wp-config.php' ),
	'http_fixture_present' => file_exists( '/var/www/html/wp-content/mu-plugins/wstm116-http.php' ),
	'individual_fixture_present' => file_exists( '/var/www/html/wp-content/mu-plugins/wstm116-individual.php' ),
	'http_attestation' => $attestation,
), JSON_THROW_ON_ERROR ) . "\n";
