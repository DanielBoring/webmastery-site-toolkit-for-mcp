<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WstmLinkScan\State;
use WstmLinkScan\WP_Post as Post;
use WstmLinkScan\Webmastery_MCP_Link_Scan as Scan;
use WstmLinkScan\Webmastery_MCP_Safe_Http as SafeHttp;

require_once __DIR__ . '/fixtures/link-scan-stubs.php';

final class LinkScanTest extends TestCase {
	private const NAME = 'webmastery-site-toolkit-for-mcp/scan-broken-links';

	private const POST = 10;

	protected function setUp(): void {
		State::reset();
		self::post( self::POST, '' );
	}

	private static function post( int $id, string $content, array $fields = array() ): void {
		State::$posts[ $id ] = new Post( array( 'ID' => $id, 'post_content' => $content ) + $fields );
	}

	/** Runs the scan after the permission check, on the fake clock. */
	private static function scan( array $input = array() ): array {
		$method = new ReflectionMethod( Scan::class, 'scan' );
		$method->setAccessible( true );
		return $method->invoke( null, $input + array( 'post_id' => self::POST ), static fn() => State::$now );
	}

	/** @return array<string, array<string, mixed>> Records keyed by URL. */
	private static function by_url( array $data ): array {
		return array_column( $data['links'], null, 'url' );
	}

	private static function admin(): void {
		State::$caps = array( 'edit_posts', 'read', 'manage_options' );
	}

	private static function external( string $content, array $input = array() ): array {
		self::admin();
		self::post( self::POST, $content );
		return self::scan( $input + array( 'check_external' => true ) );
	}

	private static function public_host( string $host, string $ip = '93.184.215.14' ): void {
		State::$a[ $host ] = array( array( $ip ) );
	}

	public function test_registration_is_read_only_not_idempotent_closed_and_bounded(): void {
		Scan::register();
		$args = State::$registered[ self::NAME ];
		self::assertSame( array( 'readonly' => true, 'destructive' => false, 'idempotent' => false ), $args['meta']['annotations'] );
		self::assertSame( array( 'public' => true, 'type' => 'tool' ), $args['meta']['mcp'] );
		$schema = $args['input_schema'];
		self::assertSame( array( 'post_id', 'check_external', 'max_links', 'offset' ), array_keys( $schema['properties'] ) );
		self::assertSame( array( 'post_id' ), $schema['required'] );
		self::assertSame( false, $schema['properties']['check_external']['default'] );
		self::assertSame( array( 1, 50, 25 ), array( $schema['properties']['max_links']['minimum'], $schema['properties']['max_links']['maximum'], $schema['properties']['max_links']['default'] ) );
		self::assertSame( array( 0, 0 ), array( $schema['properties']['offset']['minimum'], $schema['properties']['offset']['default'] ) );
		self::assertFalse( Webmastery_MCP_Input::close_schema( $schema )['additionalProperties'] );
		self::assertSame( array( Scan::class, 'permission' ), $args['permission_callback'] );
	}

	public function test_permission_matrix(): void {
		self::post( 20, '', array( 'post_type' => 'attachment' ) );
		self::post( 11, '', array( 'post_type' => 'page' ) );
		$code = static function ( $result ) {
			return true === $result ? true : $result->get_error_code();
		};
		$execute = static function ( array $input ) {
			$result = Scan::execute( $input );
			return $result['success'] ? true : $result['error']['code'];
		};

		// Post or page that exists, editable by the caller: allowed without manage_options.
		foreach ( array( array( 'post_id' => self::POST ), array( 'post_id' => self::POST, 'check_external' => false ), array( 'post_id' => 11 ) ) as $input ) {
			self::assertTrue( Scan::permission( $input ) );
			self::assertTrue( $execute( $input ) );
		}

		// Missing objects and other post types: left to execution, which reports not_found.
		foreach ( array( 999, 20, 0 ) as $id ) {
			self::assertTrue( Scan::permission( array( 'post_id' => $id ) ) );
			self::assertSame( 'not_found', $execute( array( 'post_id' => $id ) ) );
		}

		// edit_post only: external checks are an administrator action.
		$denied = Scan::permission( array( 'post_id' => self::POST, 'check_external' => true ) );
		self::assertSame( 'forbidden', $code( $denied ) );
		self::assertStringContainsString( 'manage_options', $denied->get_error_message() );
		self::assertStringContainsString( 'third-party', $denied->get_error_message() );
		self::assertSame( 'forbidden', $execute( array( 'post_id' => self::POST, 'check_external' => true ) ) );
		self::assertSame( 'forbidden', $code( Scan::permission( array( 'post_id' => 999, 'check_external' => true ) ) ) );

		// edit_post + manage_options: allowed.
		self::admin();
		self::assertTrue( Scan::permission( array( 'post_id' => self::POST, 'check_external' => true ) ) );

		// manage_options without edit_post for this post is still forbidden.
		State::$uneditable = array( self::POST );
		self::assertSame( 'forbidden', $code( Scan::permission( array( 'post_id' => self::POST, 'check_external' => true ) ) ) );
		self::assertSame( 'forbidden', $code( Scan::permission( array( 'post_id' => self::POST ) ) ) );
		self::assertSame( 'forbidden', $execute( array( 'post_id' => self::POST ) ) );

		// No editing capability at all: forbidden whether or not the post exists (no ID probing).
		State::$caps       = array( 'read' );
		State::$uneditable = array();
		self::assertSame( 'forbidden', $code( Scan::permission( array( 'post_id' => self::POST ) ) ) );
		self::assertSame( 'forbidden', $code( Scan::permission( array( 'post_id' => 999 ) ) ) );
		self::assertSame( 'forbidden', $execute( array( 'post_id' => 999 ) ) );

		// A page-only editor passes the base check.
		State::$caps = array( 'edit_pages', 'read' );
		self::assertTrue( Scan::permission( array( 'post_id' => 999 ) ) );
		self::assertSame( array(), State::$sent );
	}

