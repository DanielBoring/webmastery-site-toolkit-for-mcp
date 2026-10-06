<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-host-topology.php';
require_once __DIR__ . '/fixtures/ci-diagnostic-transition.php';

final class CiAdmissionDiagnosticTest extends TestCase {
	private const UNAVAILABLE = "Untrusted admission diagnostic unavailable; QA outcome remains authoritative.\n";
	private const INVALID = "Untrusted admission diagnostic invalid; QA outcome remains authoritative.\n";

	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	private function format( ?string $payload, bool $observe = false, ?string $site = null ): array {
		$root = dirname( __DIR__, 2 );
		$disabled = 'exec,shell_exec,system,passthru,popen,proc_open,file_get_contents,file_put_contents,'
			. 'fopen,glob,scandir,stat,lstat,realpath,readlink,getmypid,socket_create,stream_socket_client,curl_init';
		$command = array( PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'disable_functions=' . $disabled );
		if ( $observe ) {
			$command[] = '-r';
			$command[] = 'require ' . var_export( $root . '/scripts/untrusted-admission-diagnostic.php', true )
				. '; fwrite(STDERR, json_encode(get_included_files(), JSON_THROW_ON_ERROR));';
		} else {
			$command[] = $root . '/scripts/untrusted-admission-diagnostic.php';
		}
		$environment = null === $payload ? array() : array( 'WSTM108_ADMISSION_FAILURE' => $payload );
		if ( null !== $site ) { $environment['WSTM108_ADMISSION_CALLSITE_V1'] = $site; }
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root, $environment );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $output, $errors );
	}

	public function test_every_existing_allowlisted_reason_formats_without_admission_or_evidence_reads(): void {
		foreach ( Wstm108_HostTopology::REFUSAL_REASONS as $reason ) {
			$expected = 'Untrusted admission refused: phase=topology reason=' . $reason
				. " callsite=unknown; diagnostic formatting only, not admission success; QA outcome remains authoritative.\n";
			self::assertSame( array( 0, $expected, '' ), $this->format( json_encode( array( 'phase' => 'topology', 'reason' => $reason ), JSON_THROW_ON_ERROR ) ) );
			self::assertSame( array( 0, $expected, '' ), $this->format( ' { "reason" : "' . $reason . '", "phase" : "topology" } ' ) );
		}
	}

	public function test_absent_output_is_unavailable_not_acceptance(): void {
		foreach ( array( null, '' ) as $payload ) {
			self::assertSame( array( 0, self::UNAVAILABLE, '' ), $this->format( $payload ) );
		}
	}

	public function test_invalid_foreign_injection_and_private_values_never_escape(): void {
		$reason = Wstm108_HostTopology::REFUSAL_REASONS[0];
		$valid = '{"phase":"topology","reason":"' . $reason . '"}';
		$sentinel = 'PRIVATE_SENTINEL_167';
		$invalid = array(
			' ', '{', 'null', 'false', '[]', '42', '"topology"',
			'{"phase":"topology"}', '{"reason":"' . $reason . '"}',
			'{"phase":"runtime","reason":"' . $reason . '"}',
			'{"phase":"topology","reason":"foreign"}',
			'{"phase":true,"reason":"' . $reason . '"}',
			'{"phase":["topology"],"reason":"' . $reason . '"}',
			'{"phase":"topology","reason":78}',
			'{"phase":"topology","reason":null}',
			'{"phase":"topology","reason":{"path":"' . $sentinel . '"}}',
			'{"phase":"topology","reason":"' . $reason . '","extra":"' . $sentinel . '"}',
			'{"phase":"runtime","phase":"topology","reason":"' . $reason . '"}',
			'{"phase":"topology","reason":"foreign","reason":"' . $reason . '"}',
			'{"phase":"topology","reason":"' . $reason . '","phase":"topology"}',
			'{"phase":"topology","reason":"' . $sentinel . '$(touch forbidden);::error::\\n"}',
			'{"phase":"topology","reason":"C:\\\\private\\\\' . $sentinel . '"}',
			$valid . "\n::set-output name=untrusted_export_ready::true",
			'{"phase":"topology","reason":"\\u0000' . $sentinel . '"}', $valid . "\x1b[31m" . $sentinel,
			"{\"phase\":\"topology\",\"reason\":\"\xff\"}",
			str_repeat( ' ', 257 ) . $valid,
			$valid . str_repeat( ' ', 257 - strlen( $valid ) ),
			str_repeat( $sentinel, 128 ),
		);
		foreach ( $invalid as $payload ) {
			self::assertSame( array( 0, self::INVALID, '' ), $this->format( $payload ) );
		}
		self::assertSame( array( 0, 'Untrusted admission refused: phase=topology reason=' . $reason
			. " callsite=unknown; diagnostic formatting only, not admission success; QA outcome remains authoritative.\n", '' ),
			$this->format( $valid . str_repeat( ' ', 256 - strlen( $valid ) ) ) );
	}

	public function test_formatter_loads_only_passive_allowlist_definitions(): void {
		$result = $this->format( '{"phase":"topology","reason":"noncanonical-path"}', true );
		self::assertSame( 0, $result[0] );
		$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
		self::assertSame( array(
			$root . '/scripts/untrusted-admission-diagnostic.php',
			$root . '/scripts/untrusted-host-topology.php',
			$root . '/tests/e2e/untrusted-content-files.php',
		), array_map( static fn( $path ) => str_replace( '\\', '/', $path ), json_decode( $result[2], true, 4, JSON_THROW_ON_ERROR ) ) );
		$source = $this->read( 'scripts/untrusted-admission-diagnostic.php' );
		self::assertSame( 2, substr_count( $source, 'getenv(' ) );
		self::assertStringContainsString( "getenv( 'WSTM108_ADMISSION_FAILURE' )", $source );
		self::assertStringContainsString( 'Wstm108_HostTopology::REFUSAL_REASONS', $source );
		self::assertDoesNotMatchRegularExpression( '/(?:\\$argv|GITHUB_OUTPUT|WSTM108_HOST_AUTHORITY_ROOT|Wstm108_HostTopology::(?!REFUSAL_REASONS)|(?:file_get_contents|fopen|exec|proc_open|shell_exec|realpath)\\s*\\()/', $source );
	}

	public function test_all_runtime_qa_steps_have_best_effort_environment_only_diagnostics(): void {
		$step = "      - name: Show closed admission refusal diagnostic (not acceptance)\n"
			. "        if: \${{ always() }}\n        continue-on-error: true\n        env:\n"
			. "          WSTM108_ADMISSION_FAILURE: \${{ steps.qa.outputs.untrusted_admission_failure }}\n"
			. "          WSTM108_ADMISSION_CALLSITE_V1: \${{ steps.qa.outputs.untrusted_admission_callsite_v1 }}\n"
			. "        run: php -d display_errors=0 -d log_errors=0 scripts/untrusted-admission-diagnostic.php\n";
		foreach ( array( 'e2e-qa.yml' => 2, 'release-package-qa.yml' => 1, 'release.yml' => 1, 'compatibility-qa.yml' => 4 ) as $name => $count ) {
			$path = '.github/workflows/' . $name;
			$source = $this->read( $path );
			self::assertSame( $count, substr_count( $source, '        id: qa' ), $name );
			$floor_step = str_replace( "diagnostic (not acceptance)\n", "diagnostic (not acceptance)\n"
				. "        working-directory: \${{ github.workspace }}/candidate-floor-tools\n", $step );
			$floor_count = 'compatibility-qa.yml' === $name ? 1 : 0;
			self::assertSame( $count - $floor_count, substr_count( $source, $step ), $name );
			self::assertSame( $floor_count, substr_count( $source, $floor_step ), $name );
			self::assertSame( $count, substr_count( $source, 'untrusted_admission_failure' ), $name );
			self::assertSame( $count, preg_match_all( '/        id: qa\n(?:(?!      - name:).)*?        run: bash scripts\/(?:e2e-test|release-qa)\.sh[^\n]*\n\n?(?:'
				. preg_quote( $step, '/' ) . '|' . preg_quote( $floor_step, '/' ) . ')/s', $source ), $name );
			$historical = Wstm167CiBudgetControlTransition::restore( $path, $this->read( $path ) );
			$old_step = str_replace( "          WSTM108_ADMISSION_CALLSITE_V1: \${{ steps.qa.outputs.untrusted_admission_callsite_v1 }}\n", '', $step );
			self::assertSame( str_replace( $old_step, '', str_replace( $old_step . "\n", '', $historical ) ),
				Wstm167CiDiagnosticTransition::restore( $path, $this->read( $path ) ), $name );
		}

		foreach ( array( 'unit-tests.yml', 'coding-standards.yml', 'workflow-lint.yml' ) as $name ) {
			self::assertStringNotContainsString( 'untrusted-admission-diagnostic', $this->read( '.github/workflows/' . $name ) );
		}
	}

	public function test_scalar_is_finite_and_malformed_private_or_unpaired_values_are_unknown(): void {
		$payload = '{"phase":"topology","reason":"noncanonical-path"}';
		foreach ( Wstm108_AdmissionCallsite::IDS as $site ) {
			$result = $this->format( $payload, false, $site );
			self::assertSame( 0, $result[0] );
			self::assertSame( '', $result[2] );
			self::assertStringContainsString( ' callsite=' . $site . ';', $result[1] );
			if ( 0 === strpos( $site, 'mount-root-' ) ) {
				// OS environments cannot carry NUL; test that value at the closed allowlist itself.
				self::assertFalse( Wstm108_AdmissionCallsite::allows( 'noncanonical-path', $site . "\0" ) );
				foreach ( array( $site . "\nPRIVATE_SENTINEL", $site . ' ', $site . '-PRIVATE_SENTINEL' ) as $bad ) {
					$result = $this->format( $payload, false, $bad );
					self::assertStringContainsString( ' callsite=unknown;', $result[1] );
					self::assertSame( '', $result[2] );
					self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $result[1] );
				}
				self::assertStringContainsString( ' callsite=unknown;',
					$this->format( '{"phase":"topology","reason":"native-coordinate-prerequisite"}', false, $site )[1] );
			}
		}
		foreach ( array( '', 'PRIVATE_SENTINEL', '/private/mount-root', 'mount-root ', "mount-root\n::error::PRIVATE_SENTINEL",
			'{"site":"mount-root"}', str_repeat( 'PRIVATE_SENTINEL', 20 ), 'kernel-physical-length-extra' ) as $site ) {
			$result = $this->format( $payload, false, $site );
			self::assertStringContainsString( ' callsite=unknown;', $result[1] );
			self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $result[1] . $result[2] );
		}
		self::assertStringContainsString( ' callsite=unknown;',
			$this->format( '{"phase":"topology","reason":"malformed-mount-record"}', false, 'mount-root' )[1] );
		self::assertSame( self::INVALID, $this->format( 'PRIVATE_SENTINEL', false, 'mount-root' )[1] );
		foreach ( Wstm108_AdmissionCallsite::PREREQUISITE_IDS as $site ) {
			$result = $this->format( '{"phase":"topology","reason":"native-coordinate-prerequisite"}', false, $site );
			self::assertStringContainsString( ' callsite=' . $site . ';', $result[1] );
			self::assertSame( '', $result[2] );
			self::assertStringContainsString( ' callsite=unknown;', $this->format( $payload, false, $site )[1] );
		}
		self::assertStringContainsString( ' callsite=unknown;',
			$this->format( '{"phase":"topology","reason":"native-coordinate-prerequisite"}', false, 'mount-root' )[1] );
	}

	public function test_outer_reversal_preserves_original_bytes_seals_and_source_fixes(): void {
		$map = Wstm167CiDiagnosticTransition::load();
		self::assertSame( '1d1f9080c9e38627995c26eb11f9c4a54e8f8193', $map['base_commit'] );
		self::assertSame( array(
			'.github/REPOSITORY_CHANGELOG.md', '.github/workflows/compatibility-qa.yml',
			'.github/workflows/e2e-qa.yml', '.github/workflows/release-package-qa.yml',
			'.github/workflows/release.yml', 'docs/ci-cd-strategy.md',
			'tests/unit/ScoreProofEntryTransitionTest.php',
			'tests/unit/UntrustedExportTest.php',
			'tests/unit/fixtures/score-proof-entry-transition.php',
			'tests/unit/fixtures/untrusted-workflow-transition.php',
		), array_keys( $map['files'] ) );
		Wstm167CiDiagnosticTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167CiDiagnosticTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		self::assertSame( 'bbc14f6fdf5184f515a93dedd19b29d707245e52bd4d17207a61b5fbfd8e2076', hash( 'sha256', $this->read( 'tests/unit/fixtures/score-marker-transition.json' ) ) );
		self::assertSame( 'e79abfdb00bcb85deff22b30c836b2bfbc2ac1e343a8b20dc9ba1311aaeb8ca8', hash( 'sha256', $this->read( 'tests/unit/fixtures/score-proof-entry-transition.json' ) ) );
	}

	public function test_omission_reversion_forgery_and_foreign_source_are_rejected(): void {
		$map = Wstm167CiDiagnosticTransition::load();
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			foreach ( array( Wstm167CiDiagnosticTransition::restore( $path, $current ), $current . "\nforeign", str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try {
					Wstm167CiDiagnosticTransition::restore( $path, $foreign );
					self::fail( 'Unreviewed source accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try {
					Wstm167CiDiagnosticTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) );
					self::fail( 'Omitted or foreign dependency accepted.' );
				} catch ( RuntimeException $error ) {
					self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() );
				}
			}
		}
		$json = $this->read( 'tests/unit/fixtures/ci-diagnostic-transition.json' );
		$forgeries = array( '', $json . "\n" );
		foreach ( array_keys( $map ) as $key ) {
			$foreign = $map;
			unset( $foreign[ $key ] );
			$forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR );
		}
		foreach ( array( 'before', 'after', 'start', 'hunks', 'baseline_raw_sha256', 'current_raw_sha256' ) as $key ) {
			$forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json );
		}
		foreach ( $forgeries as $foreign ) {
			try {
				Wstm167CiDiagnosticTransition::load( $foreign );
				self::fail( 'Forged ledger accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'CI diagnostic transition seal mismatch.', $error->getMessage() );
			}
		}
	}
}
