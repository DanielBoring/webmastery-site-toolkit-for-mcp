<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-export.php';

final class UntrustedExportTest extends TestCase {
	public static function list_shapes(): array {
		return array(
			'empty' => array( array(), true ),
			'contiguous' => array( array( null, '', array(), (object) array( 'field' => 'value' ) ), true ),
			'numeric key normalized by PHP' => array( array( '0' => 'value' ), true ),
			'sparse' => array( array( 0 => 'a', 2 => 'b' ), false ),
			'nonzero start' => array( array( 1 => 'a' ), false ),
			'reordered' => array( array( 1 => 'a', 0 => 'b' ), false ),
			'negative' => array( array( -1 => 'a' ), false ),
			'string' => array( array( 'name' => 'a' ), false ),
			'mixed' => array( array( 0 => 'a', 'name' => 'b' ), false ),
			'noncanonical numeric string' => array( array( '00' => 'a' ), false ),
		);
	}

	/** @dataProvider list_shapes */
	public function test_php80_list_predicate_preserves_keys_values_and_types( array $value, bool $expected ): void {
		$before = serialize( $value );
		self::assertSame( $expected, Wstm108_Export::is_list( $value ) );
		self::assertSame( $before, serialize( $value ) );
	}

	public static function nonarrays(): array {
		return array( array( null ), array( false ), array( 0 ), array( '' ), array( (object) array() ) );
	}

	/** @dataProvider nonarrays */
	public function test_list_predicate_does_not_coerce_nonarray_types( $value ): void {
		$this->expectException( TypeError::class );
		Wstm108_Export::is_list( $value );
	}

	private static function binding(): array {
		return array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'owned-export', 'source_sha' => str_repeat( 'b', 40 ),
			'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
	}

	private static function proof(): array {
		return array( 'version' => 1, 'binding' => self::binding(), 'run' => array( 'id' => '123', 'attempt' => '1', 'job' => 'ability-contract-qa' ),
			'status' => 'failed', 'context_sha256' => str_repeat( 'd', 64 ), 'validated_actions' => array( 'acquire', 'original' ),
			'release' => array( 'authorization' => 'not_authorized', 'commit_outcome' => 'not_observed', 'qa_outcome' => 'not_asserted' ) );
	}

	public function test_structurally_safe_failure_is_not_semantic_success(): void {
		$proof = self::proof();
		Wstm108_Export::proof( $proof );
		$failure = array( 'version' => 1, 'binding' => self::binding(), 'failures' => array(
			array( 'action' => 'runner', 'reason' => 'frame-refused', 'child_exit' => 0, 'capture_complete' => true ),
		) );
		Wstm108_Export::failures( $failure, self::binding() );
		self::assertSame( 'failed', $proof['status'] );
		self::assertSame( 'not_observed', $proof['release']['commit_outcome'] );
		self::assertSame( 'not_asserted', $proof['release']['qa_outcome'] );
		$this->expectException( RuntimeException::class );
		$proof['status'] = 'passed';
		Wstm108_Export::proof( $proof );
	}

	public static function rejected_fields(): array {
		return array_map( static fn( $key ) => array( $key ), array( 'body', 'headers', 'authority', 'private_path', 'credentials', 'message', 'extra' ) );
	}

