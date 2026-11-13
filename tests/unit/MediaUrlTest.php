<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-media.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once __DIR__ . '/fixtures/media-pinning-stubs.php';

use WstmMediaPinning\State as PinState;

final class MediaResolverFixture extends Webmastery_MCP_Media {
	public static $ipv4 = [ '93.184.215.14' ];
	public static $dns = [];
	public static $calls = [];

	protected static function resolve_image_ipv4( $host ) {
		self::$calls[] = 'A:' . $host;
		return self::$ipv4;
	}

	protected static function resolve_image_dns( $host ) {
		self::$calls[] = 'DNS:' . $host;
		return self::$dns[ $host ] ?? [];
	}

	public static function validate( $url ) {
		return parent::validate_public_image_url( $url );
	}

	public static function validate_with_ips( $url, &$ips ) {
		return parent::validate_public_image_url( $url, $ips );
	}
}

final class MediaUrlTest extends TestCase {
	protected function setUp(): void {
		MediaResolverFixture::$ipv4 = [ '93.184.215.14' ];
		MediaResolverFixture::$dns = [];
		MediaResolverFixture::$calls = [];
	}

	/** @dataProvider deniedAddresses */
	public function test_private_reserved_and_mapped_addresses( string $address ): void {
		$method = new ReflectionMethod( Webmastery_MCP_Media::class, 'is_private_ip' );
		$method->setAccessible( true );
		self::assertTrue( $method->invoke( null, $address ) );
	}

	public static function deniedAddresses(): array {
		return array_map( static fn( $ip ) => [ $ip ], [
			'127.0.0.1', '10.1.2.3', '192.168.1.1', '169.254.169.254', '0.0.0.0',
			'::', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '2001:db8::1', 'ff02::1',
			'2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
			'::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1', 'not-an-ip',
		] );
	}

