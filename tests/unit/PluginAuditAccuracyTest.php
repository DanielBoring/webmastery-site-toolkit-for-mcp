<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WstmAuditAccuracy\Webmastery_MCP_Backup_Status as Backup;
use WstmAuditAccuracy\Webmastery_MCP_Plugins as Plugins;

require_once __DIR__ . '/fixtures/plugin-audit-accuracy-stubs.php';

final class PluginAuditAccuracyTest extends TestCase {
	private string $plugins_dir;

	protected function setUp(): void {
		$this->plugins_dir = \WstmAuditAccuracy\WP_PLUGIN_DIR;
		self::remove( dirname( $this->plugins_dir ) );
		mkdir( $this->plugins_dir, 0777, true );
		$GLOBALS['wstm_audit_accuracy'] = array(
			'options' => array(),
			'wp_version' => '6.8',
			'file_data_reads' => array(),
		);
	}

	protected function tearDown(): void {
		self::remove( dirname( $this->plugins_dir ) );
		unset( $GLOBALS['wstm_audit_accuracy'] );
		parent::tearDown();
	}

	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}
		rmdir( $path );
	}

	private function write( string $relative, string $contents ): void {
		$path = $this->plugins_dir . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $contents );
	}

	private static function audit( string $plugin, array $plugin_data, object $updates ): array {
		$method = new ReflectionMethod( Plugins::class, 'normalize_plugin_audit' );
		$method->setAccessible( true );
		return $method->invoke( null, $plugin, $plugin_data, $updates );
	}

	private static function updates( array $response = array(), $no_update = null ): object {
		$updates = (object) array( 'response' => $response );
		if ( null !== $no_update ) {
			$updates->no_update = $no_update;
		}
		return $updates;
	}

	public function test_pending_update_record_takes_precedence(): void {
		$this->write( 'alpha/alpha.php', "<?php\n/* Plugin Name: Alpha\nTested up to: 5.0 */" );
		$this->write( 'alpha/readme.txt', "=== Alpha ===\nTested up to: 6.1\n" );
		$updates = self::updates(
			array( 'alpha/alpha.php' => (object) array( 'slug' => 'alpha-pending', 'new_version' => '2.0', 'tested' => '6.8', 'requires' => '6.4' ) ),
			array( 'alpha/alpha.php' => (object) array( 'slug' => 'alpha-current', 'tested' => '6.2', 'requires' => '5.0' ) )
		);

		$record = self::audit( 'alpha/alpha.php', array( 'Name' => 'Alpha', 'Version' => '1.0', 'RequiresWP' => '4.0' ), $updates );

		self::assertSame( '6.8', $record['tested_up_to'] );
		self::assertSame( '6.4', $record['requires_wp'] );
		self::assertSame( 'alpha-pending', $record['slug'] );
		self::assertFalse( $record['abandoned'] );
		self::assertSame( array(), $GLOBALS['wstm_audit_accuracy']['file_data_reads'] );
	}

	public function test_no_update_record_supplies_tested_requires_and_slug(): void {
		$this->write( 'beta/beta.php', "<?php\n/* Plugin Name: Beta */" );
		$this->write( 'beta/readme.txt', "=== Beta ===\nTested up to: 6.8\n" );
		foreach ( array( 'object' => true, 'array' => false ) as $label => $as_object ) {
			$entry = array( 'slug' => 'Beta_Slug', 'tested' => '6.2.1', 'requires' => '5.9' );
			$updates = self::updates( array(), array( 'beta/beta.php' => $as_object ? (object) $entry : $entry ) );

			$record = self::audit( 'beta/beta.php', array( 'Name' => 'Beta', 'Version' => '1.0', 'RequiresWP' => '4.0' ), $updates );

			self::assertSame( '6.2.1', $record['tested_up_to'], $label );
			self::assertSame( '5.9', $record['requires_wp'], $label );
			self::assertSame( 'beta_slug', $record['slug'], $label );
			self::assertFalse( $record['update_available'], $label );
			self::assertNull( $record['new_version'], $label );
			self::assertTrue( $record['abandoned'], $label );
		}
	}

	public function test_readme_is_used_when_update_records_lack_tested(): void {
		$this->write( 'gamma/gamma.php', "<?php\n/* Plugin Name: Gamma\nTested up to: 6.8 */" );
		$this->write( 'gamma/README.txt', "=== Gamma ===\nContributors: someone\ntested UP to: 6.1\nStable tag: 1.0\n" );
		$updates = self::updates( array(), array( 'gamma/gamma.php' => (object) array( 'slug' => 'gamma', 'new_version' => '1.0' ) ) );

		$record = self::audit( 'gamma/gamma.php', array( 'Name' => 'Gamma', 'Version' => '1.0' ), $updates );

		self::assertSame( '6.1', $record['tested_up_to'] );
		self::assertTrue( $record['abandoned'] );
		self::assertNull( $record['requires_wp'] );
		self::assertSame( 'gamma', $record['slug'] );
		self::assertSame( array( realpath( $this->plugins_dir . '/gamma/README.txt' ) ), $GLOBALS['wstm_audit_accuracy']['file_data_reads'] );
	}

	public function test_malformed_no_update_entries_are_ignored(): void {
		$this->write( 'delta/delta.php', "<?php\n/* Plugin Name: Delta */" );
		$this->write( 'delta/readme.txt', "Tested up to: 6.0\n" );
		foreach ( array( 'not an array', array( 'delta/delta.php' => 'string' ), array( 'delta/delta.php' => 42 ) ) as $no_update ) {
			$record = self::audit( 'delta/delta.php', array( 'Name' => 'Delta' ), self::updates( array(), $no_update ) );
			self::assertSame( '6.0', $record['tested_up_to'] );
			self::assertSame( 'delta', $record['slug'] );
		}
	}

	public static function invalid_versions(): array {
		return array(
			'markup' => array( '6.4<script>' ),
			'word' => array( 'latest' ),
			'too long' => array( '6.4.1.2.3' ),
			'over length bound' => array( '1234567890123456789.12' ),
			'leading dot' => array( '.6' ),
			'array' => array( array( '6.4' ) ),
			'boolean' => array( true ),
		);
	}

	/** @dataProvider invalid_versions */
	public function test_invalid_version_values_fall_through_to_next_source( $invalid ): void {
		$this->write( 'eps/eps.php', "<?php\n/* Plugin Name: Eps */" );
		$this->write( 'eps/readme.txt', "Tested up to: 6.3\n" );
		$updates = self::updates(
			array( 'eps/eps.php' => (object) array( 'tested' => $invalid, 'tested_up_to' => $invalid ) ),
			array( 'eps/eps.php' => (object) array( 'tested' => $invalid ) )
		);

		self::assertSame( '6.3', self::audit( 'eps/eps.php', array(), $updates )['tested_up_to'] );
	}

	public function test_invalid_readme_value_falls_back_to_main_file_header(): void {
		$this->write( 'zeta/zeta.php', "<?php\n/* Plugin Name: Zeta\nTested up to: 6.7 */" );
		$this->write( 'zeta/readme.txt', "Tested up to: WordPress 6.9\n" );

		self::assertSame( '6.7', self::audit( 'zeta/zeta.php', array(), self::updates() )['tested_up_to'] );
	}

	public function test_single_file_plugin_never_reads_plugins_root_readme(): void {
		$this->write( 'hello.php', "<?php\n/* Plugin Name: Hello */" );
		$this->write( 'readme.txt', "Tested up to: 5.0\n" );

		$record = self::audit( 'hello.php', array( 'Name' => 'Hello' ), self::updates() );

		self::assertNull( $record['tested_up_to'] );
		self::assertFalse( $record['abandoned'] );
		self::assertSame( 'hello', $record['slug'] );
		self::assertSame( array( $this->plugins_dir . '/hello.php' ), $GLOBALS['wstm_audit_accuracy']['file_data_reads'] );
	}

	public function test_readme_outside_plugin_directory_is_ignored(): void {
		$outside = dirname( $this->plugins_dir ) . '/outside';
		mkdir( $outside );
		file_put_contents( $outside . '/readme.txt', "Tested up to: 5.0\n" );
		file_put_contents( $outside . '/eta.php', "<?php\n/* Plugin Name: Eta */" );
		symlink( $outside, $this->plugins_dir . '/eta' );
		$this->write( 'theta/theta.php', "<?php\n/* Plugin Name: Theta */" );
		symlink( $outside . '/readme.txt', $this->plugins_dir . '/theta/readme.txt' );

		self::assertNull( self::audit( 'eta/eta.php', array(), self::updates() )['tested_up_to'] );
		self::assertNull( self::audit( 'theta/theta.php', array(), self::updates() )['tested_up_to'] );
		self::assertNotContains( realpath( $outside . '/readme.txt' ), $GLOBALS['wstm_audit_accuracy']['file_data_reads'] );
	}

	public function test_readme_header_beyond_first_8_kb_is_not_read(): void {
		$this->write( 'iota/iota.php', "<?php\n/* Plugin Name: Iota */" );
		$this->write( 'iota/readme.txt', "=== Iota ===\n" . str_repeat( "Filler line of readme text.\n", 400 ) . "Tested up to: 5.0\n" );

		self::assertNull( self::audit( 'iota/iota.php', array(), self::updates() )['tested_up_to'] );
	}

	public function test_audit_record_keeps_field_names_and_types(): void {
		$this->write( 'kappa/kappa.php', "<?php\n/* Plugin Name: Kappa */" );
		$this->write( 'kappa/readme.txt', "Tested up to: 6.8\n" );

		$record = self::audit( 'kappa/kappa.php', array( 'Name' => 'Kappa', 'Version' => '1.2' ), self::updates() );

		self::assertSame(
			array( 'plugin', 'name', 'slug', 'version', 'active', 'update_available', 'new_version', 'tested_up_to', 'requires_wp', 'abandoned', 'days_since_update', 'critical_update' ),
			array_keys( $record )
		);
		self::assertSame( '6.8', $record['tested_up_to'] );
		self::assertIsInt( $record['days_since_update'] );
	}

	private static function backup( array $active, array $options = array() ): array {
		$GLOBALS['wstm_audit_accuracy']['options'] = array_merge( array( 'active_plugins' => $active ), $options );
		return Backup::execute()['data'];
	}

	public function test_backup_capable_plugin_alone_is_not_reported_as_configured(): void {
		$data = self::backup( array( 'jetpack/jetpack.php' ) );

		self::assertFalse( $data['backup_plugin_detected'] );
		self::assertNull( $data['plugin_name'] );
		self::assertNull( $data['last_backup'] );
		self::assertNull( $data['schedule'] );
		self::assertSame(
			'A backup-capable plugin is active (Jetpack), but backup configuration could not be verified. Confirm that backups are enabled and running, or configure a dedicated backup solution.',
			$data['warning']
		);
		self::assertSame(
			array( array( 'name' => 'Jetpack', 'basename' => 'jetpack/jetpack.php', 'last_backup' => null, 'schedule' => null, 'backup_verified' => false ) ),
			$data['active_known_plugins']
		);
	}

	public function test_multiple_backup_capable_plugins_are_named_in_warning(): void {
		$data = self::backup( array( 'worker/init.php', 'jetpack/jetpack.php' ) );

		self::assertFalse( $data['backup_plugin_detected'] );
		self::assertStringContainsString( '(Jetpack, ManageWP Worker)', $data['warning'] );
		self::assertSame( array( 'jetpack/jetpack.php', 'worker/init.php' ), array_column( $data['active_known_plugins'], 'basename' ) );
		self::assertSame( array( false, false ), array_column( $data['active_known_plugins'], 'backup_verified' ) );
	}

	public function test_dedicated_plugins_sort_ahead_of_backup_capable_ones(): void {
		$data = self::backup( array( 'jetpack/jetpack.php', 'worker/init.php', 'jetpack-backup/jetpack-backup.php' ) );

		self::assertTrue( $data['backup_plugin_detected'] );
		self::assertSame( 'Jetpack VaultPress Backup', $data['plugin_name'] );
		self::assertNull( $data['warning'] );
		self::assertSame(
			array( 'jetpack-backup/jetpack-backup.php', 'jetpack/jetpack.php', 'worker/init.php' ),
			array_column( $data['active_known_plugins'], 'basename' )
		);
		self::assertSame( array( true, false, false ), array_column( $data['active_known_plugins'], 'backup_verified' ) );
	}

	public function test_solid_backups_is_a_dedicated_backup_plugin(): void {
		$data = self::backup( array( 'backupbuddy/backupbuddy.php' ) );

		self::assertTrue( $data['backup_plugin_detected'] );
		self::assertSame( 'Solid Backups (BackupBuddy)', $data['plugin_name'] );
		self::assertNull( $data['warning'] );
		self::assertTrue( $data['active_known_plugins'][0]['backup_verified'] );
	}

	public function test_backwpup_reports_latest_job_lastrun_only(): void {
		$jobs = array(
			1 => array( 'jobid' => 1, 'name' => 'monday', 'lastrun' => 1747191600, 'cronnextrun' => 1893456000, 'archivesize' => 4000000000 ),
			2 => array( 'jobid' => 2, 'name' => 'next friday', 'lastrun' => '1747278000', 'logfile' => '2030-01-01' ),
			3 => array( 'jobid' => 3, 'name' => 'never run', 'lastrun' => 0 ),
			4 => array( 'jobid' => 4, 'lastrun' => 'tomorrow' ),
			5 => array( 'jobid' => 5, 'lastrun' => 1.9e9 ),
			6 => 1893456000,
			'settings' => array( 'timeout' => 1893456000 ),
		);

		$data = self::backup( array( 'backwpup/backwpup.php' ), array( 'backwpup_jobs' => $jobs ) );

		self::assertTrue( $data['backup_plugin_detected'] );
		self::assertSame( '2025-05-15T03:00:00Z', $data['last_backup'] );
		self::assertTrue( $data['active_known_plugins'][0]['backup_verified'] );
	}

	public function test_backwpup_without_usable_lastrun_reports_null(): void {
		foreach ( array( null, 'monday', 1747191600, array(), array( array( 'name' => 'monday' ) ), array( array( 'lastrun' => 'monday' ) ) ) as $jobs ) {
			$data = self::backup( array( 'backwpup/backwpup.php' ), array( 'backwpup_jobs' => $jobs ) );
			self::assertNull( $data['last_backup'] );
		}
	}

	public function test_updraftplus_last_backup_is_unchanged(): void {
		$data = self::backup(
			array( 'updraftplus/updraftplus.php' ),
			array( 'updraft_last_backup' => array( 'backup_time' => 1747278000, 'backup_nonce' => '999999999999' ), 'updraft_interval' => 'weekly', 'updraft_interval_database' => 'weekly' )
		);

		self::assertSame( '2025-05-15T03:00:00Z', $data['last_backup'] );
		self::assertSame( 'weekly', $data['schedule'] );
	}
}
