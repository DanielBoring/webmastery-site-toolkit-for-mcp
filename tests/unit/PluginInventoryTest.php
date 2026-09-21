<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm119\Webmastery_MCP_Backup_Status as Backup;
use Wstm119\Webmastery_MCP_Performance_Status as Performance;
use Wstm119\Webmastery_MCP_Plugins as Plugins;

require_once __DIR__ . '/fixtures/plugin-inventory-stubs.php';

final class PluginInventoryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm119'] = array(
			'options' => array(),
			'network' => array(),
			'multisite' => false,
			'caps' => array(),
			'calls' => array(),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm119'] );
		parent::tearDown();
	}

	private static function inventories(): array {
		return array(
			array( Plugins::class, 'active_basenames' ),
			array( Backup::class, 'get_active_plugin_basenames' ),
			array( Performance::class, 'get_active_plugin_basenames' ),
		);
	}

	private static function inventory( array $target ): array {
		$method = new ReflectionMethod( $target[0], $target[1] );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	public static function option_cases(): array {
		$local = array( 'active_plugins' => array( 'b/b.php', 'a/a.php', 'b/b.php' ) );
		$network = array( 'active_sitewide_plugins' => array( 'a/a.php' => 100, 'c/c.php' => false, 'b/b.php' => 200 ) );
		$cases = array(
			'absent single site' => array( array(), array(), false, array() ),
			'absent multisite' => array( array(), array(), true, array() ),
			'local order and duplicates' => array( $local, $network, false, array( 'b/b.php', 'a/a.php' ) ),
			'network keys after local' => array( $local, $network, true, array( 'b/b.php', 'a/a.php', 'c/c.php' ) ),
			'network only' => array( array(), $network, true, array( 'a/a.php', 'c/c.php', 'b/b.php' ) ),
			'cast then deduplicate and reindex' => array(
				array( 'active_plugins' => array( 8 => 12, 2 => '12', 'x' => false, 4 => null, 6 => true, 7 => '1', 9 => 1.5, 11 => 'z/z.php' ) ),
				array( 'active_sitewide_plugins' => array( 12 => 500, 'z/z.php' => null, 'tail/tail.php' => 100 ) ),
				true,
				array( '12', '', '1', '1.5', 'z/z.php', 'tail/tail.php' ),
			),
		);
		foreach ( array( null, false, true, 123, 'b/b.php', (object) array( 'plugin' => 'b/b.php' ) ) as $index => $malformed ) {
			$cases[ 'malformed local ' . $index ] = array( array( 'active_plugins' => $malformed ), $network, true, array( 'a/a.php', 'c/c.php', 'b/b.php' ) );
			$cases[ 'malformed network ' . $index ] = array( $local, array( 'active_sitewide_plugins' => $malformed ), true, array( 'b/b.php', 'a/a.php' ) );
			$cases[ 'malformed both single site ' . $index ] = array( array( 'active_plugins' => $malformed ), array( 'active_sitewide_plugins' => $malformed ), false, array() );
		}
		return $cases;
	}

	/** @dataProvider option_cases */
	public function test_inventory_preserves_values_order_reads_and_no_side_effects( array $options, array $network, bool $multisite, array $expected ): void {
		$GLOBALS['wstm119']['options'] = $options;
		$GLOBALS['wstm119']['network'] = $network;
		$GLOBALS['wstm119']['multisite'] = $multisite;
		$expected_calls = array( array( 'get_option', 'active_plugins', array() ), array( 'is_multisite' ) );
		if ( $multisite ) {
			$expected_calls[] = array( 'get_site_option', 'active_sitewide_plugins', array() );
		}
		foreach ( self::inventories() as $target ) {
			$GLOBALS['wstm119']['calls'] = array();
			$before = $GLOBALS['wstm119'];
			$included = get_included_files();
			$result = self::inventory( $target );
			$included_after = get_included_files();
			self::assertSame( $expected, $result, $target[0] );
			self::assertSame( $expected_calls, $GLOBALS['wstm119']['calls'] );
			$after = $GLOBALS['wstm119'];
			$after['calls'] = array();
			self::assertSame( $before, $after );
			self::assertSame( $included, $included_after );
		}
	}

	public function test_each_call_observes_fresh_filtered_local_and_network_options(): void {
		foreach ( self::inventories() as $target ) {
			foreach ( array( false, true, false ) as $multisite ) {
				$GLOBALS['wstm119']['multisite'] = $multisite;
				$GLOBALS['wstm119']['calls'] = array();
				foreach ( array( 'first', 'second' ) as $value ) {
					$GLOBALS['wstm119']['filter'] = static function ( $name, $stored ) use ( $value ) {
						return 'active_plugins' === $name ? array( $value . '/local.php' ) : array( $value . '/network.php' => 123 );
					};
					self::assertSame(
						$multisite ? array( $value . '/local.php', $value . '/network.php' ) : array( $value . '/local.php' ),
						self::inventory( $target )
					);
				}
				self::assertCount( $multisite ? 6 : 4, $GLOBALS['wstm119']['calls'] );
			}
		}
	}

	public function test_diagnostic_permissions_remain_manage_options_only(): void {
		foreach ( array( Backup::class, Performance::class ) as $class ) {
			foreach ( array( array(), array( 'activate_plugins' ), array( 'manage_options' ) ) as $caps ) {
				$GLOBALS['wstm119']['caps'] = $caps;
				$GLOBALS['wstm119']['calls'] = array();
				$result = $class::permission();
				if ( in_array( 'manage_options', $caps, true ) ) {
					self::assertTrue( $result );
				} else {
					self::assertInstanceOf( WP_Error::class, $result );
					self::assertSame( 'forbidden', $result->get_error_code() );
					self::assertSame( 'Requires manage_options capability.', $result->get_error_message() );
				}
				self::assertSame( array( array( 'current_user_can', 'manage_options' ) ), $GLOBALS['wstm119']['calls'] );
			}
		}
	}

	public function test_complete_diagnostic_responses_with_and_without_inventory_matches(): void {
		foreach ( array( false, true ) as $active ) {
			$GLOBALS['wstm119']['multisite'] = true;
			$GLOBALS['wstm119']['network'] = array( 'active_sitewide_plugins' => $active ? array( 'duplicator/duplicator.php' => 1, 'wp-rocket/wp-rocket.php' => 2 ) : array() );
			$GLOBALS['wstm119']['caps'] = array( 'manage_options' );
			$GLOBALS['wstm119']['calls'] = array();
			$backup_plugins = $active ? array( array( 'name' => 'Duplicator', 'basename' => 'duplicator/duplicator.php', 'last_backup' => null, 'schedule' => null ) ) : array();
			self::assertSame(
				array( 'success' => true, 'data' => array(
					'backup_plugin_detected' => $active,
					'plugin_name' => $active ? 'Duplicator' : null,
					'last_backup' => null,
					'schedule' => null,
					'warning' => $active ? null : 'No known active WordPress backup plugin was detected. Configure and verify a reliable backup solution for this site.',
					'active_known_plugins' => $backup_plugins,
				) ),
				Backup::execute()
			);
			self::assertSame(
				array( 'success' => true, 'data' => array(
					'object_cache' => array( 'external_object_cache_active' => false, 'object_cache_dropin_present' => false ),
					'page_cache' => array(
						'known_page_cache_active' => $active,
						'active_known_plugins' => $active ? array( array( 'name' => 'WP Rocket', 'basename' => 'wp-rocket/wp-rocket.php' ) ) : array(),
						'advanced_cache_dropin_present' => false,
					),
					'memory' => array( 'wp_memory_limit' => null, 'server_memory_limit' => (string) ini_get( 'memory_limit' ) ),
					'post_revisions' => array( 'defined' => false, 'value' => null ),
					'autosave' => array( 'defined' => false, 'interval_seconds' => 60 ),
					'concatenate_scripts' => array( 'defined' => false, 'value' => null, 'disabled' => false ),
				) ),
				Performance::execute()
			);
			$reads = array( array( 'get_option', 'active_plugins', array() ), array( 'is_multisite' ), array( 'get_site_option', 'active_sitewide_plugins', array() ) );
			self::assertSame( array_merge( $reads, $reads ), $GLOBALS['wstm119']['calls'] );
		}
	}
}
