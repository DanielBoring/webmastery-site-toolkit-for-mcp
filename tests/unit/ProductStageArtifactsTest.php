<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/product-stage-artifacts.php';

final class ProductStageArtifactsTest extends TestCase {
	public function test_both_finite_stages_inspect_only_root_and_exact_token_directory(): void {
		$token = str_repeat( 'a', 32 );
		foreach ( array( 'destructive', 'input-schema' ) as $stage ) {
			$paths = array();
			$directory = '/qa-artifacts/' . $stage . '-' . $token;
			Wstm_Product_Stage_Artifacts::directory( $directory, $stage, $token, static function ( string $path ) use ( &$paths ): array {
				$paths[] = $path;
				return array( 'mode' => 0040700, 'uid' => 33, 'gid' => 33 );
			} );
			self::assertSame( array( '/qa-artifacts', $directory ), $paths );
		}
	}

	public static function invalid_paths(): array {
		$token = str_repeat( 'a', 32 );
		$valid = '/qa-artifacts/destructive-' . $token;
		return array(
			array( $valid, 'unknown', $token ),
			array( $valid, 'destructive', strtoupper( $token ) ),
			array( $valid, 'destructive', $token . 'a' ),
			array( $valid, 'destructive', str_repeat( 'b', 32 ) ),
			array( $valid . '/', 'destructive', $token ),
			array( $valid . '/child', 'destructive', $token ),
			array( '/qa-artifacts/../' . substr( $valid, 14 ), 'destructive', $token ),
			array( '/var/www/html/wp-content/plugins/e2e-artifacts/destructive-' . $token, 'destructive', $token ),
			array( '/tmp/destructive-' . $token, 'destructive', $token ),
			array( $valid . "\0", 'destructive', $token ),
		);
	}

	/** @dataProvider invalid_paths */
	public function test_foreign_paths_refuse_without_filesystem_inspection( string $path, string $stage, string $token ): void {
		$calls = 0;
		try {
			Wstm_Product_Stage_Artifacts::directory( $path, $stage, $token, static function () use ( &$calls ) { ++$calls; } );
			self::fail( 'Unsafe path accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 0, $calls );
		}
	}

	public static function invalid_directories(): array {
		return array(
			array( false ), array( array() ),
			array( array( 'mode' => 0120700, 'uid' => 33, 'gid' => 33 ) ),
			array( array( 'mode' => 0100700, 'uid' => 33, 'gid' => 33 ) ),
			array( array( 'mode' => 0040755, 'uid' => 33, 'gid' => 33 ) ),
			array( array( 'mode' => 0041700, 'uid' => 33, 'gid' => 33 ) ),
			array( array( 'mode' => 0040700, 'uid' => 0, 'gid' => 33 ) ),
			array( array( 'mode' => 0040700, 'uid' => 33, 'gid' => 0 ) ),
			array( array( 'mode' => '16832', 'uid' => 33, 'gid' => 33 ) ),
			array( array( 'mode' => 0040700, 'uid' => '33', 'gid' => 33 ) ),
		);
	}

	/** @dataProvider invalid_directories */
	public function test_unsafe_root_or_stage_refuses( $bad ): void {
		foreach ( array( 1, 2 ) as $bad_at ) {
			$calls = 0;
			try {
				Wstm_Product_Stage_Artifacts::directory( '/qa-artifacts/destructive-' . str_repeat( 'a', 32 ), 'destructive', str_repeat( 'a', 32 ), static function () use ( &$calls, $bad_at, $bad ) {
					++$calls;
					return $calls === $bad_at ? $bad : array( 'mode' => 0040700, 'uid' => 33, 'gid' => 33 );
				} );
				self::fail( 'Unsafe directory accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( $bad_at, $calls );
			}
		}
	}
}
