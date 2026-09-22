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
		$text = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/' . $file );
		$parts = explode( '      - name: ' . $step . "\n", $text );
		self::assertCount( 2, $parts );
		$body = explode( "\n      - name:", $parts[1] )[0];
		self::assertStringContainsString( "        env:\n", $body );
		$assignment = '          COMPOSE_PROJECT_NAME: ' . $prefix . '-${{ github.run_id }}-${{ github.run_attempt }}';
		self::assertStringContainsString( $assignment, $body );
		self::assertStringContainsString( 'run: bash scripts/release-qa.sh', $body );
		self::assertLessThan( strpos( $body, 'run: bash scripts/release-qa.sh' ), strpos( $body, $assignment ) );
	}
}
