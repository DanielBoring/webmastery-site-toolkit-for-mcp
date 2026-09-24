<?php
/**
 * Read-only runtime attestation and bounded, non-mutating HTTP convergence.
 */
declare(strict_types=1);

final class Wstm126_Boot {
	public static function owner( string $token ): string {
		return $token;
	}

	public static function runtime(): array {
		$flags = array();
		foreach ( array( 'EMPTY_TRASH_DAYS', 'WSTM116_DISPOSABLE_RUNTIME', 'WSTM116_STAGE_TOKEN', 'WSTM126_DISPOSABLE_RUNTIME', 'WSTM126_STAGE_TOKEN' ) as $name ) {
			$value = defined( $name ) ? constant( $name ) : null;
			if ( defined( $name ) ) {
				self::check( false !== strpos( $name, 'TOKEN' ) ? is_string( $value ) : ( 'EMPTY_TRASH_DAYS' === $name ? is_int( $value ) : is_bool( $value ) ), 'Unexpected runtime flag type; private value suppressed.' );
			}
			$flags[ $name ] = array( 'defined' => defined( $name ), 'value' => false !== strpos( $name, 'TOKEN' ) && null !== $value ? hash( 'sha256', (string) $value ) : $value );
		}
		$functions = array_values( array_filter( get_defined_functions()['user'], static fn( string $name ): bool => 0 === stripos( $name, 'wstm126' ) || 0 === stripos( $name, 'wstm118' ) ) );
		sort( $functions );
		$constants = array();
		foreach ( get_defined_constants() as $name => $value ) {
			if ( 0 === stripos( $name, 'WSTM126' ) && ! in_array( $name, array( 'WSTM126_PROBE_OWNER', 'WSTM126_PROBE_SOURCE', 'WSTM126_PROBE_PROJECT', 'WSTM126_PROBE_KEY_HASH', 'WSTM126_DISPOSABLE_RUNTIME', 'WSTM126_STAGE_TOKEN' ), true ) ) {
				$constants[] = $name;
			}
		}
		$classes = array_values( array_filter( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ), static fn( string $name ): bool => 0 === stripos( $name, 'wstm126' ) && 'Wstm126_Boot' !== $name ) );
		sort( $constants );
		sort( $classes );
		$loaded = array( 'schema' => false, 'error' => false, 'foreign' => false );
		foreach ( get_included_files() as $file ) {
			foreach ( array( 'schema' => 'input-schema-fixture.php', 'error' => 'error-contract-fixture.php' ) as $kind => $name ) {
				if ( $name === basename( $file ) ) {
					$loaded[ $kind ] = true;
					$loaded['foreign'] = $loaded['foreign'] || realpath( __DIR__ . '/' . $name ) !== $file;
				}
			}
		}
		$missing = new stdClass();
		return array( 'flags' => $flags, 'functions' => $functions, 'constants' => $constants, 'classes' => $classes, 'loaded' => $loaded, 'observation_absent' => $missing === get_option( 'wstm126_http', $missing ) );
	}

	public static function baseline( array $runtime ): void {
		self::check( array_keys( $runtime ) === array( 'flags', 'functions', 'constants', 'classes', 'loaded', 'observation_absent' ), 'Malformed original runtime.' );
		self::check( true === $runtime['observation_absent'] && array() === $runtime['functions'] && array() === $runtime['constants'] && array() === $runtime['classes'] && array( 'schema' => false, 'error' => false, 'foreign' => false ) === $runtime['loaded'], 'Existing schema namespace, loader or observation collision.' );
		foreach ( array( 'WSTM126_DISPOSABLE_RUNTIME', 'WSTM126_STAGE_TOKEN' ) as $name ) {
			self::check( array( 'defined' => false, 'value' => null ) === ( $runtime['flags'][ $name ] ?? null ), 'Existing schema constant collision.' );
		}
	}

	public static function active( array $runtime, array $original, string $token ): void {
		self::check( $runtime === self::active_runtime( $original, $token ), 'Active runtime is not the exact owned schema transition.' );
	}

	public static function active_runtime( array $original, string $token ): array {
		$expected = $original;
		$expected['flags']['WSTM126_DISPOSABLE_RUNTIME'] = array( 'defined' => true, 'value' => true );
		$expected['flags']['WSTM126_STAGE_TOKEN'] = array( 'defined' => true, 'value' => hash( 'sha256', $token ) );
		$expected['functions'] = array( 'wstm118_foreign_result', 'wstm118_permission', 'wstm118_probe', 'wstm126_assert_no_work', 'wstm126_begin', 'wstm126_end', 'wstm126_require' );
		$expected['loaded'] = array( 'schema' => true, 'error' => true, 'foreign' => false );
		sort( $expected['functions'] );
		return $expected;
	}

	public static function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	public static function hashes( string $plugin ): array {
		$result = array();
		foreach ( array( 'includes/class-ability.php', 'includes/class-input.php', 'includes/class-response.php', 'tests/e2e/input-schema-fixture.php', 'tests/e2e/error-contract-fixture.php', 'tests/e2e/metadata-batch-fixture.php', 'tests/e2e/input-schema-boot.php', 'tests/e2e/input-schema-probe.php' ) as $relative ) {
			$path = $plugin . '/' . $relative;
			self::check( is_file( $path ) && ! is_link( $path ), 'Missing or aliased mounted source.' );
			self::check( str_replace( '\\', '/', (string) realpath( $path ) ) === str_replace( '\\', '/', $path ), 'Mounted source path alias.' );
			$result[ $relative ] = hash_file( 'sha256', $path );
		}
		return $result;
	}

	public static function body( string $root, string $plugin, string $token, string $source, string $project ): array {
		self::check( function_exists( 'wp_get_abilities' ), 'Required native abilities runtime unavailable.' );
		wp_get_abilities();
		foreach ( array( 'Webmastery_MCP_Ability' => 'class-ability.php', 'Webmastery_MCP_Input' => 'class-input.php', 'Webmastery_MCP_Response' => 'class-response.php' ) as $class => $file ) {
			self::check( class_exists( $class ) && realpath( $plugin . '/includes/' . $file ) === realpath( (string) ( new ReflectionClass( $class ) )->getFileName() ), 'Runtime class is not loaded from the mounted production root.' );
		}
		return array(
			'identity' => array( 'owner' => self::owner( $token ), 'source' => $source, 'project' => $project, 'root' => realpath( $root ), 'plugin_root' => realpath( $plugin ), 'harness_root' => realpath( __DIR__ ), 'config_sha256' => hash_file( 'sha256', $root . '/wp-config.php' ), 'hashes' => self::hashes( $plugin ), 'uid' => function_exists( 'posix_geteuid' ) ? posix_geteuid() : 0 ),
			'runtime' => self::runtime(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
		);
	}

	public static function validate( array $body, array $identity, bool $cli = false ): void {
		self::check( array_keys( $body ) === array( 'identity', 'runtime', 'php', 'sapi' ) && is_array( $body['identity'] ) && is_array( $body['runtime'] ) && is_string( $body['php'] ) && 1 === preg_match( '/^[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,3}(?:(?:alpha|beta|RC)[0-9]{1,3}|-dev)?$/D', $body['php'] ), 'Malformed boot attestation.' );
		self::check( $cli ? 'cli' === $body['sapi'] : in_array( $body['sapi'], array( 'apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'cli-server' ), true ), 'Wrong boot SAPI.' );
		self::check( array_keys( $body['identity'] ) === array( 'owner', 'source', 'project', 'root', 'plugin_root', 'harness_root', 'config_sha256', 'hashes', 'uid' ) && is_int( $body['identity']['uid'] ) && $body['identity']['uid'] >= 0, 'Malformed boot identity or UID.' );
		foreach ( $identity as $name => $value ) {
			self::check( array_key_exists( $name, $body['identity'] ) && $value === $body['identity'][ $name ], 'Foreign boot provenance: ' . $name );
		}
	}

	/**
	 * Preserve exact bounded wire privately, then log only its digest before parsing.
	 * An independently validated fixed-schema body can subsequently be retained.
	 */
	public static function converge( callable $request, callable $journal, array $identity, array $expected, array $stale, callable $sleep, callable $clock, callable $private_wire ): array {
		$deadline = $clock() + 14.0;
		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			self::check( $clock() < $deadline, 'HTTP boot finite budget exhausted.' );
			try {
				$response = $request( min( 2.0, $deadline - $clock() ) );
			} catch ( Throwable $error ) {
				$private_wire( '', null, $attempt );
				$journal( array( 'attempt' => $attempt, 'status' => null, 'transport_error' => true ) );
				throw new RuntimeException( 'HTTP boot transport failed; no retry.' );
			}
			$wire = is_string( $response['body'] ?? null ) ? $response['body'] : '';
			$status = is_int( $response['status'] ?? null ) ? $response['status'] : null;
			$private_wire( substr( $wire, 0, 16385 ), $status, $attempt );
			$journal( array( 'attempt' => $attempt, 'status' => $status, 'body_bytes' => strlen( $wire ), 'body_sha256' => hash( 'sha256', $wire ) ) );
			self::check( 200 === $status && strlen( $wire ) <= 16384, 'HTTP boot denied, oversized or failed; no retry.' );
			$body = json_decode( $wire, true, 32, JSON_THROW_ON_ERROR );
			self::check( is_array( $body ), 'Malformed HTTP boot body.' );
			self::validate( $body, $identity );
			self::check( $clock() <= $deadline, 'HTTP boot finite budget exhausted.' );
			$matches = $body['runtime'] === $expected;
			self::check( $matches || in_array( $body['runtime'], $stale, true ), 'Foreign or unrecognized boot state; no retry.' );
			// All identity and runtime fields now match a caller-owned fixed schema.
			$journal( array( 'attempt' => $attempt, 'safe_body' => $body ) );
			if ( $matches ) {
				return $body;
			}
			self::check( $attempt < 5 && $deadline - $clock() > 1.0, 'HTTP boot stayed stale within finite budget.' );
			$sleep( 1 );
		}
		throw new RuntimeException( 'HTTP boot convergence failed.' );
	}

	public static function authorized( string $method, $header, string $key_hash ): bool {
		return 'GET' === $method && is_string( $header ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $header )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $key_hash ) && hash_equals( $key_hash, hash( 'sha256', $header ) );
	}
}

// Direct execution is the fresh-process CLI runtime sampler; inclusion is inert.
if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	if ( 'cli' !== PHP_SAPI ) {
		http_response_code( 403 );
		exit( 'CLI only.' );
	}
	Wstm126_Boot::check( '1' === getenv( 'WSTM126_STAGE_DISPOSABLE' ), 'Explicit stage opt-in required before WordPress.' );
	Wstm126_Boot::check( 7 === count( $argv ) && in_array( $argv[1], array( '--sample', '--baseline' ), true ), 'Invalid sampler arguments.' );
	$_SERVER['HTTP_HOST'] = 'localhost';
	ob_start();
	require $argv[2] . '/wp-load.php';
	$noise = ob_get_clean();
	Wstm126_Boot::check( '' === $noise, 'Unexpected WordPress bootstrap output.' );
	foreach ( array( 'WSTM126_PROBE_OWNER' => $argv[4], 'WSTM126_PROBE_SOURCE' => $argv[5], 'WSTM126_PROBE_PROJECT' => $argv[6] ) as $name => $value ) {
		Wstm126_Boot::check( '--baseline' === $argv[1] ? ! defined( $name ) : defined( $name ) && $value === constant( $name ), 'Foreign probe constant or missing owned probe.' );
	}
	Wstm126_Boot::check( '--baseline' === $argv[1] ? ! defined( 'WSTM126_PROBE_KEY_HASH' ) : defined( 'WSTM126_PROBE_KEY_HASH' ) && is_string( WSTM126_PROBE_KEY_HASH ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', WSTM126_PROBE_KEY_HASH ), 'Foreign or malformed probe authentication verifier.' );
	if ( '--baseline' === $argv[1] ) {
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			Wstm126_Boot::check( 0 !== stripos( $route, '/wstm126' ), 'Existing schema REST probe namespace.' );
		}
		Wstm126_Boot::check( function_exists( 'wp_has_ability' ), 'Required native abilities runtime unavailable.' );
		foreach ( array( 'wstm118-probe', 'wstm118-bad-execute', 'wstm118-bad-permission', 'wstm118-missing-schema' ) as $slug ) {
			Wstm126_Boot::check( ! wp_has_ability( 'webmastery-site-toolkit-for-mcp/' . $slug ), 'Existing schema ability probe collision.' );
		}
	}
	echo json_encode( Wstm126_Boot::body( $argv[2], $argv[3], $argv[4], $argv[5], $argv[6] ), JSON_THROW_ON_ERROR );
}
