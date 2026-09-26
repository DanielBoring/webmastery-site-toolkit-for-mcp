<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/input-schema-boot.php';

final class InputSchemaDiagnosticsTest extends TestCase {
	private function runner_fragment( string $start_marker, string $end_marker ): string {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' ) );
		self::assertSame( 1, substr_count( $source, $start_marker ) );
		self::assertSame( 1, substr_count( $source, $end_marker ) );
		$start = strpos( $source, $start_marker );
		$end = strpos( $source, $end_marker );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}

	private function progress_source(): string {
		return $this->runner_fragment( '$progress = static function', "\n\$progress( 'bootstrap', 'begin' );" );
	}

	public function test_progress_is_closed_bounded_streamed_and_uses_existing_source_hashes(): void {
		require_once dirname( __DIR__ ) . '/e2e/input-schema-proof.php';
		$boundary = 'direct';
		eval( $this->progress_source() );
		$hashes = wstm126_source_hashes( dirname( __DIR__, 2 ) );
		$sources = array_intersect_key( $hashes['harness'], array(
			'tests/e2e/input-schema-runner.php' => true, 'tests/e2e/input-schema-cleanup.php' => true,
		) );
		$events = 0;
		$emit = static function ( string $phase, string $event, int $case = 0, array $source_hashes = array() ) use ( $progress, &$events ): array {
			ob_start();
			try { $progress( $phase, $event, $case, $source_hashes ); $line = ob_get_contents(); } finally { ob_end_clean(); }
			self::assertSame( 1, substr_count( $line, "\n" ) );
			self::assertLessThanOrEqual( 1024, strlen( $line ) );
			$record = json_decode( $line, true, 8, JSON_THROW_ON_ERROR );
			self::assertSame( array( 'diagnostic', 'authoritative', 'status', 'boundary', 'phase', 'event', 'case_index', 'memory_bytes', 'peak_memory_bytes', 'source_hashes' ), array_keys( $record ) );
			self::assertSame( 'wstm126-progress', $record['diagnostic'] );
			self::assertFalse( $record['authoritative'] );
			self::assertSame( 'observed', $record['status'] );
			self::assertSame( 'direct', $record['boundary'] );
			self::assertSame( $phase, $record['phase'] );
			self::assertSame( $event, $record['event'] );
			self::assertSame( 'case' === $phase ? $case : null, $record['case_index'] );
			self::assertIsInt( $record['memory_bytes'] );
			self::assertGreaterThan( 0, $record['memory_bytes'] );
			self::assertIsInt( $record['peak_memory_bytes'] );
			self::assertGreaterThanOrEqual( $record['memory_bytes'], $record['peak_memory_bytes'] );
			self::assertSame( $source_hashes, $record['source_hashes'] );
			$events++;
			return $record;
		};
		foreach ( array( 'bootstrap', 'preflight', 'journal', 'setup' ) as $phase ) {
			$emit( $phase, 'begin' );
			$emit( $phase, 'end', 0, 'preflight' === $phase ? $sources : array() );
		}
		foreach ( wstm126_expected_labels() as $index => $label ) {
			$emit( 'case', 'begin', $index + 1 );
			$emit( 'case', 'end', $index + 1 );
		}
		foreach ( array( 'cleanup', 'artifact' ) as $phase ) { $emit( $phase, 'begin' ); $emit( $phase, 'end' ); }
		self::assertSame( 316, $events );
		self::assertSame( array( 'boundary' ), array_keys( ( new ReflectionFunction( $progress ) )->getStaticVariables() ) );
		$runner = file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' );
		self::assertStringContainsString( "\$progress( 'preflight', 'end', 0, array_intersect_key( \$summary['source_hashes']['harness']", $runner );
		self::assertStringNotContainsString( 'register_shutdown_function', $runner );
		self::assertStringNotContainsString( 'memory_limit', $runner );
	}

	public function test_malformed_progress_cannot_disclose_supplied_content(): void {
		$boundary = 'permission';
		eval( $this->progress_source() );
		$private = 'PRIVATE_INPUT_SQL_TOKEN';
		foreach ( array(
			array( $private, 'begin' ), array( 'case', $private, 1 ),
			array( 'case', 'begin', 0 ), array( 'case', 'end', 153 ),
			array( 'cleanup', 'begin', 1 ), array( 'preflight', 'end' ),
			array( 'setup', 'begin', 0, array( $private => $private ) ),
			array( 'preflight', 'end', 0, array( 'tests/e2e/input-schema-runner.php' => $private, 'tests/e2e/input-schema-cleanup.php' => str_repeat( 'b', 64 ) ) ),
		) as $arguments ) {
			ob_start();
			try { $progress( ...$arguments ); $line = ob_get_contents(); } finally { ob_end_clean(); }
			self::assertSame( array( 'diagnostic' => 'wstm126-progress', 'authoritative' => false, 'status' => 'rejected_invalid_input' ), json_decode( $line, true, 8, JSON_THROW_ON_ERROR ) );
			self::assertStringNotContainsString( $private, $line );
		}
	}

