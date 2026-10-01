<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UntrustedAuthorityObserverTest extends TestCase {
	private const SEAL = '88ff7a6b1d42d7839c793aa481b9099dd4631a49eabf39aa87100600b96fef58';

	private function restore( array $binding, string $source ): string {
		if ( ! hash_equals( $binding['current_raw_sha256'], hash( 'sha256', $source ) ) ) {
			throw new RuntimeException( 'Authority observer current source drift.' );
		}
		$lines = explode( "\n", $source );
		foreach ( array_reverse( $binding['hunks'] ) as $hunk ) {
			if ( $hunk['after'] !== array_slice( $lines, $hunk['start'], count( $hunk['after'] ) ) ) {
				throw new RuntimeException( 'Authority observer reverse hunk mismatch.' );
			}
			array_splice( $lines, $hunk['start'], count( $hunk['after'] ), $hunk['before'] );
		}
		$before = implode( "\n", $lines );
		if ( ! hash_equals( $binding['baseline_raw_sha256'], hash( 'sha256', $before ) ) ) {
			throw new RuntimeException( 'Authority observer restored source drift.' );
		}
		return $before;
	}

	public function testExactObserverAndImportCompositionReversalPreservesAllPriorProofs(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/authority-observer-transition.json' );
		self::assertSame( self::SEAL, hash( 'sha256', $json ) );
		$map = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( array( 'tests/unit/fixtures/untrusted-authority-controls.php', 'tests/unit/UntrustedSelectorImportTest.php' ), array_keys( $map['files'] ) );
		foreach ( $map['files'] as $path => $binding ) {
			$current = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', $this->restore( $binding, $current ) ) );
			try {
				$this->restore( $binding, $current . "\nforeign" );
				self::fail( 'Foreign observer/composition source accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Authority observer current source drift.', $error->getMessage() );
			}
			$foreign = $binding;
			$foreign['hunks'][0]['after'][] = 'foreign';
			try {
				$this->restore( $foreign, $current );
				self::fail( 'Foreign reversal accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Authority observer reverse hunk mismatch.', $error->getMessage() );
			}
		}
		foreach ( $map['frozen_files'] as $path => $hash ) {
			self::assertSame( $hash, hash_file( 'sha256', dirname( __DIR__, 2 ) . '/' . $path ), $path );
		}
	}

	private function observer(): string {
		$source = file_get_contents( __DIR__ . '/fixtures/untrusted-authority-controls.php' );
		$marker = 'set_exception_handler( static function ( Throwable $error ): void {';
		self::assertSame( 1, substr_count( $source, $marker ) );
		$start = strpos( $source, $marker );
		$end = strpos( $source, "\n} );\n", $start );
		self::assertNotFalse( $end );
		return substr( $source, $start, $end + strlen( "\n} );\n" ) - $start );
	}

	public static function invocation_modes(): array {
		return array( 'direct delegated callback' => array( true ), 'uncaught native callback' => array( false ) );
	}

	/** @dataProvider invocation_modes */
	public function testActualObserverDisablesPreviousDelegationAndRetainsOriginalThrowable( bool $direct ): void {
		$observer = $this->observer();
		self::assertStringNotContainsString( 'restore_exception_handler', $observer );
		self::assertLessThan( strpos( $observer, 'record_authority' ), strpos( $observer, 'set_exception_handler( null )' ) );
		$file = tempnam( sys_get_temp_dir(), 'wstm108-observer-' );
		self::assertIsString( $file );
		$script = '<?php require ' . var_export( __DIR__ . '/fixtures/untrusted-runtime-diagnostic.php', true ) . ';'
			. 'putenv("WSTM108_MOCK_ONLY"); putenv("WSTM108_MOCK_ROOT");'
			. '$calls = 0; $prior = static function(Throwable $error) use (&$calls): void {'
			. 'fwrite(STDERR,"UNEXPECTED_PRIOR_OBSERVER\n"); if (++$calls > 3) { exit(99); }'
			. '($GLOBALS["observer"])($error); }; set_exception_handler($prior);'
			. $observer
			. '$GLOBALS["observer"] = set_exception_handler($prior);'
			. 'set_exception_handler($GLOBALS["observer"]);'
			. ( $direct ? '$original = new Error("WSTM108_PRIVATE_ORIGINAL_ERROR",47);'
				. 'try { ($GLOBALS["observer"])($original); } catch (Throwable $caught) {'
				. 'if ($caught !== $original) { exit(98); }'
				. 'if (null !== set_exception_handler(null)) { exit(99); } throw $caught; }'
				: 'throw new Error("WSTM108_PRIVATE_ORIGINAL_ERROR",47);' );
		try {
			self::assertSame( strlen( $script ), file_put_contents( $file, $script ) );
			$process = proc_open( array( PHP_BINARY, $file ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 255, proc_close( $process ) );
			$private = $out . $err;
			self::assertSame( 1, substr_count( $private, 'Native authority location writer failed; original Throwable retained.' ) );
			self::assertStringNotContainsString( 'UNEXPECTED_PRIOR_OBSERVER', $private );
			self::assertStringContainsString( 'Uncaught Error: WSTM108_PRIVATE_ORIGINAL_ERROR', $private );
			self::assertStringNotContainsString( 'stack size', $private );
		} finally {
			self::assertTrue( unlink( $file ) );
		}
	}
}
