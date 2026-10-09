<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-stage-envelope.php';

/** Synthetic Docker topology/site responses; real host capture and proof parsers remain in use. */
final class Wstm108_MockDocker {
	public static function main( array $args ): int {
		if ( '1' !== getenv( 'WSTM108_MOCK_ONLY' ) || 'Windows' === PHP_OS_FAMILY ) {
			throw new RuntimeException( 'Synthetic boundary requires explicit opt-in and native POSIX; never invokes Docker.' );
		}
		$checkout = getenv( 'WSTM108_MOCK_CHECKOUT' );
		$root = getenv( 'WSTM108_MOCK_ROOT' );
		$live = getenv( 'WSTM108_MOCK_LIVE' );
		foreach ( array( $checkout, $root, $live ) as $path ) {
			if ( ! is_string( $path ) || realpath( $path ) !== $path ) { throw new RuntimeException( 'Noncanonical synthetic boundary directory.' ); }
			Wstm108_Files::directory( $path );
		}
		if ( $checkout !== getcwd() || 0 !== strpos( $live, $root . '/' ) ) { throw new RuntimeException( 'Foreign synthetic source/private root.' ); }
		$project = getenv( 'COMPOSE_PROJECT_NAME' );
		if ( '--host' === ( $args[0] ?? null ) ) {
			if ( ! in_array( $args[1] ?? null, array( 'unix:///run/docker.sock', 'unix:///var/run/docker.sock' ), true ) ) { throw new RuntimeException( 'Synthetic command attempted an unverified endpoint.' ); }
			$args = array_slice( $args, 2 );
		} elseif ( 'context' !== ( $args[0] ?? null ) ) { throw new RuntimeException( 'Synthetic command must preserve explicit endpoint pinning.' ); }
		$handled = true;
		$json = null;
		if ( array( 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}' ) === $args ) {
			$json = 'unix:///var/run/docker.sock';
		} elseif ( array( 'info', '--format', '{{json .ID}}' ) === $args ) {
			$json = 'synthetic-daemon';
		} elseif ( array( 'info', '--format', '{{json .DockerRootDir}}' ) === $args ) {
			$json = $root . '/docker-data';
		} elseif ( array( 'ps', '--all', '--filter', 'label=com.docker.compose.project=' . $project, '--format', '{"ID":"{{.ID}}"}' ) === $args ) {
			$json = array( array( 'ID' => str_repeat( 'a', 64 ) ) );
		} elseif ( 'inspect' === ( $args[0] ?? null ) && str_repeat( 'a', 64 ) === end( $args ) ) {
			$json = array( 'project' => $project, 'mounts' => array( array( 'Type' => 'bind', 'Source' => $checkout ) ) );
		} elseif ( array( 'compose', '--project-name', $project ) === array_slice( $args, 0, 3 ) ) {
			$tail = array_slice( $args, 3 );
			$package = array( '-f', 'docker-compose.yml', '-f', 'docker-compose.release.yml' );
			$package_mode = array_slice( $tail, 0, 4 ) === $package;
			if ( ( false !== getenv( 'E2E_PACKAGE_ZIP' ) && '' !== getenv( 'E2E_PACKAGE_ZIP' ) ) !== $package_mode ) {
				throw new RuntimeException( 'Synthetic source/original-ZIP Compose identity differs.' );
			}
			if ( $package_mode ) { $tail = array_slice( $tail, 4 ); }
			if ( array( 'config', '--format', 'json', '--no-env-resolution' ) === $tail ) {
				$json = array( 'name' => $project, 'services' => array( 'wordpress' => array( 'volumes' => array( array( 'type' => 'bind', 'source' => $checkout ) ) ) ), 'volumes' => array() );
			} elseif ( array( 'ps', '--all', '--format', 'json' ) === $tail ) {
				$json = array( array( 'ID' => str_repeat( 'a', 64 ) ) );
			} elseif ( in_array( 'exec', $tail, true ) && in_array( end( $tail ), array(
				'/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/untrusted-content-runner.php',
			), true ) ) {
				self::trace( $args );
				return self::phase( 'runner', (string) getenv( 'WSTM108_STAGE_CONTEXT' ), $checkout, $live );
			} elseif ( in_array( '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/untrusted-content-stage.php', $tail, true ) ) {
				self::trace( $args );
				return self::phase( $tail[ count( $tail ) - 2 ], end( $tail ), $checkout, $live );
			} else {
				$handled = false;
			}
		} else {
			$handled = false;
		}
		if ( ! $handled ) { return 125; }
		self::trace( $args );
		echo json_encode( $json, JSON_THROW_ON_ERROR ) . "\n";
		return 0;
	}

	private static function trace( array $args ): void {
		if ( false === file_put_contents( (string) getenv( 'TRACE' ), implode( ' ', $args ) . "\n", FILE_APPEND ) ) {
			throw new RuntimeException( 'Cannot persist synthetic command trace.' );
		}
	}

