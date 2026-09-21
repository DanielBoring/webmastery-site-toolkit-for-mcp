<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm126Boundary\Probe;

require_once __DIR__ . '/fixtures/input-boundary-stubs.php';
require_once dirname( __DIR__ ) . '/e2e/metadata-batch-fixture.php';

final class BoundedIntegrationTest extends TestCase {
	private function manifest(): array {
		return array_column( json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR ), null, 'label' );
	}

	private function resolved( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'resolved' ), $value );
		}
		return is_string( $value ) && preg_match( '/^__.+__$/', $value ) ? 42 : $value;
	}

	public static function corrected_cases(): array {
		$cases = array();
		foreach ( array( 'publish', 'private', 'future' ) as $status ) {
			$cases[] = array( "wstm120 contributor cannot transition draft to {$status} rejects original combined payload", true );
		}
		$cases[] = array( 'wstm120 contributor cannot update unrelated draft', true );
		$cases[] = array( 'wstm122 denied post write preserves metadata', false );
		$cases[] = array( 'wstm122 denied page write preserves metadata', false );
		return $cases;
	}

	/** @dataProvider corrected_cases */
	public function test_combined_oracles_match_current_registered_schema_and_raw_permission( string $label, bool $has_permission_assertion ): void {
		Probe::reset();
		$case = $this->manifest()[ $label ];
		$args = Probe::$abilities[ $case['ability'] ];
		$input = $this->resolved( $case['input'] );
		self::assertFalse( $args['input_schema']['additionalProperties'] );
		self::assertArrayNotHasKey( 'yoast_meta_description', $args['input_schema']['properties'] );
		$plain = $input;
		unset( $plain['yoast_meta_description'] );
		self::assertNull( Webmastery_MCP_Input::validate( $plain, $args['input_schema'] ), 'The combined alias, not an unrelated malformed field, must cause native rejection.' );
		$native_validation = Webmastery_MCP_Response::from_wp_error( Webmastery_MCP_Input::validate( $input, $args['input_schema'] ) );
		self::assertSame( $native_validation['error']['code'], $case['expect_error_code'] );
		self::assertSame( $native_validation['error']['reason'], $case['expect_error_reason'] );
		foreach ( array( true, false ) as $allowed ) {
			Probe::$allowed = $allowed;
			$permission = $args['permission_callback']( $input );
			self::assertInstanceOf( WP_Error::class, $permission );
			self::assertSame( 'invalid_input', $permission->get_error_code() );
			self::assertSame( 'metadata_requires_separate_call', Webmastery_MCP_Response::from_wp_error( $permission )['error']['reason'] );
			if ( $has_permission_assertion ) {
				self::assertSame( $permission->get_error_code(), $case['assert_permission'] );
			}
			self::assertSame( array(), Probe::$events );
		}
		self::assertTrue( $case['assert_unchanged'] );
		self::assertTrue( $case['assert_metadata_boundary'] );
		self::assertContains( 'data', $case['assert_missing_paths'] );
	}

	public function test_all_batch_variants_reject_before_original_callbacks_and_keep_aliases_unadvertised(): void {
		Probe::reset();
		$checked = 0;
		foreach ( array( 'post', 'page', 'cpt-mcp-book', 'cpt-mcp-case-study' ) as $suffix ) {
			foreach ( array( 'create', 'update' ) as $operation ) {
				$name = "webmastery-site-toolkit-for-mcp/{$operation}-{$suffix}";
				$original = Probe::$originals[ $name ];
				$called = 0;
				foreach ( array( 'permission_callback', 'execute_callback' ) as $key ) {
					$callback = $original[ $key ];
					$original[ $key ] = static function ( $input ) use ( &$called, $callback ) {
						++$called;
						return $callback( $input );
					};
				}
				$args = Webmastery_MCP_Input::register_args( $original, $name );
				$input = array( 'title' => 'Valid title', 'content' => 'Valid content', 'status' => 'draft' );
				if ( 'update' === $operation ) {
					$input[ in_array( $suffix, array( 'post', 'page' ), true ) ? $suffix . '_id' : 'id' ] = 42;
				}
				self::assertNull( Webmastery_MCP_Input::validate( $input, $args['input_schema'] ) );
				foreach ( array_merge( array( 'meta', 'meta_input' ), array_keys( wstm110_batch_aliases() ) ) as $field ) {
					self::assertArrayNotHasKey( $field, $args['input_schema']['properties'] );
				}
				foreach ( wstm110_batch_payloads() as $payload ) {
					$combined = $input + $payload;
					$error = Webmastery_MCP_Input::validate( $combined, $args['input_schema'] );
					self::assertInstanceOf( WP_Error::class, $error );
					self::assertSame( 'ability_invalid_input', Webmastery_MCP_Response::from_wp_error( $error )['error']['reason'] );
					$permission = $args['permission_callback']( $combined );
					self::assertSame( 'invalid_input', $permission->get_error_code() );
					self::assertSame( 'metadata_requires_separate_call', Webmastery_MCP_Response::from_wp_error( $permission )['error']['reason'] );
					self::assertSame( 'metadata_requires_separate_call', $args['execute_callback']( $combined )['error']['reason'] );
					self::assertSame( 0, $called );
					self::assertSame( array(), Probe::$events );
					++$checked;
				}
			}
		}
		self::assertSame( 1176, $checked );
	}

	public function test_plain_controls_preserve_permission_and_stored_metadata_purposes(): void {
		Probe::reset();
		$cases = $this->manifest();
		foreach ( array( 'publish', 'private', 'future' ) as $status ) {
			$case = $cases[ "wstm120 contributor cannot transition draft to {$status}" ];
			self::assertTrue( $case['assert_permission'] );
			self::assertSame( 'forbidden', $case['expect_error_reason'] );
			self::assertSame( 'You do not have permission to publish this post.', $case['assert_values']['error.message'] );
			self::assertTrue( $case['assert_unchanged'] );
			self::assertArrayNotHasKey( 'yoast_meta_description', $case['input'] );
		}
		foreach ( array(
			'wstm120 contributor cannot update unrelated draft' => 'wstm120 contributor cannot update unrelated draft with plain input',
			'wstm122 denied post write preserves metadata' => 'wstm122 denied post write with plain input preserves metadata',
			'wstm122 denied page write preserves metadata' => 'wstm122 denied page write with plain input preserves metadata',
		) as $combined_label => $plain_label ) {
			self::assertArrayHasKey( $plain_label, $cases );
			$combined = $cases[ $combined_label ];
			$plain = $cases[ $plain_label ];
			$args = Probe::$abilities[ $plain['ability'] ];
			self::assertNull( Webmastery_MCP_Input::validate( $this->resolved( $plain['input'] ), $args['input_schema'] ) );
			self::assertSame( $combined['role'], $plain['role'] );
			self::assertSame( $combined['ability'], $plain['ability'] );
			self::assertArrayNotHasKey( 'yoast_meta_description', $plain['input'] );
			self::assertSame( 'forbidden', $plain['assert_permission'] );
			self::assertSame( 'forbidden', $plain['expect_error_code'] );
			self::assertSame( 'ability_invalid_permissions', $plain['expect_error_reason'] );
			self::assertTrue( $plain['assert_unchanged'] );
			self::assertNotEmpty( $plain['assert_capabilities'] );
			$id_key = 'webmastery-site-toolkit-for-mcp/update-page' === $plain['ability'] ? 'page_id' : 'post_id';
			self::assertSame( $combined['input'][ $id_key ], $plain['input'][ $id_key ] );
			self::assertContains( array( 'capability' => 'edit_post', 'args' => array( $plain['input'][ $id_key ] ), 'allowed' => false ), $plain['assert_capabilities'] );
			if ( isset( $combined['assert_post_meta'] ) ) {
				self::assertSame( $combined['assert_post_meta'], $plain['assert_post_meta'] );
				self::assertSame( 'Must not change', $plain['input']['title'] );
			} else {
				self::assertSame( $combined['assert_capabilities'], $plain['assert_capabilities'] );
				$expected = $combined['input'];
				unset( $expected['yoast_meta_description'] );
				self::assertSame( $expected, $plain['input'] );
			}
		}
		Probe::$allowed = false;
		$args = Probe::$abilities['webmastery-site-toolkit-for-mcp/update-page'];
		$denied = $args['permission_callback']( array( 'page_id' => 42, 'title' => 'Must not change' ) );
		self::assertSame( 'forbidden', $denied->get_error_code() );
		self::assertSame( array( 'get_post', 'capability:edit_post' ), Probe::$events, 'Valid plain input must reach the existing object permission callback.' );
	}

	public function test_every_combined_metadata_manifest_case_uses_the_actual_native_schema_reason(): void {
		Probe::reset();
		$checked = 0;
		foreach ( $this->manifest() as $case ) {
			if ( ! preg_match( '~/(?:create|update)-(?:post|page|cpt-.+)$~', $case['ability'] ) ) {
				continue;
			}
			$input = $this->resolved( $case['input'] );
			if ( ! is_array( $input ) || null === Webmastery_MCP_Posts::reject_combined_metadata( $input ) ) {
				continue;
			}
			$args = Probe::$abilities[ $case['ability'] ];
			$error = Webmastery_MCP_Input::validate( $input, $args['input_schema'] );
			self::assertInstanceOf( WP_Error::class, $error );
			$envelope = Webmastery_MCP_Response::from_wp_error( $error );
			self::assertSame( 'failure', $case['expect'], $case['label'] );
			self::assertSame( $envelope['error']['code'], $case['expect_error_code'], $case['label'] );
			self::assertSame( $envelope['error']['reason'], $case['expect_error_reason'], $case['label'] );
			if ( isset( $case['assert_permission'] ) ) {
				self::assertSame( 'invalid_input', $case['assert_permission'], $case['label'] );
			}
			++$checked;
		}
		self::assertSame( 26, $checked );
		self::assertSame( array(), Probe::$events );
	}
}
