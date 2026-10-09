<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

final class ErrorContractAssertionsTest extends TestCase {
	public static function mutations(): array {
		return array_map( static function ( $name ) { return array( $name ); }, array( 'valid', 'valid-null', 'false-flag', 'array-details', 'wrong-code', 'missing-reason', 'extra-content', 'structured-error', 'legacy-text' ) );
	}

	/** @dataProvider mutations */
	public function test_wire_assertions_reject_weakened_contract( string $mutation ): void {
		$envelope = array( 'success' => false, 'error' => array( 'code' => 'invalid_input', 'reason' => 'invalid_meta_key', 'message' => 'Invalid key.', 'details' => (object) array() ) );
		if ( 'array-details' === $mutation ) { $envelope['error']['details'] = array(); }
		if ( 'wrong-code' === $mutation ) { $envelope['error']['code'] = 'forbidden'; }
		if ( 'missing-reason' === $mutation ) { unset( $envelope['error']['reason'] ); }
		$wire = array( 'content' => array( array( 'type' => 'text', 'text' => json_encode( $envelope ) ) ), 'isError' => true );
		if ( 'valid-null' === $mutation ) { $wire['structuredContent'] = null; }
		if ( 'false-flag' === $mutation ) { $wire['isError'] = false; }
		if ( 'extra-content' === $mutation ) { $wire['content'][] = $wire['content'][0]; }
		if ( 'structured-error' === $mutation ) { $wire['structuredContent'] = $envelope; }
		if ( 'legacy-text' === $mutation ) { $wire['content'][0]['text'] = 'Invalid key.'; }
		if ( ! in_array( $mutation, array( 'valid', 'valid-null' ), true ) ) {
			$this->expectException( RuntimeException::class );
		}
		$actual = wstm118_wire_error( $wire );
		$this->assertSame( 'invalid_meta_key', $actual['error']['reason'] );
		$this->assertInstanceOf( stdClass::class, $actual['error']['details'] );
	}

	public function test_disposable_guard_fails_closed_before_wordpress_or_credentials(): void {
		$runner = dirname( __DIR__ ) . '/e2e/error-contract-runner.php';
		foreach ( array( '', '0', 'true' ) as $value ) {
			$process = proc_open( array( PHP_BINARY, '-n', $runner ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array( 'WSTM118_DISPOSABLE' => $value ) );
			$this->assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( 1, proc_close( $process ) );
			$this->assertSame( "Error-contract proof requires WSTM118_DISPOSABLE=1 on an owned disposable site.\n", $output );
		}
		$source = file_get_contents( $runner );
		$this->assertLessThan( strpos( $source, "require_once '/var/www/html/wp-load.php'" ), strpos( $source, "getenv( 'WSTM118_DISPOSABLE' )" ) );
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/e2e-test.sh' );
		$this->assertStringContainsString( '-e WSTM118_DISPOSABLE=1 wordpress php "${CONTAINER_PLUGIN_ROOT}/tests/e2e/error-contract-runner.php"', $script );
	}
}