	public function test_actual_case_wrapper_preserves_failures_and_emits_indices_not_case_data(): void {
		require_once dirname( __DIR__ ) . '/e2e/input-schema-cleanup.php';
		$boundary = 'direct';
		eval( $this->progress_source() );
		$summary = array( 'passed' => 0, 'failed' => 0, 'cases' => array() );
		$http_secrets = array( 'PRIVATE_SECRET' );
		eval( $this->runner_fragment( '$record = static function', "\n\$progress( 'setup', 'begin' );" ) );
		ob_start();
		try {
			$record( 'PRIVATE_LABEL', static function ( array &$entry ): void { $entry['input'] = 'PRIVATE_INPUT'; } );
			$record( 'PRIVATE_FAILED_LABEL', static function (): void { throw new RuntimeException( 'Original case failure.' ); } );
			$output = ob_get_contents();
		} finally { ob_end_clean(); }
		$records = array_map( static fn( string $line ): array => json_decode( $line, true, 8, JSON_THROW_ON_ERROR ), explode( "\n", trim( $output ) ) );
		self::assertSame( array( 'begin', 'end', 'begin', 'end' ), array_column( $records, 'event' ) );
		self::assertSame( array( 1, 1, 2, 2 ), array_column( $records, 'case_index' ) );
		self::assertSame( 1, $summary['passed'] );
		self::assertSame( 1, $summary['failed'] );
		self::assertSame( 'PRIVATE_INPUT', $summary['cases'][0]['input'] );
		self::assertSame( 'Original case failure.', $summary['cases'][1]['error'] );
		self::assertFalse( $summary['cases'][1]['passed'] );
		self::assertStringNotContainsString( 'PRIVATE', $output );
		self::assertStringNotContainsString( 'Original case failure.', $output );
	}

	public function test_native_early_exit_is_not_replaced_and_has_no_fabricated_end_marker(): void {
		foreach ( array( 0, 23, 255 ) as $status ) {
			$code = '$boundary="direct";' . $this->progress_source()
				. '$progress("setup","begin");$progress("setup","end");$progress("case","begin",1);'
				. ( 0 === $status ? '$progress("case","end",1);' : '' ) . 'exit(' . $status . ');';
			$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			self::assertSame( $status, proc_close( $process ) );
			self::assertSame( '', $error );
			$records = array_map( static fn( string $line ): array => json_decode( $line, true, 8, JSON_THROW_ON_ERROR ), explode( "\n", trim( $output ) ) );
			self::assertSame( 0 === $status ? array( 'begin', 'end', 'begin', 'end' ) : array( 'begin', 'end', 'begin' ), array_column( $records, 'event' ) );
			self::assertSame( 0 === $status ? array( null, null, 1, 1 ) : array( null, null, 1 ), array_column( $records, 'case_index' ) );
		}
	}

	public function test_noncase_markers_follow_actual_runner_phase_order(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' );
		$previous = 0;
		foreach ( array( 'bootstrap', 'preflight', 'journal', 'setup', 'cleanup', 'artifact' ) as $phase ) {
			foreach ( array( 'begin', 'end' ) as $event ) {
				$marker = "\$progress( '{$phase}', '{$event}'";
				self::assertSame( 1, substr_count( $source, $marker ) );
				$position = strpos( $source, $marker );
				self::assertGreaterThan( $previous, $position );
				$previous = $position;
			}
		}
		self::assertLessThan( strpos( $source, '$_SERVER' ), strpos( $source, "\$progress( 'bootstrap', 'begin' );" ) );
		self::assertGreaterThan( strpos( $source, '$cases = array();' ), strpos( $source, "\$progress( 'cleanup', 'begin' );" ) );
	}

