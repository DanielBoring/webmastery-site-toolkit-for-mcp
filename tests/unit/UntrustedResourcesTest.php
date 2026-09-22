<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-resources.php';

final class UntrustedResourcesTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm108-resources-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->directory = realpath( $this->directory );
	}

	protected function tearDown(): void {
		$this->remove_fixture( $this->directory );
	}

	private function remove_fixture( string $path ): void {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( ! is_link( $path ) ) { chmod( $path, 0600 ); }
			unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_fixture( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		rmdir( $path );
	}

	private function run_raw_fixture( string $mode, string $residue = 'none' ): array {
		$process = proc_open( array( PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'untrusted-resources.php', $mode, $this->directory, $residue ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error . $output );
		self::assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	private function run_fixture( string $mode ): array {
		$result = $this->run_raw_fixture( $mode );
		self::assertTrue( $result['foreign_intact'] );
		self::assertTrue( $result['no_secrets'] );
		self::assertTrue( $result['bounded_queries'] );
		self::assertTrue( $result['early_rejected'] );
		self::assertNotContains( 99, $result['deleted_users'] );
		self::assertNotContains( 99, $result['deleted_posts'] );
		return $result;
	}

	public static function successful_cases(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'normal', 'resume', 'resume-recording', 'lost-post', 'lost-comment', 'lost-session', 'lost-password',
			'payload-password', 'lost-payload-password', 'resume-payload-password', 'payload-password-foreign-actor',
			'payload-password-registration-name', 'payload-password-registration-uuid',
			'payload-password-registration-actor', 'payload-password-absent',
			'encoded-attachment', 'lost-encoded-attachment', 'resume-encoded-attachment',
			'repeated-mutations',
		) );
	}

	/** @dataProvider successful_cases */
	public function test_complete_cleanup_and_explicit_source_bound_retirement( string $mode ): void {
		$result = $this->run_fixture( $mode );
		self::assertTrue( $result['proof']['cleanup_complete'], json_encode( $result['proof'] ) );
		self::assertSame( 0, $result['proof']['failed'] );
		self::assertNull( $result['proof']['first_error'] );
		self::assertTrue( $result['journal_after_cleanup'] );
		self::assertTrue( $result['proof_persisted'] );
		self::assertFalse( $result['journal_after_retire'] );
		self::assertTrue( $result['wrong_rejected'] );
		self::assertTrue( $result['parent_restored'] );
		self::assertSame( array( 99 ), $result['users'] );
		self::assertSame( array( 99 ), $result['posts'] );
		self::assertSame( array( 99 ), $result['comments'] );
		self::assertFalse( $result['file_exists'] );
		self::assertFalse( $result['directory_exists'] );
		self::assertCount( 35, $result['proof']['cleanup'] );
		self::assertTrue( $result['password_names_preserved'] );
		self::assertTrue( $result['attachment_title_preserved'] );
		if ( 'repeated-mutations' === $mode ) { self::assertSame( 5, $result['mutation_intents'] ); }
		if ( 'payload-password-absent' === $mode ) { self::assertNotContains( 'PRIVATE-UUID-1', $result['deleted_passwords'] ); }
		if ( in_array( $mode, array( 'payload-password-registration-name', 'payload-password-registration-uuid', 'payload-password-registration-actor' ), true ) ) {
			self::assertTrue( $result['password_registration_rejected'] );
			self::assertTrue( $result['password_registration_unchanged'] );
		}
		if ( 'resume' === $mode ) { self::assertTrue( $result['resumed'] ); }
	}

	public static function unsafe_content(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'sentinel', 'login', 'email', 'outside-reference', 'foreign-reference', 'raw-reference', 'cross-reference', 'derivative', 'backup', 'metadata-path', 'metadata-basename',
			'changed-file', 'unknown-entry', 'foreign-child', 'foreign-comment', 'foreign-revision', 'unknown-post', 'unregistered-type',
			'attachment-veto', 'post-veto', 'comment-veto', 'commentmeta', 'commentmeta-hidden', 'postmeta', 'postmeta-hidden', 'cron', 'custom-cron', 'file-veto',
			'ambiguous-post', 'ambiguous-comment', 'database-error', 'identity-mode', 'identity-uid', 'identity-gid', 'identity-ino',
			'option-residue',
			'mismatched-encoded-attachment',
			'repeated-post-ambiguous', 'repeated-post-missing-second', 'repeated-post-surplus-intent',
		) );
	}

	/** @dataProvider unsafe_content */
	public function test_unknown_ownership_or_veto_retains_files_and_actors( string $mode ): void {
		$result = $this->run_fixture( $mode );
		self::assertFalse( $result['proof']['cleanup_complete'] );
		self::assertGreaterThan( 0, $result['proof']['failed'] );
		self::assertTrue( $result['journal_after_retire'] );
		self::assertTrue( $result['file_exists'] );
		self::assertTrue( $result['directory_exists'] );
		self::assertSame( array(), $result['deleted_users'] );
		if ( ! in_array( $mode, array( 'attachment-veto', 'post-veto', 'comment-veto', 'commentmeta', 'commentmeta-hidden', 'postmeta', 'postmeta-hidden', 'cron', 'custom-cron', 'file-veto' ), true ) ) {
			self::assertSame( array(), $result['deleted_posts'] );
		}
	}

	public static function incomplete_cleanup(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'empty', 'missing-journal', 'foreign-journal', 'malformed-session', 'unknown-session', 'unknown-password',
			'http-error', 'session-refusal', 'session-residue', 'password-refusal', 'password-residue', 'actor-veto', 'usermeta', 'file-replacement',
			'usermeta-hidden', 'partial-session', 'late-unknown-entry', 'proof-new-resource', 'lost-actor', 'foreign-lost-actor', 'unfulfilled-user',
			'payload-password-lost-near-match', 'payload-password-lost-foreign-actor', 'payload-password-lost-sentinel',
			'payload-password-ambiguous', 'payload-password-changed-name', 'payload-password-changed-uuid',
			'payload-password-lost-absent', 'payload-password-actor-id', 'payload-password-actor-login', 'payload-password-actor-email',
			'payload-password-actor-role', 'payload-password-actor-absent', 'payload-password-owner-missing', 'payload-password-owner-partial',
			'payload-password-missing-journal', 'payload-password-unbound-journal', 'payload-password-partial-intent', 'payload-password-foreign-source',
		) );
	}

	/** @dataProvider incomplete_cleanup */
	public function test_missing_evidence_and_residue_never_prove_cleanup( string $mode ): void {
		$result = $this->run_fixture( $mode );
		self::assertFalse( $result['proof']['cleanup_complete'] );
		self::assertGreaterThan( 0, $result['proof']['failed'] );
		if ( in_array( $mode, array( 'session-refusal', 'session-residue', 'password-refusal', 'password-residue' ), true ) ) {
			self::assertSame( array(), $result['deleted_users'] );
		}
		if ( 'unknown-password' === $mode ) { self::assertNotContains( 'UNKNOWN-UUID', $result['deleted_passwords'] ); }
		if ( in_array( $mode, array( 'missing-journal', 'foreign-journal', 'lost-actor', 'foreign-lost-actor', 'unfulfilled-user' ), true ) ) {
			self::assertSame( array(), $result['deleted_users'] );
			self::assertSame( array(), $result['deleted_posts'] );
		}
		if ( 'lost-actor' === $mode ) { self::assertSame( array( 'administrator' => 1 ), $result['recorded_users'] ); }
		if ( 'foreign-lost-actor' === $mode ) { self::assertSame( array(), $result['recorded_users'] ); }
		if ( 'late-unknown-entry' === $mode ) {
			self::assertSame( array(), $result['deleted_users'] );
			self::assertTrue( $result['directory_exists'] );
		}
		if ( str_contains( $mode, 'payload-password' ) ) {
			self::assertTrue( $result['password_names_preserved'] );
			self::assertNotContains( 1, $result['closed_clients'] );
			self::assertNotContains( 1, $result['session_delete_users'] );
			self::assertNotContains( 1, $result['deleted_users'] );
			self::assertNotContains( 'PRIVATE-UUID-1', $result['deleted_passwords'] );
			self::assertNotContains( 'AMBIGUOUS-UUID', $result['deleted_passwords'] );
			self::assertNotContains( 'CHANGED-UUID', $result['deleted_passwords'] );
			self::assertNotContains( 'FOREIGN-MATCH-UUID', $result['deleted_passwords'] );
		}
		if ( in_array( $mode, array( 'payload-password-missing-journal', 'payload-password-unbound-journal', 'payload-password-partial-intent', 'payload-password-foreign-source' ), true ) ) {
			foreach ( array( 'closed_clients', 'session_delete_users', 'deleted_passwords', 'deleted_users', 'deleted_posts' ) as $operations ) {
				self::assertSame( array(), $result[ $operations ] );
			}
		}
	}

	public static function adversarial_password_refusals(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'payload-password-refusal', 'payload-password-false', 'payload-password-residue', 'payload-password-removed-refusal',
		) );
	}

	/** @dataProvider adversarial_password_refusals */
	public function test_adversarial_password_refusal_retains_actors_even_if_api_removed_the_password( string $mode ): void {
		$result = $this->run_fixture( $mode );
		self::assertTrue( $result['password_names_preserved'] );
		self::assertFalse( $result['proof']['cleanup_complete'] );
		self::assertSame( 'password:administrator', $result['proof']['first_error'] );
		self::assertSame( array(), $result['deleted_users'] );
		foreach ( array( 1, 2, 3 ) as $id ) {
			self::assertContains( $id, $result['users'] );
			self::assertContains( 'PRIVATE-UUID-' . $id, $result['deleted_passwords'] );
		}
		foreach ( array( 'administrator', 'subscriber', 'reader' ) as $role ) {
			self::assertIsString( $result['proof']['cleanup'][ 'password:' . $role ] );
			self::assertIsString( $result['proof']['cleanup'][ 'actor:' . $role ] );
		}
		self::assertTrue( $result['proof_persisted'] );
		self::assertTrue( $result['journal_after_retire'] );
	}

	public function test_slug_contract_accepts_wordpress_encoded_unicode_without_rewriting_it(): void {
		$run = 'wstm108-0123456789abcdef';
		$resources = new Wstm108_Resources( $this->directory . DIRECTORY_SEPARATOR . 'resources.json', $this->proof_binding(), $run );
		$check = new ReflectionMethod( Wstm108_Resources::class, 'owned_slug' );
		foreach ( array( $run . '-title', $run . '-auto-%e9%9b%aa-%f0%9f%98%80', $run . '-auto-%e9%9b%aa-2' ) as $slug ) {
			self::assertTrue( $check->invoke( $resources, $slug ) );
		}
		foreach ( array( $run . '-', $run . '-title-%', $run . '-title-%e9%9', $run . '-title-%zz', $run . '-space title', 'foreign-%e9%9b%aa' ) as $slug ) {
			self::assertFalse( $check->invoke( $resources, $slug ) );
		}
	}

	public static function repeated_post_cases(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'repeated-post-registered', 'repeated-post-queued', 'repeated-post-lost-second', 'repeated-post-replay',
		) );
	}

	/** @dataProvider repeated_post_cases */
	public function test_repeated_draft_slugs_track_distinct_ids_and_recover_only_unrecorded_results( string $mode ): void {
		$result = $this->run_fixture( $mode );
		self::assertTrue( $result['repeated_inputs_preserved'] );
		self::assertTrue( $result['proof']['cleanup_complete'], json_encode( $result['proof'] ) );
		self::assertSame( array( 'posts' => 10, 'comments' => 1, 'files' => 1 ), $result['proof']['resource_counts'] );
		Wstm108_Resources::validate_proof( $result['proof'], $this->proof_binding() );
		self::assertCount( 41, $result['proof']['cleanup'] );
		self::assertCount( 10, $result['deleted_posts'] );
		foreach ( array( 30, 31, 32, 33, 34, 35 ) as $id ) {
			self::assertSame( 1, count( array_keys( $result['deleted_posts'], $id, true ) ) );
		}
		self::assertSame( array( 99 ), $result['users'] );
		self::assertSame( array( 99 ), $result['posts'] );
		self::assertTrue( $result['proof_persisted'] );
		self::assertTrue( $result['journal_after_cleanup'] );
		self::assertFalse( $result['journal_after_retire'] );
		if ( 'repeated-post-replay' === $mode ) {
			self::assertTrue( $result['post_replay_rejected'] );
			self::assertTrue( $result['post_replay_unchanged'] );
		}
	}

	public function test_http_failure_stays_first_even_after_successful_narrow_recovery(): void {
		$result = $this->run_fixture( 'first-error' );
		self::assertFalse( $result['proof']['cleanup_complete'] );
		self::assertSame( 'HTTP:gateway:administrator', $result['proof']['first_error'] );
		self::assertTrue( $result['proof']['cleanup']['session:gateway:administrator'] );
		self::assertIsString( $result['proof']['cleanup']['HTTP:gateway:administrator'] );
		self::assertIsString( $result['proof']['cleanup']['references'] );
	}

	public static function filesystem_controls(): array {
		return array( array( 'mode' ), array( 'root-mode' ), array( 'symlink' ), array( 'hardlink' ) );
	}

	/** @dataProvider filesystem_controls */
	public function test_real_filesystem_negative_controls( string $mode ): void {
		$result = $this->run_fixture( $mode );
		if ( ( 'root-mode' === $mode && 'Windows' === PHP_OS_FAMILY )
			|| ( 'symlink' === $mode && ! $result['symlink_available'] )
			|| ( 'hardlink' === $mode && ! $result['hardlink_available'] ) ) {
			// Unsupported OS controls are explicit, not skipped mocked checks.
			self::assertTrue( $result['proof']['cleanup_complete'] );
			return;
		}
		self::assertFalse( $result['proof']['cleanup_complete'] );
		self::assertSame( array(), $result['deleted_users'] );
		self::assertSame( array(), $result['deleted_posts'] );
	}

	public function test_exclusive_reservation_and_foreign_resume_leave_journal_untouched(): void {
		$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'unit', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
		$run = 'wstm108-0123456789abcdef';
		$path = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
		$resources = new Wstm108_Resources( $path, $binding, $run );
		$before = Wstm108_Files::file( $path );
		foreach ( array( 'collision', 'source', 'owner', 'order' ) as $control ) {
			try {
				$foreign = $binding;
				if ( 'source' === $control ) { $foreign['source_sha'] = str_repeat( 'd', 40 ); }
				if ( 'owner' === $control ) { $foreign['owner'] = str_repeat( 'd', 32 ); }
				if ( 'order' === $control ) { $foreign = array_reverse( $foreign, true ); }
				if ( 'collision' === $control ) { new Wstm108_Resources( $path, $foreign, $run ); }
				else { Wstm108_Resources::resume( $path, $foreign ); }
				self::fail( 'Expected foreign/collision refusal.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( $before, Wstm108_Files::file( $path ) );
			}
		}
		self::assertFalse( $resources->proof()['cleanup_complete'] );
	}

	public function test_public_upload_creation_preserves_private_modes_parent_identity_and_restrictive_umask(): void {
		$root = $this->directory . DIRECTORY_SEPARATOR . 'uploads';
		self::assertTrue( mkdir( $root, 0755 ) );
		$before = Wstm108_Files::directory( $root );
		$run = 'wstm108-0123456789abcdef';
		$journal = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
		$mask = umask( 0077 );
		try {
			$effective_mask = umask();
			$resources = new Wstm108_Resources( $journal, $this->proof_binding(), $run );
			$upload = $resources->create_upload_directory( $root );
			self::assertSame( $effective_mask, umask() );
			$after = Wstm108_Files::directory( $root );
			self::assertContains( $after['nlink'], array( $before['nlink'], $before['nlink'] + 1 ) );
			self::assertSame( array_diff_key( $before, array( 'nlink' => true ) ), array_diff_key( $after, array( 'nlink' => true ) ) );
			self::assertSame( array( '.', '..', $run ), scandir( $root ) );
			$file = $upload . DIRECTORY_SEPARATOR . $run . '-雪.png';
			$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1kAAAAASUVORK5CYII=', true );
			$resources->intent( 'file', array( 'path' => $file, 'sha256' => hash( 'sha256', $bytes ) ) );
			Wstm108_Files::create( $file, $bytes, true );
			$resources->file( $file );
			self::assertSame( $effective_mask, umask() );
			self::assertSame( $bytes, Wstm108_Files::file( $file )['bytes'] );
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				self::assertSame( 0077, $effective_mask );
				self::assertSame( 0755, Wstm108_Files::directory( $upload )['mode'] & 0777 );
				self::assertSame( 0644, Wstm108_Files::file( $file )['identity']['mode'] & 0777 );
				self::assertSame( 0700, Wstm108_Files::directory( $this->directory )['mode'] & 0777 );
				self::assertSame( 0600, Wstm108_Files::file( $journal )['identity']['mode'] & 0777 );
			}
		} finally {
			umask( $mask );
		}
		Wstm108_Files::remove( $file, Wstm108_Files::file( $file ) );
		self::assertTrue( rmdir( $upload ) );
		self::assertSame( $before, Wstm108_Files::directory( $root ) );
	}

	public function test_malformed_private_journal_cannot_inject_public_labels_or_skip_categories(): void {
		$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'unit', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
		$run = 'wstm108-0123456789abcdef';
		$path = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
		new Wstm108_Resources( $path, $binding, $run );
		$original = Wstm108_Files::file( $path );
		foreach ( array( 'missing-category', 'secret-label', 'secret-error', 'partial-intent', 'partial-upload', 'unproven-user' ) as $control ) {
			$state = json_decode( $original['bytes'], true, 512, JSON_THROW_ON_ERROR );
			switch ( $control ) {
				case 'missing-category': unset( $state['files'] ); break;
				case 'secret-label': $state['cleanup']['SECRET-TOKEN'] = true; break;
				case 'secret-error': $state['cleanup']['journal'] = 'SECRET-PASSWORD'; break;
				case 'partial-intent': $state['intents'][] = array( 'kind' => 'user', 'details' => array(), 'resolved' => true ); break;
				case 'partial-upload': $state['upload'] = array( 'path' => 'foreign' ); break;
				case 'unproven-user': $state['users']['administrator'] = 1; break;
			}
			$bad = Wstm108_Files::update( $path, $original, json_encode( $state, JSON_THROW_ON_ERROR ) );
			try {
				Wstm108_Resources::resume( $path, $binding );
				self::fail( 'Malformed journal must be retained and rejected.' );
			} catch ( RuntimeException $error ) {
				self::assertStringNotContainsString( 'SECRET-', $error->getMessage() );
				self::assertSame( $bad, Wstm108_Files::file( $path ) );
			}
			Wstm108_Files::update( $path, $bad, $original['bytes'] );
		}
	}

	public function test_fresh_process_reads_exact_persisted_proof_and_retires_only_explicitly(): void {
		$prepared = $this->run_raw_fixture( 'prepare-finalize' );
		self::assertTrue( $prepared['proof']['cleanup_complete'] );
		self::assertTrue( $prepared['proof_persisted'] );
		self::assertFileExists( $this->directory . DIRECTORY_SEPARATOR . 'resources.json' );
		$finalized = $this->run_raw_fixture( 'finalize' );
		self::assertFalse( $finalized['rejected'] );
		self::assertNotSame( $prepared['pid'], $finalized['pid'] );
		self::assertSame( $prepared['proof'], $finalized['proof'] );
		self::assertTrue( $finalized['persisted_matches'] );
		self::assertFalse( $finalized['journal_exists'] );
		self::assertTrue( $finalized['no_secrets'] );
	}

	public function test_fresh_process_recovers_lost_session_results_without_fabricating_http_success(): void {
		$prepared = $this->run_raw_fixture( 'prepare-recovery' );
		self::assertTrue( $prepared['prepared'] );
		$recovered = $this->run_raw_fixture( 'recover' );
		self::assertFalse( $recovered['rejected'] );
		self::assertNotSame( $prepared['pid'], $recovered['pid'] );
		self::assertFalse( $recovered['proof']['cleanup_complete'] );
		self::assertSame( 'HTTP:gateway:administrator', $recovered['proof']['first_error'] );
		self::assertSame( 0, $recovered['sessions_remaining'] );
		self::assertSame( 0, $recovered['passwords_remaining'] );
		self::assertSame( array( 99 ), $recovered['users'] );
		self::assertSame( array( 99 ), $recovered['posts'] );
		self::assertTrue( $recovered['journal_exists'] );
		self::assertTrue( $recovered['persisted_matches'] );
		$inspected = $this->run_raw_fixture( 'inspect' );
		self::assertSame( $recovered['proof'], $inspected['proof'] );
		self::assertTrue( $inspected['retire_rejected'] );
		self::assertTrue( $inspected['journal_exists'] );
	}

	public function test_fresh_process_after_cleanup_interruption_only_recovers_owned_credentials(): void {
		$prepared = $this->run_raw_fixture( 'prepare-interrupted' );
		self::assertTrue( $prepared['prepared'] );
		$recovered = $this->run_raw_fixture( 'recover' );
		self::assertFalse( $recovered['rejected'] );
		self::assertNotSame( $prepared['pid'], $recovered['pid'] );
		self::assertFalse( $recovered['proof']['cleanup_complete'] );
		self::assertSame( 'recovery-resumed', $recovered['proof']['first_error'] );
		self::assertSame( 0, $recovered['sessions_remaining'] );
		self::assertSame( 0, $recovered['passwords_remaining'] );
		self::assertSame( array(), $recovered['deleted_users'] );
		self::assertSame( array(), $recovered['deleted_posts'] );
		self::assertTrue( $recovered['file_exists'] );
		self::assertTrue( $recovered['directory_exists'] );
		self::assertTrue( $recovered['persisted_matches'] );
		$again = $this->run_raw_fixture( 'recover' );
		self::assertFalse( $again['proof']['cleanup_complete'] );
		self::assertSame( $recovered['proof']['first_error'], $again['proof']['first_error'] );
		self::assertIsString( $again['proof']['cleanup']['recovery-resumed'] );
		$inspected = $this->run_raw_fixture( 'inspect' );
		self::assertSame( $again['proof'], $inspected['proof'] );
		self::assertTrue( $inspected['retire_rejected'] );
	}

	public function test_fresh_process_cannot_adopt_another_source_binding(): void {
		$this->run_raw_fixture( 'prepare-recovery' );
		$foreign = $this->run_raw_fixture( 'inspect-foreign' );
		self::assertTrue( $foreign['rejected'] );
		self::assertTrue( $foreign['unchanged'] );
		self::assertTrue( $foreign['no_secrets'] );
		self::assertSame( array(), $foreign['deleted_users'] );
	}

	public static function corrupted_saved_state(): array {
		return array_map( static fn( $mode ) => array( $mode ), array(
			'missing-proof', 'missing-proof-labels', 'proof-source', 'proof-digest', 'self-identity',
			'run', 'extra-field', 'intent-type', 'file-identity', 'upload-identity',
		) );
	}

	/** @dataProvider corrupted_saved_state */
	public function test_fresh_process_rejects_malformed_full_state_and_persisted_proof( string $control ): void {
		$this->run_raw_fixture( 'prepare-finalize' );
		$path = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
		$before = Wstm108_Files::file( $path );
		$state = json_decode( $before['bytes'], true, 512, JSON_THROW_ON_ERROR );
		switch ( $control ) {
			case 'missing-proof': unset( $state['proof'] ); break;
			case 'missing-proof-labels': $state['proof']['cleanup'] = array(); break;
			case 'proof-source': $state['proof']['source_sha'] = str_repeat( 'd', 40 ); break;
			case 'proof-digest': $state['proof']['inventory_sha256'] = str_repeat( '0', 64 ); break;
			case 'self-identity': ++$state['journal_identity']['ino']; break;
			case 'run': $state['run'] = 'foreign-run'; break;
			case 'extra-field': $state['foreign'] = 'unexpected'; break;
			case 'intent-type': $state['intents'][0]['details']['role'] = array(); break;
			case 'file-identity': $state['files'][ array_key_first( $state['files'] ) ]['identity']['nlink'] = 2; break;
			case 'upload-identity': $state['upload']['identity']['mode'] = array(); break;
		}
		Wstm108_Files::update( $path, $before, json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		$result = $this->run_raw_fixture( 'inspect-malformed' );
		self::assertTrue( $result['rejected'] );
		self::assertTrue( $result['unchanged'] );
		self::assertTrue( $result['no_secrets'] );
		self::assertSame( array(), $result['deleted_users'] );
	}

	private function proof_binding(): array {
		return array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'unit-test', 'source_sha' => str_repeat( 'b', 40 ),
			'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
	}

	private function digest_proof( array $proof ): array {
		unset( $proof['cleanup_sha256'] );
		$proof['cleanup_sha256'] = hash_hmac( 'sha256', json_encode( $proof, JSON_THROW_ON_ERROR ), $this->proof_binding()['owner'] );
		return $proof;
	}

	private function assert_invalid_public_proof( array $proof, array $binding ): void {
		try {
			Wstm108_Resources::validate_proof( $proof, $binding );
			self::fail( 'Malformed, incomplete or foreign proof must be rejected.' );
		} catch ( RuntimeException $error ) {
			self::assertStringNotContainsString( 'SECRET-', $error->getMessage() );
		}
	}

	public function test_public_validator_is_pure_in_a_process_without_any_wordpress_bootstrap(): void {
		$proof = $this->run_raw_fixture( 'prepare-finalize' )['proof'];
		$code = <<<'PHP'
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
Wstm108_Resources::validate_proof($input['proof'], $input['binding']);
echo json_encode(array('accepted' => true, 'no_wp' => !function_exists('get_post') && !function_exists('get_userdata') && !class_exists('WP_Query') && !isset($GLOBALS['wpdb'])), JSON_THROW_ON_ERROR);
PHP;
		$process = proc_open( array( PHP_BINARY, '-r', $code, dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'e2e' . DIRECTORY_SEPARATOR . 'untrusted-content-resources.php' ),
			array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fwrite( $pipes[0], json_encode( array( 'proof' => $proof, 'binding' => $this->proof_binding() ), JSON_THROW_ON_ERROR ) );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error . $output );
		self::assertSame( '', $error );
		self::assertSame( array( 'accepted' => true, 'no_wp' => true ), json_decode( $output, true, 512, JSON_THROW_ON_ERROR ) );
		self::assertSame( array( 'posts' => 4, 'comments' => 1, 'files' => 1 ), $proof['resource_counts'] );
		$labels = array_keys( $proof['cleanup'] );
		$sorted = $labels;
		sort( $sorted, SORT_STRING );
		self::assertSame( $sorted, $labels );
		foreach ( array( 'administrator', 'subscriber', 'reader' ) as $role ) {
			self::assertTrue( $proof['cleanup'][ 'actor:' . $role ] );
			self::assertTrue( $proof['cleanup'][ 'password:' . $role ] );
			foreach ( array( 'gateway', 'individual' ) as $boundary ) {
				self::assertTrue( $proof['cleanup'][ 'HTTP:' . $boundary . ':' . $role ] );
				self::assertTrue( $proof['cleanup'][ 'session:' . $boundary . ':' . $role ] );
			}
		}
		$public = json_encode( $proof, JSON_THROW_ON_ERROR );
		foreach ( array( 'SECRET-', 'PRIVATE-UUID' ) as $secret ) {
			self::assertStringNotContainsString( $secret, $public );
		}
	}

	public function test_public_validator_rejects_every_missing_category_and_nonboolean_outcome_even_with_rehashed_proof(): void {
		$proof = $this->run_raw_fixture( 'prepare-finalize' )['proof'];
		$binding = $this->proof_binding();
		foreach ( array_keys( $proof['cleanup'] ) as $label ) {
			$partial = $proof;
			unset( $partial['cleanup'][ $label ] );
			$this->assert_invalid_public_proof( $this->digest_proof( $partial ), $binding );
			foreach ( array( false, 1, 'true', array(), null, 'SECRET-TOKEN' ) as $outcome ) {
				$invalid = $proof;
				$invalid['cleanup'][ $label ] = $outcome;
				$this->assert_invalid_public_proof( $this->digest_proof( $invalid ), $binding );
			}
		}
		foreach ( array( 'empty', 'reordered', 'unknown', 'ordinal-gap', 'all-posts-missing' ) as $control ) {
			$invalid = $proof;
			switch ( $control ) {
				case 'empty': $invalid['cleanup'] = array(); break;
				case 'reordered': $invalid['cleanup'] = array_reverse( $invalid['cleanup'], true ); break;
				case 'unknown': $invalid['cleanup']['session:foreign:administrator'] = true; ksort( $invalid['cleanup'], SORT_STRING ); break;
				case 'ordinal-gap': unset( $invalid['cleanup']['post:4'] ); $invalid['cleanup']['post:5'] = true; ksort( $invalid['cleanup'], SORT_STRING ); break;
				case 'all-posts-missing':
					$invalid['resource_counts']['posts'] = 0;
					foreach ( array_keys( $invalid['cleanup'] ) as $label ) {
						if ( str_starts_with( $label, 'post:' ) ) { unset( $invalid['cleanup'][ $label ] ); }
					}
					break;
			}
			$this->assert_invalid_public_proof( $this->digest_proof( $invalid ), $binding );
		}
	}

	public function test_public_validator_rejects_partial_foreign_and_malformed_proof_and_binding(): void {
		$proof = $this->run_raw_fixture( 'prepare-finalize' )['proof'];
		$binding = $this->proof_binding();
		foreach ( array_keys( $proof ) as $key ) {
			$invalid = $proof;
			unset( $invalid[ $key ] );
			$this->assert_invalid_public_proof( $invalid, $binding );
		}
		foreach ( array( 'owner', 'project', 'source_sha', 'tree_sha', 'package_sha256' ) as $key ) {
			$foreign = $binding;
			$foreign[ $key ] = array( 'owner' => str_repeat( 'd', 32 ), 'project' => 'foreign-project',
				'source_sha' => str_repeat( 'd', 40 ), 'tree_sha' => str_repeat( 'd', 40 ), 'package_sha256' => str_repeat( 'd', 64 ) )[ $key ];
			$this->assert_invalid_public_proof( $proof, $foreign );
			unset( $foreign[ $key ] );
			$this->assert_invalid_public_proof( $proof, $foreign );
		}
		$this->assert_invalid_public_proof( $proof, array_reverse( $binding, true ) );
		$this->assert_invalid_public_proof( array( 'cleanup_complete' => true ), $binding );
		$this->assert_invalid_public_proof( array_reverse( $proof, true ), $binding );
		foreach ( array(
			array( 'version', '1' ), array( 'cleanup_complete', 1 ), array( 'cleanup_complete', false ),
			array( 'failed', '0' ), array( 'failed', 1 ), array( 'first_error', 'SECRET-TOKEN' ),
			array( 'source_sha', str_repeat( 'd', 40 ) ), array( 'tree_sha', str_repeat( 'd', 40 ) ),
			array( 'package_sha256', str_repeat( 'd', 64 ) ), array( 'binding_sha256', str_repeat( 'd', 64 ) ),
			array( 'inventory_sha256', '' ), array( 'inventory_sha256', null ), array( 'inventory_sha256', str_repeat( 'A', 64 ) ),
			array( 'resource_counts', array() ), array( 'resource_counts', array( 'posts' => 4, 'files' => 1, 'comments' => 1 ) ),
			array( 'resource_counts', array( 'posts' => '4', 'comments' => 1, 'files' => 1 ) ),
			array( 'resource_counts', array( 'posts' => PHP_INT_MAX, 'comments' => 1, 'files' => 1 ) ),
			array( 'session_token', 'SECRET-TOKEN' ),
		) as list( $key, $value ) ) {
			$invalid = $proof;
			$invalid[ $key ] = $value;
			$this->assert_invalid_public_proof( $this->digest_proof( $invalid ), $binding );
		}
		foreach ( array( 'inventory_sha256', 'cleanup_sha256' ) as $key ) {
			$invalid = $proof;
			$invalid[ $key ] = str_repeat( 'd', 64 );
			$this->assert_invalid_public_proof( $invalid, $binding );
		}
		$public_only = $proof;
		--$public_only['resource_counts']['posts'];
		unset( $public_only['cleanup']['post:4'], $public_only['cleanup_sha256'] );
		$public_only['cleanup_sha256'] = hash( 'sha256', json_encode( $public_only, JSON_THROW_ON_ERROR ) );
		$this->assert_invalid_public_proof( $public_only, $binding );
		$wrong_key = $proof;
		unset( $wrong_key['cleanup_sha256'] );
		$wrong_key['cleanup_sha256'] = hash_hmac( 'sha256', json_encode( $wrong_key, JSON_THROW_ON_ERROR ), str_repeat( 'd', 32 ) );
		$this->assert_invalid_public_proof( $wrong_key, $binding );
	}

	public static function structurally_valid_foreign_proofs(): array {
		return array( array( 'inventory' ), array( 'reduced-count' ) );
	}

	/** @dataProvider structurally_valid_foreign_proofs */
	public function test_finalizer_requires_exact_journal_proof_not_just_pure_validation( string $control ): void {
		$prepared = $this->run_raw_fixture( 'prepare-finalize' );
		$proof = $prepared['proof'];
		if ( 'inventory' === $control ) {
			$proof['inventory_sha256'] = str_repeat( 'd', 64 );
		} else {
			--$proof['resource_counts']['posts'];
			unset( $proof['cleanup']['post:4'] );
		}
		$proof = $this->digest_proof( $proof );
		Wstm108_Resources::validate_proof( $proof, $this->proof_binding() );
		$path = $this->directory . DIRECTORY_SEPARATOR . 'fake-database.json';
		$before = Wstm108_Files::file( $path );
		$database = json_decode( $before['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$database['proof'] = $proof;
		Wstm108_Files::update( $path, $before, json_encode( $database, JSON_THROW_ON_ERROR ) );
		$result = $this->run_raw_fixture( 'finalize-reject' );
		self::assertFalse( $result['rejected'] );
		self::assertTrue( $result['retire_rejected'] );
		self::assertTrue( $result['journal_exists'] );
		self::assertTrue( $result['journal_unchanged'] );
		self::assertSame( $prepared['proof'], $result['proof'] );
	}

	public function test_verify_absent_is_read_only_on_success_in_a_fresh_process(): void {
		$prepared = $this->run_raw_fixture( 'prepare-finalize' );
		$result = $this->run_raw_fixture( 'verify-absent' );
		self::assertNotSame( $prepared['pid'], $result['pid'] );
		self::assertFalse( $result['rejected'] );
		self::assertTrue( $result['journal_exists'] );
		self::assertTrue( $result['journal_unchanged'] );
		self::assertTrue( $result['database_unchanged'] );
		self::assertTrue( $result['file_unchanged'] );
		self::assertTrue( $result['directory_unchanged'] );
		self::assertSame( array(), $result['deleted_users'] );
		self::assertSame( array(), $result['deleted_posts'] );
		self::assertSame( array(), $result['deleted_passwords'] );
	}

	public static function reappeared_resources(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'post', 'postmeta', 'revision', 'comment', 'commentmeta', 'user', 'usermeta', 'session', 'password',
			'owned-author-post', 'owned-author-comment', 'actor-name', 'actor-email', 'file', 'directory', 'cron',
			'option-exact', 'option-dash', 'option-colon', 'option-underscore',
		) );
	}

	/** @dataProvider reappeared_resources */
	public function test_fresh_reappearance_blocks_verification_and_retirement_without_any_mutations( string $kind ): void {
		$prepared = $this->run_raw_fixture( 'prepare-finalize' );
		foreach ( array( 'verify-absent', 'retire-reappeared' ) as $operation ) {
			$result = $this->run_raw_fixture( $operation, $kind );
			self::assertNotSame( $prepared['pid'], $result['pid'] );
			self::assertTrue( $result['rejected'], $operation . ':' . $kind );
			self::assertTrue( $result['no_secrets'] );
			self::assertTrue( $result['journal_exists'] );
			self::assertTrue( $result['journal_unchanged'] );
			self::assertTrue( $result['database_unchanged'] );
			self::assertTrue( $result['file_unchanged'] );
			self::assertTrue( $result['directory_unchanged'] );
			self::assertSame( array(), $result['deleted_users'] );
			self::assertSame( array(), $result['deleted_posts'] );
			self::assertSame( array(), $result['deleted_passwords'] );
			if ( in_array( $kind, array( 'file', 'directory' ), true ) ) {
				// Remove only this test's reappearance before the independent retry.
				$path = $this->directory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'wstm108-0123456789abcdef';
				if ( 'file' === $kind ) { unlink( $path . DIRECTORY_SEPARATOR . 'wstm108-0123456789abcdef-雪.png' ); }
				rmdir( $path );
			}
		}

	}

	public function test_posix_owned_child_link_delta_preserves_every_other_parent_identity_field(): void {
		$check = new ReflectionMethod( Wstm108_Resources::class, 'owned_upload_parent' );
		$before = array( 'dev' => 17, 'ino' => 123456, 'uid' => 1000, 'gid' => 1000, 'mode' => 0040755, 'nlink' => 2 );
		$owned_child = $before;
		++$owned_child['nlink'];
		self::assertTrue( $check->invoke( null, $before, $owned_child ), 'A single POSIX child adds precisely one parent link.' );
		self::assertTrue( $check->invoke( null, $before, $before ), 'Filesystems reporting invariant directory link counts remain supported.' );
		foreach ( array( 0, 1, 4, 5 ) as $links ) {
			$foreign = $before;
			$foreign['nlink'] = $links;
			self::assertFalse( $check->invoke( null, $before, $foreign ), 'Unknown parent link-count changes must fail.' );
		}
		foreach ( array( 'dev', 'ino', 'uid', 'gid', 'mode' ) as $field ) {
			$foreign = $owned_child;
			++$foreign[ $field ];
			self::assertFalse( $check->invoke( null, $before, $foreign ), 'The owned child cannot excuse a change to ' . $field . '.' );
		}
	}

	public static function invalid_saved_parent_link_deltas(): array {
		return array( array( 2 ), array( 3 ) );
	}

	/** @dataProvider invalid_saved_parent_link_deltas */
	public function test_resume_rejects_unaccounted_parent_links_before_any_cleanup( int $delta ): void {
		$root = $this->directory . DIRECTORY_SEPARATOR . 'uploads';
		self::assertTrue( mkdir( $root, 0755 ) );
		$path = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
		$resources = new Wstm108_Resources( $path, $this->proof_binding(), 'wstm108-0123456789abcdef' );
		$original_parent = Wstm108_Files::directory( $root );
		$owned = $resources->create_upload_directory( $root );
		$before = Wstm108_Files::file( $path );
		$state = json_decode( $before['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$state['upload']['active_root_identity']['nlink'] = $original_parent['nlink'] + $delta;
		$foreign = Wstm108_Files::update( $path, $before, json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
		$active_parent = Wstm108_Files::directory( $root );
		try {
			Wstm108_Resources::resume( $path, $this->proof_binding() );
			self::fail( 'Unaccounted link delta must not be accepted from a private journal.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $foreign, Wstm108_Files::file( $path ) );
			self::assertSame( $active_parent, Wstm108_Files::directory( $root ) );
			self::assertDirectoryExists( $owned );
		}
	}
}
