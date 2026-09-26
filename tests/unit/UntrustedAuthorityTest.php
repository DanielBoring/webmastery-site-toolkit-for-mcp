<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-evidence.php';

/** Pure protocol/policy fixtures are not positive native host-authority proof. */
final class UntrustedAuthorityTest extends TestCase {
	private static function capture( string $stdout, string $stderr = '', int $status = 0, bool $complete = true ): array {
		$identity = array( 'dev' => 1, 'ino' => 2, 'mode' => 0100600, 'nlink' => 1, 'uid' => 1001, 'gid' => 1001 );
		$file = static fn( $bytes ) => array( 'identity' => $identity, 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
		return array( 'action' => 'original', 'child_exit' => $status, 'capture_complete' => $complete, 'streams' => array( 'stdout' => $file( $stdout ), 'stderr' => $file( $stderr ) ) );
	}

	public static function rejected_streams(): array {
		$expected = "WSTM108 stage original completed with owned evidence.\n";
		return array(
			'pre-handler-stdout' => array( "unknown secret warning\n" . $expected, '', 0, true ),
			'provider-stderr' => array( $expected, "unknown provider credential\n", 0, true ),
			'bootstrap-both-streams' => array( "unknown stdout\n" . $expected, "unknown stderr\n", 1, true ),
			'exit-zero-extra-line' => array( $expected . "\n", '', 0, true ),
			'truncated-stdout' => array( substr( $expected, 0, -1 ), '', 0, true ),
			'capture-write-failure' => array( $expected, '', 0, false ),
			'child-failed-with-success-shaped-output' => array( $expected, '', 73, true ),
		);
	}

	/** @dataProvider rejected_streams */
	public function test_synthetic_process_framing_never_discards_diagnostics_or_first_exit( string $stdout, string $stderr, int $status, bool $complete ): void {
		$capture = self::capture( $stdout, $stderr, $status, $complete );
		$before = $capture;
		try {
			Wstm108_HostAuthority::frame( $capture, "WSTM108 stage original completed with owned evidence.\n" );
			self::fail( 'Unexpected or incomplete streams must veto retirement.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $before, $capture );
			$witness = Wstm108_HostAuthority::witness( $capture, false );
			self::assertSame( $status, $witness['child_exit'] );
			self::assertSame( $complete, $witness['capture_complete'] );
			self::assertFalse( $witness['validated'] );
			self::assertSame( hash( 'sha256', $stdout ), $witness['stdout']['sha256'] );
			self::assertSame( hash( 'sha256', $stderr ), $witness['stderr']['sha256'] );
			self::assertSame( strlen( $stdout ), $witness['stdout']['length'] );
			self::assertSame( strlen( $stderr ), $witness['stderr']['length'] );
			$public = json_encode( $witness, JSON_THROW_ON_ERROR );
			self::assertStringNotContainsString( 'unknown', $public );
			self::assertStringNotContainsString( 'credential', $public );
			self::assertStringNotContainsString( 'argv', $public );
		}
	}

	public static function rejected_anchor_frames(): array {
		$valid = 'WSTM108_ANCHOR ' . base64_encode( '{"version":1}' ) . "\n";
		return array(
			'failed-acquire' => array( $valid, '', 42, true ),
			'pre-handler-warning' => array( 'warning ' . $valid, '', 0, true ),
			'extra-line' => array( $valid . $valid, '', 0, true ),
			'extra-newline' => array( $valid . "\n", '', 0, true ),
			'truncated' => array( rtrim( $valid ), '', 0, true ),
			'stderr' => array( $valid, 'private warning', 0, true ),
			'partial-capture' => array( $valid, '', 0, false ),
			'malformed-base64' => array( "WSTM108_ANCHOR %%%\n", '', 0, true ),
			'malformed-json' => array( 'WSTM108_ANCHOR ' . base64_encode( '{"version":' ) . "\n", '', 0, true ),
		);
	}

	/** @dataProvider rejected_anchor_frames */
	public function test_synthetic_acquire_never_extracts_a_last_line_or_adopts_failed_output( string $stdout, string $stderr, int $status, bool $complete ): void {
		$capture = self::capture( $stdout, $stderr, $status, $complete );
		$before = $capture;
		try {
			Wstm108_HostAuthority::encoded_frame( $capture, 'WSTM108_ANCHOR ' );
			self::fail( 'Invalid acquire frame must remain uninterpreted.' );
		} catch ( RuntimeException | JsonException $error ) {
			self::assertSame( $before, $capture );
			self::assertSame( $status, $capture['child_exit'] );
		}
	}

	public function test_synthetic_exact_successful_frame_is_only_protocol_evidence(): void {
		$frame = 'WSTM108_ANCHOR ' . base64_encode( '{"version":1}' ) . "\n";
		self::assertSame( array( 'version' => 1 ), Wstm108_HostAuthority::encoded_frame( self::capture( $frame ), 'WSTM108_ANCHOR ' ) );
		Wstm108_HostAuthority::frame( self::capture( "complete\n" ), "complete\n" );
		self::assertTrue( Wstm108_HostAuthority::witness( self::capture( "complete\n" ), true )['validated'] );
	}

	public function test_synthetic_release_authorization_is_strictly_precommit_and_nonsecret(): void {
		$class = new ReflectionClass( Wstm108_ReleaseGuard::class );
		$release = $class->newInstanceWithoutConstructor();
		$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'release-control',
			'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
		$host_property = $class->getProperty( 'host' );
		$host_property->setAccessible( true );
		$host_property->setValue( $release, array( 'binding' => $binding, 'context_sha256' => str_repeat( 'd', 64 ),
			'directory' => '/private/authority', 'credentials' => 'unknown-private-secret' ) );
		$state = array( 'prepared' => array( 'private' => 'unknown-private-secret' ),
			'processes' => array( array( 'private' => 'unknown-private-secret' ) ) );
		$method = $class->getMethod( 'authorization' );
		$method->setAccessible( true );
		$receipt = $method->invoke( $release, $state, array( 'private/path' => 'unknown-private-secret' ) );
		self::assertSame( array( 'version', 'state', 'binding', 'context_sha256', 'prepared_sha256', 'process_verdicts_sha256',
			'evidence_inventory_sha256', 'primary_guard_absent', 'companion_guard_present', 'commit_point', 'commit_outcome', 'qa_outcome' ), array_keys( $receipt ) );
		self::assertSame( 1, $receipt['version'] );
		self::assertSame( 'precommit_authorized', $receipt['state'] );
		self::assertSame( $binding, $receipt['binding'] );
		self::assertTrue( $receipt['primary_guard_absent'] );
		self::assertTrue( $receipt['companion_guard_present'] );
		self::assertSame( 'final_companion_unlink', $receipt['commit_point'] );
		self::assertSame( 'not_observed', $receipt['commit_outcome'] );
		self::assertSame( 'not_asserted', $receipt['qa_outcome'] );
		foreach ( array( 'context_sha256', 'prepared_sha256', 'process_verdicts_sha256', 'evidence_inventory_sha256' ) as $key ) {
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $receipt[ $key ] );
		}
		self::assertStringNotContainsString( 'private', json_encode( $receipt, JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'credentials', json_encode( $receipt, JSON_THROW_ON_ERROR ) );
	}

	private static function assert_terminal_sources( string $source, string $host ): void {
		$source = str_replace( "\r\n", "\n", $source );
		$host = str_replace( "\r\n", "\n", $host );
		$start = strpos( $source, 'public function commit(' );
		self::assertNotFalse( $start );
		$commit = substr( $source, $start );
		self::assertMatchesRegularExpression( '/self::require\( @unlink\( \$path \), [^\n]+\);\n\t\}\n\}\s*$/D', $commit );
		self::assertStringNotContainsString( 'Wstm108_Files::remove(', $commit, 'The generic remover has a post-unlink check and is not the terminal commit primitive.' );
		foreach ( array( 'echo ', 'fwrite(', '->receipt(', 'Wstm108_Files::create(' ) as $write ) { self::assertStringNotContainsString( $write, $commit ); }
		self::assertStringContainsString( "\$release->commit( \$this->state, \$this->context, \$precommit );\n\t\treturn 0;", $host );
		self::assertStringContainsString( "callable \$precommit", $commit );
		self::assertMatchesRegularExpression( '/self::require\( true === \$precommit\(\), [^\n]+\);\n\t\t\/\/[^\n]+\n\t\tself::require\( @unlink/', $commit );
	}

	public function test_terminal_release_has_no_postcommit_output_or_validation_dependency(): void {
		self::assert_terminal_sources(
			file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-release.php' ),
			file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' )
		);
		$shell = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-stage.sh' );
		self::assertStringContainsString( 'wstm108_host_bootstrap', $shell );
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-bootstrap.sh' );
		self::assertStringContainsString( 'exec env -i', $bootstrap );
		$controller = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		self::assertStringContainsString( "\$release->commit( \$this->state, \$this->context, \$precommit );\n\t\treturn 0;", $controller );
		self::assertStringNotContainsString( 'wstm116_clear_retention "$owner" "$source"', $shell );
	}

	public static function terminal_line_endings(): array {
		return array( 'LF' => array( "\n" ), 'CRLF' => array( "\r\n" ) );
	}

	/** @dataProvider terminal_line_endings */
	public function test_terminal_source_contract_accepts_both_line_endings( string $ending ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-release.php' ) );
		$host = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		self::assert_terminal_sources( str_replace( "\n", $ending, $source ), str_replace( "\n", $ending, $host ) );
	}

	public static function postcommit_source_mutants(): array {
		return array( 'LF release' => array( "\n", 'release' ), 'CRLF release' => array( "\r\n", 'release' ),
			'LF host' => array( "\n", 'host' ), 'CRLF host' => array( "\r\n", 'host' ) );
	}

	/** @dataProvider postcommit_source_mutants */
	public function test_line_ending_normalization_does_not_accept_postcommit_operations( string $ending, string $target ): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-release.php' ) );
		$host = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-host-controller.php' ) );
		if ( 'release' === $target ) {
			$needle = "self::require( @unlink( \$path ), 'terminal unlink did not report success; release outcome must not be inferred.' );";
			$source = str_replace( $needle, $needle . "\n\t\t\$this->prerequisites( \$state, \$context );", $source, $count );
		} else {
			$needle = "\$release->commit( \$this->state, \$this->context, \$precommit );";
			$host = str_replace( $needle, $needle . "\n\t\t\t\tWstm108_HostAuthority::frame( \$capture, \"postcommit dependency\" );", $host, $count );
		}
		self::assertSame( 1, $count, 'The negative control must modify the actual terminal boundary.' );
		try {
			self::assert_terminal_sources( str_replace( "\n", $ending, $source ), str_replace( "\n", $ending, $host ) );
		} catch ( \PHPUnit\Framework\ExpectationFailedException $expected ) {
			self::assertStringContainsString( 'release' === $target ? 'matches PCRE pattern' : 'contains', $expected->getMessage() );
			return;
		}
		self::fail( 'A postcommit operation must fail the same contract checks for either line ending.' );
	}

	public function test_public_projection_excludes_unknown_fields_and_unregistered_secrets_even_for_passed_cases(): void {
		$secret = 'not-in-any-redaction-list-private-credential';
		$descriptor = (object) array(
			'name' => 'actual-discovered-not-guessed', 'description' => $secret,
			'inputSchema' => (object) array( 'type' => 'object', 'default' => $secret ),
			'outputSchema' => (object) array( 'literal' => $secret ),
			'annotations' => (object) array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'title' => $secret ),
			'unknown' => array( 'body' => $secret ),
		);
		$summary = array(
			'status' => 'failed', 'passed' => 1, 'failed' => 1, 'error' => $secret,
			'cases' => array( array( 'label' => 'fixture:passed', 'passed' => true, 'descriptor' => $descriptor, 'response' => array( 'data' => $secret ), 'expected' => $secret ) ),
			'unknown' => $secret,
			'actors' => array( 'reader' => array( 'id' => 3, 'roles' => array( 'subscriber' ), 'caps' => (object) array( 'list_users' => true, $secret => true ) ) ),
		);
		$before = serialize( $summary );
		$public = Wstm108_Evidence::public_summary( $summary );
		self::assertSame( $before, serialize( $summary ), 'Projection must not rewrite original values or types.' );
		self::assertStringNotContainsString( $secret, json_encode( $public, JSON_THROW_ON_ERROR ) );
		self::assertSame( 1, $public['passed'] );
		self::assertSame( 1, $public['failed'] );
		self::assertSame( 'failed', $public['status'] );
		self::assertSame( 'actual-discovered-not-guessed', $public['cases'][0]['descriptor']['name'] );
		self::assertSame( array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true ), (array) $public['cases'][0]['descriptor']['annotations'] );
		self::assertSame( hash( 'sha256', wstm108_wire_canonical( $descriptor->inputSchema ) ), $public['cases'][0]['descriptor']['input_schema_sha256'] );
		self::assertSame( hash( 'sha256', serialize( $summary['cases'][0] ) ), $public['cases'][0]['private_case_sha256'] );
		self::assertTrue( $public['actors']['reader']['list_users'] );
	}

	public function test_native_windows_refusal_precedes_filesystem_or_docker_authority(): void {
		// Linux exercises the same source with only the OS constant substituted.
		// This is a refusal control, never a positive native-Windows authority claim.
		$class = Wstm108_HostAuthority::class;
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php' );
			$start = strpos( $source, 'final class Wstm108_HostAuthority' );
			$end = strpos( $source, "\nif ( realpath( ", $start );
			eval( 'namespace Wstm108WindowsAuthorityRefusal; use \RuntimeException; use \Throwable; use \Wstm108_Files; const PHP_OS_FAMILY = "Windows"; ' . substr( $source, $start, $end - $start ) );
			$class = 'Wstm108WindowsAuthorityRefusal\\Wstm108_HostAuthority';
		}
		foreach ( array( 'create', 'query' ) as $method ) {
			try {
				if ( 'create' === $method ) {
					$class::create( 'must-not-be-created', 'must-not-be-read', array(), '', array() );
				} else {
					$class::query( array( 'must-not-be-launched' ) );
				}
				self::fail( 'Unproven native ownership must refuse before any command.' );
			} catch ( RuntimeException $error ) {
				self::assertMatchesRegularExpression( '/native|unsupported/', $error->getMessage() );
			}
		}
		self::assertFileDoesNotExist( 'must-not-be-created' );
	}
}