	private static function sections(): array {
		return array( 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links', 'credentials', 'observation', 'cron' );
	}

	private static function digests(): array {
		return array_fill_keys( self::sections(), str_repeat( 'a', 64 ) );
	}

	private static function runtime(): array {
		return array(
			'flags' => array(
				'EMPTY_TRASH_DAYS' => array( 'defined' => true, 'value' => 30 ),
				'WSTM116_DISPOSABLE_RUNTIME' => array( 'defined' => true, 'value' => false ),
				'WSTM116_STAGE_TOKEN' => array( 'defined' => true, 'value' => str_repeat( 'a', 64 ) ),
				'WSTM126_DISPOSABLE_RUNTIME' => array( 'defined' => false, 'value' => null ),
				'WSTM126_STAGE_TOKEN' => array( 'defined' => false, 'value' => null ),
			),
			'functions' => array(), 'constants' => array(), 'classes' => array(),
			'loaded' => array( 'schema' => false, 'error' => false, 'foreign' => false ),
			'observation_absent' => true,
		);
	}

	public static function logical_sections(): array {
		$cases = array();
		foreach ( self::sections() as $section ) { $cases[ $section ] = array( $section ); }
		return $cases;
	}

	/** @dataProvider logical_sections */
	public function test_only_logical_section_and_two_valid_digests_are_disclosed( string $section ): void {
		$expected = self::digests();
		$observed = $expected;
		$observed[ $section ] = str_repeat( 'b', 64 );
		self::assertSame( array(
			'diagnostic' => 'wstm126', 'kind' => 'cleanup_scope', 'authoritative' => false, 'status' => 'comparison_failed',
			'differences' => array( array( 'dimension' => $section, 'expected_sha256' => str_repeat( 'a', 64 ), 'observed_sha256' => str_repeat( 'b', 64 ) ) ),
		), Wstm126_Boot::diagnostic( 'cleanup_scope', $expected, $observed ) );
		self::assertSame( self::digests(), $expected );
	}

	public function test_full_section_set_is_bounded_and_diagnostic_sink_cannot_replace_original_error(): void {
		$expected = self::digests();
		$observed = array_fill_keys( self::sections(), str_repeat( 'b', 64 ) );
		$lines = array();
		try {
			Wstm126_Boot::fail_with_diagnostic( 'Preexisting state or cron changed.', 'cleanup_scope', $expected, $observed, static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
				throw new Error( 'never-emit-private-sink-failure' );
			} );
			self::fail( 'Diagnostic sink hid the original comparison failure.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Preexisting state or cron changed.', $error->getMessage() );
		}
		self::assertCount( 1, $lines );
		self::assertLessThanOrEqual( 4096, strlen( $lines[0] ) );
		self::assertSame( 1, substr_count( $lines[0], "\n" ) );
		self::assertSame( self::sections(), array_column( json_decode( $lines[0], true, 16, JSON_THROW_ON_ERROR )['differences'], 'dimension' ) );
		self::assertSame( self::digests(), $expected );
	}

	public static function malformed_scope_inputs(): array {
		$cases = array();
		foreach ( array( 'null', 'object', 'scalar', 'unknown section', 'missing section', 'reordered sections', 'null digest', 'array digest', 'object digest', 'integer digest', 'uppercase digest', 'short digest', 'long digest', 'newline digest' ) as $variant ) {
			foreach ( array( 'expected', 'observed' ) as $side ) { $cases[ $side . ':' . $variant ] = array( $side, $variant ); }
		}
		return $cases;
	}

	/** @dataProvider malformed_scope_inputs */
	public function test_malformed_scope_inputs_are_explicitly_rejected_without_partial_disclosure( string $side, string $variant ): void {
		$good = self::digests();
		$bad = $good;
		$bad['posts'] = str_repeat( 'b', 64 );
		switch ( $variant ) {
			case 'null': $bad = null; break;
			case 'object': $bad = (object) $good; break;
			case 'scalar': $bad = 'never-emit-private-input'; break;
			case 'unknown section': $bad['wp_secret_physical_table'] = str_repeat( 'b', 64 ); break;
			case 'missing section': unset( $bad['cron'] ); break;
			case 'reordered sections': $bad = array_reverse( $bad, true ); break;
			case 'null digest': $bad['cron'] = null; break;
			case 'array digest': $bad['cron'] = array( 'never-emit-private-input' ); break;
			case 'object digest': $bad['cron'] = (object) array( 'secret' => 'never-emit-private-input' ); break;
			case 'integer digest': $bad['cron'] = 123; break;
			case 'uppercase digest': $bad['cron'] = str_repeat( 'A', 64 ); break;
			case 'short digest': $bad['cron'] = str_repeat( 'a', 63 ); break;
			case 'long digest': $bad['cron'] = str_repeat( 'a', 65 ); break;
			case 'newline digest': $bad['cron'] = str_repeat( 'a', 64 ) . "\n"; break;
		}
		$record = Wstm126_Boot::diagnostic( 'cleanup_scope', 'expected' === $side ? $bad : $good, 'observed' === $side ? $bad : $good );
		self::assertSame( array( 'diagnostic' => 'wstm126', 'kind' => 'cleanup_scope', 'authoritative' => false, 'status' => 'rejected_invalid_input', 'differences' => array() ), $record );
	}

