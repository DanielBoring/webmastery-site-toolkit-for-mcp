<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ArraySyntaxTest extends TestCase {
	private static function long_literals( string $source ): int {
		$tokens = token_get_all( $source, TOKEN_PARSE );
		$count = 0;
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_ARRAY === $token[0] ) {
				$next = $index + 1;
				while ( isset( $tokens[ $next ] ) && is_array( $tokens[ $next ] )
					&& in_array( $tokens[ $next ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$next;
				}
				$count += '(' === ( $tokens[ $next ] ?? null ) ? 1 : 0;
			}
		}
		return $count;
	}

	public function testConvertedProductionFilesParseAndUseShortArrayLiterals(): void {
		foreach ( array( 'backup-status', 'performance-status', 'database-health', 'users', 'comments' ) as $name ) {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-' . $name . '.php' );
			self::assertSame( 0, self::long_literals( $source ), $name );
			self::assertSame( 0, self::long_literals( str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", $source ) ) ), $name );
		}
	}

	/** @dataProvider syntax_examples */
	public function testLexerAndRepositorySniffAgreeWithoutHistoricalRestoration( string $source, int $expected ): void {
		self::assertSame( $expected, self::long_literals( $source ) );
		$root = dirname( __DIR__, 2 );
		$vendor = dirname( ( new ReflectionClass( Composer\Autoload\ClassLoader::class ) )->getFileName(), 2 );
		$command = array( PHP_BINARY, $vendor . '/squizlabs/php_codesniffer/bin/phpcs',
			'--standard=' . $root . '/phpcs.xml.dist', '--sniffs=Generic.Arrays.DisallowLongArraySyntax',
			'--report=json', '--stdin-path=includes/array-syntax-probe.php', '-' );
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, $root );
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
		return array(
			'empty long literal' => array( '<?php $x = array();', 1 ),
			'nested long literals' => array( '<?php $x = array( "x" => array( 1 ) );', 2 ),
			'short literals' => array( '<?php $x = [ "x" => [ 1 ] ];', 0 ),
			'type declarations and offsets' => array( '<?php function example(array $x): array { return $x[0]; }', 0 ),
			'destructuring' => array( '<?php [ $a, $b ] = [ 1, 2 ];', 0 ),
			'strings and comments' => array( '<?php $x = "array()"; /* array() */', 0 ),
		);
	}
}
