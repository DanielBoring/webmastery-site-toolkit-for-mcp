<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

require_once __DIR__ . '/untrusted-compact-delete-calibration.php';

final class Wstm108_Manifest_Projection {
	public const BASELINE_SHA256 = 'da4395a6a9d6532c10423e25d02c710c2ed150d226dfa87a988b5594ede8fa4a';
	private const INVENTORY_SHA256 = '01ba6e13c4ecd6f1bb6d2323c158898f501ca2b6d0b9adffe2cfa478d556c9da';

	public static function fingerprint( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public static function inventory(): object {
		$inventory = json_decode( file_get_contents( __DIR__ . '/untrusted-manifest-inventory.json' ), false, 512, JSON_THROW_ON_ERROR );
		Assert::assertSame( self::INVENTORY_SHA256, self::fingerprint( $inventory ), 'Immutable reviewed marker inventory changed.' );
		Assert::assertSame( '2ef8c40b7d0ac3cc8b15a9292a4ec987b55854e6', $inventory->marker_source_sha );
		Assert::assertSame( '2feed8d18d0721a4c7ca2e0187005c8cfae76322', $inventory->baseline_source_sha );
		Assert::assertSame( self::BASELINE_SHA256, $inventory->baseline_manifest_sha256 );
		Assert::assertSame( 190, $inventory->marked_case_count );
		Assert::assertSame( 200, $inventory->marker_assertion_count );
		return $inventory;
	}

	public static function project( array $manifest ): array {
		$inventory = self::inventory();
		$calibration = Wstm108_Compact_Delete_Calibration::ledger();
		$manifest = Wstm108_Compact_Delete_Calibration::restore_historical_cases( $manifest, $calibration );
		$historical_deletes = array_column( $calibration->cases, null, 'index' );
		Assert::assertCount( 570, $manifest );
		Assert::assertSame( range( 0, 569 ), array_keys( $manifest ) );
		$copy = json_decode( json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
		$count = 0;
		foreach ( $inventory->cases as $entry ) {
			$case = $copy[ $entry->index ];
			Assert::assertSame( $entry->ability, $case->ability );
			Assert::assertSame( $entry->label, $case->label );
			Assert::assertTrue( property_exists( $case, 'assert_values' ) );
			Assert::assertInstanceOf( stdClass::class, $case->assert_values );
			$policy = (array) $case;
			$policy['input'] = (array) $case->input;
			$policy['assert_values'] = (array) $case->assert_values;
			$required = self::required_markers( $policy );
			if ( isset( $historical_deletes[ $entry->index ] ) ) {
				$historical = $historical_deletes[ $entry->index ];
				Assert::assertSame( $historical->before_sha256, self::fingerprint( $case ) );
				$required = array( 'data.untrusted_fields' => $historical->before->assert_values->{'data.untrusted_fields'} );
			}
			Assert::assertSame( (array) $entry->markers, $required );
			foreach ( $entry->markers as $path => $fields ) {
				Assert::assertTrue( property_exists( $case->assert_values, $path ), 'Missing approved marker: ' . $path );
				Assert::assertSame( $fields, $case->assert_values->$path, 'Changed approved marker: ' . $path );
				unset( $case->assert_values->$path );
				++$count;
			}
			if ( ! $entry->had_assert_values ) {
				Assert::assertSame( array(), get_object_vars( $case->assert_values ) );
				unset( $case->assert_values );
			}
		}
		Assert::assertSame( 200, $count );
		// Unknown assertions and every untouched typed property remain hash-covered.
		Assert::assertSame( self::BASELINE_SHA256, self::fingerprint( $copy ), 'Projected manifest differs from the complete accepted 570-case source.' );
		return $copy;
	}

	public static function required_markers( array $case ): array {
		if ( 'success' !== $case['expect'] ) {
			return array();
		}
		$slug = substr( $case['ability'], strlen( 'webmastery-site-toolkit-for-mcp/' ) );
		$post = array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' );
		$revision = array( 'author_name', 'title', 'content', 'excerpt' );
		if ( preg_match( '/^(get|create|update)-(post|page|cpt-.+)$/', $slug ) || in_array( $slug, array( 'set-featured-image', 'remove-featured-image' ), true ) ) {
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
				$markers = array();
				$blocks = 'list-content-blocks post' === $case['label'] ? 4 : 1;
				for ( $index = 0; $index < $blocks; ++$index ) {
					$markers[ 'data.blocks.' . $index . '.untrusted_fields' ] = array( 'block_name', 'text', 'html', 'attrs' );
				}
				return $markers;
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
}
