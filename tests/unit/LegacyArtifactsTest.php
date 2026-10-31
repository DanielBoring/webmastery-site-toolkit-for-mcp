<?php

declare(strict_types=1);

namespace WstmLegacyArtifacts;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wstm_E2E_Artifacts;

require_once dirname( __DIR__ ) . '/e2e/legacy-artifacts.php';
require_once __DIR__ . '/fixtures/legacy-artifact-calibration.php';

const WP_PLUGIN_DIR = '/unused-readonly-plugin-root';

function wp_mkdir_p( string $path ): bool {
	return ! LegacyArtifactsTest::$mkdir_failure && ( is_dir( $path ) || mkdir( $path, 0700, true ) );
}

function wp_json_encode( $value, int $flags = 0 ) {
	return LegacyArtifactsTest::$encode_failure ? false : json_encode( $value, $flags );
}

function file_put_contents( string $path, string $bytes ) {
	if ( LegacyArtifactsTest::$write_failure ) { return false; }
	if ( LegacyArtifactsTest::$short_write ) { return strlen( $bytes ) - 1; }
	return \file_put_contents( $path, $bytes );
}

final class LegacyArtifactsTest extends TestCase {
	public static bool $mkdir_failure = false;
	public static bool $encode_failure = false;
	public static bool $write_failure = false;
	public static bool $short_write = false;
	private array $environment = array();
	private ?string $root = null;

	private static function source( string $runner ): string {
		return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/' . $runner . '.php' ) );
	}

	public static function setUpBeforeClass(): void {
		foreach ( array( 'ability-runner' => 'e2e_write_summary', 'mcp-crud-runner' => 'webmastery_mcp_e2e_env' ) as $runner => $function ) {
			self::assertSame( 1, preg_match( '/^function ' . $function . '\b.*?^}/ms', self::source( $runner ), $match ) );
			eval( 'namespace WstmLegacyArtifacts; use \Wstm_E2E_Artifacts; use \RuntimeException; ' . $match[0] );
		}
	}

	protected function setUp(): void {
		foreach ( array( 'WSTM_E2E_ARTIFACTS_DIR', 'WSTM105_ARTIFACT', 'WSTM106_ARTIFACT', 'WSTM113_ARTIFACT', 'MCP_CRUD_ARTIFACT' ) as $name ) {
			$this->environment[ $name ] = getenv( $name ); putenv( $name );
		}
		self::$mkdir_failure = self::$encode_failure = self::$write_failure = self::$short_write = false;
	}

	protected function tearDown(): void {
		foreach ( $this->environment as $name => $value ) { putenv( false === $value ? $name : $name . '=' . $value ); }
		if ( null !== $this->root ) {
			foreach ( array( 'e2e-summary.json', 'issue125-permission-summary.json' ) as $name ) {
				if ( is_file( $this->root . '/' . $name ) ) { unlink( $this->root . '/' . $name ); }
			}
			if ( is_dir( $this->root ) ) { rmdir( $this->root ); }
		}
	}

	public static function runners(): array {
		$rows = array(
			'ability-runner' => 'directory', 'comments-runner' => 'path', 'error-contract-runner' => 'path',
			'mcp-crud-runner' => 'path', 'media-download-runner' => 'directory', 'metadata-batch-runner' => 'directory',
			'parent-assignment-runner' => 'path', 'patch-html-runner' => 'directory', 'post-meta-authorization-runner' => 'path',
			'scheduling-runner' => 'path', 'seo-metadata-runner' => 'directory', 'site-kit-mcp-runner' => 'path',
			'site-kit-permissions-runner' => 'path', 'taxonomy-write-runner' => 'path', 'trash-safety-runner' => 'directory',
		);
		$result = array();
		foreach ( $rows as $runner => $kind ) { $result[ $runner ] = array( $runner, $kind ); }
		return $result;
	}

