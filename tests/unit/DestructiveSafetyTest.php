<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-media.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-content-hygiene.php';

function wp_trash_post( $id ) {
	$GLOBALS['wstm116_mutations'][] = array( 'trash', $id );
	$GLOBALS['wstm_test_posts'][ $id ]->post_status = 'trash';
	return $GLOBALS['wstm_test_posts'][ $id ];
}

function wp_update_post( $input, $error = false ) {
	$GLOBALS['wstm116_mutations'][] = array( 'publish', $input['ID'] );
	$GLOBALS['wstm_test_posts'][ $input['ID'] ]->post_status = $input['post_status'];
	return $input['ID'];
}

function wp_delete_attachment( $id, $force = false ) {
	$GLOBALS['wstm116_mutations'][] = array( 'delete', $id, $force );
	return $GLOBALS['wstm_test_posts'][ $id ];
}

function wp_get_attachment_url( $id ) {
	return 'https://example.test/uploads/' . $id . '.png';
}

final class Wstm116ReferenceDatabase {
	public string $postmeta = 'private_postmeta';
	public string $posts = 'private_posts';
	public string $last_error = '';
	public array $results = array( 0, 0, 0 );
	public array $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( $query, $args );
	}

	public function esc_like( $value ) {
		return addcslashes( $value, '_%\\' );
	}

	public function get_col( $query ) {
		$this->queries[] = $query;
		$result = array_shift( $this->results );
		$this->last_error = null === $result ? 'PRIVATE SQL DATABASE ERROR' : '';
		return null === $result ? null : ( $result ? array( '46' ) : array() );
	}

	public function get_row( $query, $format ) {
		$this->queries[] = $query;
		$row = array();
		foreach ( $query[1] as $index => $pattern ) {
			$result = array_shift( $this->results );
			if ( null === $result ) {
				$this->last_error = 'PRIVATE SQL DATABASE ERROR';
				return null;
			}
			$row[ 'ref_' . $index ] = $result;
		}
		$this->last_error = '';
		return $row;
	}
}

