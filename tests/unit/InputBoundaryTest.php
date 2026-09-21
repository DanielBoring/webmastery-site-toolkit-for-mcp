<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm126Boundary\Probe;
use Wstm126Boundary\BoundaryReached;

require_once __DIR__ . '/fixtures/input-boundary-stubs.php';

final class InputBoundaryTest extends TestCase {
	protected function setUp(): void {
		Probe::reset();
	}

	private function definition( string $slug ): array {
		return Probe::$abilities[ 'webmastery-site-toolkit-for-mcp/' . $slug ];
	}

	private function input( array $schema ): array {
		$input = array();
		foreach ( $schema['required'] ?? array() as $key ) {
			$property = $schema['properties'][ $key ];
			$type = is_array( $property['type'] ) ? $property['type'][0] : $property['type'];
			$input[ $key ] = $property['enum'][0] ?? match ( $type ) {
				'integer', 'number' => 42,
				'boolean' => true,
				'array' => array( 42 ),
				'object' => array(),
				default => 'value',
			};
		}
		return $input;
	}

	private function rejected( $result, bool $permission, string $reason = 'ability_invalid_input' ): void {
		if ( $permission ) {
			self::assertInstanceOf( WP_Error::class, $result, 'Permission failure must not be a truthy error array.' );
			$result = Webmastery_MCP_Response::from_wp_error( $result );
		}
		self::assertFalse( $result['success'] );
		self::assertSame( 'missing_confirmation' === $reason ? 'precondition_failed' : 'invalid_input', $result['error']['code'] );
		self::assertSame( $reason, $result['error']['reason'] );
		self::assertInstanceOf( stdClass::class, $result['error']['details'] );
		self::assertSame( array(), Probe::$events, 'Invalid input reached capability, read, query or write boundaries.' );
	}