	/**
	 * Through the real Input/Ability wrappers over the lifecycle double: a missing post reaches
	 * execution as not_found, a role without edit rights is forbidden, and a malformed request
	 * is invalid_input before any permission branch.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_native_lifecycle_reports_not_found_forbidden_and_invalid_input(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';
		require_once __DIR__ . '/fixtures/native-input-ability.php';
		require_once dirname( __DIR__, 2 ) . '/includes/class-ability.php';
		Scan::register();
		$args    = Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( State::$registered[ self::NAME ], self::NAME ), self::NAME );
		$ability = new Webmastery_MCP_Ability( self::NAME, $args );

		$missing = $ability->execute( array( 'post_id' => 987654321 ) );
		self::assertSame( array( 'not_found', 'not_found' ), array( $missing['error']['code'], $missing['error']['reason'] ) );

		self::assertTrue( $ability->execute( array( 'post_id' => self::POST ) )['success'] );

		$external = $ability->execute( array( 'post_id' => self::POST, 'check_external' => true ) );
		self::assertSame( 'forbidden', $external['error']['code'] );

		State::$caps = array( 'read' );
		$denied      = $ability->execute( array( 'post_id' => self::POST ) );
		self::assertSame( array( 'forbidden', 'ability_invalid_permissions' ), array( $denied['error']['code'], $denied['error']['reason'] ) );

		self::assertSame( 'invalid_input', $ability->execute( array( 'post_id' => self::POST, 'max_links' => 51 ) )['error']['code'] );
		self::assertSame( 'invalid_input', $ability->execute( null )['error']['code'] );
		self::assertSame( array(), State::$sent );
	}

	public function test_execute_returns_ok_envelope(): void {
		self::post( self::POST, '<a href="/">Home</a>' );
		$result = Scan::execute( array( 'post_id' => self::POST ) );
		self::assertTrue( $result['success'] );
		self::assertSame( array( 'post_id', 'checked_external', 'links', 'summary', 'next_offset' ), array_keys( $result['data'] ) );
		self::assertSame( array( 'url', 'kind', 'status', 'http_status', 'anchor_text', 'occurrences', 'target_id', 'untrusted_fields' ), array_keys( $result['data']['links'][0] ) );
		self::assertSame( array( 'url', 'anchor_text' ), $result['data']['links'][0]['untrusted_fields'] );
	}

	public function test_extraction_dedup_relative_resolution_and_skips(): void {
		$long = str_repeat( 'x', 150 );
		self::post(
			self::POST,
			'<p><a href="#top">Top</a> <a href="mailto:a@example.test">Mail</a> <a href="tel:+1555">Call</a>'
			. ' <a href="javascript:alert(1)">JS</a> <a href="data:text/html,x">Data</a> <a href="//">Empty net</a> <a href="  ">blank</a>'
			. ' <a class="x" href="https://Example.TEST:443/a/./b/../c?q=1#frag">First <b>bold</b> &amp; text</a>'
			. ' <a href="https://example.test/a/c?q=1">Again</a>'
			. ' <a href="../other/">Up</a> <a href="sibling">Sibling</a> <a href="?page=2">Query</a> <a href="/root">Root</a>'
			. ' <a href="//www.example.test/net">Net</a> <a href="HTTP://Elsewhere.TEST:80/x">' . $long . '</a>'
			. " <a href='https://elsewhere.test/x#other'>Dup external</a> <a href=\"https://elsewhere.test/q?a=1&amp;b=2\">Entity</a>"
			. ' <a href="https://user:secret@elsewhere.test/x">Creds</a> <img src="https://img.test/i.png"><script src="https://js.test/s.js"></script></p>'
		);
		$data = self::scan();

		self::assertSame( array(
			'https://example.test/a/c?q=1',
			'https://example.test/2026/other/',
			'https://example.test/2026/hello-world/sibling',
			'https://example.test/2026/hello-world/?page=2',
			'https://example.test/root',
			'https://www.example.test/net',
			'http://elsewhere.test/x',
			'https://elsewhere.test/x',
			'https://elsewhere.test/q?a=1&b=2',
			'https://elsewhere.test/x',
		), array_column( $data['links'], 'url' ) );
		$links = $data['links'];
		self::assertSame( 2, $links[0]['occurrences'] );
		self::assertSame( 'First bold & text', $links[0]['anchor_text'] );
		self::assertSame( 100, mb_strlen( $links[6]['anchor_text'] ) );
		self::assertSame( 1, $links[6]['occurrences'], 'http and https are different URLs.' );
		self::assertSame( array( 'internal', 'internal', 'internal', 'internal', 'internal', 'internal', 'external', 'external', 'external', 'external' ), array_column( $links, 'kind' ) );
		// Credentials in stored URLs are never echoed; the link stays separate from its credential-free twin.
		self::assertStringNotContainsString( 'secret', json_encode( $data ) );
		self::assertSame( 'Creds', $links[9]['anchor_text'] );
		self::assertSame( 'Dup external', $links[7]['anchor_text'] );
		self::assertSame( array( 'total_links' => 10, 'internal' => 6, 'external' => 4, 'skipped' => 7 ), array_slice( $data['summary'], 0, 4 ) );
		foreach ( $links as $link ) {
			self::assertSame( array( 'url', 'anchor_text' ), $link['untrusted_fields'] );
		}
	}

	public function test_internal_links_are_classified_offline_with_redaction(): void {
		self::post( 20, '', array( 'post_status' => 'publish' ) );
		self::post( 21, '', array( 'post_status' => 'draft', 'post_title' => 'Secret draft title' ) );
		self::post( 22, '', array( 'post_status' => 'private', 'post_title' => 'Unreadable private title' ) );
		self::post( 23, '', array( 'post_status' => 'trash' ) );
		self::post( 30, '', array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		self::post( 31, '', array( 'post_type' => 'attachment', 'post_status' => 'trash' ) );
		State::$unreadable = array( 22 );
		State::$routes     = array(
			'https://example.test/published/'  => 20,
			'https://www.example.test/draft/'  => 21,
			'https://example.test/private/'    => 22,
			'https://example.test/?p=23'       => 23,
			'https://example.test/?p=999'      => 999,
		);
		State::$attachments = array(
			'https://example.test/wp-content/uploads/2026/01/a.png' => 30,
			'https://example.test/wp-content/uploads/2026/01/b.png' => 31,
		);
		self::post( self::POST, implode( ' ', array_map( static fn( $href ) => '<a href="' . $href . '">x</a>', array(
			'/published/', 'https://www.example.test/draft/', '/private/', '/?p=23', '/?p=999', '/category/news/',
			'/wp-content/uploads/2026/01/a.png', 'http://www.example.test/wp-content/uploads/2026/01/b.png', '/wp-content/uploads/2026/01/a-300x200.png', '/',
		) ) ) );
		self::admin();
		$data = self::scan( array( 'check_external' => true ) );

		$expect = array(
			'https://example.test/published/'                                => array( 'ok', 20 ),
			'https://www.example.test/draft/'                                => array( 'not_published', 21 ),
			'https://example.test/private/'                                  => array( 'not_published', null ),
			'https://example.test/?p=23'                                     => array( 'not_found', 23 ),
			'https://example.test/?p=999'                                    => array( 'not_found', null ),
			'https://example.test/category/news/'                            => array( 'unresolved', null ),
			'https://example.test/wp-content/uploads/2026/01/a.png'          => array( 'ok', 30 ),
			'http://www.example.test/wp-content/uploads/2026/01/b.png'       => array( 'not_found', 31 ),
			'https://example.test/wp-content/uploads/2026/01/a-300x200.png'  => array( 'unresolved', null ),
			'https://example.test/'                                          => array( 'ok', null ),
		);
		$records = self::by_url( $data );
		self::assertSame( array_keys( $expect ), array_keys( $records ) );
		foreach ( $expect as $url => list( $status, $target ) ) {
			self::assertSame( array( 'internal', $status, null, $target ), array( $records[ $url ]['kind'], $records[ $url ]['status'], $records[ $url ]['http_status'], $records[ $url ]['target_id'] ), $url );
		}
		$json = json_encode( $data );
		self::assertStringNotContainsString( 'Secret draft title', $json );
		self::assertStringNotContainsString( 'Unreadable private title', $json );
		self::assertStringNotContainsString( '"target_id":22', $json );

		// Internal links never touch DNS or HTTP, even with check_external.
		self::assertSame( array(), State::$sent );
		self::assertSame( array(), State::$dns_calls );
		self::assertSame( array(), State::$core_validated );
		// Uploads URLs use the attachment lookup under the configured uploads URL, not url_to_postid().
		self::assertContains( 'attachment:https://example.test/wp-content/uploads/2026/01/b.png', State::$lookups );
		self::assertNotContains( 'url:https://example.test/wp-content/uploads/2026/01/a.png', State::$lookups );
		self::assertSame( 3, $data['summary']['by_status']['ok'] );
		self::assertSame( 2, $data['summary']['by_status']['not_published'] );
		self::assertSame( 3, $data['summary']['by_status']['not_found'] );
		self::assertSame( 2, $data['summary']['by_status']['unresolved'] );
		self::assertNull( $data['next_offset'] );
	}

	public function test_external_links_are_not_checked_without_check_external(): void {
		self::admin();
		self::post( self::POST, '<a href="https://elsewhere.test/">x</a> <a href="http://10.0.0.1/">y</a>' );
		$data = self::scan();
		self::assertFalse( $data['checked_external'] );
		self::assertSame( array( 'not_checked', 'not_checked' ), array_column( $data['links'], 'status' ) );
		self::assertNull( $data['next_offset'] );
		self::assertSame( array(), State::$sent );
		self::assertSame( array(), State::$dns_calls );
	}

	/** @dataProvider httpOutcomes */
	public function test_external_classification( array $responses, string $status, ?int $http_status, array $sent ): void {
		foreach ( array( 'a.test', 'b.test', 'c.test', 'd.test', 'e.test' ) as $host ) {
			self::public_host( $host );
		}
		State::$responses = $responses;
		$data             = self::external( '<a href="https://a.test/x">x</a>' );
		self::assertSame( array( $status, $http_status ), array( $data['links'][0]['status'], $data['links'][0]['http_status'] ) );
		self::assertSame( $sent, State::sent() );
		self::assertTrue( $data['checked_external'] );
		self::assertSame( 1, $data['summary']['by_status'][ $status ] );
		self::assertStringNotContainsString( 'SECRET BODY', json_encode( $data ) );
		self::assertStringNotContainsString( '93.184.215.14', json_encode( $data ), 'Resolved addresses are never returned.' );
	}

