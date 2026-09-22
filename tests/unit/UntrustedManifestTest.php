<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-manifest-projection.php';

final class UntrustedManifestTest extends TestCase {
	private static function cases(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	private static function required_markers( array $case ): array {
		return Wstm108_Manifest_Projection::required_markers( $case );
	}

	private static function validate( array $cases ): array {
		$count = 0;
		$marked_cases = 0;
		foreach ( $cases as $case ) {
			$markers = self::required_markers( $case );
			$marked_cases += $markers ? 1 : 0;
			foreach ( $markers as $path => $fields ) {
				if ( $fields !== ( $case['assert_values'][ $path ] ?? null ) ) {
					throw new RuntimeException( $case['label'] . ': missing or weakened marker assertion ' . $path );
				}
				++$count;
			}
		}
		return array( 'cases' => $marked_cases, 'assertions' => $count );
	}

	public function test_existing_success_cases_require_record_markers_without_replacing_their_data_assertions(): void {
		self::assertSame( array( 'cases' => 185, 'assertions' => 195 ), self::validate( self::cases() ) );
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
