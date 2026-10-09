<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class BoundedOwnerIntegrationTest extends TestCase {
	public function testReviewedAdditiveChainRestoresEveryChangedConsumerToFrozenPrerequisites(): void {
		$map = Wstm167SourceTransition::load();
		$prior = Wstm127SourceTransition::load();
		self::assertSame( Wstm127SourceTransition::SEAL, $map['predecessor_seal'] );
		self::assertSame( Wstm119SourceTransition::SEAL, $prior['predecessor_seal'] );
		self::assertCount( 13, $map['files'] );
		self::assertCount( 28, $map['dependencies'] );
		Wstm167SourceTransition::verify_dependencies();
		Wstm127SourceTransition::verify_dependencies();
		foreach ( $map['files'] as $path => $binding ) {
			$current = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			$restored = Wstm167SourceTransition::restore( $path, $current );
			self::assertSame( $prior['dependencies'][ $path ], hash( 'sha256', $restored ), $path );
			self::assertSame( $binding['baseline_blob'], sha1( 'blob ' . strlen( $restored ) . "\0" . $restored ), $path );
			self::assertSame( $restored, Wstm167SourceTransition::restore( $path, str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $current ) ) ) );
			try {
				Wstm167SourceTransition::restore( $path, $current . "\n// foreign source\n" );
				self::fail( 'Accepted source drift: ' . $path );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Bounded integration current source drift: ' . $path, $error->getMessage() );
			}
		}
	}

	public function testNeitherNewNorExistingDependenciesCanBypassTheOuterSeal(): void {
		foreach ( Wstm167SourceTransition::load()['dependencies'] as $target => $hash ) {
			try {
				Wstm127SourceTransition::verify_dependencies( static function ( $path ) use ( $target ) {
					$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
					return $target === $path ? $source . "\n// forged dependency\n" : $source;
				} );
				self::fail( 'Accepted dependency drift: ' . $target );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Bounded integration dependency current source drift: ' . $target, $error->getMessage() );
			}
		}
	}

	public function testForgedPredecessorHashesBlobsAndReverseHunksCannotRewriteHistory(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/bounded-integration-transition.json' );
		foreach ( array( 'predecessor_seal', 'source_head', 'target', 'baseline_sha256', 'current_sha256', 'baseline_blob', 'current_blob', 'start', 'before', 'after', 'dependencies' ) as $key ) {
			try {
				Wstm167SourceTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json ) );
				self::fail( 'Accepted forged binding: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Bounded integration transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	public function testBoundedConsumersRemainOwnedByTheExtractionRatherThanResurrectedPosts(): void {
		$posts = new ReflectionClass( Webmastery_MCP_Posts::class );
		foreach ( array( 'normalize_revision', 'normalize_block', 'register_get_post_meta', 'register_bulk_publish' ) as $method ) {
			self::assertFalse( $posts->hasMethod( $method ) );
		}
		foreach ( array( 'Post_Access' => 'query_readable', 'Post_Content' => 'normalize', 'Post_Meta' => 'can_read_post_meta_key', 'Post_Revisions' => 'normalize_revision', 'Content_Patch' => 'normalize_block' ) as $owner => $method ) {
			self::assertTrue( ( new ReflectionClass( 'Webmastery_MCP_' . $owner ) )->hasMethod( $method ) );
		}
	}
}