	public static function httpOutcomes(): array {
		$head = 'HEAD https://a.test/x';
		return array(
			'2xx ok'              => array( array( $head => array( 'code' => 204 ) ), 'ok', 204, array( $head ) ),
			'404 broken'          => array( array( $head => array( 'code' => 404 ) ), 'broken', 404, array( $head ) ),
			'410 broken'          => array( array( $head => array( 'code' => 410 ) ), 'broken', 410, array( $head ) ),
			'403 client error'    => array( array( $head => array( 'code' => 403 ) ), 'client_error', 403, array( $head ) ),
			'503 server error'    => array( array( $head => array( 'code' => 503 ) ), 'server_error', 503, array( $head ) ),
			'transport error'     => array( array( $head => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ), 'unreachable', null, array( $head ) ),
			'no status'           => array( array( $head => array( 'code' => 0 ) ), 'unreachable', null, array( $head ) ),
			'redirect to ok'      => array(
				array( $head => array( 'code' => 301, 'headers' => array( 'location' => '/y' ) ), 'HEAD https://a.test/y' => array( 'code' => 200 ) ),
				'ok', 200, array( $head, 'HEAD https://a.test/y' ),
			),
			'redirect to broken'  => array(
				array( $head => array( 'code' => 302, 'headers' => array( 'location' => 'https://b.test/gone' ) ), 'HEAD https://b.test/gone' => array( 'code' => 404 ) ),
				'broken', 404, array( $head, 'HEAD https://b.test/gone' ),
			),
			'redirect, no target' => array( array( $head => array( 'code' => 302 ) ), 'redirect', 302, array( $head ) ),
			'redirect chain > 3'  => array(
				array(
					$head                    => array( 'code' => 301, 'headers' => array( 'location' => 'https://b.test/1' ) ),
					'HEAD https://b.test/1' => array( 'code' => 301, 'headers' => array( 'location' => 'https://c.test/2' ) ),
					'HEAD https://c.test/2' => array( 'code' => 301, 'headers' => array( 'location' => 'https://d.test/3' ) ),
					'HEAD https://d.test/3' => array( 'code' => 308, 'headers' => array( 'location' => 'https://e.test/4' ) ),
				),
				'redirect', 308, array( $head, 'HEAD https://b.test/1', 'HEAD https://c.test/2', 'HEAD https://d.test/3' ),
			),
			'405 -> ranged GET'   => array( array( $head => array( 'code' => 405 ), 'GET https://a.test/x' => array( 'code' => 206 ) ), 'ok', 206, array( $head, 'GET https://a.test/x' ) ),
			'501 -> ranged GET'   => array( array( $head => array( 'code' => 501 ), 'GET https://a.test/x' => array( 'code' => 404 ) ), 'broken', 404, array( $head, 'GET https://a.test/x' ) ),
			'405 GET error'       => array( array( $head => array( 'code' => 405 ), 'GET https://a.test/x' => new WP_Error( 'http_request_failed', 'x' ) ), 'unreachable', null, array( $head, 'GET https://a.test/x' ) ),
		);
	}

