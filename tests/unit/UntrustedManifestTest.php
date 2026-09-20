<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UntrustedManifestTest extends TestCase {
	private static function cases(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	private static function required_markers( array $case ): array {
		if ( 'success' !== $case['expect'] ) {
			return array();
		}
		$slug = substr( $case['ability'], strlen( 'webmastery-site-toolkit-for-mcp/' ) );
		$post = array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' );
		$revision = array( 'author_name', 'title', 'content', 'excerpt' );
		if ( preg_match( '/^(get|create|update|delete)-(post|page|cpt-.+)$/', $slug ) || in_array( $slug, array( 'set-featured-image', 'remove-featured-image' ), true ) ) {
			return array( 'data.untrusted_fields' => $post );
		}
		if ( preg_match( '/^list-(posts|pages|cpt-.+)$/', $slug ) && 0 !== ( $case['assert_values']['data.total'] ?? null ) ) {
			return array( 'data.items.0.untrusted_fields' => $post );
		}
		switch ( $slug ) {
			case 'list-revisions':
				return array( 'data.revisions.0.untrusted_fields' => $revision );
			case 'restore-revision':
				return array( 'data.revision.untrusted_fields' => $revision, 'data.post.untrusted_fields' => $post );
			case 'list-content-blocks':
				return array( 'data.blocks.0.untrusted_fields' => array( 'block_name', 'text', 'html', 'attrs' ) );
			case 'patch-content-block':
				return array( 'data.untrusted_fields' => array( 'content' ) );
			case 'patch-post-content':
				return array( 'data.post.untrusted_fields' => $post, 'data.target.untrusted_fields' => 'heading' === $case['input']['target_type'] ? array( 'heading_text' ) : array() );
			case 'get-post-meta':
				return array( 'data.untrusted_fields' => array( 'meta' ) );
			case 'update-post-meta':
				return array( 'data.untrusted_fields' => array( 'meta_key', 'previous_value', 'current_value' ) );
			case 'delete-post-meta':
				return array( 'data.untrusted_fields' => array( 'meta_key' ) );
			case 'get-media':
			case 'upload-image':
			case 'update-media':
			case 'list-media':
				return array( ( 'list-media' === $slug ? 'data.items.0' : 'data' ) . '.untrusted_fields' => array( 'title', 'caption', 'alt_text', 'url', 'filename' ) );
			case 'reply-comment':
			case 'update-comment':
			case 'list-comments':
				return array( ( 'list-comments' === $slug ? 'data.items.0' : 'data' ) . '.untrusted_fields' => array( 'author', 'author_email', 'author_url', 'content' ) );
			case 'list-users':
			case 'get-user':
				return array( ( 'list-users' === $slug ? 'data.items.0' : 'data' ) . '.untrusted_fields' => 'user_lister' === $case['role'] ? array( 'display_name', 'nicename', 'url' ) : array( 'display_name', 'nicename', 'url', 'login', 'email' ) );
			case 'user-access-audit':
				return array( 'data.admin_accounts.0.untrusted_fields' => array( 'login', 'email', 'last_login' ), 'data.application_passwords.0.untrusted_fields' => array( 'user_login', 'app_name' ) );
			case 'get-yoast-metadata':
			case 'get-seopress-metadata':
				return array( 'data.untrusted_fields' => array( 'title', 'url', 'metadata', 'raw_meta' ) );
			case 'get-seo-scores':
			case 'get-readability-scores':
				return array( 'data.items.0.untrusted_fields' => array( 'title', 'url', 'score' ) );
			case 'seo-analyze-post':
				return array( 'data.metrics.untrusted_fields' => array( 'title', 'url', 'slug', 'yoast_meta_description', 'seopress_meta_description', 'yoast_focus_keyword', 'seopress_focus_keywords' ) );
		}
		return array();
	}

	private static function validate( array $cases ): int {
		$count = 0;
		foreach ( $cases as $case ) {
			foreach ( self::required_markers( $case ) as $path => $fields ) {
				if ( $fields !== ( $case['assert_values'][ $path ] ?? null ) ) {
					throw new RuntimeException( $case['label'] . ': missing or weakened marker assertion ' . $path );
				}
				++$count;
			}
		}
		return $count;
	}

	public function test_existing_success_cases_require_record_markers_without_replacing_their_data_assertions(): void {
		self::assertGreaterThan( 190, self::validate( self::cases() ) );
	}

	public static function mutations(): array {
		return array( array( 'get-post', false ), array( 'create-page', false ), array( 'update-cpt-mcp-book', true ), array( 'list-revisions', false ), array( 'list-comments', true ), array( 'get-media', false ), array( 'get-user', true ), array( 'user-access-audit', false ), array( 'get-yoast-metadata', true ), array( 'get-post-meta', false ), array( 'patch-content-block', false ), array( 'seo-analyze-post', true ) );
	}

	/** @dataProvider mutations */
	public function test_removing_or_weakening_marker_coverage_is_detected( string $slug, bool $weaken ): void {
		$cases = self::cases();
		$mutated = false;
		foreach ( $cases as &$case ) {
			if ( 'webmastery-site-toolkit-for-mcp/' . $slug !== $case['ability'] ) {
				continue;
			}
			$paths = self::required_markers( $case );
			if ( ! $paths ) {
				continue;
			}
			$path = array_key_first( $paths );
			if ( $weaken ) {
				array_pop( $case['assert_values'][ $path ] );
			} else {
				unset( $case['assert_values'][ $path ] );
			}
			$mutated = true;
			break;
		}
		unset( $case );
		self::assertTrue( $mutated, 'Mutation must reach a real existing success case.' );
		$this->expectException( RuntimeException::class );
		self::validate( $cases );
	}
}
