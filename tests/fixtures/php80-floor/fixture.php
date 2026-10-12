<?php

declare(strict_types=1);

require_once dirname( __DIR__, 3 ) . '/scripts/verify-candidate-floor.php';

function wstm_php80_floor_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wstm_php80_floor_path( mixed $path ): void {
	wstm_php80_floor_require(
		is_string( $path ) && 1 === preg_match( '#\A/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z#D', $path )
		&& ! in_array( '.', explode( '/', $path ), true ) && ! in_array( '..', explode( '/', $path ), true ),
		'An explicit canonical native Linux path is required.'
	);
}

function wstm_php80_floor_config( array $config ): array {
	$keys = array(
		'profile', 'php_base_image', 'php_binary_sha256', 'wordpress_version', 'wordpress_archive_sha256',
		'mysql_image', 'runtime_image', 'candidate_sha', 'candidate_tree', 'candidate_root',
		'project', 'http_port', 'artifact_directory', 'wp_config', 'wp_config_sha256',
		'mysql_env_file', 'mysql_env_sha256', 'dependency_policy',
	);
	$actual = array_keys( $config );
	sort( $actual );
	sort( $keys );
	wstm_php80_floor_require( $actual === $keys, 'Missing or extra floor fixture inputs.' );
	wstm_php80_floor_require( 'php80-floor' === $config['profile'] && 'pinned' === $config['dependency_policy'], 'Exact floor profile and pinned dependency policy required.' );
	foreach ( array(
		'php_base_image' => '#\A(?:docker\.io/library/)?php:8\.0\.30-cli@sha256:[a-f0-9]{64}\z#D',
		'mysql_image' => '#\Amysql:8\.0\.36@sha256:[a-f0-9]{64}\z#D',
		'wordpress_version' => '/\A6\.9(?:\.[0-9]+)?\z/D',
		'candidate_sha' => '/\A[a-f0-9]{40}\z/D',
		'candidate_tree' => '/\A[a-f0-9]{40}\z/D',
		'project' => '/\Awstm-php80-[a-z0-9][a-z0-9_-]{0,90}\z/D',
		'artifact_directory' => '/\A[a-zA-Z0-9_-]{1,64}\z/D',
	) as $key => $pattern ) {
		wstm_php80_floor_require( is_string( $config[ $key ] ) && 1 === preg_match( $pattern, $config[ $key ] ), 'Invalid exact fixture input: ' . $key );
	}
	foreach ( array( 'php_binary_sha256', 'wordpress_archive_sha256', 'wp_config_sha256', 'mysql_env_sha256' ) as $key ) {
		wstm_php80_floor_require( is_string( $config[ $key ] ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $config[ $key ] ), 'Missing reviewed original digest: ' . $key );
	}
	wstm_php80_floor_require( null === $config['runtime_image'] || ( is_string( $config['runtime_image'] ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $config['runtime_image'] ) ), 'Runtime selection requires the actual built immutable image ID, not a tag.' );
	wstm_php80_floor_require( is_int( $config['http_port'] ) && $config['http_port'] >= 1024 && $config['http_port'] <= 65535, 'Explicit unprivileged loopback port required.' );
	foreach ( array( 'candidate_root', 'wp_config', 'mysql_env_file' ) as $key ) {
		wstm_php80_floor_path( $config[ $key ] );
	}
	wstm_php80_floor_require( $config['wp_config'] !== $config['mysql_env_file'], 'Private configuration originals must be distinct.' );
	foreach ( array( 'wp_config', 'mysql_env_file' ) as $key ) {
		wstm_php80_floor_require( $config[ $key ] !== $config['candidate_root']
			&& ! str_starts_with( $config[ $key ], $config['candidate_root'] . '/' ), 'Private originals must be outside the candidate build context.' );
	}
	return $config;
}

function wstm_php80_floor_probe(): array {
	wstm_php80_floor_require( 'Linux' === PHP_OS_FAMILY && '8.0.30' === PHP_VERSION && in_array( PHP_SAPI, array( 'cli', 'cli-server' ), true )
		&& extension_loaded( 'mysqli' ), 'Actual PHP 8.0.30 CLI/server with mysqli is required.' );
	$hash = hash_file( 'sha256', PHP_BINARY );
	wstm_php80_floor_require( is_string( $hash ), 'Cannot observe the executing PHP ELF.' );
	return array( 'profile' => 'php80-floor', 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'php_binary_sha256' => $hash );
}

function wstm_php80_floor_pair( array $config, array $cli, array $http ): void {
	wstm_php80_floor_config( $config );
	foreach ( array( 'cli' => $cli, 'cli-server' => $http ) as $sapi => $record ) {
		wstm_php80_floor_require( array_keys( $record ) === array( 'profile', 'php', 'sapi', 'php_binary_sha256' )
			&& 'php80-floor' === $record['profile'] && '8.0.30' === $record['php'] && $sapi === $record['sapi']
			&& $config['php_binary_sha256'] === $record['php_binary_sha256'], 'Original CLI/HTTP version, SAPI or executing ELF differs.' );
	}
}

function wstm_php80_floor_verify( array $config, array $runtime, array $pins, array $cli, array $http ): void {
	wstm_php80_floor_pair( $config, $cli, $http );
	wstm_php80_floor_require( null !== $config['runtime_image'], 'Select the actual built immutable runtime image before verification.' );
	wstm_php80_floor_require( ( $runtime['requested_source'] ?? null ) === $config['candidate_sha']
		&& ( $runtime['source_tree'] ?? null ) === $config['candidate_tree']
		&& ( $runtime['wordpress'] ?? null ) === $config['wordpress_version'] && ( $runtime['php'] ?? null ) === $cli['php'], 'Candidate, core archive version or WordPress CLI observation differs.' );
	webmastery_mcp_verify_candidate_floor( $runtime, $pins, 'php80-floor' );
}

