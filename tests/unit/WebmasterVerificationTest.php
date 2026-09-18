<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm114\Webmastery_MCP_Webmaster_Verification as Verification;

require_once __DIR__ . '/fixtures/wstm114-verification.php';

final class WebmasterVerificationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm114'] = array(
			'caps' => array( 'read' ),
			'home' => 'https://wstm114.example.test',
			'site' => 1,
			'now' => 1000,
			'cache' => array(),
			'counts' => array( 'http' => 0, 'dns' => 0, 'plugins' => 0, 'active' => 0, 'cache' => 0, 'home' => 0 ),
			'requests' => array(),
			'installed' => true,
			'active' => true,
			'failure' => false,
		);
	}

	private function assertPublic( array $response ): void {
		self::assertTrue( $response['success'] );
		self::assertArrayNotHasKey( 'site_kit', $response['data']['google'] );
		self::assertArrayNotHasKey( 'google_site_kit', $response['data']['checks'] );
		self::assertStringNotContainsString( 'Site Kit', json_encode( $response ) );
		self::assertStringNotContainsString( 'google-site-kit', json_encode( $response ) );
		$summary = array( 'pass' => 0, 'warn' => 0, 'unknown' => 0 );
		foreach ( $response['data']['checks'] as $check ) {
			++$summary[ $check['status'] ];
		}
		self::assertSame( $summary, $response['data']['summary'] );
		self::assertCount( 7, $response['data']['checks'] );
	}

	public function testSubscriberAndAuthorHavePublicResultsWithoutPluginInspection(): void {
		foreach ( array( array( 'read' ), array( 'read', 'edit_posts' ) ) as $caps ) {
			$GLOBALS['wstm114']['caps'] = $caps;
			$response = Verification::execute();
			$this->assertPublic( $response );
			self::assertSame( array( 'pass' => 6, 'warn' => 0, 'unknown' => 1 ), $response['data']['summary'] );
			self::assertSame( 0, $GLOBALS['wstm114']['counts']['plugins'] );
			self::assertSame( 0, $GLOBALS['wstm114']['counts']['active'] );
		}
	}

	public function testDirectDenialPrecedesAllWorkOnColdAndWarmCache(): void {
		foreach ( array( false, true ) as $warm ) {
			if ( $warm ) {
				$GLOBALS['wstm114']['caps'] = array( 'read', 'activate_plugins' );
				Verification::execute();
			}
			$GLOBALS['wstm114']['caps'] = array( 'activate_plugins' );
			$before = $GLOBALS['wstm114']['counts'];
			$result = Verification::execute();
			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'forbidden', $result->get_error_code() );
			self::assertSame( $before, $GLOBALS['wstm114']['counts'] );
			self::assertInstanceOf( WP_Error::class, Verification::permission() );
		}
	}

	public function testAdministratorThenSubscriberCacheNeverContainsPrivateState(): void {
		$GLOBALS['wstm114']['caps'] = array( 'read', 'activate_plugins' );
		$admin = Verification::execute();
		self::assertSame( true, $admin['data']['google']['site_kit']['active'] );
		self::assertSame( $admin['data']['google']['site_kit'], $admin['data']['checks']['google_site_kit'] );
		self::assertSame( array( 'pass' => 7, 'warn' => 0, 'unknown' => 1 ), $admin['data']['summary'] );
		$GLOBALS['wstm114']['caps'] = array( 'read' );
		$before = $GLOBALS['wstm114']['counts'];
		$this->assertPublic( Verification::execute() );
		foreach ( array( 'http', 'dns', 'plugins', 'active' ) as $count ) {
			self::assertSame( $before[ $count ], $GLOBALS['wstm114']['counts'][ $count ] );
		}
		self::assertCount( 1, $GLOBALS['wstm114']['cache'] );
		self::assertArrayHasKey(
			'webmastery_mcp_verification_v1_' . hash( 'sha256', '1:https://wstm114.example.test/' ),
			$GLOBALS['wstm114']['cache']
		);
		self::assertStringNotContainsString( 'site_kit', json_encode( $GLOBALS['wstm114']['cache'] ) );
		self::assertStringNotContainsString( 'summary', json_encode( $GLOBALS['wstm114']['cache'] ) );
	}

	public function testSubscriberThenAdministratorInspectsFreshPrivateStateEveryTime(): void {
		$this->assertPublic( Verification::execute() );
		$GLOBALS['wstm114']['caps'] = array( 'read', 'activate_plugins' );
		foreach ( array( array( true, true, 'pass' ), array( true, false, 'warn' ), array( false, false, 'warn' ) ) as $state ) {
			$GLOBALS['wstm114']['installed'] = $state[0];
			$GLOBALS['wstm114']['active'] = $state[1];
			$result = Verification::execute();
			self::assertSame( $state[0], $result['data']['google']['site_kit']['installed'] );
			self::assertSame( $state[1], $result['data']['google']['site_kit']['active'] );
			self::assertSame( $state[2], $result['data']['google']['site_kit']['status'] );
			self::assertSame( 'google-site-kit/google-site-kit.php', $result['data']['google']['site_kit']['plugin'] );
			self::assertSame( 'pass' === $state[2] ? 7 : 6, $result['data']['summary']['pass'] );
			self::assertSame( 'warn' === $state[2] ? 1 : 0, $result['data']['summary']['warn'] );
		}
		self::assertSame( 3, $GLOBALS['wstm114']['counts']['plugins'] );
		self::assertSame( 3, $GLOBALS['wstm114']['counts']['active'] );
		self::assertSame( 4, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 1, $GLOBALS['wstm114']['counts']['dns'] );
	}

	public function testWarmRepeatsExpireAtSixtySecondsAndAreScopedToHomeAndSite(): void {
		$first = Verification::execute();
		self::assertSame( 4, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 1, $GLOBALS['wstm114']['counts']['dns'] );
		self::assertCount( 1, $GLOBALS['wstm114']['cache'] );
		self::assertSame( 60, array_values( $GLOBALS['wstm114']['cache'] )[0]['ttl'] );
		$GLOBALS['wstm114']['now'] += 59;
		for ( $i = 0; $i < 10; ++$i ) {
			self::assertSame( $first, Verification::execute() );
		}
		self::assertSame( 4, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 1, $GLOBALS['wstm114']['counts']['dns'] );
		++$GLOBALS['wstm114']['now'];
		Verification::execute();
		self::assertSame( 8, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 2, $GLOBALS['wstm114']['counts']['dns'] );
		$GLOBALS['wstm114']['home'] = 'https://other-wstm114.example.test';
		$result = Verification::execute();
		self::assertSame( $GLOBALS['wstm114']['home'] . '/', $result['data']['home_url'] );
		self::assertSame( 12, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 3, $GLOBALS['wstm114']['counts']['dns'] );
		$GLOBALS['wstm114']['home'] .= '/subdirectory';
		Verification::execute();
		self::assertSame( 16, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 4, $GLOBALS['wstm114']['counts']['dns'] );
		++$GLOBALS['wstm114']['site'];
		Verification::execute();
		self::assertSame( 20, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 5, $GLOBALS['wstm114']['counts']['dns'] );
	}

	public function testPublicFailuresRemainVisibleAndAreCached(): void {
		$GLOBALS['wstm114']['failure'] = true;
		$result = Verification::execute();
		$this->assertPublic( $result );
		self::assertFalse( $result['data']['home_reached'] );
		self::assertSame( array( 'pass' => 0, 'warn' => 0, 'unknown' => 7 ), $result['data']['summary'] );
		self::assertSame( 'wstm114 public request failed', $result['data']['google']['homepage_meta']['detail'] );
		self::assertSame( 'unknown', $result['data']['dns_txt']['status'] );
		self::assertSame( $result, Verification::execute() );
		self::assertSame( 3, $GLOBALS['wstm114']['counts']['http'] );
		self::assertSame( 1, $GLOBALS['wstm114']['counts']['dns'] );
	}
}
