<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-convergence.php';

final class UntrustedConvergenceTest extends TestCase {
	private string $path;

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-convergence-' . bin2hex( random_bytes( 8 ) ) . '.json';
	}

	protected function tearDown(): void {
		foreach ( array( $this->path, $this->path . '.http.jsonl' ) as $path ) {
			if ( file_exists( $path ) ) { unlink( $path ); }
		}
	}

	private static function identity(): array {
		return array( 'owner' => str_repeat( 'a', 32 ), 'root' => '/wordpress', 'plugin_root' => '/plugin', 'config_sha256' => str_repeat( 'b', 64 ), 'uid' => 33 );
	}

	private static function body( array $runtime ): array {
		return array( 'identity' => self::identity(), 'runtime' => $runtime, 'php' => PHP_VERSION, 'sapi' => 'apache2handler' );
	}

	public function test_only_known_owned_stale_boot_is_retried_with_exact_finite_budget(): void {
		$evidence = new Wstm108_Evidence( $this->path );
		$now = 0.0;
		$requests = array();
		$sleeps = array();
		$stale = array( 'optin' => false );
		try {
			wstm108_converge(
				static function ( float $timeout ) use ( &$now, &$requests, $stale ): array {
					$requests[] = $timeout;
					$now += $timeout;
					return array( 'status' => 200, 'body' => json_encode( self::body( $stale ), JSON_THROW_ON_ERROR ) );
				},
				$evidence, 'enabled', self::identity(), array( 'optin' => true ), array( $stale ),
				static function ( int $seconds ) use ( &$now, &$sleeps ): void { $sleeps[] = $seconds; $now += $seconds; },
				static function () use ( &$now ): float { return $now; }
			);
			self::fail( 'Persistent stale workers must retain the stage.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'bounded convergence', $error->getMessage() );
		}
		self::assertSame( array_fill( 0, 5, 2.0 ), $requests );
		self::assertSame( array_fill( 0, 4, 1 ), $sleeps );
		self::assertSame( 14.0, $now );
		self::assertCount( 10, file( $this->path . '.http.jsonl', FILE_IGNORE_NEW_LINES ) );
	}

	public static function immediate_failures(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'identity', 'hash', 'auth', 'malformed', 'unknown-runtime', 'transport' ) );
	}

	/** @dataProvider immediate_failures */
	public function test_unknown_or_denied_responses_are_journaled_before_immediate_failure( string $kind ): void {
		$evidence = new Wstm108_Evidence( $this->path );
		$evidence->secret( 'private-token' );
		$requests = 0;
		$body = self::body( array( 'optin' => true ) );
		if ( 'identity' === $kind ) { $body['identity']['owner'] = 'foreign'; }
		if ( 'hash' === $kind ) { $body['identity']['config_sha256'] = str_repeat( 'c', 64 ); }
		if ( 'unknown-runtime' === $kind ) { $body['runtime'] = array( 'unexpected' => true ); }
		$text = 'malformed' === $kind ? '<!invalid private-token>' : json_encode( $body, JSON_THROW_ON_ERROR );
		try {
			wstm108_converge(
				static function ( float $timeout ) use ( &$requests, $kind, $text ): array {
					++$requests;
					if ( 'transport' === $kind ) { throw new RuntimeException( 'private-token transport failure' ); }
					return array( 'status' => 'auth' === $kind ? 403 : 200, 'body' => $text );
				},
				$evidence, 'enabled', self::identity(), array( 'optin' => true ), array( array( 'optin' => false ) ),
				static function (): void { self::fail( 'Foreign/malformed/denied responses must not sleep or retry.' ); },
				static fn(): float => 0.0
			);
			self::fail( 'Expected immediate fail-closed response.' );
		} catch ( RuntimeException $error ) {
			self::assertNotSame( '', $error->getMessage() );
		}
		self::assertSame( 1, $requests );
		$journal = file_get_contents( $this->path . '.http.jsonl' );
		self::assertStringNotContainsString( 'private-token', $journal );
		$raw = json_decode( explode( "\n", $journal )[0], true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'owned-get-probe', $raw['boundary'] );
		self::assertSame( 'transport' === $kind ? '' : str_replace( 'private-token', '[REDACTED]', $text ), base64_decode( $raw['body_base64'], true ) );
	}

	public function test_full_successful_runtime_state_is_retained_not_only_the_legacy_whitelist(): void {
		$runtime = array( 'abilities' => array( 'native' => 'schema' ), 'servers' => array( 'gateway' => 'descriptor' ), 'observers' => array( 'actual-handler' ) );
		$body = self::body( $runtime );
		$result = wstm108_converge(
			static fn(): array => array( 'status' => 200, 'body' => json_encode( $body, JSON_THROW_ON_ERROR ) ),
			new Wstm108_Evidence( $this->path ), 'original', self::identity(), $runtime, array(),
			static function (): void { self::fail( 'Expected no retry.' ); }, static fn(): float => 0.0
		);
		self::assertSame( $body, $result );
		$raw = json_decode( file( $this->path . '.http.jsonl' )[0], true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $body, json_decode( base64_decode( $raw['body_base64'], true ), true, 512, JSON_THROW_ON_ERROR ) );
	}
}
