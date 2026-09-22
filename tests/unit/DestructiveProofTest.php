<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DestructiveProofTest extends TestCase {
	public static function guards(): array {
		return array(
			array( array( 'WSTM116_DISPOSABLE' => '' ), 'Set WSTM116_DISPOSABLE=1' ),
			array( array( 'WSTM116_DISPOSABLE' => '1', 'WSTM116_BOUNDARY' => 'typo' ), 'Unknown destructive-safety boundary.' ),
			array( array( 'WSTM116_DISPOSABLE' => '1', 'WSTM116_BOUNDARY' => 'http', 'WSTM116_ARTIFACT' => '' ), 'WSTM116_ARTIFACT must name a new file' ),
		);
	}

	/** @dataProvider guards */
	public function test_runner_refuses_before_wordpress_or_credentials( array $environment, string $message ): void {
		$runner = dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php';
		$process = proc_open( array( PHP_BINARY, $runner ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), $environment ) );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( $message, $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
	}

	public static function weakened_evidence(): array {
		$cases = array( array( 'confirmation' ), array( 'dry-run-state' ), array( 'missing-case' ), array( 'changed-type' ) );
		foreach ( array( 'bulk-publish-posts', 'bulk-trash-posts', 'delete-media', 'delete-category', 'delete-tag' ) as $slug ) {
			foreach ( array( 'true', 1 ) as $confirm ) {
				foreach ( array( 'wrong-layer', 'wrong-code', 'wrong-reason' ) as $mutation ) {
					$cases[] = array( $mutation, $slug, $confirm );
				}
			}
		}
		return $cases;
	}

	/** @dataProvider weakened_evidence */
	public function test_security_policy_rejects_weakened_safety_cases( string $mutation, string $slug = 'bulk-publish-posts', $confirm = 'true' ): void {
		$root = dirname( __DIR__, 2 );
		$directory = sys_get_temp_dir() . '/wstm116-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory . '/scripts', 0777, true );
		mkdir( $directory . '/tests/e2e', 0777, true );
		$validator = $directory . '/scripts/validate-security-qa.php';
		$manifest = $directory . '/tests/e2e/abilities-manifest.json';
		$assertions = $directory . '/tests/e2e/error-contract-assertions.php';
		copy( $root . '/scripts/validate-security-qa.php', $validator );
		copy( $root . '/tests/e2e/error-contract-assertions.php', $assertions );
		$cases = json_decode( file_get_contents( $root . '/tests/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $cases as $index => &$case ) {
			if ( 'webmastery-site-toolkit-for-mcp/' . $slug !== $case['ability'] ) {
				continue;
			}
			if ( 'confirmation' === $mutation && 'success' === $case['expect'] ) {
				unset( $case['input']['confirm'] );
			}
			if ( 'dry-run-state' === $mutation && true === ( $case['input']['dry_run'] ?? false ) ) {
				unset( $case['assert_unchanged'] );
			}
			if ( 'missing-case' === $mutation && ! array_key_exists( 'confirm', $case['input'] ) ) {
				unset( $cases[ $index ] );
			}
			if ( $confirm === ( $case['input']['confirm'] ?? null ) ) {
				if ( 'wrong-layer' === $mutation ) {
					$case['expect_error_code'] = 'precondition_failed';
					$case['expect_error_reason'] = 'missing_confirmation';
				} elseif ( 'changed-type' === $mutation ) {
					$case['input']['confirm'] = true;
				} elseif ( 'wrong-code' === $mutation ) {
					$case['expect_error_code'] = 'precondition_failed';
				} elseif ( 'wrong-reason' === $mutation ) {
					$case['expect_error_reason'] = 'missing_confirmation';
				}
			}
		}
		unset( $case );
		try {
			file_put_contents( $manifest, json_encode( array_values( $cases ), JSON_THROW_ON_ERROR ) );
			$process = proc_open( array( PHP_BINARY, $validator ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 1, proc_close( $process ), $output );
			self::assertStringContainsString( in_array( $mutation, array( 'wrong-code', 'wrong-reason' ), true ) ? 'Negative cases require canonical code, precise reason, and envelope shape.' : $slug, $output );
		} finally {
			unlink( $manifest );
			unlink( $validator );
			unlink( $assertions );
			rmdir( $directory . '/tests/e2e' );
			rmdir( $directory . '/tests' );
			rmdir( $directory . '/scripts' );
			rmdir( $directory );
		}
	}
}
