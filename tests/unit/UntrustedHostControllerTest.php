<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once __DIR__ . '/fixtures/untrusted-host-boundaries.php';

final class UntrustedHostControllerTest extends TestCase {
	public static function outer_admission_cases(): array {
		$files = array();
		foreach ( array( '/trusted/php8.0', '/trusted/php8.4', '/trusted/php', '/trusted/always-success',
			'/foreign/php8.4', '/group-writable/php8.4', '/world-writable/php8.4', '/directory/php8.4', '/nonexecutable/php8.4' ) as $path ) {
			$files[ $path ] = array( 'canonical' => $path, 'regular' => true, 'executable' => true, 'uid' => 0, 'mode' => 0755 );
		}
		$files['/trusted/php']['canonical'] = '/trusted/php8.4';
		$files['/foreign/php8.4']['uid'] = 1000;
		$files['/group-writable/php8.4']['mode'] = 0775;
		$files['/world-writable/php8.4']['mode'] = 0757;
		$files['/directory/php8.4']['regular'] = false;
		$files['/nonexecutable/php8.4']['executable'] = false;
		$cases = array();
		foreach ( array( 'tests/untrusted-stage-test.sh', 'scripts/test-release-runtime.sh' ) as $entrypoint ) {
			foreach ( array( 'empty' => '', 'missing' => '/absent/php8.4', 'relative' => 'php8.4', 'noncanonical' => '/trusted/php',
				'non-php' => '/trusted/always-success', 'foreign-owner' => '/foreign/php8.4',
				'group-writable' => '/group-writable/php8.4', 'world-writable' => '/world-writable/php8.4',
				'not-regular' => '/directory/php8.4', 'not-executable' => '/nonexecutable/php8.4' ) as $label => $selected ) {
				$cases[ $entrypoint . '-' . $label ] = array( $entrypoint, 'Linux', true, $selected, '/trusted/php8.0', $files, null );
			}
			$cases[ $entrypoint . '-unset-default' ] = array( $entrypoint, 'Linux', false, '', '/trusted/php8.4', $files, '/trusted/php8.4' );
			$cases[ $entrypoint . '-companion-matrix-separate' ] = array( $entrypoint, 'Linux', true, '/trusted/php8.4', '/trusted/php8.0', $files, '/trusted/php8.4' );
			$cases[ $entrypoint . '-untrusted-matrix' ] = array( $entrypoint, 'Linux', true, '/trusted/php8.4', '/foreign/php8.4', $files, null );
			foreach ( array( 'MINGW64_NT', 'MSYS_NT', 'CYGWIN_NT' ) as $platform ) {
				$cases[ $entrypoint . '-' . $platform ] = array( $entrypoint, $platform, true, '/trusted/php8.4', '/trusted/php8.0', $files, null );
			}
		}
		return $cases;
	}

