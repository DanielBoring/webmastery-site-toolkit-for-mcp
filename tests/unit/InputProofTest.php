<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';

final class InputProofTest extends TestCase {
	public static function unsafe_invocations(): array {
		return array_map( static fn( $value ) => array( $value ), array( null, '', '0', 'true', ' 1', '1 ' ) );
	}

	/** @dataProvider unsafe_invocations */
	public function test_runtime_opt_in_fails_before_bootstrap( ?string $value ): void {
		$path = dirname( __DIR__ ) . '/e2e/input-schema-runner.php';
		$code = 'putenv(' . var_export( 'WSTM126_DISPOSABLE' . ( null === $value ? '' : '=' . $value ), true ) . '); require ' . var_export( $path, true ) . ';';
		$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( 'WSTM126_DISPOSABLE=1', $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
	}

	public function test_type_enum_and_closed_key_mutants_reach_the_sentinel_callback(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-input.php' );
		$mutants = array(
			array( 'if ( ! $valid_type )', 'if ( false )', array( 'value' => 42 ) ),
			array( "isset( \$schema['enum'] ) &&", "false && isset( \$schema['enum'] ) &&", array( 'value' => 'allowed!' ) ),
			array( "false === ( \$schema['additionalProperties'] ?? true )", "false && false === ( \$schema['additionalProperties'] ?? true )", array( 'value' => 'allowed', 'unknown' => true ) ),
		);
		foreach ( $mutants as $index => [ $before, $after, $input ] ) {
			$mutant = str_replace( $before, $after, $source, $count );
			self::assertSame( 1, $count, 'Mutation did not target exactly one production check.' );
			$namespace = 'Wstm126Mutant' . $index;
			eval( 'namespace ' . $namespace . '; use \WP_Error; use \stdClass; use \Webmastery_MCP_Response; use \Webmastery_MCP_Posts; use \Webmastery_MCP_Media; use \Webmastery_MCP_Taxonomy; ' . substr( $mutant, 5 ) );
			$calls = 0;
			$args = array(
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string', 'enum' => array( 'allowed' ) ) ) ),
				'execute_callback' => static function () use ( &$calls ) { $calls++; return array( 'success' => true ); },
			);
			if ( 0 === $index ) {
				unset( $args['input_schema']['properties']['value']['enum'] );
			}
			$normal = Webmastery_MCP_Input::register_args( $args, 'webmastery-site-toolkit-for-mcp/probe' );
			self::assertFalse( $normal['execute_callback']( $input )['success'] );
			self::assertSame( 0, $calls );
			$class = $namespace . '\\Webmastery_MCP_Input';
			$broken = $class::register_args( $args, 'webmastery-site-toolkit-for-mcp/probe' );
			self::assertTrue( $broken['execute_callback']( $input )['success'], 'Negative control did not remove the intended safeguard.' );
			self::assertSame( 1, $calls, 'Mutation must reach a real sentinel, not merely change the error message.' );
		}
	}

	public function test_state_hook_and_callback_work_oracles_reject_negative_controls(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-fixture.php' );
		$start = strpos( $source, 'function wstm126_require' );
		$end = strpos( $source, "add_filter( 'wp_register_ability_args'" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		eval( 'namespace Wstm126Oracle; use \RuntimeException; ' . substr( $source, $start, $end - $start ) );
		$clean = array( 'callbacks' => array( array( 'queries' => 0, 'capabilities' => 0 ) ), 'mutations' => array() );
		Wstm126Oracle\wstm126_assert_no_work( array( 'same' ), array( 'same' ), $clean );
		$cases = array(
			array( array( 'same' ), array() ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => false ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array() ) ) ) ),
			array( array( 'different' ), $clean ),
			array( array( 'same' ), array_replace( $clean, array( 'mutations' => array( 'write-then-rollback' ) ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array( 'queries' => 1, 'capabilities' => 0 ) ) ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array( 'queries' => 0, 'capabilities' => 1 ) ) ) ) ),
		);
		foreach ( $cases as [ $after, $evidence ] ) {
			try {
				Wstm126Oracle\wstm126_assert_no_work( array( 'same' ), $after, $evidence );
				self::fail( 'Broken no-work control passed.' );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}
}
