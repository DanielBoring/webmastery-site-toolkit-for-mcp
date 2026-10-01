<?php

declare(strict_types=1);

/** Exact copied-wrapper RED mutations; never changes admission or native trust. */
final class Wstm108_LegacyRetentionFixture {
	private static function replace( string $source, string $before, string $after ): string {
		if ( 1 !== substr_count( $source, $before ) || $before === $after ) {
			throw new RuntimeException( 'Legacy retention fixture source seam changed.' );
		}
		return str_replace( $before, $after, $source );
	}

	public static function transform( string $path, string $source ): string {
		if ( ! in_array( $path, array( 'scripts/release-qa.sh', 'scripts/e2e-test.sh' ), true ) ) {
			throw new RuntimeException( 'Unsupported legacy retention fixture wrapper.' );
		}
		$ending = false !== strpos( $source, "\r\n" ) ? "\r\n" : "\n";
		$normalized = str_replace( "\r\n", "\n", $source );
		if ( false !== strpos( $normalized, "\r" ) || ( "\r\n" === $ending && substr_count( $source, "\n" ) !== substr_count( $source, "\r\n" ) ) ) {
			throw new RuntimeException( 'Mixed legacy retention fixture line endings.' );
		}
		$normalized = self::replace( $normalized, 'if ! wstm116_require_no_retention; then', 'if false; then' );
		if ( 'scripts/release-qa.sh' === $path ) {
			$normalized = self::replace(
				$normalized,
				"runtime_status=0\nbash scripts/e2e-test.sh all || runtime_status=\$?\n"
				. "if [[ \"\$runtime_status\" != 0 ]]; then exit \"\$runtime_status\"; fi\ntrap cleanup_release EXIT",
				"trap cleanup_release EXIT\nbash scripts/e2e-test.sh all"
			);
		}
		return "\r\n" === $ending ? str_replace( "\n", "\r\n", $normalized ) : $normalized;
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	if ( '1' !== getenv( 'WSTM108_MOCK_ONLY' ) || 'Linux' !== PHP_OS_FAMILY
		|| getcwd() !== getenv( 'WSTM108_MOCK_CHECKOUT' )
		|| getcwd() !== getenv( 'WORK' ) . '/source with spaces' || 2 !== count( $argv )
		|| ! in_array( $argv[1], array( 'scripts/release-qa.sh', 'scripts/e2e-test.sh' ), true )
		|| is_link( $argv[1] ) || realpath( $argv[1] ) !== getcwd() . '/' . $argv[1] ) {
		throw new RuntimeException( 'Legacy retention mutation requires the isolated native mock checkout.' );
	}
	$source = file_get_contents( $argv[1] );
	if ( ! is_string( $source ) ) { throw new RuntimeException( 'Missing copied legacy retention wrapper.' ); }
	echo Wstm108_LegacyRetentionFixture::transform( $argv[1], $source );
}
