<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/destructive-safety-lifecycle.php';
require_once dirname( __DIR__ ) . '/e2e/destructive-safety-evidence.php';

final class DestructiveLifecycleTest extends TestCase {
	private string $directory;
	private string $root;
	private string $lock;
	private string $plugin;
	private string $original;
	private string $token;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/wstm116-lifecycle-' . bin2hex( random_bytes( 8 ) );
		$this->root = $this->directory . '/site';
		$this->lock = $this->directory . '/lock';
		$this->plugin = dirname( __DIR__, 2 );
		$this->token = str_repeat( 'a', 32 );
		mkdir( $this->root . '/wp-content/mu-plugins', 0777, true );
		$this->original = "<?php\r\n// prior bytes\r\ndefine( 'EMPTY_TRASH_DAYS', 17 );\r\n";
		file_put_contents( $this->root . '/wp-config.php', $this->original );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->directory );
	}

	private function lifecycle( ?string $token = null ): Wstm116_Lifecycle {
		return new Wstm116_Lifecycle( $this->root, $this->plugin, $this->lock, $token ?? $this->token );
	}

	public function test_actual_boots_and_exact_restoration(): void {
		$prior_mode = fileperms( $this->root . '/wp-config.php' ) & 0777;
		$stage = $this->lifecycle();
		$stage->acquire();
		self::assertSame( $this->original, file_get_contents( $this->root . '/wp-config.php' ) );
		foreach ( array( 'enabled' => 30, 'disabled' => 0 ) as $mode => $days ) {
			$stage->configure( $mode );
			$process = proc_open( array( PHP_BINARY, '-r', 'require $argv[1]; echo json_encode([EMPTY_TRASH_DAYS,WSTM116_DISPOSABLE_RUNTIME,WSTM116_STAGE_TOKEN]);', $this->root . '/wp-config.php' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
			self::assertSame( array( $days, true, $this->token ), json_decode( $output, true ) );
			self::assertStringContainsString( $this->token, file_get_contents( $this->root . '/wp-content/mu-plugins/wstm116-http.php' ) );
		}
		$stage->restore();
		self::assertSame( $this->original, file_get_contents( $this->root . '/wp-config.php' ) );
		self::assertSame( array(), glob( $this->root . '/wp-content/mu-plugins/*' ) );
		self::assertDirectoryDoesNotExist( $this->lock );
		clearstatcache();
		self::assertSame( $prior_mode, fileperms( $this->root . '/wp-config.php' ) & 0777 );
	}

	public static function collision_types(): array {
		return array( array( 'lock' ), array( 'fixture' ), array( 'configuration' ) );
	}

	/** @dataProvider collision_types */
	public function test_collision_refuses_without_site_mutation( string $type ): void {
		if ( 'lock' === $type ) {
			mkdir( $this->lock );
		} elseif ( 'fixture' === $type ) {
			file_put_contents( $this->root . '/wp-content/mu-plugins/wstm116-http.php', 'unowned' );
		} else {
			file_put_contents( $this->root . '/wp-config.php', '<?php define("EMPTY_TRASH_DAYS", getenv("UNSAFE"));' );
		}
		$before = file_get_contents( $this->root . '/wp-config.php' );
		try {
			$this->lifecycle()->acquire();
			self::fail( 'Collision accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $before, file_get_contents( $this->root . '/wp-config.php' ) );
			self::assertNotEmpty( $error->getMessage() );
		}
	}

	public function test_wrong_owner_cannot_change_or_restore(): void {
		$this->lifecycle()->acquire();
		$this->expectExceptionMessage( 'Stage ownership mismatch.' );
		$this->lifecycle( str_repeat( 'b', 32 ) )->restore();
	}

	public static function changed_paths(): array {
		return array( array( '/wp-config.php' ), array( '/wp-content/mu-plugins/wstm116-http.php' ) );
	}

	/** @dataProvider changed_paths */
	public function test_restoration_failure_retains_backup_and_unowned_bytes( string $path ): void {
		$stage = $this->lifecycle();
		$stage->acquire();
		$stage->configure( 'enabled' );
		$owned = file_get_contents( $this->root . $path );
		file_put_contents( $this->root . $path, 'external edit' );
		try {
			$stage->restore();
			self::fail( 'Foreign bytes overwritten.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'backup retained', $error->getMessage() );
			self::assertSame( 'external edit', file_get_contents( $this->root . $path ) );
			self::assertFileExists( $this->lock . '/state.json' );
		}
		file_put_contents( $this->root . $path, $owned );
		$stage->restore();
		self::assertSame( $this->original, file_get_contents( $this->root . '/wp-config.php' ) );
	}

	public function test_restore_after_acquire_without_configuration(): void {
		$stage = $this->lifecycle();
		$stage->acquire();
		$stage->restore();
		self::assertSame( $this->original, file_get_contents( $this->root . '/wp-config.php' ) );
	}

	public function test_exclusive_evidence_retains_failed_and_successful_wire(): void {
		$path = $this->directory . '/evidence.json';
		$evidence = new Wstm116_Evidence( $path );
		self::assertFalse( json_decode( file_get_contents( $path ), true )['completed'] );
		$evidence->append( array( 'passed' => false, 'wire' => array( 'isError' => true ) ) );
		$evidence->append( array( 'passed' => true, 'wire' => array( 'isError' => false ) ) );
		$evidence->save( array( 'completed' => true, 'failed' => 1 ) );
		self::assertCount( 2, file( $path . '.jsonl' ) );
		self::assertSame( 1, json_decode( file_get_contents( $path ), true )['failed'] );
		unset( $evidence );
		self::assertStringContainsString( '"isError":true', file_get_contents( $path . '.jsonl' ) );
	}

	public static function evidence_collisions(): array {
		return array( array( '' ), array( '.jsonl' ) );
	}

	/** @dataProvider evidence_collisions */
	public function test_evidence_collision_preserves_prior_bytes( string $suffix ): void {
		$path = $this->directory . '/collision.json';
		file_put_contents( $path . $suffix, 'prior evidence' );
		try {
			new Wstm116_Evidence( $path );
			self::fail( 'Evidence collision accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'collision', $error->getMessage() );
			self::assertSame( 'prior evidence', file_get_contents( $path . $suffix ) );
		}
	}

	public static function guarded_helpers(): array {
		return array( array( 'stage' ), array( 'preflight' ) );
	}

	/** @dataProvider guarded_helpers */
	public function test_stage_helpers_refuse_before_filesystem_or_wordpress( string $helper ): void {
		$artifact = $this->directory . '/uncreated.json';
		$process = proc_open( array( PHP_BINARY, $this->plugin . '/tests/e2e/destructive-safety-' . $helper . '.php', $artifact ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), array( 'WSTM116_STAGE_DISPOSABLE' => '' ) ) );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( 'opt-in', $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
		self::assertFileDoesNotExist( $artifact );
	}

	public function test_workflows_retain_failed_and_successful_stage_directories(): void {
		foreach ( array( 'e2e-qa.yml' => 2, 'release-package-qa.yml' => 1 ) as $file => $count ) {
			$text = file_get_contents( $this->plugin . '/.github/workflows/' . $file );
			self::assertSame( $count, preg_match_all( '/name: Upload destructive safety (?:contract|HTTP|package) evidence\\R\\s+if: \\$\\{\\{ always\\(\\) \\}\\}[\\s\\S]*?path: e2e-artifacts\\/destructive-\\*\\/\\R\\s+if-no-files-found: error\\R\\s+retention-days: 7/', $text ) );
		}
	}
}