	/** @dataProvider outer_admission_cases */
	public function test_both_outer_callers_model_refusal_before_target_invocation_and_fixture_writes( string $entrypoint, string $platform, bool $explicit, string $selected, string $matrix, array $files, ?string $admitted ): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $entrypoint );
		$before = serialize( array( $selected, $matrix, $files ) );
		$result = Wstm108_HostBoundaryFixture::outer_admission_model( $source, $entrypoint, $platform, $explicit, $selected, $matrix, $files );
		self::assertSame( $before, serialize( array( $selected, $matrix, $files ) ) );
		self::assertSame( array(), $result['fixture_writes'], 'Model stops before fixture setup, not a native observation.' );
		if ( null === $admitted ) {
			self::assertSame( 'SOURCE_MODEL_BLOCKED', $result['classification'] );
			self::assertSame( 78, $result['exit'] );
			self::assertSame( array(), $result['target_invocations'], 'Even the capability probe must not invoke a rejected selection.' );
		} else {
			self::assertSame( 'SOURCE_MODEL_ADMITTED_FOR_CAPABILITY_PROBE', $result['classification'] );
			self::assertNull( $result['exit'], 'Native capability and outer runtime have not been executed or certified.' );
			self::assertSame( array( $admitted ), $result['target_invocations'] );
		}
	}

	public function test_outer_model_rejects_early_probe_windows_php_probe_and_fallback_mutants(): void {
		foreach ( array( 'tests/untrusted-stage-test.sh', 'scripts/test-release-runtime.sh' ) as $entrypoint ) {
			$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $entrypoint ) );
			$mutants = array(
				array( 'if [[ "$(uname -s)" != Linux ]]; then', ":\n" . '"$WSTM108_MOCK_PHP" -r \'echo "harmless sentinel";\'' . "\n" . 'if [[ "$(uname -s)" != Linux ]]; then' ),
				array( 'if [[ "$(uname -s)" != Linux ]]; then', 'if [[ "$(php -r \'echo PHP_OS_FAMILY;\')" == Windows ]]; then' ),
				array( '${WSTM108_HOST_PHP-$WSTM108_MOCK_MATRIX_PHP}', '${WSTM108_HOST_PHP:-$WSTM108_MOCK_MATRIX_PHP}' ),
				array( '&& "$(stat -c %u -- "$php_binary")" == 0', '&& true' ),
				array( '"$php_binary" == "$(readlink -e -- "$php_binary")"', 'true' ),
			);
			foreach ( $mutants as $change ) {
				$mutant = str_replace( $change[0], $change[1], $source, $count );
				self::assertSame( 1, $count );
				try {
					Wstm108_HostBoundaryFixture::outer_admission_model( $mutant, $entrypoint, 'Linux', true, '', '', array() );
					self::fail( 'Changed actual source must invalidate the model before interpreting any case.' );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( 'actual outer prefix differs', $error->getMessage() );
				}
			}
		}
	}

	public static function precustody_failures(): array {
		return array(
			'owned root permission failure' => array( 'exec 5>"$intent"', 1 ),
			'child reservation failure' => array( 'mkdir -m 700 -- "$directory"', 73 ),
			'stdout redirection failure' => array( 'exec 3>"$directory/controller.stdout.private"', 1 ),
			'stderr redirection failure' => array( 'exec 4>"$directory/controller.stderr.private"', 1 ),
		);
	}

	/** @dataProvider precustody_failures */
	public function test_precustody_source_routing_model_exposes_only_fixed_enum( string $site, int $native_exit ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-bootstrap.sh' ) );
		$quiet = strpos( $source, 'exec 2>/dev/null 1>/dev/null' );
		$fault = strpos( $source, $site . ' || exit $?' );
		$custody = strpos( $source, 'exec 1>&3 2>&4 || exit $?' );
		self::assertNotFalse( $quiet ); self::assertNotFalse( $fault ); self::assertNotFalse( $custody );
		self::assertLessThan( $fault, $quiet );
		self::assertLessThan( $custody, $fault );
		$prefix = substr( $source, 0, $custody );
		$trap = 'trap \'status=$?; if (( status != 0 )); then printf "%s\n" "WSTM108 bootstrap refused before controller admission." >&8; fi; exit "$status"\' EXIT';
		self::assertStringContainsString( $trap, $prefix );
		self::assertSame( 1, substr_count( $prefix, '>&8' ), 'Only the fixed EXIT enum may write to the retained public channel.' );
		self::assertStringContainsString( 'exec 8>&2', substr( $source, 0, $quiet ) );
		self::assertStringContainsString( "trap - EXIT\n\texec 8>&-", substr( $source, $custody ) );
		self::assertStringNotContainsString( '$php_binary', $prefix );
		// Routing model, not a shell/syscall observation: native failures go to
		// the quiet pre-custody sink; EXIT emits only its source-pinned constant.
		$native_stderr = 'bash: /PRIVATE_PATH_SENTINEL/owned/controller.stderr.private: permission denied';
		$private_sink = $native_stderr;
		$public = 0 !== $native_exit ? "WSTM108 bootstrap refused before controller admission.\n" : '';
		self::assertSame( $native_stderr, $private_sink );
		self::assertStringNotContainsString( 'PRIVATE_PATH_SENTINEL', $public );
		self::assertSame( "WSTM108 bootstrap refused before controller admission.\n", $public );
		self::assertNotSame( 0, $native_exit );
	}

	public static function copied_site_endings(): array {
		return array( 'LF' => array( "\n" ), 'CRLF' => array( "\r\n" ) );
	}

	/** @dataProvider copied_site_endings */
	public function test_exact_copied_site_preserves_every_other_source_byte( string $ending ): void {
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'replace' );
		$method->setAccessible( true );
		$source = 'unchanged capture' . $ending . 'exact site' . $ending . 'unchanged export' . $ending;
		$result = $method->invoke( null, $source, "exact site\n", "named boundary\n", 'synthetic-unit-site' );
		self::assertSame( 'unchanged capture' . $ending . 'named boundary' . $ending . 'unchanged export' . $ending, $result );
		self::assertSame( $source, str_replace( 'named boundary' . $ending, 'exact site' . $ending, $result ) );
	}

	public static function refused_copy_sites(): array {
		return array( 'missing' => array( "unchanged\n" ), 'changed' => array( "altered site\n" ),
			'duplicate' => array( "exact site\nexact site\n" ), 'mixed endings' => array( "first\r\nexact site\n" ) );
	}

	/** @dataProvider refused_copy_sites */
	public function test_missing_changed_duplicate_or_mixed_copy_sites_refuse( string $source ): void {
		$method = new ReflectionMethod( Wstm108_HostBoundaryFixture::class, 'replace' );
		$method->setAccessible( true );
		$this->expectException( RuntimeException::class );
		$method->invoke( null, $source, "exact site\n", "named boundary\n", 'synthetic-unit-site' );
	}

	public function test_helpers_never_inherit_github_publication_or_command_channels(): void {
		$environment = array_fill_keys( array( 'GITHUB_OUTPUT', 'GITHUB_ENV', 'GITHUB_STATE', 'GITHUB_PATH', 'GITHUB_STEP_SUMMARY', 'GITHUB_TOKEN',
			'WSTM108_EXPORT_CUSTODY', 'WSTM108_EXPORT_ROOT', 'BASH_ENV', 'ENV', 'BASH_XTRACEFD', 'PS4' ), 'forbidden-control-channel' );
		$environment['PATH'] = '/usr/bin:/bin';
		$environment['COMPOSE_PROJECT_NAME'] = 'owned-project';
		self::assertSame( array( 'PATH' => '/usr/bin:/bin', 'COMPOSE_PROJECT_NAME' => 'owned-project' ), Wstm108_HostController::child_environment( $environment ) );
		self::assertSame( array( 'PATH' => '/usr/bin:/bin', 'COMPOSE_PROJECT_NAME' => 'owned-project' ), Wstm108_HostAuthority::child_environment( $environment ) );
	}

	public static function endpoint_conflicts(): array {
		return array( array( 'remote', 'unix:///run/docker.sock' ), array( 'local', 'tcp://remote:2375' ), array( 'local', 'unix:///var/run/docker.sock' ) );
	}

	/** @dataProvider endpoint_conflicts */
	public function test_conflicting_context_and_host_refuse_before_any_query( string $context, string $host ): void {
		$calls = array();
		try {
			Wstm108_HostAuthority::resolve_endpoint( array( 'DOCKER_CONTEXT' => $context, 'DOCKER_HOST' => $host ), '/usr/bin/docker',
				static function ( array $command ) use ( &$calls ) { $calls[] = $command; return 'unix:///run/docker.sock'; } );
			self::fail( 'Conflicting selectors must refuse even when one looks local.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( array(), $calls );
			self::assertStringContainsString( 'conflicting Docker selectors', $error->getMessage() );
		}
	}

	public function test_context_resolution_is_explicit_and_remote_context_cannot_be_certified_local(): void {
		$calls = array();
		try {
			Wstm108_HostAuthority::resolve_endpoint( array( 'DOCKER_CONTEXT' => 'remote' ), '/usr/bin/docker',
				static function ( array $command ) use ( &$calls ) { $calls[] = $command; return 'tcp://remote:2375'; } );
			self::fail( 'Remote context must never be admitted.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( array( array( '/usr/bin/docker', 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}', 'remote' ) ), $calls );
		}
	}

	public static function malformed_helpers(): array {
		$frame = 'WSTM108_HOST ' . base64_encode( '{"safe":"typed"}' ) . "\n";
		return array( array( $frame, '', 73 ), array( $frame, "private-secret\0\xff", 0 ),
			array( "before-handler-secret\n" . $frame, '', 0 ), array( rtrim( $frame, "\n" ), '', 0 ),
			array( $frame . "after-frame-secret\n", '', 0 ), array( "WSTM108_HOST %%%\n", '', 0 ) );
	}

	/** @dataProvider malformed_helpers */
	public function test_failed_or_unframed_helper_capture_remains_original_data( string $stdout, string $stderr, int $exit ): void {
		$capture = array( 'capture_complete' => true, 'child_exit' => $exit,
			'streams' => array( 'stdout' => array( 'bytes' => $stdout ), 'stderr' => array( 'bytes' => $stderr ) ) );
		$before = serialize( $capture );
		try {
			Wstm108_HostController::exact_frame( $capture, 'WSTM108_HOST ' );
			self::fail( 'Helper originals cannot be discarded or promoted on an invalid frame.' );
		} catch ( RuntimeException | JsonException $error ) {
			self::assertSame( $before, serialize( $capture ) );
			self::assertSame( $exit, $capture['child_exit'] );
		}
	}

	public function test_bootstrap_opens_both_original_streams_before_php_and_has_no_postcommit_parser(): void {
		$root = dirname( __DIR__, 2 );
		$bootstrap = str_replace( "\r\n", "\n", file_get_contents( $root . '/scripts/untrusted-host-bootstrap.sh' ) );
		$stage = file_get_contents( $root . '/scripts/untrusted-stage.sh' );
		self::assertStringContainsString( 'exec 3>"$directory/controller.stdout.private"', $bootstrap );
		self::assertStringContainsString( 'exec 4>"$directory/controller.stderr.private"', $bootstrap );
		self::assertLessThan( strpos( $bootstrap, 'exec env -i' ), strpos( $bootstrap, 'exec 4>' ) );
		self::assertStringContainsString( 'exec 1>&3 2>&4', $bootstrap );
		self::assertStringNotContainsString( 'frame="$(', $stage );
		self::assertStringNotContainsString( '2>&1', $stage );
		$controller = str_replace( "\r\n", "\n", file_get_contents( $root . '/scripts/untrusted-host-controller.php' ) );
		self::assertStringContainsString( "\$release->commit( \$this->state, \$this->context, \$precommit );\n\t\treturn 0;", $controller );
		self::assertStringContainsString( "9 => array( 'file', '/dev/null', 'w' )", $controller );
	}

	public function test_opted_in_native_capture_export_and_interruption_controls(): void {
		$directory = sys_get_temp_dir() . '/wstm108-host-boundaries-' . bin2hex( random_bytes( 16 ) );
		if ( PHP_VERSION_ID < 80100 || 'Linux' !== PHP_OS_FAMILY ) {
			self::assertDirectoryDoesNotExist( $directory );
			try {
				new Wstm108_HostController( $directory, array(), getenv() );
				self::fail( 'Unsupported native host must refuse before any fixture directory or helper.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'host-requires-native-linux-php81-posix-and-builtin-fsync', $error->getMessage() );
				self::assertDirectoryDoesNotExist( $directory, 'REFUSAL-only: positive native Linux coverage remains unexecuted here.' );
			}
			return;
		}
		Wstm108_Export::require_host();
		self::assertTrue( function_exists( 'posix_geteuid' ) && function_exists( 'fsync' ), 'Required Linux component positives may not skip missing prerequisites.' );
		$environment = getenv();
		$environment['WSTM108_BOUNDARY_OPT_IN'] = '1';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$stdout = fopen( $directory . '/fixture.stdout', 'x+b' );
		$stderr = fopen( $directory . '/fixture.stderr', 'x+b' );
		self::assertIsResource( $stdout );
		self::assertIsResource( $stderr );
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/untrusted-host-boundaries.php', 'native-suite', $directory ),
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $stdout, 2 => $stderr ), $pipes, dirname( __DIR__, 2 ), $environment );
		self::assertIsResource( $process );
		$status = proc_close( $process );
		fclose( $stdout ); fclose( $stderr );
		self::assertSame( 0, $status, 'Original outputs and all generated controls are retained at ' . $directory . ': ' . file_get_contents( $directory . '/fixture.stderr' ) );
		self::assertSame( '', file_get_contents( $directory . '/fixture.stderr' ) );
		$result = json_decode( file_get_contents( $directory . '/fixture.stdout' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( array( 'actual-stdout-collision-no-child', 'actual-stderr-collision-no-child', 'actual-binary-originals-native-exit',
			'actual-php-pre-handler-failure-original-stderr',
			'synthetic-sync-failure-real-exit-and-originals', 'actual-export-collision-kept-private',
			'actual-export-refuses-foreign-source', 'actual-export-refuses-foreign-project', 'actual-export-refuses-foreign-run',
			'actual-export-refuses-forged-directory', 'actual-export-refuses-forged-file', 'actual-export-refuses-symlink',
			'actual-export-refuses-new-inode' ), $result['passed'] );
		self::assertSame( $directory, $result['root'] );
		self::assertSame( array( 'clean', 'initial-stdout-noise', 'initial-stderr-noise', 'stdout-replacement', 'stderr-replacement',
			'stdout-unlink', 'stderr-unlink', 'stdout-noise', 'stderr-noise', 'stdout-truncate', 'stderr-truncate',
			'active-buffer', 'synthetic-sync-refusal' ), $result['controller_custody'] );
		self::assertSame( array( 'empty', 'missing', 'relative', 'non-php' ), $result['interpreter_refusals'] );
	}

	public function test_host_companion_does_not_replace_matrix_or_container_interpreters(): void {
		$root = dirname( __DIR__, 2 );
		$workflow = file_get_contents( $root . '/.github/workflows/unit-tests.yml' );
		self::assertStringContainsString( "php: ['8.0', '8.4']", $workflow );
		self::assertSame( 2, substr_count( $workflow, 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240' ) );
		foreach ( array( 'readlink -e /usr/bin/php8.4', 'HOST_IDENTITY', 'HOST_SHA256', 'MATRIX_PHP', 'run: composer qa:unit',
			'run: composer test:ci-safeguards' ) as $needle ) { self::assertStringContainsString( $needle, $workflow ); }
		$shim = file_get_contents( __DIR__ . '/fixtures/untrusted-host-process' );
		self::assertStringContainsString( '*/untrusted-*.php) ;;', $shim );
		self::assertStringContainsString( 'exec "${WSTM108_MOCK_MATRIX_PHP:?Explicit matrix PHP required}" "$@"', $shim );
		$authority = file_get_contents( $root . '/scripts/untrusted-authority.php' );
		self::assertStringContainsString( "'wordpress', 'php'", $authority );
		foreach ( array( 'stage', 'runner' ) as $entry ) {
			$container = file_get_contents( $root . '/tests/e2e/untrusted-content-' . $entry . '.php' );
			self::assertNotFalse( strpos( $container, "! function_exists( 'fsync' )" ) );
			self::assertLessThan( strpos( $container, 'new Wstm108_Evidence(' ), strpos( $container, "! function_exists( 'fsync' )" ) );
			if ( 'runner' === $entry ) {
				self::assertLessThan( strpos( $container, "! function_exists( 'fsync' )" ), strpos( $container, 'Wstm108_Evidence::assert_available(' ) );
			}
		}
	}

	public function test_original_controller_custody_is_wired_at_every_precommit_boundary(): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		$blocks = array(
			'capture' => array( 'public function capture(', 'public static function exact_frame(' ),
			'export' => array( 'private function export(', 'private function publish_output(' ),
			'publication' => array( 'private function publish_output(', 'private function verify_captures(' ),
			'inventory' => array( 'private function verify_captures(', 'public function run(' ),
		);
		foreach ( $blocks as $name => $bounds ) {
			$start = strpos( $source, $bounds[0] );
			$end = strpos( $source, $bounds[1], $start );
			self::assertNotFalse( $start ); self::assertNotFalse( $end );
			$block = substr( $source, $start, $end - $start );
			self::assertStringContainsString( '$this->verify_controller_streams();', $block, $name );
			if ( in_array( $name, array( 'capture', 'publication' ), true ) ) {
				self::assertSame( 2, substr_count( $block, '$this->verify_controller_streams();' ), $name . ' checks before and after the producer.' );
			}
		}
		$start = strpos( $source, '$precommit = function (): bool {' );
		self::assertNotFalse( $start );
		$terminal = substr( $source, $start, strpos( $source, "\n\t\treturn 0;", $start ) - $start );
		self::assertStringContainsString( '$this->verify_captures();', $terminal );
		self::assertStringContainsString( "\$this->verify_controller_streams();\n\t\t\treturn true;", $terminal );
		$check = substr( $source, strpos( $source, 'private function verify_controller_streams(' ),
			strpos( $source, 'public function handle(' ) - strpos( $source, 'private function verify_controller_streams(' ) );
		self::assertSame( 2, substr_count( $check, '0 === ob_get_level()' ) );
		foreach ( array( 'fflush( $writer )', 'fflush( $handle )', 'fsync( $writer )', 'fsync( $handle )',
			"0 === \$stat['size']", '0 === ftell( $stream )', 'Wstm108_Files::read_bound(' ) as $needle ) {
			self::assertStringContainsString( $needle, $check );
		}
		foreach ( array( 'rewind(', 'ftruncate(', 'ob_end_clean(', 'fclose(' ) as $mutation ) { self::assertStringNotContainsString( $mutation, $check ); }
	}
}
