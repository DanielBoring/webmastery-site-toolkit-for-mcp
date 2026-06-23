<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Backup_Status {

	private const KNOWN_BACKUP_PLUGINS = array(
		'updraftplus/updraftplus.php'                                => 'UpdraftPlus',
		'backwpup/backwpup.php'                                      => 'BackWPup',
		'duplicator/duplicator.php'                                  => 'Duplicator',
		'all-in-one-wp-migration/all-in-one-wp-migration.php'        => 'All-in-One WP Migration',
		'blogvault-real-time-backup/blogvault.php'                   => 'BlogVault',
		'wpvivid-backuprestore/wpvivid-backuprestore.php'            => 'WPvivid Backup & Migration',
		'jetpack/jetpack.php'                                        => 'Jetpack',
		'vaultpress/vaultpress.php'                                  => 'VaultPress',
		'worker/init.php'                                            => 'ManageWP Worker',
	);

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/backup-status', array(
			'label'               => 'Backup Status',
			'description'         => 'Detect active known WordPress backup plugins and report accessible last-backup and schedule details.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => array( self::class, 'execute' ),
			'permission_callback' => array( self::class, 'permission' ),
			'meta'                => array(
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				'mcp'         => array( 'public' => true, 'type' => 'tool' ),
			),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires manage_options capability.' );
		}

		return true;
	}

	public static function execute( $input = array() ) {
		$active_plugins = self::get_active_plugin_basenames();
		$plugins        = self::get_active_known_backup_plugins( $active_plugins );

		if ( empty( $plugins ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'backup_plugin_detected' => false,
					'plugin_name'            => null,
					'last_backup'            => null,
					'schedule'               => null,
					'warning'                => 'No known active WordPress backup plugin was detected. Configure and verify a reliable backup solution for this site.',
					'active_known_plugins'   => array(),
				),
			);
		}

		$primary = $plugins[0];

		return array(
			'success' => true,
			'data'    => array(
				'backup_plugin_detected' => true,
				'plugin_name'            => $primary['name'],
				'last_backup'            => $primary['last_backup'],
				'schedule'               => $primary['schedule'],
				'warning'                => null,
				'active_known_plugins'   => $plugins,
			),
		);
	}

	private static function get_active_known_backup_plugins( $active_plugins ) {
		$plugins = array();

		foreach ( self::KNOWN_BACKUP_PLUGINS as $basename => $name ) {
			if ( ! in_array( $basename, $active_plugins, true ) ) {
				continue;
			}

			$plugins[] = array(
				'name'        => $name,
				'basename'    => $basename,
				'last_backup' => self::get_last_backup_for_plugin( $basename ),
				'schedule'    => self::get_schedule_for_plugin( $basename ),
			);
		}

		return $plugins;
	}

	private static function get_last_backup_for_plugin( $basename ) {
		if ( 'updraftplus/updraftplus.php' === $basename ) {
			return self::format_latest_timestamp( get_option( 'updraft_last_backup', null ) );
		}

		if ( 'backwpup/backwpup.php' === $basename ) {
			return self::format_latest_timestamp( get_option( 'backwpup_jobs', array() ) );
		}

		return null;
	}

	private static function get_schedule_for_plugin( $basename ) {
		if ( 'updraftplus/updraftplus.php' !== $basename ) {
			return null;
		}

		$file_schedule     = self::normalize_schedule_value( get_option( 'updraft_interval', null ) );
		$database_schedule = self::normalize_schedule_value( get_option( 'updraft_interval_database', null ) );

		if ( null === $file_schedule && null === $database_schedule ) {
			return null;
		}

		if ( $file_schedule === $database_schedule ) {
			return $file_schedule;
		}

		return sprintf(
			'files: %1$s, database: %2$s',
			null === $file_schedule ? 'not configured' : $file_schedule,
			null === $database_schedule ? 'not configured' : $database_schedule
		);
	}

	private static function normalize_schedule_value( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			return '' === $value ? null : $value;
		}

		if ( is_numeric( $value ) ) {
			return (string) $value;
		}

		return null;
	}

	private static function format_latest_timestamp( $value ) {
		$timestamps = self::extract_timestamps( $value );

		if ( empty( $timestamps ) ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', max( $timestamps ) );
	}

	private static function extract_timestamps( $value ) {
		$timestamps = array();

		if ( is_numeric( $value ) ) {
			$timestamp = self::normalize_timestamp( $value );
			if ( null !== $timestamp ) {
				$timestamps[] = $timestamp;
			}
		} elseif ( is_string( $value ) ) {
			$timestamp = self::normalize_timestamp( $value );
			if ( null !== $timestamp ) {
				$timestamps[] = $timestamp;
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$timestamps = array_merge( $timestamps, self::extract_timestamps( $item ) );
			}
		}

		return $timestamps;
	}

	private static function normalize_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = (int) $value;
			return $timestamp >= 946684800 ? $timestamp : null;
		}

		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : $timestamp;
	}

	private static function get_active_plugin_basenames() {
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		return array_values( array_unique( array_map( 'strval', $active_plugins ) ) );
	}
}
