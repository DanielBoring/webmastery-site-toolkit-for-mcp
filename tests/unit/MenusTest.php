<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WstmMenus\Webmastery_MCP_Menus as Menus;
use WstmMenus\WP_Post as Post;
use WstmMenus\WP_Term as Term;

require_once __DIR__ . '/fixtures/menus-stubs.php';

final class MenusTest extends TestCase {
	private const NS = 'webmastery-site-toolkit-for-mcp/';

	private const ITEM_TEXT = array( 'title', 'url', 'attr_title', 'description', 'classes', 'xfn' );

	protected function setUp(): void {
		$GLOBALS['wstm_menus'] = WstmMenus\wstm_menus_state();
		// Menu 10 "Main": custom link 101 (parent of 102), page item 102 -> post 500, category item 103.
		// Menu 20 "Footer": custom link 201.
		self::menu( 10, 'Main', 'main', 3 );
		self::menu( 20, 'Footer', 'footer', 1 );
		self::item( 101, 10, 'custom', 'custom', 101, array( 'post_title' => 'Docs', 'post_excerpt' => 'Read the docs', 'post_content' => 'Stored description', 'menu_order' => 1 ), array( '_menu_item_url' => 'https://example.test/docs', '_menu_item_target' => '_blank', '_menu_item_classes' => array( 'nav-docs', 'highlight' ), '_menu_item_xfn' => 'nofollow' ) );
		self::item( 102, 10, 'post_type', 'page', 500, array( 'post_title' => '', 'menu_order' => 2 ), array( '_menu_item_menu_item_parent' => '101' ) );
		self::item( 103, 10, 'taxonomy', 'category', 7, array( 'post_title' => 'News', 'menu_order' => 3 ) );
		self::item( 201, 20, 'custom', 'custom', 201, array( 'post_title' => 'Contact', 'menu_order' => 1 ), array( '_menu_item_url' => 'mailto:hi@example.test' ) );
		$GLOBALS['wstm_menus']['posts'][500] = new Post( array( 'ID' => 500, 'post_type' => 'page', 'post_title' => 'Secret Private Page', 'post_status' => 'private' ) );
		$GLOBALS['wstm_menus']['posts'][600] = new Post( array( 'ID' => 600, 'post_type' => 'post', 'post_title' => 'Ordinary post' ) );
		$GLOBALS['wstm_menus']['terms'][7]   = new Term( array( 'term_id' => 7, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category' ) );
		$GLOBALS['wstm_menus']['registered'] = array( 'primary' => 'Primary Menu', 'footer' => 'Footer Menu', 'social' => 'Social Links' );
		$GLOBALS['wstm_menus']['locations']  = array( 'primary' => 10, 'footer' => 20, 'stale' => 10, 'social' => 0 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm_menus'] );
		parent::tearDown();
	}

	private static function menu( int $id, string $name, string $slug, int $count ): void {
		$GLOBALS['wstm_menus']['terms'][ $id ] = new Term( array( 'term_id' => $id, 'name' => $name, 'slug' => $slug, 'count' => $count ) );
	}

	private static function item( int $id, int $menu, string $type, string $object, int $object_id, array $post = array(), array $meta = array() ): void {
		$GLOBALS['wstm_menus']['posts'][ $id ] = new Post( array( 'ID' => $id ) + $post );
		$GLOBALS['wstm_menus']['meta'][ $id ]  = $meta + array(
			'_menu_item_type'             => $type,
			'_menu_item_object'           => $object,
			'_menu_item_object_id'        => (string) $object_id,
			'_menu_item_menu_item_parent' => '0',
			'_menu_item_target'           => '',
			'_menu_item_classes'          => array( '' ),
			'_menu_item_xfn'              => '',
			'_menu_item_url'              => '',
		);
		$GLOBALS['wstm_menus']['object_terms'][ $id ] = array( $menu );
	}

	private static function registered(): array {
		Menus::register();
		return $GLOBALS['wstm_menus']['registered_abilities'];
	}

	private static function update( array $input ): array {
		return Menus::update_menu_item( $input );
	}

	private static function assert_error( array $result, string $code, string $reason ): void {
		self::assertFalse( $result['success'], json_encode( $result ) );
		self::assertSame( $code, $result['error']['code'] );
		self::assertSame( $reason, $result['error']['reason'] );
		self::assertSame( array(), $GLOBALS['wstm_menus']['updates'], 'A rejected update must not write.' );
	}

	public function test_registration_annotations_capability_and_closed_schemas(): void {
		$abilities = self::registered();
		self::assertSame( array( self::NS . 'list-menus', self::NS . 'get-menu', self::NS . 'update-menu-item' ), array_keys( $abilities ) );
		$read = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
		self::assertSame( $read, $abilities[ self::NS . 'list-menus' ]['meta']['annotations'] );
		self::assertSame( $read, $abilities[ self::NS . 'get-menu' ]['meta']['annotations'] );
		self::assertSame( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), $abilities[ self::NS . 'update-menu-item' ]['meta']['annotations'] );
		self::assertArrayNotHasKey( 'input_schema', $abilities[ self::NS . 'list-menus' ] );
		$get = $abilities[ self::NS . 'get-menu' ]['input_schema'];
		self::assertSame( array( 'menu_id' ), $get['required'] );
		self::assertSame( array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ), $get['properties']['per_page'] );
		$update = $abilities[ self::NS . 'update-menu-item' ]['input_schema'];
		self::assertSame( array( 'menu_item_id' ), $update['required'] );
		self::assertSame( array( 'menu_item_id', 'title', 'url', 'target', 'attr_title', 'description', 'classes', 'position', 'parent_item_id' ), array_keys( $update['properties'] ) );
		self::assertSame( array( '', '_blank' ), $update['properties']['target']['enum'] );
		self::assertSame( 20, $update['properties']['classes']['maxItems'] );
		self::assertSame( 1, $update['properties']['position']['minimum'] );
		self::assertSame( 0, $update['properties']['parent_item_id']['minimum'] );
		foreach ( array( 'object', 'object_id', 'type', 'status', 'xfn', 'menu_id' ) as $not_updatable ) {
			self::assertArrayNotHasKey( $not_updatable, $update['properties'] );
		}
		foreach ( $abilities as $name => $args ) {
			self::assertSame( array( 'public' => true, 'type' => 'tool' ), $args['meta']['mcp'], $name );
			self::assertTrue( ( $args['permission_callback'] )( array() ), $name );
		}
	}

	public function test_every_ability_requires_edit_theme_options(): void {
		$abilities = self::registered();
		foreach ( array( array( 'edit_posts', 'edit_others_posts', 'manage_categories', 'publish_pages' ), array( 'read' ), array() ) as $caps ) {
			$GLOBALS['wstm_menus']['caps'] = $caps;
			foreach ( $abilities as $name => $args ) {
				$result = ( $args['permission_callback'] )( array( 'menu_id' => 10, 'menu_item_id' => 101 ) );
				self::assertInstanceOf( WP_Error::class, $result, $name );
				self::assertSame( 'forbidden', $result->get_error_code(), $name );
			}
		}
	}

	public function test_list_menus_records_locations_and_theme_flags(): void {
		$data = Menus::list_menus()['data'];
		self::assertSame( array( 'items', 'truncated', 'registered_locations', 'classic_menus_supported', 'block_theme' ), array_keys( $data ) );
		self::assertSame( array( 'number' => 101 ), $GLOBALS['wstm_menus']['menus_args'][0] );
		self::assertSame( array(
			array( 'id' => 20, 'name' => 'Footer', 'slug' => 'footer', 'item_count' => 1, 'locations' => array( 'footer' ), 'untrusted_fields' => array( 'name', 'slug' ) ),
			array( 'id' => 10, 'name' => 'Main', 'slug' => 'main', 'item_count' => 3, 'locations' => array( 'primary' ), 'untrusted_fields' => array( 'name', 'slug' ) ),
		), $data['items'] );
		self::assertFalse( $data['truncated'] );
		// The unregistered "stale" assignment is ignored and an unassigned location reports null.
		self::assertSame( array(
			array( 'location' => 'primary', 'description' => 'Primary Menu', 'menu_id' => 10 ),
			array( 'location' => 'footer', 'description' => 'Footer Menu', 'menu_id' => 20 ),
			array( 'location' => 'social', 'description' => 'Social Links', 'menu_id' => null ),
		), $data['registered_locations'] );
		self::assertTrue( $data['classic_menus_supported'] );
		self::assertFalse( $data['block_theme'] );
	}

	public function test_list_menus_reports_block_theme_without_classic_support(): void {
		$GLOBALS['wstm_menus']['terms']          = array();
		$GLOBALS['wstm_menus']['registered']     = array();
		$GLOBALS['wstm_menus']['locations']      = false;
		$GLOBALS['wstm_menus']['theme_supports'] = false;
		$GLOBALS['wstm_menus']['block_theme']    = true;
		self::assertSame( array( 'items' => array(), 'truncated' => false, 'registered_locations' => array(), 'classic_menus_supported' => false, 'block_theme' => true ), Menus::list_menus()['data'] );
	}

	public function test_list_menus_caps_at_one_hundred_and_reports_truncation(): void {
		$GLOBALS['wstm_menus']['terms'] = array();
		for ( $id = 1; $id <= 101; ++$id ) {
			self::menu( $id, sprintf( 'Menu %03d', $id ), 'menu-' . $id, 0 );
		}
		$data = Menus::list_menus()['data'];
		self::assertCount( 100, $data['items'] );
		self::assertTrue( $data['truncated'] );
		self::assertSame( 'Menu 100', $data['items'][99]['name'] );
	}

	public function test_get_menu_returns_ordered_items_and_redacts_unreadable_posts(): void {
		$GLOBALS['wstm_menus']['unreadable'] = array( 500 );
		$result = Menus::get_menu( array( 'menu_id' => 10 ) );
		self::assertTrue( $result['success'] );
		$data = $result['data'];
		self::assertSame( array( 'id', 'name', 'slug', 'item_count', 'locations', 'untrusted_fields', 'items', 'page', 'per_page', 'next_page' ), array_keys( $data ) );
		self::assertSame( array( 10, 'Main', array( 'primary' ), array( 'name', 'slug' ) ), array( $data['id'], $data['name'], $data['locations'], $data['untrusted_fields'] ) );
		self::assertSame( array( 101, 102, 103 ), array_column( $data['items'], 'id' ) );
		self::assertSame( array(
			'id'             => 101,
			'title'          => 'Docs',
			'url'            => 'https://example.test/docs',
			'type'           => 'custom',
			'object'         => 'custom',
			'object_id'      => 101,
			'parent_item_id' => 0,
			'position'       => 1,
			'target'         => '_blank',
			'attr_title'     => 'Read the docs',
			'classes'        => array( 'nav-docs', 'highlight' ),
			'description'    => 'Stored description',
			'xfn'            => 'nofollow',
			'status'         => 'publish',
			'restricted'     => false,
			'untrusted_fields' => self::ITEM_TEXT,
		), $data['items'][0] );
		$private = $data['items'][1];
		self::assertNull( $private['title'] );
		self::assertNull( $private['url'] );
		self::assertTrue( $private['restricted'] );
		self::assertSame( array( 'post_type', 'page', 500, 101, array() ), array( $private['type'], $private['object'], $private['object_id'], $private['parent_item_id'], $private['classes'] ) );
		self::assertSame( self::ITEM_TEXT, $private['untrusted_fields'], 'Markers stay consistent for redacted records.' );
		$encoded = json_encode( $data );
		self::assertStringNotContainsString( 'Secret Private Page', $encoded );
		self::assertStringNotContainsString( '?p=500', $encoded );
		self::assertSame( array( 'News', 'https://example.test/?cat=7', false ), array( $data['items'][2]['title'], $data['items'][2]['url'], $data['items'][2]['restricted'] ) );
	}

	public function test_get_menu_shows_readable_linked_posts(): void {
		$item = Menus::get_menu( array( 'menu_id' => 10 ) )['data']['items'][1];
		self::assertSame( array( 'Secret Private Page', 'https://example.test/?p=500', false ), array( $item['title'], $item['url'], $item['restricted'] ) );
	}

	public function test_get_menu_paging_and_not_found(): void {
		$first = Menus::get_menu( array( 'menu_id' => 10, 'per_page' => 2 ) )['data'];
		self::assertSame( array( 101, 102 ), array_column( $first['items'], 'id' ) );
		self::assertSame( array( 1, 2, 2 ), array( $first['page'], $first['per_page'], $first['next_page'] ) );
		$second = Menus::get_menu( array( 'menu_id' => 10, 'per_page' => 2, 'page' => 2 ) )['data'];
		self::assertSame( array( 103 ), array_column( $second['items'], 'id' ) );
		self::assertNull( $second['next_page'] );
		$past = Menus::get_menu( array( 'menu_id' => 10, 'page' => 9 ) )['data'];
		self::assertSame( array( array(), null, 100 ), array( $past['items'], $past['next_page'], $past['per_page'] ) );
		self::assertSame( 200, Menus::get_menu( array( 'menu_id' => 10, 'per_page' => 500 ) )['data']['per_page'] );
		// A category term ID, a missing ID and a non-integer never resolve (no name/slug fallback).
		foreach ( array( 7, 999, 0, -1, '10', 'Main' ) as $menu_id ) {
			self::assert_error( Menus::get_menu( array( 'menu_id' => $menu_id ) ), 'not_found', 'not_found' );
		}
	}

	public function test_update_title_preserves_every_untouched_field(): void {
		$result = self::update( array( 'menu_item_id' => 101, 'title' => '  New <b>Docs</b>  label ' ) );
		self::assertTrue( $result['success'], json_encode( $result ) );
		[ $menu_id, $item_id, $args ] = $GLOBALS['wstm_menus']['updates'][0];
		self::assertSame( array( 10, 101 ), array( $menu_id, $item_id ) );
		// Every argument core would otherwise reset is passed back explicitly.
		self::assertSame( array( 'menu-item-db-id', 'menu-item-object-id', 'menu-item-object', 'menu-item-parent-id', 'menu-item-position', 'menu-item-type', 'menu-item-title', 'menu-item-url', 'menu-item-description', 'menu-item-attr-title', 'menu-item-target', 'menu-item-classes', 'menu-item-xfn', 'menu-item-status', 'menu-item-post-date', 'menu-item-post-date-gmt' ), array_keys( $args ) );
		self::assertSame( array( '2026-01-02 03:04:05', '2026-01-02 08:04:05' ), array( $args['menu-item-post-date'], $args['menu-item-post-date-gmt'] ), 'Core resets an omitted post date to now.' );
		$data = $result['data'];
		self::assertSame( 'New Docs label', $data['title'] );
		self::assertSame( array( 'https://example.test/docs', '_blank', 'Read the docs', 'Stored description', array( 'nav-docs', 'highlight' ), 'nofollow', 1, 0, 'publish', 'custom' ), array( $data['url'], $data['target'], $data['attr_title'], $data['description'], $data['classes'], $data['xfn'], $data['position'], $data['parent_item_id'], $data['status'], $data['type'] ) );
		self::assertSame( 10, $data['menu_id'] );
		self::assertSame( array( 'title' => 'Docs', 'untrusted_fields' => array( 'title' ) ), $data['previous'] );
		self::assertSame( self::ITEM_TEXT, $data['untrusted_fields'] );
		// The stored item matches get-menu output afterwards.
		self::assertSame( array_diff_key( $data, array( 'menu_id' => 1, 'previous' => 1 ) ), Menus::get_menu( array( 'menu_id' => 10 ) )['data']['items'][0] );
	}

	public function test_update_on_a_linked_item_keeps_its_object_and_parent(): void {
		$result = self::update( array( 'menu_item_id' => 102, 'attr_title' => 'About us', 'classes' => array( 'a b', 'x"onclick', 'ok', 'ok', '' ), 'target' => '_blank', 'position' => 7 ) );
		self::assertTrue( $result['success'], json_encode( $result ) );
		$data = $result['data'];
		self::assertSame( array( 'post_type', 'page', 500, 101, 7, '_blank', 'About us', array( 'ab', 'xonclick', 'ok' ) ), array( $data['type'], $data['object'], $data['object_id'], $data['parent_item_id'], $data['position'], $data['target'], $data['attr_title'], $data['classes'] ) );
		self::assertSame( 'Secret Private Page', $data['title'], 'Empty stored label still follows the linked page.' );
		self::assertSame( array( 'target', 'attr_title', 'classes', 'position' ), array_values( array_diff( array_keys( $data['previous'] ), array( 'untrusted_fields' ) ) ) );
		self::assertSame( array( '', array(), '', 2 ), array( $data['previous']['attr_title'], $data['previous']['classes'], $data['previous']['target'], $data['previous']['position'] ) );
		self::assertSame( array( 'attr_title', 'classes' ), $data['previous']['untrusted_fields'] );
	}

	public function test_update_redacts_restricted_items_in_result_and_previous(): void {
		$GLOBALS['wstm_menus']['unreadable'] = array( 500 );
		$data = self::update( array( 'menu_item_id' => 102, 'title' => 'Team' ) )['data'];
		self::assertSame( array( null, null, true ), array( $data['title'], $data['url'], $data['restricted'] ) );
		self::assertNull( $data['previous']['title'] );
		self::assertStringNotContainsString( 'Secret Private Page', json_encode( $data ) );
	}

	public function test_update_requires_at_least_one_field_and_an_existing_item(): void {
		self::assert_error( self::update( array( 'menu_item_id' => 101 ) ), 'invalid_input', 'invalid_input' );
		foreach ( array( 999, 500, 600, 7, 10, 0 ) as $id ) {
			self::assert_error( self::update( array( 'menu_item_id' => $id, 'title' => 'X' ) ), 'not_found', 'not_found' );
		}
	}

	public function test_update_requires_the_item_to_belong_to_exactly_one_menu(): void {
		foreach ( array( array(), array( 10, 20 ) ) as $terms ) {
			$GLOBALS['wstm_menus']['object_terms'][101] = $terms;
			self::assert_error( self::update( array( 'menu_item_id' => 101, 'title' => 'X' ) ), 'precondition_failed', 'precondition_failed' );
		}
	}

	public function test_update_honors_object_level_edit_permission(): void {
		$GLOBALS['wstm_menus']['uneditable'] = array( 101 );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'title' => 'X' ) ), 'forbidden', 'forbidden' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function rejected_urls(): array {
		$urls = array(
			'javascript', 'javascript:alert(1)', 'mixed case javascript', 'JaVaScRiPt:alert(1)', 'data', 'data:text/html;base64,PHNjcmlwdD4=',
			'vbscript', 'vbscript:msgbox(1)', 'VBScript upper', 'VBSCRIPT:msgbox(1)', 'leading space', ' javascript:alert(1)',
			'leading tab', "\tjavascript:alert(1)", 'embedded newline', "java\nscript:alert(1)", 'embedded tab', "java\tscript:alert(1)",
			'leading NUL', "\0javascript:alert(1)", 'DEL', "https://example.test/\x7f", 'trailing space', 'https://example.test/ ',
			'inner space', 'https://example.test/a b', 'protocol relative', '//evil.example/x', 'backslash protocol relative', '/\\evil.example',
			'backslash host', 'https:\\\\evil.example', 'entity colon', 'javascript&#58;alert(1)', 'encoded scheme', '%6Aavascript:alert(1)',
			'bare host', 'example.test/page', 'relative file', 'page.html', 'file', 'file:///etc/passwd', 'ftp', 'ftp://example.test/',
			'http without host', 'https:example.test', 'http empty host', 'https:///path', 'http port only', 'https://:443/', 'userinfo', 'https://trusted.example@evil.example/',
			'empty mailto', 'mailto:', 'empty tel', 'tel:', 'empty', '', 'unknown scheme', 'chrome://settings', 'about blank', 'about:blank',
		);
		$cases = array();
		for ( $index = 0; $index < count( $urls ); $index += 2 ) {
			$cases[ $urls[ $index ] ] = array( $urls[ $index + 1 ] );
		}
		return $cases;
	}

