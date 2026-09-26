<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/untrusted-stage-envelope.php';

final class UntrustedStageProofTest extends TestCase {
	private string $root;
	private array $context;
	private string $sha256;
	private object $runner;
	private string $source_root;
	private bool $created_build = false;

	protected function setUp(): void {
		$this->source_root = str_replace( '\\', '/', realpath( dirname( __DIR__, 2 ) ) );
		$build = $this->source_root . '/build';
		if ( ! file_exists( $build ) ) {
			self::assertTrue( mkdir( $build, 0700 ) );
			$this->created_build = true;
		}
		Wstm108_Files::directory( $build );
		$owner = bin2hex( random_bytes( 16 ) );
		$root = $build . '/untrusted-' . $owner;
		self::assertTrue( mkdir( $root, 0700 ) );
		$this->root = str_replace( '\\', '/', realpath( $root ) );
		$this->context = Wstm108_ProofFixture::context();
		$this->context['binding']['owner'] = $owner;
		$this->context['host_harness_root'] = $this->source_root;
		$this->context['artifact_directory'] = 'build/untrusted-' . $owner;
		$this->sha256 = hash( 'sha256', json_encode( $this->context, JSON_THROW_ON_ERROR ) );
		$this->runner = Wstm108_ProofFixture::runner( $this->context, $this->sha256 );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/*' ) as $file ) { unlink( $file ); }
		rmdir( $this->root );
		if ( $this->created_build ) { rmdir( $this->source_root . '/build' ); }
	}

	private function certificate(): Wstm108_Proof {
		return Wstm108_Proof::runner( $this->runner, $this->context, $this->sha256, Wstm108_ProofFixture::native() );
	}

	public function test_complete_source_and_original_zip_proofs_issue_only_validated_certificates(): void {
		foreach ( array( null, str_repeat( 'f', 64 ) ) as $archive ) {
			$this->context['binding']['package_sha256'] = $archive;
			$this->runner = Wstm108_ProofFixture::runner( $this->context, $this->sha256 );
			$receipt = $this->certificate()->receipt();
			self::assertSame( $this->context['binding'], $receipt['binding'] );
			self::assertTrue( $receipt['cleanup_complete'] );
			self::assertSame( 286, count( $this->runner->cases ) );
			self::assertSame( 90, count( get_object_vars( $this->runner->registered ) ) );
			self::assertSame( Wstm108_ProofFixture::resources( $this->context['binding'] ), $receipt['resource_proof'] );
		}
		self::assertTrue( ( new ReflectionClass( Wstm108_Proof::class ) )->getConstructor()->isPrivate() );
	}

	public function test_failed_semantic_case_preserves_failure_and_vetoes_retirement_despite_safe_resource_cleanup(): void {
		$this->runner->cases[100]->passed = false;
		$this->runner->cases[100]->error = 'Observed mismatch; raw response retained';
		--$this->runner->passed;
		++$this->runner->failed;
		$this->runner->status = 'failed';
		self::assertTrue( $this->runner->cleanup_complete );
		try {
			$this->certificate();
			self::fail( 'Successful resource cleanup must not retire failed wire/semantic evidence.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'case counts', $error->getMessage() );
		}
		self::assertSame( 'failed', $this->runner->status );
		self::assertSame( 1, $this->runner->failed );
	}

	public static function invalid_runner(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'incomplete', 'cleanup', 'source', 'tree', 'owner', 'project', 'archive', 'context',
			'boundary', 'invoked', 'files', 'runtime', 'inventory', 'missing-case', 'extra-case',
			'reordered-case', 'duplicate-case', 'nonobject-case', 'integer-outcome', 'wrong-passed',
			'wrong-failed', 'wrong-status', 'blocked', 'fatal', 'missing-registration', 'registration-list', 'registration-fields', 'registration-digest',
			'missing-actor', 'actor-id', 'roles-object', 'caps-list', 'missing-catalog', 'extra-catalog',
			'catalog-count', 'duplicate-tool', 'empty-tool-name', 'schema-list', 'resource-missing',
			'resource-label', 'resource-outcome', 'resource-digest', 'resource-count',
			'reader-capability', 'subscriber-capability', 'wire-extra', 'wire-scope', 'wire-semantic-hash',
			'case-extra', 'case-hash', 'verdict-extra', 'verdict-missing-event', 'verdict-shared-event', 'verdict-unordered', 'verdict-no-terminal',
		) );
	}

	/** @dataProvider invalid_runner */
	public function test_missing_partial_typed_or_foreign_runner_evidence_never_issues_certificate( string $kind ): void {
		$p = $this->runner;
		switch ( $kind ) {
			case 'incomplete': $p->completed = false; break;
			case 'cleanup': $p->cleanup_complete = false; break;
			case 'source': $p->binding->source_sha = str_repeat( 'e', 40 ); break;
			case 'tree': $p->binding->tree_sha = str_repeat( 'e', 40 ); break;
			case 'owner': $p->binding->owner = str_repeat( 'e', 32 ); break;
			case 'project': $p->binding->project = 'foreign'; break;
			case 'archive': $p->binding->package_sha256 = str_repeat( 'e', 64 ); break;
			case 'context': $p->stage_context_sha256 = str_repeat( 'e', 64 ); break;
			case 'boundary': $p->boundaries = array( 'gateway' ); break;
			case 'invoked': $p->invoked_boundaries = array_reverse( $p->invoked_boundaries ); break;
			case 'files': $p->actual_files->production->{'plugin.php'} = str_repeat( '0', 64 ); break;
			case 'runtime': $p->actual_runtime = null; break;
			case 'inventory': array_pop( $p->expected_labels ); break;
			case 'missing-case': array_pop( $p->cases ); break;
			case 'extra-case': $p->cases[] = end( $p->cases ); break;
			case 'reordered-case': $p->cases = array_reverse( $p->cases ); break;
			case 'duplicate-case': $p->cases[1] = $p->cases[0]; break;
			case 'nonobject-case': $p->cases[0] = array( 'label' => $p->cases[0]->label, 'passed' => true ); break;
			case 'integer-outcome': $p->cases[0]->passed = 1; break;
			case 'wrong-passed': --$p->passed; break;
			case 'wrong-failed': ++$p->failed; break;
			case 'wrong-status': $p->status = 'failed'; break;
			case 'blocked': $p->blocked = array( 'provider unavailable' ); break;
			case 'fatal': $p->fatal = 'fatal'; break;
			case 'missing-registration': unset( $p->registered->{array_key_first( get_object_vars( $p->registered ) )} ); break;
			case 'registration-list': $p->registered = array_values( get_object_vars( $p->registered ) ); break;
			case 'registration-fields': $p->registered->{array_key_first( get_object_vars( $p->registered ) )} = (object) array(); break;
			case 'registration-digest': $p->actual_runtime->abilities->{array_key_first( get_object_vars( $p->registered ) )} = str_repeat( '0', 64 ); break;
			case 'missing-actor': unset( $p->actors->reader ); break;
			case 'actor-id': $p->actors->reader->id = '3'; break;
			case 'roles-object': $p->actors->reader->roles = (object) array(); break;
			case 'caps-list': $p->actors->reader->caps = array(); break;
			case 'missing-catalog': unset( $p->catalogs->{'individual:reader'} ); break;
			case 'extra-catalog': $p->catalogs->foreign = array(); break;
			case 'catalog-count': array_pop( $p->catalogs->{'individual:reader'} ); break;
			case 'duplicate-tool': $p->catalogs->{'individual:reader'}[1]->name = $p->catalogs->{'individual:reader'}[0]->name; break;
			case 'empty-tool-name': $p->catalogs->{'individual:reader'}[0]->name = ''; break;
			case 'schema-list': $p->catalogs->{'individual:reader'}[0]->inputSchema = array(); break;
			case 'resource-missing': unset( $p->resource_proof ); break;
			case 'resource-label': unset( $p->resource_proof->cleanup->coverage ); break;
			case 'resource-outcome': $p->resource_proof->cleanup->coverage = 1; break;
			case 'resource-digest': $p->resource_proof->cleanup_sha256 = str_repeat( '0', 64 ); break;
			case 'resource-count': $p->resource_proof->resource_counts->posts = 0; break;
			case 'reader-capability': $p->actors->reader->list_users = false; break;
			case 'subscriber-capability': $p->actors->subscriber->list_users = true; break;
			case 'wire-extra': $p->wire_proof->unexpected_body = 'must remain private'; break;
			case 'wire-scope': $p->wire_proof->scope = 'original'; break;
			case 'wire-semantic-hash': $p->wire_proof->semantic_sha256 = 'not a digest'; break;
			case 'case-extra': $p->cases[0]->response = 'must remain private'; break;
			case 'case-hash':
				$p->cases[0]->private_case_sha256 = 'not a digest';
				$p->wire_proof->cases->{$p->cases[0]->label}->sha256 = 'not a digest';
				break;
			case 'verdict-extra': $p->wire_proof->cases->{$p->cases[0]->label}->body = 'must remain private'; break;
			case 'verdict-missing-event': $p->wire_proof->cases->{$p->cases[0]->label}->events = array( 99999 ); break;
			case 'verdict-shared-event': $p->wire_proof->cases->{$p->cases[1]->label}->events = $p->wire_proof->cases->{$p->cases[0]->label}->events; break;
			case 'verdict-unordered': $p->wire_proof->cases->{$p->cases[0]->label}->events = array( 7, 6 ); break;
			case 'verdict-no-terminal': $p->wire_proof->cases->{$p->cases[0]->label}->events = array( 1 ); break;
		}
		$this->expectException( RuntimeException::class );
		$this->certificate();
	}

	public static function unsafe_witnesses(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'scope', 'body-length', 'body-hash', 'status-type', 'status-range', 'origin-extra', 'origin-value', 'projection', 'extra-key' ) );
	}

	/** @dataProvider unsafe_witnesses */
	public function test_public_witness_metadata_cannot_carry_unvalidated_data( string $kind ): void {
		$event = Wstm108_ProofFixture::event( 1, 'runner', array( 'boundary' => 'wordpress-oracle' ), '{}', 'oracle' );
		switch ( $kind ) {
			case 'scope': $event['scope'] = 'foreign'; break;
			case 'body-length': $event['body_length'] = null; break;
			case 'body-hash': $event['body_sha256'] = 'private arbitrary value'; break;
			case 'status-type': $event['status'] = '200'; break;
			case 'status-range': $event['status'] = 600; break;
			case 'origin-extra': $event['origin']->body = 'private arbitrary value'; break;
			case 'origin-value': $event['origin']->boundary = 'private arbitrary value'; break;
			case 'projection': $event['validation'] = array( 'body' => 'private arbitrary value' ); break;
			case 'extra-key': $event['body'] = 'private arbitrary value'; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm108_Proof::witnesses( Wstm108_ProofFixture::journal( array( $event ) ) );
	}

	private function phases(): void {
		foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize', 'retire' ) as $phase ) {
			$record = Wstm108_ProofFixture::phase( $phase, $this->context, $this->sha256 );
			file_put_contents( $this->root . '/' . $phase . '.json', json_encode( $record, JSON_THROW_ON_ERROR ) );
			if ( isset( $record->wire_proof ) ) { file_put_contents( $this->root . '/' . $phase . '.json.http.jsonl', Wstm108_ProofFixture::journal( $record->wire_proof->events ) ); }
		}
		file_put_contents( $this->root . '/original.json.http.jsonl', Wstm108_ProofFixture::controls() );
	}

	public function test_complete_original_enabled_restored_and_boundary_evidence_passes(): void {
		$this->phases();
		Wstm108_Proof::lifecycle( $this->root, $this->runner, $this->context, $this->sha256 );
		self::assertTrue( true );
	}

	public static function invalid_retirement_binding(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'prepared-missing', 'prepared-extra', 'prepared-generation', 'prepared-count', 'prepared-substitution',
			'process-missing', 'process-extra', 'process-reordered', 'process-exit', 'process-partial',
			'process-stderr', 'process-empty-stdout', 'process-private-body', 'process-links', 'process-prefix-substitution',
		) );
	}

	/** @dataProvider invalid_retirement_binding */
	public function test_retirement_requires_exact_prepared_binding_and_complete_safe_process_prefix( string $kind ): void {
		$this->phases();
		$path = $this->root . '/retire.json';
		$record = json_decode( file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
		switch ( $kind ) {
			case 'prepared-missing': unset( $record->prepared ); break;
			case 'prepared-extra': $record->prepared->body = 'must remain private'; break;
			case 'prepared-generation': $record->prepared->generation = 'not a generation'; break;
			case 'prepared-count': $record->prepared->target_count = '20'; break;
			case 'prepared-substitution': $record->prepared->state_sha256 = str_repeat( '0', 64 ); break;
			case 'process-missing': unset( $record->process_verdicts ); break;
			case 'process-extra': $record->process_verdicts->body = 'must remain private'; break;
			case 'process-reordered': $record->process_verdicts->processes = array_reverse( $record->process_verdicts->processes ); break;
			case 'process-exit': $record->process_verdicts->processes[0]->witness->child_exit = 73; break;
			case 'process-partial': $record->process_verdicts->processes[0]->witness->capture_complete = false; break;
			case 'process-stderr':
				$record->process_verdicts->processes[0]->witness->stderr->length = 1;
				$record->process_verdicts->processes[0]->witness->stderr->sha256 = hash( 'sha256', 'x' );
				break;
			case 'process-empty-stdout': $record->process_verdicts->processes[0]->witness->stdout->length = 0; break;
			case 'process-private-body': $record->process_verdicts->processes[0]->witness->stdout->body = 'must remain private'; break;
			case 'process-links': $record->process_verdicts->processes[0]->witness->stdout->identity->nlink = 2; break;
			case 'process-prefix-substitution': $record->process_verdicts->processes[0]->witness->stdout->sha256 = str_repeat( '0', 64 ); break;
		}
		$bytes = json_encode( $record, JSON_THROW_ON_ERROR );
		file_put_contents( $path, $bytes );
		try {
			Wstm108_Proof::lifecycle( $this->root, $this->runner, $this->context, $this->sha256 );
			self::fail( 'Invalid preparation/process binding must refuse retirement certification.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $bytes, file_get_contents( $path ) );
			self::assertFileExists( $this->root . '/finalize.json' );
		}
	}

	public static function host_invocations(): array {
		return array(
			array( false, 'none' ), array( true, 'none' ), array( false, 'foreign-identity' ), array( true, 'foreign-identity' ),
			array( false, 'escape' ), array( true, 'foreign-file' ), array( false, 'wrong-root' ),
			array( false, 'foreign-context-root' ), array( false, 'traversal-context' ), array( false, 'symlink' ), array( false, 'hardlink' ),
		);
	}

	/** @dataProvider host_invocations */
	public function test_real_host_parser_accepts_owned_relative_or_absolute_paths_and_rejects_foreign_binding( bool $absolute, string $fault ): void {
		if ( 'foreign-context-root' === $fault ) { $this->context['host_harness_root'] = $this->source_root . '/foreign'; }
		if ( 'traversal-context' === $fault ) { $this->context['artifact_directory'] = 'build/../' . $this->context['artifact_directory']; }
		$context = json_encode( $this->context, JSON_THROW_ON_ERROR );
		$this->sha256 = hash( 'sha256', $context );
		$this->runner = Wstm108_ProofFixture::runner( $this->context, $this->sha256 );
		$this->phases();
		$journal = Wstm108_ProofFixture::runner_journal( $this->runner );
		$this->runner->http_journal_sha256 = hash( 'sha256', $journal );
		file_put_contents( $this->root . '/context.json', $context );
		file_put_contents( $this->root . '/runner.json', json_encode( $this->runner, JSON_THROW_ON_ERROR ) );
		file_put_contents( $this->root . '/runner.json.http.jsonl', $journal );
		$before = hash_file( 'sha256', $this->root . '/runner.json' );
		$prefix = $absolute ? $this->root . '/' : '';
		$proof_path = $prefix . 'runner.json';
		if ( 'escape' === $fault ) { $proof_path = '../' . basename( $this->root ) . '/runner.json'; }
		if ( 'foreign-file' === $fault ) {
			copy( $this->root . '/runner.json', $this->root . '/foreign.json' );
			$proof_path = $this->root . '/foreign.json';
		}
		if ( in_array( $fault, array( 'symlink', 'hardlink' ), true ) ) {
			rename( $this->root . '/runner.json', $this->root . '/foreign.json' );
			error_clear_last();
			$linked = 'symlink' === $fault
				? @symlink( $this->root . '/foreign.json', $this->root . '/runner.json' )
				: link( $this->root . '/foreign.json', $this->root . '/runner.json' );
			$link_error = error_get_last()['message'] ?? '';
			if ( ! $linked && 'symlink' === $fault && 'Windows' === PHP_OS_FAMILY
				&& ( 'symlink(): Permission denied' === $link_error || 1 === preg_match( '/\b1314\b/', $link_error ) ) ) {
				$this->markTestSkipped( 'Windows denied symlink creation after successful owned file creation; Linux must execute this control: ' . $link_error );
			}
			self::assertTrue( $linked, 'Unexpected link creation failure: ' . $link_error );
		}
		$directory = 'wrong-root' === $fault ? $this->source_root . '/build' : $this->root;
		$foreign = 'none' !== $fault;
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/scripts/untrusted-cleanup-proof.php',
				$proof_path, $prefix . 'context.json', 'foreign-identity' === $fault ? str_repeat( '0', 40 ) : $this->context['binding']['source_sha'],
				$this->context['binding']['project'], $this->context['binding']['owner'], $directory, '--final' ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes, $absolute || 'wrong-root' === $fault ? $this->root : $this->source_root
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( $foreign ? 1 : 0, proc_close( $process ), $error );
		if ( $foreign ) {
			self::assertSame( '', $out );
			self::assertStringContainsString( 'rejected; runtime retained', $error );
		} else {
			self::assertSame( '', $error );
			self::assertStringContainsString( 'Verified complete', $out );
		}
		self::assertSame( $before, hash_file( 'sha256', $this->root . '/runner.json' ) );
		self::assertSame( $journal, file_get_contents( $this->root . '/runner.json.http.jsonl' ) );
	}

	public static function invalid_lifecycle(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'missing-phase', 'incomplete-phase', 'foreign-phase', 'wrong-files', 'no-state', 'no-boot',
			'no-config', 'no-identity', 'empty-filesystem-identity', 'wrong-owner', 'no-provider', 'inactive-provider', 'no-options',
			'changed-config', 'changed-mode', 'changed-mu', 'changed-runtime', 'changed-provider',
			'wrong-enabled', 'partial-retirement', 'missing-controls', 'duplicate-control', 'wrong-http',
			'wrong-cli', 'wrong-body', 'wrong-raw-body', 'missing-raw-control', 'missing-stderr', 'foreign-file', 'nonobject-control',
		) );
	}

	/** @dataProvider invalid_lifecycle */
	public function test_partial_or_foreign_final_evidence_retains_runtime( string $kind ): void {
		$this->phases();
		$path = $this->root . '/finalize.json';
		$p = json_decode( file_get_contents( $path ), false, 512, JSON_THROW_ON_ERROR );
		$controls = array_map( static fn( $line ) => json_decode( $line, false, 512, JSON_THROW_ON_ERROR ), explode( "\n", rtrim( Wstm108_ProofFixture::controls(), "\n" ) ) );
		switch ( $kind ) {
			case 'missing-phase': unlink( $this->root . '/enable.json' ); break;
			case 'incomplete-phase': $p->completed = false; break;
			case 'foreign-phase': $p->stage_context_sha256 = str_repeat( '0', 64 ); break;
			case 'wrong-files': $p->actual_files = null; break;
			case 'no-state': unset( $p->state ); break;
			case 'no-boot': unset( $p->state->restored_attestation ); break;
			case 'no-config': unset( $p->state->config_sha256 ); break;
			case 'no-identity': unset( $p->state->restored_attestation->identity ); break;
			case 'empty-filesystem-identity': $p->state->config_identity = (object) array(); break;
			case 'wrong-owner': $p->state->restored_attestation->identity->owner = str_repeat( '0', 32 ); break;
			case 'no-provider': unset( $p->providers->yoast ); break;
			case 'inactive-provider': $p->providers->yoast->active = false; break;
			case 'no-options':
				$original = Wstm108_ProofFixture::phase( 'original', $this->context, $this->sha256 );
				unset( $original->stage_options_absent );
				file_put_contents( $this->root . '/original.json', json_encode( $original, JSON_THROW_ON_ERROR ) );
				break;
			case 'changed-config': $p->state->config_sha256 = str_repeat( '0', 64 ); break;
			case 'changed-mode': $p->state->config_identity->mode = 0100666; break;
			case 'changed-mu': $p->state->mu_identity->uid = 42; break;
			case 'changed-runtime': $p->state->restored_attestation->runtime->sha256 = str_repeat( '0', 64 ); break;
			case 'changed-provider': $p->providers->yoast->version_sha256 = str_repeat( '0', 64 ); break;
			case 'wrong-enabled': $this->runner->actual_runtime->sha256 = str_repeat( '0', 64 ); break;
			case 'partial-retirement':
				$retired = Wstm108_ProofFixture::phase( 'retire', $this->context, $this->sha256 );
				unset( $retired->retired->resource_journal );
				file_put_contents( $this->root . '/retire.json', json_encode( $retired, JSON_THROW_ON_ERROR ) );
				break;
			case 'missing-controls': array_pop( $controls ); break;
			case 'duplicate-control': $controls[] = $controls[0]; break;
			case 'wrong-http': $controls[2]->status = $controls[3]->status = 200; break;
			case 'wrong-cli': $controls[0]->status = $controls[1]->status = 1; break;
			case 'wrong-body':
				foreach ( array( 2, 3 ) as $index ) {
					$controls[ $index ]->body_sha256 = hash( 'sha256', 'not exact' );
					$controls[ $index ]->body_length = strlen( 'not exact' );
				}
				break;
			case 'wrong-raw-body': $controls[1]->body_base64 = base64_encode( 'different bytes' ); break;
			case 'missing-raw-control': unset( $controls[0]->body_sha256 ); break;
			case 'missing-stderr': unset( $p->process_verdicts->processes[0]->witness->stderr ); break;
			case 'foreign-file': $controls[0]->origin->file = 'foreign.php'; break;
			case 'nonobject-control': $controls[] = array(); break;
		}
		file_put_contents( $path, json_encode( $p, JSON_THROW_ON_ERROR ) );
		file_put_contents( $this->root . '/original.json.http.jsonl', implode( "\n", array_map( static fn( $entry ) => json_encode( $entry, JSON_THROW_ON_ERROR ), $controls ) ) . "\n" );
		$this->expectException( RuntimeException::class );
		Wstm108_Proof::lifecycle( $this->root, $this->runner, $this->context, $this->sha256 );
	}

	public static function journal_mutations(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'none', 'changed-bytes', 'missing-catalog', 'wrong-catalog', 'duplicate-catalog', 'foreign-catalog', 'wrong-status', 'bad-base64', 'malformed-json', 'nonobject' ) );
	}

	/** @dataProvider journal_mutations */
	public function test_original_typed_catalog_witnesses_and_complete_bound_journal_are_required( string $kind ): void {
		$journal = Wstm108_ProofFixture::runner_journal( $this->runner );
		$entries = array_map( static fn( $line ) => json_decode( $line, false, 512, JSON_THROW_ON_ERROR ), explode( "\n", rtrim( $journal, "\n" ) ) );
		if ( 'missing-catalog' === $kind ) {
			$entries = array_slice( $entries, 2 );
			$this->runner->wire_proof->events = array_slice( $this->runner->wire_proof->events, 1 );
			$this->runner->wire_proof->events_sha256 = hash( 'sha256', json_encode( $this->runner->wire_proof->events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		}
		if ( 'duplicate-catalog' === $kind ) { $entries[] = $entries[0]; }
		if ( 'foreign-catalog' === $kind ) { $entries[0]->boundary = 'foreign'; }
		if ( 'wrong-status' === $kind ) { $entries[0]->status = 500; }
		if ( 'bad-base64' === $kind ) { $entries[0]->body_base64 = '!'; }
		if ( 'nonobject' === $kind ) { $entries[] = array(); }
		if ( 'wrong-catalog' === $kind ) { $this->runner->catalogs->{'gateway:administrator'}[0]->name = 'changed'; }
		$journal = implode( "\n", array_map( static fn( $entry ) => json_encode( $entry, JSON_THROW_ON_ERROR ), $entries ) ) . "\n";
		if ( 'malformed-json' === $kind ) { $journal .= "not JSON\n"; }
		$this->runner->http_journal_sha256 = hash( 'sha256', $journal );
		if ( 'changed-bytes' === $kind ) { $journal .= "\n"; }
		$path = $this->root . '/runner.json.http.jsonl';
		file_put_contents( $path, $journal );
		if ( 'none' !== $kind ) {
			$this->expectException( 'malformed-json' === $kind ? JsonException::class : RuntimeException::class );
		}
		Wstm108_Proof::journal( $path, $this->runner );
		self::assertSame( $journal, file_get_contents( $path ) );
	}
}
