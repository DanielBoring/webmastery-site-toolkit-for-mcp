<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm119Shared\State;
use Wstm119Shared\Webmastery_MCP_Permissions as Permissions;
use Wstm119Shared\Webmastery_MCP_Post_Access as Access;
use Wstm119Shared\Webmastery_MCP_Post_Content as Content;
use Wstm119Shared\Webmastery_MCP_Post_Writes as Writes;

require_once __DIR__ . '/fixtures/shared-helpers-stubs.php';

final class SharedHelpersTest extends TestCase {
	protected function setUp(): void {
		State::$posts = array();
		State::$calls = array();
		State::$queries = array();
		State::$ids = array();
		State::$terms = array();
		State::$writes = array();
		State::$denied_ids = array();
		State::$allowed = true;
		State::$result = 42;
	}

	private static function invoke( string $namespace, string $class, string $method, array $args = array() ) {
		if ( 'Wstm119Shared' === $namespace && 'Posts' === $class && in_array( $method, array( 'permission', 'object_permission', 'restore_permission' ), true ) ) {
			$class = 'Post_Access';
		}
		$reflection = new ReflectionMethod( $namespace . '\\Webmastery_MCP_' . $class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private static function post( int $id, string $status = 'draft', string $type = 'post' ): object {
		return (object) array(
			'ID' => $id, 'post_status' => $status, 'post_type' => $type,
			'post_title' => 'Quoted "title"', 'post_content' => '<p>C:\\path\\</p>',
			'post_excerpt' => 'Excerpt', 'post_name' => 'slug', 'post_author' => '7',
			'post_mime_type' => 'image/jpeg', 'post_parent' => 0,
			'post_date' => '2026-09-30 12:00:00', 'post_modified' => '2026-09-30 13:00:00',
		);
	}

	public function testEquivalentPermissionFacadesRemainDeferredUncachedAndExact(): void {
		$type = (object) array( 'name' => 'book', 'cap' => (object) array( 'edit_posts' => 'edit_books' ) );
		foreach ( array(
			array( 'Posts', 'permission', array( 'edit_posts' ) ),
			array( 'Media', 'permission', array( 'upload_files' ) ),
			array( 'Custom_Post_Types', 'permission', array( $type, 'edit_posts' ) ),
			array( 'Posts', 'object_permission', array( 'post', 'id', 'edit_post' ) ),
			array( 'Posts', 'restore_permission', array( 'post', 'id' ) ),
			array( 'Media', 'attachment_permission', array( 'media_id', 'edit_post' ) ),
			array( 'Custom_Post_Types', 'object_permission', array( $type, 'edit_post' ) ),
			array( 'Content_Hygiene', 'permission', array( 'edit_posts' ) ),
			array( 'Comments', 'reply_permission', array() ),
		) as [ $class, $method, $args ] ) {
			State::$calls = array();
			$before = self::invoke( 'Wstm119Before', $class, $method, $args );
			$after = self::invoke( 'Wstm119Shared', $class, $method, $args );
			self::assertSame( array(), State::$calls, 'Factories must not authorize during registration.' );
			foreach ( array( true, false, true ) as $allowed ) {
				State::$allowed = $allowed;
				foreach ( array( array(), array( 'id' => 42, 'media_id' => 42 ), array( 'id' => -42, 'media_id' => -42 ) ) as $input ) {
					foreach ( array( null, self::post( 42 ), self::post( 42, 'inherit', 'attachment' ), self::post( 42, 'draft', 'book' ) ) as $post ) {
						State::$posts = array( 42 => $post );
						State::$calls = array();
						$old = $before( $input );
						$calls = State::$calls;
						State::$calls = array();
						$new = $after( $input );
						self::assertEquals( $old, $new, $class . '::' . $method );
						self::assertSame( $calls, State::$calls );
					}
				}
			}
		}
	}

	public function testObjectFactoryPreservesMissingTypeAndCallerDiagnostics(): void {
		$check = Permissions::object( 'page', 'page_id', 'delete_post', 'Page not found.', 'Exact caller denial.' );
		self::assertSame( array(), State::$calls );
		foreach ( array( array(), array( 'page_id' => 12 ) ) as $input ) {
			self::assertSame( 'not_found', $check( $input )->get_error_code() );
			self::assertSame( 'Page not found.', $check( $input )->get_error_message() );
		}
		State::$posts[12] = self::post( 12, 'trash', 'post' );
		self::assertSame( 'not_found', $check( array( 'page_id' => 12 ) )->get_error_code() );
		self::assertSame( array(), State::$calls );
		State::$posts[12]->post_type = 'page';
		State::$allowed = false;
		$denied = $check( array( 'page_id' => -12 ) );
		self::assertSame( 'forbidden', $denied->get_error_code() );
		self::assertSame( 'Exact caller denial.', $denied->get_error_message() );
		self::assertSame( array( array( 'delete_post', array( 12 ) ) ), State::$calls );
		State::$allowed = true;
		self::assertTrue( $check( array( 'page_id' => 12 ) ) );
	}

	public function testAdministrativeAndSeoPublicFacadesPreserveEffectiveCapabilityChecks(): void {
		foreach ( array(
			array( 'Backup_Status', 'permission', 'manage_options' ),
			array( 'Performance_Status', 'permission', 'manage_options' ),
			array( 'Database_Health', 'permission', 'manage_options' ),
			array( 'Users', 'permission', 'list_users' ),
			array( 'Users', 'audit_permission', 'edit_users' ),
			array( 'SEO', 'permission_site_overview', 'manage_options' ),
		) as [ $class, $method, $cap ] ) {
			foreach ( array( true, false, true ) as $allowed ) {
				State::$allowed = $allowed;
				State::$calls = array();
				$old = self::invoke( 'Wstm119Before', $class, $method );
				$calls = State::$calls;
				State::$calls = array();
				self::assertEquals( $old, self::invoke( 'Wstm119Shared', $class, $method ) );
				self::assertSame( array( array( $cap, array() ) ), State::$calls );
				self::assertSame( $calls, State::$calls );
			}
		}
	}

	public function testStatusSpecificAccessMappedCptCapsAndAttachmentPolicyMatchBaseline(): void {
		$type = (object) array( 'cap' => (object) array( 'read_post' => 'read_book', 'edit_post' => 'edit_book', 'delete_post' => 'delete_book' ) );
		foreach ( array( 'publish' => 'read', 'private' => 'read', 'trash' => 'delete', 'draft' => 'edit', 'pending' => 'edit', 'future' => 'edit', 'inherit' => 'edit' ) as $status => $cap ) {
			State::$posts[42] = self::post( 42, $status );
			foreach ( array(
				array( 'Posts', 'can_read_full_post', array( 42 ), $cap . '_post' ),
				array( 'Custom_Post_Types', 'can_read_full_post', array( $type, 42 ), $cap . '_book' ),
				array( 'Media', 'can_read_attachment', array( 42 ), 'edit_post' ),
			) as [ $class, $method, $args, $expected ] ) {
				State::$posts[42]->post_type = 'Media' === $class ? 'attachment' : 'post';
				foreach ( array( true, false ) as $allowed ) {
					State::$allowed = $allowed;
					State::$calls = array();
					self::assertSame( $allowed, self::invoke( 'Wstm119Before', $class, $method, $args ) );
					$calls = State::$calls;
					State::$calls = array();
					self::assertSame( $allowed, self::invoke( 'Wstm119Shared', $class, $method, $args ) );
					self::assertSame( array( array( $expected, array( 42 ) ) ), State::$calls );
					self::assertSame( $calls, State::$calls );
				}
			}
		}
		self::assertFalse( Access::can_read( 999 ) );
	}

	public function testAuthorizationPrecedesPaginationAndDisappearingItemsDoNotChangeTotals(): void {
		State::$ids = array( '1', '2', '3', '4', '5' );
		State::$posts = array( 1 => self::post( 1 ), 3 => self::post( 3 ), 5 => self::post( 5 ) );
		$seen = array();
		$result = Access::query_readable( array( 'post_type' => 'post', 'paged' => 9, 'posts_per_page' => 4 ), 2, 2,
			static function ( $id ) use ( &$seen ) { $seen[] = $id; return 0 !== $id % 2; },
			static fn( $post ) => $post ? array( 'id' => $post->ID ) : null );
		self::assertSame( array( 1, 2, 3, 4, 5 ), $seen );
		self::assertSame( array( 'items' => array( array( 'id' => 5 ) ), 'total' => 3, 'total_pages' => 2 ), $result );
		self::assertSame( array( 'post_type' => 'post', 'paged' => 1, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ), State::$queries[0] );
		unset( State::$posts[5] );
		self::assertSame( array( 'items' => array(), 'total' => 3, 'total_pages' => 2 ), Access::paginate( array( 1, 3, 5 ), 2, 2, static fn( $post ) => $post ? array( 'id' => $post->ID ) : null ) );
		self::assertSame( array( 'items' => array(), 'total' => 3, 'total_pages' => 2 ), Access::paginate( array( 1, 3, 5 ), 20, 2, static fn( $post ) => $post ) );
		self::assertSame( array( 'items' => array(), 'total' => 0, 'total_pages' => 0 ), Access::paginate( array(), 1, 2, static fn( $post ) => $post ) );
		self::assertSame( array( 'items' => array(), 'total' => 0, 'total_pages' => 1 ), Access::paginate( array(), 1, 0, static fn( $post ) => $post ) );
	}

	public function testListPolicyPreservesDefaultsSearchOrderAndEffectiveAuthorRestrictions(): void {
		$defaults = array( 'post_type' => 'book', 'post_status' => 'any', 'posts_per_page' => 20, 'paged' => 1, 'orderby' => 'date', 'order' => 'DESC' );
		self::assertSame( $defaults, Access::list_args( array(), 'book', 'edit_others_books' ) );
		$input = array( 'status' => 'draft', 'per_page' => 0, 'page' => -3, 'search' => '<b>needle</b>', 'author' => -12, 'orderby' => 'title', 'order' => 'asc' );
		$expected = array( 'post_type' => 'book', 'post_status' => 'draft', 'posts_per_page' => 0, 'paged' => 1, 'orderby' => 'title', 'order' => 'ASC', 's' => 'needle', 'author' => 12 );
		self::assertSame( $expected, Access::list_args( $input, 'book', 'edit_others_books' ) );
		State::$allowed = false;
		$expected['author'] = 7;
		self::assertSame( $expected, Access::list_args( $input, 'book', 'edit_others_books' ) );
		State::$allowed = true;
		self::assertSame( array( 'post_type' => 'attachment', 'author' => 12 ), Access::restrict_author( array( 'post_type' => 'attachment', 'author' => 12 ), 'edit_others_posts' ) );
		self::assertSame( array( 'edit_others_books', 'edit_others_books', 'edit_others_books', 'edit_others_posts' ), array_column( State::$calls, 0 ) );
	}

	public function testActualPostCptAndMediaQueryFacadesRetainExactResultsAndCalls(): void {
		$previous = $GLOBALS['wstm_test_posts'] ?? null;
		try {
			$type = (object) array( 'cap' => (object) array( 'read_post' => 'read_book', 'edit_post' => 'edit_book', 'delete_post' => 'delete_book' ) );
			foreach ( array(
				array( 'Posts', 'query_readable_posts', 'post' ),
				array( 'Custom_Post_Types', 'query_readable_posts', 'book' ),
				array( 'Media', 'query_readable_attachments', 'attachment' ),
			) as [ $class, $method, $post_type ] ) {
				State::$posts = array( 1 => self::post( 1, 'draft', $post_type ), 2 => self::post( 2, 'publish', $post_type ), 3 => self::post( 3, 'trash', $post_type ) );
				$GLOBALS['wstm_test_posts'] = State::$posts;
				State::$ids = array( 1, 2, 3, 999 );
				State::$denied_ids = array( 2 );
				foreach ( array( array( 1, 1 ), array( 2, 1 ), array( 20, 1 ), array( 1, 0 ) ) as [ $page, $per_page ] ) {
					$args = array( array( 'post_type' => $post_type, 'orderby' => 'date', 'order' => 'DESC' ), $page, $per_page );
					if ( 'Custom_Post_Types' === $class ) {
						array_unshift( $args, $type );
					}
					State::$calls = State::$queries = array();
					$old = self::invoke( 'Wstm119Before', $class, $method, $args );
					$calls = State::$calls;
					$queries = State::$queries;
					State::$calls = State::$queries = array();
					self::assertSame( $old, self::invoke( 'Wstm119Shared', $class, $method, $args ) );
					self::assertSame( $calls, State::$calls );
					self::assertSame( $queries, State::$queries );
					self::assertSame( 2, $old['total'] );
				}
			}
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['wstm_test_posts'] );
			} else {
				$GLOBALS['wstm_test_posts'] = $previous;
			}
		}
	}