	/**
	 * @dataProvider rejected_urls
	 */
	public function test_unsafe_urls_are_rejected( string $url ): void {
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'url' => $url ) ), 'invalid_input', 'invalid_url' );
	}

	public function test_safe_urls_are_accepted_and_sanitized_with_restricted_protocols(): void {
		$previous = 'https://example.test/docs';
		foreach ( array( 'https://example.test/a?b=c#d', 'http://example.test:8080', 'HTTPS://Example.test/', 'mailto:team@example.test', 'tel:+15551234567', '/about-us/', '#main', '?s=menu' ) as $url ) {
			$result = self::update( array( 'menu_item_id' => 101, 'url' => $url ) );
			self::assertTrue( $result['success'], $url . ' ' . json_encode( $result ) );
			self::assertSame( $url, $result['data']['url'] );
			self::assertSame( $previous, $result['data']['previous']['url'] );
			self::assertSame( array( $url, array( 'http', 'https', 'mailto', 'tel' ) ), end( $GLOBALS['wstm_menus']['esc_url_raw'] ) );
			$previous = $url;
		}
	}

	public function test_urls_that_sanitize_into_an_off_site_or_unsafe_form_are_rejected(): void {
		// esc_url_raw() strips %0d/%0a and disallowed characters, which can create "//host".
		foreach ( array( '/%0d/evil.example', '/%0a/evil.example', '/%0D%0A/evil.example', '/%0%0dd/evil.example', '/</evil.example', '/"/evil.example', '/^/evil.example', '/{}/evil.example' ) as $url ) {
			self::assert_error( self::update( array( 'menu_item_id' => 101, 'url' => $url ) ), 'invalid_input', 'invalid_url' );
		}
	}

	public function test_legitimate_url_forms_are_accepted(): void {
		foreach ( array( 'http://[::1]:8080/x', "https://b\xc3\xbccher.example/", 'https://example.test/?mail=a@b.test', 'https://example.test#a@b', '/a/%0d-literal-ok/b', '/.//still-same-site' ) as $url ) {
			$result = self::update( array( 'menu_item_id' => 101, 'url' => $url ) );
			self::assertTrue( $result['success'], $url . ' ' . json_encode( $result ) );
		}
	}

	public function test_items_that_core_would_revive_or_move_are_refused(): void {
		foreach ( array( 'trash', 'pending', 'private', 'future' ) as $status ) {
			$GLOBALS['wstm_menus']['posts'][101]->post_status = $status;
			self::assert_error( self::update( array( 'menu_item_id' => 101, 'title' => 'X' ) ), 'precondition_failed', 'precondition_failed' );
		}
		$GLOBALS['wstm_menus']['posts'][101]->post_status = 'draft';
		$result = self::update( array( 'menu_item_id' => 101, 'title' => 'Draft kept' ) );
		self::assertSame( 'draft', $result['data']['status'] );
		$GLOBALS['wstm_menus']['updates'] = array();

		// Position 0 means "append" to core: refuse unless the caller sets a position.
		$GLOBALS['wstm_menus']['posts'][201]->menu_order = 0;
		self::assert_error( self::update( array( 'menu_item_id' => 201, 'title' => 'X' ) ), 'precondition_failed', 'precondition_failed' );
		$result = self::update( array( 'menu_item_id' => 201, 'title' => 'Y', 'position' => 4 ) );
		self::assertTrue( $result['success'], json_encode( $result ) );
		self::assertSame( 4, $result['data']['position'] );
	}

	public function test_url_emptied_by_esc_url_raw_is_rejected(): void {
		$GLOBALS['wstm_menus']['empty_url'] = true;
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'url' => 'https://example.test/' ) ), 'invalid_input', 'invalid_url' );
	}

	public function test_url_is_rejected_for_non_custom_items(): void {
		foreach ( array( 102, 103 ) as $id ) {
			$result = self::update( array( 'menu_item_id' => $id, 'url' => 'https://example.test/' ) );
			self::assert_error( $result, 'invalid_input', 'invalid_input' );
			self::assertStringContainsString( 'custom link items', $result['error']['message'] );
		}
	}

	public function test_text_fields_are_plain_bounded_and_custom_titles_non_empty(): void {
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'title' => '<b></b>' ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'title' => str_repeat( 'a', 201 ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'attr_title' => str_repeat( 'é', 201 ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'description' => str_repeat( 'a', 1001 ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'target' => '_self' ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'classes' => array_fill( 0, 21, 'a' ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'classes' => array( str_repeat( 'a', 101 ) ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'classes' => array( 'x' => 'a' ) ) ), 'invalid_input', 'invalid_input' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'position' => 0 ) ), 'invalid_input', 'invalid_input' );
		self::assertSame( str_repeat( 'é', 200 ), self::update( array( 'menu_item_id' => 101, 'title' => str_repeat( 'é', 200 ) ) )['data']['title'] );
		// An empty label is allowed on a linked item: it follows the linked object's title again.
		self::assertSame( 'Custom', self::update( array( 'menu_item_id' => 103, 'title' => 'Custom', 'description' => "Line one\n<script>x</script>Line two" ) )['data']['title'] );
		$data = self::update( array( 'menu_item_id' => 103, 'title' => '' ) )['data'];
		self::assertSame( array( 'News', 'Custom', "Line one\nxLine two" ), array( $data['title'], $data['previous']['title'], $data['description'] ) );
	}

	public function test_text_is_passed_slashed_to_core(): void {
		self::update( array( 'menu_item_id' => 101, 'title' => "O'Reilly \\ Co" ) );
		self::assertSame( "O\\'Reilly \\\\ Co", $GLOBALS['wstm_menus']['updates'][0][2]['menu-item-title'] );
		self::assertSame( "O'Reilly \\ Co", $GLOBALS['wstm_menus']['posts'][101]->post_title );
	}

	public function test_parent_must_be_another_item_of_the_same_menu_without_cycles(): void {
		// Self, a cross-menu item, a non-item post and a missing post.
		foreach ( array( 101 => 101, 201 => 101, 600 => 101, 999 => 101 ) as $parent => $item ) {
			self::assert_error( self::update( array( 'menu_item_id' => $item, 'parent_item_id' => $parent ) ), 'invalid_input', 'invalid_parent' );
		}
		// 102 is a child of 101, so making 101 a child of 102 would be a cycle.
		$result = self::update( array( 'menu_item_id' => 101, 'parent_item_id' => 102 ) );
		self::assert_error( $result, 'invalid_input', 'invalid_parent' );
		self::assertStringContainsString( 'cycle', $result['error']['message'] );
		// Deeper cycle: 103 -> 102 -> 101, then 101 under 103.
		$GLOBALS['wstm_menus']['meta'][103]['_menu_item_menu_item_parent'] = '102';
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'parent_item_id' => 103 ) ), 'invalid_input', 'invalid_parent' );
		self::assert_error( self::update( array( 'menu_item_id' => 101, 'parent_item_id' => -1 ) ), 'invalid_input', 'invalid_parent' );
	}

	public function test_existing_ancestor_loops_are_bounded(): void {
		// 102 <-> 103 already loop (corrupt data); the walk stops instead of spinning.
		$GLOBALS['wstm_menus']['meta'][102]['_menu_item_menu_item_parent'] = '103';
		$GLOBALS['wstm_menus']['meta'][103]['_menu_item_menu_item_parent'] = '102';
		$result = self::update( array( 'menu_item_id' => 101, 'parent_item_id' => 102 ) );
		self::assert_error( $result, 'invalid_input', 'invalid_parent' );
		self::assertStringContainsString( 'nested too deeply', $result['error']['message'] );
	}

	public function test_valid_reparenting_and_moving_to_top_level(): void {
		$data = self::update( array( 'menu_item_id' => 103, 'parent_item_id' => 102 ) )['data'];
		self::assertSame( array( 102, 0 ), array( $data['parent_item_id'], $data['previous']['parent_item_id'] ) );
		$data = self::update( array( 'menu_item_id' => 102, 'parent_item_id' => 0 ) )['data'];
		self::assertSame( array( 0, 101 ), array( $data['parent_item_id'], $data['previous']['parent_item_id'] ) );
		self::assertSame( array(), $data['previous']['untrusted_fields'] );
	}

	public function test_core_update_errors_are_not_leaked(): void {
		$GLOBALS['wstm_menus']['update_error'] = new WP_Error( 'db_update_error', 'SECRET SQL text' );
		$result = self::update( array( 'menu_item_id' => 101, 'title' => 'X' ) );
		self::assertFalse( $result['success'] );
		self::assertSame( 'upstream_failed', $result['error']['code'] );
		self::assertStringNotContainsString( 'SECRET', json_encode( $result ) );
	}
}