	public function test_every_registered_ability_is_closed_covered_and_guards_raw_callbacks(): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		$names = array_column( $manifest, 'ability' );
		self::assertGreaterThan( 60, count( Probe::$abilities ) );
		foreach ( Probe::$abilities as $name => $args ) {
			self::assertContains( $name, $names );
			self::assertFalse( $args['input_schema']['additionalProperties'], $name );
			$input = $this->input( $args['input_schema'] );
			foreach ( array( 'execute_callback' => false, 'permission_callback' => true ) as $key => $permission ) {
				$this->rejected( $args[ $key ]( $input + array( 'unadvertised_property' => true ) ), $permission );
				foreach ( array( true, false, 1, 0, 1.5, 'array', array( 42 ), new DateTimeImmutable() ) as $malformed ) {
					$this->rejected( $args[ $key ]( $malformed ), $permission );
				}
				foreach ( $args['input_schema']['properties'] ?? array() as $field => $property ) {
					if ( in_array( 'null', (array) ( $property['type'] ?? array() ), true ) ) { continue; }
					$reason = ! $permission && 'confirm' === $field ? 'missing_confirmation'
						: ( ! $permission && in_array( $field, array( 'ids', 'force', 'dry_run' ), true ) && isset( $args['input_schema']['properties']['confirm'] ) ? 'invalid_input' : 'ability_invalid_input' );
					$this->rejected( $args[ $key ]( array_replace( $input, array( $field => null ) ) ), $permission, $reason );
				}
			}
		}
	}

	public function test_all_advertised_enum_values_are_exact_and_never_sanitized_into_validity(): void {
		foreach ( Probe::$abilities as $args ) {
			foreach ( $args['input_schema']['properties'] ?? array() as $field => $property ) {
				if ( ! isset( $property['enum'] ) ) { continue; }
				foreach ( $property['enum'] as $valid ) {
					self::assertNull( Webmastery_MCP_Input::validate( $valid, $property ) );
				}
				$values = array( null, array(), (object) array(), 1, 1.0, false, 'INVALID' );
				if ( is_string( $property['enum'][0] ) ) {
					$values[] = ' ' . $property['enum'][0];
					$values[] = '<b>' . $property['enum'][0] . '</b>';
				}
				foreach ( $values as $value ) {
					if ( in_array( $value, $property['enum'], true ) ) { continue; }
					$input = array_replace( $this->input( $args['input_schema'] ), array( $field => $value ) );
					$this->rejected( $args['execute_callback']( $input ), false, 'confirm' === $field ? 'missing_confirmation' : 'ability_invalid_input' );
					$this->rejected( $args['permission_callback']( $input ), true );
				}
			}
		}
	}

	public function test_parent_presence_is_rejected_for_posts_and_nonhierarchical_cpts_even_zero(): void {
		foreach ( array( 'create-post', 'update-post', 'create-cpt-mcp-book', 'update-cpt-mcp-book' ) as $slug ) {
			$args = $this->definition( $slug );
			foreach ( array( 0, 42, null, false, '0', array(), (object) array() ) as $parent ) {
				$input = $this->input( $args['input_schema'] ) + array( 'parent' => $parent );
				$this->rejected( $args['execute_callback']( $input ), false );
				$this->rejected( $args['permission_callback']( $input ), true );
			}
		}
		foreach ( array( 'create-page', 'update-page', 'create-cpt-mcp-case-study', 'update-cpt-mcp-case-study' ) as $slug ) {
			$schema = $this->definition( $slug )['input_schema'];
			foreach ( array( array(), array( 'parent' => 0 ), array( 'parent' => 42 ) ) as $parent ) {
				self::assertNull( Webmastery_MCP_Input::validate( $this->input( $schema ) + $parent, $schema ) );
			}
		}
	}

	public function test_existing_presence_only_metadata_rejection_precedes_schema_and_hooks(): void {
		foreach ( array( 'create-post', 'update-page', 'create-cpt-mcp-book', 'update-cpt-mcp-case-study' ) as $slug ) {
			$args = $this->definition( $slug );
			foreach ( array( 'meta', 'meta_input', 'yoast_unknown', '_seopress_titles_title' ) as $field ) {
				foreach ( array( null, array(), false, 'value' ) as $value ) {
					$input = $this->input( $args['input_schema'] ) + array( $field => $value );
					$this->rejected( $args['execute_callback']( $input ), false, 'metadata_requires_separate_call' );
					$this->rejected( $args['permission_callback']( $input ), true, 'metadata_requires_separate_call' );
				}
			}
		}
	}

	public function test_raw_numeric_boolean_string_and_array_properties_fail_without_queries(): void {
		foreach ( array(
			array( 'get-post', 'post_id', array( '42', 42.0, true, array(), (object) array() ) ),
			array( 'delete-media', 'force', array( 'false', 0, array(), (object) array() ) ),
			array( 'create-post', 'title', array( 42, true, array(), (object) array() ) ),
			array( 'create-post', 'tag_ids', array( '42', true, (object) array(), array( '42' ), array( 'x' => 42 ) ) ),
			array( 'list-posts', 'per_page', array( 0, 101, '10', 1.5 ) ),
			array( 'bulk-publish-posts', 'ids', array( array(), array_fill( 0, 101, 42 ) ) ),
		) as [ $slug, $field, $values ] ) {
			$args = $this->definition( $slug );
			foreach ( $values as $value ) {
				$input = array_replace( $this->input( $args['input_schema'] ), array( $field => $value ) );
				$reason = 'force' === $field ? 'invalid_input'
					: ( 'ids' === $field ? ( count( $value ) > 100 ? 'too_many_ids' : 'invalid_input' ) : 'ability_invalid_input' );
				$this->rejected( $args['execute_callback']( $input ), false, $reason );
				$this->rejected( $args['permission_callback']( $input ), true );
			}
		}
	}

	public function test_open_maps_and_polymorphic_values_are_not_closed_or_rewritten(): void {
		$meta = $this->definition( 'update-post-meta' )['input_schema']['properties']['meta_value'];
		self::assertArrayNotHasKey( 'additionalProperties', $meta );
		foreach ( array( array( 'extension' => array( 'anything' => true ) ), (object) array( 'custom' => 'value' ), array( 1, 'x' ), false ) as $value ) {
			self::assertNull( Webmastery_MCP_Input::validate( $value, $meta ) );
		}
		$taxonomy = $this->definition( 'create-cpt-mcp-book' )['input_schema']['properties']['taxonomy_terms'];
		self::assertIsArray( $taxonomy['additionalProperties'] );
		self::assertNull( Webmastery_MCP_Input::validate( array( 'registered_extension_taxonomy' => array( 42 ) ), $taxonomy ) );
		self::assertInstanceOf( WP_Error::class, Webmastery_MCP_Input::validate( array( 'taxonomy' => array( '42' ) ), $taxonomy ) );
		$open = array( 'type' => 'object', 'properties' => array( 'known' => array( 'type' => 'string' ) ), 'additionalProperties' => true );
		self::assertSame( $open, Webmastery_MCP_Input::close_schema( $open ) );
		$nested = Webmastery_MCP_Input::close_schema( array( 'type' => 'object', 'properties' => array(
			'fixed' => array( 'type' => 'object', 'properties' => array() ),
			'attributes' => array( 'type' => 'object' ),
		) ) );
		self::assertFalse( $nested['properties']['fixed']['additionalProperties'] );
		self::assertArrayNotHasKey( 'additionalProperties', $nested['properties']['attributes'] );
	}

	public function test_valid_permissions_default_omission_and_plain_writes_are_preserved(): void {
		$args = $this->definition( 'list-posts' );
		self::assertTrue( $args['permission_callback']( array() ) );
		Probe::$allowed = false;
		$error = $args['permission_callback']( array() );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'forbidden', Webmastery_MCP_Response::from_wp_error( $error )['error']['code'] );
		Probe::$allowed = true;
		foreach ( array( 'create-post', 'create-page', 'create-cpt-mcp-book', 'create-cpt-mcp-case-study' ) as $slug ) {
			$args = $this->definition( $slug );
			try {
				$args['execute_callback']( array( 'title' => 'Plain', 'content' => 'Plain', 'status' => 'draft' ) );
				self::fail( 'Valid creation did not reach write boundary.' );
			} catch ( BoundaryReached $error ) {
				self::assertSame( 'write:insert', $error->getMessage() );
			}
		}
	}

	public function test_default_queries_have_live_counters_and_unwrapped_enum_mutant_is_caught(): void {
		foreach ( array( 'list-posts', 'list-pages', 'list-cpt-mcp-book', 'list-media', 'list-comments', 'list-users' ) as $slug ) {
			foreach ( array( true, false ) as $wrapped ) {
				Probe::$events = array();
				$args = ( $wrapped ? Probe::$abilities : Probe::$originals )[ 'webmastery-site-toolkit-for-mcp/' . $slug ];
				try {
					$args['execute_callback']( $wrapped ? array() : array( 'status' => 'publish!', 'order' => 'DESC!' ) );
					self::fail( 'Query counter control was not reached.' );
				} catch ( BoundaryReached $error ) {
					self::assertStringStartsWith( 'query:', $error->getMessage() );
					self::assertNotEmpty( Probe::$events );
				}
			}
		}
	}

	public function test_no_input_callbacks_keep_omission_and_null_but_reject_unknown_properties(): void {
		$calls = 0;
		$args = Webmastery_MCP_Input::register_args( array(
			'execute_callback' => static function () use ( &$calls ) { $calls++; return array( 'provider' => 'unchanged' ); },
			'permission_callback' => static fn() => true,
		), 'webmastery-site-toolkit-for-mcp/test' );
		self::assertSame( array(), $args['input_schema']['default'] );
		foreach ( array( null, array(), (object) array() ) as $input ) {
			self::assertSame( array( 'provider' => 'unchanged' ), $args['execute_callback']( $input ) );
		}
		self::assertSame( array( 'provider' => 'unchanged' ), $args['execute_callback']() );
		self::assertSame( 4, $calls );
		$this->rejected( $args['permission_callback']( array( 'unused' => 1 ) ), true );
	}

	public function test_invalid_callbacks_foreign_namespaces_and_callback_exceptions_are_untouched(): void {
		foreach ( array( 'foreign/test', 'webmastery-site-toolkit-for-mcp-lookalike/test' ) as $name ) {
			$args = array( 'execute_callback' => 'not_callable', 'permission_callback' => null );
			self::assertSame( $args, Webmastery_MCP_Input::register_args( $args, $name ) );
		}
		$args = Webmastery_MCP_Input::register_args( array( 'execute_callback' => 'not_callable', 'permission_callback' => null ), 'webmastery-site-toolkit-for-mcp/test' );
		self::assertSame( 'not_callable', $args['execute_callback'] );
		self::assertNull( $args['permission_callback'] );
		$args = Webmastery_MCP_Input::register_args( array( 'execute_callback' => static function () { throw new LogicException( 'sentinel' ); } ), 'webmastery-site-toolkit-for-mcp/test' );
		$this->expectException( LogicException::class );
		$args['execute_callback']();
	}
}
