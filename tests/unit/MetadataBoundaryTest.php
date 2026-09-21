<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm110Boundary\MutationAttempt;
use Wstm110Boundary\Probe;
use Wstm110Boundary\UnauthorizedRead;

require_once dirname( __DIR__ ) . '/e2e/metadata-batch-fixture.php';
require_once __DIR__ . '/fixtures/metadata-boundary-stubs.php';

final class MetadataBoundaryTest extends TestCase {
	public static function targets(): array {
		$targets = array();
		foreach ( array( 'post', 'page', 'mcp_book', 'mcp_case_study' ) as $type ) {
			foreach ( array( 'create', 'update' ) as $operation ) {
				$slug = in_array( $type, array( 'post', 'page' ), true ) ? $type : 'cpt-' . str_replace( '_', '-', $type );
				$id_key = in_array( $type, array( 'post', 'page' ), true ) ? $type . '_id' : 'id';
				$targets[ "{$operation}-{$slug}" ] = array( $type, "{$operation}-{$slug}", $id_key );
			}
		}
		return $targets;
	}

	public static function rejected_inputs(): array {
		$cases = array();
		foreach ( self::targets() as $target => $definition ) {
			foreach ( wstm110_batch_payloads() as $label => $payload ) {
				$cases[ "{$target}: {$label}" ] = array_merge( $definition, array( $payload ) );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider rejected_inputs
	 */
	public function test_combined_input_is_rejected_before_any_mutation( string $type, string $slug, string $id_key, array $payload ): void {
		Probe::reset( $type );
		$input = array_merge( array(
			$id_key => 42, 'title' => 'Changed title', 'content' => 'Changed content', 'status' => 'publish',
		), $payload );
		$result = null;
		try {
			$result = Probe::execute( $slug, $input );
		} catch ( MutationAttempt $error ) {
			// Preserve the exact boundary as a useful failure, not an unhandled stub error.
		}
		self::assertSame( array(), Probe::$mutations, 'Metadata payload reached a WordPress mutation function.' );
		self::assertIsArray( $result );
		self::assertFalse( $result['success'] );
		self::assertSame( 'invalid_input', $result['error']['code'] );
		self::assertSame( 'metadata_requires_separate_call', $result['error']['reason'] );
		self::assertSame( array(), Probe::$reads );
		foreach ( Probe::$capabilities as $call ) {
			self::assertNotContains( $call[0], array( 'edit_post_meta', 'add_post_meta', 'delete_post_meta' ), 'Batch rejection must not preflight provider authorization.' );
		}
	}

	/**
	 * @dataProvider targets
	 */
	public function test_plain_requests_still_reach_persistence( string $type, string $slug, string $id_key ): void {
		Probe::reset( $type );
		try {
			Probe::execute( $slug, array( $id_key => 42, 'title' => 'Plain title', 'content' => 'Plain content', 'status' => 'draft' ) );
			self::fail( 'Plain requests must not be rejected by the metadata guard.' );
		} catch ( MutationAttempt $error ) {
			self::assertCount( 1, Probe::$mutations );
			self::assertStringEndsWith( str_starts_with( $slug, 'create-' ) ? 'wp_insert_post' : 'wp_update_post', Probe::$mutations[0][0] );
		}
	}

	public static function analysis_keys(): array {
		return array_map( static fn( $key ) => array( $key ), array(
			'_yoast_wpseo_metadesc', '_seopress_titles_desc', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw',
		) );
	}

	/**
	 * @dataProvider analysis_keys
	 */
	public function test_analysis_never_reads_a_denied_key( string $key ): void {
		Probe::reset();
		Probe::$denied_keys = array( $key );
		try {
			$result = Probe::execute( 'seo-analyze-post', array( 'post_id' => 42 ) );
		} catch ( UnauthorizedRead $error ) {
			self::fail( $error->getMessage() );
		}
		self::assertTrue( $result['success'] );
		self::assertNotContains( array( 42, $key ), Probe::$reads );
		self::assertContains( array( 'edit_post_meta', array( 42, $key ) ), Probe::$capabilities );
	}

	public function test_input_inventory_has_every_existing_alias_and_null_is_present(): void {
		self::assertCount( 34, wstm110_batch_aliases() );
		self::assertCount( 147, wstm110_batch_payloads() );
		self::assertCount( 8, self::targets() );
		foreach ( wstm110_batch_aliases() as $alias => $definition ) {
			$payload = wstm110_batch_payloads()[ "{$alias} null" ];
			self::assertArrayHasKey( $alias, $payload );
			self::assertNull( $payload[ $alias ] );
			self::assertStringStartsWith( str_starts_with( $alias, 'yoast_' ) ? '_yoast_wpseo_' : '_seopress_', $definition[0] );
		}
	}

	public function test_state_oracle_rejects_write_then_rollback_even_with_identical_snapshot(): void {
		$state = array( 'posts' => array( 'count' => 1, 'sha256' => 'same' ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'mutation hook' );
		wstm110_batch_assert_unchanged( $state, $state, array( 'wp_insert_post', 'deleted_post' ) );
	}

	public function test_state_oracle_rejects_persisted_changes_even_with_no_hook(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'persisted state' );
		wstm110_batch_assert_unchanged( array( 'posts' => 1 ), array( 'posts' => 2 ), array() );
	}

	public function test_state_oracle_accepts_only_unchanged_state_and_no_hooks(): void {
		wstm110_batch_assert_unchanged( array( 'posts' => 1 ), array( 'posts' => 1 ), array() );
		self::assertContains( 'before_delete_post', wstm110_batch_mutation_hooks() );
		self::assertContains( 'pre_insert_term', wstm110_batch_mutation_hooks() );
		self::assertContains( 'wp_insert_post_data', wstm110_batch_mutation_hooks() );
	}
}