	public function testPaginationClampsRetainEachLegacyPolicy(): void {
		self::assertSame( array( 'per_page' => 20, 'page' => 1 ), Webmastery_MCP_Input::pagination( array() ) );
		self::assertSame( array( 'per_page' => 10, 'page' => 1 ), Webmastery_MCP_Input::pagination( array(), 10 ) );
		foreach ( array( -5, 0, 1, 20, 100, 101, '7' ) as $value ) {
			self::assertSame( min( max( 1, (int) $value ), 100 ), Webmastery_MCP_Input::per_page( array( 'per_page' => $value ) ) );
			self::assertSame( min( (int) $value, 100 ), Webmastery_MCP_Input::per_page( array( 'per_page' => $value ), 20, 100, null ) );
		}
		self::assertSame( 50, Webmastery_MCP_Input::per_page( array( 'per_page' => 100 ), 10, 50 ) );
		self::assertSame( 1, Webmastery_MCP_Input::page( array( 'page' => 0 ) ) );
		self::assertSame( 7, Webmastery_MCP_Input::page( array( 'page' => '7' ) ) );
	}

	public function testFullPostAndCptNormalizersAreExactlyEquivalentIncludingTaxonomyFailure(): void {
		foreach ( array( 'post', 'page', 'book' ) as $type ) {
			$post = self::post( 42, 'publish', $type );
			foreach ( array( 'Posts' => 'normalize', 'Custom_Post_Types' => 'normalize_post' ) as $class => $method ) {
				foreach ( array( array( 5 ), new WP_Error( 'term_error', 'Provider failure.' ) ) as $terms ) {
					State::$terms['genre'] = $terms;
					self::assertSame( self::invoke( 'Wstm119Before', $class, $method, array( $post ) ), self::invoke( 'Wstm119Shared', $class, $method, array( $post ) ) );
				}
			}
		}
		self::assertNull( Content::normalize( 999 ) );
		self::assertSame( array( 'id', 'title', 'content', 'excerpt', 'status', 'slug', 'url', 'author', 'author_name', 'date_created', 'date_modified', 'type', 'featured_image_id' ), array_keys( Content::normalize( $post ) ) );
		self::assertSame( '<p>C:\\path\\</p>', Content::normalize( $post )['content'] );
	}

