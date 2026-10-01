<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-legacy-retention.php';
require_once __DIR__ . '/fixtures/legacy-runtime-transition.php';

final class UntrustedLegacyRetentionTest extends TestCase {
	private function source( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_red_mutation_restores_parent_trap_before_failed_child_without_changing_admission(): void {
		foreach ( array( 'scripts/release-qa.sh', 'scripts/e2e-test.sh' ) as $path ) {
			$source = $this->source( $path );
			foreach ( array( "\n", "\r\n" ) as $ending ) {
				$original = str_replace( "\n", $ending, str_replace( "\r\n", "\n", $source ) );
				$mutated = Wstm108_LegacyRetentionFixture::transform( $path, $original );
				self::assertSame( 1, substr_count( $mutated, 'if false; then' ) );
				self::assertSame( 0, substr_count( $mutated, 'if ! wstm116_require_no_retention; then' ) );
				if ( 'scripts/release-qa.sh' === $path ) {
					$legacy = 'trap cleanup_release EXIT' . $ending . 'bash scripts/e2e-test.sh all';
					self::assertStringContainsString( $legacy, $mutated );
					self::assertSame( 1, substr_count( $mutated, 'trap cleanup_release EXIT' ) );
					$restored = str_replace(
						$legacy,
						'runtime_status=0' . $ending . 'bash scripts/e2e-test.sh all || runtime_status=$?' . $ending
						. 'if [[ "$runtime_status" != 0 ]]; then exit "$runtime_status"; fi' . $ending . 'trap cleanup_release EXIT',
						$mutated
					);
				} else {
					self::assertSame( 2, substr_count( $mutated, 'run_untrusted_admission || return $?' ) );
					$restored = $mutated;
				}
				self::assertSame( $original, str_replace( 'if false; then', 'if ! wstm116_require_no_retention; then', $restored ) );
			}
		}
	}

	public function test_unknown_duplicate_or_already_mutated_wrapper_refuses(): void {
		$source = $this->source( 'scripts/release-qa.sh' );
		foreach ( array(
			array( 'scripts/other.sh', $source ),
			array( 'scripts/release-qa.sh', $source . "\nif ! wstm116_require_no_retention; then\n" ),
			array( 'scripts/release-qa.sh', Wstm108_LegacyRetentionFixture::transform( 'scripts/release-qa.sh', $source ) ),
			array( 'scripts/release-qa.sh', str_replace( "runtime_status=0\n", "runtime_status=1\n", $source ) ),
			array( 'scripts/release-qa.sh', str_replace( "\n", "\r\n", $source ) . "\n" ),
		) as $case ) {
			try {
				Wstm108_LegacyRetentionFixture::transform( $case[0], $case[1] );
				self::fail( 'An unreviewed legacy mutation was accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'legacy retention fixture', strtolower( $error->getMessage() ) );
			}
		}
	}

	public function test_outer_reversal_restores_original_native_fixture_proof_and_frozen_controls(): void {
		$map = Wstm166LegacyRuntimeTransition::load();
		self::assertCount( 3, $map['files'] );
		foreach ( $map['files'] as $path => $binding ) {
			$source = $this->source( $path );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm166LegacyRuntimeTransition::restore( $path, $source ) ) );
			try {
				Wstm166LegacyRuntimeTransition::restore( $path, $source . "\nforeign" );
				self::fail( 'Foreign source accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Legacy runtime current source drift.', $error->getMessage() ); }
			$foreign = $binding;
			$foreign['hunks'][0]['after'][] = 'foreign';
			$reverse = new ReflectionMethod( Wstm166LegacyRuntimeTransition::class, 'reverse' );
			$reverse->setAccessible( true );
			try {
				$reverse->invoke( null, $source, $foreign );
				self::fail( 'Foreign hunk accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Legacy runtime reverse hunk mismatch.', $error->getMessage() ); }
		}
		foreach ( $map['frozen_files'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->source( $path ) ) );
		}
	}

	public function test_forged_reversal_cannot_replace_the_unchanged_predecessor_seal(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/legacy-runtime-transition.json' );
		foreach ( array( 'schema', 'predecessor_seal', 'files', 'frozen_files', 'hunks', 'current_raw_sha256', 'baseline_raw_sha256', 'start' ) as $key ) {
			try {
				Wstm166LegacyRuntimeTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json ) );
				self::fail( 'Foreign transition accepted.' );
			} catch ( RuntimeException $error ) { self::assertSame( 'Legacy runtime transition seal mismatch.', $error->getMessage() ); }
		}
	}
}