final class DestructiveSafetyTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm116_mutations'] = array();
		$GLOBALS['wstm_test_abilities'] = array();
		$GLOBALS['wstm_test_posts'] = array(
			42 => (object) array( 'ID' => 42, 'post_type' => 'post', 'post_status' => 'draft' ),
			43 => (object) array( 'ID' => 43, 'post_type' => 'post', 'post_status' => 'publish' ),
			44 => (object) array( 'ID' => 44, 'post_type' => 'page', 'post_status' => 'draft' ),
			45 => (object) array( 'ID' => 45, 'post_type' => 'post', 'post_status' => 'trash' ),
			46 => (object) array( 'ID' => 46, 'post_type' => 'attachment', 'post_status' => 'inherit', 'guid' => 'https://old.example.test/46.png' ),
		);
		$GLOBALS['wstm_test_user_caps'] = array( 'delete_posts', 'delete_post', 'edit_post', 'publish_posts', 'upload_files' );
		$GLOBALS['wpdb'] = new Wstm116ReferenceDatabase();
		Webmastery_MCP_Posts::register();
		Webmastery_MCP_Media::register();
		Webmastery_MCP_Taxonomy::register();
	}

	protected function tearDown(): void {
		foreach ( array( 'wstm116_mutations', 'wstm_test_abilities', 'wstm_test_posts', 'wstm_test_user_caps', 'wstm_test_object_capability', 'wpdb' ) as $key ) {
			unset( $GLOBALS[ $key ] );
		}
	}

	private function definition( string $slug ): array {
		return $GLOBALS['wstm_test_abilities'][ 'webmastery-site-toolkit-for-mcp/' . $slug ];
	}

	private function execute( string $slug, array $input ): array {
		$result = ( $this->definition( $slug )['execute_callback'] )( $input );
		return is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
	}

	public static function confirmation_inputs(): array {
		$cases = array();
		foreach ( array( 'delete-media', 'delete-category', 'delete-tag', 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
			foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => 'true' ), array( 'confirm' => 'false' ), array( 'confirm' => 1 ), array( 'confirm' => 0 ), array( 'confirm' => null ), array( 'confirm' => array( true ) ) ) as $index => $flags ) {
				$cases[ "{$slug}-{$index}" ] = array( $slug, $flags );
			}
		}
		return $cases;
	}

	/** @dataProvider confirmation_inputs */
	public function test_exact_confirmation_precedes_work( string $slug, array $flags ): void {
		$result = $this->execute( $slug, $flags + array( 'media_id' => 46, 'category_id' => 42, 'tag_id' => 42, 'ids' => array( 42 ), 'dry_run' => true ) );
		self::assertSame( 'precondition_failed', $result['error']['code'] );
		self::assertSame( 'missing_confirmation', $result['error']['reason'] );
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_registered_schemas_advertise_safety_interlocks(): void {
		foreach ( array( 'delete-media', 'delete-category', 'delete-tag', 'bulk-trash-posts', 'bulk-publish-posts' ) as $slug ) {
			$definition = $this->definition( $slug );
			self::assertContains( 'confirm', $definition['input_schema']['required'] );
			self::assertSame( 'boolean', $definition['input_schema']['properties']['confirm']['type'] );
			self::assertTrue( $definition['meta']['annotations']['destructive'] );
			if ( 0 === strpos( $slug, 'bulk-' ) ) {
				self::assertSame( 100, $definition['input_schema']['properties']['ids']['maxItems'] );
				self::assertSame( 'boolean', $definition['input_schema']['properties']['dry_run']['type'] );
			}
		}
	}

	public static function bulk_variants(): array {
		return array( array( 'bulk-trash-posts' ), array( 'bulk-publish-posts' ) );
	}

	/** @dataProvider bulk_variants */
	public function test_raw_limit_before_normalization_even_duplicate_ids( string $slug ): void {
		foreach ( array( array_fill( 0, 101, 42 ), range( 1, 101 ) ) as $ids ) {
			foreach ( array( false, true ) as $dry_run ) {
				$result = $this->execute( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => $dry_run ) );
				self::assertSame( 'invalid_input', $result['error']['code'] );
				self::assertSame( 'too_many_ids', $result['error']['reason'] );
				self::assertEquals( (object) array( 'limit' => 100 ), $result['error']['details'] );
			}
		}
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
		foreach ( array( range( 1, 100 ), array_fill( 0, 100, 42 ) ) as $ids ) {
			$result = $this->execute( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => true ) );
			self::assertTrue( $result['success'] );
		}
	}

	/** @dataProvider bulk_variants */
	public function test_dry_run_matches_real_mixed_and_all_failed_batches( string $slug ): void {
		foreach ( array( array( 44, 999 ), array( 42, 42, 43, 44, 45, 999 ) ) as $ids ) {
			$before = serialize( $GLOBALS['wstm_test_posts'] );
			$preview = $this->execute( $slug, array( 'ids' => $ids, 'confirm' => true, 'dry_run' => true ) );
			self::assertTrue( $preview['success'] );
			self::assertTrue( $preview['data']['dry_run'] );
			self::assertSame( $before, serialize( $GLOBALS['wstm_test_posts'] ) );
			self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
			foreach ( $preview['data']['failures'] as $failure ) {
				self::assertSame( array( 'id', 'code', 'reason', 'message', 'details' ), array_keys( $failure ) );
				self::assertIsObject( $failure['details'] );
			}
			unset( $preview['data']['dry_run'] );
			self::assertEquals( $preview, $this->execute( $slug, array( 'ids' => $ids, 'confirm' => true ) ) );
		}
	}

	/** @dataProvider bulk_variants */
	public function test_dry_run_never_bypasses_per_id_authorization( string $slug ): void {
		$GLOBALS['wstm_test_object_capability'] = static fn( $cap, ...$args ) => ! in_array( $cap, array( 'edit_post', 'delete_post' ), true );
		self::assertTrue( ( $this->definition( $slug )['permission_callback'] )() );
		$result = $this->execute( $slug, array( 'ids' => array( 42 ), 'confirm' => true, 'dry_run' => true ) );
		self::assertSame( 'forbidden', $result['data']['failures'][0]['code'] );
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
	}

	public static function optional_flags(): array {
		$cases = array();
		foreach ( array( 'bulk-trash-posts' => 'dry_run', 'bulk-publish-posts' => 'dry_run', 'delete-media' => 'force' ) as $slug => $flag ) {
			foreach ( array( 'true', 'false', 0, 1, null, array() ) as $value ) {
				$cases[] = array( $slug, $flag, $value );
			}
		}
		return $cases;
	}

	/** @dataProvider optional_flags */
	public function test_optional_flags_reject_non_booleans( string $slug, string $flag, $value ): void {
		$result = $this->execute( $slug, array( 'confirm' => true, 'ids' => array( 42 ), 'media_id' => 46, $flag => $value ) );
		self::assertSame( 'invalid_input', $result['error']['code'] );
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
	}

	public static function reference_results(): array {
		return array(
			'featured' => array( array( 1 ), true ),
			'content URL' => array( array( 0, 1, 0 ), true ),
			'content GUID' => array( array( 0, 0, 1 ), true ),
			'not referenced' => array( array( 0, 0, 0 ), false ),
		);
	}

	/** @dataProvider reference_results */
	public function test_known_references_and_truthful_forced_success( array $counts, bool $in_use ): void {
		foreach ( array( false, true ) as $force ) {
			$GLOBALS['wpdb']->results = $counts;
			$GLOBALS['wpdb']->queries = array();
			$GLOBALS['wstm116_mutations'] = array();
			$result = $this->execute( 'delete-media', array( 'media_id' => 46, 'confirm' => true, 'force' => $force ) );
			self::assertCount( 1 === count( $counts ) ? 1 : 2, $GLOBALS['wpdb']->queries, 'Force must still use the same batched reference scan.' );
			self::assertSame( array( '_thumbnail_id', '46' ), $GLOBALS['wpdb']->queries[0][1] );
			if ( count( $counts ) > 1 ) {
				self::assertSame( array( '%https://example.test/uploads/46.png%', '%https://old.example.test/46.png%' ), $GLOBALS['wpdb']->queries[1][1] );
			}
			if ( $in_use && ! $force ) {
				self::assertSame( 'media_in_use', $result['error']['reason'] );
				self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
			} else {
				self::assertSame( array( 'success' => true, 'data' => array( 'id' => 46, 'deleted' => true, 'in_use' => $in_use ) ), $result );
				self::assertSame( array( array( 'delete', 46, true ) ), $GLOBALS['wstm116_mutations'] );
			}
		}
	}

	public function test_force_cannot_bypass_failed_scan_or_object_permission(): void {
		foreach ( array( array( null ), array( 0, null ), array( 0, 0, null ) ) as $counts ) {
			$GLOBALS['wpdb']->results = $counts;
			$result = $this->execute( 'delete-media', array( 'media_id' => 46, 'confirm' => true, 'force' => true ) );
			self::assertSame( 'upstream_failed', $result['error']['code'] );
			self::assertSame( 'content_hygiene_query_failed', $result['error']['reason'] );
			self::assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
		}
		$GLOBALS['wstm_test_user_caps'] = array( 'upload_files' );
		$input = array( 'media_id' => 46, 'confirm' => true, 'force' => true );
		self::assertInstanceOf( WP_Error::class, ( $this->definition( 'delete-media' )['permission_callback'] )( $input ) );
		self::assertSame( 'forbidden', $this->execute( 'delete-media', $input )['error']['code'] );
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
	}

	/** @dataProvider bulk_variants */
	public function test_non_array_empty_and_non_integer_ids_never_write( string $slug ): void {
		foreach ( array( null, '42', 42, array(), array( '42' ), array( true ), array( 42, null ) ) as $ids ) {
			$result = $this->execute( $slug, array( 'ids' => $ids, 'confirm' => true ) );
			self::assertSame( 'invalid_input', $result['error']['code'] );
		}
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_trash_remains_blocked_in_preview(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		$result = $this->execute( 'bulk-trash-posts', array( 'ids' => array( 42 ), 'confirm' => true, 'dry_run' => true ) );
		self::assertSame( 'trash_disabled', $result['data']['failures'][0]['reason'] );
		self::assertSame( array(), $GLOBALS['wstm116_mutations'] );
	}
}
