<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-host-boundaries.php';

final class UntrustedSyntheticTopologyTest extends TestCase {
	private static function identity(): array {
		return array( 'dev' => 2049, 'ino' => 100, 'mode' => 0040700, 'nlink' => 2, 'uid' => 1000, 'gid' => 1000 );
	}

	private static function fixture( string $method, array $arguments ) {
		$reflection = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $arguments );
	}

	private static function source(): string {
		return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php' ) );
	}

	private static function split_admit( string $source ): array {
		$start = strpos( $source, "\tpublic static function admit( string \$endpoint ): array {" );
		$end = strrpos( $source, '}' );
		self::assertNotFalse( $start ); self::assertNotFalse( $end );
		return array( substr( $source, 0, $start ), substr( $source, $start, $end - $start ), substr( $source, $end ) );
	}

	public static function endings(): array {
		return array( 'LF' => array( "\n" ), 'CRLF' => array( "\r\n" ) );
	}

	/** @dataProvider endings */
	public function test_actual_admit_substitution_is_exact_reversible_and_has_no_ambient_read( string $ending ): void {
		$path = dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
		$before = file_get_contents( $path );
		$source = str_replace( "\n", $ending, self::source() );
		$root = "/synthetic/quote' and \\slash/\u{2603}";
		$copy = self::fixture( 'topology_admission', array( $source, $root, self::identity() ) );
		$original_parts = self::split_admit( $source );
		$copy_parts = self::split_admit( $copy );
		self::assertSame( $original_parts[0], $copy_parts[0], 'Every production parser/coordinate/native/outside method stays byte-identical.' );
		self::assertSame( $original_parts[2], $copy_parts[2] );
		self::assertSame( 1, substr_count( $copy, $copy_parts[1] ) );
		self::assertSame( $source, str_replace( $copy_parts[1], $original_parts[1], $copy ) );
		self::assertStringNotContainsString( '/proc/', $copy_parts[1] );
		self::assertStringNotContainsString( 'file_get_contents', $copy_parts[1] );
		self::assertStringContainsString( var_export( $root, true ), $copy_parts[1] );
		$table = self::fixture( 'topology_mountinfo', array( $root, self::identity() ) );
		self::assertStringContainsString( 'base64_decode(' . var_export( base64_encode( $table ), true ) . ', true)', $copy_parts[1],
			'Mount-table LF bytes must not change when PHP source uses CRLF.' );
		self::assertSame( $before, file_get_contents( $path ), 'The real checkout is never patched by the source transformer.' );
	}

	public static function bad_sites(): array {
		return array( array( 'changed' ), array( 'duplicate' ), array( 'already-installed' ), array( 'mixed-ending' ) );
	}

	/** @dataProvider bad_sites */
	public function test_mount_installer_refuses_changed_ambiguous_or_already_installed_source( string $case ): void {
		$source = self::source();
		if ( 'changed' === $case ) { $source = str_replace( 'native-linux-prerequisite', 'changed-prerequisite', $source ); }
		if ( 'duplicate' === $case ) { $source .= $source; }
		if ( 'already-installed' === $case ) { $source = self::fixture( 'topology_admission', array( $source, '/synthetic', self::identity() ) ); }
		if ( 'mixed-ending' === $case ) { $source = "<?php\r\n" . $source; }
		$this->expectException( RuntimeException::class );
		self::fixture( 'topology_admission', array( $source, '/synthetic', self::identity() ) );
	}

	public function test_closed_model_preserves_encoded_paths_and_refuses_outside_coordinates(): void {
		$root = "/synthetic/quote' space/\\040literal/\u{2603}";
		$table = self::fixture( 'topology_mountinfo', array( $root, self::identity() ) );
		self::assertSame( $table, self::fixture( 'topology_mountinfo', array( $root, self::identity() ) ) );
		self::assertSame( 2, substr_count( $table, "\n" ) );
		self::assertStringNotContainsString( "\r", $table );
		self::assertStringContainsString( '\\134040literal', $table );
		self::assertStringContainsString( '\\040space', $table );
		$mounts = Wstm108_HostTopology::mounts( $table );
		self::assertSame( array(
			array( 'id' => 1, 'parent' => 0, 'device' => '0:0', 'root' => '/', 'point' => '/', 'type' => 'wstm108-synthetic-outside' ),
			array( 'id' => 2, 'parent' => 1, 'device' => '8:1', 'root' => $root, 'point' => $root, 'type' => 'tmpfs' ),
		), $mounts );
		self::assertSame( array( 'device' => '8:1', 'path' => $root . '/authority', 'mount_id' => 2, 'type' => 'tmpfs' ),
			Wstm108_HostTopology::coordinate( $root . '/authority', $mounts ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 78 );
		$this->expectExceptionMessage( 'unsupported-filesystem-coordinate' );
		Wstm108_HostTopology::coordinate( $root . '-not-owned', $mounts );
	}

	public static function bad_roots(): array {
		return array_map( static fn( string $root ) => array( $root ),
			array( '/', '', 'relative', '/trailing/', '/double//slash', '/dot/./path', '/parent/../path',
				"/tab\tpath", "/line\npath", "/cr\rpath", "/nul\0path", "/del\x7fpath" ) );
	}

	/** @dataProvider bad_roots */
	public function test_model_does_not_widen_production_path_policy( string $root ): void {
		$this->expectException( RuntimeException::class );
		self::fixture( 'topology_mountinfo', array( $root, self::identity() ) );
	}

	public function test_model_rejects_an_invalid_device_instead_of_using_a_default(): void {
		$identity = self::identity();
		$identity['dev'] = -1;
		$this->expectException( RuntimeException::class );
		self::fixture( 'topology_mountinfo', array( '/synthetic', $identity ) );
	}

	public function test_source_binding_ignores_link_count_but_retains_every_stable_identity_field(): void {
		$identity = self::identity();
		$before = self::fixture( 'topology_admission', array( self::source(), '/synthetic', $identity ) );
		$child_created = $identity; ++$child_created['nlink'];
		self::assertSame( $before, self::fixture( 'topology_admission', array( self::source(), '/synthetic', $child_created ) ) );
		foreach ( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) as $key ) {
			$changed = $identity; ++$changed[ $key ];
			self::assertNotSame( $before, self::fixture( 'topology_admission', array( self::source(), '/synthetic', $changed ) ), $key );
		}
	}

	private static function isolated_class( string $copy, bool $windows = false ): string {
		static $sequence = 0;
		$namespace = 'Wstm108SyntheticTopology' . ++$sequence;
		$start = strpos( $copy, 'final class Wstm108_HostTopology' );
		self::assertNotFalse( $start );
		// These traps forbid ambient reads; native identities and coordinates are not mocked.
		$traps = <<<'PHP'
function file_get_contents($path) { throw new RuntimeException('Unexpected ambient topology read.'); }
function fopen($path, $mode) { throw new RuntimeException('Unexpected ambient topology open.'); }
PHP;
		eval( 'declare(strict_types=1); namespace ' . $namespace . '; use \RuntimeException; use \Wstm108_Files; '
			. ( $windows ? 'const PHP_OS_FAMILY = "Windows"; ' : '' ) . $traps . substr( $copy, $start ) );
		return $namespace . '\\Wstm108_HostTopology';
	}

	private static function environment( array $values ): array {
		$before = array();
		foreach ( $values as $name => $value ) {
			$before[ $name ] = getenv( $name );
			self::assertTrue( putenv( false === $value ? $name : $name . '=' . $value ) );
		}
		return $before;
	}

	public function test_windows_refusal_does_not_resolve_or_create_a_modeled_root(): void {
		$root = '/wstm108-source-model-not-created-' . bin2hex( random_bytes( 16 ) );
		$copy = self::fixture( 'topology_admission', array( self::source(), $root, self::identity() ) );
		$class = self::isolated_class( $copy, true );
		$environment = self::environment( array( 'WSTM108_MOCK_ONLY' => '1', 'WSTM108_MOCK_ROOT' => $root ) );
		try {
			self::assertDirectoryDoesNotExist( $root );
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'Explicit native Linux synthetic namespace only.' );
			$class::admit( 'unix:///run/docker.sock' );
		} finally {
			self::environment( $environment );
			self::assertDirectoryDoesNotExist( $root, 'Windows refusal is not native Linux acceptance.' );
		}
	}

	public static function native_cases(): array {
		return array_map( static fn( string $case ) => array( $case ),
			array( 'stable-child', 'missing-opt-in', 'changed-env-root', 'outside-authority', 'wrong-device',
				'root-mode-change', 'root-replacement', 'physical-overlap' ) );
	}

	/** @dataProvider native_cases */
	public function test_copied_admit_keeps_real_native_identity_device_and_overlap_checks( string $case ): void {
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Native Linux filesystem controls required; source-model/Windows refusal is separate coverage.' );
		}
		self::assertSame( 8, PHP_INT_SIZE );
		self::assertTrue( function_exists( 'posix_geteuid' ), 'Native prerequisites cannot silently fall back to a model.' );
		$root = realpath( sys_get_temp_dir() ) . "/wstm108-model quote' space\\literal-\u{2603}-" . bin2hex( random_bytes( 16 ) );
		self::assertTrue( mkdir( $root, 0700 ) );
		self::assertTrue( mkdir( $root . '/authority', 0700 ) );
		self::assertTrue( mkdir( $root . '/checkout', 0700 ) );
		$identity = Wstm108_Files::directory( $root );
		$copy = self::fixture( 'topology_admission', array( self::source(), $root, $identity ) );
		if ( 'wrong-device' === $case ) {
			$wrong = $identity; $wrong['dev'] ^= 1;
			$old = self::fixture( 'topology_mountinfo', array( $root, $identity ) );
			$new = self::fixture( 'topology_mountinfo', array( $root, $wrong ) );
			$copy = str_replace( base64_encode( $old ), base64_encode( $new ), $copy, $count );
			self::assertSame( 1, $count, 'Only modeled table device changes, never bound root identity or native guards.' );
		}
		$class = self::isolated_class( $copy );
		$environment = self::environment( array( 'WSTM108_MOCK_ONLY' => '1', 'WSTM108_MOCK_ROOT' => $root,
			'WSTM108_HOST_AUTHORITY_ROOT' => $root . '/authority' ) );
		try {
			$endpoint = 'unix:///run/docker.sock';
			if ( 'stable-child' === $case ) {
				$before = $class::admit( $endpoint );
				self::assertSame( array( 'endpoint', 'synthetic_peer_only', 'mounts' ), array_keys( $before ) );
				self::assertSame( $endpoint, $before['endpoint'] );
				self::assertTrue( $before['synthetic_peer_only'] );
				$class::native_coordinates( array( $root . '/authority', $root . '/checkout' ), $before['mounts'] );
				$class::outside( $root . '/authority', array( $root . '/checkout' ), $before['mounts'] );
				self::assertTrue( mkdir( $root . '/legitimate-child', 0700 ) );
				$after = Wstm108_Files::directory( $root );
				self::assertSame( $identity['nlink'] + 1, $after['nlink'] );
				self::assertSame( Wstm108_HostTopology::stable_identity( $identity ), Wstm108_HostTopology::stable_identity( $after ) );
				self::assertSame( $before, $class::admit( $endpoint ), 'Mutable child/link/timestamp changes do not change the model.' );
				return;
			}
			$message = 'Synthetic namespace WORK identity changed.';
			if ( 'missing-opt-in' === $case ) {
				self::assertTrue( putenv( 'WSTM108_MOCK_ONLY' ) );
				$message = 'Explicit native Linux synthetic namespace only.';
			}
			if ( 'changed-env-root' === $case ) { self::assertTrue( putenv( 'WSTM108_MOCK_ROOT=' . dirname( $root ) ) ); }
			if ( 'outside-authority' === $case ) {
				self::assertTrue( putenv( 'WSTM108_HOST_AUTHORITY_ROOT=' . dirname( $root ) ) );
				$message = 'unsupported-filesystem-coordinate';
			}
			if ( 'wrong-device' === $case ) { $message = 'kernel-coordinate-or-identity-disagrees'; }
			if ( 'root-mode-change' === $case ) { self::assertTrue( chmod( $root, 0750 ) ); }
			if ( 'root-replacement' === $case ) {
				self::assertTrue( rename( $root, $root . '-original' ) );
				self::assertTrue( mkdir( $root, 0700 ) );
			}
			if ( 'physical-overlap' === $case ) { $message = 'physical-bind-exposes-authority'; }
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( $message );
			$admitted = $class::admit( $endpoint );
			$class::outside( $root . '/authority', array( $root ), $admitted['mounts'] );
		} finally {
			self::environment( $environment );
		}
	}

	public static function installer_cases(): array {
		return array( 'LF' => array( "\n", false ), 'CRLF' => array( "\r\n", false ),
			'LF altered core' => array( "\n", true ), 'CRLF altered core' => array( "\r\n", true ) );
	}

	/** @dataProvider installer_cases */
	public function test_complete_installer_preserves_origins_and_core_or_refuses_unsupported_host( string $ending, bool $alter_core ): void {
		if ( 'Linux' !== PHP_OS_FAMILY || PHP_VERSION_ID < 80100 ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'host-requires-native-linux-php81-posix-and-builtin-fsync' );
			Wstm108_HostBoundaryFixture::install();
			return;
		}
		Wstm108_Export::require_host();
		$origin = realpath( sys_get_temp_dir() ) . '/wstm108-installer-' . bin2hex( random_bytes( 16 ) );
		$root = $origin . '/build/work with spaces';
		$copy = $root . '/checkout';
		$core = array( 'scripts/untrusted-export.php', 'scripts/untrusted-release.php', 'scripts/destructive-retention.sh',
			'tests/e2e/untrusted-content-files.php', 'tests/e2e/untrusted-content-private-wire.php' );
		$edited = array( 'scripts/untrusted-host-topology.php', 'scripts/untrusted-authority.php',
			'scripts/untrusted-host-controller.php', 'scripts/untrusted-host-bootstrap.sh' );
		$before = array();
		foreach ( array_merge( $core, $edited ) as $path ) {
			$bytes = str_replace( "\n", $ending, str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) ) );
			$before[ $path ] = $bytes;
			foreach ( array( $origin, $copy ) as $base ) {
				if ( ! is_dir( dirname( $base . '/' . $path ) ) ) { self::assertTrue( mkdir( dirname( $base . '/' . $path ), 0700, true ) ); }
				self::assertSame( strlen( $bytes ), file_put_contents( $base . '/' . $path, $bytes ) );
			}
		}
		if ( $alter_core ) {
			self::assertSame( 7, file_put_contents( $copy . '/' . $core[0], 'changed' ) );
		}
		$environment = self::environment( array( 'WSTM108_MOCK_ONLY' => '1', 'WSTM108_MOCK_ROOT' => $root,
			'WSTM108_MOCK_CHECKOUT' => $copy, 'WSTM108_MOCK_ORIGIN' => $origin ) );
		$property = new ReflectionProperty( Wstm108_HostBoundaryFixture::class, 'substitutions' );
		$property->setAccessible( true );
		$old_sites = $property->getValue();
		$property->setValue( null, array() );
		try {
			try {
				Wstm108_HostBoundaryFixture::install();
				self::assertFalse( $alter_core, 'Altered retained core must refuse before any boundary write.' );
			} catch ( RuntimeException $error ) {
				if ( ! $alter_core ) { throw $error; }
				self::assertStringContainsString( 'byte-identical retained core', $error->getMessage() );
				self::assertFileDoesNotExist( $root . '/synthetic-host-boundaries.private.json' );
				foreach ( array_merge( $core, $edited ) as $path ) {
					self::assertSame( $before[ $path ], file_get_contents( $origin . '/' . $path ) );
					self::assertSame( $core[0] === $path ? 'changed' : $before[ $path ], file_get_contents( $copy . '/' . $path ) );
				}
				return;
			}
			foreach ( $before as $path => $bytes ) { self::assertSame( $bytes, file_get_contents( $origin . '/' . $path ) ); }
			foreach ( $core as $path ) { self::assertSame( $before[ $path ], file_get_contents( $copy . '/' . $path ) ); }
			$ledger = json_decode( file_get_contents( $root . '/synthetic-host-boundaries.private.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::assertStringContainsString( 'not real host, daemon or custody evidence', $ledger['claim'] );
			self::assertSame( $core, array_keys( $ledger['unchanged_core'] ) );
			self::assertCount( 9, $ledger['exact_sites'] );
			foreach ( $ledger['exact_sites'] as $site ) { self::assertSame( 1, $site['count'] ); }
			foreach ( $edited as $path ) {
				self::assertSame( hash( 'sha256', $before[ $path ] ), $ledger['before'][ basename( $path ) ] );
				self::assertSame( hash_file( 'sha256', $copy . '/' . $path ), $ledger['after'][ basename( $path ) ] );
			}
			$topology = self::split_admit( file_get_contents( $copy . '/scripts/untrusted-host-topology.php' ) );
			$original = self::split_admit( $before['scripts/untrusted-host-topology.php'] );
			self::assertSame( $original[0], $topology[0] );
			self::assertSame( $original[2], $topology[2] );
		} finally {
			self::environment( $environment );
			$property->setValue( null, $old_sites );
		}
	}
}
