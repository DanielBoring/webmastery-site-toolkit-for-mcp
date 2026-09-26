<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/input-schema-cleanup.php';
require_once dirname( __DIR__ ) . '/e2e/input-schema-lifecycle.php';
require_once __DIR__ . '/fixtures/input-schema-cleanup-fake.php';

final class InputSchemaCleanupTest extends TestCase {
	private string $directory;
	private string $owner;
	private Wstm126_Cleanup_Fake $fake;
	private Wstm126_Cleanup $journal;
	private array $filesystem_fault = array();
	private array $diagnostics = array();

	protected function setUp(): void {
		$this->directory = getcwd() . DIRECTORY_SEPARATOR . '.wstm126-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory, 0700 );
		foreach ( array( 'private', 'web', 'artifacts' ) as $child ) { mkdir( $this->directory . '/' . $child, 0700 ); }
		mkdir( $this->directory . '/private/invocations', 0700 );
		$this->owner = str_repeat( 'a', 32 );
		$this->filesystem_fault = array();
		$this->diagnostics = array();
		$this->fake = new Wstm126_Cleanup_Fake();
		$this->journal = $this->create();
	}

	protected function tearDown(): void {
		foreach ( glob( $this->directory . '/private/invocations/*' ) as $file ) { unlink( $file ); }
		rmdir( $this->directory . '/private/invocations' );
		foreach ( array( 'private', 'web', 'artifacts' ) as $child ) {
			foreach ( glob( $this->directory . '/' . $child . '/*' ) as $file ) { unlink( $file ); }
			rmdir( $this->directory . '/' . $child );
		}
		rmdir( $this->directory );
	}

	private function path(): string {
		return $this->directory . '/private/invocations/' . $this->owner . '-http.json';
	}

	private function create( ?string $directory = null, ?callable $diagnostic = null ): Wstm126_Cleanup {
		return new Wstm126_Cleanup( $directory ?? $this->directory . '/private/invocations', $this->directory . '/web', $this->directory . '/artifacts', $this->owner, 'http', $this->fake->run, array( $this->fake, 'read' ), array( $this->fake, 'delete' ), function ( string $path ): array {
			return array( 'linked' => is_link( $path ) || ( $this->filesystem_fault['linked'] ?? false ), 'stat' => array_replace( lstat( $path ), $this->filesystem_fault['stat'] ?? array() ) );
		}, $diagnostic ?? function ( string $line ): void {
			$this->diagnostics[] = $line;
		} );
	}

