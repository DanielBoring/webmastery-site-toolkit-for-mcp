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

	/**
	 * @dataProvider metadata_write_values
	 */
	public function test_apply_meta_writes_slashes_at_persistence_and_reads_stored_value( string $value ): void {
		$GLOBALS['wstm_test_meta_writes'] = array();
		$GLOBALS['wstm_test_stored_meta'] = array( 42 => array( 'example' => 'provider-sanitized value' ) );

		try {
			$normalized = self::call_private( 'normalize_meta_value', array( $value ) );
			$this->assertSame( $value, $normalized );
			$result = self::call_private( 'apply_meta_writes', array( 42, array( 'example' => $normalized ) ) );

			$this->assertSame( array( array( 42, 'example', addslashes( $value ) ) ), $GLOBALS['wstm_test_meta_writes'] );
			$this->assertSame( array( 'example' => 'provider-sanitized value' ), $result );
		} finally {
			unset( $GLOBALS['wstm_test_meta_writes'], $GLOBALS['wstm_test_stored_meta'] );
		}
	}

	public static function metadata_write_values(): array {
		return array(
			'plain'    => array( 'ordinary text' ),
			'path'     => array( 'C:\path\file' ),
			'repeated' => array( '\\\\server\\share' ),
			'trailing' => array( 'C:\\path\\' ),
			'quotes'   => array( 'A \"quoted\" value' ),
			'json'     => array( '{"pattern":"\\\\d+\\w"}' ),
		);
	}

	public function test_error_response_shape_is_stable(): void {
		$response = Webmastery_MCP_Response::error(
			'forbidden',
			'Nope.',
			array( 'capability' => 'edit_post' )
		);

		$this->assertEquals(
			array(
				'success' => false,
				'error'   => array(
					'code'    => 'forbidden',
					'reason'  => 'forbidden',
					'message' => 'Nope.',
					'details' => (object) array( 'capability' => 'edit_post' ),
				),
			),
			$response
		);
	}

	public function test_exact_patch_matches_raw_markup_without_touching_surrounding_bytes(): void {
		$needle = '<iframe src="https://example.org/embed"></iframe>';
		$before = '<script>const path = "C:\\\\site";</script>';
		$after  = '<p onclick="void(0)">Keep "quotes" and apostrophe\'s.</p>';
		$result = self::call_private( 'patch_content_by_exact_match', array( $before . $needle . $after, $needle, '<p>Replacement</p>' ) );

		$this->assertSame( $before . '<p>Replacement</p>' . $after, $result['content'] );
		$this->assertSame( array( 'type' => 'exact' ), $result['target'] );
		$this->assertNull( $result['replaced_blocks'] );
	}

	public function test_exact_patch_rejects_duplicate_raw_markup(): void {
		$needle = '<form><input name="email"></form>';
		$result = self::call_private( 'patch_content_by_exact_match', array( $needle . $needle, $needle, '<p>Replacement</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ambiguous_target', $result->get_error_code() );
	}

	public function test_exact_patch_does_not_match_sanitized_lookalike(): void {
		$result = self::call_private( 'patch_content_by_exact_match', array( '<p>Keep</p>', '<p onclick="void(0)">Keep</p>', '<p>Replacement</p>' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'target_not_found', $result->get_error_code() );
	}
}
