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
		foreach ( array( 'permissions', 'post-access', 'post-content', 'post-meta', 'post-writes', 'bulk-posts', 'post-revisions', 'featured-image', 'content-patch' ) as $helper ) {
			$path = 'includes/class-' . $helper . '.php';
			self::assertArrayHasKey( $path, $files );
			self::assertSame( hash_file( 'sha256', $root . '/' . $path ), $files[ $path ] );
		}

	}

	public function testSharedHelpersLoadInsideAbilityHookBeforeConsumers(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/webmastery-site-toolkit-for-mcp.php' );
		$hook = strpos( $source, "add_action( 'wp_abilities_api_init'" );
		$consumer = strpos( $source, "require_once __DIR__ . '/includes/class-posts.php'" );
		foreach ( array( 'post-access', 'post-content', 'post-meta', 'post-writes', 'bulk-posts', 'post-revisions', 'featured-image', 'content-patch' ) as $helper ) {
			$require = "require_once __DIR__ . '/includes/class-" . $helper . ".php'";
			self::assertSame( 1, substr_count( $source, $require ) );
			$position = strpos( $source, $require );
			self::assertGreaterThan( $hook, $position );
			self::assertLessThan( $consumer, $position );
		}
	}
}
