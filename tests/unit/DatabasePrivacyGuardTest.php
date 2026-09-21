<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DatabasePrivacyGuardTest extends TestCase {
	public static function denied_opt_ins(): array {
		return array(
			'absent' => array( null ),
			'empty' => array( '' ),
			'zero' => array( '0' ),
			'truthy word' => array( 'true' ),
			'whitespace' => array( ' 1 ' ),
		);
	}

	/**
	 * @dataProvider denied_opt_ins
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disposable_opt_in_is_required_before_loading_fixture_or_using_wordpress( ?string $value ): void {
		putenv( null === $value ? 'WSTM111_DISPOSABLE_SITE' : 'WSTM111_DISPOSABLE_SITE=' . $value );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Database privacy fixtures require WSTM111_DISPOSABLE_SITE=1 on an owned disposable installation.' );
		require dirname( __DIR__ ) . '/e2e/database-table-privacy-runner.php';
	}

	public static function invalid_site_paths(): array {
		return array( array( 'https://example.test/' ), array( '/../' ), array( '/privacy?site=2' ) );
	}

	/**
	 * @dataProvider invalid_site_paths
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_site_path_cannot_change_the_host_or_escape_the_local_path( string $path ): void {
		putenv( 'WSTM111_DISPOSABLE_SITE=1' );
		putenv( 'WSTM111_PRIVACY_SITE_PATH=' . $path );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Privacy site path must be an absolute local WordPress path.' );
		require dirname( __DIR__ ) . '/e2e/database-table-privacy-runner.php';
	}
}
