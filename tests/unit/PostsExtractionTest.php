<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm119Shared\State;
use Wstm127Proof\Registrations;

require_once __DIR__ . '/fixtures/posts-extraction-stubs.php';

final class PostsExtractionTest extends TestCase {
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

	private static function method_source( string $source, string $name ): string {
		preg_match_all( '/^\t(?:private|public) static function (\w+)\(/m', $source, $matches, PREG_OFFSET_CAPTURE );
		foreach ( $matches[1] as $index => [ $method ] ) {
			if ( $name === $method ) {
				$start = $matches[0][ $index ][1];
				$end = $matches[0][ $index + 1 ][1] ?? strrpos( $source, "\n}" );
				return rtrim( substr( $source, $start, $end - $start ) );
			}
		}
		throw new RuntimeException( 'Missing bound method: ' . $name );
	}

	public function testEveryOriginalMethodHasAnExactReviewedOwnerAndReconstructsPriorBytes(): void {
		$root = dirname( __DIR__, 2 );
		$map = Wstm127SourceTransition::load();
		self::assertCount( 61, $map['methods'] );
		$prior = Wstm127SourceTransition::restore( 'includes/class-posts.php', file_get_contents( $root . '/includes/class-posts.php' ) );
		$owners = array();
		foreach ( $map['methods'] as $name => $binding ) {
			$current = Wstm167SourceTransition::restore( $binding['owner'], file_get_contents( $root . '/' . $binding['owner'] ) );
			$before = self::method_source( $prior, $name );
			$after = self::method_source( $current, $name );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', $before ), $name );
			self::assertSame( $binding['current_sha256'], hash( 'sha256', $after ), $name );
			$normalize = static function ( $body ) {
				$body = preg_replace( '/Webmastery_MCP_(?:Post_Access|Post_Meta|Posts|Bulk_Posts)::(\w+)\(/', 'self::$1(', $body );
				return preg_replace( '/public static function/', 'private static function', $body, 1 );
			};
			self::assertSame( $normalize( $before ), $normalize( $after ), $name );
			$owners[ $binding['owner'] ] = ( $owners[ $binding['owner'] ] ?? 0 ) + 1;
		}
		self::assertCount( 7, $owners );
		foreach ( $map['files'] as $path => $binding ) {
			$source = file_get_contents( $root . '/' . $path );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', Wstm127SourceTransition::restore( $path, $source ) ), $path );
			try {
				Wstm127SourceTransition::restore( $path, $source . "\n// forged consumer\n" );
				self::fail( 'Accepted drift: ' . $path );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'current source drift', $error->getMessage() );
			}
		}
		self::assertSame( '1bdd69f8b930f9c744afe557800eeafd195f30eeaa9b61c9b81fff5267e426d6', hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/fixtures/shared-helper-transition.json' ) ) ) );
	}

	public function testForgedBindingsAndDriftedHelpersCannotEnterTheHistoricalChain(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/posts-extraction-transition.json' );
		foreach ( array( 'predecessor_seal', 'files', 'dependencies', 'methods', 'current_sha256', 'current_blob', 'baseline_sha256', 'baseline_blob', 'hunks', 'owner' ) as $key ) {
			try {
				Wstm127SourceTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json ) );
				self::fail( 'Accepted forged binding: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Posts extraction transition seal mismatch.', $error->getMessage() );
			}
		}
		foreach ( Wstm127SourceTransition::load()['dependencies'] as $target => $hash ) {
			try {
				Wstm127SourceTransition::verify_dependencies( static function ( $path ) use ( $target ) {
					$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
					return $target === $path ? $source . "\n// forged helper\n" : $source;
				} );
				self::fail( 'Accepted helper drift: ' . $target );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Bounded integration dependency current source drift: ' . $target, $error->getMessage() );
			}
		}
	}
}
