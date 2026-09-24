<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionsLoadingTest extends TestCase {
	public function testPluginBootstrapLoadsWorkingHelperWithoutCheckingCapabilities(): void {
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/permissions-bootstrap.php' ) . ' 2>&1', $output, $status );
		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array(
			'loaded' => true,
			'before' => array(),
			'factory_calls' => array(),
			'result' => true,
			'calls' => array( 'manage_options' ),
			'ability_hook' => true,
		), json_decode( implode( "\n", $output ), true, 512, JSON_THROW_ON_ERROR ) );
	}

	public function testReleasePackageMapIncludesExactHelperSource(): void {
		$root = dirname( __DIR__, 2 );
		require_once $root . '/scripts/release-lib.php';
		$files = release_source_files( $root );
		self::assertArrayHasKey( 'includes/class-permissions.php', $files );
		self::assertSame( hash_file( 'sha256', $root . '/includes/class-permissions.php' ), $files['includes/class-permissions.php'] );
	}
}
