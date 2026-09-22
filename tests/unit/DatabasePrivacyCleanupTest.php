<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/database-table-privacy-fixture.php';

final class DatabasePrivacyCleanupTest extends TestCase {
	public function test_private_tokens_preserve_complete_names_without_forbidding_public_core_labels(): void {
		$this->assertSame(
			array( 'private_users' ),
			Webmastery_MCP_Database_Table_Privacy_Fixture::private_tokens( 'private_users', 'private_' )
		);
		$this->assertSame(
			array( 'private_wstm111_owned_plugin_fingerprint', 'wstm111_owned_plugin_fingerprint' ),
			Webmastery_MCP_Database_Table_Privacy_Fixture::private_tokens( 'private_wstm111_owned_plugin_fingerprint', 'private_' )
		);
		$this->assertStringNotContainsString( 'private_users', '{"table":"users","is_core_table":true}' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_cleanup_records_failures_and_attempts_every_owned_table(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public array $drops = array();
			public array $checks = array();

			public function prepare( $query, $name ): string {
				return $name;
			}

			public function esc_like( $name ): string {
				return $name;
			}

			public function query( $name ) {
				$this->drops[] = $name;
				if ( 'owned_throw' === $name ) {
					throw new RuntimeException( 'injected drop failure' );
				}
				return 'owned_false' === $name ? false : 0;
			}

			public function get_var( $name ) {
				$this->checks[] = $name;
				if ( 'owned_throw' === $name ) {
					throw new RuntimeException( 'injected absence failure' );
				}
				return 'owned_false' === $name ? $name : null;
			}
		};
		$fixture = new Webmastery_MCP_Database_Table_Privacy_Fixture();
		$fixture->names = array( 'owned_throw', 'owned_false', 'owned_success' );
		$fixture->restore();
		$this->assertSame( $fixture->names, $GLOBALS['wpdb']->drops );
		$this->assertSame( $fixture->names, $GLOBALS['wpdb']->checks );
		$this->assertSame( array(
			array( 'table' => 'owned_throw', 'dropped' => false, 'absent' => false, 'errors' => array( 'DROP failed: injected drop failure', 'Absence check failed: injected absence failure' ) ),
			array( 'table' => 'owned_false', 'dropped' => false, 'absent' => false, 'errors' => array() ),
			array( 'table' => 'owned_success', 'dropped' => true, 'absent' => true, 'errors' => array() ),
		), $fixture->cleanup );
	}
}
