<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MediaManifestTest extends TestCase {
	/** @dataProvider mutations */
	public function test_media_manifest_policy( string $mutation, int $expected ): void {
		$root = dirname( __DIR__, 2 );
		$tmp = sys_get_temp_dir() . '/wstm112-policy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $tmp . '/scripts', 0777, true );
		mkdir( $tmp . '/tests/e2e', 0777, true );
		$script = $tmp . '/scripts/validate-security-qa.php';
		$file = $tmp . '/tests/e2e/abilities-manifest.json';
		copy( $root . '/scripts/validate-security-qa.php', $script );
		copy( $root . '/tests/e2e/error-contract-assertions.php', $tmp . '/tests/e2e/error-contract-assertions.php' );
		$cases = json_decode( file_get_contents( $root . '/tests/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $cases as $index => &$case ) {
			if ( 'webmastery-site-toolkit-for-mcp/upload-image' !== $case['ability'] ) { continue; }
			if ( 'remove' === $mutation ) { unset( $cases[ $index ] ); }
			if ( 'role' === $mutation && 'upload-image as subscriber' === $case['label'] ) { $case['role'] = 'admin'; }
			if ( 'allow' === $mutation && 'success' === $case['expect'] ) { $case['expect'] = 'failure'; }
			if ( 'invalid-file' === $mutation && 'invalid_file' === ( $case['expect_error_reason'] ?? '' ) ) { $case['expect_error_code'] = 'download_failed'; }
			if ( 'scenario' === $mutation && 'upload-image rejects IPv6 literal' === $case['label'] ) { unset( $cases[ $index ] ); }
		}
		unset( $case );
		file_put_contents( $file, 'invalid-json' === $mutation ? '{' : json_encode( array_values( $cases ), JSON_THROW_ON_ERROR ) );
		if ( 'missing-file' === $mutation ) { unlink( $file ); }
		try {
			$process = proc_open( [ PHP_BINARY, $script ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( $expected, proc_close( $process ), $output );
		} finally {
			if ( is_file( $file ) ) { unlink( $file ); }
			unlink( $script );
			unlink( $tmp . '/tests/e2e/error-contract-assertions.php' );
			rmdir( $tmp . '/tests/e2e' );
			rmdir( $tmp . '/tests' );
			rmdir( $tmp . '/scripts' );
			rmdir( $tmp );
		}
	}

	public static function mutations(): array {
		return [ [ 'none', 0 ], [ 'remove', 1 ], [ 'role', 1 ], [ 'allow', 1 ], [ 'invalid-file', 1 ], [ 'scenario', 1 ], [ 'invalid-json', 1 ], [ 'missing-file', 1 ] ];
	}
}
