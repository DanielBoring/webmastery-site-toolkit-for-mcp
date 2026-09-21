<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/destructive-safety-diagnostics.php';

final class DestructiveDiagnosticsTest extends TestCase {
	public function test_file_diagnostics_distinguish_file_bytes_and_removal_without_mutating(): void {
		$path = tempnam( sys_get_temp_dir(), 'wstm116-diagnostic-' );
		try {
			file_put_contents( $path, 'owned fixture' );
			$before = wstm116_file_diagnostics( $path );
			self::assertTrue( $before['exists'] );
			self::assertSame( hash( 'sha256', 'owned fixture' ), $before['sha256'] );
			self::assertSame( fileowner( dirname( $path ) ), $before['directory_uid'] );
			self::assertSame( 'owned fixture', file_get_contents( $path ) );
			unlink( $path );
			$after = wstm116_file_diagnostics( $path );
			self::assertFalse( $after['exists'] );
			self::assertNull( $after['sha256'] );
			self::assertSame( $before['directory_uid'], $after['directory_uid'] );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function test_boot_failure_retains_exact_public_rest_code_without_request_credentials(): void {
		$body = '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}';
		$evidence = wstm116_boot_diagnostics( 404, $body );
		self::assertSame( 404, $evidence['status'] );
		self::assertSame( 'rest_no_route', $evidence['rest_error']['code'] );
		self::assertSame( hash( 'sha256', $body ), $evidence['body_sha256'] );
		self::assertSame( strlen( $body ), $evidence['body_bytes'] );
	}

	public function test_unlink_observer_returns_exact_path_without_deleting_or_touching_unowned_paths(): void {
		$path = sys_get_temp_dir() . '/wstm116-' . bin2hex( random_bytes( 8 ) );
		$handle = fopen( $path, 'x' );
		self::assertIsResource( $handle );
		fclose( $handle );
		$operations = array();
		$observer = wstm116_file_observer( 'wstm116', $operations );
		try {
			file_put_contents( $path, 'owned fixture' );
			self::assertSame( $path, $observer( $path ) );
			self::assertCount( 1, $operations );
			self::assertSame( $path, $operations[0]['path'] );
			self::assertSame( 'owned fixture', file_get_contents( $path ) );
			self::assertSame( 'unowned-path', $observer( 'unowned-path' ) );
			self::assertFalse( $observer( false ) );
			self::assertCount( 1, $operations );
		} finally {
			unlink( $path );
		}
	}

	public function test_arbitrary_debug_html_is_not_copied_into_boot_diagnostics(): void {
		$evidence = wstm116_boot_diagnostics( 500, '<html>synthetic-password</html>' );
		self::assertSame( 500, $evidence['status'] );
		self::assertStringNotContainsString( 'synthetic-password', json_encode( $evidence ) );
		self::assertArrayNotHasKey( 'rest_error', $evidence );
	}

	public function test_boot_diagnostics_are_retained_before_original_attestation_and_actor_guards(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php' );
		$diagnostics = strpos( $source, "'phase' => 'boot'" );
		self::assertLessThan( strpos( $source, "'Cannot attest actual HTTP boot before credentials.'" ), $diagnostics );
		self::assertLessThan( strpos( $source, '$id = wp_create_user(' ), $diagnostics );
		self::assertStringContainsString( "null === get_post( \$id ) && ! file_exists( \$file )", $source );
		self::assertStringNotContainsString( 'sleep(', $source );
	}
}