	/** @dataProvider rejected_fields */
	public function test_unvalidated_bytes_are_not_projected_or_redacted_into_public_files( string $key ): void {
		$proof = self::proof();
		$proof[ $key ] = "foreign-secret-marker\xff\0";
		$before = serialize( $proof );
		try {
			Wstm108_Export::proof( $proof );
			self::fail( 'Unknown public fields must be rejected, not redacted or passed through.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $before, serialize( $proof ) );
			self::assertStringNotContainsString( 'foreign-secret-marker', $error->getMessage() );
		}
	}

	private static function matches_selector( string $pattern, string $path ): bool {
		if ( '/' === substr( $pattern, -1 ) ) { return 0 === strpos( $path, $pattern ); }
		$quoted = preg_quote( $pattern, '~' );
		$quoted = str_replace( array( '\\*\\*', '\\*', '\\?' ), array( "\x01", '[^/]*', '[^/]' ), $quoted );
		return 1 === preg_match( '~^' . str_replace( "\x01", '.*', $quoted ) . '$~D', $path );
	}

	private static function selected( array $selectors, string $path ): bool {
		$included = false;
		foreach ( $selectors as $selector ) {
			if ( '' === $selector ) { continue; }
			$negative = '!' === $selector[0];
			if ( self::matches_selector( $negative ? substr( $selector, 1 ) : $selector, $path ) ) { $included = ! $negative; }
		}
		return $included;
	}

	public function test_actual_workflow_artifact_selection_excludes_flat_and_nested_foreign_evidence(): void {
		$root = dirname( __DIR__, 2 );
		$gate_count = 0;
		$broad_count = 0;
		foreach ( array( 'compatibility-qa.yml', 'e2e-qa.yml', 'release-package-qa.yml', 'release.yml' ) as $name ) {
			$source = str_replace( "\r\n", "\n", file_get_contents( $root . '/.github/workflows/' . $name ) );
			self::assertStringNotContainsString( '/wstm108-authority-', $source, 'Private receipts must have no alternate artifact selector.' );
			self::assertStringNotContainsString( 'path: e2e-artifacts/untrusted-', $source );
			preg_match_all( '/^      - name: [^\n]+\n(?:(?!^      - name:|^  [a-z]).|\n)*/m', $source, $steps );
			foreach ( $steps[0] as $step ) {
				if ( false === strpos( $step, 'uses: actions/upload-artifact@' ) ) { continue; }
				if ( preg_match( '/^          path: \|\n((?:            [^\n]*\n)+)/m', $step, $paths ) ) {
					$selectors = array_map( 'trim', explode( "\n", trim( $paths[1] ) ) );
				} elseif ( preg_match( '/^          path: ([^\n]+)$/m', $step, $paths ) ) {
					$selectors = array( trim( $paths[1] ) );
				} else { self::fail( 'Every artifact selection must be inspected.' ); }
				if ( in_array( 'e2e-artifacts/', $selectors, true ) ) {
					++$broad_count;
					self::assertContains( '!e2e-artifacts/untrusted-*', $selectors );
					self::assertContains( '!e2e-artifacts/untrusted-*/**', $selectors );
					self::assertTrue( self::selected( $selectors, 'e2e-artifacts/database-table-privacy-native.json' ), 'Unrelated evidence must remain selected.' );
					$without_flat = array_values( array_diff( $selectors, array( '!e2e-artifacts/untrusted-*' ) ) );
					$without_nested = array_values( array_diff( $selectors, array( '!e2e-artifacts/untrusted-*/**' ) ) );
					self::assertTrue( self::selected( $without_flat, 'e2e-artifacts/untrusted-collision.json' ) );
					self::assertTrue( self::selected( $without_nested, 'e2e-artifacts/untrusted-collision/authorization.receipt.json' ) );
				}
				foreach ( array( 'e2e-artifacts/untrusted-collision.json', 'e2e-artifacts/untrusted-collision/authorization.receipt.json' ) as $foreign ) {
					self::assertFalse( self::selected( $selectors, $foreign ), 'A foreign marker at this retained path must never enter this actual upload selection: ' . $foreign );
				}
				if ( false !== strpos( $step, 'id: untrusted-export-upload' ) ) {
					++$gate_count;
					self::assertSame( array(
						'${{ steps.qa.outputs.untrusted_export_root }}/untrusted-proof.json',
						'${{ steps.qa.outputs.untrusted_export_root }}/untrusted-failure-witnesses.json',
						'${{ steps.qa.outputs.untrusted_export_root }}/untrusted-export-manifest.json',
					), $selectors );
					foreach ( array( "steps.qa.outputs.untrusted_export_ready == 'true'", "steps.qa.outputs.untrusted_export_status == 'published'",
						"steps.untrusted-export-check.outcome == 'success'" ) as $predicate ) { self::assertStringContainsString( $predicate, $step ); }
				}
			}
		}
		self::assertSame( 7, $gate_count );
		self::assertGreaterThanOrEqual( 2, $broad_count );
	}

	public function test_promotion_requires_qa_publication_verification_upload_and_precommit_proof(): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/compatibility-qa.yml' ) );
		$position = strpos( $source, '      - name: Push tested commit and request real PR-event checks' );
		self::assertNotFalse( $position );
		$step = substr( $source, $position, strpos( $source, "\n        env:", $position ) - $position );
		foreach ( array( "steps.qa.outcome == 'success'", "steps.qa.outputs.untrusted_export_ready == 'true'",
			"steps.qa.outputs.untrusted_export_status == 'published'", "steps.qa.outputs.untrusted_proof_status == 'passed'",
			"steps.untrusted-export-check.outcome == 'success'", "steps.untrusted-export-upload.outcome == 'success'",
			"steps.untrusted-required-evidence.outcome == 'success'" ) as $predicate ) { self::assertStringContainsString( $predicate, $step ); }
	}

