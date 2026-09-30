<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-stage-envelope.php';

final class UntrustedMockRetentionTest extends TestCase {
	public static function counts(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'valid', 'old-plan-count', 'wrong-passed', 'wrong-failed', 'wrong-status', 'cleanup',
			'self-consistent-short', 'extra-case', 'reordered', 'duplicate-label', 'integer-outcome',
			'wrong-fault-index', 'extra-failure', 'no-failure',
		) );
	}

	/** @dataProvider counts */
	public function test_actual_outer_mock_assertion_requires_source_planned_counts_and_one_typed_failure( string $kind ): void {
		$labels = Wstm108_Plan::labels( Wstm108_ProofFixture::native() );
		$cases = array_map( static fn( $label ) => array( 'label' => $label, 'passed' => true ), $labels );
		$cases[100]['passed'] = false;
		$proof = array( 'passed' => count( $labels ) - 1, 'failed' => 1, 'status' => 'failed',
			'cleanup_complete' => true, 'cases' => $cases );
		switch ( $kind ) {
			case 'old-plan-count': $proof['passed'] = 285; break;
			case 'wrong-passed': ++$proof['passed']; break;
			case 'wrong-failed': $proof['failed'] = 0; break;
			case 'wrong-status': $proof['status'] = 'passed'; break;
			case 'cleanup': $proof['cleanup_complete'] = false; break;
			case 'self-consistent-short':
				array_pop( $proof['cases'] );
				$proof['passed'] = count( $proof['cases'] ) - 1;
				break;
			case 'extra-case': $proof['cases'][] = end( $cases ); break;
			case 'reordered':
				list( $proof['cases'][5], $proof['cases'][6] ) = array( $cases[6], $cases[5] );
				break;
			case 'duplicate-label': $proof['cases'][6]['label'] = $cases[5]['label']; break;
			case 'integer-outcome': $proof['cases'][5]['passed'] = 1; break;
			case 'wrong-fault-index':
				$proof['cases'][100]['passed'] = true;
				$proof['cases'][99]['passed'] = false;
				break;
			case 'extra-failure': $proof['cases'][99]['passed'] = false; break;
			case 'no-failure': $proof['cases'][100]['passed'] = true; break;
		}
		$source = str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/fixtures/untrusted-mock-proof.php' ) );
		self::assertSame( 1, preg_match( '/\t\$proof = json_decode\([^\n]*\);\n([\s\S]*?)\n\}\nforeach \( glob/', $source, $match ) );
		$require = static function ( bool $condition, string $message ): void {
			if ( ! $condition ) { throw new RuntimeException( 'Synthetic outer retention assertion: ' . $message ); }
		};
		$before = serialize( $proof );
		if ( 'valid' !== $kind ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'failed case/count survives successful resource cleanup' );
		}
		eval( $match[1] );
		self::assertSame( $before, serialize( $proof ), 'The actual assertion seam never modifies failed evidence.' );
	}
}
