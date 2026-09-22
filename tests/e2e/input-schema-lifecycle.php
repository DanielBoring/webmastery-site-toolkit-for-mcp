<?php
/**
 * Isolated schema stage ownership. Configuration and mounted sources are read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/input-schema-boot.php';

final class Wstm126_Lifecycle {
	private string $root;
	private string $plugin;
	private string $lock;
	private string $token;
	private string $source;
	private string $project;
	private string $artifacts;
	private array $boundaries;
	private ?Closure $filesystem;

	public function __construct( string $root, string $plugin, string $lock, string $token, string $source, string $project, string $artifacts, array $boundaries, ?callable $filesystem = null ) {
		Wstm126_Boot::check( 1 === preg_match( '/^[a-f0-9]{32,64}$/D', $token ), 'Invalid owner token.' );
		Wstm126_Boot::check( 1 === preg_match( '/^[a-f0-9]{40,64}$/D', $source ), 'Invalid source identity.' );
		Wstm126_Boot::check( 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/D', $project ), 'Invalid project identity.' );
		Wstm126_Boot::check( count( $boundaries ) > 0 && count( array_unique( $boundaries ) ) === count( $boundaries ) && array() === array_diff( $boundaries, array( 'direct', 'permission', 'ability', 'http', 'individual' ) ), 'Invalid selected boundary list.' );
		foreach ( array( $root, $plugin, $lock, $artifacts ) as $path ) {
			Wstm126_Boot::check( false === strpos( $path, "\0" ) && ! preg_match( '~(?:^|[/\\\\])\.\.?(?:[/\\\\]|$)~', $path ), 'Unsafe lifecycle path.' );
		}
		$this->root = $root;
		$this->plugin = $plugin;
		$this->lock = $lock;
		$this->token = $token;
		$this->source = $source;
		$this->project = $project;
		$this->artifacts = $artifacts;
		$this->boundaries = $boundaries;
		$this->filesystem = null === $filesystem ? null : Closure::fromCallable( $filesystem );
	}

	private function filesystem( string $operation, string $path, ?int $mode = null, array $context = array() ) {
		$native = function () use ( $operation, $path, $mode, $context ) {
			switch ( $operation ) {
				case 'is_link': return is_link( $path );
				case 'stat': return stat( $path );
				case 'lstat': return lstat( $path );
				case 'chmod': return chmod( $path, $mode );
				case 'exists': return file_exists( $path );
				case 'is_file': return is_file( $path );
				case 'size': return filesize( $path );
				case 'read': return file_get_contents( $path );
				case 'list': return scandir( $path );
				case 'remove': return unlink( $path );
				case 'create': return $this->create_native( $path, $context['bytes'], $mode );
				case 'replace_journal': return rename( $path, $context['destination'] );
			}
			throw new RuntimeException( 'Unsupported lifecycle filesystem operation.' );
		};
		if ( null !== $this->filesystem ) {
			return ( $this->filesystem )( $operation, $path, $mode, array_merge( $context, array( 'native' => $native ) ) );
		}
		return $native();
	}

	private function directory( string $path ): void {
		Wstm126_Boot::check( is_dir( $path ) && ! $this->filesystem( 'is_link', $path ) && str_replace( '\\', '/', (string) realpath( $path ) ) === str_replace( '\\', '/', $path ), 'Missing, symlinked or aliased directory.' );
		$parent = dirname( $path );
		if ( $parent !== $path ) {
			Wstm126_Boot::check( ! $this->filesystem( 'is_link', $parent ), 'Symlinked directory ancestor.' );
		}
	}

	private function read( string $path ): string {
		$this->directory( dirname( $path ) );
		Wstm126_Boot::check( $this->filesystem( 'is_file', $path ) && ! $this->filesystem( 'is_link', $path ), 'Missing or symlinked owned file.' );
		$data = $this->filesystem( 'read', $path );
		Wstm126_Boot::check( is_string( $data ), 'Cannot read owned file.' );
		return $data;
	}

	private function fingerprint( string $path ): array {
		$bytes = $this->read( $path );
		clearstatcache( true, $path );
		$stat = $this->filesystem( 'stat', $path );
		Wstm126_Boot::check( is_array( $stat ) && 1 === $stat['nlink'], 'Hard-linked or unstatable lifecycle file.' );
		return array( 'sha256' => hash( 'sha256', $bytes ), 'size' => strlen( $bytes ), 'mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'dev' => $stat['dev'], 'ino' => $stat['ino'] );
	}

	private function directory_fingerprint( string $path ): array {
		$this->directory( $path );
		clearstatcache( true, $path );
		$stat = $this->filesystem( 'stat', $path );
		Wstm126_Boot::check( is_array( $stat ), 'Cannot identify invocation journal directory.' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			Wstm126_Boot::check( 0700 === ( $stat['mode'] & 07777 ) && function_exists( 'posix_geteuid' ) && posix_geteuid() === $stat['uid'], 'Invocation journal directory is foreign or not private.' );
		}
		return array( 'mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'dev' => $stat['dev'], 'ino' => $stat['ino'] );
	}

	private function absent( string $path ): void {
		clearstatcache( true, $path );
		Wstm126_Boot::check( ! $this->filesystem( 'exists', $path ) && ! $this->filesystem( 'is_link', $path ), 'Lifecycle path collision.' );
	}

	private function create( string $path, string $bytes, int $mode ): void {
		$this->directory( dirname( $path ) );
		$this->absent( $path );
		Wstm126_Boot::check( $this->filesystem( 'create', $path, $mode, array( 'bytes' => $bytes ) ), 'Cannot exclusively create stage file.' );
	}

	private function create_native( string $path, string $bytes, int $mode ): bool {
		$mask = umask( 0077 );
		try {
			$handle = fopen( $path, 'x+b' );
		} finally {
			umask( $mask );
		}
		Wstm126_Boot::check( false !== $handle, 'Cannot exclusively create stage file.' );
		try {
			Wstm126_Boot::check( strlen( $bytes ) === fwrite( $handle, $bytes ) && fflush( $handle ), 'Cannot persist stage file.' );
			Wstm126_Boot::check( $this->filesystem( 'chmod', $path, $mode ), 'Cannot set stage file permissions.' );
		} finally {
			fclose( $handle );
		}
		return true;
	}

	private function wrappers( string $probe_key_hash = '' ): array {
		$mu = $this->root . '/wp-content/mu-plugins';
		$probe = "<?php\n/* Owned schema probe " . Wstm126_Boot::owner( $this->token ) . " */\n";
		foreach ( array( 'WSTM126_PROBE_OWNER' => $this->token, 'WSTM126_PROBE_SOURCE' => $this->source, 'WSTM126_PROBE_PROJECT' => $this->project, 'WSTM126_PROBE_KEY_HASH' => $probe_key_hash ) as $name => $value ) {
			$probe .= 'define(' . var_export( $name, true ) . ', ' . var_export( $value, true ) . ");\n";
		}
		$probe .= 'require ' . var_export( $this->plugin . '/tests/e2e/input-schema-probe.php', true ) . ";\n";
		$wrapper = "<?php\n/* Owned schema fixture " . Wstm126_Boot::owner( $this->token ) . " */\n";
		$wrapper .= "define('WSTM126_DISPOSABLE_RUNTIME', true);\ndefine('WSTM126_STAGE_TOKEN', " . var_export( $this->token, true ) . ");\n";
		foreach ( array( 'input-schema-fixture.php', 'error-contract-fixture.php' ) as $source ) {
			$wrapper .= 'require ' . var_export( $this->plugin . '/tests/e2e/' . $source, true ) . ";\n";
		}
		return array( 'probe' => array( 'path' => $mu . '/wstm126-probe.php', 'bytes' => $probe ), 'wrapper' => array( 'path' => $mu . '/wstm126-schema.php', 'bytes' => $wrapper ) );
	}

	private function paths(): void {
		foreach ( array( $this->root, $this->root . '/wp-content/mu-plugins', $this->plugin, dirname( $this->lock ), $this->artifacts ) as $path ) {
			$this->directory( $path );
		}
		$root = rtrim( str_replace( '\\', '/', $this->root ), '/' ) . '/';
		$lock = str_replace( '\\', '/', $this->lock );
		$artifacts = str_replace( '\\', '/', $this->artifacts );
		Wstm126_Boot::check( $lock !== rtrim( $root, '/' ) && 0 !== strpos( $lock . '/', $root ), 'Private journal must be outside webroot.' );
		Wstm126_Boot::check( $lock !== $artifacts && 0 !== strpos( $artifacts . '/', $lock . '/' ) && 0 !== strpos( $lock . '/', $artifacts . '/' ), 'Private journal must be disjoint from the artifact directory.' );
	}

	private function collisions( array $owned = array() ): void {
		$walk = function ( string $directory ) use ( &$walk, $owned ): void {
			$this->directory( $directory );
			foreach ( scandir( $directory ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $directory . '/' . $entry;
				if ( isset( $owned[ $path ] ) ) {
					Wstm126_Boot::check( $owned[ $path ] === $this->read( $path ), 'Foreign content at owned loader path.' );
					continue;
				}
				Wstm126_Boot::check( ! $this->filesystem( 'is_link', $path ), 'Symlinked MU entry is not safe to stage.' );
				Wstm126_Boot::check( ! preg_match( '/wstm126|input-schema|wstm118|error-contract/i', $entry ), 'Known schema loader or probe collision.' );
				if ( is_dir( $path ) ) {
					$walk( $path );
				} elseif ( 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					Wstm126_Boot::check( ! preg_match( '/wstm126|input-schema|wstm118|error-contract/i', $this->read( $path ) ), 'Existing schema loader content collision.' );
				}
			}
		};
		$walk( $this->root . '/wp-content/mu-plugins' );
	}

	private function mu_snapshot(): array {
		$ignore = array_column( $this->wrappers(), 'path' );
		$result = array();
		$walk = function ( string $directory ) use ( &$walk, &$result, $ignore ): void {
			$this->directory( $directory );
			clearstatcache( true, $directory );
			$stat = $this->filesystem( 'stat', $directory );
			Wstm126_Boot::check( is_array( $stat ), 'Cannot identify existing MU directory.' );
			$result[ $directory ] = array( 'mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'dev' => $stat['dev'], 'ino' => $stat['ino'] );
			foreach ( scandir( $directory ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $directory . '/' . $entry;
				if ( in_array( $path, $ignore, true ) ) {
					continue;
				}
				Wstm126_Boot::check( ! $this->filesystem( 'is_link', $path ), 'Symlinked existing MU fixture.' );
				if ( is_dir( $path ) ) {
					$walk( $path );
				} else {
					$result[ $path ] = $this->fingerprint( $path );
				}
			}
		};
		$walk( $this->root . '/wp-content/mu-plugins' );
		return $result;
	}

	private function source_fingerprints(): array {
		$result = array();
		foreach ( Wstm126_Boot::hashes( $this->plugin ) as $relative => $hash ) {
			$result[ $relative ] = $this->fingerprint( $this->plugin . '/' . $relative );
		}
		return $result;
	}

	private function identity( array $state, ?int $uid = null ): array {
		$identity = array( 'owner' => Wstm126_Boot::owner( $this->token ), 'source' => $this->source, 'project' => $this->project, 'root' => realpath( $this->root ), 'plugin_root' => realpath( $this->plugin ), 'harness_root' => realpath( $this->plugin . '/tests/e2e' ), 'config_sha256' => $state['config']['sha256'], 'hashes' => $state['hashes'] );
		if ( null !== $uid ) {
			$identity['uid'] = $uid;
		}
		return $identity;
	}

	private function invariant( array $state ): void {
		$this->paths();
		Wstm126_Boot::check( $state['config'] === $this->fingerprint( $this->root . '/wp-config.php' ), 'Configuration bytes or metadata changed; ownership retained.' );
		Wstm126_Boot::check( $state['sources'] === $this->source_fingerprints(), 'Mounted fixture or production source changed; ownership retained.' );
		Wstm126_Boot::check( $state['mu_originals'] === $this->mu_snapshot(), 'Preexisting MU fixture tree changed outside stage ownership.' );
		if ( isset( $state['journal']['fingerprint'] ) ) {
			Wstm126_Boot::check( $state['journal']['fingerprint'] === $this->directory_fingerprint( $state['journal']['path'] ), 'Invocation journal directory identity changed.' );
		} else {
			$this->absent( $state['journal']['path'] );
		}
		if ( isset( $state['wire']['fingerprint'] ) ) {
			$this->verify_wire( $state );
		} else {
			$this->absent( $state['wire']['path'] );
		}
		$owned = array();
		foreach ( $state['files'] as $file ) {
			if ( isset( $file['fingerprint'] ) ) {
				Wstm126_Boot::check( $file['fingerprint'] === $this->fingerprint( $file['path'] ) && $file['bytes'] === $this->read( $file['path'] ), 'Owned fixture bytes or metadata changed.' );
				$owned[ $file['path'] ] = $file['bytes'];
			} else {
				$this->absent( $file['path'] );
			}
		}
		$this->collisions( $owned );
	}

	private function private_directory(): void {
		$this->directory( $this->lock );
		clearstatcache( true, $this->lock );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			Wstm126_Boot::check( 0700 === ( fileperms( $this->lock ) & 07777 ) && function_exists( 'posix_geteuid' ) && posix_geteuid() === fileowner( $this->lock ), 'Foreign or non-private owner journal.' );
		}
	}

	private function state(): array {
		$this->paths();
		$this->private_directory();
		$path = $this->lock . '/state.json';
		Wstm126_Boot::check( $this->filesystem( 'is_file', $path ) && $this->filesystem( 'size', $path ) <= 1048576, 'Missing or oversized owner journal.' );
		$metadata = $this->fingerprint( $path );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			Wstm126_Boot::check( 0600 === $metadata['mode'] && posix_geteuid() === $metadata['uid'], 'Foreign or exposed private state.' );
		}
		$sealed = json_decode( $this->read( $path ), true, 64, JSON_THROW_ON_ERROR );
		Wstm126_Boot::check( is_array( $sealed ) && array_keys( $sealed ) === array( 'payload', 'seal' ) && is_array( $sealed['payload'] ) && is_string( $sealed['seal'] ) && hash_equals( hash_hmac( 'sha256', json_encode( $sealed['payload'], JSON_THROW_ON_ERROR ), $this->token ), $sealed['seal'] ), 'Foreign or modified owner journal.' );
		$state = $sealed['payload'];
		Wstm126_Boot::check( is_array( $state ) && $this->token === ( $state['token'] ?? null ) && $this->root === ( $state['root'] ?? null ) && $this->plugin === ( $state['plugin'] ?? null ) && $this->source === ( $state['source'] ?? null ) && $this->project === ( $state['project'] ?? null ) && $this->artifacts === ( $state['artifacts'] ?? null ) && $this->boundaries === ( $state['boundaries'] ?? null ), 'Foreign stage journal identity.' );
		Wstm126_Boot::check( $this->lock . '/invocations' === ( $state['journal']['path'] ?? null ), 'Foreign invocation journal directory instructions.' );
		Wstm126_Boot::check( $this->lock . '/boot-wire' === ( $state['wire']['path'] ?? null ) && is_array( $state['wire']['files'] ?? null ), 'Foreign private wire directory instructions.' );
		foreach ( $state['wire']['files'] as $index => $file ) {
			Wstm126_Boot::check( is_int( $index ) && $state['wire']['path'] . '/' . sprintf( '%06d.bin', $index + 1 ) === ( $file['path'] ?? null ), 'Foreign raw wire file instructions.' );
		}
		Wstm126_Boot::check( is_string( $state['probe_key'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $state['probe_key'] ) && $state['probe_key'] !== $this->token, 'Missing or invalid private probe credential.' );
		foreach ( $this->wrappers( hash( 'sha256', $state['probe_key'] ) ) as $kind => $file ) {
			Wstm126_Boot::check( $file['path'] === ( $state['files'][ $kind ]['path'] ?? null ) && $file['bytes'] === ( $state['files'][ $kind ]['bytes'] ?? null ), 'Foreign journal file instructions.' );
		}
		return $state;
	}

	public function probe_key(): string {
		$state = $this->state();
		$this->invariant( $state );
		return $state['probe_key'];
	}

	private function verify_wire( array $state ): void {
		Wstm126_Boot::check( $state['wire']['fingerprint'] === $this->directory_fingerprint( $state['wire']['path'] ), 'Private wire directory identity changed.' );
		$entries = array( '.', '..' );
		foreach ( $state['wire']['files'] as $file ) {
			Wstm126_Boot::check( $file['fingerprint'] === $this->fingerprint( $file['path'] ), 'Private wire evidence changed; ownership retained.' );
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				Wstm126_Boot::check( 0600 === $file['fingerprint']['mode'] && posix_geteuid() === $file['fingerprint']['uid'], 'Private wire evidence is exposed or foreign.' );
			}
			$entries[] = basename( $file['path'] );
		}
		sort( $entries );
		Wstm126_Boot::check( $entries === scandir( $state['wire']['path'] ), 'Foreign or uncertain private wire file; ownership retained.' );
	}

	/** Retain the bounded exact wire privately before any decode or public logging. */
	public function retain_wire( string $body, ?int $status, int $attempt ): void {
		Wstm126_Boot::check( strlen( $body ) <= 16385 && $attempt >= 1 && $attempt <= 5, 'Invalid private wire capture bounds.' );
		$state = $this->state();
		$this->verify_wire( $state );
		$path = $state['wire']['path'] . '/' . sprintf( '%06d.bin', count( $state['wire']['files'] ) + 1 );
		$this->create( $path, $body, 0600 );
		$state['wire']['files'][] = array( 'path' => $path, 'fingerprint' => $this->fingerprint( $path ), 'status' => $status, 'attempt' => $attempt );
		$this->save( $state );
		$this->verify_wire( $state );
	}

	private function encode( array $state ): string {
		return json_encode( array( 'payload' => $state, 'seal' => hash_hmac( 'sha256', json_encode( $state, JSON_THROW_ON_ERROR ), $this->token ) ), JSON_THROW_ON_ERROR );
	}

	private function save( array $state ): void {
		$this->state();
		$path = $this->lock . '/next.json';
		$this->create( $path, $this->encode( $state ), 0600 );
		Wstm126_Boot::check( $this->filesystem( 'replace_journal', $path, null, array( 'destination' => $this->lock . '/state.json' ) ), 'Cannot persist owner journal transition.' );
	}

	public function acquire( callable $cli ): array {
		$this->paths();
		$this->absent( $this->lock );
		$this->collisions();
		$config = $this->fingerprint( $this->root . '/wp-config.php' );
		Wstm126_Boot::check( ! preg_match( '/WSTM126/i', $this->read( $this->root . '/wp-config.php' ) ), 'Existing schema configuration namespace.' );
		$probe_key = bin2hex( random_bytes( 32 ) );
		$state = array( 'token' => $this->token, 'probe_key' => $probe_key, 'root' => $this->root, 'plugin' => $this->plugin, 'source' => $this->source, 'project' => $this->project, 'artifacts' => $this->artifacts, 'boundaries' => $this->boundaries, 'config' => $config, 'sources' => $this->source_fingerprints(), 'hashes' => Wstm126_Boot::hashes( $this->plugin ), 'mu_originals' => $this->mu_snapshot(), 'files' => $this->wrappers( hash( 'sha256', $probe_key ) ), 'journal' => array( 'path' => $this->lock . '/invocations' ), 'wire' => array( 'path' => $this->lock . '/boot-wire', 'files' => array() ), 'phase' => 'acquired' );
		$original = $cli( true );
		Wstm126_Boot::validate( $original, $this->identity( $state ), true );
		Wstm126_Boot::baseline( $original['runtime'] );
		$state['original'] = $original['runtime'];
		$state['cli_uid'] = $original['identity']['uid'];
		$this->invariant( $state );
		$mask = umask( 0077 );
		try {
			Wstm126_Boot::check( mkdir( $this->lock, 0700 ), 'Cannot exclusively acquire private stage lock.' );
		} finally {
			umask( $mask );
		}
		$this->private_directory();
		$state['pending'] = 'journal-directory';
		$this->create( $this->lock . '/state.json', $this->encode( $state ), 0600 );
		foreach ( array( 'journal', 'wire' ) as $kind ) {
			$this->absent( $state[ $kind ]['path'] );
			Wstm126_Boot::check( mkdir( $state[ $kind ]['path'], 0700 ), 'Cannot exclusively create private stage evidence directory.' );
			$state[ $kind ]['fingerprint'] = $this->directory_fingerprint( $state[ $kind ]['path'] );
		}
		unset( $state['pending'] );
		$this->save( $state );
		$this->invariant( $state );
		return $this->summary( $state );
	}

	private function install( array &$state, string $kind ): void {
		$this->invariant( $state );
		$file = $state['files'][ $kind ];
		Wstm126_Boot::check( ! isset( $file['fingerprint'] ), 'Owned loader already installed.' );
		// Intent is durable before creation. Uncertain partial writes remain guarded.
		$state['pending'] = $kind;
		$this->save( $state );
		$this->create( $file['path'], $file['bytes'], 0644 );
		$state['files'][ $kind ]['fingerprint'] = $this->fingerprint( $file['path'] );
		unset( $state['pending'] );
		$this->save( $state );
		$this->invariant( $state );
	}

	private function attest( array &$state, callable $cli, callable $http, array $expected, array $stale ): array {
		$this->invariant( $state );
		$sample = $cli( false );
		Wstm126_Boot::validate( $sample, $this->identity( $state, $state['cli_uid'] ), true );
		Wstm126_Boot::check( $expected === $sample['runtime'], 'Fresh CLI runtime differs from expected state.' );
		$identity = $this->identity( $state, $state['http_uid'] ?? null );
		$boot = $http( $identity, $expected, $stale );
		$latest = $this->state();
		$state['wire'] = $latest['wire'];
		Wstm126_Boot::validate( $boot, $identity );
		Wstm126_Boot::check( $boot['runtime'] === $expected, 'Actual HTTP runtime differs from expected state.' );
		$this->invariant( $state );
		$state['http_uid'] = $boot['identity']['uid'];
		return $boot;
	}

	public function prepare( callable $cli, callable $http ): array {
		$state = $this->state();
		Wstm126_Boot::check( 'acquired' === $state['phase'] && ! isset( $state['pending'] ), 'Stage cannot prepare twice or during uncertain creation.' );
		$this->install( $state, 'probe' );
		$state['original_http'] = $this->attest( $state, $cli, $http, $state['original'], array() );
		$state['phase'] = 'baseline';
		$state['active'] = Wstm126_Boot::active_runtime( $state['original'], $this->token );
		$this->save( $state );
		$this->install( $state, 'wrapper' );
		$sample = $cli( false );
		Wstm126_Boot::validate( $sample, $this->identity( $state, $state['cli_uid'] ), true );
		Wstm126_Boot::active( $sample['runtime'], $state['original'], $this->token );
		$state['active'] = $sample['runtime'];
		$this->save( $state );
		$state['active_http'] = $this->attest( $state, $cli, $http, $state['active'], array( $state['original'] ) );
		$state['phase'] = 'active';
		$this->save( $state );
		return $this->summary( $state );
	}

	private function remove( array &$state, string $kind ): void {
		$this->invariant( $state );
		$file = $state['files'][ $kind ];
		if ( ! isset( $file['fingerprint'] ) ) {
			return;
		}
		Wstm126_Boot::check( $file['bytes'] === $this->read( $file['path'] ) && $file['fingerprint'] === $this->fingerprint( $file['path'] ), 'Foreign loader cannot be removed.' );
		Wstm126_Boot::check( unlink( $file['path'] ), 'Cannot remove exact owned loader.' );
		unset( $state['files'][ $kind ]['fingerprint'] );
		$this->save( $state );
	}

	public function restore( callable $cli, callable $http ): array {
		$state = $this->state();
		Wstm126_Boot::check( ! isset( $state['pending'] ), 'Uncertain partial creation retained for investigation.' );
		$this->invariant( $state );
		$this->remove( $state, 'wrapper' );
		if ( ! isset( $state['files']['probe']['fingerprint'] ) ) {
			$this->install( $state, 'probe' );
		}
		$boot = $this->attest( $state, $cli, $http, $state['original'], isset( $state['active'] ) ? array( $state['active'] ) : array() );
		// A prepare failure still requires real baseline HTTP evidence before finalization.
		if ( ! isset( $state['original_http'] ) ) {
			$state['original_http'] = $boot;
		}
		$state['restored_http'] = $boot;
		$state['phase'] = 'restored';
		$this->save( $state );
		return $this->summary( $state );
	}

	public static function assert_invocations_empty( string $directory ): void {
		Wstm126_Boot::check( is_dir( $directory ) && ! is_link( $directory ) && str_replace( '\\', '/', (string) realpath( $directory ) ) === str_replace( '\\', '/', $directory ), 'Invocation cleanup journal directory is missing or aliased.' );
		Wstm126_Boot::check( array( '.', '..' ) === scandir( $directory ), 'Invocation cleanup journal remains; ownership retained.' );
	}

	public function finalize( callable $cli, callable $http, callable $validate, callable $source_hashes ): array {
		$state = $this->state();
		$this->invariant( $state );
		Wstm126_Boot::check( 'restored' === $state['phase'] && isset( $state['original_http'], $state['restored_http'] ) && ! isset( $state['pending'] ) && ! isset( $state['files']['wrapper']['fingerprint'] ), 'Verified restored runtime is required before finalization.' );
		$hashes = $source_hashes( $this->plugin );
		Wstm126_Boot::check( is_array( $hashes ) && count( $hashes ) > 0, 'Missing mounted source identity proof.' );
		$proofs = array();
		foreach ( $this->boundaries as $boundary ) {
			$path = $this->artifacts . '/' . $boundary . '.json';
			Wstm126_Boot::check( is_file( $path ) && filesize( $path ) <= 33554432, 'Missing or oversized invocation report.' );
			$bytes = $this->read( $path );
			Wstm126_Boot::check( strlen( $bytes ) <= 33554432, 'Invocation report exceeds bounded size.' );
			$report = json_decode( $bytes, true, 128, JSON_THROW_ON_ERROR );
			Wstm126_Boot::check( is_array( $report ), 'Malformed invocation report.' );
			$validate( $report, $this->token, $this->source, $this->project, $boundary );
			Wstm126_Boot::check( isset( $report['source_hashes'] ) && $report['source_hashes'] === $hashes, 'Invocation source hashes do not match mounted source.' );
			$proofs[ $boundary ] = hash( 'sha256', $bytes );
		}
		$state['restored_http'] = $this->attest( $state, $cli, $http, $state['original'], array() );
		$state['proofs'] = $proofs;
		$this->save( $state );
		Wstm126_Boot::check( array( '.', '..', 'boot-wire', 'invocations', 'state.json' ) === $this->filesystem( 'list', $this->lock ), 'Foreign content in private lock; retained.' );
		Wstm126_Boot::check( isset( $state['journal']['fingerprint'] ), 'Missing invocation journal directory ownership.' );
		self::assert_invocations_empty( $state['journal']['path'] );
		$this->remove( $state, 'probe' );
		$this->invariant( $state );
		Wstm126_Boot::check( array( '.', '..', 'boot-wire', 'invocations', 'state.json' ) === $this->filesystem( 'list', $this->lock ), 'Foreign content in private lock; retained.' );
		$state_bytes = $this->read( $this->lock . '/state.json' );
		$state_metadata = $this->fingerprint( $this->lock . '/state.json' );
		$this->state();
		Wstm126_Boot::check( $state_bytes === $this->read( $this->lock . '/state.json' ) && $state_metadata === $this->fingerprint( $this->lock . '/state.json' ), 'Private state changed during finalization.' );
		$this->verify_wire( $state );
		foreach ( $state['wire']['files'] as $file ) {
			Wstm126_Boot::check( $file['fingerprint'] === $this->fingerprint( $file['path'] ) && unlink( $file['path'] ), 'Cannot retire exact owned wire evidence.' );
		}
		Wstm126_Boot::check( array( '.', '..' ) === scandir( $state['wire']['path'] ) && rmdir( $state['wire']['path'] ), 'Cannot retire exact empty wire directory.' );
		Wstm126_Boot::check( $state['journal']['fingerprint'] === $this->directory_fingerprint( $state['journal']['path'] ) && array( '.', '..' ) === scandir( $state['journal']['path'] ) && rmdir( $state['journal']['path'] ), 'Cannot retire exact empty invocation journal directory.' );
		Wstm126_Boot::check( $this->filesystem( 'remove', $this->lock . '/state.json' ) && rmdir( $this->lock ), 'Cannot finalize private stage ownership.' );
		$state['phase'] = 'finalized';
		return $this->summary( $state );
	}

	private function summary( array $state ): array {
		return array( 'phase' => $state['phase'], 'identity' => $this->identity( $state ), 'boundaries' => $this->boundaries, 'config' => $state['config'], 'baseline_http_verified' => isset( $state['original_http'] ), 'restored_http_verified' => isset( $state['restored_http'] ), 'cleanup_proofs' => $state['proofs'] ?? array() );
	}
}
