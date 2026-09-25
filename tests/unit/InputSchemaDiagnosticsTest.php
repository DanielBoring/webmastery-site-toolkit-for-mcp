<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/input-schema-boot.php';

final class InputSchemaDiagnosticsTest extends TestCase {
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
