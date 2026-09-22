<?php
require_once __DIR__ . '/error-contract-assertions.php';


// This fixture mutates disposable WordPress data; never expose it over HTTP.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

function wstm117_term_snapshot() {
	global $wpdb;

	// Read the persisted tables, not term caches, including metadata and relationships.
	$queries = array(
		'terms'         => "SELECT * FROM {$wpdb->terms} ORDER BY term_id",
		'taxonomies'    => "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
		'meta'          => "SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
		'relationships' => "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id",
	);
	$snapshot = array();
	foreach ( $queries as $key => $query ) {
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( "Could not read persisted {$key}: {$wpdb->last_error}" );
		}
		$snapshot[ $key ] = $rows;
	}
	return $snapshot;
}

function wstm117_run_taxonomy_tests() {
	global $wp_version;

	$summary = array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'passed' => 0, 'failed' => 0, 'cases' => array() );
	$record = static function ( $label, $passed, $evidence ) use ( &$summary ) {
		$summary[ $passed ? 'passed' : 'failed' ]++;
		$summary['cases'][] = array( 'label' => $label, 'passed' => $passed, 'evidence' => $evidence );
		echo ( $passed ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	};
	$success = static function ( $result ) {
		return is_array( $result ) && true === ( $result['success'] ?? null );
	};
	$error = static function ( $result ) {
		return wstm118_error_envelope( $result )['error']['message'];
	};
	$original_user = get_current_user_id();
	$admin = get_user_by( 'login', 'admin' );
	$editor = get_user_by( 'login', 'editor_test' );
	$subscriber = get_user_by( 'login', 'subscriber_test' );
	if ( ! $admin || ! $editor || ! $subscriber ) {
		throw new RuntimeException( 'Run the ability manifest bootstrap before the taxonomy regression runner.' );
	}
	$original_caps = array();
	foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
		$original_caps[ $taxonomy ] = clone get_taxonomy( $taxonomy )->cap;
	}

	$make_term = static function ( $taxonomy ) {
		$result = wp_insert_term( 'WSTM117 ' . wp_generate_uuid4(), $taxonomy, array( 'description' => 'Original description.' ) );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$id = (int) $result['term_id'];
		add_term_meta( $id, 'wstm117_proof', 'Original metadata.' );
		return $id;
	};
	$post_id = wp_insert_post( array( 'post_title' => 'WSTM117 relationship proof', 'post_status' => 'draft' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}

	try {
		foreach ( array( 'category' => 'category', 'post_tag' => 'tag' ) as $taxonomy => $slug ) {
			foreach ( array( 'create', 'update', 'delete' ) as $action ) {
				$ability = wp_get_ability( "webmastery-site-toolkit-for-mcp/{$action}-{$slug}" );
				$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
				$property->setAccessible( true );
				$execute = $property->getValue( $ability );
				$scenarios = array(
					'admin default' => array( $admin->ID, false, array(), true ),
					'editor default' => array( $editor->ID, false, array(), true ),
					'subscriber default' => array( $subscriber->ID, false, array(), false ),
					'remapped edit only' => array( $subscriber->ID, true, array( 'wstm117_edit' ), 'delete' !== $action ),
					'remapped delete only' => array( $subscriber->ID, true, array( 'wstm117_delete' ), 'delete' === $action ),
					'remapped manage only' => array( $subscriber->ID, true, array( 'wstm117_manage' ), false ),
					'global cap cannot bypass remap' => array( $editor->ID, true, array(), false ),
					'final user_has_cap denial' => array( $editor->ID, false, array(), false ),
					'core alias editor' => array( $editor->ID, false, array(), true ),
					'core alias raw grant does not bypass' => array( $subscriber->ID, false, array( 'edit_post_tags', 'delete_post_tags', 'manage_post_tags' ), false ),
				);
				if ( 'create' !== $action ) {
					$scenarios['per-term map_meta_cap denial'] = array( $editor->ID, false, array(), false );
					$scenarios['remapped per-term denial'] = array( $subscriber->ID, true, array( 'wstm117_edit', 'wstm117_delete' ), false );
				}
				foreach ( $scenarios as $scenario => [ $user_id, $remap, $grants, $allowed ] ) {
					foreach ( array( 'wrapped', 'direct' ) as $path ) {
						wp_set_current_user( $admin->ID );
						get_taxonomy( $taxonomy )->cap = clone $original_caps[ $taxonomy ];
						$id = $make_term( $taxonomy );
						wp_set_object_terms( $post_id, array( $id ), $taxonomy );
						if ( 'category' === $taxonomy ) {
							$parent_id = $make_term( $taxonomy );
							wp_update_term( $id, $taxonomy, array( 'parent' => $parent_id ) );
							$child_id = $make_term( $taxonomy );
							wp_update_term( $child_id, $taxonomy, array( 'parent' => $id ) );
						}
						$input = array( 'name' => 'Changed ' . wp_generate_uuid4(), 'slug' => 'changed-' . wp_generate_uuid4(), 'description' => 'Changed description.' );
						if ( 'category' === $taxonomy ) {
							$input['parent'] = 0;
						}
						if ( 'create' !== $action ) {
							$input[ "{$slug}_id" ] = $id;
						}
						if ( 'delete' === $action ) {
							$input = array( "{$slug}_id" => $id, 'confirm' => true );
						}
						if ( $remap ) {
							get_taxonomy( $taxonomy )->cap->edit_terms = 'wstm117_edit';
							get_taxonomy( $taxonomy )->cap->delete_terms = 'wstm117_delete';
							get_taxonomy( $taxonomy )->cap->manage_terms = 'wstm117_manage';
						}
						if ( 0 === strpos( $scenario, 'core alias' ) ) {
							get_taxonomy( $taxonomy )->cap->edit_terms = 'edit_post_tags';
							get_taxonomy( $taxonomy )->cap->delete_terms = 'delete_post_tags';
						}
						$cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $user_id, $grants, $scenario ) {
							if ( (int) $user_id === $user->ID ) {
								foreach ( $grants as $grant ) {
									$allcaps[ $grant ] = true;
								}
								if ( 'final user_has_cap denial' === $scenario ) {
									$allcaps['manage_categories'] = false;
								}
							}
							return $allcaps;
						};
						$map_filter = static function ( $caps, $cap, $user_id, $args ) use ( $id, $scenario ) {
							if ( false !== strpos( $scenario, 'per-term' ) && in_array( $cap, array( 'edit_term', 'delete_term' ), true ) && $id === ( $args[0] ?? null ) ) {
								return array( 'do_not_allow' );
							}
							return $caps;
						};
						$hooks = array();
						$hook = static function () use ( &$hooks ) {
							$hooks[] = current_filter();
						};
						$watched = array( 'create_term', 'edit_terms', 'pre_delete_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'set_object_terms' );
						add_filter( 'user_has_cap', $cap_filter, 10, 4 );
						add_filter( 'map_meta_cap', $map_filter, 10, 4 );
						foreach ( $watched as $name ) {
							add_action( $name, $hook );
						}
						try {
							wp_set_current_user( $user_id );
							$before = wstm117_term_snapshot();
							$permission = $ability->check_permissions( $input );
							$result = 'wrapped' === $path ? $ability->execute( $input ) : $execute( $input );
							$after = wstm117_term_snapshot();
							$passed = $allowed === $success( $result );
							$passed = $passed && ( $allowed ? true === $permission : is_wp_error( $permission ) );
							if ( ! $allowed ) {
								$passed = $passed && is_wp_error( $permission ) && 'forbidden' === $permission->get_error_code() && $before === $after && array() === $hooks && '' !== $error( $result );
								if ( 'direct' === $path ) {
									$passed = $passed && is_array( $result ) && false === ( $result['success'] ?? null );
								}
							} elseif ( 'delete' === $action ) {
								$passed = $passed && null === get_term( $id, $taxonomy ) && array( 'success' => true, 'data' => array( 'id' => $id, 'deleted' => true ) ) === $result;
							} else {
								$saved = get_term( is_array( $result ) ? ( $result['data']['id'] ?? 0 ) : 0, $taxonomy );
								$passed = $passed && $saved instanceof WP_Term && $input['name'] === $saved->name && $input['slug'] === $saved->slug && $input['description'] === $saved->description;
								if ( 'category' === $taxonomy && $saved instanceof WP_Term ) {
									$passed = $passed && 0 === $saved->parent;
								}
							}
							$record( "{$action}-{$slug}: {$scenario} {$path}", $passed, array(
								'result' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'error' => $error( $result ) ) : $result,
								'persisted_unchanged' => $before === $after,
								'before_sha256' => hash( 'sha256', wp_json_encode( $before ) ),
								'after_sha256' => hash( 'sha256', wp_json_encode( $after ) ),
								'write_hooks' => $hooks,
							) );
						} finally {
							remove_filter( 'user_has_cap', $cap_filter );
							remove_filter( 'map_meta_cap', $map_filter );
							foreach ( $watched as $name ) {
								remove_action( $name, $hook );
							}
						}
					}
				}
				get_taxonomy( $taxonomy )->cap = clone $original_caps[ $taxonomy ];
				wp_set_current_user( $editor->ID );
				if ( 'create' !== $action ) {
					$wrong_id = $make_term( 'category' === $taxonomy ? 'post_tag' : 'category' );
					foreach ( array( 'missing' => 987654321, 'wrong taxonomy' => $wrong_id ) as $scenario => $id ) {
						foreach ( array( 'wrapped', 'direct' ) as $path ) {
							$input = array( "{$slug}_id" => $id, 'name' => 'Must not write' );
							if ( 'delete' === $action ) {
								$input['confirm'] = true;
							}
							$before = wstm117_term_snapshot();
							$result = 'wrapped' === $path ? $ability->execute( $input ) : $execute( $input );
							$expected = ( 'category' === $taxonomy ? 'Category' : 'Tag' ) . ' not found.';
							$record( "{$action}-{$slug}: {$scenario} {$path}", 'not_found' === wstm118_error_reason( $result )
								&& $expected === $result['error']['message'] && '{}' === wp_json_encode( $result['error']['details'] )
								&& $before === wstm117_term_snapshot(), $result );
						}
					}
				}
			}
		}

		wp_set_current_user( $editor->ID );
		$id = (int) get_option( 'default_category' );
		$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/delete-category' );
		$property = new ReflectionProperty( WP_Ability::class, 'execute_callback' );
		$property->setAccessible( true );
		$execute = $property->getValue( $ability );
		$before = wstm117_term_snapshot();
		$raw = wp_delete_term( $id, 'category' );
		$record( 'core default category returns integer zero and meta-cap denies', 0 === $raw && ! current_user_can( 'delete_term', $id ) && $before === wstm117_term_snapshot(), array( 'raw_result' => $raw, 'mapped_caps' => map_meta_cap( 'delete_term', $editor->ID, $id ) ) );
		foreach ( array( 'wrapped', 'direct' ) as $path ) {
			$result = 'wrapped' === $path ? $ability->execute( array( 'category_id' => $id, 'confirm' => true ) ) : $execute( array( 'category_id' => $id, 'confirm' => true ) );
			$record( "default category explicit failure {$path}", ! $success( $result ) && '' !== $error( $result ) && $before === wstm117_term_snapshot() && get_term( $id, 'category' ) instanceof WP_Term, array( 'result' => is_wp_error( $result ) ? $error( $result ) : $result, 'persisted_unchanged' => $before === wstm117_term_snapshot() ) );
		}
		// A final site policy may override core's denial; the real zero return must still fail.
		$allow_default = static function ( $caps, $cap, $user_id, $args ) use ( $id ) {
			return 'delete_term' === $cap && $id === ( $args[0] ?? null ) ? array( 'manage_categories' ) : $caps;
		};
		add_filter( 'map_meta_cap', $allow_default, 10, 4 );
		try {
			$result = $ability->execute( array( 'category_id' => $id, 'confirm' => true ) );
			$record( 'default category zero remains failure after final meta-cap override', ! $success( $result ) && '' !== $error( $result ) && $before === wstm117_term_snapshot(), $result );
		} finally {
			remove_filter( 'map_meta_cap', $allow_default );
		}
	} finally {
		foreach ( $original_caps as $taxonomy => $caps ) {
			get_taxonomy( $taxonomy )->cap = $caps;
		}
		wp_set_current_user( $original_user );
	}

	$path = WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/e2e-artifacts/taxonomy-write-summary.json';
	if ( false === file_put_contents( $path, wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
		throw new RuntimeException( 'Could not persist taxonomy write regression evidence.' );
	}
	echo "TAXONOMY SUMMARY {$summary['passed']} passed, {$summary['failed']} failed\n";
	return $summary;
}
