<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';

final class InputProofTest extends TestCase {
	public static function registry_initialization_cases(): array {
		return array(
			'lazy registration' => array( 'lazy', 'ready', '', 1 ),
			'old order mutant' => array( 'old-order', ReflectionException::class, 'Class "Webmastery_MCP_Ability" does not exist', 0 ),
			'missing API' => array( 'missing-api', RuntimeException::class, 'Required native abilities runtime unavailable.', 0 ),
			'missing ability class' => array( 'missing-class', ReflectionException::class, 'Class "Webmastery_MCP_Ability" does not exist', 1 ),
			'foreign ability source' => array( 'foreign-ability', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
			'foreign input source' => array( 'foreign-input', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
			'foreign response source' => array( 'foreign-response', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
		);
	}

	/** @dataProvider registry_initialization_cases */
	public function test_runner_initializes_lazy_registry_before_source_reflection( string $variant, string $expected_type, string $expected_message, int $expected_initializations ): void {
		$root = dirname( __DIR__, 2 );
		$runner = dirname( __DIR__ ) . '/e2e/input-schema-runner.php';
		$source = str_replace( "\r\n", "\n", file_get_contents( $runner ) );
		$start_marker = "if ( ! defined( 'WSTM126_DISPOSABLE_RUNTIME' )";
		$end_marker = "\$summary['source_hashes'] =";
		self::assertSame( 1, substr_count( $source, $start_marker ) );
		self::assertSame( 1, substr_count( $source, $end_marker ) );
		$start = strpos( $source, $start_marker );
		$end = strpos( $source, $end_marker );
		self::assertGreaterThan( $start, $end );
		$preflight = substr( $source, $start, $end - $start );
		if ( 'old-order' === $variant ) {
			$preflight = str_replace(
				"wstm126_require( function_exists( 'wp_get_abilities' ), 'Required native abilities runtime unavailable.' );\nwp_get_abilities();\n",
				'',
				$preflight,
				$count
			);
			self::assertSame( 1, $count, 'Restore exactly the original pre-initialization order.' );
		}
		// Evaluate the actual preflight without bootstrapping WordPress or seeding fixtures.
		$preflight = str_replace( '__DIR__', var_export( dirname( $runner ), true ), $preflight );
		$code = 'define("ABSPATH",' . var_export( $root . '/', true ) . ');'
			. 'define("WSTM126_DISPOSABLE_RUNTIME",true);define("WSTM126_STAGE_TOKEN",str_repeat("a",32));'
			. '$stage_token=WSTM126_STAGE_TOKEN;$source_sha=str_repeat("b",40);$project="registry-test";$boundary="direct";'
			. '$GLOBALS["initializations"]=0;$GLOBALS["observations"]=0;class WP_Ability{}class WP_Error{}'
			. 'function wstm126_begin(){}'
			. 'function wstm126_require($valid,$message){if(!$valid){throw new RuntimeException($message);}}'
			. 'function get_option($name,$default){$GLOBALS["observations"]++;return false;}'
			. 'function get_bloginfo($name){return "isolated-double";}';
		foreach ( array( 'input', 'response' ) as $name ) {
			$code .= 'foreign-' . $name === $variant
				? 'class Webmastery_MCP_' . ucfirst( $name ) . '{}'
				: 'require ' . var_export( $root . '/includes/class-' . $name . '.php', true ) . ';';
		}
		if ( 'missing-api' !== $variant ) {
			$initialize = 'missing-class' === $variant ? '' : (
				'foreign-ability' === $variant ? 'class Webmastery_MCP_Ability{}' : 'require ' . var_export( $root . '/includes/class-ability.php', true ) . ';'
			);
			$code .= 'function wp_get_abilities(){$GLOBALS["initializations"]++;' . $initialize . 'return [];}';
		}
		$code .= '$before=class_exists("Webmastery_MCP_Ability",false);$type="ready";$message="";$verified=false;'
			. 'try{eval(' . var_export( $preflight, true ) . ');$verified=true;}'
			. 'catch(Throwable $error){$type=get_class($error);$message=$error->getMessage();}'
			. 'echo json_encode([$type,$message,$GLOBALS["initializations"],$GLOBALS["observations"],$before,$verified,$summary["expected_cases"]],JSON_THROW_ON_ERROR);';
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		self::assertSame(
			array( $expected_type, $expected_message, $expected_initializations, 1, false, 'ready' === $expected_type, 152 ),
			json_decode( $output, true, 32, JSON_THROW_ON_ERROR )
		);
	}

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