	public function test_request_arguments_are_fixed_and_cannot_be_widened_by_filters(): void {
		self::public_host( 'a.test' );
		State::$responses = array( 'HEAD https://a.test/x' => array( 'code' => 405 ), 'GET https://a.test/x' => array( 'code' => 200 ) );
		// A hostile or careless filter registered earlier tries to add credentials, cookies and redirects.
		State::$hooks['http_request_args'][] = static function ( $args ) {
			$args['cookies']                  = array( 'wordpress_logged_in' => 'x' );
			$args['headers']['Authorization'] = 'Basic eDp5';
			$args['headers']['Referer']       = 'https://example.test/wp-admin/';
			$args['redirection']              = 5;
			$args['timeout']                  = 30;
			$args['user-agent']               = 'WordPress/6.9; https://example.test';
			return $args;
		};
		self::external( '<a href="https://a.test/x">x</a>' );
		self::assertCount( 2, State::$sent );
		foreach ( State::$sent as $index => $request ) {
			$args = $request['args'];
			self::assertSame( Scan::USER_AGENT, $args['user-agent'] );
			self::assertStringNotContainsString( 'example.test', $args['user-agent'] );
			self::assertSame( array(), $args['cookies'] );
			self::assertSame( 0, $args['redirection'] );
			self::assertTrue( $args['reject_unsafe_urls'] );
			self::assertFalse( $args['stream'] );
			self::assertLessThanOrEqual( 5, $args['timeout'] );
			self::assertSame( 0 === $index ? array() : array( 'Range' => 'bytes=0-1023' ), $args['headers'] );
			self::assertSame( 0 === $index ? null : 1024, $args['limit_response_size'] );
		}
		self::assertSame( array( 'HEAD', 'GET' ), array_column( array_column( State::$sent, 'args' ), 'method' ) );
		self::assertSame( array( 'http_request_args' => array( State::$hooks['http_request_args'][0] ), 'http_api_curl' => array() ), State::$hooks, 'Request hooks are removed afterwards.' );
	}