	/** @dataProvider documentationNeighbors */
	public function test_adjacent_documentation_addresses_remain_allowed( string $address ): void {
		$method = new ReflectionMethod( Webmastery_MCP_Media::class, 'is_private_ip' );
		$method->setAccessible( true );
		self::assertFalse( $method->invoke( null, $address ) );
		MediaResolverFixture::$dns = [ 'image.test' => [ [ 'type' => 'AAAA', 'ipv6' => $address ] ] ];
		self::assertIsString( MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	public static function documentationNeighbors(): array {
		return [ [ '2001:db7:ffff:ffff:ffff:ffff:ffff:ffff' ], [ '2001:db9::' ] ];
	}

	/** @dataProvider literalUrls */
	public function test_local_or_ipv6_literals_do_not_resolve( string $url ): void {
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( $url ) );
		self::assertSame( [], MediaResolverFixture::$calls );
	}

	public static function literalUrls(): array {
		return array_map( static fn( $url ) => [ $url ], [
			'http://localhost/x.png', 'http://site.local/x.png', 'http://x.localhost/x.png',
			'http://127.0.0.1/x.png', 'http://[::1]/x.png', 'http://[2606:4700::1111]/x.png',
		] );
	}

	public function test_public_literal_and_a_only_host_remain_allowed(): void {
		self::assertSame( 'http://93.184.215.14/x.png', MediaResolverFixture::validate( 'http://93.184.215.14/x.png' ) );
		self::assertSame( [], MediaResolverFixture::$calls );
		self::assertSame( 'https://image.test/x.png', MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		self::assertSame( [ 'A:image.test', 'DNS:image.test' ], MediaResolverFixture::$calls );
	}

	public function test_public_dual_stack_answers_remain_allowed(): void {
		MediaResolverFixture::$dns = [ 'image.test' => [
			[ 'type' => 'AAAA', 'ipv6' => '2606:4700::1111' ],
			[ 'type' => 'AAAA', 'ipv6' => '::ffff:93.184.215.14' ],
		] ];
		self::assertIsString( MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	public function test_mixed_ipv4_answers_fail(): void {
		MediaResolverFixture::$ipv4[] = '10.0.0.1';
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	/** @dataProvider deniedAddresses */
	public function test_mixed_ipv6_answers_fail( string $address ): void {
		MediaResolverFixture::$dns = [ 'image.test' => [
			[ 'type' => 'AAAA', 'ipv6' => '2606:4700::1111' ],
			[ 'type' => 'AAAA', 'ipv6' => $address ],
		] ];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	public function test_resolver_failure_is_not_empty_aaaa(): void {
		MediaResolverFixture::$dns = [ 'image.test' => false ];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$dns = [ 'image.test' => [] ];
		self::assertIsString( MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$ipv4 = false;
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$ipv4 = [];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	public function test_cname_target_checked_and_cycles_rejected(): void {
		MediaResolverFixture::$dns = [
			'image.test' => [ [ 'type' => 'CNAME', 'target' => 'Alias.test.' ] ],
			'alias.test' => [ [ 'type' => 'AAAA', 'ipv6' => 'fd00::1' ] ],
		];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$dns['alias.test'] = [];
		self::assertIsString( MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$dns['alias.test'] = [ [ 'type' => 'CNAME', 'target' => 'image.test.' ] ];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
		MediaResolverFixture::$dns['alias.test'] = false;
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image.test/x.png' ) );
	}

	public function test_dns_alias_work_is_bounded(): void {
		for ( $i = 0; $i < 20; ++$i ) {
			MediaResolverFixture::$dns[ 'image' . $i . '.test' ] = [ [ 'type' => 'CNAME', 'target' => 'image' . ( $i + 1 ) . '.test' ] ];
		}
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate( 'https://image0.test/x.png' ) );
		self::assertCount( 17, MediaResolverFixture::$calls );
	}

	public function test_validation_reports_the_addresses_it_accepted_without_changing_its_result(): void {
		MediaResolverFixture::$ipv4 = [ '93.184.215.14', '93.184.215.15', '93.184.215.14' ];
		self::assertSame( 'https://image.test/x.png', MediaResolverFixture::validate_with_ips( 'https://image.test/x.png', $ips ) );
		self::assertSame( [ '93.184.215.14', '93.184.215.15' ], $ips );
		self::assertSame( 'http://93.184.215.14/x.png', MediaResolverFixture::validate_with_ips( 'http://93.184.215.14/x.png', $ips ) );
		self::assertSame( [], $ips, 'An IP literal needs no pin.' );
		MediaResolverFixture::$ipv4[] = '10.0.0.1';
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate_with_ips( 'https://image.test/x.png', $ips ) );
		self::assertNull( $ips );
		MediaResolverFixture::$ipv4 = [ '93.184.215.14' ];
		MediaResolverFixture::$dns = [ 'image.test' => [ [ 'type' => 'AAAA', 'ipv6' => 'fd00::1' ] ] ];
		self::assertInstanceOf( WP_Error::class, MediaResolverFixture::validate_with_ips( 'https://image.test/x.png', $ips ) );
		self::assertNull( $ips );
	}

	public function test_pin_entries_cover_port_and_cache_key_spellings(): void {
		$method = new ReflectionMethod( Webmastery_MCP_Media::class, 'image_pin_entries' );
		$method->setAccessible( true );
		self::assertSame( [
			'image.test:443'  => 'image.test:443:93.184.215.14,93.184.215.15',
			'image.test.:443' => 'image.test.:443:93.184.215.14,93.184.215.15',
		], $method->invoke( null, 'https://image.test/x.png', [ '93.184.215.14', '93.184.215.15' ] ) );
		self::assertSame( [
			'image.test:8080'  => 'image.test:8080:93.184.215.14',
			'image.test.:8080' => 'image.test.:8080:93.184.215.14',
			'Image.Test.:8080' => 'Image.Test.:8080:93.184.215.14',
		], $method->invoke( null, 'http://Image.Test.:8080/x.png', [ '93.184.215.14', 'not-an-ip', '2606:4700::1111' ] ) );
		self::assertSame( [], $method->invoke( null, 'http://93.184.215.14/x.png', [] ) );
		self::assertSame( [], $method->invoke( null, 'https://image.test/x.png', null ) );
	}

	private static function pinned_download( string $url, array $hops ): void {
		PinState::reset();
		PinState::$dir  = sys_get_temp_dir();
		PinState::$hops = $hops;
	}

	private static function run_pinned( string $url ) {
		$validate = new ReflectionMethod( WstmMediaPinning\Webmastery_MCP_Media::class, 'validate_public_image_url' );
		$download = new ReflectionMethod( WstmMediaPinning\Webmastery_MCP_Media::class, 'download_bounded_image' );
		$validate->setAccessible( true );
		$download->setAccessible( true );
		$ips    = null;
		$args   = [ $url, &$ips ];
		$valid  = $validate->invokeArgs( null, $args );
		self::assertSame( $url, $valid );
		$result = $download->invoke( null, $valid, 1024, $ips );
		if ( is_string( $result ) ) {
			unlink( $result );
		}
		return $result;
	}

	public function test_download_connects_only_to_addresses_validated_before_a_rebinding_answer(): void {
		self::pinned_download( 'https://image.test/x.png', [ [] ] );
		// Validation sees a public answer; every later lookup would return loopback.
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ], [ '127.0.0.1' ] ] ];
		self::assertIsString( self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'image.test' ], PinState::$lookups, 'The download must not resolve the host again.' );
		self::assertSame( [
			[ 'image.test:443:93.184.215.14', 'image.test.:443:93.184.215.14' ],
			[ '-image.test:443', '-image.test.:443' ],
		], PinState::resolves(), 'Pinned for the transfer, then removed from the reusable handle.' );
	}

	public function test_every_redirect_hop_is_revalidated_and_pinned_to_its_own_addresses(): void {
		self::pinned_download( 'https://image.test/x.png', [ [ 'location' => 'http://cdn.test:8080/y.png' ], [] ] );
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ] ], 'cdn.test' => [ [ '93.184.215.20', '93.184.215.21' ], [ '10.0.0.1' ] ] ];
		self::assertIsString( self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'https://image.test/x.png', 'http://cdn.test:8080/y.png' ], PinState::$sent );
		self::assertSame( [ 'image.test', 'cdn.test' ], PinState::$lookups );
		$unpin = [ '-image.test:443', '-image.test.:443', '-cdn.test:8080', '-cdn.test.:8080' ];
		// The reused handle was sent twice; each recorded send is cleaned up.
		self::assertSame( [
			[ 'image.test:443:93.184.215.14', 'image.test.:443:93.184.215.14' ],
			[ 'cdn.test:8080:93.184.215.20,93.184.215.21', 'cdn.test.:8080:93.184.215.20,93.184.215.21' ],
			$unpin,
			$unpin,
		], PinState::resolves() );
	}

	public function test_private_redirect_target_is_refused_before_it_is_requested(): void {
		self::pinned_download( 'https://image.test/x.png', [ [ 'location' => 'https://internal.test/y.png' ], [] ] );
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ] ], 'internal.test' => [ [ '10.0.0.1' ] ] ];
		self::assertInstanceOf( WP_Error::class, self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'https://image.test/x.png' ], PinState::$sent );
	}

	public function test_destination_changed_after_redirect_validation_is_validated_and_pinned_again(): void {
		self::pinned_download( 'https://image.test/x.png', [ [ 'location' => 'https://cdn.test/y.png' ], [ 'rewrite' => 'https://other.test/z.png' ] ] );
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ] ], 'cdn.test' => [ [ '93.184.215.20' ] ], 'other.test' => [ [ '93.184.215.30' ] ] ];
		self::assertIsString( self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'other.test:443:93.184.215.30', 'other.test.:443:93.184.215.30' ], PinState::resolves()[1] );

		self::pinned_download( 'https://image.test/x.png', [ [ 'location' => 'https://cdn.test/y.png' ], [ 'rewrite' => 'https://other.test/z.png' ] ] );
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ] ], 'cdn.test' => [ [ '93.184.215.20' ] ], 'other.test' => [ [ '192.168.1.5' ] ] ];
		self::assertInstanceOf( WP_Error::class, self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'https://image.test/x.png' ], PinState::$sent );
	}

	public function test_ip_literals_streams_and_old_curl_degrade_without_failing(): void {
		self::pinned_download( 'http://93.184.215.14/x.png', [ [] ] );
		self::assertIsString( self::run_pinned( 'http://93.184.215.14/x.png' ) );
		self::assertSame( [], PinState::resolves(), 'No resolve entries for a literal address.' );
		self::assertSame( [], PinState::$lookups );

		self::pinned_download( 'https://image.test/x.png', [ [ 'transport' => 'streams' ] ] );
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14' ] ] ];
		self::assertIsString( self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [], PinState::$curl, 'Streams cannot be pinned and are left unchanged.' );

		self::pinned_download( 'https://image.test/x.png', [ [] ] );
		PinState::$curl_version = 0x073A00;
		PinState::$answers = [ 'image.test' => [ [ '93.184.215.14', '93.184.215.15' ] ] ];
		self::assertIsString( self::run_pinned( 'https://image.test/x.png' ) );
		self::assertSame( [ 'image.test:443:93.184.215.14', 'image.test.:443:93.184.215.14' ], PinState::resolves()[0] );
	}
}
