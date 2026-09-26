<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-plan.php';
require_once __DIR__ . '/untrusted-content-wire.php';
require_once __DIR__ . '/untrusted-content-resources.php';
require_once __DIR__ . '/untrusted-content-files.php';
require_once __DIR__ . '/untrusted-content-private-wire.php';

final class Wstm108_Proof {
	private array $receipt;

	private function __construct( array $receipt ) {
		$this->receipt = $receipt;
	}

	public function receipt(): array {
		return $this->receipt;
	}

	public static function journal( string $path, object $runner ): void {
		$file = Wstm108_Files::file( $path );
		if ( $file['sha256'] !== ( $runner->http_journal_sha256 ?? null ) || '' === $file['bytes'] ) {
			throw new RuntimeException( 'WSTM108 Missing or changed complete public witness journal.' );
		}
		$events = self::witnesses( $file['bytes'] );
		$wire = $runner->wire_proof ?? null;
		if ( ! $wire instanceof stdClass || 'runner' !== ( $wire->scope ?? null ) || 'passed' !== ( $wire->verdict ?? null )
			|| wstm108_wire_canonical( $events ) !== wstm108_wire_canonical( $wire->events ?? null )
			|| hash( 'sha256', json_encode( $events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) !== ( $wire->events_sha256 ?? null ) ) {
			throw new RuntimeException( 'WSTM108 Public witnesses do not bind the complete private runner inventory.' );
		}
		$catalogs = array();
		foreach ( $events as $entry ) {
			if ( 'tools/list' !== ( $entry->origin->method ?? null ) ) {
				continue;
			}
			$label = $entry->origin->boundary ?? '';
			if ( ! is_string( $label ) || ! isset( $runner->catalogs->$label ) || $entry->status < 200 || $entry->status >= 300
				|| 'catalog' !== $entry->parser || ! is_array( $entry->validation->tools ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Foreign, unparsed or failed actual catalog witness.' );
			}
			if ( isset( $catalogs[ $label ] ) && $catalogs[ $label ]['finished'] ) {
				throw new RuntimeException( 'WSTM108 Duplicate catalog after its terminal page.' );
			}
			$catalogs[ $label ] = array(
				'tools' => array_merge( $catalogs[ $label ]['tools'] ?? array(), $entry->validation->tools ),
				'finished' => hash( 'sha256', 'null' ) === ( $entry->validation->next_cursor_sha256 ?? null ),
			);
		}
		if ( 6 !== count( $catalogs ) ) {
			throw new RuntimeException( 'WSTM108 Missing actor/boundary catalog witnesses.' );
		}
		foreach ( $catalogs as $label => $catalog ) {
			if ( ! $catalog['finished'] || wstm108_wire_canonical( $runner->catalogs->$label ) !== wstm108_wire_canonical( $catalog['tools'] ) ) {
				throw new RuntimeException( 'WSTM108 Actual paginated wire catalog differs from captured descriptor witnesses.' );
			}
		}
	}

	public static function witnesses( string $bytes ): array {
		if ( '' === $bytes || "\n" !== substr( $bytes, -1 ) ) {
			throw new RuntimeException( 'WSTM108 Partial public witness journal.' );
		}
		$events = array();
		$last = 0;
		foreach ( explode( "\n", substr( $bytes, 0, -1 ) ) as $line ) {
			$entry = json_decode( $line, false, 512, JSON_THROW_ON_ERROR );
			if ( ! $entry instanceof stdClass || array_keys( get_object_vars( $entry ) ) !== array(
				'id', 'scope', 'length', 'sha256', 'body_length', 'body_sha256', 'status', 'state', 'parser', 'removed', 'origin', 'validation',
			) || ! is_int( $entry->id ) || $entry->id < 1 || ! is_int( $entry->length ) || $entry->length < 1
				|| ! is_string( $entry->sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $entry->sha256 )
				|| false !== $entry->removed || ! $entry->origin instanceof stdClass ) {
				throw new RuntimeException( 'WSTM108 Incomplete or unsafe original-event witness.' );
			}
			Wstm108_PrivateWire::assert_witness( get_object_vars( $entry ) );
			if ( ! isset( $events[ $entry->id ] ) ) {
				if ( $entry->id <= $last || 'committed' !== $entry->state || null !== $entry->parser || null !== $entry->validation ) {
					throw new RuntimeException( 'WSTM108 Duplicate, unordered or uncommitted original-event witness.' );
				}
				$last = $entry->id;
			} else {
				$before = clone $entry;
				$before->state = 'committed';
				$before->parser = null;
				$before->validation = null;
				if ( 'validated' !== $entry->state || ! is_string( $entry->parser )
					|| wstm108_wire_canonical( $before ) !== wstm108_wire_canonical( $events[ $entry->id ] ) ) {
					throw new RuntimeException( 'WSTM108 Duplicate, changed or foreign parser verdict.' );
				}
			}
			$events[ $entry->id ] = $entry;
			Wstm108_PrivateWire::validate_projection( $entry->parser, null === $entry->validation ? null : json_decode( json_encode( $entry->validation, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR ) );
		}
		foreach ( $events as $entry ) {
			if ( 'validated' !== $entry->state || ! is_string( $entry->parser ) ) {
				throw new RuntimeException( 'WSTM108 Pending or failed parser event forbids retirement.' );
			}
		}
		return array_values( $events );
	}

	private static function wire_proof( object $wire, string $scope, array $binding, string $context_sha256 ): array {
		if ( array_keys( get_object_vars( $wire ) ) !== array( 'version', 'binding', 'context_sha256', 'scope', 'verdict', 'semantic_sha256', 'events', 'cases', 'events_sha256' )
			|| 1 !== $wire->version || $scope !== $wire->scope || 'passed' !== $wire->verdict
			|| $context_sha256 !== $wire->context_sha256 || wstm108_wire_canonical( (object) $binding ) !== wstm108_wire_canonical( $wire->binding )
			|| ! is_string( $wire->semantic_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $wire->semantic_sha256 )
			|| ! is_array( $wire->events ) || array() === $wire->events
			|| hash( 'sha256', json_encode( $wire->events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) !== $wire->events_sha256 ) {
			throw new RuntimeException( 'WSTM108 Incomplete or foreign private-scope witness binding.' );
		}
		$events = array();
		$previous = 0;
		foreach ( $wire->events as $event ) {
			if ( ! $event instanceof stdClass ) { throw new RuntimeException( 'WSTM108 Nonobject private-scope event witness.' ); }
			Wstm108_PrivateWire::assert_witness( get_object_vars( $event ) );
			if ( $scope !== $event->scope || 'validated' !== $event->state || $event->id <= $previous ) {
				throw new RuntimeException( 'WSTM108 Foreign, pending or unordered private-scope event.' );
			}
			$events[ $event->id ] = $event;
			$previous = $event->id;
		}
		return $events;
	}

	public static function process_verdicts( array $chain, array $binding, array $required ): void {
		if ( array_keys( $chain ) !== array( 'binding', 'processes' ) || $binding !== $chain['binding']
			|| ! is_array( $chain['processes'] ) || array_values( $chain['processes'] ) !== $chain['processes']
			|| count( $required ) !== count( $chain['processes'] ) ) {
			throw new RuntimeException( 'WSTM108 Missing independently validated process chain before retirement.' );
		}
		foreach ( $required as $index => $action ) {
			$record = $chain['processes'][ $index ];
			$witness = $record['witness'] ?? null;
			if ( ! is_array( $record ) || array_keys( $record ) !== array( 'witness', 'receipt' ) || ! is_array( $witness )
				|| array_keys( $witness ) !== array( 'action', 'child_exit', 'capture_complete', 'validated', 'stdout', 'stderr' )
				|| $action !== $witness['action'] || 0 !== $witness['child_exit'] || true !== $witness['capture_complete'] || true !== $witness['validated'] ) {
				throw new RuntimeException( 'WSTM108 Failed, foreign or incomplete process verdict before retirement.' );
			}
			foreach ( array( $record['receipt'], $witness['stdout'], $witness['stderr'] ) as $file ) {
				if ( ! is_array( $file ) || array_keys( $file ) !== array( 'identity', 'sha256', 'length' )
					|| ! is_array( $file['identity'] ) || ! is_int( $file['length'] ) || $file['length'] < 0
					|| ! is_string( $file['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $file['sha256'] ) ) {
					throw new RuntimeException( 'WSTM108 Incomplete original process capture witness.' );
				}
				$keys = array_keys( $file['identity'] );
				sort( $keys, SORT_STRING );
				if ( array( 'dev', 'gid', 'ino', 'mode', 'nlink', 'uid' ) !== $keys
					|| 6 !== count( array_filter( $file['identity'], 'is_int' ) ) || 0100600 !== $file['identity']['mode'] || 1 !== $file['identity']['nlink'] ) {
					throw new RuntimeException( 'WSTM108 Process captures lack exact private single-link ownership.' );
				}
			}
			if ( $record['receipt']['length'] < 1 || $witness['stdout']['length'] < 1
				|| 0 !== $witness['stderr']['length'] || hash( 'sha256', '' ) !== $witness['stderr']['sha256'] ) {
				throw new RuntimeException( 'WSTM108 Empty successful framing or unexpected process stderr forbids retirement.' );
			}
		}
	}

	private static function runtime_witness( object $runtime ): void {
		if ( array_keys( get_object_vars( $runtime ) ) !== array( 'sha256', 'abilities' )
			|| ! is_string( $runtime->sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $runtime->sha256 )
			|| ! $runtime->abilities instanceof stdClass ) {
			throw new RuntimeException( 'WSTM108 Unsafe or incomplete runtime schema witness.' );
		}
		foreach ( $runtime->abilities as $name => $sha ) {
			if ( 1 !== preg_match( '/^[a-zA-Z0-9_\/.-]+$/D', $name ) || ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) {
				throw new RuntimeException( 'WSTM108 Unsafe runtime ability/schema witness.' );
			}
		}
	}

	public static function lifecycle( string $directory, object $runner, array $context, string $context_sha256 ): void {
		$phases = array();
		foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize', 'retire' ) as $phase ) {
			$file = Wstm108_Files::file( $directory . '/' . $phase . '.json' );
			$record = json_decode( $file['bytes'], false, 512, JSON_THROW_ON_ERROR );
			if ( ! $record instanceof stdClass || true !== ( $record->completed ?? null )
				|| $phase !== ( $record->operation ?? null ) || $context_sha256 !== ( $record->stage_context_sha256 ?? null )
				|| wstm108_wire_canonical( (object) $context['binding'] ) !== wstm108_wire_canonical( $record->binding ?? null )
				|| wstm108_wire_canonical( $runner->actual_files ?? null ) !== wstm108_wire_canonical( $record->actual_files ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Missing, partial or foreign lifecycle evidence: ' . $phase );
			}
			$phases[ $phase ] = $record;
		}
		foreach ( array( 'original', 'enabled', 'restored', 'finalize' ) as $phase ) {
			$record = $phases[ $phase ];
			$attestation = 'finalize' === $phase ? 'restored_attestation' : $phase . '_attestation';
			$state = $record->state ?? null;
			$boot = $state->$attestation ?? null;
			if ( ! $state instanceof stdClass || ! $boot instanceof stdClass
				|| ! ( $boot->runtime ?? null ) instanceof stdClass || ! ( $boot->identity ?? null ) instanceof stdClass
				|| $context['binding']['owner'] !== ( $boot->identity->owner ?? null )
				|| ! is_int( $boot->identity->uid ?? null ) || $boot->identity->uid < 0
				|| ! is_string( $boot->identity->root ?? null ) || '' === $boot->identity->root
				|| ! is_string( $boot->identity->plugin_root ?? null ) || '' === $boot->identity->plugin_root
				|| ! is_string( $boot->php_sha256 ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $boot->php_sha256 )
				|| ! is_string( $boot->sapi ?? null ) || '' === $boot->sapi || 'cli' === $boot->sapi
				|| ! is_string( $state->config_sha256 ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state->config_sha256 )
				|| $state->config_sha256 !== ( $boot->identity->config_sha256 ?? null )
				|| ! ( $state->config_identity ?? null ) instanceof stdClass || ! ( $state->mu_identity ?? null ) instanceof stdClass
				|| ! ( $record->providers ?? null ) instanceof stdClass
				|| wstm108_wire_canonical( (object) $context['binding'] ) !== wstm108_wire_canonical( $state->binding ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Missing typed actual runtime/config/provider attestation: ' . $phase );
			}
			self::runtime_witness( $boot->runtime );
			foreach ( array( 'config_identity' => 0100000, 'mu_identity' => 0040000 ) as $field => $type ) {
				$identity = get_object_vars( $state->$field );
				$keys = array_keys( $identity );
				sort( $keys, SORT_STRING );
				if ( array( 'dev', 'gid', 'ino', 'mode', 'nlink', 'uid' ) !== $keys
					|| count( array_filter( $identity, 'is_int' ) ) !== 6
					|| $type !== ( $identity['mode'] & 0170000 ) || $identity['nlink'] < 1 ) {
					throw new RuntimeException( 'WSTM108 Incomplete original filesystem identity.' );
				}
			}
			foreach ( array( 'mcp_adapter', 'yoast', 'seopress' ) as $provider ) {
				$descriptor = $record->providers->$provider ?? null;
				if ( ! $descriptor instanceof stdClass || true !== ( $descriptor->active ?? null )
					|| array_keys( get_object_vars( $descriptor ) ) !== array( 'active', 'version_sha256', 'path_sha256' )
					|| ! is_string( $descriptor->version_sha256 ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $descriptor->version_sha256 )
					|| ! is_string( $descriptor->path_sha256 ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $descriptor->path_sha256 ) ) {
					throw new RuntimeException( 'WSTM108 Missing actual active provider descriptor.' );
				}
			}
		}
		if ( true !== ( $phases['original']->stage_options_absent ?? null ) ) {
			throw new RuntimeException( 'WSTM108 Missing original stage-option absence proof.' );
		}
		$original = $phases['original']->state->original_attestation;
		foreach ( array( 'restored', 'finalize' ) as $phase ) {
			if ( wstm108_wire_canonical( $original ) !== wstm108_wire_canonical( $phases[ $phase ]->state->restored_attestation )
				|| $phases['original']->state->config_sha256 !== $phases[ $phase ]->state->config_sha256
				|| wstm108_wire_canonical( $phases['original']->state->config_identity ) !== wstm108_wire_canonical( $phases[ $phase ]->state->config_identity )
				|| wstm108_wire_canonical( $phases['original']->state->mu_identity ) !== wstm108_wire_canonical( $phases[ $phase ]->state->mu_identity )
				|| wstm108_wire_canonical( $phases['original']->providers ) !== wstm108_wire_canonical( $phases[ $phase ]->providers ) ) {
				throw new RuntimeException( 'WSTM108 Original config bytes/metadata, providers or CLI/HTTP state was not restored.' );
			}
		}
		if ( wstm108_wire_canonical( $runner->actual_runtime ?? null ) !== wstm108_wire_canonical( $phases['enabled']->state->enabled_attestation->runtime )
			|| wstm108_wire_canonical( (object) array( 'runtime_loader' => true, 'probe' => true, 'resource_journal' => true, 'private_wire' => true, 'private_lock' => true ) )
				!== wstm108_wire_canonical( $phases['retire']->retired ?? null )
			|| ! ( $phases['finalize']->prepared ?? null ) instanceof stdClass ) {
			throw new RuntimeException( 'WSTM108 Enabled state or final owned-path absence is incomplete.' );
		}
		$prepared = $phases['finalize']->prepared;
		if ( array_keys( get_object_vars( $prepared ) ) !== array( 'version', 'binding', 'context_sha256', 'generation', 'state_sha256', 'inventory_sha256', 'target_count' )
			|| 1 !== $prepared->version || $context_sha256 !== $prepared->context_sha256
			|| wstm108_wire_canonical( (object) $context['binding'] ) !== wstm108_wire_canonical( $prepared->binding )
			|| ! is_string( $prepared->generation ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $prepared->generation )
			|| ! is_string( $prepared->state_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $prepared->state_sha256 )
			|| ! is_string( $prepared->inventory_sha256 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $prepared->inventory_sha256 )
			|| ! is_int( $prepared->target_count ) || $prepared->target_count < 5
			|| wstm108_wire_canonical( $prepared ) !== wstm108_wire_canonical( $phases['retire']->prepared ?? null ) ) {
			throw new RuntimeException( 'WSTM108 Missing, substituted or incomplete prepared retirement binding.' );
		}
		$required = array( 'acquire', 'original', 'enable', 'enabled', 'runner', 'runner-proof', 'restored' );
		$chains = array();
		foreach ( array( 'finalize', 'retire' ) as $phase ) {
			$chain = $phases[ $phase ]->process_verdicts ?? null;
			if ( ! $chain instanceof stdClass ) { throw new RuntimeException( 'WSTM108 Missing finalization process witnesses.' ); }
			$chains[ $phase ] = json_decode( json_encode( $chain, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
			self::process_verdicts( $chains[ $phase ], $context['binding'], $required );
			$required[] = 'finalize';
		}
		if ( $chains['finalize']['processes'] !== array_slice( $chains['retire']['processes'], 0, 7 ) ) {
			throw new RuntimeException( 'WSTM108 Independently witnessed process prefix changed during retirement.' );
		}
		$journal = Wstm108_Files::file( $directory . '/original.json.http.jsonl' );
		$controls = array();
		foreach ( self::witnesses( $journal['bytes'] ) as $entry ) {
			$boundary = $entry->origin->boundary ?? null;
			$name = $entry->origin->file ?? null;
			if ( in_array( $boundary, array( 'actual-cli-missing-optin', 'actual-http-cli-only' ), true ) ) {
				$key = $boundary . ':' . ( $name ?? '' );
				if ( isset( $controls[ $key ] ) || ! in_array( $name, array( 'untrusted-content-stage.php', 'untrusted-content-runner.php' ), true ) ) {
					throw new RuntimeException( 'WSTM108 Duplicate or foreign actual boundary control.' );
				}
				$expected_status = 'actual-cli-missing-optin' === $boundary ? 2 : 403;
				$expected_body = 2 === $expected_status ? '' : 'CLI only.';
				if ( $expected_status !== ( $entry->status ?? null ) || hash( 'sha256', $expected_body ) !== ( $entry->body_sha256 ?? null )
					|| strlen( $expected_body ) !== ( $entry->body_length ?? null )
					|| ( 2 === $expected_status ? 'cli-refusal' : 'http-cli-refusal' ) !== $entry->parser ) {
					throw new RuntimeException( 'WSTM108 Actual opt-in/HTTP guard evidence differs.' );
				}
				$controls[ $key ] = true;
			}
		}
		if ( 4 !== count( $controls ) ) {
			throw new RuntimeException( 'WSTM108 Missing actual CLI or HTTP entrypoint control.' );
		}
		foreach ( array( 'original', 'enabled', 'restored', 'finalize' ) as $phase ) {
			$wire = $phases[ $phase ]->wire_proof ?? null;
			$journal = Wstm108_Files::file( $directory . '/' . $phase . '.json.http.jsonl' );
			$events = self::witnesses( $journal['bytes'] );
			if ( ! $wire instanceof stdClass || 'passed' !== ( $wire->verdict ?? null ) || $phase !== ( $wire->scope ?? null )
				|| $context_sha256 !== ( $wire->context_sha256 ?? null )
				|| wstm108_wire_canonical( (object) $context['binding'] ) !== wstm108_wire_canonical( $wire->binding ?? null )
				|| wstm108_wire_canonical( $events ) !== wstm108_wire_canonical( $wire->events ?? null )
				|| hash( 'sha256', json_encode( $events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) !== ( $wire->events_sha256 ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Lifecycle original-wire verdict or inventory differs.' );
			}
			self::wire_proof( $wire, $phase, $context['binding'], $context_sha256 );
			if ( array() !== (array) $wire->cases ) { throw new RuntimeException( 'WSTM108 Nonrunner scope contains foreign case verdicts.' ); }
		}
	}

	public static function runner( object $proof, array $context, string $context_sha256, array $native ): self {
		$require = static function ( bool $condition, string $message ): void {
			if ( ! $condition ) {
				throw new RuntimeException( 'WSTM108 Incomplete cleanup proof; runtime retained: ' . $message );
			}
		};
		$require( true === ( $proof->completed ?? null ) && true === ( $proof->cleanup_complete ?? null ), 'runner did not finish its entire owned plan and cleanup.' );
		$require( wstm108_wire_canonical( (object) $context['binding'] ) === wstm108_wire_canonical( $proof->binding ?? null ), 'source/tree/project/owner/package identity differs.' );
		$require( $context_sha256 === ( $proof->stage_context_sha256 ?? null ), 'stage provenance file differs.' );
		$require( Wstm108_Plan::BOUNDARIES === ( $proof->boundaries ?? null )
			&& Wstm108_Plan::BOUNDARIES === ( $proof->invoked_boundaries ?? null ), 'missing or foreign HTTP boundary.' );
		$expected_files = json_decode( json_encode( $context['files'], JSON_THROW_ON_ERROR ), false, 512, JSON_THROW_ON_ERROR );
		$require( wstm108_wire_canonical( $expected_files ) === wstm108_wire_canonical( $proof->actual_files ?? null ), 'actual production/harness hash map differs.' );
		$require( ( $proof->actual_runtime ?? null ) instanceof stdClass, 'missing actual enabled runtime.' );
		self::runtime_witness( $proof->actual_runtime );
		$require( Wstm108_Plan::labels( $native ) === ( $proof->expected_labels ?? null ) && is_array( $proof->cases ?? null ), 'missing frozen case inventory.' );
		$cases = array();
		foreach ( $proof->cases as $case ) {
			$require( $case instanceof stdClass, 'nonobject case record.' );
			$cases[] = get_object_vars( $case );
		}
		Wstm108_Plan::validate_cases( $cases, $native );
		$passed = count( array_filter( $cases, static fn( $case ) => true === $case['passed'] ) );
		$failed = count( $cases ) - $passed;
		$require( 0 === $failed && $passed === ( $proof->passed ?? null ) && 0 === ( $proof->failed ?? null )
			&& 'passed' === ( $proof->status ?? null )
			&& array() === ( $proof->blocked ?? null ) && ! isset( $proof->fatal ) && ! isset( $proof->fatal_sha256 ), 'case counts, blocking failures or status differ.' );
		$require( ( $proof->registered ?? null ) instanceof stdClass
			&& Wstm108_Plan::abilities( $native ) === array_keys( get_object_vars( $proof->registered ) ), 'incomplete actual registration/schema inventory.' );
		foreach ( $proof->registered as $name => $registration ) {
			$require( $registration instanceof stdClass && $name === ( $registration->name ?? null )
				&& array_keys( get_object_vars( $registration ) ) === array( 'name', 'annotations', 'input_schema_sha256', 'output_schema_sha256', 'schema_sha256' )
				&& ( $registration->annotations ?? null ) instanceof stdClass
				&& array_keys( get_object_vars( $registration->annotations ) ) === array( 'readonly', 'destructive', 'idempotent' )
				&& 3 === count( array_filter( get_object_vars( $registration->annotations ), 'is_bool' ) ), 'missing captured registration witnesses.' );
			foreach ( array( 'input_schema_sha256', 'output_schema_sha256', 'schema_sha256' ) as $field ) {
				$require( is_string( $registration->$field ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $registration->$field ), 'missing captured schema digest.' );
			}
			$require( $registration->schema_sha256 === ( $proof->actual_runtime->abilities->$name ?? null ), 'registration differs from actual enabled schema digest.' );
		}
		$require( ( $proof->actors ?? null ) instanceof stdClass && Wstm108_Plan::ACTORS === array_keys( get_object_vars( $proof->actors ) ), 'incomplete actor capability inventory.' );
		$require( ( $proof->wire_proof ?? null ) instanceof stdClass, 'missing private runner scope.' );
		$wire_events = self::wire_proof( $proof->wire_proof, 'runner', $context['binding'], $context_sha256 );
		$require( $proof->wire_proof->cases instanceof stdClass
			&& $proof->expected_labels === array_keys( get_object_vars( $proof->wire_proof->cases ) ), 'incomplete ordered private case verdict inventory.' );
		$used_case_events = array();
		foreach ( $proof->cases as $case ) {
			$keys = array_keys( get_object_vars( $case ) );
			$expected = 0 === strpos( $case->label, 'annotations:' ) ? array( 'label', 'passed', 'private_case_sha256', 'descriptor' ) : array( 'label', 'passed', 'private_case_sha256' );
			$require( $expected === $keys && is_string( $case->private_case_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $case->private_case_sha256 ), 'unsafe public case projection.' );
			$verdict = $proof->wire_proof->cases->{$case->label} ?? null;
			$require( $verdict instanceof stdClass && array_keys( get_object_vars( $verdict ) ) === array( 'passed', 'events', 'sha256' )
				&& true === $verdict->passed && $case->private_case_sha256 === $verdict->sha256
				&& is_array( $verdict->events ) && array() !== $verdict->events && array_values( $verdict->events ) === $verdict->events, 'missing exact private case verdict.' );
			$previous = 0;
			foreach ( $verdict->events as $id ) {
				$require( is_int( $id ) && $id > $previous && isset( $wire_events[ $id ] ) && ! isset( $used_case_events[ $id ] ), 'case references missing, shared or unordered originals.' );
				$used_case_events[ $id ] = true;
				$previous = $id;
			}
			$require( 'case-result' === $wire_events[ $previous ]->parser && 'case-result' === ( $wire_events[ $previous ]->origin->boundary ?? null ), 'case verdict lacks its terminal original case record.' );
		}
		$catalog_names = array();
		foreach ( Wstm108_Plan::ACTORS as $role ) {
			foreach ( Wstm108_Plan::BOUNDARIES as $boundary ) {
				$catalog_names[] = $boundary . ':' . $role;
			}
		}
		$actual_catalog_names = ( $proof->catalogs ?? null ) instanceof stdClass ? array_keys( get_object_vars( $proof->catalogs ) ) : array();
		sort( $catalog_names, SORT_STRING );
		sort( $actual_catalog_names, SORT_STRING );
		$require( $catalog_names === $actual_catalog_names, 'missing or extra actor/boundary catalog.' );
		foreach ( Wstm108_Plan::ACTORS as $role ) {
			$actor = $proof->actors->$role;
			$require( $actor instanceof stdClass && is_int( $actor->id ?? null ) && $actor->id > 0
				&& array_keys( get_object_vars( $actor ) ) === array( 'id', 'roles_sha256', 'caps_sha256', 'list_users' )
				&& is_string( $actor->roles_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $actor->roles_sha256 )
				&& is_string( $actor->caps_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $actor->caps_sha256 )
				&& is_bool( $actor->list_users ?? null ), 'missing actual actor role/capability witnesses.' );
			$require( ( 'subscriber' !== $role ) === $actor->list_users, 'actor list_users transition or denied-role capability differs.' );
			foreach ( Wstm108_Plan::BOUNDARIES as $boundary ) {
				$name = $boundary . ':' . $role;
				$catalog = $proof->catalogs->$name ?? null;
				$require( is_array( $catalog ) && ( 'gateway' === $boundary ? 3 : 95 ) === count( $catalog ), 'incomplete actual advertised catalog: ' . $name );
				$names = array();
				foreach ( $catalog as $tool ) {
					$require( $tool instanceof stdClass && is_string( $tool->name ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $tool->name )
						&& array_keys( get_object_vars( $tool ) ) === array( 'name', 'input_schema_sha256', 'output_schema_sha256', 'descriptor_sha256', 'annotations' )
						&& is_string( $tool->input_schema_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $tool->input_schema_sha256 )
						&& is_string( $tool->output_schema_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $tool->output_schema_sha256 )
						&& is_string( $tool->descriptor_sha256 ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $tool->descriptor_sha256 )
						&& ( $tool->annotations ?? null ) instanceof stdClass, 'invalid actual catalog descriptor witness.' );
					$hints = get_object_vars( $tool->annotations );
					$require( array() === array_diff( array_keys( $hints ), array( 'readOnlyHint', 'destructiveHint', 'idempotentHint' ) )
						&& count( $hints ) === count( array_filter( $hints, 'is_bool' ) ), 'unsafe actual annotation hint witness.' );
					$names[] = $tool->name;
				}
				$wire = $proof->wire_proof ?? null;
				$require( $wire instanceof stdClass && 1 === ( $wire->version ?? null ) && 'runner' === ( $wire->scope ?? null )
					&& 'passed' === ( $wire->verdict ?? null ) && $context_sha256 === ( $wire->context_sha256 ?? null )
					&& wstm108_wire_canonical( $proof->binding ) === wstm108_wire_canonical( $wire->binding ?? null )
					&& ( $wire->cases ?? null ) instanceof stdClass
					&& $proof->expected_labels === array_keys( get_object_vars( $wire->cases ) ), 'missing full private case/transport verdict binding.' );
				foreach ( $proof->cases as $case ) {
					$verdict = $wire->cases->{$case->label};
					$require( true === ( $verdict->passed ?? null ) && is_array( $verdict->events ?? null ) && array() !== $verdict->events
						&& is_string( $case->private_case_sha256 ?? null ) && $case->private_case_sha256 === ( $verdict->sha256 ?? null ), 'private case semantic verdict differs.' );
					if ( 0 === strpos( $case->label, 'annotations:' ) ) {
						$name = substr( $case->label, strlen( 'annotations:' ) );
						$registration = $proof->registered->$name;
						$require( ( $case->descriptor ?? null ) instanceof stdClass, 'missing actual annotation descriptor witness.' );
						$matches = array_values( array_filter( $proof->catalogs->{'individual:administrator'}, static fn( $tool ) => $tool->name === $case->descriptor->name ) );
						$require( 1 === count( $matches ) && wstm108_wire_canonical( $matches[0] ) === wstm108_wire_canonical( $case->descriptor ), 'annotation witness differs from actual catalog.' );
						foreach ( array( 'readonly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint' ) as $source => $hint ) {
							$require( is_bool( $registration->annotations->$source ?? null )
								&& $registration->annotations->$source === ( $case->descriptor->annotations->$hint ?? null ), 'actual tool hint differs from registration.' );
						}
					}
				}
				$require( count( $names ) === count( array_unique( $names, SORT_STRING ) ), 'duplicate actual advertised tool name.' );
			}
		}
		$require( ( $proof->resource_proof ?? null ) instanceof stdClass, 'missing owned resource cleanup.' );
		$resources = json_decode( json_encode( $proof->resource_proof, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
		Wstm108_Resources::validate_proof( $resources, $context['binding'] );
		return new self( array(
			'binding' => $context['binding'], 'cleanup_complete' => true, 'stage_context_sha256' => $context_sha256,
			'labels_sha256' => hash( 'sha256', json_encode( Wstm108_Plan::labels( $native ), JSON_THROW_ON_ERROR ) ),
			'boundaries' => Wstm108_Plan::BOUNDARIES, 'resource_proof' => $resources,
		) );
	}
}
