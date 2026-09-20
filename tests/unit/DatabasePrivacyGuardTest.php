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
}
