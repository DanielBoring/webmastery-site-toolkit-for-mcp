<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UntrustedEntrypointTest extends TestCase {
	public function test_host_provenance_refusal_preserves_fatal_status_and_emits_explicit_diagnostic(): void {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/scripts/untrusted-provenance.php', 'invalid-owner', 'fixture', 'all', 'artifacts/untrusted-invalid' ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 255, proc_close( $process ) );
		self::assertSame( '', $out );
		self::assertStringContainsString( 'ERROR Untrusted source provenance:', $error );
	}

	public static function entrypoints(): array {
		return array(
			array( 'untrusted-content-stage.php', 'WSTM108_STAGE_DISPOSABLE' ),
			array( 'untrusted-content-runner.php', 'WSTM108_ALLOW_DISPOSABLE' ),
		);
	}

	/** @dataProvider entrypoints */
	public function test_actual_missing_environment_optin_exits_two_before_any_bootstrap_or_evidence_write( string $file, string $flag ): void {
		$artifact = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-entrypoint-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$prepend = $artifact . '.php';
		file_put_contents( $artifact, 'preexisting evidence must survive' );
		file_put_contents( $prepend, '<?php define("' . $flag . '", true);' );
		try {
			$environment = getenv();
			unset( $environment[ $flag ] );
			$environment['WSTM108_ARTIFACT'] = $artifact;
			$process = proc_open(
				array( PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, dirname( __DIR__ ) . '/e2e/' . $file ),
				array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes, null, $environment
			);
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 2, proc_close( $process ) );
			self::assertSame( '', $out );
			self::assertStringContainsString( $flag . '=1 is required before WordPress', $error );
			self::assertStringNotContainsString( 'wp-load.php', $error );
			self::assertSame( 'preexisting evidence must survive', file_get_contents( $artifact ) );
			self::assertFileDoesNotExist( $artifact . '.http.jsonl' );
		} finally {
			unlink( $prepend );
			unlink( $artifact );
		}
	}
}
