<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-plan.php';
require_once __DIR__ . '/untrusted-content-wire.php';
require_once __DIR__ . '/untrusted-content-resources.php';
require_once __DIR__ . '/untrusted-content-files.php';

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
			throw new RuntimeException( 'WSTM108 Missing or changed complete raw runner journal.' );
		}
		$catalogs = array();
		foreach ( explode( "\n", rtrim( $file['bytes'], "\n" ) ) as $line ) {
			$entry = json_decode( $line, false, 512, JSON_THROW_ON_ERROR );
			if ( ! $entry instanceof stdClass || ! is_string( $entry->body_base64 ?? null )
				|| false === base64_decode( $entry->body_base64, true ) || ! is_string( $entry->body ?? null )
				|| ! is_int( $entry->status ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Incomplete original raw HTTP evidence.' );
			}
			if ( 'tools/list' !== ( $entry->method ?? null ) ) {
				continue;
			}
			$label = $entry->boundary ?? '';
			if ( ! is_string( $label ) || isset( $catalogs[ $label ] )
				|| ! isset( $runner->catalogs->$label ) || $entry->status < 200 || $entry->status >= 300 ) {
				throw new RuntimeException( 'WSTM108 Duplicate, foreign or failed actual catalog evidence.' );
			}
			$response = json_decode( base64_decode( $entry->body_base64, true ), false, 512, JSON_THROW_ON_ERROR );
			if ( ! $response instanceof stdClass || property_exists( $response, 'error' )
				|| wstm108_wire_canonical( $runner->catalogs->$label ) !== wstm108_wire_canonical( $response->result->tools ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Original wire catalog differs from captured descriptors.' );
			}
			$catalogs[ $label ] = true;
		}
		if ( 6 !== count( $catalogs ) ) {
			throw new RuntimeException( 'WSTM108 Missing raw actor/boundary catalog evidence.' );
		}
	}

	public static function lifecycle( string $directory, object $runner, array $context, string $context_sha256 ): void {
		$phases = array();
		foreach ( array( 'acquire', 'original', 'enable', 'enabled', 'restored', 'finalize' ) as $phase ) {
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
				|| ! is_string( $boot->php ?? null ) || '' === $boot->php
				|| ! is_string( $boot->sapi ?? null ) || '' === $boot->sapi || 'cli' === $boot->sapi
				|| ! is_string( $state->config_sha256 ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state->config_sha256 )
				|| $state->config_sha256 !== ( $boot->identity->config_sha256 ?? null )
				|| ! ( $state->config_identity ?? null ) instanceof stdClass || ! ( $state->mu_identity ?? null ) instanceof stdClass
				|| ! ( $record->providers ?? null ) instanceof stdClass
				|| wstm108_wire_canonical( (object) $context['binding'] ) !== wstm108_wire_canonical( $state->binding ?? null ) ) {
				throw new RuntimeException( 'WSTM108 Missing typed actual runtime/config/provider attestation: ' . $phase );
			}
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
					|| ! is_string( $descriptor->version ?? null ) || '' === $descriptor->version
					|| ! is_string( $descriptor->path ?? null ) || '' === $descriptor->path ) {
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
			|| wstm108_wire_canonical( (object) array( 'runtime_loader' => true, 'probe' => true, 'resource_journal' => true, 'private_lock' => true ) )
				!== wstm108_wire_canonical( $phases['finalize']->retired ?? null ) ) {
			throw new RuntimeException( 'WSTM108 Enabled state or final owned-path absence is incomplete.' );
		}
		$journal = Wstm108_Files::file( $directory . '/original.json.http.jsonl' );
		$controls = array();
		foreach ( explode( "\n", rtrim( $journal['bytes'], "\n" ) ) as $line ) {
			$entry = json_decode( $line, false, 512, JSON_THROW_ON_ERROR );
			if ( ! $entry instanceof stdClass ) {
				throw new RuntimeException( 'WSTM108 Nonobject raw boundary evidence.' );
			}
			if ( in_array( $entry->boundary ?? null, array( 'actual-cli-missing-optin', 'actual-http-cli-only' ), true ) ) {
				$key = $entry->boundary . ':' . ( $entry->file ?? '' );
				if ( isset( $controls[ $key ] ) || ! in_array( $entry->file ?? null, array( 'untrusted-content-stage.php', 'untrusted-content-runner.php' ), true ) ) {
					throw new RuntimeException( 'WSTM108 Duplicate or foreign actual boundary control.' );
				}
				$expected_status = 'actual-cli-missing-optin' === $entry->boundary ? 2 : 403;
				$expected_body = 2 === $expected_status ? '' : 'CLI only.';
				if ( $expected_status !== ( $entry->status ?? null ) || $expected_body !== ( $entry->body ?? null )
					|| ! is_string( $entry->body_base64 ?? null )
					|| $expected_body !== base64_decode( $entry->body_base64 ?? '', true ) ) {
					throw new RuntimeException( 'WSTM108 Actual opt-in/HTTP guard evidence differs.' );
				}
				$flag = 'untrusted-content-stage.php' === $entry->file ? 'WSTM108_STAGE_DISPOSABLE' : 'WSTM108_ALLOW_DISPOSABLE';
				if ( 2 === $expected_status && ( ! is_string( $entry->stderr ?? null ) || false === strpos( $entry->stderr, $flag . '=1 is required before WordPress' ) ) ) {
					throw new RuntimeException( 'WSTM108 Missing actual pre-bootstrap refusal diagnostic.' );
				}
				$controls[ $key ] = true;
			}
		}
		if ( 4 !== count( $controls ) ) {
			throw new RuntimeException( 'WSTM108 Missing actual CLI or HTTP entrypoint control.' );
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
		$require( Wstm108_Plan::labels( $native ) === ( $proof->expected_labels ?? null ) && is_array( $proof->cases ?? null ), 'missing frozen case inventory.' );
		$cases = array();
		foreach ( $proof->cases as $case ) {
			$require( $case instanceof stdClass, 'nonobject case record.' );
			$cases[] = get_object_vars( $case );
		}
		Wstm108_Plan::validate_cases( $cases, $native );
		$passed = count( array_filter( $cases, static fn( $case ) => true === $case['passed'] ) );
		$failed = count( $cases ) - $passed;
		$require( $passed === ( $proof->passed ?? null ) && $failed === ( $proof->failed ?? null )
			&& ( $failed ? 'failed' : 'passed' ) === ( $proof->status ?? null )
			&& array() === ( $proof->blocked ?? null ) && ! isset( $proof->fatal ), 'case counts, blocking failures or status differ.' );
		$require( ( $proof->registered ?? null ) instanceof stdClass
			&& Wstm108_Plan::abilities( $native ) === array_keys( get_object_vars( $proof->registered ) ), 'incomplete actual registration/schema inventory.' );
		foreach ( $proof->registered as $name => $registration ) {
			$require( $registration instanceof stdClass && $name === ( $registration->name ?? null )
				&& is_string( $registration->label ?? null ) && is_string( $registration->description ?? null )
				&& property_exists( $registration, 'input_schema' ) && property_exists( $registration, 'output_schema' )
				&& ( $registration->meta ?? null ) instanceof stdClass, 'missing captured registration fields.' );
			$schema = array(
				'label' => $registration->label, 'description' => $registration->description,
				'input' => $registration->input_schema, 'output' => $registration->output_schema, 'meta' => $registration->meta,
			);
			$require( hash( 'sha256', wstm108_wire_canonical( $schema ) ) === ( $proof->actual_runtime->abilities->$name ?? null ), 'registration differs from actual enabled schema digest.' );
		}
		$require( ( $proof->actors ?? null ) instanceof stdClass && Wstm108_Plan::ACTORS === array_keys( get_object_vars( $proof->actors ) ), 'incomplete actor capability inventory.' );
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
				&& is_array( $actor->roles ?? null ) && ( $actor->caps ?? null ) instanceof stdClass, 'missing actual actor roles/capabilities.' );
			foreach ( Wstm108_Plan::BOUNDARIES as $boundary ) {
				$name = $boundary . ':' . $role;
				$catalog = $proof->catalogs->$name ?? null;
				$require( is_array( $catalog ) && ( 'gateway' === $boundary ? 3 : 95 ) === count( $catalog ), 'incomplete actual advertised catalog: ' . $name );
				$names = array();
				foreach ( $catalog as $tool ) {
					$require( $tool instanceof stdClass && is_string( $tool->name ?? null ) && '' !== $tool->name
						&& ( $tool->inputSchema ?? null ) instanceof stdClass, 'invalid actual catalog descriptor.' );
					$names[] = $tool->name;
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
