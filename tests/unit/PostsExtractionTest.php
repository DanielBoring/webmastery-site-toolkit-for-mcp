<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm119Shared\State;
use Wstm127Proof\Registrations;

require_once __DIR__ . '/fixtures/posts-extraction-stubs.php';

final class PostsExtractionTest extends TestCase {
	public function testBoundedConsumersRemainOwnedByTheExtractionRatherThanResurrectedPosts(): void {
		$posts = new ReflectionClass( Webmastery_MCP_Posts::class );
		foreach ( array( 'normalize_revision', 'normalize_block', 'register_get_post_meta', 'register_bulk_publish' ) as $method ) {
			self::assertFalse( $posts->hasMethod( $method ) );
		}
		foreach ( array( 'Post_Access' => 'query_readable', 'Post_Content' => 'normalize', 'Post_Meta' => 'can_read_post_meta_key', 'Post_Revisions' => 'normalize_revision', 'Content_Patch' => 'normalize_block' ) as $owner => $method ) {
			self::assertTrue( ( new ReflectionClass( 'Webmastery_MCP_' . $owner ) )->hasMethod( $method ) );
		}
	}
	protected function setUp(): void {
		Registrations::$before = Registrations::$after = Registrations::$reads = array();
		Wstm127Before\Webmastery_MCP_Posts::register();
		Wstm127After\Webmastery_MCP_Posts::register();
		State::$posts = State::$calls = State::$denied_ids = array();
		State::$allowed = true;
	}

	public function testAllNamesSchemasDescriptionsAndAnnotationsAreExactlyUnchanged(): void {
		self::assertCount( 24, Registrations::$before );
		self::assertSame( array_keys( Registrations::$before ), array_keys( Registrations::$after ) );
		foreach ( Registrations::$before as $name => $definition ) {
			$current = Registrations::$after[ $name ];
			if ( in_array( $name, array( 'webmastery-site-toolkit-for-mcp/list-posts', 'webmastery-site-toolkit-for-mcp/list-pages', 'webmastery-site-toolkit-for-mcp/list-revisions' ), true ) ) {
				self::assertSame( array( 'type' => 'string', 'enum' => array( 'summary', 'full' ), 'default' => 'summary',
					'description' => 'Summary omits content only; full returns unchanged stored content.' ), $current['input_schema']['properties']['fields'] );
				if ( 'webmastery-site-toolkit-for-mcp/list-revisions' === $name ) {
					self::assertSame( 'List saved revisions for a WordPress post or page. Summary omits content; use fields full for stored revision content.', $current['description'] );
				} else {
					self::assertStringContainsString( 'bounded candidate windows', $current['description'] );
					self::assertStringContainsString( 'no exact totals', $current['description'] );
				}
				unset( $current['input_schema']['properties']['fields'] );
				$current['description'] = $definition['description'];
			}
			if ( in_array( $name, array( 'webmastery-site-toolkit-for-mcp/set-featured-image', 'webmastery-site-toolkit-for-mcp/remove-featured-image' ), true ) ) {
				self::assertSame( array( 'full', 'compact' ), $current['input_schema']['properties']['response_format']['enum'] );
				self::assertSame( 'full', $current['input_schema']['properties']['response_format']['default'] );
				unset( $current['input_schema']['properties']['response_format'] );
			}
			unset( $definition['execute_callback'], $definition['permission_callback'], $current['execute_callback'], $current['permission_callback'] );
			self::assertSame( $definition, $current, $name );
		}
	}

	public function testFeatureCallbacksBelongToCohesiveOwnersNotPostsWrappers(): void {
		$owners = array(
			'Content_Patch' => array( 'list-content-blocks', 'patch-content-block', 'patch-post-content' ),
			'Featured_Image' => array( 'set-featured-image', 'remove-featured-image' ),
			'Post_Revisions' => array( 'list-revisions', 'restore-revision' ),
			'Post_Meta' => array( 'get-post-meta', 'update-post-meta', 'delete-post-meta' ),
			'Bulk_Posts' => array( 'bulk-trash-posts', 'bulk-publish-posts' ),
		);
		foreach ( $owners as $owner => $slugs ) {
			foreach ( $slugs as $slug ) {
				$callback = Registrations::$after[ 'webmastery-site-toolkit-for-mcp/' . $slug ]['execute_callback'];
				self::assertSame( 'Wstm127After\\Webmastery_MCP_' . $owner, ( new ReflectionFunction( $callback ) )->getClosureScopeClass()->getName() );
			}
		}
		self::assertSame( array( 'register', 'reject_combined_metadata', 'can_read_post_meta_key', 'bulk_input_error', 'normalize', 'can_read_full_post', 'query_readable_posts', 'register_post_type' ), array_map( static fn( $method ) => $method->getName(), ( new ReflectionClass( Webmastery_MCP_Posts::class ) )->getMethods() ) );
	}