	/** @dataProvider unsafeUrls */
	public function test_unsafe_urls_are_blocked_and_never_requested( string $url, array $a, array $dns ): void {
		State::$a   = $a;
		State::$dns = $dns;
		$data       = self::external( '<a href="' . $url . '">x</a>' );
		self::assertSame( 'blocked', $data['links'][0]['status'], $url );
		self::assertNull( $data['links'][0]['http_status'] );
		self::assertSame( array(), State::$sent );
		self::assertSame( array(), State::resolves() );
	}

	public static function unsafeUrls(): array {
		$public = array( 'a.test' => array( array( '93.184.215.14' ) ) );
		return array(
			'loopback v4'          => array( 'http://127.0.0.1/', array(), array() ),
			'private 10/8'         => array( 'http://10.1.2.3/', array(), array() ),
			'private 192.168/16'   => array( 'http://192.168.1.1/', array(), array() ),
			'private 172.16/12'    => array( 'http://172.16.0.1/', array(), array() ),
			'metadata link-local'  => array( 'http://169.254.169.254/latest/meta-data/', array(), array() ),
			'unspecified'          => array( 'http://0.0.0.0/', array(), array() ),
			'IPv6 loopback'        => array( 'http://[::1]/', array(), array() ),
			'IPv6 public literal'  => array( 'http://[2606:4700::1111]/', array(), array() ),
			'IPv4-mapped IPv6'     => array( 'http://[::ffff:127.0.0.1]/', array(), array() ),
			'localhost'            => array( 'http://localhost/', array(), array() ),
			'.localhost'           => array( 'http://admin.localhost/', array(), array() ),
			'.local'               => array( 'http://printer.local/', array(), array() ),
			'A answer private'     => array( 'https://a.test/', array( 'a.test' => array( array( '10.0.0.1' ) ) ), array() ),
			'mixed A answers'      => array( 'https://a.test/', array( 'a.test' => array( array( '93.184.215.14', '192.168.0.2' ) ) ), array() ),
			'AAAA answer private'  => array( 'https://a.test/', $public, array( 'a.test' => array( array( 'type' => 'AAAA', 'ipv6' => 'fd00::1' ) ) ) ),
			'CNAME to private'     => array( 'https://a.test/', $public, array( 'a.test' => array( array( 'type' => 'CNAME', 'target' => 'inner.test.' ) ), 'inner.test' => array( array( 'type' => 'AAAA', 'ipv6' => '::1' ) ) ) ),
			'unresolvable'         => array( 'https://a.test/', array(), array() ),
			'resolver failure'     => array( 'https://a.test/', $public, array( 'a.test' => false ) ),
		);
	}

