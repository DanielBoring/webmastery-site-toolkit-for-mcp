<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/metadata-batch-fixture.php';

final class MetadataMigrationTest extends TestCase {
	private function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public static function original_empty_object_inputs(): array {
		return array(
			array( 'wstm110 update-post rejects empty metadata presence', 'meta', '{"post_id":"__post_id__","title":"Must not persist","meta":{}}' ),
			array( 'wstm110 create-cpt-mcp-case-study rejects metadata input', 'meta_input', '{"title":"Must not exist","content":"Must not persist","meta_input":{}}' ),
		);
	}

	/** @dataProvider original_empty_object_inputs */
	public function test_original_empty_metadata_inputs_preserve_json_container_identity( string $label, string $field, string $original_json ): void {
		$manifest = json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), false, 512, JSON_THROW_ON_ERROR );
		$matches = array_values( array_filter( $manifest, static fn( $case ) => $label === $case->label ) );
		self::assertCount( 1, $matches );
		$case = $matches[0];
		self::assertInstanceOf( stdClass::class, $case->input->$field );
		self::assertSame( array(), get_object_vars( $case->input->$field ) );
		self::assertSame( $original_json, json_encode( $case->input, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'failure', $case->expect );
		self::assertSame( 'invalid_input', $case->expect_error_code );
		self::assertSame( 'ability_invalid_input', $case->expect_error_reason );
		self::assertSame( 'canonical', $case->expect_error_shape );
		self::assertTrue( $case->assert_metadata_boundary );

		$mutant = clone $case->input;
		$mutant->$field = array();
		self::assertNotSame( $original_json, json_encode( $mutant, JSON_THROW_ON_ERROR ), 'An empty-array mutation must not compare equal to the original object input.' );
	}

	public function test_all_removed_aliases_have_an_exact_documented_migration(): void {
		$guide = file_get_contents( dirname( __DIR__, 2 ) . '/docs/3.0-migration.md' );
		foreach ( wstm110_batch_aliases() as $alias => [ $key ] ) {
			self::assertStringContainsString( "| `{$alias}` | `{$key}` |", $guide );
		}
	}

	public function test_original_successes_keep_individual_value_and_storage_assertions(): void {
		$plain = $writes = $captures = 0;
		$fixtures = array();
		foreach ( $this->manifest() as $case ) {
			if ( isset( $case['capture_post_id'] ) ) {
				self::assertArrayNotHasKey( $case['capture_post_id'], $fixtures );
				$fixtures[ $case['capture_post_id'] ] = true;
				self::assertSame( 'draft', $case['input']['status'] );
				++$captures;
			}
			if ( str_ends_with( $case['label'], ' via plain request then separate metadata calls' ) ) {
				++$plain;
				self::assertSame( array(), preg_grep( '/^(meta$|yoast_|seopress_)/', array_keys( $case['input'] ) ) );
			}
			if ( 'webmastery-site-toolkit-for-mcp/update-post-meta' === $case['ability'] && str_contains( $case['label'], ' standalone _' ) ) {
				++$writes;
				self::assertSame( 'success', $case['expect'] );
				$stored = $case['assert_post_meta'][0];
				self::assertSame( $case['input']['post_id'], $stored['post_id'] );
				self::assertSame( $case['input']['meta_key'], $stored['meta_key'] );
				self::assertSame( $case['assert_values']['data.current_value'], $stored['value'] );
				if ( preg_match( '/^__(wstm110_created_\d+)__$/', $stored['post_id'], $match ) ) {
					self::assertArrayHasKey( $match[1], $fixtures, 'Create must succeed before metadata is written.' );
				}
			}
		}
		self::assertSame( 10, $plain );
		self::assertSame( 5, $captures );
		self::assertSame( 54, $writes );
	}

	public function test_combined_rejections_require_snapshot_and_hook_proof_without_masking_contributor_denials(): void {
		$combined = $contributors = 0;
		foreach ( $this->manifest() as $case ) {
			if ( str_ends_with( $case['label'], ' rejects original combined payload' ) ) {
				++$combined;
				self::assertTrue( $case['assert_metadata_boundary'] );
				self::assertTrue( $case['assert_unchanged'] );
				self::assertSame( 'ability_invalid_input', $case['expect_error_reason'] );
				self::assertSame( 'invalid_input', $case['expect_error_code'] );
			}
			if ( preg_match( '/^wstm120 contributor cannot transition draft to (publish|private|future)$/', $case['label'] ) ) {
				++$contributors;
				self::assertTrue( $case['assert_permission'] );
				self::assertSame( 'forbidden', $case['expect_error_reason'] );
				self::assertSame( 'You do not have permission to publish this post.', $case['assert_values']['error.message'] );
				self::assertTrue( $case['assert_unchanged'] );
				self::assertArrayNotHasKey( 'yoast_meta_description', $case['input'] );
			}
		}
		self::assertSame( 15, $combined );
		self::assertSame( 3, $contributors );
	}

	public static function unsafe_runtime_invocations(): array {
		$cases = array();
		foreach ( array( 'metadata-batch-runner.php', 'seo-metadata-runner.php' ) as $runner ) {
			foreach ( array( null, '', '0', 'true', ' 1', '1 ' ) as $value ) {
				$cases[] = array( $runner, $value );
			}
		}
		return $cases;
	}

	public function test_unrelated_draft_keeps_combined_payload_and_separate_object_authorization(): void {
		$cases = array_column( $this->manifest(), null, 'label' );
		$combined = $cases['wstm120 contributor cannot update unrelated draft'];
		self::assertSame( 'Must not change', $combined['input']['yoast_meta_description'] );
		self::assertSame( 'invalid_input', $combined['assert_permission'] );
		self::assertSame( 'ability_invalid_input', $combined['expect_error_reason'] );
		self::assertTrue( $combined['assert_unchanged'] );
		self::assertTrue( $combined['assert_metadata_boundary'] );
		$plain = $cases['wstm120 contributor cannot update unrelated draft with plain input'];
		$input = $combined['input'];
		unset( $input['yoast_meta_description'] );
		self::assertSame( $input, $plain['input'] );
		self::assertSame( 'contributor', $plain['role'] );
		self::assertSame( 'forbidden', $plain['assert_permission'] );
		self::assertSame( 'forbidden', $plain['expect_error_code'] );
		self::assertSame( 'ability_invalid_permissions', $plain['expect_error_reason'] );
		self::assertTrue( $plain['assert_unchanged'] );
		self::assertSame( array(
			array( 'capability' => 'edit_posts', 'args' => array(), 'allowed' => true ),
			array( 'capability' => 'edit_post', 'args' => array( '__wstm120_other_draft_id__' ), 'allowed' => false ),
		), $plain['assert_capabilities'] );
		self::assertSame( $combined['assert_capabilities'], $plain['assert_capabilities'] );
	}

	public function test_subscriber_plain_denials_preserve_exact_metadata_sentinels(): void {
		$cases = array_column( $this->manifest(), null, 'label' );
		foreach ( array( 'post' => '\\\\server\\share\\', 'page' => '{"regex":"\\\\d+\\w"}' ) as $type => $sentinel ) {
			$combined = $cases[ "wstm122 denied {$type} write preserves metadata" ];
			$plain = $cases[ "wstm122 denied {$type} write with plain input preserves metadata" ];
			$id = "__{$type}_id__";
			self::assertSame( array( "{$type}_id" => $id, 'yoast_meta_description' => 'Denied\\meta' ), $combined['input'] );
			self::assertSame( array( "{$type}_id" => $id, 'title' => 'Must not change' ), $plain['input'] );
			self::assertSame( 'subscriber', $plain['role'] );
			self::assertSame( 'forbidden', $plain['assert_permission'] );
			self::assertSame( 'forbidden', $plain['expect_error_code'] );
			self::assertSame( 'ability_invalid_permissions', $plain['expect_error_reason'] );
			self::assertTrue( $plain['assert_unchanged'] );
			self::assertTrue( $combined['assert_unchanged'] );
			self::assertTrue( $combined['assert_metadata_boundary'] );
			self::assertSame( array( array( 'capability' => 'edit_post', 'args' => array( $id ), 'allowed' => false ) ), $plain['assert_capabilities'] );
			self::assertSame( array( array( 'post_id' => $id, 'meta_key' => '_yoast_wpseo_metadesc', 'value' => $sentinel ) ), $plain['assert_post_meta'] );
			self::assertSame( $combined['assert_post_meta'], $plain['assert_post_meta'] );
		}
	}

	/**
	 * @dataProvider unsafe_runtime_invocations
	 */
	public function test_runtime_requires_exact_disposable_opt_in_before_bootstrap( string $runner, ?string $value ): void {
		$path = dirname( __DIR__ ) . '/e2e/' . $runner;
		$setting = 'WSTM110_BATCH_DISPOSABLE' . ( null === $value ? '' : '=' . $value );
		$code = 'putenv(' . var_export( $setting, true ) . '); require ' . var_export( $path, true ) . ';';
		$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( 'WSTM110_BATCH_DISPOSABLE=1', $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
	}
}
