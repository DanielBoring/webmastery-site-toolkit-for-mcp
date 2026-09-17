<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-media.php';

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

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
			'::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1', 'not-an-ip',
		] );
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
}
