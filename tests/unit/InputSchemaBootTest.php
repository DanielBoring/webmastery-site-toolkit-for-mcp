<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/input-schema-boot.php';

final class InputSchemaBootTest extends TestCase {
	private array $private_wires = array();

	private function capture_wire(): callable {
		return function ( string $body, ?int $status, int $attempt ): void {
			$this->private_wires[] = array( 'body' => $body, 'status' => $status, 'attempt' => $attempt );
		};
	}

	private function php( array $arguments, ?array $environment = null ): array {
		$process = proc_open( array_merge( array( PHP_BINARY ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $environment );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $error );
	}

	private function identity(): array {
		return array( 'owner' => str_repeat( 'a', 64 ), 'source' => str_repeat( 'b', 40 ), 'project' => 'owned-project', 'root' => '/site', 'plugin_root' => '/site/plugin', 'harness_root' => '/site/plugin/tests/e2e', 'config_sha256' => str_repeat( 'c', 64 ), 'hashes' => array( 'includes/class-input.php' => str_repeat( 'd', 64 ) ), 'uid' => 33 );
	}

	private function body( array $runtime = array( 'stage' => 'original' ) ): array {
		return array( 'identity' => $this->identity(), 'runtime' => $runtime, 'php' => '8.0.30', 'sapi' => 'apache2handler' );
	}

	public function test_get_and_private_key_authentication_are_required_and_public_owner_is_not_a_credential(): void {
		$secret = str_repeat( 'd', 64 );
		$verifier = hash( 'sha256', $secret );
		$this->assertTrue( Wstm126_Boot::authorized( 'GET', $secret, $verifier ) );
		foreach ( array( 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'get' ) as $method ) {
			$this->assertFalse( Wstm126_Boot::authorized( $method, $secret, $verifier ) );
		}
		foreach ( array( null, '', 'foreign', array( 'owner' ), 1, str_repeat( 'a', 32 ), str_repeat( 'a', 64 ), $verifier ) as $header ) {
			$this->assertFalse( Wstm126_Boot::authorized( 'GET', $header, $verifier ) );
		}
		$this->assertFalse( Wstm126_Boot::authorized( 'GET', '', '' ) );
	}

	public function test_real_runtime_sampler_preserves_existing_flags_and_detects_even_false_observation_option(): void {
		$boot = var_export( dirname( __DIR__ ) . '/e2e/input-schema-boot.php', true );
		$code = 'require ' . $boot . '; function get_option($name,$default) { return $GLOBALS["option_present"] ? false : $default; }'
			. 'define("EMPTY_TRASH_DAYS",7); define("WSTM116_DISPOSABLE_RUNTIME",false); define("WSTM116_STAGE_TOKEN","PRIVATE_EXISTING_OWNER");'
			. '$GLOBALS["option_present"]=false; $before=Wstm126_Boot::runtime(); Wstm126_Boot::baseline($before);'
			. '$GLOBALS["option_present"]=true; $after=Wstm126_Boot::runtime(); echo json_encode([$before,$after]);';
		$result = $this->php( array( '-r', $code ) );
		$this->assertSame( 0, $result[0], $result[2] );
		$runtime = json_decode( $result[1], true, 32, JSON_THROW_ON_ERROR );
		$this->assertTrue( $runtime[0]['observation_absent'] );
		$this->assertFalse( $runtime[1]['observation_absent'] );
		$this->assertSame( array( 'defined' => true, 'value' => 7 ), $runtime[0]['flags']['EMPTY_TRASH_DAYS'] );
		$this->assertSame( array( 'defined' => true, 'value' => false ), $runtime[0]['flags']['WSTM116_DISPOSABLE_RUNTIME'] );
		$this->assertSame( hash( 'sha256', 'PRIVATE_EXISTING_OWNER' ), $runtime[0]['flags']['WSTM116_STAGE_TOKEN']['value'] );
		$this->assertStringNotContainsString( 'PRIVATE_EXISTING_OWNER', $result[1] );
	}

	public function test_actual_probe_registration_is_get_only_and_independently_header_authenticated(): void {
		$probe = var_export( dirname( __DIR__ ) . '/e2e/input-schema-probe.php', true );
		$code = 'define("ABSPATH","unused"); define("WSTM126_PROBE_OWNER","owned"); define("WSTM126_PROBE_SOURCE","source"); define("WSTM126_PROBE_PROJECT","project"); define("WSTM126_PROBE_KEY_HASH",hash("sha256",str_repeat("d",64)));'
			. 'class WP_Error { public function __construct(...$args) {} }'
			. 'function add_action($hook,$callback) { $callback(); }'
			. 'function register_rest_route($namespace,$route,$args) { $GLOBALS["registration"]=[$namespace,$route,$args]; }'
			. 'require ' . $probe . ';'
			. '$r=$GLOBALS["registration"]; $auth=$r[2]["permission_callback"]; $results=[];'
			. 'foreach([["GET",str_repeat("d",64)],["POST",str_repeat("d",64)],["HEAD",str_repeat("d",64)],["GET","owned"],["GET",""]] as $pair) {'
			. '$request=new class($pair) { private array $pair; public function __construct($pair){$this->pair=$pair;} public function get_method(){return $this->pair[0];} public function get_header($name){if($name!=="X-WSTM126-Stage"){throw new RuntimeException("Wrong header");} return $this->pair[1];} };'
			. '$results[]=true===$auth($request); } echo json_encode([$r[0],$r[1],$r[2]["methods"],$results]);';
		$result = $this->php( array( '-r', $code ) );
		$this->assertSame( 0, $result[0], $result[2] );
		$this->assertSame( array( 'wstm126-stage', '/boot', 'GET', array( true, false, false, false, false ) ), json_decode( $result[1], true ) );
	}

	public function test_stage_requires_strict_optin_before_wordpress_bootstrap(): void {
		foreach ( array( false, '', 'true', '0' ) as $optin ) {
			$environment = getenv();
			unset( $environment['WSTM126_STAGE_DISPOSABLE'] );
			if ( false !== $optin ) {
				$environment['WSTM126_STAGE_DISPOSABLE'] = $optin;
			}
			$result = $this->php( array( dirname( __DIR__ ) . '/e2e/input-schema-stage.php', 'acquire', str_repeat( 'a', 32 ), str_repeat( 'b', 40 ), 'test', 'missing-artifacts', 'direct' ), $environment );
			$this->assertNotSame( 0, $result[0] );
			$this->assertStringContainsString( 'Set WSTM126_STAGE_DISPOSABLE=1', $result[1] . $result[2] );
			$this->assertStringNotContainsString( 'wp-load.php', $result[1] . $result[2] );
		}
	}

	public function test_invalid_stage_arguments_do_not_leak_tokens_even_with_argument_traces_enabled(): void {
		$environment = getenv();
		$environment['WSTM126_STAGE_DISPOSABLE'] = '1';
		$token = 'PRIVATE_OWNER_HEADER_CREDENTIAL';
		$result = $this->php( array( '-d', 'zend.exception_ignore_args=0', dirname( __DIR__ ) . '/e2e/input-schema-stage.php', 'acquire', $token, str_repeat( 'b', 40 ), 'test', 'unused', 'direct' ), $environment );
		$this->assertSame( 1, $result[0] );
		$this->assertStringNotContainsString( $token, $result[1] . $result[2] );
		$this->assertStringNotContainsString( 'Stack trace', $result[1] . $result[2] );
		$record = json_decode( trim( $result[2] ), true, 32, JSON_THROW_ON_ERROR );
		$this->assertSame( hash( 'sha256', $token ), $record['owner'] );
		$this->assertTrue( $record['retention_required'] );
	}

	public function test_packaged_stage_container_journal_root_is_outside_webroot(): void {
		$stage = file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-stage.php' );
		$this->assertStringContainsString( "'/tmp/wstm126-stage'", $stage );
		$this->assertStringNotContainsString( "'/var/www/html/wstm126-stage'", $stage );
	}

	public function test_raw_wire_digest_is_journaled_before_json_decode_failure_without_secrets(): void {
		$records = array();
		$wire = '{"secret":"DB_PASSWORD PRIVATE_TOKEN",';
		try {
			Wstm126_Boot::converge(
				static fn( float $timeout ): array => array( 'status' => 200, 'body' => $wire ),
				function ( array $record ) use ( &$records, $wire ): void {
					$this->assertSame( $wire, $this->private_wires[0]['body'] );
					$records[] = $record;
				},
				$this->identity(), array( 'stage' => 'original' ), array(),
				static function (): void { self::fail( 'Malformed response must not sleep.' ); },
				static fn(): float => 0.0, $this->capture_wire()
			);
			$this->fail( 'Malformed JSON was accepted.' );
		} catch ( JsonException $error ) {
			$this->assertCount( 1, $records );
			$this->assertSame( $wire, $this->private_wires[0]['body'] );
			$this->assertSame( hash( 'sha256', $wire ), $records[0]['body_sha256'] );
			$this->assertSame( strlen( $wire ), $records[0]['body_bytes'] );
			$this->assertStringNotContainsString( 'PRIVATE_TOKEN', json_encode( $records ) );
			$this->assertStringNotContainsString( 'DB_PASSWORD', json_encode( $records ) );
		}
	}

	public function test_denied_wire_digest_is_journaled_without_echoing_reflected_probe_credentials(): void {
		$secret = str_repeat( 'd', 64 );
		$wire = '<html>Authorization X-WSTM126-Stage: ' . $secret . '</html>';
		$records = array();
		try {
			Wstm126_Boot::converge(
				static fn(): array => array( 'status' => 403, 'body' => $wire ),
				function ( array $record ) use ( &$records, $wire ): void {
					$this->assertSame( $wire, $this->private_wires[0]['body'] );
					$records[] = $record;
				},
				$this->identity(), array( 'stage' => 'original' ), array(),
				static function (): void { self::fail( 'Denied response cannot retry.' ); },
				static fn(): float => 0.0, $this->capture_wire()
			);
			$this->fail( 'Denied response accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertCount( 1, $records );
			$this->assertSame( $wire, $this->private_wires[0]['body'] );
			$this->assertSame( 403, $records[0]['status'] );
			$this->assertSame( strlen( $wire ), $records[0]['body_bytes'] );
			$this->assertSame( hash( 'sha256', $wire ), $records[0]['body_sha256'] );
			$this->assertStringNotContainsString( $secret, json_encode( $records ) );
			$this->assertStringNotContainsString( 'Authorization', json_encode( $records ) );
		}
	}

	public function test_only_owned_stale_runtime_is_retried_with_five_two_second_calls_and_four_sleeps(): void {
		$time = 0.0;
		$timeouts = $sleeps = $records = array();
		$body = $this->body( array( 'stage' => 'active' ) );
		try {
			Wstm126_Boot::converge(
				static function ( float $timeout ) use ( &$time, &$timeouts, $body ): array {
					$timeouts[] = $timeout;
					$time += $timeout;
					return array( 'status' => 200, 'body' => json_encode( $body ) );
				},
				static function ( array $record ) use ( &$records ): void { $records[] = $record; },
				$this->identity(), array( 'stage' => 'original' ), array( array( 'stage' => 'active' ) ),
				static function ( int $seconds ) use ( &$time, &$sleeps ): void { $sleeps[] = $seconds; $time += $seconds; },
				static function () use ( &$time ): float { return $time; }, $this->capture_wire()
			);
			$this->fail( 'Stale runtime should exhaust bounded retry.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( array_fill( 0, 5, 2.0 ), $timeouts );
			$this->assertSame( array_fill( 0, 4, 1 ), $sleeps );
			$this->assertSame( 14.0, $time );
			$this->assertCount( 10, $records );
			$this->assertCount( 5, $this->private_wires );
		}
	}

	public function test_stale_can_converge_without_any_mutation_callback(): void {
		$time = 0.0;
		$calls = 0;
		$records = array();
		$body = $this->body();
		$result = Wstm126_Boot::converge(
			static function ( float $timeout ) use ( &$calls, $body ): array {
				$body['runtime'] = array( 'stage' => ++$calls < 3 ? 'active' : 'original' );
				return array( 'status' => 200, 'body' => json_encode( $body ) );
			},
			static function ( array $record ) use ( &$records ): void { $records[] = $record; },
			$this->identity(), array( 'stage' => 'original' ), array( array( 'stage' => 'active' ) ),
			static function ( int $seconds ) use ( &$time ): void { $time += $seconds; },
			static function () use ( &$time ): float { return $time; }, $this->capture_wire()
		);
		$this->assertSame( $body, $result );
		$this->assertSame( 3, $calls );
		$this->assertSame( 2.0, $time );
		$this->assertSame( $body, $records[5]['safe_body'] );
	}

	/** @dataProvider rejected_responses */
	public function test_foreign_denied_error_and_malformed_responses_fail_immediately( string $kind ): void {
		$body = $this->body();
		$status = 200;
		switch ( $kind ) {
			case 'denied': $status = 403; break;
			case 'server-error': $status = 500; break;
			case 'redirect': $status = 302; break;
			case 'owner': $body['identity']['owner'] = str_repeat( 'f', 64 ); break;
			case 'source': $body['identity']['source'] = str_repeat( 'f', 40 ); break;
			case 'project': $body['identity']['project'] = 'foreign'; break;
			case 'root': $body['identity']['root'] = '/foreign'; break;
			case 'plugin': $body['identity']['plugin_root'] = '/checkout-fallback'; break;
			case 'harness': $body['identity']['harness_root'] = '/foreign-harness'; break;
			case 'config': $body['identity']['config_sha256'] = str_repeat( 'f', 64 ); break;
			case 'hashes': $body['identity']['hashes'] = array(); break;
			case 'uid': $body['identity']['uid'] = 0; break;
			case 'uid-string': $body['identity']['uid'] = '33'; break;
			case 'uid-negative': $body['identity']['uid'] = -1; break;
			case 'sapi': $body['sapi'] = 'cli'; break;
			case 'php': $body['php'] = '8.0.30 SECRET'; break;
			case 'extra-field': $body['secret'] = 'PRIVATE_TOKEN'; break;
			case 'extra-identity': $body['identity']['password'] = 'PRIVATE_TOKEN'; break;
			case 'state': $body['runtime'] = array( 'stage' => 'foreign' ); break;
			case 'optin-denied': $body['runtime'] = array( 'stage' => false ); break;
			case 'malformed': $body = null; break;
		}
		$wire = 'oversize' === $kind ? str_repeat( 'x', 16385 ) : json_encode( $body );
		$calls = 0;
		$records = array();
		try {
			Wstm126_Boot::converge(
				static function () use ( &$calls, $status, $wire ): array { $calls++; return array( 'status' => $status, 'body' => $wire ); },
				static function ( array $record ) use ( &$records ): void { $records[] = $record; },
				$this->identity(), array( 'stage' => 'original' ), array( array( 'stage' => 'active' ) ),
				static function (): void { self::fail( 'Invalid response must never retry.' ); },
				static fn(): float => 0.0, $this->capture_wire()
			);
			$this->fail( 'Invalid response was accepted: ' . $kind );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 1, $calls );
			$this->assertCount( 1, $records );
			$this->assertArrayNotHasKey( 'safe_body', $records[0] );
			$this->assertSame( $wire, $this->private_wires[0]['body'] );
		}
	}

	public static function rejected_responses(): array {
		return array_map( static fn( string $name ): array => array( $name ), array( 'denied', 'server-error', 'redirect', 'owner', 'source', 'project', 'root', 'plugin', 'harness', 'config', 'hashes', 'uid', 'uid-string', 'uid-negative', 'sapi', 'php', 'extra-field', 'extra-identity', 'state', 'optin-denied', 'malformed', 'oversize' ) );
	}

	public function test_transport_failure_is_sanitized_and_never_retried(): void {
		$records = array();
		try {
			Wstm126_Boot::converge(
				static function (): array { throw new RuntimeException( 'Authorization: PRIVATE_TOKEN' ); },
				static function ( array $record ) use ( &$records ): void { $records[] = $record; },
				$this->identity(), array(), array(), static function (): void { self::fail( 'No transport retry.' ); }, static fn(): float => 0.0, $this->capture_wire()
			);
			$this->fail( 'Transport exception was accepted.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringNotContainsString( 'PRIVATE_TOKEN', $error->getMessage() . json_encode( $records ) );
			$this->assertCount( 1, $records );
			$this->assertTrue( $records[0]['transport_error'] );
		}
	}

	public function test_private_wire_retention_failure_stops_before_decode_or_public_body_logging(): void {
		$this->expectExceptionMessage( 'Private raw evidence unavailable.' );
		Wstm126_Boot::converge(
			static fn(): array => array( 'status' => 200, 'body' => '{malformed-secret' ),
			static function (): void { self::fail( 'Do not continue public logging when durable private retention failed.' ); },
			$this->identity(), array( 'stage' => 'original' ), array(),
			static function (): void { self::fail( 'No retry after private evidence failure.' ); },
			static fn(): float => 0.0,
			static function (): void { throw new RuntimeException( 'Private raw evidence unavailable.' ); }
		);
	}

	public function test_expired_clock_rejects_otherwise_valid_response(): void {
		$time = 0.0;
		$body = $this->body();
		$this->expectExceptionMessage( 'finite budget' );
		Wstm126_Boot::converge(
			static function () use ( &$time, $body ): array { $time = 15.0; return array( 'status' => 200, 'body' => json_encode( $body ) ); },
			static function (): void {}, $this->identity(), array( 'stage' => 'original' ), array(),
			static function (): void { self::fail( 'Expired request cannot retry.' ); }, static function () use ( &$time ): float { return $time; }, $this->capture_wire()
		);
	}
}
