<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebmasterVerificationPolicyTest extends TestCase {
	public static function missingProjectionProvider(): array {
		return array(
			array( 'subscriber', 'data.google.site_kit' ),
			array( 'subscriber', 'data.checks.google_site_kit' ),
			array( 'author', 'data.google.site_kit' ),
			array( 'author', 'data.checks.google_site_kit' ),
		);
	}

	/** @dataProvider missingProjectionProvider */
	public function testValidatorRejectsDroppedOmissionAssertions( string $role, string $path ): void {
		$root = dirname( __DIR__, 2 );
		$manifest = json_decode( file_get_contents( $root . '/tests/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $manifest as &$case ) {
			if ( 'webmastery-site-toolkit-for-mcp/webmaster-verification-status' === $case['ability'] && $role === $case['role'] ) {
				$case['assert_missing_paths'] = array_values( array_diff( $case['assert_missing_paths'] ?? array(), array( $path ) ) );
			}
		}
		unset( $case );
		$temp = sys_get_temp_dir() . '/wstm114-policy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $temp . '/scripts', 0777, true );
		mkdir( $temp . '/tests/e2e', 0777, true );
		try {
			copy( $root . '/scripts/validate-security-qa.php', $temp . '/scripts/validate-security-qa.php' );
			file_put_contents( $temp . '/tests/e2e/abilities-manifest.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
			$output = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $temp . '/scripts/validate-security-qa.php' ) . ' 2>&1', $output, $status );
			self::assertSame( 1, $status );
			self::assertStringContainsString( "successful {$role} cases omitting both private Site Kit projections", implode( "\n", $output ) );
		} finally {
			unlink( $temp . '/scripts/validate-security-qa.php' );
			unlink( $temp . '/tests/e2e/abilities-manifest.json' );
			rmdir( $temp . '/tests/e2e' );
			rmdir( $temp . '/tests' );
			rmdir( $temp . '/scripts' );
			rmdir( $temp );
		}
	}
}