	public function testEveryPermissionPreservesLookupCapabilityOrderAndExactDiagnostic(): void {
		$inputs = array(
			array(),
			array( 'post_id' => 42, 'page_id' => 42, 'content_id' => 42, 'content_type' => 'post', 'revision_id' => 42 ),
			array( 'post_id' => -42, 'page_id' => -42, 'content_id' => -42, 'content_type' => 'page', 'revision_id' => -42 ),
			array( 'status' => 'future', 'parent' => 43 ),
			array( 'status' => 'private', 'parent' => 43 ),
		);
		foreach ( Registrations::$before as $name => $definition ) {
			foreach ( array( 'post', 'page', 'revision', 'attachment', 'book' ) as $type ) {
				State::$posts = array(
					42 => (object) array( 'ID' => 42, 'post_type' => $type, 'post_parent' => 43 ),
					43 => (object) array( 'ID' => 43, 'post_type' => 'page', 'post_parent' => 0 ),
				);
				foreach ( array( true, false ) as $allowed ) {
					State::$allowed = $allowed;
					foreach ( $inputs as $input ) {
						State::$calls = Registrations::$reads = array();
						$before = $definition['permission_callback']( $input );
						$calls = State::$calls;
						$reads = Registrations::$reads;
						State::$calls = Registrations::$reads = array();
						self::assertEquals( $before, Registrations::$after[ $name ]['permission_callback']( $input ), $name );
						self::assertSame( $calls, State::$calls, $name );
						self::assertSame( $reads, Registrations::$reads, $name );
					}
				}
			}
		}
	}

	public function testFeatureDirectCallbacksKeepMissingTargetAndConfirmationFailureChannels(): void {
		foreach ( array( 'list-content-blocks', 'patch-content-block', 'patch-post-content', 'set-featured-image', 'remove-featured-image', 'list-revisions', 'restore-revision', 'get-post-meta', 'update-post-meta', 'delete-post-meta', 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
			$name = 'webmastery-site-toolkit-for-mcp/' . $slug;
			$input = array( 'post_id' => 999, 'revision_id' => 999, 'content_id' => 999, 'content_type' => 'post', 'attachment_id' => 999 );
			State::$calls = array();
			$before = Registrations::$before[ $name ]['execute_callback']( $input );
			$calls = State::$calls;
			State::$calls = array();
			self::assertEquals( $before, Registrations::$after[ $name ]['execute_callback']( $input ), $name );
			self::assertSame( $calls, State::$calls, $name );
			self::assertFalse( $before['success'] );
		}
	}

	public function testAllFeatureRegistrationMethodsRemainAtTheirCurrentOwners(): void {
		foreach ( array(
			'Content_Patch' => array( 'register_list_content_blocks', 'register_patch_content_block', 'register_patch_post_content', 'normalize_block' ),
			'Featured_Image' => array( 'register_set_featured_image', 'register_remove_featured_image' ),
			'Post_Revisions' => array( 'register_list_revisions', 'register_restore_revision', 'normalize_revision' ),
			'Post_Meta' => array( 'register_get_post_meta', 'register_update_post_meta', 'register_delete_post_meta', 'writable_keys' ),
			'Bulk_Posts' => array( 'register_bulk_trash_posts', 'register_bulk_publish_posts' ),
			'Post_Access' => array( 'permission', 'object_permission', 'can_read', 'query_readable' ),
		) as $owner => $methods ) {
			foreach ( $methods as $method ) {
				self::assertTrue( ( new ReflectionClass( 'Webmastery_MCP_' . $owner ) )->hasMethod( $method ), $owner . '::' . $method );
				self::assertFalse( ( new ReflectionClass( Webmastery_MCP_Posts::class ) )->hasMethod( $method ), $method );
			}
		}
		self::assertStringNotContainsString( 'SourceTransition', file_get_contents( __DIR__ . '/fixtures/posts-extraction-stubs.php' ) );
	}

	public function testIndependentMainFixtureRejectsAlteredOwnerOracle(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/product-main-baseline.json' );
		foreach ( array( 'main_commit', 'files', 'methods', 'class_sha256', 'class_source', 'main_file_sha256' ) as $key ) {
			try {
				wstm_test_main_baseline( str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json ) );
				self::fail( 'Accepted forged binding: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Independent main semantic baseline changed.', $error->getMessage() );
			}
		}
	}
}
