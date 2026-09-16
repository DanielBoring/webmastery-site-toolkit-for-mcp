<?php

declare(strict_types=1);

function webmastery_mcp_read_baselines( string $path ): array {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( "Could not read compatibility baselines: {$path}" );
	}
	$config = json_decode( $contents, false, 512, JSON_THROW_ON_ERROR );
	if ( ! $config instanceof stdClass ) {
		throw new RuntimeException( 'Compatibility baselines must be a JSON object.' );
	}
	$config = get_object_vars( $config );
	$patterns = array(
		'wordpress'          => '/^\d+\.\d+(?:\.\d+)?$/D',
		'mcp_adapter'        => '/^\d+\.\d+\.\d+$/D',
		'mcp_adapter_sha256' => '/^[a-f0-9]{64}$/D',
		'wp_cli'             => '/^\d+\.\d+\.\d+$/D',
		'wp_cli_sha512'      => '/^[a-f0-9]{128}$/D',
		'yoast'              => '/^\d+\.\d+(?:\.\d+)*$/D',
		'seopress'           => '/^\d+\.\d+(?:\.\d+)*$/D',
		'plugin_check'       => '/^\d+\.\d+(?:\.\d+)*$/D',
	);
	foreach ( $patterns as $key => $pattern ) {
		if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) || ! preg_match( $pattern, $config[ $key ] ) ) {
			throw new RuntimeException( "Missing or invalid compatibility baseline: {$key}" );
		}
	}
	return $config;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		$config = webmastery_mcp_read_baselines( $argv[2] ?? dirname( __DIR__ ) . '/.github/compatibility-versions.json' );
		$key    = $argv[1] ?? '';
		if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) ) {
			throw new RuntimeException( 'Usage: php scripts/compatibility-baselines.php <key> [path]' );
		}
		echo $config[ $key ] . PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
