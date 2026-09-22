<?php

/**
 * Scoped diagnostic simulations for disposable WordPress test installations.
 * Loading this file alone does not install hooks or change configuration.
 */
final class Webmastery_MCP_Diagnostics_Fixture {
	public array $queries = array();
	public int $outbound = 0;
	public int $injected = 0;
	private array $server;
	private bool $admin_ssl;
	private bool $suppress_errors;
	private array $scenario;

	public const ERROR_CONTEXTS = array(
		'post revisions' => 'WHERE post_type =',
		'post revision size estimate' => 'SELECT AVG_ROW_LENGTH',
		'orphaned post meta' => 'SELECT COUNT(pm.meta_id)',
		'expired transients' => 'CAST(option_value AS UNSIGNED)',
		'autoloaded options' => 'SELECT COALESCE(SUM(LENGTH(option_name)',
		'table sizes' => 'SELECT TABLE_NAME AS table_name',
	);

	public function __construct( array $scenario ) {
		global $wpdb;
		$this->scenario = $scenario;
		$this->server = $_SERVER;
		$this->admin_ssl = force_ssl_admin();
		$this->suppress_errors = $wpdb->suppress_errors;
		if ( array_key_exists( 'https', $scenario ) ) {
			$_SERVER['HTTPS'] = $scenario['https'] ? 'on' : 'off';
			$_SERVER['SERVER_PORT'] = $scenario['https'] ? '443' : '80';
		}
		if ( array_key_exists( 'admin_ssl', $scenario ) ) {
			force_ssl_admin( $scenario['admin_ssl'] );
		}
		if ( array_key_exists( 'home', $scenario ) ) {
			// option_home also permits false/null fixtures without pre_option's false sentinel.
			add_filter( 'option_home', array( $this, 'home' ), PHP_INT_MAX );
		}
		if ( isset( $scenario['db_failure'] ) ) {
			if ( ! isset( self::ERROR_CONTEXTS[ $scenario['db_failure'] ] ) ) {
				throw new InvalidArgumentException( 'Unknown diagnostic failure context.' );
			}
			// Only intentionally failing fixture queries are silenced; restore the prior state.
			$wpdb->suppress_errors( true );
		}
		add_filter( 'query', array( $this, 'query' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( $this, 'http' ), PHP_INT_MAX );
	}

	public function home( $home ) {
		return $this->scenario['home'];
	}

	public function query( $query ) {
		global $wpdb;
		$this->queries[] = $query;
		$context = $this->scenario['db_failure'] ?? '';
		if ( isset( self::ERROR_CONTEXTS[ $context ] ) && str_contains( $query, self::ERROR_CONTEXTS[ $context ] ) ) {
			++$this->injected;
			return 'SELECT wstm111_RAW_SQL_PRIVATE_SENTINEL FROM information_schema.TABLES LIMIT 1';
		}
		if ( ! empty( $this->scenario['table_privacy'] ) && str_contains( $query, self::ERROR_CONTEXTS['table sizes'] ) ) {
			++$this->injected;
			return $wpdb->prepare(
				'SELECT %s AS table_name, 3 AS row_count, 200 AS data_bytes, 10 AS index_bytes, 210 AS total_bytes
				UNION ALL SELECT %s, 2, 100, 10, 110
				UNION ALL SELECT %s, 1, 50, 10, 60',
				$wpdb->posts,
				$wpdb->prefix . 'wstm111_plugin_fingerprint',
				$wpdb->prefix . 'wstm111_posts'
			);
		}
		return $query;
	}

	public function http( $preempt ) {
		++$this->outbound;
		return new WP_Error( 'unexpected_diagnostic_probe', 'Diagnostic fixture blocked an outbound request.' );
	}

	public function restore(): void {
		global $wpdb;
		remove_filter( 'option_home', array( $this, 'home' ), PHP_INT_MAX );
		remove_filter( 'query', array( $this, 'query' ), PHP_INT_MAX );
		remove_filter( 'pre_http_request', array( $this, 'http' ), PHP_INT_MAX );
		$_SERVER = $this->server;
		force_ssl_admin( $this->admin_ssl );
		$wpdb->suppress_errors( $this->suppress_errors );
	}
}