	public function testSharedSeoTablesPreserveAllWriteEligibilityAndReadOrder(): void {
		$original = self::invoke( 'Wstm119Before', 'Posts', 'writable_protected_meta_keys' );
		$shared = Webmastery_MCP_Post_Meta::writable_keys();
		ksort( $original );
		ksort( $shared );
		self::assertSame( $original, $shared );
		self::assertCount( 36, $shared );
		foreach ( array( 'yoast_post_meta_keys', 'seopress_post_meta_keys' ) as $method ) {
			self::assertSame( self::invoke( 'Wstm119Before', 'SEO', $method ), self::invoke( 'Wstm119Shared', 'SEO', $method ) );
		}
		foreach ( array( '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_seopress_news_disabled', '_seopress_video_disabled' ) as $key ) {
			self::assertArrayNotHasKey( $key, $shared );
		}
	}

	public function testPersistenceSlashesExactlyOnceAndPassesThroughReturnsAndErrors(): void {
		$value = array( 'post_title' => 'Quote\'s "title"', 'post_content' => '{"path":"C:\\\\folder\\file"}', 'nested' => array( 'slash' => '\\\\server\\', 'bool' => true, 'null' => null, 'number' => 5 ) );
		foreach ( array( 42, 0, false, new WP_Error( 'write_failed', 'Provider failure.' ) ) as $result ) {
			State::$result = $result;
			foreach ( array( 'insert', 'update' ) as $method ) {
				State::$writes = array();
				self::assertSame( $result, Writes::$method( $value ) );
				self::assertSame( array( array( 'slash', $value ), array( $method, wp_slash( $value ), true ) ), State::$writes );
			}
			State::$writes = array();
			self::assertSame( $result, Writes::meta( 42, 'key', $value ) );
			self::assertSame( array( array( 'slash', $value ), array( 'meta', 42, 'key', wp_slash( $value ) ) ), State::$writes );
		}
	}

	public function testEveryReviewedSourceTransitionIsReversibleAndRejectsSourceDrift(): void {
		$map = Wstm119SourceTransition::load();
		foreach ( $map['files'] as $path => $binding ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', Wstm119SourceTransition::restore( $path, $source ) ) );
			try {
				Wstm119SourceTransition::restore( $path, $source . "\n// unauthorized drift\n" );
				self::fail( 'Source drift accepted: ' . $path );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'current source drift', $error->getMessage() );
			}
		}
	}

	public function testForgedHistoricalHashesReverseHunksAndDependencyBindingsAreRejected(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/shared-helper-transition.json' );
		foreach ( array( 'baseline_sha256', 'baseline_blob', 'current_sha256', 'current_blob', 'hunks', 'helpers' ) as $key ) {
			$forged = str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json );
			try {
				Wstm119SourceTransition::load( $forged );
				self::fail( 'Forged transition accepted: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Shared-helper transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