	public static function malformed_runtime_inputs(): array {
		$cases = array();
		foreach ( array( 'null', 'object', 'scalar', 'unknown dimension', 'missing dimension', 'reordered dimensions', 'unknown flag', 'missing flag', 'flag fields', 'flag defined type', 'integer flag type', 'boolean flag type', 'raw token', 'undefined value', 'unknown loader', 'loader type', 'observation type', 'namespace type', 'namespace keys', 'namespace item type', 'namespace item length', 'namespace count', 'namespace prefix' ) as $variant ) {
			foreach ( array( 'expected', 'observed' ) as $side ) { $cases[ $side . ':' . $variant ] = array( $side, $variant ); }
		}
		return $cases;
	}

	/** @dataProvider malformed_runtime_inputs */
	public function test_malformed_runtime_inputs_never_expose_values_or_unknown_dimensions( string $side, string $variant ): void {
		$good = self::runtime();
		$bad = $good;
		$bad['flags']['EMPTY_TRASH_DAYS']['value'] = 31;
		switch ( $variant ) {
			case 'null': $bad = null; break;
			case 'object': $bad = (object) $good; break;
			case 'scalar': $bad = 'never-emit-private-input'; break;
			case 'unknown dimension': $bad['never-emit-environment'] = 'never-emit-private-input'; break;
			case 'missing dimension': unset( $bad['loaded'] ); break;
			case 'reordered dimensions': $bad = array_reverse( $bad, true ); break;
			case 'unknown flag': $bad['flags']['NEVER_EMIT_CREDENTIAL'] = array( 'defined' => true, 'value' => 'never-emit-private-input' ); break;
			case 'missing flag': unset( $bad['flags']['WSTM116_STAGE_TOKEN'] ); break;
			case 'flag fields': $bad['flags']['EMPTY_TRASH_DAYS']['never-emit'] = 'never-emit-private-input'; break;
			case 'flag defined type': $bad['flags']['EMPTY_TRASH_DAYS']['defined'] = 'true'; break;
			case 'integer flag type': $bad['flags']['EMPTY_TRASH_DAYS']['value'] = '30'; break;
			case 'boolean flag type': $bad['flags']['WSTM116_DISPOSABLE_RUNTIME']['value'] = 1; break;
			case 'raw token': $bad['flags']['WSTM116_STAGE_TOKEN']['value'] = 'never-emit-private-token'; break;
			case 'undefined value': $bad['flags']['WSTM126_STAGE_TOKEN']['value'] = str_repeat( 'a', 64 ); break;
			case 'unknown loader': $bad['loaded']['never-emit-path'] = 'never-emit-private-input'; break;
			case 'loader type': $bad['loaded']['schema'] = 'false'; break;
			case 'observation type': $bad['observation_absent'] = array( 'never-emit-private-input' ); break;
			case 'namespace type': $bad['functions'] = 'never-emit-private-input'; break;
			case 'namespace keys': $bad['functions'] = array( 'never-emit-path' => 'wstm126_probe' ); break;
			case 'namespace item type': $bad['functions'] = array( array( 'never-emit-private-input' ) ); break;
			case 'namespace item length': $bad['functions'] = array( 'wstm126' . str_repeat( 'a', 122 ) ); break;
			case 'namespace count': $bad['functions'] = array_fill( 0, 65, 'wstm126_probe' ); break;
			case 'namespace prefix': $bad['functions'] = array( 'never_emit_private_path' ); break;
		}
		$record = Wstm126_Boot::diagnostic( 'cli_runtime', 'expected' === $side ? $bad : $good, 'observed' === $side ? $bad : $good );
		self::assertSame( array( 'diagnostic' => 'wstm126', 'kind' => 'cli_runtime', 'authoritative' => false, 'status' => 'rejected_invalid_input', 'differences' => array() ), $record );
	}

