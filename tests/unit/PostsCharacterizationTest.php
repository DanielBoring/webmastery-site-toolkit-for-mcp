<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm120\Webmastery_MCP_Posts as Posts;

require_once __DIR__ . '/fixtures/posts-helper-stubs.php';

final class PostsCharacterizationTest extends TestCase {
	private static function invoke( string $method, array $args ) {
		$reflection = new ReflectionMethod( Posts::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm120_blocks'], $GLOBALS['wstm120_serialized'] );
	}

	public static function block_paths(): array {
		return array(
			array( '0', array( 0 ) ),
			array( " \t2.01.0\n", array( 2, 1, 0 ) ),
			array( 12, array( 12 ) ),
			array( '', null ), array( ' ', null ), array( '1..2', null ),
			array( '.1', null ), array( '1.', null ), array( '-1', null ),
			array( '+1', null ), array( '1. 2', null ), array( 'a', null ),
			array( '1e2', null ), array( '1/2', null ),
		);
	}

	/** @dataProvider block_paths */
	public function test_block_path_grammar( $path, ?array $expected ): void {
		$result = self::invoke( 'parse_block_path', array( $path ) );
		if ( null === $expected ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_block_path', $result->get_error_code() );
		} else {
			$this->assertSame( $expected, $result );
		}
	}

	public function test_nested_replacement_mutates_only_target_by_reference(): void {
		$blocks = array(
			array( 'innerHTML' => 'prefix' ),
			array( 'innerHTML' => 'group', 'innerBlocks' => array(
				array( 'innerHTML' => 'sibling' ),
				array( 'innerHTML' => 'nested', 'innerBlocks' => array( array( 'innerHTML' => 'old' ) ) ),
			) ),
			array( 'innerHTML' => 'suffix' ),
		);
		$expected = $blocks;
		$replacement = array( 'innerHTML' => 'replacement', 'innerBlocks' => array() );
		$expected[1]['innerBlocks'][1]['innerBlocks'][0] = $replacement;
		$this->assertTrue( self::invoke( 'replace_block_by_segments', array( &$blocks, array( 1, 1, 0 ), $replacement ) ) );
		$this->assertSame( $expected, $blocks );
		foreach ( array( array( 9 ), array( 0, 0 ), array( 1, 9 ), array( 1, 1, 9 ), array( 1, 1, 0, 0 ) ) as $path ) {
			$result = self::invoke( 'replace_block_by_segments', array( &$blocks, $path, array() ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'target_not_found', $result->get_error_code() );
			$this->assertSame( $expected, $blocks, 'Missing paths must not partially mutate ancestors.' );
		}
	}

	private static function block( string $html, ?int $level = null ): array {
		return array(
			'blockName' => null === $level ? 'core/paragraph' : 'core/heading',
			'attrs' => null === $level ? array() : array( 'level' => $level ),
			'innerHTML' => $html,
			'innerBlocks' => array(),
		);
	}

	public function test_heading_missing_and_ambiguous_targets(): void {
		$heading = self::block( '<h2>Target</h2>', 2 );
		foreach ( array( 'target_not_found' => array( self::block( '<p>Target</p>' ) ), 'ambiguous_target' => array( $heading, $heading ) ) as $code => $blocks ) {
			$GLOBALS['wstm120_blocks'] = array( 'source' => $blocks );
			$result = self::invoke( 'patch_content_by_heading', array( 'source', 'Target', 'replacement' ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertArrayNotHasKey( 'wstm120_serialized', $GLOBALS );
		}
	}

	public static function section_boundaries(): array {
		return array( 'same level' => array( 2 ), 'shallower' => array( 1 ), 'end of content' => array( null ) );
	}

	/** @dataProvider section_boundaries */
	public function test_heading_replaces_deeper_subsections_preserving_prefix_and_suffix( ?int $next_level ): void {
		$prefix = array( self::block( '<p>Untouched "prefix" \\\\</p>' ), self::block( '<h2>Target</h2>', 2 ) );
		$section = array( self::block( '<p>old</p>' ), self::block( '<h3>Deeper</h3>', 3 ), self::block( '<p>deep body</p>' ) );
		$suffix = null === $next_level ? array() : array( self::block( "<h{$next_level}>Next</h{$next_level}>", $next_level ), self::block( '<p>Untouched suffix</p>' ) );
		$replacement = array( self::block( '<p>New section</p>' ) );
		$GLOBALS['wstm120_blocks'] = array( 'source' => array_merge( $prefix, $section, $suffix ), 'replacement' => $replacement );
		$result = self::invoke( 'patch_content_by_heading', array( 'source', 'Target', 'replacement' ) );
		$expected = array_merge( $prefix, $replacement, $suffix );
		$this->assertSame( $expected, $GLOBALS['wstm120_serialized'] );
		$this->assertSame( implode( '', array_column( $expected, 'innerHTML' ) ), $result['content'] );
		$this->assertSame( 3, $result['replaced_blocks'] );
		$this->assertSame( array( 'type' => 'heading', 'heading_text' => 'Target', 'heading_level' => 2 ), $result['target'] );
	}

	private static function nest( $value, int $levels ) {
		for ( $i = 0; $i < $levels; ++$i ) {
			$value = array( 'child' => $value );
		}
		return $value;
	}

	public function test_metadata_depth_counts_recursive_values_from_zero(): void {
		$ten = self::nest( 'leaf', 10 );
		$this->assertSame( $ten, self::invoke( 'normalize_post_meta_value', array( $ten ) ) );
		$error = self::invoke( 'normalize_post_meta_value', array( self::nest( 'leaf', 11 ) ) );
		$this->assertSame( 'invalid_meta_value', $error->get_error_code() );
		$this->assertSame( 'meta_value nesting is too deep.', $error->get_error_message() );
		// Null entries bypass recursion; an array at depth ten may contain null.
		$null_leaf = self::nest( null, 11 );
		$this->assertSame( $null_leaf, self::invoke( 'normalize_post_meta_value', array( $null_leaf ) ) );
		$error = self::invoke( 'normalize_post_meta_value', array( self::nest( null, 12 ) ) );
		$this->assertSame( 'invalid_meta_value', $error->get_error_code() );
		$this->assertSame( array( 'nested' => null ), self::invoke( 'normalize_post_meta_value', array( (object) array( 'Nested' => null ) ) ) );
		$this->assertSame( 'invalid_meta_value', self::invoke( 'normalize_post_meta_value', array( null ) )->get_error_code() );
	}

	public function test_metadata_limit_measures_encoded_json_bytes(): void {
		$accepted = str_repeat( '"', 49999 );
		$rejected = $accepted . 'x';
		$this->assertSame( 100000, strlen( json_encode( $accepted ) ) );
		$this->assertSame( 100001, strlen( json_encode( $rejected ) ) );
		$this->assertLessThan( 100000, strlen( $rejected ) );
		$this->assertSame( $accepted, self::invoke( 'validate_post_meta_value', array( $accepted ) ) );
		$error = self::invoke( 'validate_post_meta_value', array( $rejected ) );
		$this->assertSame( 'invalid_meta_value', $error->get_error_code() );
		$this->assertSame( 'meta_value must encode to 100000 bytes or fewer.', $error->get_error_message() );
	}

	public function test_metadata_encoding_failure_is_not_a_successful_empty_value(): void {
		foreach ( array( INF, NAN ) as $value ) {
			$error = self::invoke( 'validate_post_meta_value', array( array( 'number' => $value ) ) );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'invalid_meta_value', $error->get_error_code() );
			$this->assertSame( 'meta_value could not be encoded safely.', $error->get_error_message() );
		}
	}
}
