<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/shared-helper-transition.php';

final class ArraySyntaxTest extends TestCase {
	public function testReviewedConversionChangesOnlyArrayLiteralTokens(): void {
		$map = Wstm119ArraySyntaxTransition::load();
		self::assertCount( 5, $map['files'] );
		foreach ( $map['files'] as $path => $binding ) {
			$current = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertSame( $binding['current_raw_sha256'], hash( 'sha256', $current ), $path );
			$before = Wstm119ArraySyntaxTransition::restore( $path, $current );
			self::assertSame( $binding['baseline_sha256'], hash( 'sha256', $before ), $path );
			self::assertSame( self::literal_tokens( $before ), self::literal_tokens( $current ), $path );
			self::assertSame( $before, Wstm119ArraySyntaxTransition::restore( $path, str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $current ) ) ), $path );
		}
	}

	public function testHistoricalSealsAndDependencyControlsRemainUnchanged(): void {
		foreach ( [
			'shared-helper-transition.json' => Wstm119SourceTransition::SEAL,
			'posts-extraction-transition.json' => Wstm127SourceTransition::SEAL,
			'bounded-integration-transition.json' => Wstm167SourceTransition::SEAL,
		] as $file => $seal ) {
			self::assertSame( $seal, hash( 'sha256', str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/fixtures/' . $file ) ) ) );
		}
		Wstm127SourceTransition::verify_dependencies();
		foreach ( [ 'includes/class-backup-status.php', 'includes/class-performance-status.php' ] as $path ) {
			$current = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertSame( Wstm119SourceTransition::load()['files'][ $path ]['baseline_sha256'], hash( 'sha256', Wstm119SourceTransition::restore( $path, $current ) ) );
		}
	}

	public function testEveryConvertedSourceRejectsForeignBytesEvenWhenSemanticallyEquivalent(): void {
		foreach ( Wstm119ArraySyntaxTransition::load()['files'] as $path => $binding ) {
			$current = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			foreach ( [ $current . "\n// foreign\n", str_replace( '<?php', "<?php\n// foreign", $current ), $current . ' ' ] as $foreign ) {
				try {
					Wstm119ArraySyntaxTransition::restore( $path, $foreign );
					self::fail( 'Accepted source drift: ' . $path );
				} catch ( RuntimeException $error ) {
					self::assertSame( 'Array syntax current source drift: ' . $path, $error->getMessage() );
				}
			}
		}
	}

	public function testForgedProofCannotChangeHashesHunksPathsOrPredecessors(): void {
		$json = file_get_contents( __DIR__ . '/fixtures/array-syntax-transition.json' );
		foreach ( [ 'schema', 'base_commit', 'base_inventory_sha256', 'predecessor_seals', 'files', 'baseline_sha256', 'current_sha256', 'baseline_blob', 'current_blob', 'baseline_raw_sha256', 'current_raw_sha256', 'hunks', 'start', 'before', 'after', 'includes/class-backup-status.php' ] as $key ) {
			try {
				Wstm119ArraySyntaxTransition::load( str_replace( '"' . $key . '"', '"' . $key . '_forged"', $json ) );
				self::fail( 'Accepted forged proof: ' . $key );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'Array syntax transition seal mismatch.', $error->getMessage() );
			}
		}
	}

	/** @dataProvider syntax_examples */
	public function testEnforcedRuleRejectsLongLiteralsNotArrayTypesOrAccess( string $source, int $expected ): void {
		$root = dirname( __DIR__, 2 );
		$command = [
			PHP_BINARY, $root . '/vendor/squizlabs/php_codesniffer/bin/phpcs',
			'--standard=' . $root . '/phpcs.xml.dist',
			'--sniffs=Generic.Arrays.DisallowLongArraySyntax', '--report=json',
			'--stdin-path=includes/array-syntax-probe.php', '-',
		];
		$process = proc_open( $command, [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, $root );
		self::assertIsResource( $process );
		fwrite( $pipes[0], $source );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		self::assertSame( '', $error );
		$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $expected, $report['totals']['errors'], $output );
		self::assertSame( 0 === $expected ? 0 : 2, $status );
	}

	public static function syntax_examples(): array {
		return [
			'empty long literal' => [ '<?php $x = array();', 1 ],
			'nested long literals' => [ '<?php $x = array( "x" => array( 1 ) );', 2 ],
			'short literals' => [ '<?php $x = [ "x" => [ 1 ] ];', 0 ],
			'type declarations and offsets' => [ '<?php function example(array $x): array { return $x[0]; }', 0 ],
			'destructuring' => [ '<?php [ $a, $b ] = [ 1, 2 ];', 0 ],
			'strings and comments' => [ '<?php $x = "array()"; /* array() */', 0 ],
		];
	}

	private static function literal_tokens( string $source ): array {
		$tokens = token_get_all( str_replace( "\r\n", "\n", $source ), TOKEN_PARSE );
		$result = [];
		$stack = [];
		$array = false;
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
				continue;
			}
			if ( is_array( $token ) && T_ARRAY === $token[0] ) {
				$next = $index + 1;
				while ( isset( $tokens[ $next ] ) && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
					++$next;
				}
				if ( '(' === ( $tokens[ $next ] ?? null ) ) {
					$array = true;
					continue;
				}
			}
			if ( '(' === $token ) {
				$stack[] = $array;
				$result[] = $array ? '[' : '(';
				$array = false;
			} elseif ( ')' === $token ) {
				$result[] = array_pop( $stack ) ? ']' : ')';
			} else {
				$result[] = is_array( $token ) ? [ $token[0], $token[1] ] : $token;
			}
		}
		return $result;
	}
}