	/** @dataProvider runners */
	public function testEveryActualLegacySelectionRedirectsWithoutChangingItsFallback( string $runner, string $kind ): void {
		$source = self::source( $runner );
		self::assertSame( 1, preg_match( '/Wstm_E2E_Artifacts::(directory|path)\( (.*?e2e-artifacts.*?) \)/', $source, $match ) );
		self::assertSame( $kind, $match[1] );
		$runner_directory = dirname( __DIR__ ) . '/e2e';
		$root = dirname( $runner_directory, 2 ); $boundary = 'direct'; $boundary_filter = 'all';
		$expression = str_replace( '__DIR__', '$runner_directory', $match[2] );
		$fallback = eval( 'namespace WstmLegacyArtifacts; return ' . $expression . ';' );
		self::assertSame( $fallback, Wstm_E2E_Artifacts::$kind( $fallback ) );
		putenv( 'WSTM_E2E_ARTIFACTS_DIR=' );
		self::assertSame( $fallback, Wstm_E2E_Artifacts::$kind( $fallback ) );
		putenv( 'WSTM_E2E_ARTIFACTS_DIR=/qa-artifacts' );
		$expected = 'directory' === $kind ? '/qa-artifacts' : '/qa-artifacts/' . basename( $fallback );
		self::assertSame( $expected, Wstm_E2E_Artifacts::$kind( $fallback ) );
		putenv( 'WSTM_E2E_ARTIFACTS_DIR=/qa-artifacts/' );
		self::assertSame( 'directory' === $kind ? '/qa-artifacts/' : $expected, Wstm_E2E_Artifacts::$kind( $fallback ) );
	}

	public function testExistingPerRunnerOverridesKeepPriorityInActualExpressions(): void {
		$overrides = array( 'comments-runner' => 'WSTM105_ARTIFACT', 'parent-assignment-runner' => 'WSTM106_ARTIFACT',
			'scheduling-runner' => 'WSTM113_ARTIFACT', 'mcp-crud-runner' => 'MCP_CRUD_ARTIFACT' );
		putenv( 'WSTM_E2E_ARTIFACTS_DIR=/qa-artifacts' );
		$runner_directory = dirname( __DIR__ ) . '/e2e'; $boundary_filter = 'all';
		foreach ( $overrides as $runner => $name ) {
			self::assertSame( 1, preg_match( '/^\$(?:artifact|artifact_path) = (.*);$/m', self::source( $runner ), $match ) );
			$expression = str_replace( '__DIR__', '$runner_directory', $match[1] );
			putenv( $name . '=/specific-private/report.json' );
			self::assertSame( '/specific-private/report.json', eval( 'namespace WstmLegacyArtifacts; use \Wstm_E2E_Artifacts; return ' . $expression . ';' ) );
			putenv( $name );
			self::assertStringStartsWith( '/qa-artifacts/', eval( 'namespace WstmLegacyArtifacts; use \Wstm_E2E_Artifacts; return ' . $expression . ';' ) );
		}
	}

	private function destination(): void {
		$this->root = sys_get_temp_dir() . '/wstm-legacy-artifacts-' . bin2hex( random_bytes( 8 ) );
		putenv( 'WSTM_E2E_ARTIFACTS_DIR=' . $this->root );
	}

	private function permissions_summary(): void {
		$source = self::source( 'site-kit-permissions-runner' );
		$start = strpos( $source, '$wstm125_summary_path =' ); $end = strpos( $source, 'if ( $wstm125_failed )', $start );
		self::assertIsInt( $start ); self::assertIsInt( $end );
		$wstm125_results = array( array( 'label' => 'retained', 'passed' => true, 'calls' => array() ) ); $wstm125_failed = 0;
		eval( 'namespace WstmLegacyArtifacts; use \Wstm_E2E_Artifacts; use \RuntimeException; ' . substr( $source, $start, $end - $start ) );
	}

