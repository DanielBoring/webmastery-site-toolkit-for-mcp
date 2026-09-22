<?php

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'CLI only.' );
}
if ( '1' !== getenv( 'WSTM108_STAGE_DISPOSABLE' ) ) {
	fwrite( STDERR, "Refusing: WSTM108_STAGE_DISPOSABLE=1 is required before WordPress or stage writes.\n" );
	exit( 2 );
}

require_once __DIR__ . '/untrusted-content-evidence.php';
require_once __DIR__ . '/untrusted-content-lifecycle.php';
require_once __DIR__ . '/untrusted-content-provenance.php';
require_once __DIR__ . '/untrusted-content-convergence.php';
require_once __DIR__ . '/untrusted-content-boot.php';
require_once __DIR__ . '/untrusted-content-proof.php';

$operation = $argv[1] ?? '';
if ( ! in_array( $operation, array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize' ), true ) ) {
	throw new RuntimeException( 'Unknown owned untrusted-stage operation.' );
}
$context_path = $argv[2] ?? '';
$context_file = Wstm108_Files::file( $context_path );
$context = json_decode( $context_file['bytes'], true, 512, JSON_THROW_ON_ERROR );
$directory = dirname( $context_path );
$evidence = new Wstm108_Evidence( $directory . '/' . $operation . '.json' );
$plugin = str_replace( '\\', '/', realpath( dirname( __DIR__, 2 ) ) );
$lifecycle = new Wstm108_Lifecycle( '/var/www/html', $plugin, '/tmp/wstm108-stage', $context['binding'] );
$summary = array( 'operation' => $operation, 'binding' => $context['binding'], 'stage_context_sha256' => $context_file['sha256'], 'completed' => false );
$bootstrap = static function () use ( $plugin, $context ): array {
	$_SERVER['HTTP_HOST'] = 'localhost';
	require_once '/var/www/html/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	Wstm108_Provenance::loaded_root( $plugin );
	$pins = json_decode( file_get_contents( $plugin . '/.github/compatibility-versions.json' ), true, 512, JSON_THROW_ON_ERROR );
	$versions = array();
	foreach ( array( 'mcp_adapter' => 'mcp-adapter/mcp-adapter.php', 'yoast' => 'wordpress-seo/wp-seo.php', 'seopress' => 'wp-seopress/seopress.php' ) as $name => $path ) {
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $path, false, false );
		if ( ! is_plugin_active( $path ) || ! is_string( $data['Version'] ?? null ) || '' === $data['Version']
			|| ( ( 'mcp_adapter' === $name || 'pinned' === $context['dependency_policy'] ) && $pins[ $name ] !== $data['Version'] ) ) {
			throw new RuntimeException( 'Owned proof requires the actual selected active dependency: ' . $name );
		}
		$versions[ $name ] = array( 'path' => $path, 'version' => $data['Version'], 'active' => true );
	}
	return $versions;
};
$verify = static function ( array $identity, array $expected, array $stale ) use ( $lifecycle, $evidence, $operation ): array {
	$secret = $lifecycle->secret();
	$evidence->secret( $secret );
	return wstm108_converge(
		static function ( float $timeout ) use ( $secret ): array {
			$response = wp_remote_get( 'http://localhost/wp-json/wstm108/boot', array(
				'headers' => array( 'X-WSTM108-Stage' => $secret ), 'timeout' => $timeout, 'redirection' => 0,
			) );
			return is_wp_error( $response )
				? array( 'status' => 0, 'body' => $response->get_error_message(), 'transport_error' => true )
				: array( 'status' => wp_remote_retrieve_response_code( $response ), 'body' => wp_remote_retrieve_body( $response ) );
		},
		$evidence, $operation, $identity, $expected, $stale,
		static function ( int $seconds ): void { sleep( $seconds ); },
		static fn(): float => hrtime( true ) / 1e9
	);
};
try {
	$summary['actual_files'] = Wstm108_Provenance::verify( $plugin, $context['files'] );
	if ( 'acquire' === $operation ) {
		$lifecycle->acquire();
	}
	$lifecycle->bind_context( $context, $context_file['sha256'] );
	if ( 'original' === $operation ) {
		$summary['providers'] = $bootstrap();
		if ( defined( 'WSTM108_PROBE_OWNER' ) || defined( 'WSTM108_PROBE_SECRET' ) ) {
			throw new RuntimeException( 'Preexisting probe constants; no owned loader or credentials may be installed.' );
		}
		global $wpdb;
		$options = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'wstm108' ) . '%', $wpdb->esc_like( '_transient_wstm108' ) . '%', $wpdb->esc_like( '_transient_timeout_wstm108' ) . '%'
		) );
		if ( '' !== $wpdb->last_error || array() !== $options ) {
			throw new RuntimeException( 'Preexisting or unreadable stage-option namespace; no fixtures or credentials may be created.' );
		}
		$summary['stage_options_absent'] = true;
		$original = wstm108_runtime_snapshot();
		$names = array_values( array_filter( array_keys( $original['abilities'] ), static fn( $name ) => 0 === strpos( $name, 'webmastery-site-toolkit-for-mcp/' ) ) );
		if ( Wstm108_Plan::native_from_manifest( __DIR__ . '/abilities-manifest.json' ) !== $names ) {
			throw new RuntimeException( 'Native registration differs before any owned test fixture or credential.' );
		}
		foreach ( array( 'untrusted-content-stage.php' => 'WSTM108_STAGE_DISPOSABLE', 'untrusted-content-runner.php' => 'WSTM108_ALLOW_DISPOSABLE' ) as $file => $flag ) {
			$environment = getenv();
			unset( $environment[ $flag ] );
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/' . $file ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $environment );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Cannot execute the actual missing-opt-in CLI control.' );
			}
			fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$status = proc_close( $process );
			$evidence->append( array( 'boundary' => 'actual-cli-missing-optin', 'file' => $file, 'status' => $status, 'body' => $out, 'stderr' => $error ) );
			if ( 2 !== $status || '' !== $out || false === strpos( $error, $flag . '=1 is required before WordPress' ) ) {
				throw new RuntimeException( 'Actual missing-opt-in CLI boundary did not exit 2 before bootstrap.' );
			}
			$response = wp_remote_get( 'http://localhost/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/' . $file, array( 'timeout' => 2, 'redirection' => 0 ) );
			$status = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
			$evidence->append( array( 'boundary' => 'actual-http-cli-only', 'file' => $file, 'status' => $status, 'body' => $body ) );
			if ( 403 !== $status || 'CLI only.' !== $body ) {
				throw new RuntimeException( 'Actual HTTP boundary did not return exact 403 CLI only.' );
			}
		}
		$lifecycle->install_probe( $original );
		$lifecycle->attest( 'original', $original, $verify );
	}
	if ( 'enable' === $operation ) {
		$lifecycle->enable();
	}
	if ( 'enabled' === $operation ) {
		$summary['providers'] = $bootstrap();
		$lifecycle->attest( 'enabled', wstm108_runtime_snapshot(), $verify );
	}
	if ( 'restored' === $operation || 'finalize' === $operation ) {
		$lifecycle->restore_loader();
		$summary['providers'] = $bootstrap();
		$lifecycle->attest( 'restored', wstm108_runtime_snapshot(), $verify );
	}
	$summary['state'] = $lifecycle->public_state();
	if ( 'finalize' === $operation ) {
		$runner_file = Wstm108_Files::file( $directory . '/runner.json' );
		$runner = json_decode( $runner_file['bytes'], false, 512, JSON_THROW_ON_ERROR );
		if ( ! $runner instanceof stdClass ) {
			throw new RuntimeException( 'Missing original runner proof object.' );
		}
		$certificate = Wstm108_Proof::runner( $runner, $context, $context_file['sha256'], Wstm108_Plan::native_from_manifest( __DIR__ . '/abilities-manifest.json' ) );
		Wstm108_Proof::journal( $directory . '/runner.json.http.jsonl', $runner );
		$resources = Wstm108_Resources::resume( '/tmp/wstm108-stage/resources.json', $context['binding'] );
		$lifecycle->preflight_finalization( $certificate );
		$resources->retire( $certificate->receipt()['resource_proof'] );
		$lifecycle->finalize( $certificate );
		$summary['retired'] = array( 'runtime_loader' => true, 'probe' => true, 'resource_journal' => true, 'private_lock' => true );
	}
	$summary['completed'] = true;
	$evidence->save( $summary );
	echo 'WSTM108 stage ' . $operation . " completed with owned evidence.\n";
} catch ( Throwable $error ) {
	$summary['error'] = $error->getMessage();
	$evidence->save( $summary );
	fwrite( STDERR, 'WSTM108 stage retained: ' . $evidence->redact( $error->getMessage() ) . "\n" );
	exit( 1 );
}
