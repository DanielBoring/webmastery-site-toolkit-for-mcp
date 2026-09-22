<?php

declare(strict_types=1);

namespace Wstm108Proof;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Wstm108_Evidence;

require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-evidence.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-wire.php';

$wstm108_old_optin = getenv( 'WSTM108_ALLOW_DISPOSABLE' );
putenv( 'WSTM108_ALLOW_DISPOSABLE=1' );
try {
	$wstm108_source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-fixture.php' );
	eval( 'namespace Wstm108Proof; use \RuntimeException; use \Throwable; use \Wstm108_Evidence; ' . substr( $wstm108_source, 5 ) );
} finally {
	putenv( false === $wstm108_old_optin ? 'WSTM108_ALLOW_DISPOSABLE' : 'WSTM108_ALLOW_DISPOSABLE=' . $wstm108_old_optin );
}
unset( $wstm108_source, $wstm108_old_optin );

function wp_remote_post( $url, $args ) {
	$GLOBALS['wstm108_requests'][] = array( $url, $args );
	if ( empty( $GLOBALS['wstm108_responses'] ) ) {
		throw new RuntimeException( 'Unexpected HTTP call.' );
	}
	return array_shift( $GLOBALS['wstm108_responses'] );
}

function wp_remote_request( $url, $args ) {
	return wp_remote_post( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['status'];
}

function wp_remote_retrieve_header( $response, $key ) {
	return $response['headers'][ $key ] ?? '';
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_get_ability( $name ) {
	return new class() {
		public function get_description(): string {
			return 'An exact unique registered description.';
		}
	};
}

final class UntrustedProofTest extends TestCase {
	private array $files = array();

	protected function setUp(): void {
		$GLOBALS['wstm108_requests'] = array();
		$GLOBALS['wstm108_responses'] = array();
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		unset( $GLOBALS['wstm108_requests'], $GLOBALS['wstm108_responses'] );
		unset( $GLOBALS['wstm108_before_exclusive_open'] );
	}

	private function path(): string {
		$path = __DIR__ . '/.wstm108-proof-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$this->files[] = $path;
		$this->files[] = $path . '.http.jsonl';
		return $path;
	}

	/**
	 * File descriptors avoid Windows pipe backpressure entirely. Poll a direct PHP
	 * child with a deadline instead of waiting indefinitely in stream_get_contents.
	 */
	private function subprocess( string $code ): array {
		$stdout = $this->path();
		$stderr = $this->path();
		$process = proc_open(
			array( PHP_BINARY, '-r', $code ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $stdout, 'w' ), 2 => array( 'file', $stderr, 'w' ) ),
			$pipes,
			null,
			null,
			array( 'bypass_shell' => true )
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$deadline = microtime( true ) + 5;
		$timed_out = false;
		$status = proc_get_status( $process );
		try {
			while ( $status['running'] ) {
				if ( microtime( true ) >= $deadline ) {
					$timed_out = true;
					proc_terminate( $process );
					break;
				}
				usleep( 10000 );
				$status = proc_get_status( $process );
			}
		} finally {
			$closed = proc_close( $process );
		}
		$out = file_get_contents( $stdout );
		$err = file_get_contents( $stderr );
		self::assertFalse( $timed_out, 'PHP self-test subprocess exceeded five seconds: ' . $err );
		// PHP releases before 8.3 may return -1 after proc_get_status consumed exit.
		$exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
		return array( $exit, $out, $err );
	}

	private function response( string $body, int $status = 200, array $headers = array() ): array {
		return array( 'status' => $status, 'body' => $body, 'headers' => array_merge( array( 'x-wstm108-fixture' => '1' ), $headers ) );
	}

	private function initialize( Wstm108_Evidence $evidence, bool $individual = true ): Wstm108_Transport {
		$tools = $individual
			? array( array( 'name' => 'not-a-guessed-sanitized-name', 'description' => 'An exact unique registered description.', 'inputSchema' => array( 'type' => 'object' ) ) )
			: array(
				array( 'name' => 'arbitrary-gateway-executor', 'inputSchema' => array( 'properties' => array( 'ability_name' => array(), 'parameters' => array() ) ) ),
				array( 'name' => 'arbitrary-gateway-info', 'inputSchema' => array( 'properties' => array( 'ability_name' => array() ) ) ),
			);
		$GLOBALS['wstm108_responses'] = array(
			$this->response( '{"result":{}}', 200, array( 'mcp-session-id' => 'private-session' ) ),
			$this->response( '', 202 ),
			$this->response( json_encode( array( 'result' => array( 'tools' => $tools ) ) ) ),
		);
		$client = new Wstm108_Transport( $individual, 'actor', 'secret-password', $evidence, 'admin' );
		$client->initialize();
		return $client;
	}

	public function test_runner_refuses_before_wordpress_or_artifact_writes(): void {
		$artifact = $this->path();
		$runner = dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php';
		$code = 'putenv("WSTM108_ALLOW_DISPOSABLE=0"); putenv(' . var_export( 'WSTM108_ARTIFACT=' . $artifact, true ) . '); require ' . var_export( $runner, true ) . ';';
		list( $exit, $out, $err ) = $this->subprocess( $code );
		self::assertSame( 2, $exit );
		self::assertSame( '', $out );
		self::assertStringContainsString( 'WSTM108_ALLOW_DISPOSABLE=1 is required before WordPress', $err );
		self::assertStringNotContainsString( 'wp-load', $err );
		self::assertFileDoesNotExist( $artifact );
		self::assertFileDoesNotExist( $artifact . '.http.jsonl' );
	}

	public function test_http_fixture_without_optin_registers_no_hooks(): void {
		$fixture = dirname( __DIR__ ) . '/e2e/untrusted-content-fixture.php';
		$code = 'putenv("WSTM108_ALLOW_DISPOSABLE"); define("ABSPATH", __DIR__);'
			. 'function add_action(){throw new RuntimeException("registered action");}'
			. 'function add_filter(){throw new RuntimeException("registered filter");}'
			. 'require ' . var_export( $fixture, true ) . '; echo "inert";';
		list( $exit, $out, $err ) = $this->subprocess( $code );
		self::assertSame( 0, $exit, $err );
		self::assertSame( 'inert', $out );
	}

	public static function collisions(): array {
		return array( 'summary' => array( true, false ), 'journal' => array( false, true ), 'both' => array( true, true ) );
	}

	/** @dataProvider collisions */
	public function test_existing_artifacts_are_untouched_before_wordpress_or_credentials( bool $summary_exists, bool $journal_exists ): void {
		$path = $this->path();
		$summary_bytes = "Preexisting summary \0 \"do not replace\"\n";
		$journal_bytes = "Preexisting raw failed HTTP \0 \\bytes\n";
		if ( $summary_exists ) {
			file_put_contents( $path, $summary_bytes );
		}
		if ( $journal_exists ) {
			file_put_contents( $path . '.http.jsonl', $journal_bytes );
		}
		$runner = dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php';
		$code = 'putenv("WSTM108_ALLOW_DISPOSABLE=1"); putenv(' . var_export( 'WSTM108_ARTIFACT=' . $path, true ) . '); require ' . var_export( $runner, true ) . ';';
		list( $exit, $out, $err ) = $this->subprocess( $code );
		self::assertSame( 2, $exit, $err );
		self::assertSame( '', $out );
		self::assertStringContainsString( 'Evidence path already exists; refusing to overwrite', $err );
		self::assertStringNotContainsString( 'wp-load', $err );
		if ( $summary_exists ) {
			self::assertSame( $summary_bytes, file_get_contents( $path ) );
		} else {
			self::assertFileDoesNotExist( $path );
		}
		if ( $journal_exists ) {
			self::assertSame( $journal_bytes, file_get_contents( $path . '.http.jsonl' ) );
		} else {
			self::assertFileDoesNotExist( $path . '.http.jsonl' );
		}
	}

	public function test_reservation_prevents_a_second_owner_and_retains_both_evidence_files(): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		self::assertFileExists( $path );
		self::assertFileExists( $path . '.http.jsonl' );
		$evidence->save( array( 'failed' => 1 ) );
		$evidence->append( array( 'body' => 'original failed HTTP response' ) );
		unset( $evidence );
		$before = array( file_get_contents( $path ), file_get_contents( $path . '.http.jsonl' ) );
		try {
			new Wstm108_Evidence( $path );
			self::fail( 'A subsequent invocation must not acquire existing evidence.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Evidence path already exists', $error->getMessage() );
		}
		self::assertSame( $before, array( file_get_contents( $path ), file_get_contents( $path . '.http.jsonl' ) ) );
	}

	public function test_missing_evidence_directory_is_not_created_or_chmodded(): void {
		$directory = __DIR__ . '/.wstm108-missing-' . bin2hex( random_bytes( 8 ) );
		try {
			new Wstm108_Evidence( $directory . '/proof.json' );
			self::fail( 'Missing evidence directories require explicit caller ownership.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'already exist at its exact resolved path', $error->getMessage() );
		}
		self::assertDirectoryDoesNotExist( $directory );
	}

	public function test_existing_evidence_directory_metadata_is_unchanged(): void {
		$path = $this->path();
		$before = array_intersect_key( stat( __DIR__ ), array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) );
		$evidence = new Wstm108_Evidence( $path );
		$evidence->save( array( 'reserved' => true ) );
		clearstatcache();
		self::assertSame( $before, array_intersect_key( stat( __DIR__ ), array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) ) ) );
	}

	public function test_malformed_utf8_response_bytes_survive_without_base64_credential_leakage(): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$evidence->secret( 'private-password' );
		$body = "\xff\x00invalid private-password body";
		$evidence->append( array( 'status' => 500, 'body' => $body ) );
		$entry = json_decode( file_get_contents( $path . '.http.jsonl' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( "\xff\x00invalid [REDACTED] body", base64_decode( $entry['body_base64'], true ) );
		self::assertStringNotContainsString( 'private-password', file_get_contents( $path . '.http.jsonl' ) );
	}

	public function test_retained_summary_and_journal_keep_integral_floats_distinct_from_integers(): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$value = array( 'integer' => 1, 'float' => 1.0, 'object' => (object) array(), 'list' => array() );
		$evidence->save( $value );
		$evidence->append( $value );
		foreach ( array( $path, $path . '.http.jsonl' ) as $file ) {
			$actual = json_decode( file_get_contents( $file ), false, 512, JSON_THROW_ON_ERROR );
			self::assertSame( 1, $actual->integer );
			self::assertSame( 1.0, $actual->float );
			self::assertInstanceOf( \stdClass::class, $actual->object );
			self::assertSame( array(), $actual->list );
		}
	}

	public function test_session_recorder_runs_after_raw_persistence_once_per_new_token(): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$recorded = array();
		$client = new Wstm108_Transport(
			true, 'actor', 'secret-password', $evidence, 'admin', 'owned-client',
			static function ( string $token, string $label ) use ( &$recorded, $path ): void {
				self::assertFileExists( $path . '.http.jsonl' );
				$bytes = file_get_contents( $path . '.http.jsonl' );
				self::assertNotSame( '', $bytes );
				self::assertStringNotContainsString( $token, $bytes );
				$recorded[] = array( $token, $label );
			}
		);
		foreach ( array( 'private-session', 'private-session', 'different-session' ) as $token ) {
			$GLOBALS['wstm108_responses'][] = $this->response( '{"result":{}}', 200, array( 'mcp-session-id' => $token ) );
			$client->rpc( 'initialize', array() );
		}
		self::assertSame( array( array( 'private-session', 'individual:admin' ), array( 'different-session', 'individual:admin' ) ), $recorded );
		self::assertCount( 3, file( $path . '.http.jsonl' ) );
	}

	public function test_thrown_session_cleanup_transport_failure_is_retained_before_propagation(): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$client = $this->initialize( $evidence );
		try {
			$client->close();
			self::fail( 'Missing HTTP response must throw.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Unexpected HTTP call.', $error->getMessage() );
		}
		$lines = file( $path . '.http.jsonl' );
		$last = json_decode( end( $lines ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'DELETE', $last['method'] );
		self::assertSame( 0, $last['status'] );
		self::assertSame( '', $last['body'] );
		self::assertSame( 'Unexpected HTTP call.', $last['transport_error'] );
	}

	public static function racing_paths(): array {
		return array( 'summary' => array( '' ), 'journal' => array( '.http.jsonl' ) );
	}

	/** @dataProvider racing_paths */
	public function test_creation_between_preflight_and_open_never_overwrites_foreign_bytes( string $suffix ): void {
		$path = $this->path();
		$collision = $path . $suffix;
		$bytes = "Concurrent owner's evidence \0 keep unchanged\n";
		$called = false;
		$GLOBALS['wstm108_before_exclusive_open'] = static function ( string $opening, string $mode ) use ( $collision, $bytes, &$called ): void {
			self::assertSame( 'x+b', $mode );
			if ( $opening === $collision ) {
				$called = true;
				file_put_contents( $collision, $bytes );
			}
		};
		try {
			new \Wstm108EvidenceRace\Wstm108_Evidence( $path );
			self::fail( 'Exclusive reservation must reject a concurrent creator.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'Cannot exclusively reserve evidence path', $error->getMessage() );
		}
		self::assertTrue( $called, 'The collision must occur after the initial existence checks.' );
		self::assertSame( $bytes, file_get_contents( $collision ) );
		if ( '' === $suffix ) {
			self::assertFileDoesNotExist( $path . '.http.jsonl' );
		} else {
			self::assertSame( '', file_get_contents( $path ), 'Retain the partial reservation; never unlink a potentially replaced path.' );
		}
	}

	public function test_subprocess_drains_large_stdout_and_stderr_without_pipe_deadlock(): void {
		list( $exit, $out, $err ) = $this->subprocess( 'fwrite(STDERR, str_repeat("e", 1048576)); fwrite(STDOUT, str_repeat("o", 1048576));' );
		self::assertSame( 0, $exit );
		self::assertSame( 1048576, strlen( $out ) );
		self::assertSame( 1048576, strlen( $err ) );
	}

	public function test_only_record_marker_is_removed_and_full_original_shape_is_required(): void {
		$expected = array( 'post_id' => 7, 'meta' => array( 'literal' => wstm108_nested() ) );
		$actual = json_decode( json_encode( $expected + array( 'untrusted_fields' => array( 'meta' ) ) ), false, 512, JSON_THROW_ON_ERROR );
		wstm108_compare_record( $actual, $expected, 'meta' );
		unset( $actual->meta->literal->untrusted_fields );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'original object/list/scalar shape or stored value changed' );
		wstm108_compare_record( $actual, $expected, 'meta' );
	}

	public function test_extra_marker_fields_cannot_restore_redacted_login_or_email(): void {
		$expected = array( 'id' => 9, 'display_name' => 'visible', 'nicename' => 'visible', 'url' => '', 'roles' => array( 'subscriber' ), 'registered' => '2026-01-01' );
		$actual = (object) ( $expected + array( 'untrusted_fields' => array( 'display_name', 'nicename', 'url', 'login', 'email' ) ) );
		$this->expectException( RuntimeException::class );
		wstm108_compare_record( $actual, $expected, 'user' );
	}

	public function test_patch_targets_and_site_records_mark_only_present_surfaces(): void {
		$records = array(
			array( 'patch-target', array( 'type' => 'exact' ), array() ),
			array( 'patch-target', array( 'type' => 'heading', 'heading_text' => wstm108_payload(), 'heading_level' => 2 ), array( 'heading_text' ) ),
			array( 'meta-delete', array( 'post_id' => 7, 'meta_key' => 'wstm108_delete', 'deleted_count' => 2 ), array( 'meta_key' ) ),
			array( 'sitemap', array( 'url' => 'https://example.test/sitemap_index.xml', 'accessible' => false ), array( 'url' ) ),
			array( 'sitemap', array( 'url' => 'https://example.test/sitemap_index.xml', 'accessible' => true, 'entries' => array( 'https://example.test/?quote=%22&slash=%5C' ), 'entry_count' => 1 ), array( 'url', 'entries' ) ),
			array( 'robots', array( 'url' => 'https://example.test/robots.txt', 'accessible' => true ), array( 'url' ) ),
		);
		foreach ( $records as list( $kind, $expected, $markers ) ) {
			wstm108_compare_record( (object) ( $expected + array( 'untrusted_fields' => $markers ) ), $expected, $kind );
			$this->addToAssertionCount( 1 );
		}
	}

	public static function boundaries(): array {
		return array( 'individual' => array( true ), 'gateway' => array( false ) );
	}

	/** @dataProvider boundaries */
	public function test_discovered_names_and_legitimate_nested_errors_survive( bool $individual ): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$client = $this->initialize( $evidence, $individual );
		$success = array( 'success' => true, 'data' => wstm108_nested() );
		$wire = $individual ? $success : array( 'success' => true, 'data' => $success );
		$GLOBALS['wstm108_responses'][] = $this->response( json_encode( array( 'result' => array(
			'structuredContent' => $wire, 'content' => array( array( 'type' => 'text', 'text' => json_encode( $wire, JSON_THROW_ON_ERROR ) ) ),
		) ) ) );
		$result = $client->execute( 'fixture/read', array() );
		self::assertSame( \wstm108_wire_canonical( $success ), \wstm108_wire_canonical( $result ) );
		self::assertInstanceOf( \stdClass::class, $result['data'] );
		$request = json_decode( $GLOBALS['wstm108_requests'][3][1]['body'], true );
		self::assertSame( $individual ? 'not-a-guessed-sanitized-name' : 'arbitrary-gateway-executor', $request['params']['name'] );
		$text = file_get_contents( $path . '.http.jsonl' );
		self::assertStringNotContainsString( 'private-session', $text );
		self::assertStringNotContainsString( 'secret-password', $text );
		self::assertStringNotContainsString( base64_encode( 'actor:secret-password' ), $text );
	}

	public static function failing_responses(): array {
		return array(
			'bad status' => array( 500, '<html>failure secret-password</html>' ),
			'bad json' => array( 200, '{"truncated": secret-password' ),
			'rpc error' => array( 200, '{"error":{"message":"secret-password"}}' ),
		);
	}

	/** @dataProvider failing_responses */
	public function test_raw_status_and_body_are_durable_before_failure( int $status, string $body ): void {
		$path = $this->path();
		$evidence = new Wstm108_Evidence( $path );
		$client = $this->initialize( $evidence );
		$GLOBALS['wstm108_responses'][] = $this->response( $body, $status );
		try {
			$client->rpc( 'tools/call', array() );
			self::fail( 'Expected failure.' );
		} catch ( Throwable $error ) {
			$lines = file( $path . '.http.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$event = json_decode( end( $lines ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( $status, $event['status'] );
			self::assertSame( str_replace( 'secret-password', '[REDACTED]', $body ), $event['body'] );
			self::assertCount( 4, $lines );
		}
	}

	public function test_actual_error_wire_contract_is_preserved(): void {
		$error = array( 'success' => false, 'error' => array( 'code' => 'forbidden', 'reason' => 'forbidden', 'message' => 'Unchanged.', 'details' => (object) array() ) );
		$wire = (object) array( 'isError' => true, 'content' => array( (object) array( 'type' => 'text', 'text' => json_encode( $error ) ) ) );
		self::assertEquals( $error, wstm108_decode_tool( $wire, false ) );
		$wire->structuredContent = $error;
		$this->expectException( RuntimeException::class );
		wstm108_decode_tool( $wire, false );
	}

	public function test_cleanup_keeps_original_failure_and_attempts_every_action(): void {
		$path = $this->path();
		$summary_path = $path;
		$evidence = new Wstm108_Evidence( $path );
		$client = $this->initialize( $evidence );
		$GLOBALS['wstm108_responses'][] = $this->response( '<html>failed cleanup secret-password</html>', 500 );
		$attempted = array();
		$summary = array( 'passed' => 0, 'failed' => 1, 'cases' => array( array( 'label' => 'original', 'error' => 'Original malformed HTTP failure.' ) ), 'cleanup' => array() );
		$actions = array(
			'session' => static fn() => $client->close(),
			'credential' => static function () use ( &$attempted ): void {
				$attempted[] = 'credential';
				throw new RuntimeException( 'Injected credential failure.' );
			},
			'owned-post' => static function () use ( &$attempted ): void { $attempted[] = 'post'; },
			'owned-user' => static function () use ( &$attempted ): void { $attempted[] = 'user'; },
		);
		wstm108_cleanup( $actions, $summary );
		$evidence->save( $summary );
		self::assertSame( array( 'credential', 'post', 'user' ), $attempted );
		self::assertSame( 3, $summary['failed'] );
		self::assertSame( 'Original malformed HTTP failure.', $summary['cases'][0]['error'] );
		self::assertSame( $summary, json_decode( file_get_contents( $summary_path ), true ) );
		$lines = file( $path . '.http.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$event = json_decode( end( $lines ), true );
		self::assertSame( 'DELETE', $event['method'] );
		self::assertSame( 500, $event['status'] );
		self::assertSame( '<html>failed cleanup [REDACTED]</html>', $event['body'] );
	}
}

namespace Wstm108EvidenceRace;

$source = file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-evidence.php' );
eval( 'namespace Wstm108EvidenceRace; use \RuntimeException; use \Throwable; ' . str_replace( '__DIR__', var_export( dirname( __DIR__ ) . '/e2e', true ), substr( $source, 5 ) ) );

function fopen( string $path, string $mode ) {
	( $GLOBALS['wstm108_before_exclusive_open'] )( $path, $mode );
	return \fopen( $path, $mode );
}