	public function test_core_safe_url_rejection_and_credentials_are_blocked(): void {
		self::public_host( 'a.test' );
		State::$core_rejects = array( 'https://a.test:2222/' );
		$data                = self::external( '<a href="https://a.test:2222/">port</a> <a href="https://user:pw@a.test/">creds</a>' );
		self::assertSame( array( 'blocked', 'blocked' ), array_column( $data['links'], 'status' ) );
		self::assertSame( array(), State::$sent );
		self::assertSame( array( 'https://a.test:2222/', 'https://a.test/' ), array_column( $data['links'], 'url' ) );
	}

	public function test_redirect_to_a_private_address_is_blocked_before_it_is_requested(): void {
		self::public_host( 'a.test' );
		State::$a['internal.test'] = array( array( '10.0.0.5' ) );
		State::$responses          = array( 'HEAD https://a.test/x' => array( 'code' => 302, 'headers' => array( 'location' => 'http://internal.test/admin' ) ) );
		$data                      = self::external( '<a href="https://a.test/x">x</a>' );
		self::assertSame( array( 'blocked', 302 ), array( $data['links'][0]['status'], $data['links'][0]['http_status'] ) );
		self::assertSame( array( 'HEAD https://a.test/x' ), State::sent() );
		self::assertStringNotContainsString( 'internal.test', json_encode( $data ), 'Redirect targets are never returned.' );

		State::$sent      = array();
		State::$responses = array( 'HEAD https://a.test/x' => array( 'code' => 301, 'headers' => array( 'location' => 'http://169.254.169.254/' ) ) );
		self::assertSame( 'blocked', self::external( '<a href="https://a.test/x">x</a>' )['links'][0]['status'] );
		self::assertSame( array( 'HEAD https://a.test/x' ), State::sent() );
	}

	public function test_connection_is_pinned_to_the_address_validated_before_a_rebinding_answer(): void {
		// Validation sees a public answer; every later lookup would return loopback.
		State::$a = array( 'a.test' => array( array( '93.184.215.14' ), array( '127.0.0.1' ) ) );
		$data     = self::external( '<a href="https://a.test/x">x</a>' );
		self::assertSame( 'ok', $data['links'][0]['status'] );
		self::assertSame( array( 'A:a.test', 'DNS:a.test' ), State::$dns_calls, 'The request must not resolve the host again.' );
		self::assertSame( array(
			array( 'a.test:443:93.184.215.14', 'a.test.:443:93.184.215.14' ),
			array( '-a.test:443', '-a.test.:443' ),
		), State::resolves(), 'Pinned for the transfer, then removed from the reusable handle.' );

		// Each redirect hop is validated again and pinned to its own answer.
		State::reset();
		self::post( self::POST, '' );
		State::$a         = array( 'a.test' => array( array( '93.184.215.14' ) ), 'cdn.test' => array( array( '93.184.215.20' ), array( '10.0.0.1' ) ) );
		State::$responses = array( 'HEAD https://a.test/x' => array( 'code' => 301, 'headers' => array( 'location' => 'http://cdn.test:8080/y' ) ) );
		self::assertSame( 'ok', self::external( '<a href="https://a.test/x">x</a>' )['links'][0]['status'] );
		self::assertSame( array( 'cdn.test:8080:93.184.215.20', 'cdn.test.:8080:93.184.215.20' ), State::resolves()[2] );
	}

