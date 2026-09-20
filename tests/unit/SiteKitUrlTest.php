<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/site-kit-stubs.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-site-kit.php';

final class SiteKitUrlTest extends TestCase {
	public static function urls(): array {
		return array(
			array( 'http://localhost', 'http://LOCALHOST/path?x=1', true ),
			array( 'http://localhost', 'http://localhost:80/', true ),
			array( 'http://localhost:80', 'http://localhost/', true ),
			array( 'https://example.test', 'HTTPS://EXAMPLE.TEST:443/path', true ),
			array( 'https://example.test:443', 'https://example.test/', true ),
			array( 'http://localhost:8080', 'http://localhost:8080/', true ),
			array( 'http://localhost:8080', 'http://localhost/', false ),
			array( 'http://localhost', 'http://localhost:8080/', false ),
			array( 'http://localhost', 'https://localhost/', false ),
			// Existing policy compares effective ports, not scheme equality.
			array( 'http://localhost', 'https://localhost:80/', true ),
			array( 'http://localhost', 'http://other.test/', false ),
			array( 'http://localhost', 'ftp://localhost:80/', false ),
			array( 'http://localhost', '//localhost/', false ),
			array( 'http://localhost', '/relative', false ),
			array( 'http://localhost', 'http://user@localhost/', false ),
			array( 'http://localhost', 'http://user:pass@localhost/', false ),
			array( 'http://localhost', 'http://localhost/#section', false ),
			array( 'http://localhost', 'http://localhost/#', false ),
			array( 'http://localhost', 'http://localhost:99999/', false ),
		);
	}

	/** @dataProvider urls */
	public function test_same_site_policy( string $home, string $url, bool $expected ): void {
		$had_home = array_key_exists( 'wstm120_home', $GLOBALS );
		$previous = $GLOBALS['wstm120_home'] ?? null;
		try {
			$GLOBALS['wstm120_home'] = $home;
			$method = new ReflectionMethod( Webmastery_MCP_Site_Kit::class, 'is_same_site_url' );
			$method->setAccessible( true );
			$this->assertSame( $expected, $method->invoke( null, $url ) );
		} finally {
			if ( $had_home ) {
				$GLOBALS['wstm120_home'] = $previous;
			} else {
				unset( $GLOBALS['wstm120_home'] );
			}
		}
	}
}
