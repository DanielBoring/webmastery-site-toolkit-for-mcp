<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/latest-main-transition.php';

final class SignedPhpTransitionTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_exact_public_predecessor_restoration_and_current_source_binding(): void {
		$map = Wstm167SignedPhpTransition::load();
		self::assertCount( 530, $map['baseline_inventory'] );
		self::assertSame( Wstm167OriginFailureTransition::SEAL, $map['predecessor_seal'] );
		Wstm167LatestMainTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['baseline_inventory'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', Wstm167SignedPhpTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['frozen_json'] as $path => $hash ) {
			self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path );
		}
	}

	public function test_foreign_old_truncated_and_missing_current_sources_refuse(): void {
		$map = Wstm167SignedPhpTransition::load();
		foreach ( $map['current_hashes'] as $path => $hash ) {
			$current = $this->read( $path );
			foreach ( array( false, '', substr( $current, 0, -1 ), $current . "\nforeign" ) as $foreign ) {
				try {
					Wstm167SignedPhpTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Missing or foreign signed-provider source accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
			if ( isset( $map['files'][ $path ] ) ) {
				try {
					Wstm167SignedPhpTransition::restore( $path, Wstm167SignedPhpTransition::restore( $path, $current ) );
					self::fail( 'Prior public bytes accepted as current source.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_predecessor_checks_do_not_bypass_added_source_binding(): void {
		foreach ( array( 'scripts/host-php-apt.py', 'tests/unit/fixtures/signed-php-producers/sessionclean' ) as $path ) {
			$current = $this->read( $path );
			foreach ( array( false, '', substr( $current, 0, -1 ), $current . "\nforeign" ) as $foreign ) {
				try {
					Wstm167OriginFailureTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Predecessor checks bypassed an omitted or foreign added source.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function test_raw_seal_drift_is_not_hidden_by_prior_map_load(): void {
		$json = $this->read( 'tests/unit/fixtures/signed-php-transition.json' );
		Wstm167SignedPhpTransition::load();
		foreach ( array( '', substr( $json, 0, -1 ), $json . "\n" ) as $foreign ) {
			try {
				Wstm167SignedPhpTransition::load( $foreign );
				self::fail( 'Unsealed current transition accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Signed PHP transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
