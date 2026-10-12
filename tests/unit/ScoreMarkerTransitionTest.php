<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class ScoreMarkerTransitionTest extends TestCase {
	public function test_exact_two_path_outer_reversal_preserves_historical_bytes_and_entry_points(): void {
		$root = dirname( __DIR__, 2 );
		$read = static fn( $path ) => Wstm167SelectedListenerTransition::restore( $path, file_get_contents( $root . '/' . $path ) );
		$map = Wstm167ScoreMarkerTransition::load();
		self::assertSame( 'a0e01905214d11aa90a17f265e0f0316a43daa28', $map['base_commit'] );
		self::assertSame( [ 'includes/class-seo.php', 'tests/unit/fixtures/bounded-admission-transition.php' ], array_keys( $map['files'] ) );
		Wstm167ScoreMarkerTransition::verify_dependencies( $read );
		foreach ( $map['files'] as $path => $binding ) {
			$before = Wstm167ScoreMarkerTransition::restore( $path, $read( $path ) );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', $before ), $path );
			self::assertSame( $before, Wstm167ScoreMarkerTransition::restore( $path, str_replace( "\n", "\r\n", $read( $path ) ) ) );
		}
		$before = Wstm167ScoreMarkerTransition::restore( 'includes/class-seo.php', $read( 'includes/class-seo.php' ) );
		$old = "'score'        => '' === \$raw_score ? null : (int) \$raw_score,\n\t\t\t], [ 'title', 'url' ] );";
		$new = str_replace( "[ 'title', 'url' ]", "[ 'title', 'url', 'score' ]", $old );
		self::assertSame( 1, substr_count( $before, $old ) );
		self::assertSame( str_replace( $old, $new, $before ), $read( 'includes/class-seo.php' ) );
		$unbound = str_replace( "\n", "\r\n", $read( 'tests/e2e/abilities-manifest.json' ) );
		self::assertSame( $unbound, Wstm167ScoreMarkerTransition::restore( 'tests/e2e/abilities-manifest.json', $unbound ) );
		foreach ( $map['predecessor_raw_sha256'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $read( $path ) ), $path );
		}
		Wstm167SourceTransition::verify_dependencies();
		Wstm127SourceTransition::verify_dependencies();
		$restored = Wstm119SourceTransition::restore( 'includes/class-seo.php', $read( 'includes/class-seo.php' ) );
		$historical = Wstm119SourceTransition::load()['files']['includes/class-seo.php'];
		self::assertSame( $historical['baseline_sha256'], hash( 'sha256', $restored ) );
		self::assertSame( $historical['baseline_blob'], sha1( 'blob ' . strlen( $restored ) . "\0" . $restored ) );
	}

	public function test_changed_source_and_dependency_omission_or_forgery_fail_closed(): void {
		$root = dirname( __DIR__, 2 );
		$map = Wstm167ScoreMarkerTransition::load();
		foreach ( $map['files'] as $path => $binding ) {
			$current = file_get_contents( $root . '/' . $path );
			$current = Wstm167SelectedListenerTransition::restore( $path, $current );
			$before = Wstm167ScoreMarkerTransition::restore( $path, $current );
			foreach ( [ $before, $current . "\nforeign", str_replace( '<?php', "<?php\n// foreign", $current ) ] as $foreign ) {
				try {
					Wstm167ScoreMarkerTransition::restore( $path, $foreign );
					self::fail( 'Accepted unreviewed source: ' . $path );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'Score marker current source drift: ' . $path, $error->getMessage() );
				}
			}
			foreach ( [ false, '', $current . "\nforeign" ] as $foreign ) {
				try {
					Wstm167SourceTransition::verify_dependencies( static fn( $entry ) => $entry === $path ? $foreign : file_get_contents( $root . '/' . $entry ) );
					self::fail( 'Accepted dependency omission/drift: ' . $path );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'Bounded integration dependency current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_omitted_and_forged_proof_fields_cannot_reseal_history(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/score-marker-transition.json' );
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$forgeries = [ $json . "\n" ];
		foreach ( array_keys( $map ) as $key ) {
			$foreign = $map;
			unset( $foreign[ $key ] );
			$forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR );
		}
		foreach ( [ 'current_raw_sha256', 'baseline_raw_sha256', 'current_blob', 'baseline_blob', 'hunks', 'start', 'before', 'after', 'includes/class-seo.php' ] as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try {
				Wstm167ScoreMarkerTransition::load( $foreign );
				self::fail( 'Accepted forged or omitted proof.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Score marker transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
