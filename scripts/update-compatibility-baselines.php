<?php

declare(strict_types=1);

require_once __DIR__ . '/compatibility-baselines.php';

function webmastery_mcp_replace_baseline( string $path, string $pattern, callable $replacement ): string {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		throw new RuntimeException( "Could not read {$path}." );
	}
	// Count the entire file, not just the first replacement.
	if ( 1 !== preg_match_all( $pattern, $contents ) ) {
		throw new RuntimeException( "Expected exactly one version reference in {$path}." );
	}
	$updated = preg_replace_callback( $pattern, $replacement, $contents );
	if ( null === $updated ) {
		throw new RuntimeException( "Could not update {$path}." );
	}
	return $updated;
}

function webmastery_mcp_update_baselines( string $root, array $options ): bool {
	$versions = $root . '/.github/compatibility-versions.json';
	$config   = webmastery_mcp_read_baselines( $versions );
	$original = $config;
	foreach ( $options as $option => $value ) {
		if ( 'confirmed-wordpress' === $option ) {
			continue;
		}
		$key = str_replace( '-', '_', $option );
		if ( ! in_array( $key, array( 'wordpress', 'mcp_adapter', 'mcp_adapter_sha256', 'wp_cli', 'wp_cli_sha512', 'yoast', 'seopress', 'plugin_check' ), true ) || ! is_string( $value ) ) {
			throw new InvalidArgumentException( "Unknown or invalid option: {$option}" );
		}
		$config[ $key ] = $value;
	}
	foreach ( array( 'mcp_adapter' => 'mcp_adapter_sha256', 'wp_cli' => 'wp_cli_sha512' ) as $version => $digest ) {
		if ( $config[ $version ] !== $original[ $version ] && ! array_key_exists( str_replace( '_', '-', $digest ), $options ) ) {
			throw new InvalidArgumentException( "Changing {$version} requires its new digest." );
		}
	}
	foreach ( array( 'wordpress', 'mcp_adapter', 'wp_cli', 'yoast', 'seopress', 'plugin_check' ) as $key ) {
		if ( ! is_string( $config[ $key ] ) || ! preg_match( '/^\d+\.\d+(?:\.\d+)*$/D', $config[ $key ] ) || version_compare( $config[ $key ], $original[ $key ], '<' ) ) {
			throw new InvalidArgumentException( "Invalid version or downgrade: {$key}" );
		}
	}
	// Validate the complete proposed schema before any write, without staging a partial file.
	$encoded = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	webmastery_mcp_read_baselines( 'data://text/plain;base64,' . base64_encode( $encoded ) );
	$wordpress_changed = $config['wordpress'] !== $original['wordpress'];
	if ( $wordpress_changed && ( $options['confirmed-wordpress'] ?? null ) !== $config['wordpress'] ) {
		throw new InvalidArgumentException( 'WordPress promotion requires --confirmed-wordpress=<tested candidate version>.' );
	}
	$updates = array(
		$versions => $encoded,
		$root . '/docker-compose.yml' => webmastery_mcp_replace_baseline(
			$root . '/docker-compose.yml',
			'#wordpress:\d+\.\d+(?:\.\d+)?(-php\d+\.\d+-apache)#',
			static fn( array $match ): string => 'wordpress:' . $config['wordpress'] . $match[1]
		),
	);
	$readme = $root . '/readme.txt';
	// Validate the header even on an adapter-only update; never silently repair a stale claim.
	$updates[ $readme ] = webmastery_mcp_replace_baseline(
		$readme,
		'/^Tested up to: \d+\.\d+(?:\.\d+)?[ \t]*(\r?)$/m',
		static fn( array $match ): string => $wordpress_changed
			? 'Tested up to: ' . implode( '.', array_slice( explode( '.', $config['wordpress'] ), 0, 2 ) ) . $match[1]
			: $match[0]
	);
	$changed = false;
	foreach ( $updates as $path => $contents ) {
		if ( file_get_contents( $path ) === $contents ) {
			continue;
		}
		if ( ! is_writable( $path ) ) {
			throw new RuntimeException( "Cannot write {$path}." );
		}
	}
	foreach ( $updates as $path => $contents ) {
		if ( file_get_contents( $path ) === $contents ) {
			continue;
		}
		if ( strlen( $contents ) !== file_put_contents( $path, $contents, LOCK_EX ) ) {
			throw new RuntimeException( "Could not completely write {$path}." );
		}
		$changed = true;
	}
	return $changed;
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		$options = array();
		foreach ( array_slice( $argv, 1 ) as $argument ) {
			if ( ! preg_match( '/^--([a-z-]+)=(.+)$/D', $argument, $match ) || isset( $options[ $match[1] ] ) ) {
				throw new InvalidArgumentException( 'Expected unique --name=value options.' );
			}
			$options[ $match[1] ] = $match[2];
		}
		if ( ! isset( $options['wordpress'], $options['mcp-adapter'] ) ) {
			throw new InvalidArgumentException( 'Required: --wordpress=X.Y[.Z] --mcp-adapter=X.Y.Z; changed versions also require their digests/confirmation.' );
		}
		echo webmastery_mcp_update_baselines( dirname( __DIR__ ), $options ) ? "Updated compatibility baselines.\n" : "Compatibility baselines unchanged.\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
