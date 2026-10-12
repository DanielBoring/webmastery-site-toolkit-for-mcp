<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm110Boundary\Probe;

require_once __DIR__ . '/fixtures/metadata-boundary-stubs.php';

final class SeoScoreMarkerTest extends TestCase {
	public static function score_keys(): array {
		return [
			[ 'get-seo-scores', '_yoast_wpseo_linkdex' ],
			[ 'get-readability-scores', '_yoast_wpseo_content_score' ],
		];
	}

	public static function scores(): array {
		$cases = [];
		foreach ( self::score_keys() as [ $slug, $key ] ) {
			foreach ( [
				'integer' => [ 82, 82, true ],
				'numeric string' => [ '82', 82, true ],
				'zero integer' => [ 0, 0, true ],
				'zero string' => [ '0', 0, true ],
				'empty metadata' => [ '', null, true ],
				'absent metadata' => [ '', null, false ],
			] as $label => [ $raw, $expected, $stored ] ) {
				$cases[ $slug . ' ' . $label ] = [ $slug, $key, $raw, $expected, $stored ];
			}
		}
		return $cases;
	}

	private function seed(): void {
		Probe::reset();
		Probe::$posts[42] = (object) [
			'ID' => 42, 'post_type' => 'post', 'post_status' => 'publish',
			'post_title' => 'Stored <b>title</b>', 'post_content' => '', 'post_name' => 'score',
			'post_modified_gmt' => '2026-01-01 00:00:00',
		];
	}

	/** @dataProvider scores */
	public function test_present_integer_and_null_scores_remain_marked_without_value_changes( string $slug, string $key, $raw, ?int $expected, bool $stored ): void {
		$this->seed();
		if ( $stored ) {
			Probe::$metadata[42][ $key ] = $raw;
		}
		$result = Probe::execute( $slug, [ 'post_type' => 'post', 'per_page' => 1 ] );
		self::assertTrue( $result['success'] );
		self::assertCount( 1, $result['data']['items'] );
		$item = $result['data']['items'][0];
		self::assertSame( $expected, $item['score'] );
		self::assertSame( 'Stored <b>title</b>', $item['title'] );
		self::assertSame( [ 'title', 'url', 'score' ], $item['untrusted_fields'] );
		self::assertSame( [ 'post_id', 'title', 'url', 'post_type', 'modified_gmt', 'score', 'untrusted_fields' ], array_keys( $item ) );
		self::assertSame( [ [ 42, $key ] ], Probe::$reads );
		self::assertContains( [ 'edit_post', [ 42 ] ], Probe::$capabilities );
		self::assertContains( [ 'edit_post_meta', [ 42, $key ] ], Probe::$capabilities );
		self::assertArrayNotHasKey( 'untrusted_fields', $result );
		self::assertArrayNotHasKey( 'untrusted_fields', $result['data'] );
		self::assertSame( [], Probe::$mutations );
	}

	public static function denials(): array {
		$cases = [];
		foreach ( self::score_keys() as [ $slug, $key ] ) {
			foreach ( [ 'base', 'object', 'key' ] as $denial ) {
				$cases[ $slug . ' ' . $denial ] = [ $slug, $key, $denial ];
			}
		}
		return $cases;
	}

	/** @dataProvider denials */
	public function test_permission_denials_never_read_or_mark_hidden_scores( string $slug, string $key, string $denial ): void {
		$this->seed();
		Probe::$metadata[42][ $key ] = 'HIDDEN_SCORE_MARKER';
		if ( 'base' === $denial ) {
			Probe::$deny_base = true;
		} elseif ( 'object' === $denial ) {
			Probe::$denied_objects = [ 42 ];
		} else {
			Probe::$denied_keys = [ $key ];
		}
		$result = Probe::execute( $slug, [ 'post_type' => 'post', 'per_page' => 1 ] );
		self::assertSame( [], Probe::$reads );
		self::assertSame( [], Probe::$mutations );
		self::assertStringNotContainsString( 'HIDDEN_SCORE_MARKER', json_encode( $result, JSON_THROW_ON_ERROR ) );
		self::assertArrayNotHasKey( 'untrusted_fields', $result );
		if ( 'base' === $denial ) {
			self::assertFalse( $result['success'] );
			self::assertSame( 'forbidden', $result['error']['code'] );
			self::assertArrayNotHasKey( 'data', $result );
			self::assertSame( [], Probe::$queries );
		} else {
			self::assertTrue( $result['success'] );
			self::assertSame( [], $result['data']['items'] );
			self::assertArrayNotHasKey( 'untrusted_fields', $result['data'] );
		}
	}

	public function test_inactive_provider_has_no_records_or_metadata_markers(): void {
		foreach ( self::score_keys() as [ $slug, $key ] ) {
			$this->seed();
			Probe::$providers_active = false;
			Probe::$metadata[42][ $key ] = 82;
			$result = Probe::execute( $slug, [] );
			self::assertTrue( $result['success'] );
			self::assertSame( [], $result['data']['items'] );
			self::assertSame( [], Probe::$reads );
			self::assertSame( [], Probe::$queries );
			self::assertArrayNotHasKey( 'untrusted_fields', $result['data'] );
		}
	}
}
