<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/tests/fixtures/php80-floor/fixture.php';
require_once dirname( __DIR__ ) . '/tests/e2e/untrusted-content-files.php';

/** Routing identity only: no admission, authority, provisioning or cleanup grant. */
final class WstmQaRuntime {
	public const PROFILE = 'WSTM_QA_RUNTIME_PROFILE';
	public const CONFIG = 'WSTM_PHP80_CONFIG';
	public const HASH = 'WSTM_PHP80_CONFIG_SHA256';

	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( 'QA runtime: ' . $message ); }
	}

	public static function floor( array $environment ): bool {
		$profile = $environment[ self::PROFILE ] ?? '';
		self::require( '' === $profile || 'php80-floor' === $profile, 'unknown explicit runtime profile.' );
		if ( '' === $profile ) {
			self::require( ! isset( $environment[ self::CONFIG ], $environment[ self::HASH ] )
				&& ! array_key_exists( self::CONFIG, $environment ) && ! array_key_exists( self::HASH, $environment ),
				'floor config without its explicit profile.' );
			return false;
		}
		foreach ( array( 'E2E_PACKAGE_ROOT', 'E2E_PACKAGE_ZIP', 'COMPOSE_FILE', 'COMPOSE_PROFILES', 'COMPOSE_ENV_FILES' ) as $key ) {
			self::require( '' === ( $environment[ $key ] ?? '' ), 'incompatible package or ambient Compose selector: ' . $key );
		}
		foreach ( array( 'WORDPRESS_IMAGE', 'MYSQL_IMAGE', 'WORDPRESS_PORT', 'MYSQL_PORT' ) as $key ) {
			self::require( '' === ( $environment[ $key ] ?? '' ), 'default/compatibility override conflicts with floor: ' . $key );
		}
		self::require( is_string( $environment[ self::CONFIG ] ?? null ) && is_string( $environment[ self::HASH ] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $environment[ self::HASH ] ), 'exact original config path and SHA256 required.' );
		wstm_php80_floor_path( $environment[ self::CONFIG ] );
		return true;
	}

	public static function plan( array $environment, string $root, ?array $config = null ): array {
		if ( ! self::floor( $environment ) ) {
			$argv = array( 'docker', 'compose' );
			if ( '' !== ( $environment['COMPOSE_PROJECT_NAME'] ?? '' ) ) {
				$argv = array_merge( $argv, array( '--project-name', $environment['COMPOSE_PROJECT_NAME'] ) );
			}
			if ( '' !== ( $environment['E2E_PACKAGE_ROOT'] ?? '' ) || '' !== ( $environment['E2E_PACKAGE_ZIP'] ?? '' ) ) {
				self::require( '' !== ( $environment['E2E_PACKAGE_ROOT'] ?? '' ) && '' !== ( $environment['E2E_PACKAGE_ZIP'] ?? '' ), 'package root and original ZIP required together.' );
				$argv = array_merge( $argv, array( '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' ) );
			}
			return array( 'profile' => '', 'compose_argv' => $argv, 'environment' => array(), 'config' => null );
		}
		self::require( null !== $config, 'original pinned config was not read.' );
		$config = wstm_php80_floor_config( $config );
		self::require( null !== $config['runtime_image'] && $root === $config['candidate_root'], 'immutable runtime selection or candidate root differs.' );
		self::require( ( $environment['COMPOSE_PROJECT_NAME'] ?? null ) === $config['project']
			&& ( $environment['E2E_ARTIFACTS_DIR'] ?? null ) === $config['artifact_directory']
			&& 'pinned' === ( $environment['DEPENDENCY_POLICY'] ?? 'pinned' ), 'project, artifact or dependency identity differs.' );
		$plan = wstm_php80_floor_plan( $config );
		foreach ( $plan['environment'] as $key => $value ) {
			self::require( ! array_key_exists( $key, $environment ) || $environment[ $key ] === $value, 'selected environment drift: ' . $key );
		}
		return array( 'profile' => 'php80-floor', 'compose_argv' => $plan['compose_argv'], 'environment' => $plan['environment'], 'config' => $config );
	}

	public static function read_config( array $environment ): array {
		self::require( self::floor( $environment ) && 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' ), 'native Linux original config reader required.' );
		$path = $environment[ self::CONFIG ];
		Wstm108_Files::directory( dirname( $path ) );
		clearstatcache( true, $path );
		$before = lstat( $path );
		self::require( is_array( $before ) && realpath( $path ) === $path && ! is_link( $path )
			&& 0100000 === ( $before['mode'] & 0170000 ) && 1 === $before['nlink']
			&& posix_geteuid() === $before['uid'] && 0 === ( $before['mode'] & 0077 )
			&& $before['size'] <= 1048576, 'original config ownership, shape or size differs.' );
		$handle = fopen( $path, 'rb' );
		self::require( is_resource( $handle ), 'cannot open original config.' );
		try {
			self::require( flock( $handle, LOCK_SH | LOCK_NB ), 'config is being changed.' );
			$identity = static fn( array $stat ): array => array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size', 'mtime', 'ctime' ) ) );
			$opened = fstat( $handle );
			self::require( is_array( $opened ) && $identity( $opened ) === $identity( $before ), 'config replaced before opening.' );
			$bytes = stream_get_contents( $handle, 1048577 );
			$after = fstat( $handle );
			clearstatcache( true, $path );
			$current = lstat( $path );
			self::require( is_string( $bytes ) && strlen( $bytes ) <= 1048576 && feof( $handle )
				&& is_array( $after ) && is_array( $current )
				&& $identity( $after ) === $identity( $before ) && $identity( $current ) === $identity( $before )
				&& hash_equals( $environment[ self::HASH ], hash( 'sha256', $bytes ) ), 'original config bytes/identity drift.' );
		} finally {
			fclose( $handle );
		}
		self::require( json_decode( $bytes, false, 64, JSON_THROW_ON_ERROR ) instanceof stdClass, 'config must be an object.' );
		return wstm_php80_floor_config( json_decode( $bytes, true, 64, JSON_THROW_ON_ERROR ) );
	}

	public static function selection( array $environment, string $root ): array {
		$plan = self::plan( $environment, $root, self::floor( $environment ) ? self::read_config( $environment ) : null );
		if ( 'php80-floor' === $plan['profile'] ) {
			self::require( PHP_VERSION_ID >= 80100 && function_exists( 'fsync' ), 'floor routing requires the existing >=8.1 proof host, not the PHP8.0 runtime.' );
			self::require( $root === realpath( $root ), 'candidate root is not canonical.' );
			$config = $plan['config'];
			$output = array();
			exec( 'git --no-optional-locks -C ' . escapeshellarg( $root ) . ' rev-parse HEAD ' . escapeshellarg( 'HEAD^{tree}' ), $output, $status );
			self::require( 0 === $status && $output === array( $config['candidate_sha'], $config['candidate_tree'] ), 'actual Git source/tree differs from the selected original.' );
		}
		return $plan;
	}

	public static function assert_binding( array $plan, array $binding ): void {
		if ( 'php80-floor' === $plan['profile'] ) {
			self::require( $plan['config']['candidate_sha'] === ( $binding['source_sha'] ?? null )
				&& $plan['config']['candidate_tree'] === ( $binding['tree_sha'] ?? null )
				&& $plan['config']['project'] === ( $binding['project'] ?? null )
				&& array_key_exists( 'package_sha256', $binding ) && null === $binding['package_sha256'], 'original source/tree/project/package binding differs.' );
		}
	}

	public static function retention_bytes( array $plan, array $environment, string $owner, string $project, string $source ): string {
		$bytes = "owner={$owner}\nproject={$project}\nsource={$source}\n";
		if ( 'php80-floor' === $plan['profile'] ) {
			self::require( $project === $plan['config']['project'] && $source === $plan['config']['candidate_sha'], 'retention source/project differs.' );
			$bytes .= 'profile=php80-floor' . "\nconfig=" . $environment[ self::CONFIG ] . "\nconfig_sha256=" . $environment[ self::HASH ]
				. "\nruntime_image=" . $plan['config']['runtime_image'] . "\nsource_tree=" . $plan['config']['candidate_tree'] . "\n";
		}
		return $bytes;
	}

	public static function projected_query( array $plan, callable $query, array &$seen ): callable {
		return static function ( array $argv ) use ( $plan, $query, &$seen ) {
			$inspect = array_search( 'inspect', $argv, true );
			$is_container = 'php80-floor' === $plan['profile'] && false !== $inspect
				&& '--format' === ( $argv[ $inspect + 1 ] ?? null )
				&& str_contains( $argv[ $inspect + 2 ] ?? '', '.Mounts' );
			if ( $is_container ) {
				$argv[ $inspect + 2 ] = '{"id":{{json .Id}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"mounts":{{json .Mounts}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"oneoff":{{json (index .Config.Labels "com.docker.compose.oneoff")}},"image":{{json .Image}},"configured_image":{{json .Config.Image}}}';
			}
			$result = $query( $argv );
			if ( 'php80-floor' !== $plan['profile'] ) { return $result; }
			if ( in_array( '--no-env-resolution', $argv, true ) && in_array( 'config', $argv, true ) ) {
				$config = $plan['config'];
				self::require( is_array( $result ) && is_array( $result['services'] ?? null ), 'missing floor Compose projection.' );
				$services = array_keys( $result['services'] ?? array() );
				sort( $services, SORT_STRING );
				self::require( is_array( $result ) && $config['project'] === ( $result['name'] ?? null )
					&& $services === array( 'mysql', 'wordpress' )
					&& $config['runtime_image'] === ( $result['services']['wordpress']['image'] ?? null )
					&& $config['mysql_image'] === ( $result['services']['mysql']['image'] ?? null ), 'planned floor service/image identity differs.' );
				self::wordpress_mounts( $config, $result['services']['wordpress']['volumes'] ?? array(), false );
			}
			if ( $is_container ) {
				$config = $plan['config'];
				self::require( is_array( $result ), 'missing floor container projection.' );
				$service = $result['service'] ?? null;
				self::require( is_array( $result ) && $config['project'] === ( $result['project'] ?? null )
					&& in_array( $service, array( 'mysql', 'wordpress' ), true ) && 'False' === ( $result['oneoff'] ?? null )
					&& is_string( $result['image'] ?? null ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $result['image'] ),
					'foreign, one-off or unresolved floor container.' );
				$requested_id = end( $argv );
				$id = $result['id'] ?? null;
				self::require( is_string( $requested_id ) && 1 === preg_match( '/\A[a-f0-9]{12,64}\z/D', $requested_id )
					&& is_string( $id ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $id ) && str_starts_with( $id, $requested_id ),
					'actual container identity differs from requested original.' );
				self::require( ! isset( $seen[ $service ] ) || $seen[ $service ] === array( $id, $result['image'] ), 'duplicate service or changed live image.' );
				$seen[ $service ] = array( $id, $result['image'] );
				if ( 'wordpress' === $service ) {
					self::require( $config['runtime_image'] === $result['image'] && $config['runtime_image'] === ( $result['configured_image'] ?? null ), 'live floor ELF image selection differs.' );
					self::wordpress_mounts( $config, $result['mounts'] ?? array(), true );
				} else {
					self::require( $config['mysql_image'] === ( $result['configured_image'] ?? null ), 'live MySQL digest selection differs.' );
					$image = $query( array_merge( array_slice( $argv, 0, $inspect ), array( 'image', 'inspect', '--format', '{"id":{{json .Id}},"digests":{{json .RepoDigests}}}', $config['mysql_image'] ) ) );
					$digest = 'mysql@' . substr( $config['mysql_image'], strpos( $config['mysql_image'], '@' ) + 1 );
					self::require( is_array( $image ) && $result['image'] === ( $image['id'] ?? null )
						&& is_array( $image['digests'] ?? null ) && ( in_array( $digest, $image['digests'], true )
							|| in_array( 'docker.io/library/' . $digest, $image['digests'], true ) ), 'actual MySQL image is not the selected digest.' );
				}
			}
			return $result;
		};
	}

	private static function wordpress_mounts( array $config, array $volumes, bool $live ): void {
		$actual = array();
		foreach ( $volumes as $volume ) {
			self::require( 'bind' === ( $volume[ $live ? 'Type' : 'type' ] ?? null ), 'unexpected floor WordPress mount type.' );
			$target = $volume[ $live ? 'Destination' : 'target' ] ?? null;
			self::require( is_string( $target ) && ! isset( $actual[ $target ] ), 'duplicate/unresolved floor bind.' );
			$actual[ $target ] = array( $volume[ $live ? 'Source' : 'source' ] ?? null,
				$live ? ! ( $volume['RW'] ?? true ) : ( $volume['read_only'] ?? false ) );
			if ( ! $live ) { self::require( false === ( $volume['bind']['create_host_path'] ?? null ), 'floor bind can create or replace an original path.' ); }
		}
		$expected = array(
			'/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp' => array( $config['candidate_root'], false ),
			'/run/php80-floor/wp-config.php' => array( $config['wp_config'], true ),
		);
		ksort( $actual, SORT_STRING ); ksort( $expected, SORT_STRING );
		self::require( $actual === $expected, 'planned/live floor bind identity differs.' );
	}

	public static function discover( array $plan, callable $query, string $authority_root ): array {
		$seen = array();
		$project = $plan['config']['project'] ?? null;
		if ( null === $project ) {
			$index = array_search( '--project-name', $plan['compose_argv'], true );
			self::require( false !== $index, 'discovery requires the existing explicit project.' );
			$project = $plan['compose_argv'][ $index + 1 ];
		}
		$mounts = Wstm108_HostAuthority::discover_mounts( $project, $plan['compose_argv'], self::projected_query( $plan, $query, $seen ), $authority_root );
		if ( 'php80-floor' === $plan['profile'] ) {
			$services = array_keys( $seen ); sort( $services, SORT_STRING );
			self::require( array( 'mysql', 'wordpress' ) === $services, 'complete actual floor service inventory required.' );
		}
		return $mounts;
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		$mode = $argv[1] ?? '';
		$environment = getenv();
		if ( 'package' === $mode && 2 === count( $argv ) ) {
			throw new RuntimeException( 'Floor selection is incompatible with original-ZIP package QA.' );
		}
		$plan = WstmQaRuntime::selection( $environment, realpath( dirname( __DIR__ ) ) );
		if ( 'frame' === $mode && 2 === count( $argv ) ) {
			echo json_encode( $plan, JSON_THROW_ON_ERROR ) . "\n";
		} elseif ( 'shell' === $mode && 2 === count( $argv ) ) {
			foreach ( $plan['environment'] as $key => $value ) { echo 'export ' . $key . '=' . escapeshellarg( $value ) . "\n"; }
			echo 'WSTM_QA_COMPOSE_ARGV=(' . implode( ' ', array_map( 'escapeshellarg', $plan['compose_argv'] ) ) . ")\n";
		} elseif ( 'retention' === $mode && 4 === count( $argv ) ) {
			if ( 'php80-floor' === $plan['profile'] ) { wstm_php80_floor_private_inputs( $plan['config'] ); }
			echo WstmQaRuntime::retention_bytes( $plan, $environment, $argv[2], $environment['COMPOSE_PROJECT_NAME'] ?? '', $argv[3] );
		} else {
			throw new InvalidArgumentException( 'Usage: qa-runtime.php shell | retention owner source' );
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
}