	private static function release_workflow(): string {
		return str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/release.yml' ) );
	}

	private static function release_step( string $source, string $name ): string {
		$pattern = '/^      - name: ' . preg_quote( $name, '/' ) . '\n(?:(?!^      - name:|^  [a-z]).|\n)*/m';
		self::assertSame( 1, preg_match_all( $pattern, $source, $matches ), 'The release step must be unique: ' . $name );
		return $matches[0][0];
	}

	private static function release_condition( string $step ): string {
		self::assertSame( 1, preg_match_all( '/^        if: (.+)$/m', $step, $matches ), 'Exactly one explicit condition is required.' );
		return $matches[1][0];
	}

	private static function release_script( string $step ): string {
		$prefix = "        run: |\n";
		$start = strpos( $step, $prefix );
		self::assertNotFalse( $start );
		return rtrim( substr( $step, $start + strlen( $prefix ) ), "\n" );
	}

	private static function release_gate_fields(): array {
		return array(
			'QA_OUTCOME' => array( '${{ steps.qa.outcome }}', 'success' ),
			'EXPORT_READY' => array( '${{ steps.qa.outputs.untrusted_export_ready }}', 'true' ),
			'EXPORT_STATUS' => array( '${{ steps.qa.outputs.untrusted_export_status }}', 'published' ),
			'PROOF_STATUS' => array( '${{ steps.qa.outputs.untrusted_proof_status }}', 'passed' ),
			'EXPORT_CHECK' => array( '${{ steps.untrusted-export-check.outcome }}', 'success' ),
			'EXPORT_UPLOAD' => array( '${{ steps.untrusted-export-upload.outcome }}', 'success' ),
		);
	}

	private static function release_publication_steps(): array {
		return array(
			'Seal original ZIP, scoped notes, listing assets and source manifest',
			'Upload immutable run-scoped validated bundle',
			'Ready for GitHub production approval',
		);
	}

	private static function assert_release_gates( string $source ): void {
		$verify = self::release_step( $source, 'Verify independently published untrusted export' );
		$upload = self::release_step( $source, 'Upload only the closed safe untrusted export' );
		$required = self::release_step( $source, 'Require QA and safe evidence success' );
		self::assertSame( "\${{ always() && steps.qa.outputs.untrusted_export_ready == 'true' && steps.qa.outputs.untrusted_export_status == 'published' }}",
			self::release_condition( $verify ) );
		self::assertSame( "\${{ always() && steps.qa.outputs.untrusted_export_ready == 'true' && steps.qa.outputs.untrusted_export_status == 'published' && steps.untrusted-export-check.outcome == 'success' }}",
			self::release_condition( $upload ) );
		self::assertSame( 'always()', self::release_condition( $required ) );
		$commands = array( '          set -euo pipefail' );
		foreach ( self::release_gate_fields() as $name => $field ) {
			self::assertStringContainsString( '          ' . $name . ': ' . $field[0] . "\n", $required );
			$commands[] = '          test "$' . $name . '" = ' . $field[1];
		}
		self::assertSame( implode( "\n", $commands ), self::release_script( $required ), 'No missing checks or success-shaped shell fallback.' );
		foreach ( self::release_publication_steps() as $name ) {
			self::assertSame( "\${{ success() && steps.untrusted-required-evidence.outcome == 'success' }}",
				self::release_condition( self::release_step( $source, $name ) ) );
		}
	}

