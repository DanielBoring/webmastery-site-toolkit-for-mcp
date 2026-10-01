<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';

/** Original event frames are opaque private bytes, never deserialized or uploaded. */
final class Wstm108_PrivateWire {
	private string $directory;
	private array $binding;
	private string $context_sha256;
	private array $identity;
	private $guard;
	private ?array $snapshot = null;
	private ?string $expected_sha256 = null;
	private const SCOPES = array( 'original', 'enabled', 'runner', 'restored', 'finalize' );
	private const PARSERS = array( 'initialize', 'notification', 'catalog', 'canonical-tool', 'gateway-info', 'session-close', 'oracle', 'owned-current-get', 'owned-stale-get', 'cli-refusal', 'http-cli-refusal', 'convergence', 'case-result', 'local-summary' );

	private function __construct( string $directory, array $binding, string $context_sha256, array $identity, callable $guard ) {
		$this->directory = $directory;
		$this->binding = $binding;
		$this->context_sha256 = $context_sha256;
		$this->identity = $identity;
		$this->guard = $guard;
	}

	public static function create( string $directory, array $binding, string $context_sha256, callable $guard ): self {
		$guard();
		$state = array( 'version' => 1, 'binding' => $binding, 'context_sha256' => $context_sha256, 'events' => array(), 'scopes' => array(), 'retirement' => 'pending', 'removal_pending_id' => null );
		$file = Wstm108_Files::create( $directory . '/private-wire.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
		$self = new self( $directory, $binding, $context_sha256, $file['identity'], $guard );
		$self->expected_sha256 = $file['sha256'];
		return $self;
	}

	public static function resume( string $directory, array $binding, string $context_sha256, array $identity, callable $guard ): self {
		$self = new self( $directory, $binding, $context_sha256, $identity, $guard );
		$state = $self->state();
		$self->require( 'pending' === $state['retirement'] && null === $state['removal_pending_id'], 'partial or complete retirement cannot resume; remaining evidence is retained.' );
		return $self;
	}

	public function identity(): array {
		return $this->identity;
	}

	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'WSTM108 Private wire retirement refused: ' . $message );
		}
	}

	private function state(): array {
		( $this->guard )();
		$file = Wstm108_Files::read_bound( $this->directory . '/private-wire.json', $this->identity );
		$this->require( null === $this->expected_sha256 || $this->expected_sha256 === $file['sha256'], 'journal generation changed outside its verified writer.' );
		$this->require( 'Windows' === PHP_OS_FAMILY || 0600 === ( $file['identity']['mode'] & 0777 ), 'private journal permissions differ.' );
		$state = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$this->require( is_array( $state ) && array_keys( $state ) === array( 'version', 'binding', 'context_sha256', 'events', 'scopes', 'retirement', 'removal_pending_id' )
			&& 1 === $state['version'] && $this->binding === $state['binding'] && $this->context_sha256 === $state['context_sha256']
			&& is_array( $state['events'] ) && is_array( $state['scopes'] )
			&& ( array() === $state['events'] || array_keys( $state['events'] ) === range( 0, count( $state['events'] ) - 1 ) )
			&& in_array( $state['retirement'], array( 'pending', 'retiring', 'complete' ), true ), 'foreign, partial or malformed journal.' );
		$this->validate_retirement( $state );
		$this->snapshot = $file;
		$this->expected_sha256 = $file['sha256'];
		return $state;
	}

	private function save( array $state ): void {
		$this->validate_retirement( $state );
		( $this->guard )();
		$this->require( null !== $this->snapshot, 'no authoritative journal read precedes persistence.' );
		$file = Wstm108_Files::update( $this->directory . '/private-wire.json', $this->snapshot, json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->expected_sha256 = $file['sha256'];
		$this->snapshot = null;
	}

	private function validate_retirement( array $state ): void {
		$pending = $state['removal_pending_id'];
		$this->require( null === $pending || ( is_int( $pending ) && $pending > 0 && $pending <= count( $state['events'] ) ), 'invalid pending removal target.' );
		$this->require( 'pending' === $state['retirement'] || array() !== $state['events'], 'empty retirement inventory.' );
		$first_remaining = null;
		foreach ( $state['events'] as $index => $record ) {
			$this->require( is_array( $record ) && ( $record['id'] ?? null ) === $index + 1
				&& ( $record['file'] ?? null ) === sprintf( 'wire-%06d.bin', $index + 1 )
				&& array_key_exists( 'removed', $record ) && is_bool( $record['removed'] ), 'invalid retirement event identity or removal flag.' );
			if ( $record['removed'] ) {
				$this->require( 'pending' !== $state['retirement'] && null === $first_remaining && 'validated' === ( $record['state'] ?? null ), 'inconsistent confirmed removal order or phase.' );
			} elseif ( null === $first_remaining ) {
				$first_remaining = $record['id'];
			}
		}
		$this->require( null === $pending || ( 'retiring' === $state['retirement'] && $pending === $first_remaining
			&& 'validated' === ( $state['events'][ $pending - 1 ]['state'] ?? null ) ), 'pending target is foreign, already removed or inconsistent with retirement phase.' );
		$this->require( 'complete' !== $state['retirement'] || ( null === $pending && null === $first_remaining ), 'complete retirement still has unconfirmed originals.' );
	}

	public function begin( string $scope ): void {
		$state = $this->state();
		$this->require( in_array( $scope, self::SCOPES, true ) && ! array_key_exists( $scope, $state['scopes'] )
			&& 'pending' === $state['retirement'], 'scope is duplicate, foreign or already retiring.' );
		$state['scopes'][ $scope ] = array( 'verdict' => 'pending', 'semantic_sha256' => null, 'cases' => array() );
		$this->save( $state );
	}

	public function capture( string $scope, array $event ): array {
		$state = $this->state();
		$this->require( 'pending' === ( $state['scopes'][ $scope ]['verdict'] ?? null ) && 'pending' === $state['retirement'], 'event has no open owned scope.' );
		$id = count( $state['events'] ) + 1;
		$bytes = "WSTM108-WIRE-1\n" . serialize( $event );
		$record = array(
			'id' => $id, 'scope' => $scope, 'file' => sprintf( 'wire-%06d.bin', $id ),
			'length' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ),
			'body_length' => is_string( $event['body'] ?? null ) ? strlen( $event['body'] ) : null,
			'body_sha256' => is_string( $event['body'] ?? null ) ? hash( 'sha256', $event['body'] ) : null,
			'status' => is_int( $event['status'] ?? null ) ? $event['status'] : null,
			'state' => 'intent', 'identity' => null, 'parser' => null, 'removed' => false,
			'origin' => self::origin( $event ), 'validation' => null,
		);
		$state['events'][] = $record;
		$this->save( $state );
		( $this->guard )();
		$file = Wstm108_Files::create( $this->directory . '/' . $record['file'], $bytes );
		$state = $this->state();
		$this->require( $record === $state['events'][ $id - 1 ], 'event intent changed during original-byte persistence.' );
		$state['events'][ $id - 1 ]['identity'] = $file['identity'];
		$state['events'][ $id - 1 ]['state'] = 'committed';
		$this->save( $state );
		return $this->witness( $state['events'][ $id - 1 ] );
	}

	private function witness( array $record ): array {
		self::require( is_int( $record['id'] ) && $record['id'] > 0 && in_array( $record['scope'], self::SCOPES, true )
			&& is_int( $record['length'] ) && $record['length'] > 0 && self::hash_string( $record['sha256'] )
			&& ( null === $record['body_length'] || ( is_int( $record['body_length'] ) && $record['body_length'] >= 0 ) )
			&& ( null === $record['body_sha256'] || self::hash_string( $record['body_sha256'] ) )
			&& ( null === $record['status'] || ( is_int( $record['status'] ) && $record['status'] >= 0 && $record['status'] <= 599 ) )
			&& in_array( $record['state'], array( 'intent', 'committed', 'validated' ), true )
			&& ( null === $record['parser'] || in_array( $record['parser'], self::PARSERS, true ) ) && is_bool( $record['removed'] ), 'unsafe public event metadata.' );
		$this->require( is_array( $record['origin'] ) && self::origin( $record['origin'] ) === $record['origin'], 'unrecognized public origin metadata.' );
		$this->validate_projection( $record['parser'], $record['validation'] );
		$record['origin'] = (object) $record['origin'];
		if ( 'catalog' === $record['parser'] ) {
			foreach ( $record['validation']['tools'] as &$tool ) {
				$tool['annotations'] = (object) $tool['annotations'];
			}
			unset( $tool );
		}
		$witness = array_intersect_key( $record, array_flip( array( 'id', 'scope', 'length', 'sha256', 'body_length', 'body_sha256', 'status', 'state', 'parser', 'removed', 'origin', 'validation' ) ) );
		self::assert_witness( $witness );
		return $witness;
	}

	public static function assert_witness( array $entry ): void {
		self::require( array_keys( $entry ) === array( 'id', 'scope', 'length', 'sha256', 'body_length', 'body_sha256', 'status', 'state', 'parser', 'removed', 'origin', 'validation' )
			&& is_int( $entry['id'] ) && $entry['id'] > 0 && in_array( $entry['scope'], self::SCOPES, true )
			&& is_int( $entry['length'] ) && $entry['length'] > 0 && self::hash_string( $entry['sha256'] )
			&& ( ( null === $entry['body_length'] && null === $entry['body_sha256'] )
				|| ( is_int( $entry['body_length'] ) && $entry['body_length'] >= 0 && self::hash_string( $entry['body_sha256'] ) ) )
			&& ( null === $entry['status'] || ( is_int( $entry['status'] ) && $entry['status'] >= 0 && $entry['status'] <= 599 ) )
			&& in_array( $entry['state'], array( 'committed', 'validated' ), true ) && false === $entry['removed']
			&& ( $entry['origin'] instanceof stdClass || is_array( $entry['origin'] ) ), 'unsafe public witness shape or primitive metadata.' );
		$origin = (array) $entry['origin'];
		self::require( self::origin( $origin ) === $origin, 'unsafe or foreign public origin.' );
		self::require( ( 'committed' === $entry['state'] && null === $entry['parser'] && null === $entry['validation'] )
			|| ( 'validated' === $entry['state'] && is_string( $entry['parser'] ) ), 'inconsistent public parser state.' );
		self::require( null === $entry['validation'] || is_array( $entry['validation'] ) || $entry['validation'] instanceof stdClass, 'unsafe public parser projection type.' );
		$projection = null === $entry['validation'] ? null : json_decode( json_encode( $entry['validation'], JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
		self::validate_projection( $entry['parser'], $projection );
	}

	private static function hash_string( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
	}

	public static function validate_projection( ?string $parser, ?array $projection ): void {
		$hash = static fn( $value ) => is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
		self::require( null === $parser || in_array( $parser, self::PARSERS, true ), 'unknown parser verdict.' );
		if ( 'catalog' === $parser ) {
			self::require( null !== $projection && array_keys( $projection ) === array( 'tools', 'next_cursor_sha256' )
				&& is_array( $projection['tools'] ) && $hash( $projection['next_cursor_sha256'] ), 'missing safe catalog projection.' );
			foreach ( $projection['tools'] as $tool ) {
				self::require( is_array( $tool ) && array_keys( $tool ) === array( 'name', 'input_schema_sha256', 'output_schema_sha256', 'descriptor_sha256', 'annotations' )
					&& is_string( $tool['name'] ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $tool['name'] )
					&& $hash( $tool['input_schema_sha256'] ) && $hash( $tool['output_schema_sha256'] ) && $hash( $tool['descriptor_sha256'] ), 'unsafe catalog projection.' );
				$hints = (array) $tool['annotations'];
				self::require( array() === array_diff( array_keys( $hints ), array( 'readOnlyHint', 'destructiveHint', 'idempotentHint' ) )
					&& count( $hints ) === count( array_filter( $hints, 'is_bool' ) ), 'unsafe annotation projection.' );
			}
		} elseif ( 'canonical-tool' === $parser ) {
			self::require( null !== $projection && array_keys( $projection ) === array( 'is_error', 'payload_sha256' )
				&& is_bool( $projection['is_error'] ) && $hash( $projection['payload_sha256'] ), 'unsafe canonical tool projection.' );
		} else {
			self::require( null === $projection, 'unknown public parser projection.' );
		}
	}

	private static function origin( array $event ): array {
		$origin = array();
		$boundary = $event['boundary'] ?? null;
		if ( is_string( $boundary ) && ( 1 === preg_match( '/^(gateway|individual):(administrator|subscriber|reader|admin)$/D', $boundary )
			|| in_array( $boundary, array( 'wordpress-oracle', 'owned-get-probe', 'actual-cli-missing-optin', 'actual-http-cli-only', 'local-summary', 'case-result', 'diagnostic' ), true ) ) ) {
			$origin['boundary'] = $boundary;
		}
		if ( in_array( $event['method'] ?? null, array( 'initialize', 'notifications/initialized', 'tools/list', 'tools/call', 'DELETE', 'HEAD', 'GET' ), true ) ) {
			$origin['method'] = $event['method'];
		}
		if ( in_array( $event['file'] ?? null, array( 'untrusted-content-stage.php', 'untrusted-content-runner.php' ), true ) ) {
			$origin['file'] = $event['file'];
		}
		return $origin;
	}

	private function verify_record( array $record, int $index ): void {
		$this->require( array_keys( $record ) === array( 'id', 'scope', 'file', 'length', 'sha256', 'body_length', 'body_sha256', 'status', 'state', 'identity', 'parser', 'removed', 'origin', 'validation' )
			&& $index + 1 === $record['id'] && sprintf( 'wire-%06d.bin', $index + 1 ) === $record['file']
			&& in_array( $record['scope'], self::SCOPES, true ) && is_int( $record['length'] ) && $record['length'] > 0
			&& is_string( $record['sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] )
			&& in_array( $record['state'], array( 'committed', 'validated' ), true ) && is_array( $record['identity'] )
			&& false === $record['removed'], 'missing, foreign, partial or retired original event.' );
		$file = Wstm108_Files::read_bound( $this->directory . '/' . $record['file'], $record['identity'] );
		$this->require( strlen( $file['bytes'] ) === $record['length'] && $file['sha256'] === $record['sha256']
			&& 0 === strpos( $file['bytes'], "WSTM108-WIRE-1\n" )
			&& ( 'Windows' === PHP_OS_FAMILY || 0600 === ( $file['identity']['mode'] & 0777 ) ), 'original event bytes, framing or ownership changed.' );
	}

	public function validate( string $scope, int $id, string $parser, ?array $validation = null ): array {
		$state = $this->state();
		$this->validate_projection( $parser, $validation );
		$this->require( isset( $state['events'][ $id - 1 ] ) && in_array( $parser, self::PARSERS, true )
			&& 'pending' === ( $state['scopes'][ $scope ]['verdict'] ?? null ), 'foreign parser verdict or closed scope.' );
		$record = $state['events'][ $id - 1 ];
		$this->verify_record( $record, $id - 1 );
		$this->require( $scope === $record['scope'] && 'committed' === $record['state'] && null === $record['parser'], 'duplicate or foreign event verdict.' );
		$state['events'][ $id - 1 ]['state'] = 'validated';
		$state['events'][ $id - 1 ]['parser'] = $parser;
		$state['events'][ $id - 1 ]['validation'] = $validation;
		$this->save( $state );
		return $this->witness( $state['events'][ $id - 1 ] );
	}

	public function case_verdict( string $scope, string $label, bool $passed, array $event_ids, array $case ): void {
		$state = $this->state();
		$this->require( 'runner' === $scope && 'pending' === ( $state['scopes'][ $scope ]['verdict'] ?? null )
			&& ! isset( $state['scopes'][ $scope ]['cases'][ $label ] ), 'duplicate, foreign or closed case verdict.' );
		$this->require( array() !== $event_ids && array_values( $event_ids ) === $event_ids, 'case verdict has no ordered original events.' );
		$previous = 0;
		foreach ( $event_ids as $id ) {
			$this->require( is_int( $id ) && $id > $previous && $scope === ( $state['events'][ $id - 1 ]['scope'] ?? null ), 'case verdict references a duplicate, unordered or foreign original event.' );
			if ( $passed ) {
				$this->require( 'validated' === $state['events'][ $id - 1 ]['state'], 'case cannot pass with pending transport/parser evidence.' );
			}
			$previous = $id;
		}
		$state['scopes'][ $scope ]['cases'][ $label ] = array( 'passed' => $passed, 'events' => $event_ids, 'sha256' => hash( 'sha256', serialize( $case ) ) );
		$this->save( $state );
	}

	public function seal( string $scope, bool $passed, array $semantic_verdict ): array {
		$state = $this->state();
		$this->require( 'pending' === ( $state['scopes'][ $scope ]['verdict'] ?? null ), 'duplicate or foreign semantic verdict.' );
		foreach ( $state['scopes'][ $scope ]['cases'] as $case ) {
			$this->require( ! $passed || true === $case['passed'], 'failed case cannot be erased by an aggregate success verdict.' );
		}
		foreach ( $state['events'] as $index => $record ) {
			if ( $scope !== $record['scope'] ) { continue; }
			if ( $passed ) {
				$this->verify_record( $record, $index );
				$this->require( 'validated' === $record['state'] && in_array( $record['parser'], self::PARSERS, true ), 'pending parser verdict forbids successful sealing.' );
			}
		}
		$state['scopes'][ $scope ]['verdict'] = $passed ? 'passed' : 'failed';
		$state['scopes'][ $scope ]['semantic_sha256'] = hash( 'sha256', json_encode( $semantic_verdict, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		$this->save( $state );
		return $this->scope_proof( $scope );
	}

	public function scope_proof( string $scope ): array {
		$state = $this->state();
		$this->require( isset( $state['scopes'][ $scope ] ), 'missing source-bound scope.' );
		$records = array();
		foreach ( $state['events'] as $record ) {
			if ( $scope === $record['scope'] ) { $records[] = $this->witness( $record ); }
		}
		return array(
			'version' => 1, 'binding' => $this->binding, 'context_sha256' => $this->context_sha256,
			'scope' => $scope, 'verdict' => $state['scopes'][ $scope ]['verdict'],
			'semantic_sha256' => $state['scopes'][ $scope ]['semantic_sha256'], 'events' => $records,
			'cases' => $state['scopes'][ $scope ]['cases'],
			'events_sha256' => hash( 'sha256', json_encode( $records, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ),
		);
	}

	public function preflight(): array {
		$state = $this->state();
		$this->require( 'pending' === $state['retirement'] && self::SCOPES === array_keys( $state['scopes'] ) && array() !== $state['events'], 'incomplete lifecycle wire inventory.' );
		foreach ( $state['scopes'] as $scope => $verdict ) {
			$this->require( array_keys( $verdict ) === array( 'verdict', 'semantic_sha256', 'cases' )
				&& 'passed' === $verdict['verdict'] && is_string( $verdict['semantic_sha256'] )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $verdict['semantic_sha256'] ), 'failed or pending scope retains original wire.' );
			foreach ( $verdict['cases'] as $case ) {
				$this->require( is_array( $case ) && array_keys( $case ) === array( 'passed', 'events', 'sha256' )
					&& true === $case['passed'] && is_array( $case['events'] ) && array() !== $case['events']
					&& array_values( $case['events'] ) === $case['events'] && is_string( $case['sha256'] )
					&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $case['sha256'] ), 'failed or malformed case retains original wire.' );
				$previous = 0;
				foreach ( $case['events'] as $id ) {
					$this->require( is_int( $id ) && $id > $previous
						&& $scope === ( $state['events'][ $id - 1 ]['scope'] ?? null )
						&& 'validated' === $state['events'][ $id - 1 ]['state'], 'case references duplicate, unordered, foreign or unvalidated originals.' );
					$previous = $id;
				}
			}
		}
		$files = array( 'private-wire.json' );
		foreach ( $state['events'] as $index => $record ) {
			$this->verify_record( $record, $index );
			$this->require( 'validated' === $record['state'] && in_array( $record['parser'], self::PARSERS, true ), 'failed or pending event retains original wire.' );
			$files[] = $record['file'];
		}
		sort( $files, SORT_STRING );
		$actual = array_map( 'basename', glob( $this->directory . '/wire-*' ) );
		sort( $actual, SORT_STRING );
		$expected = array_values( array_diff( $files, array( 'private-wire.json' ) ) );
		$this->require( $actual === $expected, 'unknown or missing private wire file.' );
		return $files;
	}

	public function inventory(): array {
		$this->preflight();
		$state = $this->state();
		$inventory = array(
			'private-wire.json' => array( 'identity' => $this->identity, 'sha256' => $this->snapshot['sha256'], 'length' => strlen( $this->snapshot['bytes'] ) ),
		);
		foreach ( $state['events'] as $record ) {
			$inventory[ $record['file'] ] = array( 'identity' => $record['identity'], 'sha256' => $record['sha256'], 'length' => $record['length'] );
		}
		ksort( $inventory, SORT_STRING );
		return $inventory;
	}

	public function retire( array $prepared_inventory ): void {
		$this->require( $prepared_inventory === $this->inventory(), 'prepared journal generation or retirement targets changed.' );
		$state = $this->state();
		$state['retirement'] = 'retiring';
		$this->save( $state );
		foreach ( $state['events'] as $index => $record ) {
			$state = $this->remaining_retirement_state();
			$this->require( null === $state['removal_pending_id'], 'an unconfirmed removal cannot be retried.' );
			$state['removal_pending_id'] = $record['id'];
			$this->save( $state );
			$state = $this->remaining_retirement_state();
			$this->require( $index + 1 === $state['removal_pending_id'], 'durable removal intent changed.' );
			$record = $state['events'][ $index ];
			$file = Wstm108_Files::read_bound( $this->directory . '/' . $record['file'], $record['identity'] );
			$this->require( $file['sha256'] === $record['sha256'] && strlen( $file['bytes'] ) === $record['length'], 'original changed immediately before removal.' );
			Wstm108_Files::remove( $this->directory . '/' . $record['file'], $file );
			// Only a successful remove return authorizes this candidate, never observed absence.
			$state = $this->state();
			$this->require( 'retiring' === $state['retirement'] && $record['id'] === $state['removal_pending_id']
				&& $record === $state['events'][ $index ], 'removal predecessor changed; outcome remains unconfirmed.' );
			$state['events'][ $index ]['removed'] = true;
			$state['removal_pending_id'] = null;
			$this->validate_retirement( $state );
			$this->verify_remaining_inventory( $state, 'retiring' );
			$this->save( $state );
			$this->remaining_retirement_state();
		}
		$state = $this->remaining_retirement_state();
		$state['retirement'] = 'complete';
		$this->save( $state );
		$this->remaining_retirement_state( 'complete' );
		Wstm108_Files::remove( $this->directory . '/private-wire.json', $this->snapshot );
	}

	private function remaining_retirement_state( string $phase = 'retiring' ): array {
		$state = $this->state();
		$this->require( $phase === $state['retirement'], 'no verified retirement in progress.' );
		$this->verify_remaining_inventory( $state, $phase );
		return $state;
	}

	private function verify_remaining_inventory( array $state, string $phase ): void {
		$remaining = array();
		foreach ( $state['events'] as $index => $record ) {
			if ( true === $record['removed'] ) {
				clearstatcache( true, $this->directory . '/' . $record['file'] );
				$this->require( false === @lstat( $this->directory . '/' . $record['file'] ), 'removed original reappeared during partial retirement.' );
				continue;
			}
			$this->verify_record( $record, $index );
			$remaining[] = $record['file'];
		}
		$this->require( 'complete' !== $phase || array() === $remaining, 'incomplete original removal cannot retire its journal.' );
		sort( $remaining, SORT_STRING );
		$actual = glob( $this->directory . '/wire-*' );
		$this->require( is_array( $actual ), 'cannot enumerate remaining private originals.' );
		$actual = array_map( 'basename', $actual );
		sort( $actual, SORT_STRING );
		$this->require( $actual === $remaining, 'private original inventory changed during partial retirement.' );
	}
}
