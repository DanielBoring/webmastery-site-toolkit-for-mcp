<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

final class ErrorContractAssertionsTest extends TestCase {
	public static function mutations(): array {
		return array_map( static function ( $name ) { return array( $name ); }, array( 'valid', 'false-flag', 'array-details', 'wrong-code', 'missing-reason', 'extra-content', 'structured-error', 'legacy-text' ) );
	}

	/** @dataProvider mutations */
	public function test_wire_assertions_reject_weakened_contract( string $mutation ): void {
		$envelope = array( 'success' => false, 'error' => array( 'code' => 'invalid_input', 'reason' => 'invalid_meta_key', 'message' => 'Invalid key.', 'details' => (object) array() ) );
		if ( 'array-details' === $mutation ) { $envelope['error']['details'] = array(); }
		if ( 'wrong-code' === $mutation ) { $envelope['error']['code'] = 'forbidden'; }
		if ( 'missing-reason' === $mutation ) { unset( $envelope['error']['reason'] ); }
		$wire = array( 'content' => array( array( 'type' => 'text', 'text' => json_encode( $envelope ) ) ), 'isError' => true );
		if ( 'false-flag' === $mutation ) { $wire['isError'] = false; }
		if ( 'extra-content' === $mutation ) { $wire['content'][] = $wire['content'][0]; }
		if ( 'structured-error' === $mutation ) { $wire['structuredContent'] = $envelope; }
		if ( 'legacy-text' === $mutation ) { $wire['content'][0]['text'] = 'Invalid key.'; }
		if ( 'valid' !== $mutation ) {
			$this->expectException( RuntimeException::class );
		}
		$actual = wstm118_wire_error( $wire );
		$this->assertSame( 'invalid_meta_key', $actual['error']['reason'] );
		$this->assertInstanceOf( stdClass::class, $actual['error']['details'] );
	}
}