function wstm_php80_floor_plan( array $config ): array {
	$config = wstm_php80_floor_config( $config );
	$directory = $config['candidate_root'] . '/tests/fixtures/php80-floor';
	$build = array( 'docker', 'build', '--file', $directory . '/Dockerfile' );
	foreach ( array(
		'PHP80_BASE_IMAGE' => $config['php_base_image'], 'PHP80_BINARY_SHA256' => $config['php_binary_sha256'],
		'WP_CORE_VERSION' => $config['wordpress_version'], 'WP_CORE_SHA256' => $config['wordpress_archive_sha256'],
	) as $key => $value ) {
		$build[] = '--build-arg';
		$build[] = $key . '=' . $value;
	}
	$build[] = '--tag';
	$build[] = 'wstm-php80-floor:' . $config['candidate_sha'];
	$build[] = $config['candidate_root'];
	$environment = array(
		'PHP80_RUNTIME_IMAGE' => $config['runtime_image'], 'PHP80_MYSQL_IMAGE' => $config['mysql_image'],
		'PHP80_CANDIDATE_ROOT' => $config['candidate_root'], 'PHP80_HTTP_PORT' => (string) $config['http_port'],
		'PHP80_WP_CONFIG' => $config['wp_config'], 'PHP80_WP_CONFIG_SHA256' => $config['wp_config_sha256'],
		'PHP80_MYSQL_ENV_FILE' => $config['mysql_env_file'], 'DEPENDENCY_POLICY' => 'pinned',
		'COMPOSE_PROJECT_NAME' => $config['project'], 'E2E_ARTIFACTS_DIR' => $config['artifact_directory'],
		'E2E_MANAGE_COMPOSE' => '0',
		'WORDPRESS_URL' => 'http://127.0.0.1:' . $config['http_port'],
	);
	return array(
		'profile' => 'php80-floor', 'authorization' => 'not_supplied', 'executes_commands' => false,
		'build_argv' => $build,
		'compose_argv' => null === $config['runtime_image'] ? null : array( 'docker', 'compose', '--project-name', $config['project'], '--file', $directory . '/compose.yml' ),
		'environment' => $environment,
		'qa_mode' => 'all',
		'harness_routing_required' => 'Use the pinned explicit php80-floor selector for shared Compose and admitted discovery; separate admission, fixture grants and original capture remain required.',
	);
}

function wstm_php80_floor_private_inputs( array $config ): void {
	wstm_php80_floor_config( $config );
	wstm_php80_floor_require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' ), 'Native Linux input verification required.' );
	wstm_php80_floor_require( $config['candidate_root'] === realpath( $config['candidate_root'] )
		&& is_dir( $config['candidate_root'] ), 'Candidate build context must be an existing canonical native directory.' );
	foreach ( array( 'wp_config' => 'wp_config_sha256', 'mysql_env_file' => 'mysql_env_sha256' ) as $key => $hash_key ) {
		$path = $config[ $key ];
		$stat = lstat( $path );
		wstm_php80_floor_require( is_array( $stat ) && $path === realpath( $path ) && ! is_link( $path )
			&& 0100000 === ( $stat['mode'] & 0170000 ) && 1 === $stat['nlink']
			&& posix_geteuid() === $stat['uid'] && 0 === ( $stat['mode'] & 0077 )
			&& $stat['size'] <= 1048576 && hash_file( 'sha256', $path ) === $config[ $hash_key ], 'Private input ownership, shape or original bytes differ.' );
	}
}

function wstm_php80_floor_read( string $path ): array {
	$data = file_get_contents( $path, false, null, 0, 1048577 );
	wstm_php80_floor_require( is_string( $data ) && strlen( $data ) <= 1048576, 'Missing or oversized original JSON.' );
	$object = json_decode( $data, false, 64, JSON_THROW_ON_ERROR );
	wstm_php80_floor_require( $object instanceof stdClass, 'Original JSON must be an object.' );
	return json_decode( $data, true, 64, JSON_THROW_ON_ERROR );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		$mode = $argv[1] ?? '';
		if ( 'probe' === $mode && 2 === count( $argv ) ) {
			echo json_encode( wstm_php80_floor_probe(), JSON_THROW_ON_ERROR ) . "\n";
		} elseif ( in_array( $mode, array( 'plan', 'verify-inputs' ), true ) && 3 === count( $argv ) ) {
			$config = wstm_php80_floor_read( $argv[2] );
			if ( 'plan' === $mode ) {
				echo json_encode( wstm_php80_floor_plan( $config ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n";
			} else {
				wstm_php80_floor_private_inputs( $config );
				echo "Private input byte checks passed; admission and execution grants remain separate.\n";
			}
		} elseif ( 'verify' === $mode && 7 === count( $argv ) ) {
			wstm_php80_floor_verify( wstm_php80_floor_read( $argv[2] ), wstm_php80_floor_read( $argv[3] ),
				webmastery_mcp_read_baselines( $argv[4] ), wstm_php80_floor_read( $argv[5] ), wstm_php80_floor_read( $argv[6] ) );
			echo "Exact PHP 8.0 CLI/HTTP ELF and candidate full E2E verified; admission, custody and cleanup remain separate.\n";
		} else {
			throw new InvalidArgumentException( 'Usage: fixture.php probe | plan|verify-inputs config.json | verify config.json runtime.json pins.json cli.json http.json' );
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
}
