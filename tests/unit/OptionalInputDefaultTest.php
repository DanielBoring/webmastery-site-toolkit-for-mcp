<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm126Boundary\Probe;

require_once __DIR__ . '/fixtures/input-boundary-stubs.php';

/**
 * Guards the "no input" bug class statically for every registered ability.
 *
 * WordPress validates the input against the schema before the permission check, so an
 * all-optional object schema without a root default rejects an omitted or null input as
 * invalid_input instead of running with defaults (and a denied role sees invalid_input,
 * not forbidden). Unit tests that call callbacks directly cannot see this; Docker E2E did.
 * The registry fixture evaluates every includes/class-*.php with register() and records
 * each wp_register_ability() call.
 */
final class OptionalInputDefaultTest extends TestCase {
	/**
	 * Released abilities where an omitted input is deliberately invalid. Do not add new
	 * abilities here: declare a root default or a required list instead.
	 */
	private const EXEMPT = array(
		// The E2E case "list-post-types without read invalid input rejected first" pins null input as invalid_input.
		'webmastery-site-toolkit-for-mcp/list-post-types',
		// Each needs plugin or slug (checked by the callback); there is no meaningful default.
		'webmastery-site-toolkit-for-mcp/activate-plugin',
		'webmastery-site-toolkit-for-mcp/deactivate-plugin',
		// Needs post_id (url alone returns unsupported); there is no meaningful default.
		'webmastery-site-toolkit-for-mcp/get-yoast-metadata',
	);

	protected function setUp(): void {
		Probe::reset();
	}

	/**
	 * Registration args after the plugin's own wp_register_ability_args filters, in the
	 * priority order the main plugin file adds them (Response at 10, Input at 20).
	 */
	private static function filtered( string $name, array $args ): array {
		return Webmastery_MCP_Input::register_args( Webmastery_MCP_Response::register_args( $args, $name ), $name );
	}

	/** @return array<int, string> Names of abilities whose null/omitted input would be rejected. */
	private static function violations( array $registrations ): array {
		$violations = array();
		foreach ( $registrations as $name => $args ) {
			if ( empty( $args['input_schema'] ) ) {
				// (a) No input schema: the input layer supplies an empty closed object with a [] default.
				continue;
			}
			$schema = self::filtered( $name, $args )['input_schema'];
			if ( 'object' !== ( $schema['type'] ?? null ) ) {
				continue;
			}
			// (b) A required input means an omitted input is genuinely invalid.
			// (c) An explicit root default is what the wrappers materialize for null input.
			if ( array() === ( $schema['required'] ?? array() ) && ! array_key_exists( 'default', $schema ) ) {
				$violations[] = $name;
			}
		}
		return $violations;
	}

	public function test_every_all_optional_object_input_declares_a_root_default(): void {
		self::assertCount( 94, Probe::$originals, 'Every registered ability is checked.' );
		foreach ( Probe::$originals as $name => $args ) {
			$schema = self::filtered( $name, $args )['input_schema'];
			self::assertSame( 'object', $schema['type'] ?? null, "{$name} must have an object input root." );
			if ( array_key_exists( 'default', $schema ) ) {
				self::assertNull( Webmastery_MCP_Input::validate( $schema['default'], $schema ), "{$name} root default must satisfy its own schema." );
			}
		}
		$found      = self::violations( Probe::$originals );
		$violations = array_values( array_diff( $found, self::EXEMPT ) );
		self::assertSame( array(), array_values( array_diff( self::EXEMPT, $found ) ), 'Remove exemptions that no longer apply.' );
		self::assertSame(
			array(),
			$violations,
			'These abilities have only optional inputs but no root default, so an omitted or null input fails as invalid_input before the permission check: '
			. implode( ', ', $violations ) . ". Add 'default' => [] to the root of their input_schema."
		);
	}

	public function test_the_guard_flags_a_missing_root_default_and_accepts_each_valid_shape(): void {
		$name     = 'webmastery-site-toolkit-for-mcp/example';
		$optional = array( 'type' => 'object', 'properties' => array( 'page' => array( 'type' => 'integer' ) ) );
		$args     = static fn( $schema ) => array( $name => array( 'input_schema' => $schema, 'execute_callback' => '__return_true', 'permission_callback' => '__return_true' ) );

		self::assertSame( array( $name ), self::violations( $args( $optional ) ) );
		self::assertSame( array( $name ), self::violations( $args( $optional + array( 'required' => array() ) ) ) );
		self::assertSame( array( $name ), self::violations( $args( array( 'type' => 'object', 'properties' => array() ) ) ) );
		self::assertSame( array(), self::violations( $args( $optional + array( 'default' => array() ) ) ) );
		self::assertSame( array(), self::violations( $args( $optional + array( 'required' => array( 'page' ) ) ) ) );
		self::assertSame( array(), self::violations( array( $name => array( 'execute_callback' => '__return_true', 'permission_callback' => '__return_true' ) ) ) );
	}
}
