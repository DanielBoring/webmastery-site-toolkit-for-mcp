<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DestructiveCleanupProofTest extends TestCase {
	public static function invalid_proofs(): array {
		return array(
			array( 'completed', null ), array( 'completed', false ), array( 'completed', 'true' ), array( 'completed', 1 ),
			array( 'cleanup_complete', null ), array( 'cleanup_complete', false ), array( 'cleanup_complete', 'true' ), array( 'cleanup_complete', 1 ),
			array( 'source_sha', 'foreign' ), array( 'boundary', 'individual' ),
			array( 'trash_days', '30' ), array( 'trash_days', 0 ), array( 'trash_days', 30.0 ),
			array( 'cleanup', array() ), array( 'cleanup', (object) array() ), array( 'cleanup', array( true ) ),
			array( 'cleanup', null ), array( 'cleanup', array( 'post' => false ) ),
			array( 'cleanup', array( 'post' => 'true' ) ), array( 'cleanup', array( 'post' => 1 ) ),
		);
	}

	private function proof(): array {
		return array( 'completed' => true, 'cleanup_complete' => true, 'source_sha' => str_repeat( 'a', 40 ),
			'boundary' => 'direct', 'trash_days' => 30, 'cleanup' => array( 'post' => true ), 'failed' => 1 );
	}

	private function verify( string $contents ): int {
		$path = tempnam( sys_get_temp_dir(), 'cleanup-proof-' );
		try {
			file_put_contents( $path, $contents );
			$process = proc_open(
				array( PHP_BINARY, dirname( __DIR__, 2 ) . '/scripts/destructive-cleanup-proof.php', $path, str_repeat( 'a', 40 ), 'direct', '30' ),
				array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes
			);
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			return proc_close( $process );
		} finally {
			unlink( $path );
		}
	}

	/** @dataProvider invalid_proofs */
	public function test_incomplete_untyped_or_foreign_proof_retains_runtime( string $key, $value ): void {
		$proof = $this->proof();
		$proof[ $key ] = $value;
		self::assertSame( 1, $this->verify( json_encode( $proof, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) );
	}

	public function test_successful_cleanup_is_independent_of_prior_case_failure(): void {
		self::assertSame( 0, $this->verify( json_encode( $this->proof(), JSON_THROW_ON_ERROR ) ) );
	}

	public function test_malformed_report_retains_runtime(): void {
		self::assertSame( 1, $this->verify( '{"cleanup_complete":true' ) );
	}
}