	private static function phase( string $operation, string $container_context, string $checkout, string $live ): int {
		$prefix = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/';
		if ( 0 !== strpos( $container_context, $prefix ) ) { throw new RuntimeException( 'Foreign synthetic container context.' ); }
		$context_path = $checkout . '/' . substr( $container_context, strlen( $prefix ) );
		$file = Wstm108_Files::file( $context_path );
		$context = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$directory = dirname( $context_path );
		$fault = getenv( 'WSTM108_MOCK_FAULT' ) ?: '';
		$create = static fn( string $name, string $bytes ) => Wstm108_Files::create( $live . '/' . $name, $bytes );
		$remove = static function ( string $name ) use ( $live ): void {
			$path = $live . '/' . $name;
			if ( file_exists( $path ) ) { Wstm108_Files::remove( $path, Wstm108_Files::file( $path ) ); }
		};
		$statuses = array( 'acquire' => 41, 'original' => 42, 'enable' => 43, 'enabled' => 49, 'runner' => 44 );
		if ( 'acquire' === $operation ) {
			if ( ! file_exists( $checkout . '/build/wstm116-retention-' . $context['binding']['project'] ) ) { throw new RuntimeException( 'Guard must precede synthetic acquisition.' ); }
			if ( ! file_exists( $checkout . '/build/wstm116-retention-' . $context['binding']['project'] . '-wstm108-' . $context['binding']['owner'] ) ) { throw new RuntimeException( 'Companion must precede all synthetic site mutations.' ); }
			$create( 'context-path.private', $context_path );
			$create( 'private-state', "private-owned-state\n" );
		} elseif ( 'original' === $operation ) {
			$create( 'probe', "owner-authenticated-readonly-probe\n" );
		} elseif ( 'enable' === $operation ) {
			$create( 'loader', "owned-loader-not-wp-config\n" );
		} elseif ( 'runner' === $operation ) {
			if ( '1' !== getenv( 'WSTM108_ALLOW_DISPOSABLE' ) || '' === (string) getenv( 'WSTM108_ARTIFACT' ) ) { throw new RuntimeException( 'Synthetic runner requires actual explicit entrypoint flags.' ); }
			$create( 'private-journal', "private-owned-IDs-and-session-journal\n" );
			$create( 'actor', "owned-actor\n" );
			$create( 'attachment', "owned-attachment\n" );
			$create( 'file', "original-owned-file\n" );
			$body = 'malformed-json' === $fault ? "{\"private\":\"unknown-original-secret\",\xff" : '{"private":"unknown-original-secret","valid":"json","wrong":"semantic"}';
			$create( 'original-wire.private', $body );
			$create( 'original-wire-metadata.private.json', json_encode( array(
				'status' => 'http500' === $fault ? 500 : 200, 'headers' => array( 'set-cookie' => 'unknown-private-cookie' ),
				'body_length' => strlen( $body ), 'body_sha256' => hash( 'sha256', $body ), 'verdict' => 'pending',
			), JSON_THROW_ON_ERROR ) );
			if ( '0' === ( getenv( 'FAIL_OWNED_CLEANUP' ) ?: '0' ) ) {
				foreach ( array( 'actor', 'attachment', 'file' ) as $name ) { $remove( $name ); }
			} else {
				return 45;
			}
		} elseif ( 'restored' === $operation ) {
			if ( '1' === getenv( 'FAIL_RESTORE' ) ) { return 47; }
			$remove( 'loader' );
		} elseif ( 'finalize' === $operation && '1' === getenv( 'FAIL_FINALIZE' ) ) {
			return 48;
		}
		if ( $operation === getenv( 'FAIL_STAGE' ) ) { return $statuses[ $operation ]; }
		$record = 'runner' === $operation ? Wstm108_ProofFixture::runner( $context, $file['sha256'] ) : Wstm108_ProofFixture::phase( $operation, $context, $file['sha256'] );
		if ( in_array( $operation, array( 'finalize', 'retire' ), true ) ) {
			$record->process_verdicts = json_decode( base64_decode( (string) getenv( 'WSTM108_PROCESS_VERDICTS' ), true ), false, 512, JSON_THROW_ON_ERROR );
		}
		$journal = isset( $record->wire_proof ) ? Wstm108_ProofFixture::journal( $record->wire_proof->events ) : '';
		$semantic_failure = 'runner' === $operation && in_array( $fault, array( 'http500', 'malformed-json', 'failed-valid-json' ), true );
		if ( 'runner' === $operation ) {
			if ( $semantic_failure ) {
				$case = array( 'label' => $record->cases[100]->label, 'passed' => false, 'error' => 'synthetic unexpected transport/parser/semantic failure', 'original_sha256' => hash( 'sha256', $body ) );
				$record->cases[100]->passed = false;
				$record->cases[100]->private_case_sha256 = hash( 'sha256', serialize( $case ) );
				$record->wire_proof->cases->{$case['label']}->passed = false;
				$record->wire_proof->cases->{$case['label']}->sha256 = $record->cases[100]->private_case_sha256;
				$record->wire_proof->events[106] = Wstm108_ProofFixture::object( Wstm108_ProofFixture::event( 107, 'runner', array( 'boundary' => 'case-result' ), null, 'case-result', null, null, array( 'case' => $case ) ) );
				$record->wire_proof->events_sha256 = hash( 'sha256', json_encode( $record->wire_proof->events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
				$record->wire_proof->verdict = 'failed';
				$record->status = 'failed';
				--$record->passed;
				++$record->failed;
				$journal = Wstm108_ProofFixture::journal( $record->wire_proof->events );
			}
			$invalid = getenv( 'FAIL_PROOF' ) ?: '';
			if ( 'missing' === $invalid ) {
				self::runner_output();
				return 0;
			}
			if ( 'partial' === $invalid ) { $record->completed = false; }
			foreach ( array( 'foreign-source' => 'source_sha', 'foreign-project' => 'project', 'foreign-owner' => 'owner' ) as $kind => $key ) {
				if ( $kind === $invalid ) { $record->binding->$key = 'foreign'; }
			}
			if ( 'partial-journal' === $fault ) { $journal = substr( $journal, 0, -1 ); }
			if ( 'pending-parser' === $fault || 'partial-spool' === $fault ) {
				$record->wire_proof->events[0]->state = 'committed';
				$record->wire_proof->events[0]->parser = null;
				$record->wire_proof->events_sha256 = hash( 'sha256', json_encode( $record->wire_proof->events, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
				if ( 'partial-spool' === $fault ) {
					Wstm108_Files::update( $live . '/original-wire.private', Wstm108_Files::file( $live . '/original-wire.private' ), 'partial original' );
				}
			}
			$record->http_journal_sha256 = hash( 'sha256', $journal );
		}
		Wstm108_Files::create( $directory . '/' . $operation . '.json.http.jsonl', $journal, true );
		Wstm108_Files::create( $directory . '/' . $operation . '.json', json_encode( $record, JSON_THROW_ON_ERROR ), true );
		if ( 'runner' === $operation ) {
			if ( in_array( $fault, array( 'pre-handler-stdout', 'both-streams' ), true ) ) { echo "unknown-bootstrap-secret\n"; }
			if ( in_array( $fault, array( 'provider-stderr', 'both-streams' ), true ) ) { fwrite( STDERR, "unknown-provider-secret\n" ); }
			self::runner_output( $semantic_failure );
			if ( 'extra-line' === $fault ) { echo "unknown-extra-secret\n"; }
		} elseif ( 'acquire' === $operation ) {
			$anchor = array(
				'version' => 1, 'binding' => $context['binding'], 'root' => '/var/www/html', 'plugin' => rtrim( $prefix, '/' ), 'lock' => '/tmp/wstm108-stage',
				'lock_identity' => Wstm108_Files::directory( $live ), 'state_identity' => Wstm108_Files::file( $live . '/private-state' )['identity'], 'context_sha256' => $file['sha256'],
			);
			if ( 'bad-anchor' === $fault ) {
				echo "WSTM108_ANCHOR %%%\n";
			} else {
				echo 'WSTM108_ANCHOR ' . base64_encode( json_encode( $anchor, JSON_THROW_ON_ERROR ) ) . ( 'truncated-anchor' === $fault ? '' : "\n" );
			}
			if ( 'acquire-stderr' === $fault ) { fwrite( STDERR, "unknown-acquire-secret\n" ); }
			if ( 'failed-acquire' === $fault ) { return 41; }
		} elseif ( 'finalize' === $operation ) {
			if ( 'bad-prepare' === $fault ) { $record->prepared->version = 2; }
			echo 'WSTM108_PREPARED ' . base64_encode( json_encode( $record->prepared, JSON_THROW_ON_ERROR ) ) . "\n";
		} else {
			if ( 'retire' === $operation ) {
				foreach ( array( 'private-state', 'private-journal', 'probe', 'original-wire.private', 'original-wire-metadata.private.json' ) as $name ) { $remove( $name ); }
			}
			echo 'WSTM108 stage ' . $operation . " completed with owned evidence.\n";
		}
		return $semantic_failure ? 44 : 0;
	}

	private static function runner_output( bool $failed = false ): void {
		$labels = Wstm108_Plan::labels( Wstm108_ProofFixture::native() );
		foreach ( $labels as $index => $label ) { echo ( $failed && 100 === $index ? 'FAIL ' : 'PASS ' ) . $label . "\n"; }
		echo 'WSTM108 ' . ( $failed ? 'failed' : 'passed' ) . ': ' . ( count( $labels ) - (int) $failed ) . ' passed; ' . (int) $failed . ' failed. Evidence: ' . getenv( 'WSTM108_ARTIFACT' ) . "\n";
	}
}

try {
	exit( Wstm108_MockDocker::main( array_slice( $argv, 1 ) ) );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Synthetic boundary failed: ' . $error->getMessage() . "\n" );
	exit( 96 );
}
