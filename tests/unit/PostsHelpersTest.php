<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostsHelpersTest extends TestCase {

	private static function call_private( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Webmastery_MCP_Posts::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	public function test_validate_post_meta_key_rejects_empty_key(): void {
		$result = self::call_private( 'validate_post_meta_key', array( '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_meta_key', $result->get_error_code() );
	}

	public function test_validate_post_meta_key_accepts_valid_key(): void {
		$result = self::call_private( 'validate_post_meta_key', array( 'Meta_Key-1:seo.title' ) );

		$this->assertSame( 'Meta_Key-1:seo.title', $result );
	}

	public function test_normalize_meta_value_rejects_structured_values_for_scalar_meta(): void {
		$result = self::call_private( 'normalize_meta_value', array( array( 'not' => 'scalar' ) ) );

		$this->assertNull( $result );
	}

	public function test_normalize_meta_value_handles_boolean_storage_formats(): void {
		$this->assertSame( '1', self::call_private( 'normalize_meta_value', array( true, 'boolean_string' ) ) );
		$this->assertSame( '0', self::call_private( 'normalize_meta_value', array( false, 'boolean_string' ) ) );
		$this->assertSame( 'yes', self::call_private( 'normalize_meta_value', array( true, 'seopress_boolean_string' ) ) );
		$this->assertSame( '', self::call_private( 'normalize_meta_value', array( false, 'seopress_boolean_string' ) ) );
	}

	public function test_error_response_shape_is_stable(): void {
		$response = self::call_private(
			'error_response',
			array(
				'forbidden',
				'Nope.',
				array( 'capability' => 'edit_post' ),
			)
		);

		$this->assertSame(
			array(
				'success' => false,
				'error'   => array(
					'code'    => 'forbidden',
					'message' => 'Nope.',
				),
				'data'    => array(
					'capability' => 'edit_post',
				),
			),
			$response
		);
	}
}