	public function test_unpinnable_transport_sends_nothing_and_stops_external_checks(): void {
		self::public_host( 'a.test' );
		self::public_host( 'b.test' );
		State::$curl_available = false;
		$data                  = self::external( '<a href="https://a.test/">a</a> <a href="https://b.test/">b</a> <a href="/">home</a>' );
		self::assertSame( array( 'not_checked', 'not_checked', 'ok' ), array_column( $data['links'], 'status' ) );
		self::assertSame( array(), State::$sent );
		self::assertNull( $data['next_offset'], 'A transport that cannot pin is not a bound to resume from.' );

		// cURL claimed but a different transport handled the request: the response is discarded.
		State::reset();
		self::post( self::POST, '' );
		self::public_host( 'a.test' );
		State::$curl_transport = false;
		$data                  = self::external( '<a href="https://a.test/">a</a>' );
		self::assertSame( 'not_checked', $data['links'][0]['status'] );
		self::assertNull( $data['links'][0]['http_status'] );

		// An IP literal needs no pin and is still checked.
		State::reset();
		self::post( self::POST, '' );
		State::$curl_available = false;
		self::assertSame( 'ok', self::external( '<a href="http://93.184.215.14/">ip</a>' )['links'][0]['status'] );
	}

	public function test_max_links_and_offset_window(): void {
		self::post( self::POST, implode( '', array_map( static fn( $i ) => '<a href="/p' . $i . '">' . $i . '</a>', range( 1, 7 ) ) ) );
		$first = self::scan( array( 'max_links' => 3 ) );
		self::assertSame( array( 'https://example.test/p1', 'https://example.test/p2', 'https://example.test/p3' ), array_column( $first['links'], 'url' ) );
		self::assertSame( 3, $first['next_offset'] );
		self::assertSame( 7, $first['summary']['total_links'] );
		self::assertSame( 3, array_sum( $first['summary']['by_status'] ), 'by_status counts the returned window.' );
		$last = self::scan( array( 'max_links' => 3, 'offset' => 6 ) );
		self::assertSame( array( 'https://example.test/p7' ), array_column( $last['links'], 'url' ) );
		self::assertNull( $last['next_offset'] );
		$past = self::scan( array( 'offset' => 50 ) );
		self::assertSame( array(), $past['links'] );
		self::assertNull( $past['next_offset'] );
		self::assertCount( 7, self::scan()['links'] );

		// The default window is 25 unique links.
		self::post( self::POST, implode( '', array_map( static fn( $i ) => '<a href="/q' . $i . '">' . $i . '</a>', range( 1, 30 ) ) ) );
		$default = self::scan();
		self::assertCount( 25, $default['links'] );
		self::assertSame( 25, $default['next_offset'] );
		self::assertCount( 25, array_filter( State::$lookups, static fn( $lookup ) => str_starts_with( $lookup, 'url:https://example.test/q' ) ), 'Only the window is resolved.' );
	}

	public function test_request_cap_reports_the_rest_as_not_checked_with_next_offset(): void {
		$content = '<a href="/internal">in</a>';
		for ( $i = 1; $i <= 12; ++$i ) {
			self::public_host( "h{$i}.test" );
			$content .= '<a href="https://h' . $i . '.test/">' . $i . '</a>';
		}
		$data = self::external( $content, array( 'max_links' => 50 ) );
		self::assertCount( 10, State::$sent );
		$statuses = array_column( $data['links'], 'status' );
		self::assertSame( array_merge( array( 'unresolved' ), array_fill( 0, 10, 'ok' ), array( 'not_checked', 'not_checked' ) ), $statuses );
		self::assertSame( 11, $data['next_offset'], 'Continue from the first link a bound stopped.' );
		// Links past the cap cost no DNS lookups either.
		self::assertNotContains( 'A:h11.test', State::$dns_calls );

		State::$sent = array();
		$next        = self::external( $content, array( 'max_links' => 50, 'offset' => 11 ) );
		self::assertSame( array( 'ok', 'ok' ), array_column( $next['links'], 'status' ) );
		self::assertNull( $next['next_offset'] );
	}

	public function test_redirect_hops_and_fallbacks_count_toward_the_request_cap(): void {
		$content   = '';
		$responses = array();
		for ( $i = 1; $i <= 6; ++$i ) {
			self::public_host( "h{$i}.test" );
			$content                                    .= '<a href="https://h' . $i . '.test/">' . $i . '</a>';
			$responses[ 'HEAD https://h' . $i . '.test/' ] = array( 'code' => 405 );
		}
		State::$responses = $responses;
		$data             = self::external( $content );
		self::assertCount( 10, State::$sent );
		self::assertSame( array( 'ok', 'ok', 'ok', 'ok', 'ok', 'not_checked' ), array_column( $data['links'], 'status' ) );
		self::assertSame( 5, $data['next_offset'] );
	}

