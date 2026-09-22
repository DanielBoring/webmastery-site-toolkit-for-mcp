<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';

final class InputProofTest extends TestCase {
	public function test_runtime_adds_exact_typed_destructive_matrix_and_hashes_native_validator(): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' ) );
		self::assertStringContainsString( "__DIR__ . '/../../includes/class-ability.php'", $source );
		$start = strpos( $source, "\tforeach ( array(\n\t\t'bulk-trash-posts'" );
		$end = strpos( $source, "\tforeach ( \$cases as \$label =>" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$posts = array( 'post' => 42 );
		foreach ( array( 'direct', 'ability', 'permission', 'http', 'individual' ) as $boundary ) {
			$cases = array();
			eval( substr( $source, $start, $end - $start ) );
			self::assertCount( 43, $cases );
			foreach ( array( 'bulk-trash-posts' => 'ids', 'bulk-publish-posts' => 'ids', 'delete-media' => 'media_id', 'delete-category' => 'category_id', 'delete-tag' => 'tag_id' ) as $slug => $key ) {
				$base = array( $key => 'ids' === $key ? array( 42 ) : 42 );
				foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => null ), array( 'confirm' => 'true' ), array( 'confirm' => 1 ) ) as $index => $flags ) {
					self::assertSame( array( $slug, $base + $flags, 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' ), $cases[ "{$slug}:confirm:{$index}" ] );
				}
				$flag = 'delete-media' === $slug ? 'force' : ( str_starts_with( $slug, 'bulk-' ) ? 'dry_run' : null );
				if ( null !== $flag ) {
					foreach ( array( 'true', 'false', 0, 1, null, array() ) as $index => $value ) {
						self::assertSame( array( $slug, $base + array( 'confirm' => true, $flag => $value ), 'direct' === $boundary ? 'invalid_input' : 'ability_invalid_input' ), $cases[ "{$slug}:{$flag}:{$index}" ] );
					}
				}
			}
		}
	}

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

	public function test_missing_schema_fault_restores_only_the_probes_original_permission_callback(): void {
		require_once __DIR__ . '/fixtures/error-fixture-input-stubs.php';
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/error-contract-fixture.php' );
		$start = strpos( $source, "\t\$probe = wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-missing-schema'" );
		$end = strpos( $source, "\twp_register_ability( 'wstm118-foreign/probe'" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$permissions = $executions = 0;
		$args = array(
			'permission_callback' => static function () use ( &$permissions ) { $permissions++; return true; },
			'execute_callback' => static function () use ( &$executions ) { $executions++; return array( 'success' => true ); },
		);
		eval( 'namespace Wstm126Probe; use \ReflectionProperty; use \RuntimeException; ' . substr( $source, $start, $end - $start ) );
		$schema = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'input_schema' );
		$permission = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'permission_callback' );
		$execute = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'execute_callback' );
		foreach ( array( $schema, $permission, $execute ) as $property ) { $property->setAccessible( true ); }
		self::assertFalse( $probe->registered['input_schema']['additionalProperties'] );
		self::assertSame( array(), $schema->getValue( $probe ) );
		self::assertSame( $args['permission_callback'], $permission->getValue( $probe ) );
		self::assertSame( $probe->registered['execute_callback'], $execute->getValue( $probe ), 'Do not alter core invocation or the wrapped execute callback.' );
		self::assertTrue( ( $permission->getValue( $probe ) )( array( 'probe' => 1 ) ) );
		self::assertSame( 1, $permissions );
		self::assertSame( 0, $executions );

		// Clearing only the stored schema leaves the captured raw-permission schema active.
		$permission->setValue( $probe, $probe->registered['permission_callback'] );
		$error = ( $permission->getValue( $probe ) )( array( 'probe' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'ability_invalid_input', Webmastery_MCP_Response::from_wp_error( $error )['error']['reason'] );
		self::assertSame( 1, $permissions, 'The schema-only mutant must not reach the original callback.' );
		self::assertSame( 0, $executions );

		$ordinary = Webmastery_MCP_Input::register_args( $args, 'webmastery-site-toolkit-for-mcp/ordinary-empty-input' );
		self::assertInstanceOf( WP_Error::class, $ordinary['permission_callback']( array( 'probe' => 1 ) ) );
		self::assertFalse( $ordinary['execute_callback']( array( 'probe' => 1 ) )['success'] );
		self::assertSame( 1, $permissions );
		self::assertSame( 0, $executions );
	}
}
