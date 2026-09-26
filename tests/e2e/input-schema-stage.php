<?php
/**
 * CLI: operation token source_sha project container_artifact_directory boundaries_csv.
 */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM126_STAGE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set WSTM126_STAGE_DISPOSABLE=1 only in the owned disposable project.' );
}
// Never emit an exception trace: PHP configurations may include secret argv values.
try {
require_once __DIR__ . '/input-schema-lifecycle.php';
Wstm126_Boot::check( 7 === count( $argv ) && in_array( $argv[1], array( 'acquire', 'prepare', 'restore', 'finalize' ), true ), 'Invalid schema stage invocation.' );
$root = '/var/www/html';
$plugin = dirname( __DIR__, 2 );
$lifecycle = new Wstm126_Lifecycle( $root, $plugin, '/tmp/wstm126-stage', $argv[2], $argv[3], $argv[4], $argv[5], explode( ',', $argv[6] ) );
$cli = static function ( bool $baseline ) use ( $root, $plugin, $argv ): array {
	$command = array( PHP_BINARY, __DIR__ . '/input-schema-boot.php', $baseline ? '--baseline' : '--sample', $root, $plugin, $argv[2], $argv[3], $argv[4] );
	$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	Wstm126_Boot::check( is_resource( $process ), 'Cannot start fresh CLI attestation.' );
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	$out = '';
	$error_bytes = 0;
	$deadline = microtime( true ) + 20.0;
	$status = array( 'running' => true, 'exitcode' => -1 );
	try {
		do {
			$out .= stream_get_contents( $pipes[1], 16385 );
			$error_bytes += strlen( stream_get_contents( $pipes[2], 16385 ) );
			Wstm126_Boot::check( strlen( $out ) <= 16384 && $error_bytes <= 16384, 'CLI bootstrap output exceeds bounded size.' );
			$status = proc_get_status( $process );
			Wstm126_Boot::check( microtime( true ) <= $deadline, 'CLI bootstrap deadline exceeded.' );
			if ( $status['running'] ) {
				usleep( 10000 );
			}
		} while ( $status['running'] );
		$out .= stream_get_contents( $pipes[1], 16385 );
		$error_bytes += strlen( stream_get_contents( $pipes[2], 16385 ) );
		Wstm126_Boot::check( 0 === $status['exitcode'] && 0 === $error_bytes && strlen( $out ) <= 16384, 'Fresh CLI bootstrap failed; private output suppressed.' );
	} finally {
		if ( $status['running'] ) {
			proc_terminate( $process );
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
	}
	$body = json_decode( $out, true, 32, JSON_THROW_ON_ERROR );
	Wstm126_Boot::check( is_array( $body ), 'Malformed fresh CLI attestation.' );
	return $body;
};
$http = static function ( array $identity, array $expected, array $stale ) use ( $argv, $lifecycle ): array {
	$probe_key = $lifecycle->probe_key();
	return Wstm126_Boot::converge(
		static function ( float $timeout ) use ( $probe_key ): array {
			Wstm126_Boot::check( function_exists( 'curl_init' ), 'Bounded HTTP attestation requires PHP cURL.' );
			$handle = curl_init( 'http://localhost/wp-json/wstm126-stage/boot' );
			Wstm126_Boot::check( false !== $handle, 'Cannot initialize HTTP attestation.' );
			$body = '';
			curl_setopt_array( $handle, array(
				CURLOPT_HTTPGET => true,
				CURLOPT_HTTPHEADER => array( 'X-WSTM126-Stage: ' . $probe_key, 'Accept: application/json', 'Cache-Control: no-cache' ),
				CURLOPT_TIMEOUT_MS => max( 1, (int) floor( $timeout * 1000 ) ),
				CURLOPT_CONNECTTIMEOUT_MS => max( 1, (int) floor( $timeout * 1000 ) ),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROXY => '',
				CURLOPT_WRITEFUNCTION => static function ( $curl, string $chunk ) use ( &$body ): int {
					$body .= substr( $chunk, 0, max( 0, 16385 - strlen( $body ) ) );
					return strlen( $body ) > 16384 ? 0 : strlen( $chunk );
				},
			) );
			try {
				$result = curl_exec( $handle );
				$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
				// Preserve the bounded wire on errors as well; convergence journals first.
				return array( 'status' => false === $result ? 0 : $status, 'body' => $body );
			} finally {
				curl_close( $handle );
			}
		},
		static function ( array $record ) use ( $argv ): void {
			echo json_encode( array( 'operation' => $argv[1], 'owner' => Wstm126_Boot::owner( $argv[2] ), 'source' => $argv[3], 'project' => $argv[4], 'probe' => $record ), JSON_THROW_ON_ERROR ) . "\n";
		},
		$identity, $expected, $stale,
		static function ( int $seconds ): void { sleep( $seconds ); },
		static fn(): float => hrtime( true ) / 1e9,
		static function ( string $wire, ?int $status, int $attempt ) use ( $lifecycle ): void { $lifecycle->retain_wire( $wire, $status, $attempt ); }
	);
};
switch ( $argv[1] ) {
	case 'acquire':
		$result = $lifecycle->acquire( $cli );
		break;
	case 'prepare':
		$result = $lifecycle->prepare( $cli, $http );
		break;
	case 'restore':
		$result = $lifecycle->restore( $cli, $http );
		break;
	case 'finalize':
		require_once __DIR__ . '/input-schema-proof.php';
		$result = $lifecycle->finalize( $cli, $http, 'wstm126_validate_invocation', 'wstm126_source_hashes' );
		break;
}
echo json_encode( array( 'operation' => $argv[1], 'stage' => $result ), JSON_THROW_ON_ERROR ) . "\n";
} catch ( Throwable $error ) {
	$local = in_array( basename( $error->getFile() ), array( 'input-schema-lifecycle.php', 'input-schema-boot.php', 'input-schema-stage.php' ), true );
	fwrite( STDERR, json_encode( array(
		'operation' => in_array( $argv[1] ?? '', array( 'acquire', 'prepare', 'restore', 'finalize' ), true ) ? $argv[1] : 'invalid',
		'owner' => hash( 'sha256', $argv[2] ?? '' ),
		'error' => $local && $error instanceof RuntimeException ? $error->getMessage() : 'Stage proof failed; private diagnostics suppressed.',
		'retention_required' => true,
	), JSON_THROW_ON_ERROR ) . "\n" );
	exit( 1 );
}
