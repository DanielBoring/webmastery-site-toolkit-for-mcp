<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Database_Health {

	private const AUTOLOAD_THRESHOLD_BYTES = 921600;

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/database-health', [
			'label'               => 'Database Health',
			'description'         => 'Audit WordPress database bloat indicators including revisions, orphaned post meta, expired transients, autoloaded options, and table sizes.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
		}

		return true;
	}

	public static function execute( $input = [] ) {
		$revisions = self::get_revision_report();
		if ( is_wp_error( $revisions ) ) {
			return $revisions;
		}

		$orphaned_post_meta = self::get_orphaned_post_meta_report();
		if ( is_wp_error( $orphaned_post_meta ) ) {
			return $orphaned_post_meta;
		}

		$expired_transients = self::get_expired_transients_report();
		if ( is_wp_error( $expired_transients ) ) {
			return $expired_transients;
		}

		$autoloaded_options = self::get_autoloaded_options_report();
		if ( is_wp_error( $autoloaded_options ) ) {
			return $autoloaded_options;
		}

		$table_sizes = self::get_table_sizes();
		if ( is_wp_error( $table_sizes ) ) {
			return $table_sizes;
		}

		return [
			'success' => true,
			'data'    => [
				'post_revisions'     => $revisions,
				'orphaned_post_meta' => $orphaned_post_meta,
				'expired_transients' => $expired_transients,
				'autoloaded_options' => $autoloaded_options,
				'table_sizes'        => $table_sizes,
			],
		];
	}

	private static function get_revision_report() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Administrator-requested database health audit.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'post revisions' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reads table metadata for administrator diagnostics.
		$average_row_length = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				$wpdb->dbname,
				$wpdb->posts
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'post revision size estimate' );
		}

		$count              = absint( $count );
		$average_row_length = absint( $average_row_length );
		$limit_value        = self::get_revision_limit_value();
		$limit_configured   = self::is_revision_limit_configured( $limit_value );

		return [
			'count'                     => $count,
			'estimated_bytes'           => $count * $average_row_length,
			'wp_post_revisions_defined' => defined( 'WP_POST_REVISIONS' ),
			'wp_post_revisions_value'   => $limit_value,
			'revision_limit_configured' => $limit_configured,
			'limit_not_configured'      => ! $limit_configured,
		];
	}

	private static function get_orphaned_post_meta_report() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Administrator-requested database health audit.
		$count = $wpdb->get_var(
			"SELECT COUNT(pm.meta_id)
			FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.ID IS NULL"
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'orphaned post meta' );
		}

		return [
			'count' => absint( $count ),
		];
	}

	private static function get_expired_transients_report() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Administrator-requested database health audit.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'expired transients' );
		}

		return [
			'count' => absint( $count ),
		];
	}

	private static function get_autoloaded_options_report() {
		global $wpdb;

		$autoload_values = self::get_autoload_values();
		$placeholders    = implode( ', ', array_fill( 0, count( $autoload_values ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count is generated from the sanitized autoload value list.
		$query = $wpdb->prepare(
			"SELECT COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0)
			FROM {$wpdb->options}
			WHERE autoload IN ({$placeholders})",
			...$autoload_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Administrator-requested database health audit using the prepared query above.
		$total_bytes = $wpdb->get_var( $query );
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'autoloaded options' );
		}

		$total_bytes = absint( $total_bytes );

		return [
			'total_bytes'     => $total_bytes,
			'threshold_bytes' => self::AUTOLOAD_THRESHOLD_BYTES,
			'over_threshold'  => $total_bytes > self::AUTOLOAD_THRESHOLD_BYTES,
		];
	}

	private static function get_table_sizes() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reads table metadata for administrator diagnostics.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS table_name,
					COALESCE(TABLE_ROWS, 0) AS row_count,
					COALESCE(DATA_LENGTH, 0) AS data_bytes,
					COALESCE(INDEX_LENGTH, 0) AS index_bytes,
					COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS total_bytes
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME LIKE %s
				ORDER BY total_bytes DESC, TABLE_NAME ASC',
				$wpdb->dbname,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error ) {
			return self::database_error( 'table sizes' );
		}

		return array_map(
			static function ( $row ) {
				return [
					'table'       => (string) $row['table_name'],
					'rows'        => absint( $row['row_count'] ),
					'data_bytes'  => absint( $row['data_bytes'] ),
					'index_bytes' => absint( $row['index_bytes'] ),
					'total_bytes' => absint( $row['total_bytes'] ),
				];
			},
			(array) $rows
		);
	}

	private static function get_revision_limit_value() {
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			return null;
		}

		if ( is_bool( WP_POST_REVISIONS ) ) {
			return WP_POST_REVISIONS;
		}

		if ( is_numeric( WP_POST_REVISIONS ) ) {
			return (int) WP_POST_REVISIONS;
		}

		return (string) WP_POST_REVISIONS;
	}

	private static function is_revision_limit_configured( $limit_value ) {
		return false === $limit_value || ( is_int( $limit_value ) && $limit_value >= 0 );
	}

	private static function get_autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return array_values( array_map( 'strval', wp_autoload_values_to_autoload() ) );
		}

		return [ 'yes', 'on', 'auto-on', 'auto' ];
	}

	private static function database_error( $context ) {
		global $wpdb;

		return new WP_Error(
			'database_health_query_failed',
			sprintf(
				'Database health query failed while reading %1$s: %2$s',
				$context,
				$wpdb->last_error
			)
		);
	}
}