	public function test_tag_release_binds_original_custody_and_metadata_before_safe_upload(): void {
		$source = self::release_workflow();
		self::assert_release_gates( $source );
		$qa = self::release_step( $source, 'Build once and test the package with pinned and current Plugin Check' );
		self::assertStringContainsString( "        id: qa\n", $qa );
		self::assertStringContainsString( '          WSTM108_HOST_AUTHORITY_ROOT: ${{ runner.temp }}' . "\n", $qa );
		$verify = self::release_step( $source, 'Verify independently published untrusted export' );
		foreach ( array(
			'WSTM108_EXPORT_ROOT' => '${{ steps.qa.outputs.untrusted_export_root }}',
			'WSTM108_EXPORT_SHA256' => '${{ steps.qa.outputs.untrusted_export_manifest_sha256 }}',
			'WSTM108_EXPORT_CUSTODY' => '${{ steps.qa.outputs.untrusted_export_custody }}',
			'WSTM108_EXPECTED_EXPORT_PARENT' => '${{ runner.temp }}',
			'WSTM108_EXPECTED_SOURCE' => '${{ steps.metadata.outputs.source-sha }}',
			'WSTM108_EXPECTED_PROJECT' => 'wstm-release-qa-${{ github.run_id }}-${{ github.run_attempt }}',
			'WSTM108_EXPECTED_RUN' => '${{ github.run_id }}',
			'WSTM108_EXPECTED_ATTEMPT' => '${{ github.run_attempt }}',
			'WSTM108_EXPECTED_JOB' => '${{ github.job }}',
		) as $name => $value ) {
			self::assertStringContainsString( '          ' . $name . ': ' . $value . "\n", $verify );
		}
		self::assertSame( implode( "\n", array(
			'          set -euo pipefail',
			'          test "$(git rev-parse HEAD)" = "$WSTM108_EXPECTED_SOURCE"',
			'          "${WSTM108_HOST_PHP-php}" scripts/untrusted-export.php verify-published',
		) ), self::release_script( $verify ) );
		self::assertStringNotContainsString( '${{', self::release_script( $verify ), 'Workflow outputs must enter shell through env.' );
		$upload = self::release_step( $source, 'Upload only the closed safe untrusted export' );
		self::assertStringContainsString( '        uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1' . "\n", $upload );
		self::assertStringContainsString( '          name: untrusted-release-${{ github.job }}-${{ github.run_id }}-${{ github.run_attempt }}' . "\n", $upload );
		self::assertStringContainsString( "          if-no-files-found: error\n          retention-days: 30\n", $upload );
		self::assertStringNotContainsString( 'build/release-bundle', $upload );
		$previous = -1;
		foreach ( array_merge( array(
			'Validate source metadata',
			'Build once and test the package with pinned and current Plugin Check',
			'Verify independently published untrusted export',
			'Upload only the closed safe untrusted export',
			'Require QA and safe evidence success',
		), self::release_publication_steps() ) as $name ) {
			$position = strpos( $source, self::release_step( $source, $name ) );
			self::assertNotFalse( $position );
			self::assertTrue( $position > $previous, 'Required ordering changed at ' . $name );
			$previous = $position;
		}
	}

	public static function release_gate_outcomes(): array {
		return array(
			'all requirements passed' => array( array(), true, true, true, true ),
			'failed QA with safe failure witness' => array( array( 'QA_OUTCOME' => 'failure', 'PROOF_STATUS' => 'failed' ), true, true, false, false ),
			'passed precommit projection but failed QA' => array( array( 'QA_OUTCOME' => 'failure' ), true, true, false, false ),
			'cancelled QA' => array( array( 'QA_OUTCOME' => 'cancelled' ), true, true, false, false ),
			'missing QA outcome' => array( array( 'QA_OUTCOME' => '' ), true, true, false, false ),
			'unknown QA outcome' => array( array( 'QA_OUTCOME' => 'unknown' ), true, true, false, false ),
			'missing readiness' => array( array( 'EXPORT_READY' => '' ), false, false, false, false ),
			'false readiness' => array( array( 'EXPORT_READY' => 'false' ), false, false, false, false ),
			'noncanonical readiness' => array( array( 'EXPORT_READY' => 'TRUE' ), true, true, false, false ),
			'missing publication status' => array( array( 'EXPORT_STATUS' => '' ), false, false, false, false ),
			'unknown publication status' => array( array( 'EXPORT_STATUS' => 'unknown' ), false, false, false, false ),
			'mixed-case publication status' => array( array( 'EXPORT_STATUS' => 'PuBlIsHeD' ), true, true, false, false ),
			'missing proof status' => array( array( 'PROOF_STATUS' => '' ), true, true, false, false ),
			'failed proof status' => array( array( 'PROOF_STATUS' => 'failed' ), true, true, false, false ),
			'unknown proof status' => array( array( 'PROOF_STATUS' => 'unknown' ), true, true, false, false ),
			'malformed export refused by verifier' => array( array( 'EXPORT_CHECK' => 'failure' ), true, false, false, false ),
			'cancelled verifier' => array( array( 'EXPORT_CHECK' => 'cancelled' ), true, false, false, false ),
			'skipped verifier' => array( array( 'EXPORT_CHECK' => 'skipped' ), true, false, false, false ),
			'missing verifier outcome' => array( array( 'EXPORT_CHECK' => '' ), true, false, false, false ),
			'unknown verifier outcome' => array( array( 'EXPORT_CHECK' => 'unknown' ), true, false, false, false ),
			'mixed-case verifier outcome' => array( array( 'EXPORT_CHECK' => 'SuCcEsS' ), true, true, false, false ),
			'upload failure' => array( array( 'EXPORT_UPLOAD' => 'failure' ), true, true, false, false ),
			'cancelled upload' => array( array( 'EXPORT_UPLOAD' => 'cancelled' ), true, true, false, false ),
			'skipped upload' => array( array( 'EXPORT_UPLOAD' => 'skipped' ), true, true, false, false ),
			'missing upload outcome' => array( array( 'EXPORT_UPLOAD' => '' ), true, true, false, false ),
			'unknown upload outcome' => array( array( 'EXPORT_UPLOAD' => 'unknown' ), true, true, false, false ),
			'workflow cancelled after evidence succeeded' => array( array( 'WORKFLOW_SUCCESS' => false ), true, true, true, false ),
		);
	}

