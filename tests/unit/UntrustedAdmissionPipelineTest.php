<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php';

final class UntrustedAdmissionPipelineTest extends TestCase {
	private function source( string $path ): string {
		return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ) );
	}

	public function test_full_pipeline_checks_native_admission_before_reset_and_again_before_fixture_trap(): void {
		$source = $this->source( 'scripts/e2e-test.sh' );
		$main = substr( $source, strpos( $source, "main() {\n" ) );
		$guard = "\t\trun_untrusted_admission || return \$?\n";
		self::assertSame( 2, substr_count( $main, $guard ) );
		$first = strpos( $main, $guard );
		$start = strpos( $main, "\tstart_compose || return \$?\n" );
		$second = strpos( $main, $guard, $first + 1 );
		self::assertLessThan( $start, $first );
		self::assertLessThan( $second, $start );
		self::assertLessThan( strpos( $main, "\twait_for_wordpress_files\n" ), $second );
		self::assertStringContainsString( "if [[ \"\$QA_MODE\" == contract ]]; then\n\t\ttrap cleanup_compose EXIT\n\telse", $main );
		self::assertStringContainsString( $guard . "\t\ttrap cleanup_compose EXIT", $main );
	}

	public function test_package_parent_never_cleans_a_pre_admission_child_refusal(): void {
		$source = $this->source( 'scripts/release-qa.sh' );
		self::assertStringContainsString(
			"runtime_status=0\nbash scripts/e2e-test.sh all || runtime_status=\$?\n"
			. "if [[ \"\$runtime_status\" != 0 ]]; then exit \"\$runtime_status\"; fi\ntrap cleanup_release EXIT",
			$source
		);
		self::assertSame( 1, substr_count( $source, 'trap cleanup_release EXIT' ) );
	}

	public function test_admission_only_returns_before_export_provenance_or_fixture_acquisition(): void {
		$source = $this->source( 'scripts/untrusted-host-controller.php' );
		$run = substr( $source, strpos( $source, 'public function run( array $arguments ): int {' ) );
		$admission = strpos( $run, "if ( 'admission' === \$mode ) {" );
		self::assertGreaterThan( strpos( $run, 'WstmQaRuntime::discover(' ), $admission );
		self::assertLessThan( strpos( $run, "'exclusive-export-reservation'" ), $admission );
		self::assertStringContainsString( "if ( 'admission' === \$mode ) {\n\t\t\t\\Wstm108_KernelMounts::finish_scope();\n\t\t\t\$this->verify_controller_streams();\n\t\t\treturn 0;\n\t\t}", $run );
		$stage = $this->source( 'scripts/untrusted-stage.sh' );
		self::assertStringContainsString( "QA_MODE=admission\n\trun_untrusted_content_qa", $stage );
		self::assertStringNotContainsString( 'compose ', substr( $stage, 0, strpos( $stage, 'run_untrusted_content_qa()' ) ) );
	}

	public function test_query_binds_parent_originals_and_closed_supervisor_receipt(): void {
		$source = $this->source( 'scripts/untrusted-host-controller.php' );
		foreach ( array(
			'array( 10, 11 ) === array_keys( $query_streams )',
			"'/untrusted-query.py'",
			'hrtime( true ) + 120000000000',
			'++$this->query_count > 64',
			'WSTM108_QUERY_COMPLETE_V1',
			"read_bound( \$directory . '/' . \$stream . '.log', \$query_originals[ \$stream ]['identity'] )",
			"'root_exit_observed'",
			"'secondary_errors'",
		) as $control ) {
			self::assertStringContainsString( $control, $source );
		}
		self::assertStringContainsString( "'scripts/untrusted-query.py', 'tests/e2e/bounded-list-controller.py'", $this->source( 'scripts/untrusted-provenance.php' ) );
	}

	public function test_real_query_budget_refuses_before_reserving_or_launching_anything(): void {
		$class = new ReflectionClass( Wstm108_HostController::class );
		foreach ( array( 'deadline', 'count' ) as $fault ) {
			$controller = $class->newInstanceWithoutConstructor();
			$deadline = $class->getProperty( 'query_deadline' );
			$deadline->setAccessible( true );
			$deadline->setValue( $controller, hrtime( true ) + ( 'deadline' === $fault ? -1 : 10000000000 ) );
			$count = $class->getProperty( 'query_count' );
			$count->setAccessible( true );
			$count->setValue( $controller, 'count' === $fault ? 64 : 0 );
			try {
				$controller->query( array() );
				self::fail( 'An exhausted query budget must refuse before launch.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 78, $error->getCode() );
				self::assertSame( 'WSTM108 controller refused: admission-query-budget', $error->getMessage() );
			}
			$directory = $class->getProperty( 'directory' );
			$directory->setAccessible( true );
			self::assertFalse( $directory->isInitialized( $controller ) );
		}
	}

	public function test_floor_initial_and_action_selection_share_finite_capture_and_originals(): void {
		$controller = $this->source( 'scripts/untrusted-host-controller.php' );
		self::assertSame( 2, substr_count( $controller, "array( PHP_BINARY, __DIR__ . '/qa-runtime.php', 'frame' )" ) );
		self::assertSame( 2, substr_count( $controller, 'WstmQaRuntime::discover( $runtime,' ) );
		self::assertStringContainsString( 'WstmQaRuntime::assert_binding( $runtime, $this->context', $controller );
		self::assertStringContainsString( "'foreign-or-missing-query-capture'", $controller );
		$bootstrap = $this->source( 'scripts/untrusted-host-bootstrap.sh' );
		foreach ( array( 'WSTM_PHP80_CONFIG_SHA256', 'COMPOSE_ENV_FILES', 'PHP80_MYSQL_ENV_FILE', 'WORDPRESS_URL', '"${runtime_args[@]}"' ) as $original ) {
			self::assertStringContainsString( $original, $bootstrap );
		}
		$e2e = $this->source( 'scripts/e2e-test.sh' );
		self::assertStringContainsString( 'runtime_selection="$(run_untrusted_runtime_selection shell)" || return $?', $e2e );
		$release = $this->source( 'scripts/release-qa.sh' );
		self::assertLessThan( strpos( $release, 'ZIP_FILE=' ), strpos( $release, 'run_untrusted_runtime_selection package' ) );
		self::assertStringNotContainsString( 'WstmQaRuntime::selection(', $controller );
	}

	public function test_real_php_to_python_query_handoff_retains_original_streams_and_receipt(): void {
		$directory = realpath( sys_get_temp_dir() ) . '/wstm108-query-handoff-' . bin2hex( random_bytes( 16 ) );
		if ( PHP_VERSION_ID < 80100 || 'Linux' !== PHP_OS_FAMILY ) {
			try {
				new Wstm108_HostController( $directory, array(), array() );
				self::fail( 'An unsupported host must refuse before query reservation.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'host-requires-native-linux-php81-posix-and-builtin-fsync', $error->getMessage() );
				self::assertDirectoryDoesNotExist( $directory );
			}
			return;
		}
		Wstm108_Export::require_host();
		$root = dirname( __DIR__, 2 );
		$mask = umask( 0077 );
		$passed = false;
		try {
			self::assertTrue( mkdir( $directory, 0700 ) );
			$php = realpath( PHP_BINARY );
			$command = array( $php, $root . '/scripts/qa-runtime.php', 'frame' );
			$controller = new Wstm108_HostController( $directory, Wstm108_Files::directory( $directory ),
				array( 'PATH' => '/usr/bin:/bin', 'WSTM108_HOST_PHP' => $php, 'COMPOSE_PROJECT_NAME' => 'wstm108-query-handoff' ) );
			$result = $controller->query( $command, 'handoff' );
			self::assertSame( array( 'profile' => '', 'compose_argv' => array( 'docker', 'compose', '--project-name', 'wstm108-query-handoff' ),
				'environment' => array(), 'config' => null ), $result );
			$captures = glob( $directory . '/handoff-query-*-supervised' );
			self::assertCount( 1, $captures );
			$captured = $captures[0];
			$receipt = json_decode( file_get_contents( $captured . '/receipt.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( $command, $receipt['argv'] );
			self::assertSame( 0, $receipt['exit_code'] );
			self::assertTrue( $receipt['root_exit_observed'] );
			foreach ( array( 'stdout', 'stderr' ) as $stream ) {
				$original = Wstm108_Files::file( $captured . '/' . $stream . '.log' );
				self::assertTrue( $receipt[ $stream . '_eof' ] );
				self::assertSame( $original['sha256'], $receipt[ $stream . '_sha256' ] );
				self::assertSame( strlen( $original['bytes'] ), $receipt[ $stream . '_bytes' ] );
				self::assertSame( 0100600, $original['identity']['mode'] );
				self::assertSame( posix_geteuid(), $original['identity']['uid'] );
				self::assertSame( '', file_get_contents( $captured . '/' . $stream . '.overflow.bin' ) );
			}
			self::assertSame( '', file_get_contents( $captured . '/stderr.log' ) );
			self::assertSame( $result, json_decode( file_get_contents( $captured . '/stdout.log' ), true, 512, JSON_THROW_ON_ERROR ) );
			self::assertFalse( $receipt['truncated'] );
			self::assertNull( $receipt['failure'] );
			self::assertSame( array(), $receipt['secondary_errors'] );
			$passed = true;
		} finally {
			umask( $mask );
			if ( $passed ) {
				$children = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $children as $child ) { self::assertTrue( $child->isDir() ? rmdir( $child->getPathname() ) : unlink( $child->getPathname() ) ); }
				self::assertTrue( rmdir( $directory ) );
			}
		}
	}
}
