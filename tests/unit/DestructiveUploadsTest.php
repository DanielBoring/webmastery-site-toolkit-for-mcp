<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/destructive-safety-uploads.php';

final class DestructiveUploadsTest extends TestCase {
	private string $directory;
	private string $root;
	private string $path;
	private string $token;
	private int $owner = 1000;
	private array $chowns = array();

	protected function setUp(): void {
		// Scratch data stays inside this checkout, never a machine-wide temp folder.
		$this->directory = __DIR__ . '/.uploads-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0755 );
		$this->root = $this->directory . '/uploads';
		mkdir( $this->root, 0755 );
		$this->root = realpath( $this->root );
		$this->token = str_repeat( 'a', 32 );
		$this->path = $this->root . '/wstm116-' . $this->token;
	}

	protected function tearDown(): void {
		$this->remove_fixture( $this->directory );
	}

	private function remove_fixture( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
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

	private function uploads( $uid = 33, ?callable $chown = null ): Wstm116_Uploads {
		return new Wstm116_Uploads(
			$this->root, 'https://example.test/uploads', $this->token, $uid,
			function ( string $path ): int {
				self::assertSame( $this->path, $path );
				return $this->owner;
			},
			$chown ?? function ( string $path, int $uid ): bool {
				$this->chowns[] = array( $path, $uid );
				$this->owner = $uid;
				return true;
			}
		);
	}

	private function configuration(): array {
		return array(
			'basedir' => $this->root, 'baseurl' => 'https://example.test/uploads',
			'path' => $this->root . '/2026/09', 'url' => 'https://example.test/uploads/2026/09',
			'subdir' => '/2026/09', 'error' => false, 'custom' => 'preserved',
		);
	}

	private function failure( callable $operation, string $message ): void {
		try {
			$operation();
			self::fail( 'Unsafe operation unexpectedly succeeded.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( $message, $error->getMessage() );
		}
	}

	public function test_only_new_directory_is_chowned_and_filter_preserves_bases(): void {
		mkdir( $this->root . '/2026', 0755 );
		file_put_contents( $this->root . '/prior.png', 'untouched' );
		$prior = array( fileowner( $this->root ), fileperms( $this->root ), fileowner( $this->root . '/2026' ), fileperms( $this->root . '/2026' ) );
		$uploads = $this->uploads();
		$uploads->acquire();
		self::assertSame( array( array( $this->path, 33 ) ), $this->chowns );
		$before = $this->configuration();
		$after = $uploads->filter( $before );
		self::assertSame( $this->path, $after['path'] );
		self::assertSame( '/wstm116-' . $this->token, $after['subdir'] );
		self::assertSame( $before['baseurl'] . $after['subdir'], $after['url'] );
		foreach ( array( 'basedir', 'baseurl', 'error', 'custom' ) as $key ) {
			self::assertSame( $before[ $key ], $after[ $key ] );
		}
		$uploads->finalize();
		clearstatcache();
		self::assertDirectoryDoesNotExist( $this->path );
		self::assertSame( $prior, array( fileowner( $this->root ), fileperms( $this->root ), fileowner( $this->root . '/2026' ), fileperms( $this->root . '/2026' ) ) );
		self::assertSame( 'untouched', file_get_contents( $this->root . '/prior.png' ) );
	}

	public function test_correct_owner_is_verified_without_chown(): void {
		$uploads = $this->uploads( 1000 );
		$uploads->acquire();
		self::assertSame( array(), $this->chowns );
		$uploads->assert_owned();
		$uploads->finalize();
	}

	public function test_default_owner_reader_accepts_actual_owner_without_chown(): void {
		$uploads = new Wstm116_Uploads( $this->root, 'https://example.test/uploads', $this->token, fileowner( $this->root ), null, static function (): bool {
			self::fail( 'Correct native owner must not be changed.' );
		} );
		$uploads->acquire();
		$uploads->finalize();
		self::assertDirectoryDoesNotExist( $this->path );
	}

	public function test_failed_chown_retains_proof_but_allows_explicit_empty_cleanup(): void {
		$uploads = $this->uploads( 33, static function (): bool { return false; } );
		$this->failure( array( $uploads, 'acquire' ), 'Cannot assign' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		$this->failure( array( $uploads, 'acquire' ), 'already acquired' );
		$this->failure( function () use ( $uploads ): void { $uploads->filter( $this->configuration() ); }, 'not ready' );
		$uploads->assert_owned();
		$uploads->finalize();
		self::assertDirectoryDoesNotExist( $this->path );
	}

	public function test_chown_success_without_requested_owner_fails_closed(): void {
		$uploads = $this->uploads( 33, static function (): bool { return true; } );
		$this->failure( array( $uploads, 'acquire' ), 'verification failed' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		$uploads->finalize();
	}

	public function test_wrong_owner_after_chown_is_not_claimed_or_cleaned(): void {
		$uploads = $this->uploads( 33, function (): bool {
			$this->owner = 34;
			return true;
		} );
		$this->failure( array( $uploads, 'acquire' ), 'verification failed' );
		$this->failure( array( $uploads, 'finalize' ), 'ownership changed' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
	}

	public static function invalid_uids(): array {
		return array( array( -1 ), array( '33' ), array( 33.0 ), array( true ), array( null ) );
	}

	/** @dataProvider invalid_uids */
	public function test_invalid_uid_never_creates_directory( $uid ): void {
		$this->failure( function () use ( $uid ): void { $this->uploads( $uid ); }, 'non-negative integer' );
		self::assertDirectoryDoesNotExist( $this->path );
	}

	public function test_zero_uid_is_valid(): void {
		$uploads = $this->uploads( 0 );
		$uploads->acquire();
		self::assertSame( array( array( $this->path, 0 ) ), $this->chowns );
		$uploads->finalize();
	}

	public static function invalid_tokens(): array {
		return array( array( '' ), array( '../escape' ), array( str_repeat( 'A', 32 ) ), array( str_repeat( 'a', 31 ) ), array( str_repeat( 'a', 32 ) . "\n" ) );
	}

	/** @dataProvider invalid_tokens */
	public function test_invalid_token_never_creates_directory( string $token ): void {
		$this->token = $token;
		$this->failure( function (): void { $this->uploads(); }, 'token' );
		self::assertSame( array( '.', '..' ), scandir( $this->root ) );
	}

	public static function collision_types(): array {
		return array( array( 'directory' ), array( 'file' ) );
	}

	/** @dataProvider collision_types */
	public function test_collisions_do_not_claim_or_mutate_existing_paths( string $type ): void {
		'directory' === $type ? mkdir( $this->path, 0755 ) : file_put_contents( $this->path, 'foreign' );
		$before = lstat( $this->path );
		$uploads = $this->uploads();
		$this->failure( array( $uploads, 'acquire' ), 'collision' );
		$this->failure( array( $uploads, 'finalize' ), 'No complete' );
		clearstatcache();
		self::assertSame( $before, lstat( $this->path ) );
		self::assertSame( array(), $this->chowns );
	}

	public function test_second_instance_cannot_adopt_first_instance_directory(): void {
		$first = $this->uploads();
		$first->acquire();
		$second = $this->uploads();
		$this->failure( array( $second, 'acquire' ), 'collision' );
		$this->failure( array( $second, 'finalize' ), 'No complete' );
		$first->finalize();
	}

	public static function foreign_configurations(): array {
		return array( array( 'basedir', '/foreign' ), array( 'baseurl', 'https://foreign.test/uploads' ), array( 'error', 'Original error' ) );
	}

	/** @dataProvider foreign_configurations */
	public function test_filter_rejects_foreign_or_failed_configuration( string $key, string $value ): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$config = $this->configuration();
		$config[ $key ] = $value;
		$this->failure( static function () use ( $uploads, $config ): void { $uploads->filter( $config ); }, 'refusing seeding' );
		$uploads->finalize();
	}

	public function test_finalization_never_deletes_known_or_foreign_files_or_subdirectories(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		file_put_contents( $this->path . '/known.png', 'known' );
		file_put_contents( $this->path . '/foreign.png', 'foreign' );
		mkdir( $this->path . '/foreign-directory', 0755 );
		$this->failure( array( $uploads, 'finalize' ), 'refusing recursive cleanup' );
		self::assertSame( 'known', file_get_contents( $this->path . '/known.png' ) );
		self::assertSame( 'foreign', file_get_contents( $this->path . '/foreign.png' ) );
		self::assertDirectoryExists( $this->path . '/foreign-directory' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		// The runner, not this helper, removes files after its own ownership checks.
		unlink( $this->path . '/known.png' );
		$this->failure( array( $uploads, 'finalize' ), 'refusing recursive cleanup' );
		unlink( $this->path . '/foreign.png' );
		rmdir( $this->path . '/foreign-directory' );
		$uploads->finalize();
	}

	public function test_changed_directory_owner_retains_evidence(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$this->owner = 99;
		$this->failure( array( $uploads, 'finalize' ), 'ownership changed' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
	}

	public function test_changed_marker_retains_evidence(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		file_put_contents( $this->path . '/.wstm116-owner', 'foreign proof' );
		$this->failure( array( $uploads, 'finalize' ), 'marker changed' );
		self::assertSame( 'foreign proof', file_get_contents( $this->path . '/.wstm116-owner' ) );
	}

	public function test_replaced_directory_is_not_adopted(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		rename( $this->path, $this->path . '-retained' );
		mkdir( $this->path, 0755 );
		$this->failure( array( $uploads, 'finalize' ), 'changed' );
		self::assertDirectoryExists( $this->path );
		self::assertFileExists( $this->path . '-retained/.wstm116-owner' );
	}

	public function test_missing_marker_retains_directory(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		unlink( $this->path . '/.wstm116-owner' );
		$this->failure( array( $uploads, 'finalize' ), 'Missing or symlinked' );
		self::assertDirectoryExists( $this->path );
	}

	public function test_unreadable_owner_fails_before_chown_without_deleting_directory(): void {
		$uploads = new Wstm116_Uploads( $this->root, 'https://example.test/uploads', $this->token, 33, static function () { return false; }, static function (): bool {
			self::fail( 'Unknown owner must not be changed.' );
		} );
		$this->failure( array( $uploads, 'acquire' ), 'Cannot determine' );
		$this->failure( array( $uploads, 'finalize' ), 'No complete' );
		self::assertDirectoryExists( $this->path );
	}

	public function test_root_must_exist_without_creating_parents(): void {
		$missing = $this->root . '/missing/child';
		$this->failure( function () use ( $missing ): void {
			new Wstm116_Uploads( $missing, 'https://example.test/uploads', $this->token, 33 );
		}, 'resolved real path' );
		self::assertDirectoryDoesNotExist( $this->root . '/missing' );
	}

	public function test_changed_directory_mode_is_not_repaired_or_deleted(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows directory stat mode does not model POSIX permissions.' );
		}
		$uploads = $this->uploads();
		$uploads->acquire();
		chmod( $this->path, 0700 );
		$this->failure( array( $uploads, 'finalize' ), 'ownership changed' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		clearstatcache();
		self::assertSame( 0700, fileperms( $this->path ) & 0777 );
	}

	public function test_changed_root_mode_is_not_repaired_or_deleted(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows directory stat mode does not model POSIX permissions.' );
		}
		$uploads = $this->uploads();
		$uploads->acquire();
		chmod( $this->root, 0700 );
		$this->failure( array( $uploads, 'finalize' ), 'basedir identity changed' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		clearstatcache();
		self::assertSame( 0700, fileperms( $this->root ) & 0777 );
	}

	private function symlink_or_skip( string $target, string $link ): void {
		if ( ! @symlink( $target, $link ) ) {
			self::markTestSkipped( 'Host does not permit isolated filesystem symlink creation.' );
		}
	}

	public function test_symlink_collision_is_not_followed(): void {
		$this->symlink_or_skip( $this->root . '/absent', $this->path );
		$uploads = $this->uploads();
		$this->failure( array( $uploads, 'acquire' ), 'collision' );
		self::assertTrue( is_link( $this->path ) );
		self::assertSame( array(), $this->chowns );
	}

	public function test_symlink_root_is_refused(): void {
		$alias = $this->directory . '/alias';
		$this->symlink_or_skip( $this->root, $alias );
		$this->failure( function () use ( $alias ): void {
			new Wstm116_Uploads( $alias, 'https://example.test/uploads', $this->token, 33 );
		}, 'resolved real path' );
		self::assertSame( array( '.', '..' ), scandir( $this->root ) );
	}

	public function test_symlink_in_owned_directory_is_never_followed_or_deleted(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$foreign = $this->directory . '/foreign';
		file_put_contents( $foreign, 'retain' );
		$this->symlink_or_skip( $foreign, $this->path . '/link' );
		$this->failure( array( $uploads, 'finalize' ), 'refusing recursive cleanup' );
		self::assertSame( 'retain', file_get_contents( $foreign ) );
		self::assertTrue( is_link( $this->path . '/link' ) );
	}

	public function test_symlink_replacement_directory_is_not_followed(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		rename( $this->path, $this->path . '-retained' );
		$this->symlink_or_skip( $this->path . '-retained', $this->path );
		$this->failure( array( $uploads, 'finalize' ), 'Missing or symlinked' );
		self::assertTrue( is_link( $this->path ) );
		self::assertFileExists( $this->path . '-retained/.wstm116-owner' );
	}

	public function test_symlink_replacement_marker_is_not_followed(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$marker = $this->path . '/.wstm116-owner';
		$foreign = $this->directory . '/foreign-proof';
		rename( $marker, $foreign );
		$this->symlink_or_skip( $foreign, $marker );
		$this->failure( array( $uploads, 'finalize' ), 'Missing or symlinked' );
		self::assertTrue( is_link( $marker ) );
		self::assertFileExists( $foreign );
	}

	public function test_filter_cannot_be_used_before_acquire_or_after_finalization(): void {
		$uploads = $this->uploads();
		$this->failure( function () use ( $uploads ): void { $uploads->filter( $this->configuration() ); }, 'not ready' );
		$uploads->acquire();
		$this->failure( array( $uploads, 'acquire' ), 'already acquired' );
		$uploads->finalize();
		$this->failure( function () use ( $uploads ): void { $uploads->filter( $this->configuration() ); }, 'not ready' );
		$this->failure( array( $uploads, 'finalize' ), 'No complete' );
	}

	public static function seed_outcomes(): array {
		return array( array( false ), array( true ) );
	}

	/** @dataProvider seed_outcomes */
	public function test_seed_restores_exact_registry_before_actor_and_ability_calls( bool $throws ): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$unrelated = static function ( array $value ): array { return $value; };
		$registry = array( 'upload_dir' => array( $unrelated ), 'other_hook' => array( $unrelated ) );
		$before = $registry;
		$events = array();
		$expected = array( $uploads, 'filter' );
		$add = static function ( string $hook, array $callback ) use ( &$registry, &$events, $expected ): bool {
			self::assertSame( 'upload_dir', $hook );
			self::assertSame( $expected, $callback );
			$registry[ $hook ][] = $callback;
			$events[] = 'add';
			return true;
		};
		$remove = static function ( string $hook, array $callback ) use ( &$registry, &$events, $expected ): bool {
			self::assertSame( 'upload_dir', $hook );
			self::assertSame( $expected, $callback );
			self::assertSame( $callback, $registry[ $hook ][1] );
			unset( $registry[ $hook ][1] );
			$events[] = 'remove';
			return true;
		};
		$failure = new LogicException( 'Seed failed.' );
		$result = new stdClass();
		try {
			$actual = $uploads->seed( function () use ( &$registry, &$events, $throws, $failure, $result ): object {
				$events[] = 'seed';
				$config = $this->configuration();
				foreach ( $registry['upload_dir'] as $filter ) {
					$config = $filter( $config );
				}
				self::assertSame( $this->path, $config['path'] );
				if ( $throws ) {
					throw $failure;
				}
				return $result;
			}, $add, $remove );
			self::assertFalse( $throws );
			self::assertSame( $result, $actual );
		} catch ( LogicException $error ) {
			self::assertTrue( $throws );
			self::assertSame( $failure, $error );
		}
		self::assertSame( $before, $registry );
		// Simulated runner actions must observe restoration on either exit path.
		foreach ( array( 'actor', 'ability' ) as $action ) {
			self::assertNotContains( $expected, $registry['upload_dir'] );
			$events[] = $action;
		}
		self::assertSame( array( 'add', 'seed', 'remove', 'actor', 'ability' ), $events );
		$uploads->finalize();
	}

	public function test_seed_checks_readiness_and_ownership_before_touching_registry(): void {
		$uploads = $this->uploads();
		$unused = static function (): bool {
			self::fail( 'Invalid ownership must not touch the registry or run seed.' );
		};
		$this->failure( static function () use ( $uploads, $unused ): void { $uploads->seed( $unused, $unused, $unused ); }, 'not ready' );
		$uploads->acquire();
		$this->owner = 99;
		$this->failure( static function () use ( $uploads, $unused ): void { $uploads->seed( $unused, $unused, $unused ); }, 'ownership changed' );
		$this->owner = 33;
		$uploads->finalize();
		$this->failure( static function () use ( $uploads, $unused ): void { $uploads->seed( $unused, $unused, $unused ); }, 'not ready' );
	}

	public function test_nested_seeding_and_finalization_cannot_remove_outer_filter(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$registered = false;
		$add = static function () use ( &$registered ): bool { $registered = true; return true; };
		$remove = static function () use ( &$registered ): bool { $registered = false; return true; };
		$uploads->seed( function () use ( $uploads, $add, $remove, &$registered ): void {
			$this->failure( static function () use ( $uploads, $add, $remove ): void { $uploads->seed( static function (): void {}, $add, $remove ); }, 'Nested' );
			$this->failure( array( $uploads, 'finalize' ), 'while the seeding filter is registered' );
			self::assertTrue( $registered );
		}, $add, $remove );
		self::assertFalse( $registered );
		$uploads->finalize();
	}

	public function test_registration_exception_still_removes_exact_callback(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$registered = null;
		$this->failure( static function () use ( $uploads, &$registered ): void {
			$uploads->seed(
				static function (): void { self::fail( 'Failed registration must not run seed.' ); },
				static function ( string $hook, array $callback ) use ( &$registered ): bool {
					$registered = $callback;
					throw new RuntimeException( 'Registration failed after insertion.' );
				},
				static function ( string $hook, array $callback ) use ( &$registered ): bool {
					self::assertSame( $registered, $callback );
					$registered = null;
					return true;
				}
			);
		}, 'Registration failed after insertion' );
		self::assertNull( $registered );
		$uploads->finalize();
	}

	public function test_failed_filter_removal_is_explicit_not_success_shaped(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$this->failure( static function () use ( $uploads ): void {
			$uploads->seed( static function (): string { return 'seeded'; }, static function (): bool { return true; }, static function (): bool { return false; } );
		}, 'Cannot remove scoped upload filter' );
		$this->failure( array( $uploads, 'finalize' ), 'while the seeding filter is registered' );
		$this->failure( function () use ( $uploads ): void { $uploads->filter( $this->configuration() ); }, 'not ready' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
	}

	public function test_directory_accessor_does_not_create_or_claim_ownership(): void {
		$uploads = $this->uploads();
		self::assertSame( $this->path, $uploads->directory() );
		self::assertDirectoryDoesNotExist( $uploads->directory() );
		$this->failure( function () use ( $uploads ): void { $uploads->validate_known_file( $this->path . '/known.png', true ); }, 'No complete' );
	}

	public function test_known_file_scope_guard_is_read_only_and_allows_explicit_absence(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$file = $this->path . '/known.png';
		file_put_contents( $file, 'fixture' );
		$uploads->validate_known_file( $file );
		self::assertSame( 'fixture', file_get_contents( $file ) );
		unlink( $file );
		$this->failure( static function () use ( $uploads, $file ): void { $uploads->validate_known_file( $file ); }, 'missing' );
		$uploads->validate_known_file( $file, true );
		$this->owner = 99;
		$this->failure( static function () use ( $uploads, $file ): void { $uploads->validate_known_file( $file, true ); }, 'ownership changed' );
		$this->owner = 33;
		$uploads->finalize();
	}

	public static function unsafe_file_paths(): array {
		return array(
			array( '/../foreign.png' ), array( '/nested/file.png' ), array( '/.wstm116-owner' ),
			array( '/known.png:stream' ), array( '/known.png.' ), array( '/known.png/' ),
			array( '/NUL' ), array( '/com1.png' ),
			array( '/known.png' . "\0" ), array( '-sibling/file.png' ), array( '' ),
		);
	}

	/** @dataProvider unsafe_file_paths */
	public function test_known_file_scope_rejects_traversal_aliases_and_marker( string $suffix ): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$this->failure( function () use ( $uploads, $suffix ): void { $uploads->validate_known_file( $this->path . $suffix, true ); }, 'Known upload file' );
		self::assertFileExists( $this->path . '/.wstm116-owner' );
		$uploads->finalize();
	}

	public function test_known_file_scope_rejects_directories_and_symlinks(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$path = $this->path . '/nested';
		mkdir( $path, 0755 );
		$this->failure( static function () use ( $uploads, $path ): void { $uploads->validate_known_file( $path ); }, 'single-link regular file' );
		rmdir( $path );
		$this->symlink_or_skip( $this->root . '/missing', $path );
		$this->failure( static function () use ( $uploads, $path ): void { $uploads->validate_known_file( $path, true ); }, 'single-link regular file' );
		self::assertTrue( is_link( $path ) );
	}

	public function test_known_file_scope_rejects_hardlinks(): void {
		$uploads = $this->uploads();
		$uploads->acquire();
		$foreign = $this->root . '/foreign.png';
		$path = $this->path . '/linked.png';
		file_put_contents( $foreign, 'foreign bytes' );
		if ( ! @link( $foreign, $path ) ) {
			self::markTestSkipped( 'Host does not permit isolated hardlink creation.' );
		}
		$this->failure( static function () use ( $uploads, $path ): void { $uploads->validate_known_file( $path ); }, 'single-link regular file' );
		self::assertSame( 'foreign bytes', file_get_contents( $foreign ) );
		self::assertFileExists( $path );
	}
}