	/** @dataProvider release_gate_outcomes */
	public function test_source_bound_release_gate_models_do_not_promote_failure_or_unknown(
		array $overrides, bool $verify, bool $upload, bool $required, bool $publish
	): void {
		self::assert_release_gates( self::release_workflow() );
		$state = array( 'WORKFLOW_SUCCESS' => true );
		foreach ( self::release_gate_fields() as $name => $field ) { $state[ $name ] = $field[1]; }
		$state = array_replace( $state, $overrides );
		foreach ( array( 'EXPORT_READY', 'EXPORT_STATUS', 'EXPORT_CHECK' ) as $name ) { self::assertIsString( $state[ $name ] ); }
		$may_verify = 0 === strcasecmp( 'true', $state['EXPORT_READY'] ) && 0 === strcasecmp( 'published', $state['EXPORT_STATUS'] );
		$may_upload = $may_verify && 0 === strcasecmp( 'success', $state['EXPORT_CHECK'] );
		$gate_passed = true;
		foreach ( self::release_gate_fields() as $name => $field ) { $gate_passed = $gate_passed && $state[ $name ] === $field[1]; }
		self::assertSame( $verify, $may_verify );
		self::assertSame( $upload, $may_upload );
		self::assertSame( $required, $gate_passed );
		self::assertSame( $publish, $state['WORKFLOW_SUCCESS'] && $gate_passed );
	}

	public function test_only_authorized_tag_release_wiring_changes_original_workflow_bytes(): void {
		$source = self::release_workflow();
		self::assert_release_gates( $source );
		$qa = self::release_step( $source, 'Build once and test the package with pinned and current Plugin Check' );
		$original_qa = str_replace( array(
			"        id: qa\n",
			'          WSTM108_HOST_AUTHORITY_ROOT: ${{ runner.temp }}' . "\n",
		), '', $qa, $count );
		self::assertSame( 2, $count );
		$projected = str_replace( $qa, $original_qa, $source, $count );
		self::assertSame( 1, $count );
		foreach ( array( 'Verify independently published untrusted export', 'Upload only the closed safe untrusted export',
			'Require QA and safe evidence success' ) as $name ) {
			$projected = str_replace( self::release_step( $source, $name ), '', $projected, $count );
			self::assertSame( 1, $count );
		}
		$condition = "        if: \${{ success() && steps.untrusted-required-evidence.outcome == 'success' }}\n";
		foreach ( self::release_publication_steps() as $name ) {
			$step = self::release_step( $source, $name );
			$original = str_replace( $condition, '', $step, $count );
			self::assertSame( 1, $count );
			$projected = str_replace( $step, $original, $projected, $count );
			self::assertSame( 1, $count );
		}
		self::assertSame( '0c19c4426972eaeaa835c35f70e79b8dac9b2fde4333f714618a292b26d970a8', hash( 'sha256', $projected ),
			'Only the two QA additions, three safe-export steps and three explicit gates may change; original ZIP, outputs, setup, ancestry, recovery and publication bytes stay intact.' );
	}
}