	public function test_success_independently_reads_all_absence_and_retires_exact_journal(): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		self::assertCount( 2, $this->fake->rows['term_relationships'] );
		$this->fake->rows['posts'][0]['post_title'] = 'Legitimate positive control';
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertNull( $result['error'] );
		self::assertCount( 8, $result['proof'] );
		self::assertNotContains( false, $result['proof'] );
		self::assertGreaterThanOrEqual( 6, $this->fake->reads );
		self::assertFileDoesNotExist( $this->path() );
		self::assertSame( array(), $this->diagnostics );
		self::assertSame( array( 'proof', 'error', 'raw_evidence' ), array_keys( $result ) );
		self::assertSame( array(), $this->fake->rows['term_relationships'] );
	}

	private function seed_foreign_relationship_baseline(): array {
		$foreign = array( 'object_id' => '900', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		$this->fake->rows['term_relationships'] = array( $foreign );
		$this->owner = str_repeat( 'b', 32 );
		$this->journal = $this->create();
		$this->fake->seed( $this->journal );
		return $foreign;
	}

	public function test_owned_relationship_cleanup_preserves_unchanged_foreign_relationship(): void {
		$foreign = $this->seed_foreign_relationship_baseline();
		$this->fake->revision();
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertNull( $result['error'] );
		self::assertNotContains( false, $result['proof'] );
		self::assertSame( array( $foreign ), $this->fake->rows['term_relationships'] );
		self::assertSame( array(), $this->diagnostics );
		self::assertFileDoesNotExist( $this->path() );
	}

	public static function foreign_relationship_changes(): array {
		return array( array( 'insert' ), array( 'update' ), array( 'delete' ) );
	}

	private function mutate_foreign_relationship( string $change ): void {
		if ( 'insert' === $change ) {
			$this->fake->rows['term_relationships'][] = array( 'object_id' => '901', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		} elseif ( 'update' === $change ) {
			$this->fake->rows['term_relationships'][0]['term_order'] = '1';
		} else {
			$this->fake->rows['term_relationships'] = array_values( array_filter( $this->fake->rows['term_relationships'], static fn( $row ) => '900' !== $row['object_id'] ) );
		}
	}

	/** @dataProvider foreign_relationship_changes */
	public function test_foreign_relationship_change_refuses_before_any_cleanup( string $change ): void {
		$this->seed_foreign_relationship_baseline();
		$this->mutate_foreign_relationship( $change );
		$before = $this->fake->rows;
		$journal = file_get_contents( $this->path() );
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after foreign relationship change.' ); }, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertNotContains( true, $result['proof'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $before, $this->fake->rows );
		self::assertSame( $journal, file_get_contents( $this->path() ) );
		self::assertCount( 1, $this->diagnostics );
		self::assertSame( array( 'term_relationships' ), array_column( json_decode( $this->diagnostics[0], true, 16, JSON_THROW_ON_ERROR )['differences'], 'dimension' ) );
	}

	/** @dataProvider foreign_relationship_changes */
	public function test_foreign_relationship_change_during_cleanup_blocks_later_deletions( string $change ): void {
		$this->seed_foreign_relationship_baseline();
		$this->fake->revision();
		$this->fake->during_delete = function ( $fake, $kind ) use ( $change ): void {
			if ( 'post' === $kind ) { $this->mutate_foreign_relationship( $change ); }
		};
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after foreign relationship interference.' ); }, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertSame( array( array( 'post', 71 ) ), $this->fake->deleted );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['sessions_closed'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public function test_retained_revision_relationship_stops_before_the_next_post_delete(): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		$this->fake->retain = 'term_relationships';
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close with retained relationships.' ); }, true );
		self::assertSame( 'Post term relationships retained; actors and credentials preserved.', $result['error'] );
		self::assertSame( array( array( 'post', 71 ) ), $this->fake->deleted );
		self::assertSame( array( '70' ), array_column( $this->fake->rows['posts'], 'ID' ) );
		self::assertCount( 2, $this->fake->rows['term_relationships'] );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public function test_new_revision_during_cleanup_cannot_expand_relationship_ownership(): void {
		$this->fake->seed( $this->journal );
		$post = $this->fake->rows['posts'][0];
		$post['ID'] = '72';
		$post['post_name'] = 'second-owned-marker';
		$this->journal->plan( 'second-post', array( 'post_name' => $post['post_name'] ) );
		$this->fake->rows['posts'][] = $post;
		$this->journal->created( 'second-post', 'posts', '72', Wstm126_Cleanup::post_identity( $post ) );
		$this->fake->during_delete = static function ( $fake, $kind, $id ): void {
			if ( 'post' === $kind && 72 === $id ) { $fake->revision(); }
		};
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after ownership expansion.' ); }, true );
		self::assertSame( 'Owned identity changed; no cleanup allowed.', $result['error'] );
		self::assertSame( array( array( 'post', 72 ) ), $this->fake->deleted );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public function test_changed_revision_identity_during_cleanup_cannot_hide_its_relationships(): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		$revision = $this->fake->rows['posts'][1];
		$revision['ID'] = '73';
		$this->fake->rows['posts'][] = $revision;
		$this->fake->rows['term_relationships'][] = array( 'object_id' => '73', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		$this->fake->during_delete = static function ( $fake, $kind, $id ): void {
			if ( 'post' === $kind && 73 === $id ) { $fake->rows['posts'][1]['guid'] = 'foreign-replacement'; }
		};
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after revision replacement.' ); }, true );
		self::assertSame( 'Owned identity changed; no cleanup allowed.', $result['error'] );
		self::assertSame( array( array( 'post', 73 ) ), $this->fake->deleted );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public function test_reappearing_deleted_revision_relationship_is_checked_after_each_delete(): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		$this->fake->during_delete = static function ( $fake, $kind, $id ): void {
			if ( 'post' === $kind && 70 === $id ) {
				$fake->rows['term_relationships'][] = array( 'object_id' => '71', 'term_taxonomy_id' => '5', 'term_order' => '0' );
			}
		};
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close with reappearing relationships.' ); }, true );
		self::assertSame( 'Post term relationships retained; actors and credentials preserved.', $result['error'] );
		self::assertSame( array( array( 'post', 71 ), array( 'post', 70 ) ), $this->fake->deleted );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public function test_content_absence_guard_rejects_dangling_owned_relationship_independently(): void {
		$snapshot = $this->fake->rows;
		$snapshot['term_relationships'][] = array( 'object_id' => '70', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		$method = new ReflectionMethod( Wstm126_Cleanup::class, 'validate_content_absent' );
		$method->setAccessible( true );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Post term relationships retained; actors and credentials preserved.' );
		$method->invoke( $this->journal, $snapshot, array( 70 ) );
	}

	public function test_relationship_reappearing_during_session_close_preserves_credentials(): void {
		$this->fake->seed( $this->journal );
		$result = $this->journal->finish( function (): void {
			$this->fake->rows['term_relationships'][] = array( 'object_id' => '70', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		}, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertSame( array( array( 'post', 70 ) ), $this->fake->deleted );
		self::assertTrue( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['credentials_absent'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertFileExists( $this->path() );
	}

	public static function scope_changes(): array {
		return array(
			'posts' => array( 'posts', array( 'ID' => '999', 'post_author' => '999', 'post_type' => 'page', 'post_parent' => '0', 'private' => 'never-emit-row' ) ),
			'postmeta' => array( 'postmeta', array( 'post_id' => '999', 'meta_key' => 'never-emit-row' ) ),
			'users' => array( 'users', array( 'ID' => '999', 'user_email' => 'never-emit-row' ) ),
			'usermeta' => array( 'usermeta', array( 'user_id' => '999', 'meta_key' => 'never-emit-row' ) ),
			'terms' => array( 'terms', array( 'term_id' => '999', 'name' => 'never-emit-row' ) ),
			'term_taxonomy' => array( 'term_taxonomy', array( 'term_taxonomy_id' => '999', 'taxonomy' => 'never-emit-row' ) ),
			'term_relationships' => array( 'term_relationships', array( 'object_id' => '999', 'term_taxonomy_id' => '999' ) ),
			'comments' => array( 'comments', array( 'comment_ID' => '999', 'comment_content' => 'never-emit-row' ) ),
			'commentmeta' => array( 'commentmeta', array( 'comment_id' => '999', 'meta_key' => 'never-emit-row' ) ),
			'links' => array( 'links', array( 'link_id' => '999', 'link_owner' => '999', 'link_url' => 'never-emit-row' ) ),
			'credentials' => array( 'credentials', array( 'actor' => 999, 'verifier_sha256' => 'never-emit-verifier' ) ),
			'cron' => array( 'cron', array( 'private_schedule' => 'never-emit-row' ) ),
		);
	}

	/** @dataProvider scope_changes */
	public function test_scope_diagnostic_uses_existing_snapshot_and_preserves_zero_cleanup_writes( string $section, array $row ): void {
		$this->fake->seed( $this->journal );
		$this->fake->rows[ $section ][] = $row;
		$before = $this->fake->rows;
		$journal = file_get_contents( $this->path() );
		$baseline = json_decode( $journal, true, 512, JSON_THROW_ON_ERROR )['baseline'];
		$reads = $this->fake->reads;
		$closed = 0;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed++; }, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertSame( array( 'proof', 'error', 'raw_evidence' ), array_keys( $result ) );
		self::assertNotContains( true, $result['proof'] );
		self::assertSame( 0, $closed );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $reads + 1, $this->fake->reads );
		self::assertSame( $before, $this->fake->rows );
		self::assertSame( $journal, file_get_contents( $this->path() ) );
		self::assertCount( 1, $this->diagnostics );
		$record = json_decode( $this->diagnostics[0], true, 16, JSON_THROW_ON_ERROR );
		self::assertSame( array(
			'diagnostic' => 'wstm126', 'kind' => 'cleanup_scope', 'authoritative' => false, 'status' => 'comparison_failed',
			'differences' => array( array( 'dimension' => $section, 'expected_sha256' => $baseline[ $section ], 'observed_sha256' => hash( 'sha256', serialize( 'cron' === $section ? $before['cron'] : array( $row ) ) ) ) ),
		), $record );
		self::assertStringNotContainsString( 'never-emit', $this->diagnostics[0] );
	}

	public function test_multiple_scope_mismatches_preserve_all_original_state_and_original_error_when_sink_throws(): void {
		$this->fake->seed( $this->journal );
		$this->fake->rows['terms'][] = array( 'term_id' => '999' );
		$this->fake->rows['cron'][] = 'never-emit-schedule';
		$before = $this->fake->rows;
		$journal = file_get_contents( $this->path() );
		$reads = $this->fake->reads;
		$diagnostic = new ReflectionProperty( Wstm126_Cleanup::class, 'diagnostic' );
		$diagnostic->setAccessible( true );
		$diagnostic->setValue( $this->journal, function ( string $line ): void {
			$this->diagnostics[] = $line;
			throw new RuntimeException( 'never-emit-sink-error' );
		} );
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after scope mismatch.' ); }, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $reads + 1, $this->fake->reads );
		self::assertSame( $before, $this->fake->rows );
		self::assertSame( $journal, file_get_contents( $this->path() ) );
		self::assertNotContains( true, $result['proof'] );
		self::assertCount( 1, $this->diagnostics );
		self::assertSame( array( 'terms', 'cron' ), array_column( json_decode( $this->diagnostics[0], true, 16, JSON_THROW_ON_ERROR )['differences'], 'dimension' ) );
	}

	public function test_unknown_scope_section_is_reported_without_disclosing_its_name_or_advancing_cleanup(): void {
		$this->fake->seed( $this->journal );
		$this->fake->rows['private_prefix_secret_table'] = array( 'never-emit-row' );
		$before = $this->fake->rows;
		$journal = file_get_contents( $this->path() );
		$reads = $this->fake->reads;
		$result = $this->journal->finish( static function (): void { self::fail( 'Session close after malformed diagnostic input.' ); }, true );
		self::assertSame( 'Preexisting state or cron changed.', $result['error'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $before, $this->fake->rows );
		self::assertSame( $journal, file_get_contents( $this->path() ) );
		self::assertSame( $reads + 1, $this->fake->reads );
		self::assertNotContains( true, $result['proof'] );
		self::assertCount( 1, $this->diagnostics );
		self::assertSame( array( 'diagnostic' => 'wstm126', 'kind' => 'cleanup_scope', 'authoritative' => false, 'status' => 'rejected_invalid_input', 'differences' => array() ), json_decode( $this->diagnostics[0], true, 16, JSON_THROW_ON_ERROR ) );
	}

	public static function identity_mutations(): array {
		return array_map( static fn( $v ) => array( $v ), array( 'actor', 'post', 'marker', 'observation', 'foreign-content', 'foreign-credential', 'credential', 'cron', 'baseline' ) );
	}

	/** @dataProvider identity_mutations */
	public function test_all_identity_checks_precede_any_mutation( string $mutation ): void {
		$this->fake->seed( $this->journal );
		if ( 'actor' === $mutation ) { $this->fake->rows['users'][0]['user_registered'] = 'replacement'; }
		if ( 'post' === $mutation ) { $this->fake->rows['posts'][0]['post_author'] = '999'; }
		if ( 'marker' === $mutation ) { $this->fake->rows['posts'][0]['post_name'] = 'replacement'; }
		if ( 'observation' === $mutation ) { $this->fake->rows['observation'][0]['owner'] = 'other'; }
		if ( 'credential' === $mutation ) { $this->fake->rows['credentials']['synthetic-uuid']['created'] = 999; }
		if ( 'foreign-credential' === $mutation ) { $this->fake->rows['credentials']['foreign'] = array( 'actor' => 40 ); }
		if ( 'foreign-content' === $mutation ) { $this->fake->rows['posts'][] = array( 'ID' => '999', 'post_author' => '40', 'post_type' => 'post', 'post_parent' => '0' ); }
		if ( 'cron' === $mutation ) { $this->fake->rows['cron'][] = 'unexpected scheduled fixture'; }
		if ( 'baseline' === $mutation ) { $this->fake->rows['postmeta'][] = array( 'post_id' => '999', 'meta_key' => 'foreign' ); }
		$before = $this->fake->rows;
		$closed = false;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed = true; }, true );
		self::assertNotNull( $result['error'] );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertFalse( $closed );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $before, $this->fake->rows );
		self::assertFileExists( $this->path() );
		if ( ! in_array( $mutation, array( 'cron', 'baseline' ), true ) ) { self::assertSame( array(), $this->diagnostics ); }
	}

	public static function retained_rows(): array {
		return array_map( static fn( $v ) => array( $v ), array( 'post', 'revision', 'postmeta', 'term_relationships', 'actor', 'usermeta', 'credential', 'observation' ) );
	}

	public static function immutable_identity_fields(): array {
		$cases = array();
		foreach ( array( 'users' => array( 'ID', 'user_login', 'user_email', 'user_registered' ), 'posts' => array( 'ID', 'post_author', 'post_type', 'post_name', 'post_date', 'guid' ) ) as $table => $fields ) {
			foreach ( $fields as $field ) {
				$cases[ $table . ':' . $field ] = array( $table, $field );
			}
		}
		return $cases;
	}

	/** @dataProvider immutable_identity_fields */
	public function test_every_immutable_identity_field_blocks_all_cleanup( string $table, string $field ): void {
		$this->fake->seed( $this->journal );
		$this->fake->rows[ $table ][0][ $field ] .= '-changed';
		$before = $this->fake->rows;
		$journal = file_get_contents( $this->path() );
		$closed = false;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed = true; }, true );
		self::assertSame( 'Owned identity changed; no cleanup allowed.', $result['error'] );
		self::assertCount( 8, $result['proof'] );
		self::assertNotContains( true, $result['proof'] );
		self::assertFalse( $closed );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( $before, $this->fake->rows );
		self::assertSame( $journal, file_get_contents( $this->path() ) );
		self::assertSame( array(), $this->diagnostics );
	}

	/** @dataProvider retained_rows */
	public function test_observed_rows_not_delete_return_values_control_proof( string $kind ): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		$this->fake->retain = $kind;
		$closed = false;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed = true; }, true );
		self::assertNotNull( $result['error'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertFileExists( $this->path() );
		if ( in_array( $kind, array( 'post', 'postmeta', 'revision', 'term_relationships' ), true ) ) {
			self::assertFalse( $closed );
			self::assertNotEmpty( $this->fake->rows['users'] );
			self::assertNotEmpty( $this->fake->rows['credentials'] );
			self::assertNotEmpty( $this->fake->rows['observation'] );
			self::assertNotContains( array( 'actor', 40 ), $this->fake->deleted );
		}
		if ( 'credential' === $kind ) {
			self::assertNotEmpty( $this->fake->rows['users'] );
			self::assertNotEmpty( $this->fake->rows['credentials'] );
			self::assertNotEmpty( $this->fake->rows['observation'] );
			self::assertNotContains( array( 'actor', 40 ), $this->fake->deleted );
		}
	}

	public function test_partial_creation_plan_never_silently_clears_evidence(): void {
		$this->fake->seed( $this->journal );
		$this->journal->plan( 'interrupted-seed', array( 'post_name' => 'partial' ) );
		$result = $this->journal->finish( static function (): void {}, false );
		self::assertNotNull( $result['error'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertFileExists( $this->path() );
	}

	public function test_session_failure_preserves_actor_password_and_marker(): void {
		$this->fake->seed( $this->journal );
		$this->journal->plan( 'session', array( 'actor' => 40 ) );
		$this->journal->observed_session( 'session', 40, 'private-session', 'http://localhost/owned' );
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertTrue( $result['proof']['posts_absent'] );
		self::assertFalse( $result['proof']['sessions_closed'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertStringNotContainsString( 'private-session', file_get_contents( $this->path() ) );
	}

	public function test_only_matching_observed_session_delete_closes_session(): void {
		$this->fake->seed( $this->journal );
		$this->journal->plan( 'session', array( 'actor' => 40 ) );
		$this->journal->observed_session( 'session', 40, 'private-session', 'http://localhost/owned' );
		$result = $this->journal->finish( function (): void {
			$this->journal->observed_session( 'session', 40, 'private-session', 'http://localhost/owned', true );
		}, true );
		self::assertNotContains( false, $result['proof'] );
	}

	public static function delete_statuses(): array {
		return array( array( 200, true ), array( 202, true ), array( 204, true ), array( 201, false ), array( 401, false ), array( 500, false ) );
	}

	/** @dataProvider delete_statuses */
	public function test_http_capture_records_body_before_parser_failure_and_exact_delete_status( int $status, bool $closed ): void {
		$this->fake->seed( $this->journal );
		$this->journal->plan( 'session', array( 'actor' => 40 ) );
		$witness = wstm126_capture_http( $this->journal, 40, 'session', 'http://localhost/owned', array(), 200, 'invalid JSON body', 'private-session' );
		self::assertTrue( $witness['redacted'] );
		self::assertStringContainsString( hash( 'sha256', 'invalid JSON body' ), file_get_contents( $this->path() ) );
		$result = $this->journal->finish( function () use ( $status ): void {
			wstm126_capture_http( $this->journal, 40, 'session', 'http://localhost/owned', array( 'method' => 'DELETE', 'headers' => array( 'Mcp-Session-Id' => 'private-session', 'Authorization' => 'Basic never-log-this' ) ), $status, '', '' );
		}, true );
		self::assertSame( $closed, $result['proof']['sessions_closed'] );
		self::assertSame( $closed, $result['proof']['journal_retired'] );
		if ( ! $closed ) {
			self::assertNotEmpty( $this->fake->rows['credentials'] );
			self::assertStringNotContainsString( 'never-log-this', file_get_contents( $this->path() ) );
		}
	}

	public function test_wrong_session_url_cannot_prove_close(): void {
		$this->fake->seed( $this->journal );
		$this->journal->plan( 'session', array( 'actor' => 40 ) );
		$this->journal->observed_session( 'session', 40, 'private-session', 'http://localhost/owned' );
		$result = $this->journal->finish( function (): void {
			wstm126_capture_http( $this->journal, 40, 'session', 'http://localhost/foreign', array( 'method' => 'DELETE', 'headers' => array( 'Mcp-Session-Id' => 'private-session' ) ), 204, '', '' );
		}, true );
		self::assertFalse( $result['proof']['sessions_closed'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
	}

	public function test_revoked_credential_prevents_post_actor_or_marker_cleanup(): void {
		$this->fake->seed( $this->journal );
		$this->fake->rows['credentials'] = array();
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertNotEmpty( $this->fake->rows['posts'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
	}

	public function test_zero_creation_run_cannot_claim_cleanup_proof(): void {
		$result = $this->journal->finish( static function (): void {}, false );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertFileExists( $this->path() );
	}

	public function test_foreign_actor_replacement_during_post_cleanup_preserves_actor(): void {
		$this->fake->seed( $this->journal );
		$this->fake->during_delete = static function ( $fake, $kind ): void {
			if ( 'post' === $kind ) { $fake->rows['users'][0]['user_email'] = 'foreign@example.test'; }
		};
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['actors_absent'] );
		self::assertNotContains( array( 'actor', 40 ), $this->fake->deleted );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
	}

	public function test_foreign_revision_marker_prevents_every_cleanup_including_session_close(): void {
		$this->fake->seed( $this->journal );
		$this->fake->revision();
		$this->fake->rows['posts'][1]['post_name'] = 'foreign-revision';
		$closed = false;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed = true; }, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertFalse( $closed );
		self::assertSame( array(), $this->fake->deleted );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
	}

	public function test_post_replaced_between_deletes_retains_post_actor_credentials_and_marker(): void {
		$this->fake->seed( $this->journal );
		$post = $this->fake->rows['posts'][0];
		$post['ID'] = '72';
		$post['post_name'] = 'second-owned-marker';
		$this->journal->plan( 'second-post', array( 'post_name' => $post['post_name'] ) );
		$this->fake->rows['posts'][] = $post;
		$this->journal->created( 'second-post', 'posts', '72', Wstm126_Cleanup::post_identity( $post ) );
		$this->fake->during_delete = static function ( $fake, $kind, $id ): void {
			if ( 'post' === $kind && 72 === $id ) { $fake->rows['posts'][0]['post_name'] = 'foreign-replacement'; }
		};
		$closed = false;
		$result = $this->journal->finish( static function () use ( &$closed ): void { $closed = true; }, true );
		self::assertFalse( $result['proof']['posts_absent'] );
		self::assertFalse( $closed );
		self::assertSame( array( array( 'post', 72 ) ), $this->fake->deleted );
		self::assertSame( 'foreign-replacement', $this->fake->rows['posts'][0]['post_name'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
	}

	public function test_new_content_during_cleanup_cannot_be_implicitly_deleted(): void {
		$this->fake->seed( $this->journal );
		$result = $this->journal->finish( function (): void {
			$this->fake->rows['posts'][] = array( 'ID' => '999', 'post_author' => '40' );
		}, true );
		self::assertNotNull( $result['error'] );
		self::assertNotContains( array( 'actor', 40 ), $this->fake->deleted );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
	}

	public function test_baseline_changed_during_deletion_retains_marker_and_journal(): void {
		$this->fake->seed( $this->journal );
		$this->fake->during_delete = static function ( $fake ): void { $fake->rows['cron'][] = 'new foreign event'; };
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['baseline_restored'] );
		self::assertNotEmpty( $this->fake->rows['observation'] );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
		self::assertFileExists( $this->path() );
	}

	public function test_missing_cases_keeps_journal_even_after_local_absence_proof(): void {
		$this->fake->seed( $this->journal );
		$result = $this->journal->finish( static function (): void {}, false );
		self::assertNull( $result['error'] );
		self::assertTrue( $result['proof']['baseline_restored'] );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertFileExists( $this->path() );
	}

	public function test_failed_case_does_not_prevent_successful_cleanup_attestation(): void {
		$this->fake->seed( $this->journal );
		$cases = array_fill( 0, 152, array( 'passed' => false ) );
		$result = $this->journal->finish( static function (): void {}, 152 === count( $cases ) );
		self::assertNotContains( false, $result['proof'] );
	}

	public function test_malformed_journal_refuses_all_cleanup(): void {
		$this->fake->seed( $this->journal );
		file_put_contents( $this->path(), '{broken' );
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( '{broken', file_get_contents( $this->path() ) );
	}

	public function test_existing_journal_is_never_overwritten(): void {
		$before = file_get_contents( $this->path() );
		try {
			$this->create();
			self::fail( 'Existing journal was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $before, file_get_contents( $this->path() ) );
		}
	}

	public function test_journal_modification_cannot_be_overwritten_by_next_record(): void {
		file_put_contents( $this->path(), '{"owner":"foreign"}' );
		try {
			$this->journal->plan( 'actor', array( 'user_login' => 'owned' ) );
			self::fail( 'Modified journal was overwritten.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( '{"owner":"foreign"}', file_get_contents( $this->path() ) );
		}
	}

	public function test_hardlinked_journal_is_rejected(): void {
		$this->fake->seed( $this->journal );
		$this->filesystem_fault = array( 'stat' => array( 'nlink' => 2 ) );
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertSame( array(), $this->fake->deleted );
		$this->filesystem_fault = array();
		$link = $this->directory . '/private/other-link';
		if ( @link( $this->path(), $link ) ) {
			$result = $this->journal->finish( static function (): void {}, true );
			self::assertFalse( $result['proof']['identity_validated'] );
			self::assertSame( array(), $this->fake->deleted );
		}
	}

	public static function invalid_runtime_identity(): array {
		return array(
			array( 'WSTM126_DISPOSABLE', '', 'Set WSTM126_DISPOSABLE=1' ),
			array( 'WSTM126_STAGE_TOKEN', '', 'runtime identity' ),
			array( 'WSTM126_STAGE_TOKEN', str_repeat( 'A', 32 ), 'runtime identity' ),
			array( 'WSTM126_SOURCE_SHA', '', 'runtime identity' ),
			array( 'WSTM126_SOURCE_SHA', str_repeat( 'a', 39 ), 'runtime identity' ),
			array( 'WSTM126_PROJECT', '../foreign', 'runtime identity' ),
			array( 'WSTM126_BOUNDARY', 'foreign', 'must be direct, permission, ability, http or individual' ),
			array( 'WSTM126_ARTIFACT', '', 'must name a new file' ),
		);
	}

	/** @dataProvider invalid_runtime_identity */
	public function test_invalid_identity_fails_before_bootstrap_or_credentials( string $name, string $value, string $expected ): void {
		$environment = array_merge( getenv(), array(
			'WSTM126_DISPOSABLE' => '1', 'WSTM126_STAGE_TOKEN' => $this->owner,
			'WSTM126_SOURCE_SHA' => str_repeat( 'b', 40 ), 'WSTM126_PROJECT' => 'owned-schema',
			'WSTM126_BOUNDARY' => 'direct', 'WSTM126_ARTIFACT' => $this->directory . '/artifacts/new.json',
			$name => $value,
		) );
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/e2e/input-schema-runner.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $environment );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( $expected, $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
		self::assertFileDoesNotExist( $this->directory . '/artifacts/new.json' );
	}

	public function test_journal_cannot_be_inside_webroot_or_artifacts(): void {
		foreach ( array( 'web', 'artifacts' ) as $directory ) {
			try {
				$this->create( $this->directory . '/' . $directory );
				self::fail( 'Public journal accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'outside', $error->getMessage() );
			}
		}
	}

	public function test_symlink_journal_is_rejected_without_touching_target(): void {
		$this->fake->seed( $this->journal );
		$target = $this->directory . '/private/foreign';
		file_put_contents( $target, 'foreign marker' );
		$this->filesystem_fault = array( 'linked' => true );
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( 'foreign marker', file_get_contents( $target ) );
		$this->filesystem_fault = array();
		$link = $this->directory . '/private/actual-link';
		if ( @symlink( $target, $link ) ) {
			unlink( $this->path() );
			rename( $link, $this->path() );
			$result = $this->journal->finish( static function (): void {}, true );
			self::assertFalse( $result['proof']['identity_validated'] );
			self::assertSame( array(), $this->fake->deleted );
			self::assertSame( 'foreign marker', file_get_contents( $target ) );
		}
	}

	public function test_raw_body_witness_keeps_safe_json_and_never_exposes_secret_or_invalid_body(): void {
		$body = '{"jsonrpc":"2.0","result":{"isError":true,"structuredContent":{"success":false,"error":{"code":"invalid_input","reason":"ability_invalid_input","message":"Synthetic input rejected"}}}}';
		self::assertSame( $body, wstm126_safe_body( $body )['body'] );
		foreach ( array( '{"jsonrpc":"2.0","password":"private-value"}', 'unparseable private-value', str_repeat( 'x', 262145 ) ) as $unsafe ) {
			$result = wstm126_safe_body( $unsafe );
			self::assertTrue( $result['redacted'] );
			self::assertArrayNotHasKey( 'body', $result );
			self::assertSame( hash( 'sha256', $unsafe ), $result['sha256'] );
		}
		self::assertTrue( wstm126_safe_body( '{"jsonrpc":"2.0","result":"opaque-credential"}', array( 'opaque-credential' ) )['redacted'] );
		self::assertStringNotContainsString( 'opaque-credential', wstm126_safe_failure( 'Remote said opaque-credential', array( 'opaque-credential' ) ) );
	}

	public function test_malformed_denied_raw_bytes_block_retirement_after_successful_database_cleanup(): void {
		$this->fake->seed( $this->journal );
		$body = "\x00\xffmalformed denied body\nAuthorization: synthetic-private-value";
		$witness = wstm126_capture_http( $this->journal, 40, 'unused', 'http://localhost/owned', array(), 403, $body, '' );
		self::assertTrue( $witness['redacted'] );
		self::assertArrayNotHasKey( 'body', $witness );
		$spool = $this->path() . '.wire.jsonl';
		$record = json_decode( trim( file_get_contents( $spool ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $body, base64_decode( $record['body_base64'], true ) );
		try {
			json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
			self::fail( 'Synthetic malformed body was accepted.' );
		} catch ( JsonException $error ) {
			self::assertFileExists( $spool );
		}
		$result = $this->journal->finish( static function (): void {}, true, true );
		foreach ( $result['proof'] as $key => $value ) { self::assertSame( 'journal_retired' !== $key, $value ); }
		self::assertFileExists( $this->path() );
		self::assertFileExists( $spool );
		self::assertTrue( $result['raw_evidence']['retained'] );
		self::assertStringContainsString( 'retirement is blocked', $result['error'] );
		$journal = json_decode( file_get_contents( $this->path() ), true, 512, JSON_THROW_ON_ERROR );
		self::assertTrue( $journal['wire']['retained'] );
		self::assertFalse( $journal['proof']['journal_retired'] );
		$record = json_decode( trim( file_get_contents( $spool ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 403, $record['status'] );
		self::assertSame( $body, base64_decode( $record['body_base64'], true ) );
		self::assertSame( hash( 'sha256', $body ), $record['sha256'] );
		self::assertStringNotContainsString( 'synthetic-private-value', json_encode( array( $witness, $result ), JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'body_base64', json_encode( array( $witness, $result ), JSON_THROW_ON_ERROR ) );
		try {
			Wstm126_Lifecycle::assert_invocations_empty( dirname( $this->path() ) );
			self::fail( 'Stage gate accepted retained private raw evidence.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Invocation cleanup journal remains; ownership retained.', $error->getMessage() );
		}
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertFileExists( $this->path() );
		self::assertFileExists( $spool );
		if ( DIRECTORY_SEPARATOR !== '\\' ) { self::assertSame( 0600, fileperms( $spool ) & 0777 ); }
	}

	public function test_passing_http_run_retires_only_verified_raw_spool(): void {
		$this->fake->seed( $this->journal );
		$body = '{"jsonrpc":"2.0","result":{"isError":true,"structuredContent":{"success":false,"error":{"code":"forbidden"}}}}';
		$witness = wstm126_capture_http( $this->journal, 40, 'unused', 'http://localhost/owned', array(), 200, $body, '' );
		self::assertSame( $body, $witness['body'] );
		$record = json_decode( trim( file_get_contents( $this->path() . '.wire.jsonl' ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $body, base64_decode( $record['body_base64'], true ) );
		$result = $this->journal->finish( static function (): void {}, true );
		self::assertNotContains( false, $result['proof'] );
		self::assertNull( $result['raw_evidence'] );
		self::assertFileDoesNotExist( $this->path() . '.wire.jsonl' );
		Wstm126_Lifecycle::assert_invocations_empty( dirname( $this->path() ) );
		self::assertSame( array( '.', '..', 'artifacts', 'private', 'web' ), scandir( $this->directory ) );
	}

	public function test_unknown_cleanup_keeps_exact_raw_bytes_in_private_invocation_directory(): void {
		$this->fake->seed( $this->journal );
		$body = 'malformed-private-wire';
		wstm126_capture_http( $this->journal, 40, 'unused', 'http://localhost/owned', array(), 500, $body, '' );
		$this->fake->retain = 'post';
		$result = $this->journal->finish( static function (): void {}, true, true );
		self::assertFalse( $result['proof']['journal_retired'] );
		self::assertFileExists( $this->path() );
		$record = json_decode( trim( file_get_contents( $this->path() . '.wire.jsonl' ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $body, base64_decode( $record['body_base64'], true ) );
		self::assertNotEmpty( $this->fake->rows['users'] );
		self::assertNotEmpty( $this->fake->rows['credentials'] );
	}

	public function test_tampered_raw_spool_prevents_any_cleanup(): void {
		$this->fake->seed( $this->journal );
		wstm126_capture_http( $this->journal, 40, 'unused', 'http://localhost/owned', array(), 403, 'original denied bytes', '' );
		file_put_contents( $this->path() . '.wire.jsonl', 'foreign replacement bytes' );
		$result = $this->journal->finish( static function (): void {}, true, true );
		self::assertFalse( $result['proof']['identity_validated'] );
		self::assertSame( array(), $this->fake->deleted );
		self::assertSame( 'foreign replacement bytes', file_get_contents( $this->path() . '.wire.jsonl' ) );
	}

	public function test_failed_case_without_private_wire_can_retire_after_complete_cleanup(): void {
		$this->fake->seed( $this->journal );
		$result = $this->journal->finish( static function (): void {}, true, true );
		self::assertNotContains( false, $result['proof'] );
		self::assertNull( $result['raw_evidence'] );
		self::assertFileDoesNotExist( $this->path() );
	}

	public function test_sql_adapter_reads_fresh_rows_and_only_fingerprints_credential_verifier(): void {
		if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = new Wstm126_Cleanup_Fake_DB();
		$GLOBALS['wpdb'] = $db;
		try {
			$credential = array( 'uuid' => 'synthetic', 'name' => 'owned', 'created' => 12, 'password' => 'synthetic-stored-verifier' );
			$db->tables['usermeta'] = array( array( 'user_id' => '40', 'meta_key' => '_application_passwords', 'meta_value' => serialize( array( $credential ) ) ) );
			$db->tables['wstm126_http'] = array( array( 'option_value' => serialize( array( 'owner' => 'owned-run', 'token' => 'never-return-token' ) ) ) );
			$db->tables['cron'] = array( array( 'option_value' => 'original-cron' ) );
			$before = wstm126_cleanup_snapshot();
			self::assertSame( array( array( 'owner' => 'owned-run' ) ), $before['observation'] );
			self::assertSame( hash( 'sha256', 'synthetic-stored-verifier' ), $before['credentials']['synthetic']['verifier_sha256'] );
			self::assertArrayNotHasKey( 'password', $before['credentials']['synthetic'] );
			$db->tables['usermeta'] = $db->tables['wstm126_http'] = array();
			$after = wstm126_cleanup_snapshot();
			self::assertSame( array(), $after['credentials'] );
			self::assertSame( array(), $after['observation'] );
			self::assertSame( $before['cron'], $after['cron'] );
			self::assertCount( 24, $db->queries );
		} finally {
			if ( null === $previous ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $previous; }
		}
	}
}
