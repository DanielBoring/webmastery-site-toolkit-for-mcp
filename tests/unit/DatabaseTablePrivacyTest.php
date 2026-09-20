<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-database-health.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

final class DatabaseTablePrivacyTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wstm_test_user_caps'] );
		parent::tearDown();
	}

	private function database( array $mapping, array $names, string $prefix = 'private_' ): object {
		$database = new class() {
			public string $prefix;
			public string $dbname = 'private_database';
			public string $posts = 'private_posts';
			public string $postmeta = 'private_postmeta';
			public string $options = 'private_options';
			public string $last_error = '';
			public array $mapping = array();
			public array $rows = array();
			public array $queries = array();
			public array $prepared = array();
			public array $mapping_calls = array();

			public function tables( $scope, $prefix ): array {
				$this->mapping_calls[] = array( $scope, $prefix );
				return $this->mapping;
			}

			public function prepare( $query, ...$args ): string {
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			public function esc_like( $value ): string {
				return addcslashes( $value, '_%\\' );
			}

			public function get_var( $query ): string {
				$this->queries[] = $query;
				return '7';
			}

			public function get_results( $query, $output ): array {
				$this->queries[] = $query;
				return $this->rows;
			}
		};
		$database->prefix = $prefix;
		$database->mapping = $mapping;
		foreach ( $names as $index => $name ) {
			$database->rows[] = array(
				'table_name' => $name,
				'row_count' => (string) ( 30 - $index ),
				'data_bytes' => (string) ( 200 - $index ),
				'index_bytes' => '10',
				'total_bytes' => (string) ( 210 - $index ),
			);
		}
		$GLOBALS['wpdb'] = $database;
		$GLOBALS['wstm_test_user_caps'] = array( 'manage_options' );
		return $database;
	}

	public function test_default_redacts_entire_payload_and_preserves_opt_in_metrics_and_order(): void {
		$names = array( 'private_secret_plugin', 'private_posts', 'private_custom_posts', 'private_options' );
		$database = $this->database( array( 'posts' => 'private_posts', 'options' => 'private_options' ), $names );
		$default = Webmastery_MCP_Database_Health::execute();
		$explicit = Webmastery_MCP_Database_Health::execute( array( 'include_table_names' => false ) );
		$raw = Webmastery_MCP_Database_Health::execute( array( 'include_table_names' => true ) );
		$this->assertSame( $default, $explicit );
		$this->assertSame( array( 'custom_table_1', 'posts', 'custom_table_2', 'options' ), array_column( $default['data']['table_sizes'], 'table' ) );
		$this->assertSame( array( false, true, false, true ), array_column( $default['data']['table_sizes'], 'is_core_table' ) );
		$this->assertSame( $names, array_column( $raw['data']['table_sizes'], 'table' ) );
		foreach ( array_merge( $names, array( 'private_', 'secret_plugin', 'custom_posts' ) ) as $private ) {
			$this->assertStringNotContainsString( $private, json_encode( $default ) );
		}
		foreach ( $raw['data']['table_sizes'] as $index => &$row ) {
			$row['table'] = $default['data']['table_sizes'][ $index ]['table'];
		}
		unset( $row );
		$this->assertSame( $default, $raw );
		$this->assertSame( array( 'private_database', 'private\\_%' ), $database->prepared[4][1] );
		$this->assertStringContainsString( 'ORDER BY total_bytes DESC, TABLE_NAME ASC', $database->prepared[4][0] );
		$this->assertSame( array_fill( 0, 3, array( 'all', true ) ), $database->mapping_calls );
	}

	public function test_multisite_and_custom_user_mapping_do_not_classify_suffix_collisions_as_core(): void {
		$this->database(
			array(
				'posts' => 'tenant_7_posts', 'options' => 'tenant_7_options',
				'users' => 'tenant_shared_users', 'usermeta' => 'external_usermeta',
				'blogs' => 'tenant_blogs', 'blogmeta' => 'tenant_blogmeta',
				'secret_plugin' => 'tenant_secret_plugin',
			),
			array( 'tenant_posts', 'tenant_7_posts', 'tenant_8_posts', 'tenant_users', 'tenant_shared_users', 'tenant_blogs', 'tenant_blogmeta', 'tenant_secret_plugin', 'tenant_7_users' ),
			'tenant_'
		);
		$result = Webmastery_MCP_Database_Health::execute();
		$this->assertSame(
			array( 'custom_table_1', 'posts', 'custom_table_2', 'custom_table_3', 'users', 'blogs', 'blogmeta', 'custom_table_4', 'custom_table_5' ),
			array_column( $result['data']['table_sizes'], 'table' )
		);
		$this->assertStringNotContainsString( 'tenant_', json_encode( $result ) );
		$this->assertStringNotContainsString( 'secret_plugin', json_encode( $result ) );
	}

	public function test_current_blog_scope_is_not_broadened_to_network_or_custom_user_tables(): void {
		$database = $this->database(
			array( 'posts' => 'tenant_7_posts', 'users' => 'shared_users', 'blogs' => 'tenant_blogs' ),
			array( 'tenant_7_posts', 'tenant_7_users' ),
			'tenant_7_'
		);
		$result = Webmastery_MCP_Database_Health::execute();
		$this->assertSame( array( 'posts', 'custom_table_1' ), array_column( $result['data']['table_sizes'], 'table' ) );
		$this->assertSame( array( 'private_database', 'tenant\\_7\\_%' ), $database->prepared[4][1] );
	}

	public function test_custom_labels_restart_for_each_response_and_empty_tables_are_valid(): void {
		$database = $this->database( array(), array( 'private_plugin_a', 'private_plugin_b' ) );
		$this->assertSame( 'custom_table_1', Webmastery_MCP_Database_Health::execute()['data']['table_sizes'][0]['table'] );
		array_shift( $database->rows );
		$this->assertSame( 'custom_table_1', Webmastery_MCP_Database_Health::execute()['data']['table_sizes'][0]['table'] );
		$database->rows = array();
		$this->assertSame( array(), Webmastery_MCP_Database_Health::execute()['data']['table_sizes'] );
	}

	public function test_direct_denial_precedes_input_validation_and_all_database_queries(): void {
		$database = $this->database( array(), array() );
		$GLOBALS['wstm_test_user_caps'] = array( 'read' );
		foreach ( array( array(), array( 'include_table_names' => true ), array( 'include_table_names' => 'true' ) ) as $input ) {
			$result = Webmastery_MCP_Database_Health::execute( $input );
			$this->assertSame( 'forbidden', $result->get_error_code() );
			$this->assertSame( 'Requires manage_options capability.', $result->get_error_message() );
		}
		$this->assertSame( array(), $database->queries );
		$this->assertSame( array(), $database->prepared );
		$this->assertSame( array(), $database->mapping_calls );
	}

	public function test_non_boolean_flags_and_non_array_inputs_fail_before_queries(): void {
		$database = $this->database( array(), array() );
		$inputs = array( null, true, 'true', 1, (object) array() );
		foreach ( array( null, 'true', 'false', '0', '1', 0, 1, array(), new stdClass() ) as $flag ) {
			$inputs[] = array( 'include_table_names' => $flag );
		}
		foreach ( $inputs as $input ) {
			$result = Webmastery_MCP_Database_Health::execute( $input );
			$this->assertSame( 'invalid_input', $result->get_error_code() );
			$this->assertSame( 'include_table_names must be a boolean.', $result->get_error_message() );
		}
		$this->assertSame( array(), $database->queries );
	}

	public function test_registered_flag_is_explicit_boolean_defaulting_to_private(): void {
		Webmastery_MCP_Database_Health::register();
		$schema = $GLOBALS['wstm_test_abilities']['webmastery-site-toolkit-for-mcp/database-health']['input_schema'];
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array(), $schema['default'] );
		$this->assertSame( 'boolean', $schema['properties']['include_table_names']['type'] );
		$this->assertFalse( $schema['properties']['include_table_names']['default'] );
		$this->assertStringContainsString( 'model provider', $schema['properties']['include_table_names']['description'] );
	}
}