	public function test_runtime_values_are_hashed_not_dumped_and_original_arrays_are_unchanged(): void {
		$expected = self::runtime();
		$observed = $expected;
		$observed['functions'] = array( 'wstm126_never_emit_private_name' );
		$observed['observation_absent'] = false;
		$record = Wstm126_Boot::diagnostic( 'cli_runtime', $expected, $observed );
		self::assertSame( array(
			array( 'dimension' => 'functions', 'expected_sha256' => hash( 'sha256', serialize( array() ) ), 'observed_sha256' => hash( 'sha256', serialize( $observed['functions'] ) ) ),
			array( 'dimension' => 'observation_absent', 'expected_sha256' => hash( 'sha256', serialize( true ) ), 'observed_sha256' => hash( 'sha256', serialize( false ) ) ),
		), $record['differences'] );
		self::assertFalse( $record['authoritative'] );
		self::assertStringNotContainsString( 'never_emit', json_encode( $record, JSON_THROW_ON_ERROR ) );
		self::assertSame( self::runtime(), $expected );
		self::assertSame( array( 'wstm126_never_emit_private_name' ), $observed['functions'] );
	}

	public function test_default_cli_sink_emits_only_bounded_json_on_stderr_and_preserves_original_error(): void {
		$expected = self::digests();
		$observed = $expected;
		$observed['cron'] = str_repeat( 'b', 64 );
		$boot = var_export( dirname( __DIR__ ) . '/e2e/input-schema-boot.php', true );
		$code = 'require ' . $boot . ';try{Wstm126_Boot::fail_with_diagnostic("Preexisting state or cron changed.","cleanup_scope",'
			. var_export( $expected, true ) . ',' . var_export( $observed, true )
			. ');}catch(RuntimeException $error){echo json_encode(["error"=>$error->getMessage()],JSON_THROW_ON_ERROR);exit(23);}exit(24);';
		$command = array( PHP_BINARY );
		$ini = php_ini_loaded_file();
		if ( false !== $ini ) { $command = array_merge( $command, array( '-c', $ini ) ); }
		$command = array_merge( $command, array( '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $code ) );
		$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 23, proc_close( $process ) );
		self::assertSame( array( 'error' => 'Preexisting state or cron changed.' ), json_decode( $out, true, 16, JSON_THROW_ON_ERROR ) );
		self::assertLessThanOrEqual( 4096, strlen( $error ) );
		self::assertSame( 1, substr_count( $error, "\n" ) );
		self::assertSame( array(
			'diagnostic' => 'wstm126', 'kind' => 'cleanup_scope', 'authoritative' => false, 'status' => 'comparison_failed',
			'differences' => array( array( 'dimension' => 'cron', 'expected_sha256' => str_repeat( 'a', 64 ), 'observed_sha256' => str_repeat( 'b', 64 ) ) ),
		), json_decode( $error, true, 16, JSON_THROW_ON_ERROR ) );
	}

	public function test_unknown_kind_has_a_fixed_rejection_and_cannot_replace_original_failure(): void {
		$lines = array();
		try {
			Wstm126_Boot::fail_with_diagnostic( 'Fresh CLI runtime differs from expected state.', 'never-emit-private-kind', null, null, static function ( string $line ) use ( &$lines ): void { $lines[] = $line; } );
			self::fail( 'Rejected diagnostic hid the original failure.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Fresh CLI runtime differs from expected state.', $error->getMessage() );
		}
		self::assertCount( 1, $lines );
		self::assertSame( array( 'diagnostic' => 'wstm126', 'kind' => 'invalid', 'authoritative' => false, 'status' => 'rejected_invalid_input', 'differences' => array() ), json_decode( $lines[0], true, 16, JSON_THROW_ON_ERROR ) );
	}

	public static function malformed_kinds(): array {
		return array(
			'null' => array( null ),
			'boolean' => array( true ),
			'integer' => array( 1 ),
			'array' => array( array( 'never-emit-private-kind' ) ),
			'object' => array( (object) array( 'private' => 'never-emit-private-kind' ) ),
		);
	}

	/** @dataProvider malformed_kinds */
	public function test_unknown_kind_types_are_rejected_without_conversion_or_content( $kind ): void {
		self::assertSame( array( 'diagnostic' => 'wstm126', 'kind' => 'invalid', 'authoritative' => false, 'status' => 'rejected_invalid_input', 'differences' => array() ), Wstm126_Boot::diagnostic( $kind, self::digests(), self::digests() ) );
	}
}
