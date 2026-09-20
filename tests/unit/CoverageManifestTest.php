<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CoverageManifestTest extends TestCase {
	public static function mutations(): array {
		return array(
			array( 'none', 'security', 0 ),
			array( 'none', 'e2e', 0 ),
			array( 'denial-code', 'security', 1 ),
			array( 'callback-code', 'security', 1 ),
			array( 'media-state', 'security', 1 ),
			array( 'contributor-private', 'security', 1 ),
			array( 'contributor-future-message', 'security', 1 ),
			array( 'taxonomy-allowed', 'security', 1 ),
			array( 'unknown-role', 'e2e', 1 ),
			array( 'disabled-state', 'e2e', 1 ),
			array( 'invalid-capability', 'e2e', 1 ),
			array( 'invalid-stored-post', 'e2e', 1 ),
		);
	}

	/** @dataProvider mutations */
	public function test_regression_policy_rejects_weakened_evidence( string $mutation, string $validator, int $expected ): void {
		$root = dirname( __DIR__, 2 );
		$tmp = sys_get_temp_dir() . '/wstm120-policy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $tmp . '/scripts', 0777, true );
		mkdir( $tmp . '/tests/e2e', 0777, true );
		$name = 'security' === $validator ? 'validate-security-qa.php' : 'validate-e2e-manifest.php';
		$script = $tmp . '/scripts/' . $name;
		$file = $tmp . '/tests/e2e/abilities-manifest.json';
		copy( $root . '/scripts/' . $name, $script );
		copy( $root . '/tests/e2e/error-contract-assertions.php', $tmp . '/tests/e2e/error-contract-assertions.php' );
		$cases = json_decode( file_get_contents( $root . '/tests/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $cases as &$case ) {
			if ( 'webmastery-site-toolkit-for-mcp/delete-media' === $case['ability'] && 'failure' === $case['expect'] ) {
				if ( 'denial-code' === $mutation ) { $case['expect_error_code'] = 'not_found'; }
				if ( 'callback-code' === $mutation ) { $case['assert_permission'] = 'not_found'; }
				if ( 'media-state' === $mutation ) { unset( $case['assert_unchanged'] ); }
				if ( 'disabled-state' === $mutation ) { $case['assert_unchanged'] = false; }
			}
			if ( 'contributor' === $case['role'] ) {
				if ( 'unknown-role' === $mutation ) { $case['role'] = 'contributor_typo'; }
				if ( 'contributor-private' === $mutation && 'private' === ( $case['input']['status'] ?? '' ) ) { unset( $case['assert_unchanged'] ); }
				if ( 'contributor-future-message' === $mutation && 'future' === ( $case['input']['status'] ?? '' ) && isset( $case['assert_values']['error'] ) ) { $case['assert_values']['error'] = 'Invalid date.'; }
				if ( 'invalid-capability' === $mutation ) { $case['assert_capabilities'] = array( array( 'capability' => 'edit_posts', 'args' => array(), 'allowed' => 'true' ) ); }
				if ( 'invalid-stored-post' === $mutation ) { $case['assert_stored_post'] = array( 'fields' => array() ); }
			}
			if ( 'taxonomy-allowed' === $mutation && 'webmastery-site-toolkit-for-mcp/list-tags' === $case['ability'] && 'subscriber' === $case['role'] ) {
				$case['role'] = 'admin';
			}
		}
		unset( $case );
		try {
			file_put_contents( $file, json_encode( $cases, JSON_THROW_ON_ERROR ) );
			$process = proc_open( array( PHP_BINARY, $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $process );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( $expected, proc_close( $process ), $output );
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
}
