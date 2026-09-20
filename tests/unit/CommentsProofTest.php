<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/comments-fixture.php';

final class CommentsProofTest extends TestCase {
	public function test_cleanup_failures_preserve_evidence_and_attempt_remaining_cleanup(): void {
		$artifact = tempnam( sys_get_temp_dir(), 'wstm105-' );
		$this->assertNotFalse( $artifact );
		$attempted = array();
		$cleanup = array();
		foreach ( array( 'close:editor', 'close:subscriber', 'revoke:editor', 'revoke:subscriber' ) as $label ) {
			$cleanup[ $label ] = static function () use ( $label, &$attempted ): void {
				$attempted[] = $label;
				if ( 'close:editor' === $label ) {
					throw new RuntimeException( 'Injected DELETE transport failure.' );
				}
				if ( 'revoke:editor' === $label ) {
					wstm105_assert( false, 'Cannot revoke fixture credential.' );
				}
			};
		}
		$summary = array( 'passed' => 2, 'failed' => 1, 'cases' => array( array( 'passed' => false, 'error' => 'Original HTTP failure.' ) ) );
		$this->expectOutputString( "FAIL comment cleanup close:editor: Injected DELETE transport failure.\nFAIL comment cleanup revoke:editor: WSTM105 Cannot revoke fixture credential.\n" );
		try {
			wstm105_finalize_proof( $cleanup, $summary, $artifact );
			$this->assertSame( array_keys( $cleanup ), $attempted );
			$this->assertSame( 3, $summary['failed'] );
			$this->assertSame( 2, $summary['passed'] );
			$this->assertSame( array( array( 'passed' => false, 'error' => 'Original HTTP failure.' ) ), $summary['cases'] );
			$this->assertSame(
				array(
					array( 'label' => 'close:editor', 'error' => 'Injected DELETE transport failure.' ),
					array( 'label' => 'revoke:editor', 'error' => 'WSTM105 Cannot revoke fixture credential.' ),
				),
				$summary['cleanup_errors']
			);
			$this->assertSame( $summary, json_decode( file_get_contents( $artifact ), true, 512, JSON_THROW_ON_ERROR ) );
		} finally {
			unlink( $artifact );
		}
	}

	public function test_successful_cleanup_preserves_successful_summary(): void {
		$artifact = tempnam( sys_get_temp_dir(), 'wstm105-' );
		$this->assertNotFalse( $artifact );
		$summary = array( 'passed' => 1, 'failed' => 0, 'cases' => array() );
		$expected = $summary;
		$called = false;
		try {
			wstm105_finalize_proof(
				array( 'revoke:editor' => static function () use ( &$called ): void { $called = true; } ),
				$summary,
				$artifact
			);
			$this->assertTrue( $called );
			$this->assertSame( $expected, $summary );
			$this->assertSame( $expected, json_decode( file_get_contents( $artifact ), true, 512, JSON_THROW_ON_ERROR ) );
		} finally {
			unlink( $artifact );
		}
	}
}
