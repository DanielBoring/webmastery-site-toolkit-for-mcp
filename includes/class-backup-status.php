<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Backup_Status {

	private const KNOWN_BACKUP_PLUGINS = [
		'updraftplus/updraftplus.php'                         => 'UpdraftPlus',
		'backwpup/backwpup.php'                               => 'BackWPup',
		'duplicator/duplicator.php'                           => 'Duplicator',
		'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
		'blogvault-real-time-backup/blogvault.php'            => 'BlogVault',
		'wpvivid-backuprestore/wpvivid-backuprestore.php'     => 'WPvivid Backup & Migration',
		'vaultpress/vaultpress.php'                           => 'VaultPress',
		'jetpack-backup/jetpack-backup.php'                   => 'Jetpack VaultPress Backup',
		'backupbuddy/backupbuddy.php'                         => 'Solid Backups (BackupBuddy)',
	];

	// Plugins that can run backups but are often active for other features.
	private const BACKUP_CAPABLE_PLUGINS = [
		'jetpack/jetpack.php' => 'Jetpack',
		'worker/init.php'     => 'ManageWP Worker',
	];

	// WPvivid's own recurrence list (WPvivid_Schedule::$schedule_type), mapped to UpdraftPlus-style names.
	private const WPVIVID_SCHEDULES = [
		'wpvivid_12hours'     => 'every12hours',
		'twicedaily'          => 'every12hours',
		'wpvivid_daily'       => 'daily',
		'daily'               => 'daily',
		'onceday'             => 'daily',
		'wpvivid_weekly'      => 'weekly',
		'weekly'              => 'weekly',
		'wpvivid_fortnightly' => 'fortnightly',
		'fortnightly'         => 'fortnightly',
		'wpvivid_monthly'     => 'monthly',
		'monthly'             => 'monthly',
		'montly'              => 'monthly',
	];

	/**
	 * Register the backup-status ability.
	 *
	 * @return void
	 */
	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/backup-status', [
			'label'               => 'Backup Status',
			'description'         => 'Detect active known WordPress backup plugins and report accessible last-backup and schedule details.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Permission callback requiring manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public static function permission() {
		return Webmastery_MCP_Permissions::check( 'manage_options' );
	}

	/**
	 * Report active known backup plugins with last-backup and schedule details.
	 *
	 * @param array<string, mixed> $input Ability input (unused).
	 * @return array{success: true, data: array<string, mixed>}
	 */
	public static function execute( $input = [] ) {
		$active_plugins = self::get_active_plugin_basenames();
		$plugins        = self::get_active_known_backup_plugins( $active_plugins );

		if ( empty( $plugins ) ) {
			return [
				'success' => true,
				'data'    => [
					'backup_plugin_detected' => false,
					'plugin_name'            => null,
					'last_backup'            => null,
					'schedule'               => null,
					'warning'                => 'No known active WordPress backup plugin was detected. Configure and verify a reliable backup solution for this site.',
					'active_known_plugins'   => [],
				],
			];
		}

		$primary = $plugins[0];

		if ( ! $primary['backup_verified'] ) {
			return [
				'success' => true,
				'data'    => [
					'backup_plugin_detected' => false,
					'plugin_name'            => null,
					'last_backup'            => null,
					'schedule'               => null,
					'warning'                => sprintf(
						'A backup-capable plugin is active (%s), but backup configuration could not be verified. Confirm that backups are enabled and running, or configure a dedicated backup solution.',
						implode( ', ', array_column( $plugins, 'name' ) )
					),
					'active_known_plugins'   => $plugins,
				],
			];
		}

		return [
			'success' => true,
			'data'    => [
				'backup_plugin_detected' => true,
				'plugin_name'            => $primary['name'],
				'last_backup'            => $primary['last_backup'],
				'schedule'               => $primary['schedule'],
				'warning'                => null,
				'active_known_plugins'   => $plugins,
			],
		];
	}

	/**
	 * Active known backup plugins, dedicated plugins first.
	 *
	 * @param list<string> $active_plugins Active plugin basenames.
	 * @return list<array{name: string, basename: string, last_backup: string|null, schedule: string|null, backup_verified: bool}>
	 */
	private static function get_active_known_backup_plugins( $active_plugins ) {
		$plugins = [];
		$groups  = [
			[ self::KNOWN_BACKUP_PLUGINS, true ],
			[ self::BACKUP_CAPABLE_PLUGINS, false ],
		];

		// Dedicated backup plugins are listed first so one of them becomes the primary.
		foreach ( $groups as [ $known_plugins, $verified ] ) {
			foreach ( $known_plugins as $basename => $name ) {
				if ( ! in_array( $basename, $active_plugins, true ) ) {
					continue;
				}

				$plugins[] = [
					'name'            => $name,
					'basename'        => $basename,
					'last_backup'     => self::get_last_backup_for_plugin( $basename ),
					'schedule'        => self::get_schedule_for_plugin( $basename ),
					'backup_verified' => $verified,
				];
			}
		}

		return $plugins;
	}

	/**
	 * Last backup time for a plugin whose data is readable.
	 *
	 * @param string $basename Plugin basename.
	 * @return string|null ISO 8601 time, or null when unknown.
	 */
	private static function get_last_backup_for_plugin( $basename ) {
		if ( 'updraftplus/updraftplus.php' === $basename ) {
			return self::get_updraftplus_last_backup();
		}

		if ( 'backwpup/backwpup.php' === $basename ) {
			return self::get_backwpup_last_backup();
		}

		if ( 'backupbuddy/backupbuddy.php' === $basename ) {
			return self::get_solid_backups_last_backup();
		}

		return null;
	}

	/**
	 * Solid Backups (BackupBuddy) keeps its settings in the `pb_backupbuddy` option and sets
	 * `last_backup_finish` (microtime float, 0 = never) only after a backup completes without failing.  *
	 *
	 * @return string|null ISO 8601 time of the last completed backup, or null when unknown.
	 */
	private static function get_solid_backups_last_backup() {
		$options = get_option( 'pb_backupbuddy', null );
		if ( ! is_array( $options ) || ! array_key_exists( 'last_backup_finish', $options ) ) {
			return null;
		}

		$finish = $options['last_backup_finish'];
		if ( is_float( $finish ) ) {
			$finish = is_finite( $finish ) && $finish >= 0 && $finish < 1e12 ? (int) floor( $finish ) : null;
		} elseif ( is_string( $finish ) ) {
			$finish = 1 === preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/D', $finish ) ? (int) $finish : null;
		}

		if ( ! is_int( $finish ) ) {
			return null;
		}

		$timestamp = self::normalize_timestamp( $finish );
		return null === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Latest BackWPup job run time.
	 *
	 * @return string|null ISO 8601 time, or null when unknown.
	 */
	private static function get_backwpup_last_backup() {
		$jobs = get_option( 'backwpup_jobs', [] );
		if ( ! is_array( $jobs ) ) {
			return null;
		}

		$latest = null;
		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) || ! isset( $job['lastrun'] ) ) {
				continue;
			}

			$lastrun = $job['lastrun'];
			if ( ! is_int( $lastrun ) && ! ( is_string( $lastrun ) && 1 === preg_match( '/^\d{1,12}$/D', $lastrun ) ) ) {
				continue;
			}

			$timestamp = self::normalize_timestamp( $lastrun );
			if ( null !== $timestamp && ( null === $latest || $timestamp > $latest ) ) {
				$latest = $timestamp;
			}
		}

		return null === $latest ? null : gmdate( 'Y-m-d\TH:i:s\Z', $latest );
	}

	/**
	 * UpdraftPlus last backup time.
	 *
	 * @return string|null ISO 8601 time, or null when unknown.
	 */
	private static function get_updraftplus_last_backup() {
		$last_backup = get_option( 'updraft_last_backup', null );

		if ( is_array( $last_backup ) ) {
			return array_key_exists( 'backup_time', $last_backup )
				? self::format_latest_timestamp( $last_backup['backup_time'] )
				: null;
		}

		return self::format_latest_timestamp( $last_backup );
	}

	/**
	 * Backup schedule for a plugin whose settings are readable.
	 *
	 * @param string $basename Plugin basename.
	 * @return string|null Schedule description, or null when unknown.
	 */
	private static function get_schedule_for_plugin( $basename ) {
		if ( 'updraftplus/updraftplus.php' === $basename ) {
			return self::get_updraftplus_schedule();
		}

		if ( 'wpvivid-backuprestore/wpvivid-backuprestore.php' === $basename ) {
			return self::get_wpvivid_schedule();
		}

		return null;
	}

	/**
	 * WPvivid saves its schedule form as `wpvivid_schedule_setting`: `enable` ('1' when on) and
	 * `type` (a recurrence name). A disabled schedule means backups run only manually.  *
	 *
	 * @return string|null Schedule name, 'manual' when disabled, or null when unknown.
	 */
	private static function get_wpvivid_schedule() {
		$setting = get_option( 'wpvivid_schedule_setting', null );
		if ( ! is_array( $setting ) || ! array_key_exists( 'enable', $setting ) ) {
			return null;
		}

		$enable = $setting['enable'];
		if ( in_array( $enable, [ false, 0, '0', '' ], true ) ) {
			return 'manual';
		}

		if ( ! in_array( $enable, [ true, 1, '1' ], true ) || ! isset( $setting['type'] ) || ! is_string( $setting['type'] ) ) {
			return null;
		}

		return self::WPVIVID_SCHEDULES[ $setting['type'] ] ?? null;
	}

	/**
	 * UpdraftPlus file and database schedule summary.
	 *
	 * @return string|null Schedule description, or null when none is configured.
	 */
	private static function get_updraftplus_schedule() {
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

	/**
	 * Trimmed schedule name from a stored option value.
	 *
	 * @param mixed $value Stored option value.
	 * @return string|null
	 */
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

	/**
	 * Latest timestamp found in a stored value, formatted as ISO 8601 UTC.
	 *
	 * @param mixed $value Stored option value, possibly nested.
	 * @return string|null
	 */
	private static function format_latest_timestamp( $value ) {
		$timestamps = self::extract_timestamps( $value );

		if ( empty( $timestamps ) ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', max( $timestamps ) );
	}

	/**
	 * Collect every plausible Unix timestamp from a possibly nested value.
	 *
	 * @param mixed $value Stored option value.
	 * @return list<int>
	 */
	private static function extract_timestamps( $value ) {
		$timestamps = [];

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

	/**
	 * Unix timestamp from a stored value, or null when it is not a plausible time.
	 *
	 * @param mixed $value Stored option value.
	 * @return int|null
	 */
	private static function normalize_timestamp( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = (int) $value;
			return $timestamp >= 946684800 ? $timestamp : null;
		}

		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Basenames of the active plugins.
	 *
	 * @return list<string>
	 */
	private static function get_active_plugin_basenames() {
		return Webmastery_MCP_Plugins::active_basenames();
	}
}
