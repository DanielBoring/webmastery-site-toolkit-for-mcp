<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CoverageManifestTest extends TestCase {
	private function manifest(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public static function destructive_state_cases(): array {
		return array(
			array( 'bulk-trash-posts', 'editor' ),
			array( 'delete-post', 'editor' ),
			array( 'delete-page', 'editor' ),
			array( 'delete-cpt-mcp-book', 'book_manager' ),
			array( 'delete-cpt-mcp-case-study', 'case_manager' ),
			array( 'delete-post-meta', 'editor' ),
		);
	}

	/** @dataProvider destructive_state_cases */
	public function test_destructive_permission_denials_retain_state_evidence_and_allowed_controls( string $slug, string $allowed_role ): void {
		$this->assert_destructive_controls( $this->manifest(), $slug, $allowed_role );
	}

	private function assert_destructive_controls( array $cases, string $slug, string $allowed_role ): void {
		$denied = $allowed = array();
		foreach ( $cases as $case ) {
			if ( "webmastery-site-toolkit-for-mcp/{$slug}" !== $case['ability'] ) {
				continue;
			}
			if ( $allowed_role === $case['role'] && 'success' === $case['expect'] && $this->has_destructive_write_evidence( $case, $slug ) ) {
				$allowed[] = $case;
			}
			if ( 'subscriber' === $case['role'] && 'failure' === $case['expect']
				&& 'forbidden' === ( $case['assert_permission'] ?? null )
				&& 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? null )
				&& true === ( $case['assert_unchanged'] ?? null ) ) {
				$denied[] = $case;
			}
		}
		$this->assertNotEmpty( $denied, "{$slug} must retain a Subscriber permission denial with unchanged persisted state." );
		$this->assertNotEmpty( $allowed, "{$slug} must retain its {$allowed_role} positive write control, not a preview or zero-write result." );
	}

	private function has_destructive_write_evidence( array $case, string $slug ): bool {
		if ( true === ( $case['input']['dry_run'] ?? false ) ) {
			return false;
		}
		$values = $case['assert_values'] ?? array();
		if ( 'bulk-trash-posts' === $slug ) {
			if ( ! is_int( $values['data.success_count'] ?? null ) || $values['data.success_count'] <= 0 ) {
				return false;
			}
			foreach ( $values as $path => $id ) {
				if ( preg_match( '/^data\.successes\.(\d+)\.id$/', $path, $match )
					&& 'trash' === ( $values[ "data.successes.{$match[1]}.status" ] ?? null )
					&& in_array( $id, $case['input']['ids'], true ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 'delete-post-meta' === $slug ) {
			return is_int( $values['data.deleted_count'] ?? null ) && $values['data.deleted_count'] > 0;
		}
		$id_key = array( 'delete-post' => 'post_id', 'delete-page' => 'page_id' )[ $slug ] ?? 'id';
		return 'trash' === ( $values['data.status'] ?? null )
			&& isset( $values['data.id'], $case['input'][ $id_key ] )
			&& $values['data.id'] === $case['input'][ $id_key ];
	}

	public function test_media_update_denial_retains_previously_stored_alt_metadata(): void {
		$this->assert_media_update_controls( $this->manifest() );
	}

	private function assert_media_update_controls( array $manifest ): void {
		$cases = array_column( $manifest, null, 'label' );
		$allowed_label = 'wstm122 update-media preserves sanitized title caption and alt backslashes';
		$denied_label = 'wstm122 denied media write preserves alt metadata';
		$readback_label = 'wstm122 denied media write preserves title and caption';
		$this->assertArrayHasKey( $allowed_label, $cases );
		$this->assertArrayHasKey( $denied_label, $cases );
		$this->assertArrayHasKey( $readback_label, $cases );
		$allowed = $cases[ $allowed_label ];
		$denied = $cases[ $denied_label ];
		$readback = $cases[ $readback_label ];
		foreach ( array( $allowed, $denied ) as $case ) {
			$this->assertSame( 'webmastery-site-toolkit-for-mcp/update-media', $case['ability'] );
			$this->assertArrayHasKey( 'alt_text', $case['input'] );
			$this->assertNotEmpty( $case['assert_post_meta'] ?? array() );
			$this->assertSame( $case['input']['media_id'], $case['assert_post_meta'][0]['post_id'] );
			$this->assertSame( '_wp_attachment_image_alt', $case['assert_post_meta'][0]['meta_key'] );
		}
		$this->assertSame( 'author', $allowed['role'] );
		$this->assertSame( 'success', $allowed['expect'] );
		$this->assertSame( $allowed['input']['alt_text'], $allowed['assert_values']['data.alt_text'] );
		$this->assertSame( $allowed['input']['alt_text'], $allowed['assert_post_meta'][0]['value'] );
		$this->assertSame( 'subscriber', $denied['role'] );
		$this->assertSame( 'failure', $denied['expect'] );
		$this->assertSame( 'ability_invalid_permissions', $denied['expect_error_reason'] );
		$this->assertSame( $allowed['assert_post_meta'], $denied['assert_post_meta'] );
		$this->assertNotSame( $denied['input']['alt_text'], $denied['assert_post_meta'][0]['value'] );
		$this->assertLessThan( array_search( $denied_label, array_keys( $cases ), true ), array_search( $allowed_label, array_keys( $cases ), true ) );
		$this->assertSame( 'webmastery-site-toolkit-for-mcp/get-media', $readback['ability'] );
		$this->assertSame( 'author', $readback['role'] );
		$this->assertSame( 'success', $readback['expect'] );
		$this->assertSame( $allowed['input']['media_id'], $readback['input']['media_id'] );
		foreach ( array( 'data.title', 'data.caption', 'data.alt_text' ) as $field ) {
			$this->assertSame( $allowed['assert_values'][ $field ], $readback['assert_values'][ $field ] );
		}
		$this->assertLessThan( array_search( $readback_label, array_keys( $cases ), true ), array_search( $denied_label, array_keys( $cases ), true ) );
	}

	public static function destructive_guard_mutations(): array {
		$mutations = array();
		foreach ( self::destructive_state_cases() as list( $slug, $role ) ) {
			$variants = array( 'remove-denial', 'remove-state', 'not_found', 'invalid_input', 'missing_confirmation', 'remove-allowed', 'remove-write-evidence', 'zero-write', 'preview-only' );
			if ( 'delete-post-meta' !== $slug ) {
				$variants[] = 'wrong-target';
			}
			if ( 'bulk-trash-posts' === $slug ) {
				$variants[] = 'missing-success-count';
				$variants[] = 'missing-success-item';
				$variants[] = 'wrong-success-status';
			}
			foreach ( $variants as $mutation ) {
				$mutations[ "{$slug} {$mutation}" ] = array( $slug, $role, $mutation );
			}
		}
		return $mutations;
	}

	/** @dataProvider destructive_guard_mutations */
	public function test_destructive_guard_rejects_weakened_evidence( string $slug, string $allowed_role, string $mutation ): void {
		$cases = $this->manifest();
		$this->assert_destructive_controls( $cases, $slug, $allowed_role );
		foreach ( $cases as $index => &$case ) {
			if ( "webmastery-site-toolkit-for-mcp/{$slug}" !== $case['ability'] ) {
				continue;
			}
			if ( $allowed_role === $case['role'] && 'success' === $case['expect'] ) {
				if ( 'remove-allowed' === $mutation ) { unset( $cases[ $index ] ); }
				if ( 'remove-write-evidence' === $mutation ) { unset( $case['assert_values'] ); }
				if ( 'preview-only' === $mutation ) { $case['input']['dry_run'] = true; }
				if ( 'zero-write' === $mutation ) {
					$case['assert_values']['data.success_count'] = 0;
					$case['assert_values']['data.deleted_count'] = 0;
					$case['assert_values']['data.status'] = 'draft';
				}
				if ( 'missing-success-count' === $mutation ) { unset( $case['assert_values']['data.success_count'] ); }
				foreach ( array_keys( $case['assert_values'] ?? array() ) as $path ) {
					if ( 'wrong-target' === $mutation && ( 'data.id' === $path || preg_match( '/^data\.successes\.\d+\.id$/', $path ) ) ) {
						$case['assert_values'][ $path ] = '__unsubmitted_target__';
					}
					if ( 'missing-success-item' === $mutation && str_starts_with( $path, 'data.successes.' ) ) {
						unset( $case['assert_values'][ $path ] );
					}
					if ( 'wrong-success-status' === $mutation && preg_match( '/^data\.successes\.\d+\.status$/', $path ) ) {
						$case['assert_values'][ $path ] = 'draft';
					}
				}
			}
			if ( 'subscriber' !== $case['role'] || 'failure' !== $case['expect'] ) {
				continue;
			}
			if ( 'remove-denial' === $mutation ) {
				unset( $cases[ $index ] );
			} elseif ( 'remove-state' === $mutation ) {
				unset( $case['assert_unchanged'] );
			} elseif ( in_array( $mutation, array( 'not_found', 'invalid_input', 'missing_confirmation' ), true ) ) {
				$case['expect_error_reason'] = $mutation;
			}
		}
		unset( $case );
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->assert_destructive_controls( $cases, $slug, $allowed_role );
	}

	public static function media_guard_mutations(): array {
		return array_map( static function ( $mutation ) { return array( $mutation ); }, array(
			'remove-denial', 'remove-state', 'wrong-state', 'not_found', 'invalid_input',
			'missing_confirmation', 'remove-allowed', 'remove-readback', 'wrong-readback',
		) );
	}

	/** @dataProvider media_guard_mutations */
	public function test_media_guard_rejects_weakened_evidence( string $mutation ): void {
		$cases = $this->manifest();
		$this->assert_media_update_controls( $cases );
		foreach ( $cases as $index => &$case ) {
			if ( 'wstm122 denied media write preserves alt metadata' === $case['label'] ) {
				if ( 'remove-denial' === $mutation ) { unset( $cases[ $index ] ); }
				if ( 'remove-state' === $mutation ) { unset( $case['assert_post_meta'] ); }
				if ( 'wrong-state' === $mutation ) { $case['assert_post_meta'][0]['value'] = $case['input']['alt_text']; }
				if ( in_array( $mutation, array( 'not_found', 'invalid_input', 'missing_confirmation' ), true ) ) { $case['expect_error_reason'] = $mutation; }
			}
			if ( 'remove-allowed' === $mutation && 'wstm122 update-media preserves sanitized title caption and alt backslashes' === $case['label'] ) {
				unset( $cases[ $index ] );
			}
			if ( 'wstm122 denied media write preserves title and caption' === $case['label'] ) {
				if ( 'remove-readback' === $mutation ) { unset( $cases[ $index ] ); }
				if ( 'wrong-readback' === $mutation ) { $case['assert_values']['data.title'] = 'Changed after denial'; }
			}
		}
		unset( $case );
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->assert_media_update_controls( $cases );
	}

	public static function mutations(): array {
		return array(
			array( 'none', 'security', 0 ),
			array( 'none', 'e2e', 0 ),
			array( 'denial-code', 'security', 1 ),
			array( 'callback-code', 'security', 1 ),
			array( 'media-state', 'security', 1 ),
			array( 'contributor-private', 'security', 1 ),
			array( 'contributor-future-message', 'security', 1 ),
			array( 'taxonomy-allowed', 'security', 1 ),
			array( 'window-items', 'security', 1 ),
			array( 'window-continuation', 'security', 1 ),
			array( 'window-no-totals', 'security', 1 ),
			array( 'unknown-role', 'e2e', 1 ),
			array( 'disabled-state', 'e2e', 1 ),
			array( 'invalid-capability', 'e2e', 1 ),
			array( 'invalid-stored-post', 'e2e', 1 ),
			array( 'metadata-old-reason', 'e2e', 1 ),
			array( 'permission-metadata-reason', 'e2e', 1 ),
			array( 'permission-schema-reason', 'e2e', 1 ),
			array( 'permission-unknown-code', 'e2e', 1 ),
			array( 'permission-array-code', 'e2e', 1 ),
		);
	}

	/** @dataProvider mutations */
	public function test_regression_policy_rejects_weakened_evidence( string $mutation, string $validator, int $expected ): void {
		$root = dirname( __DIR__, 2 );
		$tmp = sys_get_temp_dir() . '/wstm120-policy-' . bin2hex( random_bytes( 8 ) );
		mkdir( $tmp . '/scripts', 0777, true );
		mkdir( $tmp . '/tests/e2e', 0777, true );
		$name = 'security' === $validator ? 'validate-security-qa.php' : 'validate-e2e-manifest.php';
		$script = $tmp . '/scripts/' . $name;
		$file = $tmp . '/tests/e2e/abilities-manifest.json';
		copy( $root . '/scripts/' . $name, $script );
		copy( $root . '/tests/e2e/error-contract-assertions.php', $tmp . '/tests/e2e/error-contract-assertions.php' );
		$cases = json_decode( file_get_contents( $root . '/tests/e2e/abilities-manifest.json' ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $cases as &$case ) {
			if ( in_array( $case['ability'], array( 'webmastery-site-toolkit-for-mcp/list-posts', 'webmastery-site-toolkit-for-mcp/list-pages' ), true ) && 'private' === ( $case['input']['status'] ?? '' ) ) {
				if ( 'window-items' === $mutation ) { unset( $case['assert_values']['data.items'] ); }
				if ( 'window-continuation' === $mutation ) { unset( $case['assert_values']['data.next_page'] ); }
				if ( 'window-no-totals' === $mutation ) { $case['assert_missing_paths'] = array(); }
			}
			if ( 'metadata-old-reason' === $mutation && ! empty( $case['assert_metadata_boundary'] ) ) {
				$case['expect_error_reason'] = 'metadata_requires_separate_call';
			}
			if ( 'invalid_input' === ( $case['assert_permission'] ?? null ) ) {
				if ( 'permission-metadata-reason' === $mutation ) { $case['assert_permission'] = 'metadata_requires_separate_call'; }
				if ( 'permission-schema-reason' === $mutation ) { $case['assert_permission'] = 'ability_invalid_input'; }
				if ( 'permission-unknown-code' === $mutation ) { $case['assert_permission'] = 'unknown'; }
				if ( 'permission-array-code' === $mutation ) { $case['assert_permission'] = array( 'invalid_input' ); }
			}
			if ( 'webmastery-site-toolkit-for-mcp/delete-media' === $case['ability'] && 'failure' === $case['expect'] ) {
				if ( 'denial-code' === $mutation ) { $case['expect_error_code'] = 'not_found'; }
				if ( 'callback-code' === $mutation ) { $case['assert_permission'] = 'not_found'; }
				if ( 'media-state' === $mutation ) { unset( $case['assert_unchanged'] ); }
				if ( 'disabled-state' === $mutation ) { $case['assert_unchanged'] = false; }
			}
			if ( 'contributor' === $case['role'] ) {
				if ( 'unknown-role' === $mutation ) { $case['role'] = 'contributor_typo'; }
				if ( 'contributor-private' === $mutation && 'private' === ( $case['input']['status'] ?? '' ) ) { unset( $case['assert_unchanged'] ); }
				if ( 'contributor-future-message' === $mutation && 'future' === ( $case['input']['status'] ?? '' ) && isset( $case['assert_values']['error.message'] ) ) { $case['assert_values']['error.message'] = 'Invalid date.'; }
				if ( 'invalid-capability' === $mutation ) { $case['assert_capabilities'] = array( array( 'capability' => 'edit_posts', 'args' => array(), 'allowed' => 'true' ) ); }
				if ( 'invalid-stored-post' === $mutation ) { $case['assert_stored_post'] = array( 'fields' => array() ); }
			}
			if ( 'taxonomy-allowed' === $mutation && 'webmastery-site-toolkit-for-mcp/list-tags' === $case['ability'] && 'subscriber' === $case['role'] ) {
				$case['role'] = 'admin';
			}
		}
		unset( $case );
		try {
			file_put_contents( $file, json_encode( $cases, JSON_THROW_ON_ERROR ) );
			$process = proc_open( array( PHP_BINARY, $script ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $process );
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( $expected, proc_close( $process ), $output );
		} finally {
			if ( is_file( $file ) ) { unlink( $file ); }
			unlink( $script );
			unlink( $tmp . '/tests/e2e/error-contract-assertions.php' );
			rmdir( $tmp . '/tests/e2e' );
			rmdir( $tmp . '/tests' );
			rmdir( $tmp . '/scripts' );
			rmdir( $tmp );
		}
	}
}