	public function testActualAbilityAndSiteKitWritersCreatePrivateOutputsWithUnchangedJson(): void {
		$this->destination(); $summary = array( 'passed' => 1, 'failed' => 0, 'cases' => array() );
		e2e_write_summary( $summary );
		self::assertSame( json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n", file_get_contents( $this->root . '/e2e-summary.json' ) );
		$this->permissions_summary();
		self::assertSame( array( 'passed' => 1, 'failed' => 0, 'cases' => array( array( 'label' => 'retained', 'passed' => true, 'calls' => array() ) ) ),
			json_decode( file_get_contents( $this->root . '/issue125-permission-summary.json' ), true ) );
	}

	public static function failures(): array {
		$rows = array();
		foreach ( array( 'ability', 'permissions' ) as $writer ) {
			foreach ( array( 'mkdir_failure', 'encode_failure', 'write_failure', 'short_write' ) as $failure ) {
				$rows[ $writer . ':' . $failure ] = array( $writer, $failure );
			}
		}
		return $rows;
	}

	/** @dataProvider failures */
	public function testActualWritersRefuseDirectoryEncodingFailedAndShortWrites( string $writer, string $failure ): void {
		$this->destination(); self::$$failure = true;
		$this->expectException( RuntimeException::class );
		if ( 'ability' === $writer ) { e2e_write_summary( array( 'passed' => 1 ) ); }
		else { $this->permissions_summary(); }
	}

	public function testCalibrationAllowsOnlyExactOutputDeltaAndLeavesUnrelatedBytesVisible(): void {
		foreach ( array( 'taxonomy', 'scheduling' ) as $runner ) {
			$ledger = json_decode( file_get_contents( __DIR__ . '/fixtures/' . $runner . '-calibration-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
			$historical = $ledger[ 'scheduling' === $runner ? 'current_source' : 'original_source' ];
			$expected = \wstm_test_legacy_artifact_output_source( $runner, $historical );
			self::assertSame( $expected . "\nUNRELATED CHANGE\n", \wstm_test_legacy_artifact_output_source( $runner, $historical . "\nUNRELATED CHANGE\n" ) );
			$site = "require_once __DIR__ . '/error-contract-assertions.php';";
			foreach ( array( str_replace( $site, '', $historical ), $historical . "\n" . $site ) as $changed ) {
				try { \wstm_test_legacy_artifact_output_source( $runner, $changed ); self::fail( 'Missing/duplicate selected artifact site was accepted.' ); }
				catch ( RuntimeException $error ) { self::assertStringContainsString( 'exactly one approved output site', $error->getMessage() ); }
			}
		}
	}

	public function testFeaturedImageFailuresRetainCapturedResponsesStateAndCountersThroughTheActualWriter(): void {
		self::assertSame( 1, preg_match( '/^\t\$record = .*?^\t};$/ms', self::source( 'featured-image-runner' ), $match ) );
		$summary = array( 'passed' => 0, 'failed' => 0, 'cases' => array() );
		eval( 'namespace WstmLegacyArtifacts; use \\Throwable; ' . $match[0] );
		$captured = array();
		ob_start();
		try {
			foreach ( array( 'full', 'compact', 'invalid', 'denied' ) as $kind ) {
				$observed = array( 'before_image' => 11, 'result' => array( 'unexpected' => $kind ),
					'persisted_image' => 22, 'capability_calls' => 0, 'metadata_reads' => 0, 'queries' => 0 );
				$captured[] = $observed;
				$record( $kind, static function ( array &$evidence ) use ( $observed ): void {
					$evidence = $observed;
					// Model the real cases: preserve observations, then a comparison fails.
					throw new RuntimeException( 'Observed response/state mismatch.' );
				} );
				unset( $observed ); // Later fixture cleanup cannot recreate this evidence.
			}
			$record( 'observation failure', static function ( array &$evidence ): void {
				$evidence['result'] = array( 'success' => true, 'data' => array( 'featured_image_id' => 22 ) );
				throw new RuntimeException( 'Persisted-state observer failed.' );
			} );
			$record( 'success control', static function ( array &$evidence ): void { $evidence['persisted_image'] = 0; } );
		} finally {
			ob_end_clean();
		}
		self::assertSame( 5, $summary['failed'] );
		self::assertSame( 1, $summary['passed'] );
		foreach ( $captured as $index => $observed ) {
			self::assertFalse( $summary['cases'][ $index ]['passed'] );
			self::assertSame( $observed, $summary['cases'][ $index ]['evidence'] );
			self::assertSame( 'Observed response/state mismatch.', $summary['cases'][ $index ]['error'] );
		}
		self::assertSame( array( 'result' => array( 'success' => true, 'data' => array( 'featured_image_id' => 22 ) ) ), $summary['cases'][4]['evidence'] );
		self::assertSame( 'Persisted-state observer failed.', $summary['cases'][4]['error'] );
		$this->destination();
		e2e_write_summary( $summary );
		self::assertSame( $summary, json_decode( file_get_contents( $this->root . '/e2e-summary.json' ), true ) );
	}
}
