<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UntrustedEntrypointTest extends TestCase {
	public static function entrypoints(): array {
		return array( array( 'untrusted-content-runner.php', 2 ), array( 'bounded-list-benchmark.php', 1 ) );
	}

	/** @dataProvider entrypoints */
	public function testMissingProductOptinRefusesBeforeBootstrapOrExistingEvidenceMutation( string $file, int $status ): void {
		$artifact = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm167-entrypoint-' . bin2hex( random_bytes( 8 ) ) . '.json';
		file_put_contents( $artifact, 'preexisting evidence must survive' );
		try {
			$environment = getenv();
			unset( $environment['E2E_OPT_IN'] );
			$environment['WSTM_PRODUCT_CONTEXT'] = $artifact;
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/e2e/' . $file ),
				array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, $environment );
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( $status, proc_close( $process ) );
			self::assertSame( '', $out );
			self::assertNotSame( '', $error );
			self::assertStringNotContainsString( 'wp-load.php', $error );
			self::assertSame( 'preexisting evidence must survive', file_get_contents( $artifact ) );
			self::assertFileDoesNotExist( $artifact . '.http.jsonl' );
		} finally {
			unlink( $artifact );
		}
	}
}
