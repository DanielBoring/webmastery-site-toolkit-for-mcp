<?php

declare(strict_types=1);

require_once __DIR__ . '/compatibility-baselines.php';

function webmastery_mcp_verify_candidate_floor( array $runtime, array $pins ): void {
	$source = $runtime['requested_source'] ?? null;
	if ( ! is_string( $source ) || 1 !== preg_match( '/^[0-9a-f]{40}$/D', $source ) || ( $runtime['actual_source'] ?? null ) !== $source ) {
		throw new RuntimeException( 'Candidate checkout does not match the exact requested commit.' );
	}
	foreach ( array( 'mounted_source_exit', 'checkout_diff_exit' ) as $key ) {
		if ( ( $runtime[ $key ] ?? null ) !== 0 ) {
			throw new RuntimeException( 'Candidate source verification failed or is missing: ' . $key );
		}
	}
	$loaded = $runtime['loaded_source'] ?? null;
	if ( ! is_array( $loaded ) || ( $loaded['entrypoint_included'] ?? null ) !== true ||
		( $loaded['response_file'] ?? null ) !== '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/includes/class-response.php' ) {
		throw new RuntimeException( 'WordPress did not load the candidate toolkit source.' );
	}
	foreach ( array( 'wordpress' => '/^6\.9(?:\.\d+)?$/D', 'php' => '/^8\.1\.\d+$/D', 'mysql_server' => '/^8\.0\.36$/D' ) as $key => $pattern ) {
		if ( ! isset( $runtime[ $key ] ) || ! is_string( $runtime[ $key ] ) || 1 !== preg_match( $pattern, $runtime[ $key ] ) ) {
			throw new RuntimeException( 'Observed runtime is not the supported floor: ' . $key );
		}
	}
	if ( ( $runtime['dependency_policy'] ?? null ) !== 'pinned' || ( $runtime['wp_cli'] ?? null ) !== 'WP-CLI ' . $pins['wp_cli'] ) {
		throw new RuntimeException( 'Runtime must use the candidate-pinned dependency policy and WP-CLI.' );
	}
	$plugins = $runtime['plugins'] ?? null;
	if ( ! is_array( $plugins ) || array_values( $plugins ) !== $plugins ) {
		throw new RuntimeException( 'Missing or malformed observed plugin inventory.' );
	}
	$required = array(
		'webmastery-site-toolkit-for-mcp' => null,
		'mcp-adapter'                    => $pins['mcp_adapter'],
		'wordpress-seo'                  => $pins['yoast'],
		'wp-seopress'                    => $pins['seopress'],
	);
	foreach ( $required as $name => $version ) {
		$matches = array_values( array_filter( $plugins, static fn( $plugin ): bool => is_array( $plugin ) && ( $plugin['name'] ?? null ) === $name ) );
		if ( count( $matches ) !== 1 || ( $matches[0]['status'] ?? null ) !== 'active' ||
			( null !== $version && ( $matches[0]['version'] ?? null ) !== $version ) ) {
			throw new RuntimeException( 'Required active candidate-pinned plugin mismatch: ' . $name );
		}
	}
	if ( ( $runtime['qa_result'] ?? null ) !== 'success' ) {
		throw new RuntimeException( 'Full candidate E2E did not succeed; runtime observations alone are not floor acceptance.' );
	}
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		if ( count( $argv ) !== 3 ) {
			throw new InvalidArgumentException( 'Usage: php scripts/verify-candidate-floor.php <runtime.json> <candidate-pins.json>' );
		}
		$contents = file_get_contents( $argv[1] );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read candidate runtime evidence.' );
		}
		$document = json_decode( $contents, false, 512, JSON_THROW_ON_ERROR );
		if ( ! $document instanceof stdClass ) {
			throw new RuntimeException( 'Runtime evidence must be a JSON object.' );
		}
		$runtime = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		webmastery_mcp_verify_candidate_floor( $runtime, webmastery_mcp_read_baselines( $argv[2] ) );
		echo "Exact candidate supported-floor runtime and full E2E verified; cleanup outcome remains separate.\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
