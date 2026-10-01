<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-private-wire.php';

final class UntrustedPrivateWireTest extends TestCase {
	private string $directory;
	private array $binding;
	private array $directory_identity;
	private Wstm108_PrivateWire $wire;

	protected function setUp(): void {
		$this->directory = str_replace( '\\', '/', realpath( sys_get_temp_dir() ) ) . '/wstm108-private-wire-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->directory_identity = Wstm108_Files::directory( $this->directory );
		$this->binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'private-wire-unit', 'source_sha' => str_repeat( 'b', 40 ), 'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
		$this->wire = Wstm108_PrivateWire::create( $this->directory, $this->binding, str_repeat( 'd', 64 ), function (): void {
			if ( $this->directory_identity !== Wstm108_Files::directory( $this->directory ) ) {
				throw new RuntimeException( 'Test authority changed.' );
			}
		} );
	}

	protected function tearDown(): void {
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::assertTrue( unlink( $this->directory . '/' . $name ) );
			}
		}
		self::assertTrue( rmdir( $this->directory ) );
	}

	private function event(): array {
		return array(
			'boundary' => 'gateway:administrator', 'method' => 'tools/call', 'status' => 500,
			'headers' => array( 'set-cookie' => 'unregistered-private-cookie', 'mcp-session-id' => 'private-session' ),
			'body' => "unknown-secret-not-on-redaction-list\xff\0" . '"<b>雪</b>"\\',
		);
	}

	private function complete(): void {
		foreach ( array( 'original', 'enabled', 'runner', 'restored', 'finalize' ) as $scope ) {
			$this->wire->begin( $scope );
			$record = $this->wire->capture( $scope, array( 'status' => 200, 'body' => '{"validated":true}' ) );
			$this->wire->validate( $scope, $record['id'], 'oracle' );
			if ( 'runner' === $scope ) {
				$this->wire->case_verdict( $scope, 'fixture:owned-case', true, array( $record['id'] ), array( 'passed' => true ) );
			}
			$this->wire->seal( $scope, true, array( 'passed' => true ) );
		}
	}

	public function test_original_invalid_bytes_headers_and_unknown_secrets_are_private_before_validation(): void {
		$this->wire->begin( 'runner' );
		$event = $this->event();
		$record = $this->wire->capture( 'runner', $event );
		self::assertSame( "WSTM108-WIRE-1\n" . serialize( $event ), file_get_contents( $this->directory . '/wire-000001.bin' ) );
		self::assertSame( hash( 'sha256', $event['body'] ), $record['body_sha256'] );
		self::assertSame( strlen( $event['body'] ), $record['body_length'] );
		self::assertSame( 'committed', $record['state'] );
		$public = json_encode( $record, JSON_THROW_ON_ERROR );
		foreach ( array( 'unknown-secret', 'private-cookie', 'private-session', '<b>', 'body":', 'headers' ) as $private ) {
			self::assertStringNotContainsString( $private, $public );
		}
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			self::assertSame( 0600, fileperms( $this->directory . '/wire-000001.bin' ) & 0777 );
		}
		$this->wire->seal( 'runner', false, array( 'failed' => 1 ) );
		self::assertSame( 'failed', $this->wire->scope_proof( 'runner' )['verdict'] );
	}

	public static function corruption(): array {
		return array_map( static fn( $kind ) => array( $kind ), array( 'missing', 'changed', 'partial', 'replacement', 'hardlink', 'foreign-mode', 'extra', 'journal-partial', 'journal-replacement', 'journal-uid', 'pending-parser', 'failed-case' ) );
	}

	/** @dataProvider corruption */
	public function test_complete_preflight_refuses_without_deleting_any_evidence( string $kind ): void {
		$this->complete();
		$path = $this->directory . '/wire-000001.bin';
		$journal = $this->directory . '/private-wire.json';
		$state = json_decode( file_get_contents( $journal ), true, 512, JSON_THROW_ON_ERROR );
		switch ( $kind ) {
			case 'missing':
				unlink( $path );
				break;
			case 'changed':
			case 'partial':
				file_put_contents( $path, 'partial' === $kind ? '' : 'changed original bytes' );
				break;
			case 'replacement':
			case 'journal-replacement':
				$target = 'replacement' === $kind ? $path : $journal;
				$bytes = file_get_contents( $target );
				rename( $target, $this->directory . '/retained-original' );
				Wstm108_Files::create( $target, $bytes );
				break;
			case 'hardlink':
				self::assertTrue( link( $path, $this->directory . '/retained-link' ) );
				break;
			case 'foreign-mode':
				$state['events'][0]['identity']['mode'] ^= 0400;
				file_put_contents( $journal, json_encode( $state, JSON_THROW_ON_ERROR ) );
				break;
			case 'extra':
				Wstm108_Files::create( $this->directory . '/wire-foreign.bin', 'foreign bytes' );
				break;
			case 'journal-partial':
				file_put_contents( $journal, '{"version":' );
				break;
			case 'journal-uid':
				$identity = $this->wire->identity();
				++$identity['uid'];
				try {
					Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $identity, static function (): void {} );
					self::fail( 'Foreign independent UID must refuse resume.' );
				} catch ( RuntimeException $error ) {
					self::assertStringContainsString( 'identity', $error->getMessage() );
				}
				return;
			case 'pending-parser':
				$state['events'][0]['state'] = 'committed';
				$state['events'][0]['parser'] = null;
				file_put_contents( $journal, json_encode( $state, JSON_THROW_ON_ERROR ) );
				break;
			case 'failed-case':
				$state['scopes']['runner']['cases']['fixture:owned-case']['passed'] = false;
				file_put_contents( $journal, json_encode( $state, JSON_THROW_ON_ERROR ) );
				break;
		}
		$before = array();
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) { $before[ $name ] = file_get_contents( $this->directory . '/' . $name ); }
		}
		try {
			$this->wire->retire( array() );
			self::fail( 'Corrupt or failed private wire must not retire: ' . $kind );
		} catch ( RuntimeException | JsonException $error ) {
			self::assertNotSame( '', $error->getMessage() );
		}
		$after = array();
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) { $after[ $name ] = file_get_contents( $this->directory . '/' . $name ); }
		}
		self::assertSame( $before, $after );
	}

	public function test_duplicate_and_foreign_verdicts_do_not_change_original_or_journal(): void {
		$this->wire->begin( 'runner' );
		$record = $this->wire->capture( 'runner', array( 'status' => 200, 'body' => '{"isError":true}' ) );
		$this->wire->validate( 'runner', $record['id'], 'canonical-tool', array( 'is_error' => true, 'payload_sha256' => hash( 'sha256', '{"isError":true}' ) ) );
		$before = file_get_contents( $this->directory . '/private-wire.json' );
		foreach ( array( array( 'runner', 1, 'canonical-tool' ), array( 'original', 1, 'oracle' ), array( 'runner', 2, 'oracle' ), array( 'runner', 1, 'invented-parser' ) ) as $arguments ) {
			try {
				$this->wire->validate( ...$arguments );
				self::fail( 'Invalid verdict was accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( $before, file_get_contents( $this->directory . '/private-wire.json' ) );
			}
		}
		$this->wire->case_verdict( 'runner', 'fixture:expected-denial', true, array( $record['id'] ), array( 'reason' => 'forbidden' ) );
		$this->wire->seal( 'runner', true, array( 'expected_denial' => true ) );
		self::assertSame( 'passed', $this->wire->scope_proof( 'runner' )['verdict'] );
	}

	public function test_failed_semantic_case_cannot_be_hidden_by_successful_cleanup_or_aggregate_count(): void {
		$this->wire->begin( 'runner' );
		$record = $this->wire->capture( 'runner', array( 'status' => 200, 'body' => '{"valid":"json","wrong":"semantic result"}' ) );
		$this->wire->validate( 'runner', $record['id'], 'canonical-tool', array( 'is_error' => false, 'payload_sha256' => hash( 'sha256', 'wrong semantic result' ) ) );
		$this->wire->case_verdict( 'runner', 'fixture:semantic-failure', false, array( $record['id'] ), array( 'passed' => false ) );
		$before = file_get_contents( $this->directory . '/private-wire.json' );
		try {
			$this->wire->seal( 'runner', true, array( 'cleanup_complete' => true, 'failed' => 0 ) );
			self::fail( 'Failed semantic verdict must be durable.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( $before, file_get_contents( $this->directory . '/private-wire.json' ) );
			self::assertFileExists( $this->directory . '/wire-000001.bin' );
		}
	}

	public function test_only_fully_validated_inventory_retires(): void {
		$this->complete();
		self::assertCount( 6, $this->wire->preflight() );
		$inventory = $this->wire->inventory();
		$this->wire->retire( $inventory );
		self::assertSame( array( '.', '..' ), scandir( $this->directory ) );
	}

	public function test_case_verdict_requires_ordered_unique_owned_events(): void {
		$this->wire->begin( 'runner' );
		foreach ( array( 'first', 'second' ) as $body ) {
			$event = $this->wire->capture( 'runner', array( 'status' => 200, 'body' => $body ) );
			$this->wire->validate( 'runner', $event['id'], 'oracle' );
		}
		$before = file_get_contents( $this->directory . '/private-wire.json' );
		foreach ( array( array(), array( 1, 1 ), array( 2, 1 ), array( 0 ), array( '1' ), array( 3 ), array( 'foreign' => 1 ) ) as $ids ) {
			try {
				$this->wire->case_verdict( 'runner', 'fixture:invalid-events', true, $ids, array( 'passed' => true ) );
				self::fail( 'Invalid event inventory must refuse without persisting a case.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( $before, file_get_contents( $this->directory . '/private-wire.json' ) );
			}
		}
	}

	public static function late_retirement_changes(): array {
		return array( 'later target changed' => array( 'changed' ), 'new target appeared' => array( 'extra' ), 'authority veto' => array( 'veto' ) );
	}

	public function test_late_file_after_original_removal_prevents_journal_retirement(): void {
		$this->complete();
		$inventory = $this->wire->inventory();
		$injected = false;
		$wire = Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $this->wire->identity(), function () use ( &$injected ): void {
			$state = json_decode( file_get_contents( $this->directory . '/private-wire.json' ), true, 512, JSON_THROW_ON_ERROR );
			if ( ! $injected && 'complete' === $state['retirement'] ) {
				$injected = true;
				Wstm108_Files::create( $this->directory . '/wire-foreign.bin', 'foreign final-checkpoint bytes' );
			}
		} );
		try {
			$wire->retire( $inventory );
			self::fail( 'A late unknown file must retain the remaining journal.' );
		} catch ( RuntimeException $error ) {
			self::assertTrue( $injected );
			self::assertFileExists( $this->directory . '/private-wire.json' );
			self::assertSame( 'foreign final-checkpoint bytes', file_get_contents( $this->directory . '/wire-foreign.bin' ) );
			for ( $id = 1; $id <= 5; ++$id ) {
				self::assertFileDoesNotExist( $this->directory . '/' . sprintf( 'wire-%06d.bin', $id ) );
			}
			$state = json_decode( file_get_contents( $this->directory . '/private-wire.json' ), true, 512, JSON_THROW_ON_ERROR );
			foreach ( $state['events'] as $record ) {
				self::assertTrue( $record['removed'], 'Partial retirement must not claim the originals remain intact.' );
			}
		}
		$retained = $this->files_snapshot();
		try {
			Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $this->wire->identity(), static function (): void {} );
			self::fail( 'Complete retirement with a retained journal must not resume.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'cannot resume', $error->getMessage() );
		}
		self::assertSame( $retained, $this->files_snapshot() );
	}

	/** @dataProvider late_retirement_changes */
	public function test_late_retirement_refusal_retains_every_remaining_original_and_partial_journal( string $kind ): void {
		$this->complete();
		$inventory = $this->wire->inventory();
		$before = array();
		foreach ( array_keys( $inventory ) as $name ) {
			$before[ $name ] = file_get_contents( $this->directory . '/' . $name );
		}
		$injected = false;
		$wire = Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $this->wire->identity(), function () use ( $kind, &$injected ): void {
			if ( $injected || file_exists( $this->directory . '/wire-000001.bin' ) ) { return; }
			$injected = true;
			if ( 'changed' === $kind ) {
				file_put_contents( $this->directory . '/wire-000005.bin', 'late foreign replacement bytes' );
			} elseif ( 'extra' === $kind ) {
				Wstm108_Files::create( $this->directory . '/wire-foreign.bin', 'late foreign file' );
			} else {
				throw new RuntimeException( 'Synthetic late authority veto.' );
			}
		} );
		try {
			$wire->retire( $inventory );
			self::fail( 'Late refusal must not delete the next original or complete retirement.' );
		} catch ( RuntimeException $error ) {
			self::assertTrue( $injected );
			self::assertFileDoesNotExist( $this->directory . '/wire-000001.bin' );
			for ( $id = 2; $id <= 5; ++$id ) {
				$name = sprintf( 'wire-%06d.bin', $id );
				$expected = 'changed' === $kind && 5 === $id ? 'late foreign replacement bytes' : $before[ $name ];
				self::assertSame( $expected, file_get_contents( $this->directory . '/' . $name ) );
			}
			$state = json_decode( file_get_contents( $this->directory . '/private-wire.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( 'retiring', $state['retirement'] );
			self::assertNotSame( $before['private-wire.json'], file_get_contents( $this->directory . '/private-wire.json' ) );
			foreach ( array_slice( $state['events'], 1 ) as $record ) {
				self::assertFalse( $record['removed'] );
			}
			if ( 'extra' === $kind ) {
				self::assertSame( 'late foreign file', file_get_contents( $this->directory . '/wire-foreign.bin' ) );
			}
		}
	}

	public static function invalid_removal_intents(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'missing-field', 'string', 'float', 'boolean', 'zero', 'negative', 'out-of-range',
			'foreign-target', 'already-removed', 'pending-phase', 'complete-phase',
			'unvalidated-target', 'pending-removed', 'out-of-order-removed', 'incomplete-complete',
		) );
	}

	/** @dataProvider invalid_removal_intents */
	public function test_pending_removal_schema_rejects_invalid_targets_without_mutation( string $kind ): void {
		$this->complete();
		$path = $this->directory . '/private-wire.json';
		$state = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		$state['retirement'] = 'retiring';
		$state['removal_pending_id'] = 1;
		switch ( $kind ) {
			case 'missing-field': unset( $state['removal_pending_id'] ); break;
			case 'string': $state['removal_pending_id'] = '1'; break;
			case 'float': $state['removal_pending_id'] = 1.0; break;
			case 'boolean': $state['removal_pending_id'] = true; break;
			case 'zero': $state['removal_pending_id'] = 0; break;
			case 'negative': $state['removal_pending_id'] = -1; break;
			case 'out-of-range': $state['removal_pending_id'] = 6; break;
			case 'foreign-target': $state['removal_pending_id'] = 2; break;
			case 'already-removed': $state['events'][0]['removed'] = true; break;
			case 'pending-phase': $state['retirement'] = 'pending'; break;
			case 'complete-phase':
				$state['retirement'] = 'complete';
				foreach ( $state['events'] as &$record ) { $record['removed'] = true; }
				unset( $record );
				break;
			case 'unvalidated-target': $state['events'][0]['state'] = 'committed'; break;
			case 'pending-removed':
				$state['retirement'] = 'pending'; $state['removal_pending_id'] = null; $state['events'][0]['removed'] = true;
				break;
			case 'out-of-order-removed': $state['removal_pending_id'] = null; $state['events'][1]['removed'] = true; break;
			case 'incomplete-complete': $state['retirement'] = 'complete'; $state['removal_pending_id'] = null; break;
		}
		file_put_contents( $path, json_encode( $state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		$before = $this->files_snapshot();
		try {
			Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $this->wire->identity(), static function (): void {} );
			self::fail( 'Malformed pending-removal schema must refuse: ' . $kind );
		} catch ( RuntimeException $error ) {
			self::assertStringNotContainsString( 'cannot resume', $error->getMessage(), 'Malformed state must fail schema validation, not merely the fresh-resume phase gate.' );
		}
		self::assertSame( $before, $this->files_snapshot() );
	}

	private function files_snapshot(): array {
		$files = array();
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) { $files[ $name ] = Wstm108_Files::file( $this->directory . '/' . $name ); }
		}
		return $files;
	}

	/** Real child/filesystem operations; namespace wrappers inject only the selected synthetic fault. */
	private static function transition_child_source(): string {
		return <<<'PHP'
namespace Wstm108WireTransition {
	function observe( array $event ): void {
		$bytes = \json_encode( $event, JSON_THROW_ON_ERROR ) . "\n";
		if ( \file_put_contents( $GLOBALS['input']['directory'] . '/transition-observations.jsonl', $bytes, FILE_APPEND ) !== \strlen( $bytes ) ) {
			throw new \RuntimeException( 'Cannot retain test transition observation.' );
		}
	}
	function unlink( string $path ): bool {
		$input = $GLOBALS['input'];
		if ( \dirname( $path ) !== $input['directory'] ) { return \unlink( $path ); }
		$state = \json_decode( \file_get_contents( $input['directory'] . '/private-wire.json' ), true, 512, JSON_THROW_ON_ERROR );
		observe( array( 'event' => 'unlink-attempt', 'file' => \basename( $path ), 'phase' => $state['retirement'],
			'pending' => $state['removal_pending_id'], 'flags' => \array_column( $state['events'], 'removed' ) ) );
		if ( 'remove-refusal' === $input['fault'] && 'wire-000001.bin' === \basename( $path ) ) { return false; }
		$result = \unlink( $path );
		if ( $result && 'postcheck-reappearance' === $input['fault'] && 'wire-000001.bin' === \basename( $path ) ) {
			Wstm108_Files::create( $path, 'foreign reappeared original path' );
			observe( array( 'event' => 'postcheck-reappearance' ) );
		}
		return $result;
	}
	function ftruncate( $handle, int $size ): bool {
		$input = $GLOBALS['input'];
		$uri = \stream_get_meta_data( $handle )['uri'];
		\clearstatcache();
		if ( 'before-write' === $input['fault'] && $uri === $input['directory'] . '/private-wire.json'
			&& ! \file_exists( $input['directory'] . '/wire-000001.bin' ) ) {
			observe( array( 'event' => 'before-write' ) );
			return false;
		}
		return \ftruncate( $handle, $size );
	}
	function fwrite( $handle, string $bytes ) {
		$input = $GLOBALS['input'];
		$uri = \stream_get_meta_data( $handle )['uri'];
		\clearstatcache();
		if ( 'partial-write' === $input['fault'] && $uri === $input['directory'] . '/private-wire.json'
			&& ! \file_exists( $input['directory'] . '/wire-000001.bin' ) ) {
			$prefix = \substr( $bytes, 0, 31 );
			if ( \fwrite( $handle, $prefix ) !== \strlen( $prefix ) || ! \fflush( $handle ) ) {
				throw new \RuntimeException( 'Partial-write injection itself failed.' );
			}
			observe( array( 'event' => 'partial-write', 'prefix' => $prefix ) );
			return 0;
		}
		return \fwrite( $handle, $bytes );
	}
}
namespace {
	$input = json_decode( base64_decode( $argv[1], true ), true, 512, JSON_THROW_ON_ERROR );
	foreach ( array( 'untrusted-content-files.php', 'untrusted-content-private-wire.php' ) as $name ) {
		$source = file_get_contents( $input['source'] . '/' . $name );
		$source = preg_replace( '/\A<\?php\s+declare\(strict_types=1\);/', '', $source, 1, $count );
		if ( 1 !== $count ) { throw new RuntimeException( 'Test namespace source prefix changed.' ); }
		$source = str_replace( '__DIR__', var_export( $input['source'], true ), $source );
		eval( 'declare(strict_types=1); namespace Wstm108WireTransition; use \RuntimeException; use \stdClass; ' . $source );
	}
	$guard = static function () use ( $input ): void {
		\Wstm108WireTransition\Wstm108_Files::assert_file( $input['directory'] . '/host-guard.mock', $input['guard'] );
		clearstatcache();
		if ( file_exists( $input['directory'] . '/wire-000001.bin' ) ) { return; }
		$journal = $input['directory'] . '/private-wire.json';
		$state = json_decode( file_get_contents( $journal ), true, 512, JSON_THROW_ON_ERROR );
		if ( 1 === $state['removal_pending_id'] ) {
			if ( 'guard-veto' === $input['fault'] ) { throw new RuntimeException( 'Synthetic post-unlink guard veto.' ); }
			if ( 'exit-before-flag' === $input['fault'] ) { exit( 81 ); }
			if ( 'journal-replacement' === $input['fault'] ) {
				$bytes = file_get_contents( $journal );
				if ( ! rename( $journal, $input['directory'] . '/journal-predecessor' ) ) { throw new RuntimeException( 'Cannot stage test replacement.' ); }
				\Wstm108WireTransition\Wstm108_Files::create( $journal, $bytes );
				\Wstm108WireTransition\observe( array( 'event' => 'journal-replacement' ) );
			}
		} elseif ( true === $state['events'][0]['removed'] && 'exit-after-flag' === $input['fault'] ) {
			exit( 82 );
		}
	};
	try {
		$wire = \Wstm108WireTransition\Wstm108_PrivateWire::resume( $input['directory'], $input['binding'], $input['context'], $input['identity'], $guard );
		$wire->retire( $input['inventory'] );
	} catch ( RuntimeException | JsonException $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" );
		exit( 23 );
	}
	exit( 0 );
}
PHP;
	}

	public static function transition_faults(): array {
		return array_map( static fn( $kind ) => array( $kind ), array(
			'none', 'remove-refusal', 'postcheck-reappearance', 'guard-veto', 'before-write',
			'partial-write', 'exit-before-flag', 'exit-after-flag', 'journal-replacement',
		) );
	}

	/** @dataProvider transition_faults */
	public function test_durable_removal_transition_with_real_child_and_precise_synthetic_faults( string $kind ): void {
		$this->complete();
		$inventory = $this->wire->inventory();
		$guard = Wstm108_Files::create( $this->directory . '/host-guard.mock', 'synthetic host guard must remain' );
		$before = $this->files_snapshot();
		$input = array( 'directory' => $this->directory, 'source' => str_replace( '\\', '/', dirname( __DIR__ ) . '/e2e' ),
			'binding' => $this->binding, 'context' => str_repeat( 'd', 64 ), 'identity' => $this->wire->identity(),
			'inventory' => $inventory, 'guard' => $guard, 'fault' => $kind );
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::transition_child_source(),
			base64_encode( json_encode( $input, JSON_THROW_ON_ERROR ) ) ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		$exit = proc_close( $process );
		$expected_exit = 'none' === $kind ? 0 : ( 'exit-before-flag' === $kind ? 81 : ( 'exit-after-flag' === $kind ? 82 : 23 ) );
		self::assertSame( $expected_exit, $exit, $stderr );
		self::assertSame( '', $stdout );
		if ( 23 === $expected_exit ) { self::assertNotSame( '', $stderr ); } else { self::assertSame( '', $stderr ); }
		$diagnostics = array( 'remove-refusal' => 'Cannot remove the exact owned file.', 'postcheck-reappearance' => 'Owned path reappeared',
			'guard-veto' => 'Synthetic post-unlink guard veto.', 'before-write' => 'Cannot reset the owned journal handle.',
			'partial-write' => 'Cannot persist owned file bytes.', 'journal-replacement' => 'identity changed before opening' );
		if ( isset( $diagnostics[ $kind ] ) ) { self::assertStringContainsString( $diagnostics[ $kind ], $stderr ); }
		self::assertSame( $guard, Wstm108_Files::file( $this->directory . '/host-guard.mock' ) );
		$observations = array_map( static fn( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ),
			file( $this->directory . '/transition-observations.jsonl', FILE_IGNORE_NEW_LINES ) );
		$attempts = array_values( array_filter( $observations, static fn( $entry ) => 'unlink-attempt' === $entry['event'] ) );
		if ( in_array( $kind, array( 'postcheck-reappearance', 'before-write', 'partial-write', 'journal-replacement' ), true ) ) {
			self::assertCount( 1, array_filter( $observations, static fn( $entry ) => $kind === $entry['event'] ), 'The selected injection must actually reach its intended boundary.' );
		}
		self::assertCount( 'none' === $kind ? 6 : 1, $attempts, 'No next deletion is allowed after any failed or interrupted transition.' );
		foreach ( $attempts as $index => $attempt ) {
			$journal_attempt = 5 === $index;
			self::assertSame( $journal_attempt ? 'private-wire.json' : sprintf( 'wire-%06d.bin', $index + 1 ), $attempt['file'] );
			self::assertSame( $journal_attempt ? 'complete' : 'retiring', $attempt['phase'] );
			self::assertSame( $journal_attempt ? null : $index + 1, $attempt['pending'], 'Exact intent must be durable before the actual unlink call.' );
			self::assertSame( array_merge( array_fill( 0, $index, true ), array_fill( 0, 5 - $index, false ) ), $attempt['flags'] );
		}
		$journal = $this->directory . '/private-wire.json';
		if ( 'none' === $kind ) {
			self::assertFileDoesNotExist( $journal );
			self::assertSame( array(), glob( $this->directory . '/wire-*' ) );
			return;
		}
		self::assertFileExists( $journal );
		for ( $id = 2; $id <= 5; ++$id ) {
			$name = sprintf( 'wire-%06d.bin', $id );
			self::assertSame( $before[ $name ], Wstm108_Files::file( $this->directory . '/' . $name ), 'Every remaining original must preserve identity, hash and bytes.' );
		}
		$first = $this->directory . '/wire-000001.bin';
		if ( 'remove-refusal' === $kind ) {
			self::assertSame( $before['wire-000001.bin'], Wstm108_Files::file( $first ) );
		} elseif ( 'postcheck-reappearance' === $kind ) {
			self::assertSame( 'foreign reappeared original path', file_get_contents( $first ) );
			self::assertStringContainsString( 'reappeared', $stderr );
		} else {
			self::assertFileDoesNotExist( $first );
		}
		$expected = json_decode( $before['private-wire.json']['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$expected['retirement'] = 'retiring';
		$expected['removal_pending_id'] = 1;
		if ( 'exit-after-flag' === $kind ) {
			$expected['events'][0]['removed'] = true;
			$expected['removal_pending_id'] = null;
		}
		if ( 'partial-write' === $kind ) {
			$partial = array_values( array_filter( $observations, static fn( $entry ) => 'partial-write' === $entry['event'] ) );
			self::assertCount( 1, $partial );
			self::assertSame( 31, strlen( $partial[0]['prefix'] ) );
			$candidate = $expected;
			$candidate['events'][0]['removed'] = true;
			$candidate['removal_pending_id'] = null;
			self::assertSame( substr( json_encode( $candidate, JSON_THROW_ON_ERROR ), 0, 31 ), $partial[0]['prefix'] );
			self::assertSame( $partial[0]['prefix'], file_get_contents( $journal ), 'Interrupted persistence is not a confirmed removal flag.' );
		} else {
			self::assertSame( $expected, json_decode( file_get_contents( $journal ), true, 512, JSON_THROW_ON_ERROR ) );
		}
		$journal_file = Wstm108_Files::file( $journal );
		if ( 'journal-replacement' === $kind ) {
			self::assertNotSame( $before['private-wire.json']['identity'], $journal_file['identity'] );
			self::assertSame( $before['private-wire.json']['identity'], Wstm108_Files::file( $this->directory . '/journal-predecessor' )['identity'] );
			self::assertSame( $journal_file['bytes'], file_get_contents( $this->directory . '/journal-predecessor' ) );
			self::assertStringContainsString( 'identity', $stderr );
		} else {
			self::assertSame( $before['private-wire.json']['identity'], $journal_file['identity'] );
		}
		$retained = $this->files_snapshot();
		try {
			Wstm108_PrivateWire::resume( $this->directory, $this->binding, str_repeat( 'd', 64 ), $this->wire->identity(),
				static function () use ( $guard, $input ): void { Wstm108_Files::assert_file( $input['directory'] . '/host-guard.mock', $guard ); } );
			self::fail( 'A fresh process cannot adopt partial retirement or infer removal from absence.' );
		} catch ( RuntimeException | JsonException $error ) {
			self::assertNotSame( '', $error->getMessage() );
		}
		self::assertSame( $retained, $this->files_snapshot(), 'Resume refusal must not mutate or delete retained evidence.' );
	}
}