	public function test_per_host_cap(): void {
		self::public_host( 'a.test' );
		self::public_host( 'b.test' );
		self::public_host( 'www.a.test' );
		$data = self::external( '<a href="https://a.test/1">1</a><a href="https://a.test/2">2</a><a href="https://www.A.test/3">3</a><a href="https://a.test/4">4</a><a href="https://b.test/">b</a>' );
		self::assertSame( array( 'ok', 'ok', 'ok', 'not_checked', 'ok' ), array_column( $data['links'], 'status' ), 'www.a.test is a different host from a.test for politeness.' );
		State::$sent = array();
		$data        = self::external( '<a href="https://a.test/1">1</a><a href="https://a.test/2">2</a><a href="https://a.test/3">3</a><a href="https://b.test/">b</a>' );
		self::assertSame( array( 'ok', 'ok', 'not_checked', 'ok' ), array_column( $data['links'], 'status' ) );
		self::assertSame( 2, $data['next_offset'] );
		self::assertSame( array( 'HEAD https://a.test/1', 'HEAD https://a.test/2', 'HEAD https://b.test/' ), State::sent() );
	}

	public function test_time_budget_stops_new_requests(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			self::public_host( "h{$i}.test" );
		}
		State::$latency = 8.0;
		$data           = self::external( implode( '', array_map( static fn( $i ) => '<a href="https://h' . $i . '.test/">' . $i . '</a>', range( 1, 5 ) ) ) );
		// 0s, 8s and 16s start; at 24s the 20-second budget is spent.
		self::assertSame( array( 'ok', 'ok', 'ok', 'not_checked', 'not_checked' ), array_column( $data['links'], 'status' ) );
		self::assertSame( 3, $data['next_offset'] );
		foreach ( State::$sent as $request ) {
			self::assertLessThanOrEqual( 5, $request['args']['timeout'] );
		}

		// The first request always runs, so every call makes progress.
		State::reset();
		self::post( self::POST, '' );
		self::public_host( 'h1.test' );
		State::$latency = 30.0;
		$data           = self::external( '<a href="https://h1.test/">1</a><a href="https://h1.test/2">2</a>' );
		self::assertSame( array( 'ok', 'not_checked' ), array_column( $data['links'], 'status' ) );
		self::assertSame( 1, $data['next_offset'] );
	}

	public function test_shared_guard_keeps_media_messages_and_labels_link_messages(): void {
		$cases = array(
			'not a url'                   => array( 'Image URL must be a valid http or https URL.', 'Link URL must be a valid http or https URL.' ),
			'http://localhost/x'          => array( 'Image URL must not target a local host.', 'Link URL must not target a local host.' ),
			'http://[::1]/x'              => array( 'Image URL must use a hostname or public IPv4 address supported by the safe HTTP API.', 'Link URL must use a hostname or public IPv4 address supported by the safe HTTP API.' ),
			'http://10.0.0.1/x'           => array( 'Image URL must not target a private or reserved address.', 'Link URL must not target a private or reserved address.' ),
			'https://nowhere.test/x'      => array( 'Could not resolve image hostname IPv4 records.', 'Could not resolve link hostname IPv4 records.' ),
			'https://private.test/x'      => array( 'Image URL must not resolve to a private or reserved address.', 'Link URL must not resolve to a private or reserved address.' ),
			'https://broken-dns.test/x'   => array( 'Could not resolve image hostname IPv6 records.', 'Could not resolve link hostname IPv6 records.' ),
			'https://loop.test/x'         => array( 'Image hostname has a cyclic or excessive DNS alias chain.', 'Link hostname has a cyclic or excessive DNS alias chain.' ),
		);
		State::$a   = array( 'private.test' => array( array( '10.0.0.1' ) ), 'broken-dns.test' => array( array( '93.184.215.14' ) ), 'loop.test' => array( array( '93.184.215.14' ) ) );
		State::$dns = array( 'broken-dns.test' => false, 'loop.test' => array( array( 'type' => 'CNAME', 'target' => 'loop.test.' ) ) );
		foreach ( $cases as $url => list( $image, $link ) ) {
			self::assertSame( $image, SafeHttp::validate_public_url( $url, $ips )->get_error_message(), $url );
			self::assertSame( $link, SafeHttp::validate_public_url( $url, $ips, 'Link' )->get_error_message(), $url );
			self::assertNull( $ips );
		}
		self::assertSame( 'invalid_url', SafeHttp::validate_public_url( 'http://10.0.0.1/', $ips )->get_error_code() );
	}
}
