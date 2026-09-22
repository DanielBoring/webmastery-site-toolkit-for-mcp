<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/destructive-safety-boot.php';

final class DestructiveBootTest extends TestCase {
	private function identity(): array {
		return array( 'owner' => str_repeat( 'a', 32 ), 'root' => '/owned/site', 'plugin_root' => '/owned/plugin', 'config_sha256' => str_repeat( 'b', 64 ), 'uid' => 33 );
	}

	private function body( int $days ): array {
		return array( 'identity' => $this->identity(), 'runtime' => wstm116_stage_configuration( str_repeat( 'a', 32 ), $days ), 'php' => '8.2.33', 'sapi' => 'apache2handler' );
	}

	public static function scenarios(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'fresh', 'stale-fresh', 'all-stale', 'owner', 'hash', 'optin', 'denied', 'malformed', 'uid', 'budget', 'transport' ) );
	}

	/** @dataProvider scenarios */
	public function test_bounded_read_only_convergence_gates_all_actor_and_ability_calls( string $scenario ): void {
		$clock = 0.0;
		$sleeps = $requests = $journal = array();
		$actors = $abilities = 0;
		$request = function ( float $timeout ) use ( $scenario, &$clock, &$requests, &$actors, &$abilities ): array {
			self::assertSame( 0, $actors );
			self::assertSame( 0, $abilities );
			self::assertGreaterThan( 0, $timeout );
			self::assertLessThanOrEqual( 2.0, $timeout );
			$requests[] = $timeout;
			$body = $this->body( 'all-stale' === $scenario || ( 'stale-fresh' === $scenario && count( $requests ) < 3 ) ? 30 : 0 );
			if ( 'owner' === $scenario ) { $body['identity']['owner'] = str_repeat( 'c', 32 ); }
			if ( 'hash' === $scenario ) { $body['identity']['config_sha256'] = str_repeat( 'd', 64 ); }
			if ( 'optin' === $scenario ) { $body['runtime']['disposable'] = false; }
			if ( 'uid' === $scenario ) { $body['identity']['uid'] = '33'; }
			if ( 'budget' === $scenario ) { $clock += 15.0; }
			if ( 'transport' === $scenario ) { throw new RuntimeException( 'transport failed' ); }
			return array( 'status' => 'denied' === $scenario ? 403 : 200, 'body' => 'malformed' === $scenario ? '<html>failure</html>' : json_encode( $body, JSON_THROW_ON_ERROR ) );
		};
		try {
			$boot = wstm116_converge_boot( $request,
				static function ( array $attempt ) use ( &$journal ): void { $journal[] = $attempt; },
				$this->identity(), $this->body( 0 )['runtime'], array( $this->body( 30 )['runtime'] ),
				static function ( int $seconds ) use ( &$sleeps, &$clock ): void { $sleeps[] = $seconds; $clock += $seconds; },
				static function () use ( &$clock ): float { return $clock; }
			);
			self::assertContains( $scenario, array( 'fresh', 'stale-fresh' ) );
			self::assertSame( $this->body( 0 ), $boot );
			$actors++;
			$abilities++;
		} catch ( RuntimeException $error ) {
			self::assertNotContains( $scenario, array( 'fresh', 'stale-fresh' ), $error->getMessage() );
		}
		$expected = 'all-stale' === $scenario ? 5 : ( 'stale-fresh' === $scenario ? 3 : 1 );
		self::assertCount( $expected, $requests );
		self::assertCount( $expected, $journal );
		self::assertSame( $expected > 1 ? array_fill( 0, $expected - 1, 1 ) : array(), $sleeps );
		self::assertSame( in_array( $scenario, array( 'fresh', 'stale-fresh' ), true ) ? 1 : 0, $actors );
		self::assertSame( $actors, $abilities );
	}

	public function test_restore_accepts_only_captured_original_configuration_after_owned_stale_boots(): void {
		$original = array( 'trash_days' => 17, 'disposable_defined' => false, 'disposable' => null, 'stage_defined' => false, 'stage_owner' => null );
		$responses = array( $this->body( 0 ), array_replace( $this->body( 0 ), array( 'runtime' => $original ) ) );
		$journal = array();
		$boot = wstm116_converge_boot(
			static function () use ( &$responses ): array { return array( 'status' => 200, 'body' => json_encode( array_shift( $responses ) ) ); },
			static function ( array $attempt ) use ( &$journal ): void { $journal[] = $attempt; },
			$this->identity(), $original, array( $this->body( 0 )['runtime'] ),
			static function ( int $seconds ): void { self::assertSame( 1, $seconds ); }, static fn(): float => 0.0
		);
		self::assertSame( $original, $boot['runtime'] );
		self::assertCount( 2, $journal );
	}

	public function test_probe_is_get_only_token_protected_without_mutation_optin_constants(): void {
		$source = <<<'PHP'
define('ABSPATH', '/fake-root/');
define('WSTM116_PROBE_OWNER', str_repeat('a',32));
class WP_Error { public function __construct(public $code, public $message, public $data) {} }
function add_action($hook,$callback) { $callback(); }
function register_rest_route($namespace,$route,$definition) { $GLOBALS['routes'][$route]=$definition; }
require $argv[1];
$result=[];
foreach($GLOBALS['routes'] as $route=>$definition) {
  foreach([['GET',str_repeat('a',32)],['GET','wrong'],['GET',''],['POST',str_repeat('a',32)]] as [$method,$token]) {
    $request=new class($method,$token) {
      public function __construct(private $method,private $token) {}
      public function get_method(){return $this->method;}
      public function get_header($name){return $this->token;}
    };
    $permission=$definition['permission_callback']($request);
    $result[]=['route'=>$route,'methods'=>$definition['methods'],'allowed'=>$permission===true,'status'=>$permission===true?200:$permission->data['status']];
  }
}
echo json_encode(['results'=>$result,'optin_defined'=>defined('WSTM116_DISPOSABLE_RUNTIME'),'stage_defined'=>defined('WSTM116_STAGE_TOKEN')]);
PHP;
		$process = proc_open( array( PHP_BINARY, '-r', $source, dirname( __DIR__ ) . '/e2e/destructive-safety-probe.php' ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		$decoded = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertFalse( $decoded['optin_defined'] );
		self::assertFalse( $decoded['stage_defined'] );
		self::assertCount( 8, $decoded['results'] );
		foreach ( $decoded['results'] as $index => $result ) {
			self::assertSame( 'GET', $result['methods'] );
			self::assertSame( 0 === $index % 4, $result['allowed'] );
			self::assertSame( 0 === $index % 4 ? 200 : 403, $result['status'] );
		}
	}
}
