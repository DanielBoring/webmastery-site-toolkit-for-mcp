<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DestructiveCleanupTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = __DIR__ . '/.cleanup-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0755 );
	}

	protected function tearDown(): void {
		$this->remove_fixture( $this->directory );
	}

	private function remove_fixture( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_fixture( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function run_cleanup( string $mode ): array {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/destructive-cleanup.php', $mode, $this->directory ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error . $output );
		self::assertSame( '', $error );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsBool( $result['summary']['cleanup_complete'] );
		self::assertTrue( $result['foreign_intact'] );
		self::assertContains( 99, $result['posts'] );
		self::assertContains( 99, $result['users'] );
		self::assertSame( array( 'foreign credential' ), $result['credentials'][99] );
		self::assertNotContains( 99, $result['deleted_attachments'] );
		self::assertNotContains( 99, $result['deleted_users'] );
		return $result;
	}

	public static function retained_attachments(): array {
		return array( array( 'metadata' ), array( 'backup' ), array( 'cross-reference' ), array( 'veto' ), array( 'foreign-main' ), array( 'changed-identity' ) );
	}

	/** @dataProvider retained_attachments */
	public function test_refused_attachment_retains_original_files_and_proof_but_safe_cleanup_continues( string $mode ): void {
		$result = $this->run_cleanup( $mode );
		self::assertFalse( $result['summary']['cleanup_complete'] );
		self::assertTrue( $result['summary']['completed'] );
		self::assertGreaterThan( 0, $result['summary']['failed'] );
		self::assertContains( 1, $result['posts'] );
		self::assertTrue( $result['main_intact'] );
		self::assertTrue( $result['orphan_intact'] );
		self::assertTrue( $result['directory'] );
		self::assertTrue( $result['marker_intact'] );
		self::assertNotSame( true, $result['summary']['cleanup']['post 1'] );
		self::assertNotSame( true, $result['summary']['cleanup']['file main.png'] );
		self::assertNotSame( true, $result['summary']['cleanup']['owned upload directory'] );
		self::assertNotContains( 2, $result['posts'] );
		self::assertArrayNotHasKey( 2, $result['meta'] );
		self::assertSame( array(), $result['cron'] );
		self::assertSame( array(), $result['terms'] );
		self::assertSame( array(), $result['term_meta'] );
		self::assertTrue( $result['transport_closed'] );
		self::assertFalse( $result['control'] );
		self::assertSame( 42, $result['current_user'] );
		self::assertContains( 11, $result['users'] );
		self::assertNotContains( 11, $result['deleted_users'] );
		self::assertContains( 12, $result['deleted_users'] );
		self::assertArrayNotHasKey( 11, $result['credentials'] );
		self::assertArrayNotHasKey( 12, $result['credentials'] );
		if ( 'veto' !== $mode ) {
			self::assertSame( array(), $result['deleted_attachments'] );
			self::assertTrue( $result['other_intact'] );
		}
	}

	public function test_success_removes_owned_files_proof_posts_meta_cron_terms_actors_and_credentials(): void {
		$result = $this->run_cleanup( 'success' );
		self::assertTrue( $result['summary']['cleanup_complete'] );
		self::assertTrue( $result['summary']['completed'] );
		self::assertSame( 0, $result['summary']['failed'] );
		foreach ( $result['summary']['cleanup'] as $entry ) {
			self::assertSame( true, $entry );
		}
		self::assertSame( array( 99 ), $result['posts'] );
		self::assertSame( array( 99 ), $result['users'] );
		foreach ( array( 'meta', 'cron', 'terms', 'term_meta' ) as $key ) {
			self::assertSame( array(), $result[ $key ] );
		}
		foreach ( array( 'directory', 'marker_intact', 'main_exists', 'orphan_intact', 'other_intact', 'control' ) as $key ) {
			self::assertFalse( $result[ $key ] );
		}
		self::assertSame( array( 99 => array( 'foreign credential' ) ), $result['credentials'] );
	}

	public static function cleanup_failures(): array {
		return array(
			array( 'post-meta', 'post 2' ), array( 'post-cron', 'post 2' ), array( 'term-meta', 'term 5' ),
			array( 'file-veto', 'file main.png' ), array( 'credentials', 'actor author' ),
			array( 'actor-veto', 'actor author' ), array( 'transport', 'HTTP administrator' ),
			array( 'foreign-control', 'control option' ), array( 'changed-hash', 'attachment references 1' ),
		);
	}

	/** @dataProvider cleanup_failures */
	public function test_each_existing_cleanup_assertion_still_fails_closed( string $mode, string $label ): void {
		$result = $this->run_cleanup( $mode );
		self::assertFalse( $result['summary']['cleanup_complete'] );
		self::assertGreaterThan( 0, $result['summary']['failed'] );
		self::assertIsString( $result['summary']['cleanup'][ $label ] );
		if ( 'foreign-control' === $mode ) {
			self::assertSame( array( 'owner' => 'foreign-run' ), $result['control'] );
		}
		if ( 'changed-hash' === $mode ) {
			self::assertTrue( $result['main_exists'] );
			self::assertTrue( $result['marker_intact'] );
		}
	}

	public static function incomplete_results(): array {
		return array( array( 'nontrue-entry' ), array( 'abort' ) );
	}

	/** @dataProvider incomplete_results */
	public function test_nonboolean_entry_or_interruption_cannot_report_cleanup_success( string $mode ): void {
		$result = $this->run_cleanup( $mode );
		self::assertFalse( $result['summary']['cleanup_complete'] );
		if ( 'abort' === $mode ) {
			self::assertFalse( $result['summary']['completed'] );
			self::assertSame( 'Interrupted final cleanup.', $result['summary']['fatal'] );
		}
	}

	public function test_prior_case_failure_remains_failed_but_successful_cleanup_is_proven(): void {
		$result = $this->run_cleanup( 'prior-failure' );
		self::assertTrue( $result['summary']['cleanup_complete'] );
		self::assertGreaterThan( 0, $result['summary']['failed'] );
		self::assertFalse( $result['directory'] );
		self::assertFalse( $result['main_exists'] );
		self::assertSame( array( 99 ), $result['posts'] );
	}

	public function test_runner_calls_the_tested_cleanup_and_guard_and_fails_closed_on_shutdown(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php' );
		self::assertStringContainsString( "require_once __DIR__ . '/destructive-safety-cleanup.php';", $source );
		self::assertStringContainsString( '$check_file = wstm116_cleanup_file_guard( $owned_uploads, $owned_configuration, $files, $file_hashes, $file_identities );', $source );
		self::assertStringContainsString( 'wstm116_cleanup( $summary, $transports, $owns_control, $run, $posts, $terms, $files, $owned_uploads, $users, $old_user, $check_file );', $source );
		self::assertStringNotContainsString( 'wp_delete_attachment(', $source );
		self::assertStringContainsString( "\$summary['cleanup_complete'] = false;", $source );
		self::assertSame( 2, substr_count( $source, "'cleanup_complete' => false" ) );
	}
}
