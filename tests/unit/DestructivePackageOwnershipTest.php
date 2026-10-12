<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DestructivePackageOwnershipTest extends TestCase {
	public static function callers(): array {
		return array(
			array( 'release-package-qa.yml', 'Run Release Package QA', 'wstm-package-qa' ),
			array( 'release.yml', 'Build once and test the package with pinned and current Plugin Check', 'wstm-release-qa' ),
		);
	}

	/** @dataProvider callers */
	public function test_actual_workflow_callers_supply_job_run_attempt_owned_projects( string $file, string $step, string $prefix ): void {
		$text = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/' . $file ) );
		$parts = explode( '      - name: ' . $step . "\n", $text );
		self::assertCount( 2, $parts );
		$body = explode( "\n      - name:", $parts[1] )[0];
		self::assertStringContainsString( "        env:\n", $body );
		$assignment = '          COMPOSE_PROJECT_NAME: ' . $prefix . '-${{ github.run_id }}-${{ github.run_attempt }}';
		self::assertStringContainsString( $assignment, $body );
		$command = 'release-package-qa.yml' === $file ? 'run: bash scripts/qa-run.sh package' : 'run: bash scripts/release-qa.sh';
		self::assertStringContainsString( $command, $body );
		self::assertLessThan( strpos( $body, $command ), strpos( $body, $assignment ) );
	}

	public function test_outer_workflow_teardown_cannot_bypass_retention(): void {
		$root = dirname( __DIR__, 2 ) . '/.github/workflows/';
		$source = str_replace( "\r\n", "\n", file_get_contents( $root . 'e2e-qa.yml' ) );
		$compatibility = str_replace( "\r\n", "\n", file_get_contents( $root . 'compatibility-qa.yml' ) );
		self::assertSame( 2, substr_count( $source, 'bash scripts/destructive-retention.sh cleanup' ) );
		self::assertSame( 1, substr_count( $compatibility, 'bash scripts/destructive-retention.sh cleanup' ) );
		foreach ( array( 'ability-contract-qa' => $source, 'full-mcp-e2e-qa' => $source, 'compatibility-qa' => $compatibility ) as $job => $workflow ) {
			self::assertSame( 1, preg_match( '/^  ' . preg_quote( $job, '/' ) . ':\n(.*?)(?=^  [a-z][a-z0-9-]*:|\z)/ms', $workflow, $section ) );
			self::assertSame( 1, substr_count( $section[1], 'bash scripts/destructive-retention.sh cleanup' ) );
			self::assertMatchesRegularExpression( '/if: (?:\$\{\{ )?always\(\)(?: \}\})?\n        run: bash scripts\/destructive-retention\.sh cleanup/', $section[1] );
		}
		self::assertSame( 2, substr_count( $source, "bash scripts/destructive-retention.sh check\n          bash scripts/qa-compose.sh down -v --remove-orphans" ) );
		self::assertSame( 2, substr_count( $source, 'bash scripts/qa-compose.sh down' ) );
		self::assertStringNotContainsString( 'docker compose down', $source );
		self::assertStringNotContainsString( 'docker compose down', $compatibility );
		self::assertStringNotContainsString( 'candidate-floor:', $compatibility );
		foreach ( array( 'current-plugin-check' => 'mcp-current-checker', 'open-update-pr' => 'mcp-candidate' ) as $job => $prefix ) {
			self::assertSame( 1, preg_match( '/^  ' . preg_quote( $job, '/' ) . ':\n(.*?)(?=^  [a-z][a-z0-9-]*:|\z)/ms', $compatibility, $section ) );
			self::assertStringContainsString( 'COMPOSE_PROJECT_NAME: ' . $prefix . '-${{ github.run_id }}-${{ github.run_attempt }}', $section[1] );
			self::assertStringContainsString( 'run: bash scripts/release-qa.sh', $section[1] );
		}
		$package = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/scripts/release-qa.sh' ) );
		self::assertSame( 1, preg_match( '/^cleanup_release\(\) \{\n([\s\S]*?)^\}/m', $package, $cleanup ) );
		self::assertStringContainsString( 'if ! wstm116_require_no_retention; then', $cleanup[1] );
		self::assertStringContainsString( 'if [[ "$status" != 0 ]]; then exit "$status"; fi', $cleanup[1] );
		self::assertStringContainsString( 'compose down -v --remove-orphans || cleanup=$?', $cleanup[1] );
		self::assertStringContainsString( 'exit "$cleanup"', $cleanup[1] );
		self::assertLessThan( strpos( $cleanup[1], 'compose down' ), strpos( $cleanup[1], 'wstm116_require_no_retention' ) );
		self::assertStringContainsString( 'trap cleanup_release EXIT', $package );
	}
}
