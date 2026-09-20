<?php

declare(strict_types=1);

$repo_root     = dirname(__DIR__);
$includes_path = $repo_root . '/includes';
$manifest_path = $repo_root . '/tests/e2e/abilities-manifest.json';
$errors        = array();

function webmastery_mcp_security_qa_fail( array $errors ): void {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "ERROR {$error}\n" );
	}

	exit( 1 );
}

function webmastery_mcp_security_qa_read_json( string $path ): array {
	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		webmastery_mcp_security_qa_fail( array( "Could not read {$path}" ) );
	}

	$data = json_decode( $raw, true );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		webmastery_mcp_security_qa_fail( array( "Invalid JSON in {$path}: " . json_last_error_msg() ) );
	}

	require_once dirname(__DIR__) . '/tests/e2e/error-contract-assertions.php';
	foreach ( $data as $case ) {
		if ( 'failure' !== ( $case['expect'] ?? null ) ) {
			continue;
		}
		try {
			if ( 'canonical' !== ( $case['expect_error_shape'] ?? null )
				|| wstm118_expected_code( $case['expect_error_reason'] ?? '' ) !== ( $case['expect_error_code'] ?? null ) ) {
				webmastery_mcp_security_qa_fail( array( 'Negative cases require canonical code, precise reason, and envelope shape.' ) );
			}
		} catch ( RuntimeException $error ) {
			webmastery_mcp_security_qa_fail( array( $error->getMessage() ) );
		}
	}
	return $data;
}

function webmastery_mcp_security_qa_has_missing_path_case( array $cases, string $ability, array $paths ): bool {
	foreach ( $cases as $case ) {
		if ( ! is_array( $case ) || $ability !== ( $case['ability'] ?? '' ) ) {
			continue;
		}

		$missing_paths = $case['assert_missing_paths'] ?? array();
		if ( ! is_array( $missing_paths ) ) {
			continue;
		}

		$has_all_paths = true;
		foreach ( $paths as $path ) {
			if ( ! in_array( $path, $missing_paths, true ) ) {
				$has_all_paths = false;
				break;
			}
		}

		if ( $has_all_paths ) {
			return true;
		}
	}

	return false;
}

function webmastery_mcp_security_qa_has_filtered_total_case( array $cases, string $ability, string $status ): bool {
	foreach ( $cases as $case ) {
		if ( ! is_array( $case ) || $ability !== ( $case['ability'] ?? '' ) ) {
			continue;
		}

		if ( 'success' !== ( $case['expect'] ?? '' ) ) {
			continue;
		}

		$input         = $case['input'] ?? array();
		$assert_values = $case['assert_values'] ?? array();
		if (
			is_array( $input )
			&& $status === ( $input['status'] ?? '' )
			&& is_array( $assert_values )
			&& array_key_exists( 'data.total', $assert_values )
			&& 0 === $assert_values['data.total']
		) {
			return true;
		}
	}

	return false;
}

if ( is_dir( $includes_path ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_path, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		$contents = file_get_contents( $file->getPathname() );
		if ( false === $contents ) {
			$errors[] = "Could not read {$file->getPathname()}";
			continue;
		}

		if ( preg_match( "/['\"]permission_callback['\"]\\s*=>\\s*['\"]__return_true['\"]/", $contents ) ) {
			$errors[] = "{$file->getPathname()} uses permission_callback => __return_true. Public ability callbacks require explicit security review and should be allow-listed in this validator only when intentionally unauthenticated.";
		}
	}
}

$manifest = webmastery_mcp_security_qa_read_json( $manifest_path );
$summary  = array();

foreach ( $manifest as $case ) {
	if ( ! is_array( $case ) ) {
		continue;
	}

	$ability = (string) ( $case['ability'] ?? '' );
	$expect  = (string) ( $case['expect'] ?? '' );
	if ( '' === $ability ) {
		continue;
	}

	if ( ! isset( $summary[ $ability ] ) ) {
		$summary[ $ability ] = array(
			'success' => 0,
			'failure' => 0,
		);
	}

	if ( isset( $summary[ $ability ][ $expect ] ) ) {
		$summary[ $ability ][ $expect ]++;
	}
}

$required_failure_cases = array(
	'webmastery-site-toolkit-for-mcp/activate-plugin',
	'webmastery-site-toolkit-for-mcp/approve-comment',
	'webmastery-site-toolkit-for-mcp/bulk-publish-posts',
	'webmastery-site-toolkit-for-mcp/bulk-trash-posts',
	'webmastery-site-toolkit-for-mcp/create-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/create-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/create-page',
	'webmastery-site-toolkit-for-mcp/create-category',
	'webmastery-site-toolkit-for-mcp/create-tag',
	'webmastery-site-toolkit-for-mcp/create-post',
	'webmastery-site-toolkit-for-mcp/deactivate-plugin',
	'webmastery-site-toolkit-for-mcp/delete-category',
	'webmastery-site-toolkit-for-mcp/delete-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/delete-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/delete-media',
	'webmastery-site-toolkit-for-mcp/delete-page',
	'webmastery-site-toolkit-for-mcp/delete-post',
	'webmastery-site-toolkit-for-mcp/delete-post-meta',
	'webmastery-site-toolkit-for-mcp/delete-tag',
	'webmastery-site-toolkit-for-mcp/get-environment-info',
	'webmastery-site-toolkit-for-mcp/get-media',
	'webmastery-site-toolkit-for-mcp/get-page',
	'webmastery-site-toolkit-for-mcp/get-post',
	'webmastery-site-toolkit-for-mcp/get-user',
	'webmastery-site-toolkit-for-mcp/list-site-kit-modules',
	'webmastery-site-toolkit-for-mcp/get-site-kit-permissions',
	'webmastery-site-toolkit-for-mcp/get-site-kit-pagespeed',
	'webmastery-site-toolkit-for-mcp/list-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/list-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/list-media',
	'webmastery-site-toolkit-for-mcp/list-posts',
	'webmastery-site-toolkit-for-mcp/list-pages',
	'webmastery-site-toolkit-for-mcp/list-categories',
	'webmastery-site-toolkit-for-mcp/list-tags',
	'webmastery-site-toolkit-for-mcp/list-users',
	'webmastery-site-toolkit-for-mcp/patch-content-block',
	'webmastery-site-toolkit-for-mcp/patch-post-content',
	'webmastery-site-toolkit-for-mcp/security-audit',
	'webmastery-site-toolkit-for-mcp/seo-analyze-post',
	'webmastery-site-toolkit-for-mcp/spam-comment',
	'webmastery-site-toolkit-for-mcp/trash-comment',
	'webmastery-site-toolkit-for-mcp/update-comment',
	'webmastery-site-toolkit-for-mcp/update-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/update-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/update-page',
	'webmastery-site-toolkit-for-mcp/update-category',
	'webmastery-site-toolkit-for-mcp/update-tag',
	'webmastery-site-toolkit-for-mcp/update-post',
	'webmastery-site-toolkit-for-mcp/upload-image',
	'webmastery-site-toolkit-for-mcp/webmaster-verification-status',
);

foreach ( $required_failure_cases as $ability ) {
	if ( empty( $summary[ $ability ]['failure'] ) ) {
		$errors[] = "{$ability} must keep at least one negative permission/security manifest case.";
	}
}

// A not-found, invalid-input, or default-category error is not permission proof.
$required_permission_cases = array(
	'bulk-trash-posts', 'delete-category', 'delete-tag', 'delete-media',
	'delete-post', 'delete-page', 'delete-cpt-mcp-book', 'delete-cpt-mcp-case-study',
	'delete-post-meta', 'get-post', 'get-page', 'get-media', 'seo-analyze-post',
	'list-posts', 'list-pages', 'list-categories', 'list-tags',
);
foreach ( $required_permission_cases as $slug ) {
	$matches = array_filter(
		$manifest,
		static function ( $case ) use ( $slug ) {
			return "webmastery-site-toolkit-for-mcp/{$slug}" === ( $case['ability'] ?? '' )
				&& 'failure' === ( $case['expect'] ?? '' )
				&& 'forbidden' === ( $case['assert_permission'] ?? '' )
				&& 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' );
		}
	);
	if ( ! $matches ) {
		$errors[] = "{$slug} must retain an exact callback and wrapper permission-denial case.";
	}
}

foreach ( array( 'create-post', 'update-post', 'delete-post' ) as $slug ) {
	foreach ( array( 'success', 'failure' ) as $expect ) {
		$matches = array_filter(
			$manifest,
			static function ( $case ) use ( $slug, $expect ) {
				return "webmastery-site-toolkit-for-mcp/{$slug}" === ( $case['ability'] ?? '' )
					&& 'contributor' === ( $case['role'] ?? '' ) && $expect === ( $case['expect'] ?? '' )
					&& ( 'success' === $expect ? isset( $case['assert_stored_post'] ) : true === ( $case['assert_unchanged'] ?? false ) );
			}
		);
		if ( ! $matches ) {
			$errors[] = "{$slug} must retain Contributor {$expect} coverage with persisted-state evidence.";
		}
	}
}

foreach ( array( 'subscriber', 'author' ) as $role ) {
	$matches = array_filter(
		$manifest,
		static function ( $case ) use ( $role ) {
			return 'webmastery-site-toolkit-for-mcp/delete-media' === ( $case['ability'] ?? '' )
				&& $role === ( $case['role'] ?? '' ) && 'failure' === ( $case['expect'] ?? '' )
				&& 'forbidden' === ( $case['assert_permission'] ?? '' )
				&& 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' )
				&& true === ( $case['assert_unchanged'] ?? false );
		}
	);
	if ( ! $matches ) {
		$errors[] = "delete-media must retain {$role} permission denial with unchanged persisted rows, metadata, and files.";
	}
}

foreach ( array( 'publish', 'private', 'future' ) as $status ) {
	foreach ( array( 'create-post', 'update-post' ) as $slug ) {
		$matches = array_filter(
			$manifest,
			static function ( $case ) use ( $slug, $status ) {
				$is_create = 'create-post' === $slug;
				$permission_proof = $is_create
					? 'forbidden' === ( $case['assert_permission'] ?? '' ) && 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' )
					: true === ( $case['assert_permission'] ?? false ) && false === ( $case['assert_values']['success'] ?? null )
						&& 'forbidden' === ( $case['expect_error_reason'] ?? '' )
						&& 'You do not have permission to publish this post.' === ( $case['assert_values']['error.message'] ?? '' );
				return "webmastery-site-toolkit-for-mcp/{$slug}" === ( $case['ability'] ?? '' )
					&& 'contributor' === ( $case['role'] ?? '' ) && 'failure' === ( $case['expect'] ?? '' )
					&& $status === ( $case['input']['status'] ?? '' )
					&& true === ( $case['assert_unchanged'] ?? false ) && $permission_proof;
			}
		);
		if ( ! $matches ) {
			$errors[] = "{$slug} must retain Contributor {$status} permission rejection with no persisted changes.";
		}
	}
}

foreach ( array( 'list-categories', 'list-tags' ) as $slug ) {
	foreach ( array( 'subscriber' => 'success', 'no_role' => 'failure' ) as $role => $expect ) {
		$matches = array_filter(
			$manifest,
			static function ( $case ) use ( $slug, $role, $expect ) {
				return "webmastery-site-toolkit-for-mcp/{$slug}" === ( $case['ability'] ?? '' )
					&& $role === ( $case['role'] ?? '' ) && $expect === ( $case['expect'] ?? '' )
					&& ( 'success' === $expect ? true : 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' ) );
			}
		);
		if ( ! $matches ) {
			$errors[] = "{$slug} must retain {$role} {$expect} read-capability coverage.";
		}
	}
}

foreach ( array( 'update', 'approve', 'trash', 'spam' ) as $action ) {
	$ability = "webmastery-site-toolkit-for-mcp/{$action}-comment";
	$wstm105_covered = false;
	foreach ( $manifest as $case ) {
		if (
			$ability === ( $case['ability'] ?? '' )
			&& 'wstm105_moderator' === ( $case['role'] ?? '' )
			&& 'failure' === ( $case['expect'] ?? '' )
			&& 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' )
			&& isset( $case['input']['comment_id'], $case['assert_comment_state']['comment_id'], $case['assert_comment_state']['content'], $case['assert_comment_state']['status'] )
			&& $case['input']['comment_id'] === $case['assert_comment_state']['comment_id']
			&& ( 'update' !== $action || 'spam' === ( $case['input']['status'] ?? '' ) )
		) {
			$wstm105_covered = true;
			break;
		}
	}
	if ( ! $wstm105_covered ) {
		$errors[] = "{$ability} must keep a moderate_comments-only denial with persisted content/status assertions (including a status input for update-comment).";
	}
	$wstm105_required = array(
		"wstm105 {$action} own author lacks moderation floor" => array( 'author', 'failure', true ),
		"wstm105 {$action} editor lacks CPT edit capability" => array( 'editor', 'failure', true ),
		"wstm105 {$action} mapped CPT allowed" => array( 'wstm105_mapped_moderator', 'success', true ),
		"wstm105 {$action} zero ID" => array( 'editor', 'failure', true ),
		"wstm105 {$action} missing denied caller" => array( 'subscriber', 'failure', false ),
		'update' === $action ? 'update-comment missing' : "wstm105 {$action} missing comment" => array( 'editor', 'failure', false ),
	);
	foreach ( $wstm105_required as $label => list( $role, $expect, $state ) ) {
		$covered = false;
		foreach ( $manifest as $case ) {
			if ( $label !== ( $case['label'] ?? '' ) || $ability !== ( $case['ability'] ?? '' ) || $role !== ( $case['role'] ?? '' ) || $expect !== ( $case['expect'] ?? '' ) ) {
				continue;
			}
			if ( $state && ! isset( $case['assert_comment_state']['comment_id'], $case['assert_comment_state']['content'], $case['assert_comment_state']['status'] ) ) {
				continue;
			}
			$missing = 'editor' === $role && ( 0 === ( $case['input']['comment_id'] ?? null ) || '__missing_comment_id__' === ( $case['input']['comment_id'] ?? null ) );
			if ( 'failure' === $expect ) {
				if ( $missing && 'update' !== $action ) {
					if ( 'not_found' !== ( $case['expect_error_reason'] ?? null )
						|| 'Comment not found.' !== ( $case['assert_values']['error.message'] ?? null ) ) {
						continue;
					}
				} elseif ( ( $missing ? 'not_found' : 'ability_invalid_permissions' ) !== ( $case['expect_error_reason'] ?? null ) ) {
					continue;
				}
			}
			$covered = true;
		}
		if ( ! $covered ) {
			$errors[] = "{$ability} must keep its exact {$label} role/result/state regression.";
		}
	}
}

foreach ( array( 'patch-content-block', 'patch-post-content' ) as $ability_slug ) {
	foreach ( array( 'editor' => 'success', 'subscriber' => 'failure' ) as $role => $expect ) {
		$matches = array_filter(
			$manifest,
			static function ( $case ) use ( $ability_slug, $role, $expect ) {
				return "webmastery-site-toolkit-for-mcp/{$ability_slug}" === ( $case['ability'] ?? '' )
					&& $role === ( $case['role'] ?? '' )
					&& $expect === ( $case['expect'] ?? '' );
			}
		);
		if ( empty( $matches ) ) {
			$errors[] = "{$ability_slug} must keep a {$role} {$expect} manifest case.";
		}
	}
}

foreach ( array( 'get-post-meta', 'update-post-meta', 'delete-post-meta' ) as $slug ) {
	foreach ( array( 'author' => 'failure', 'admin' => 'success' ) as $role => $expect ) {
		$found = false;
		foreach ( $manifest as $case ) {
			if ( "webmastery-site-toolkit-for-mcp/{$slug}" === ( $case['ability'] ?? '' )
				&& $role === ( $case['role'] ?? '' ) && $expect === ( $case['expect'] ?? '' )
				&& 'wstm110_restricted' === ( $case['input']['meta_key'] ?? '' )
				&& ( 'success' === $expect || 'forbidden' === ( $case['expect_error_reason'] ?? '' ) ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$errors[] = "{$slug} must keep its restricted-key {$role} {$expect} manifest case.";
		}
	}
}
if ( ! webmastery_mcp_security_qa_has_missing_path_case( $manifest, 'webmastery-site-toolkit-for-mcp/get-post-meta', array( 'data.meta.wstm110_restricted' ) ) ) {
	$errors[] = 'Standalone metadata listings must retain restricted-key absence assertions.';
}

foreach ( array( 'list-site-kit-modules', 'get-site-kit-permissions', 'get-site-kit-pagespeed' ) as $slug ) {
	$ability = "webmastery-site-toolkit-for-mcp/{$slug}";
	$required = array( 'local-denial' => false, 'read-allowed' => false, 'subscriber-allowed' => false, 'upstream-denial' => false );
	foreach ( $manifest as $case ) {
		if ( $ability !== ( $case['ability'] ?? '' ) ) {
			continue;
		}
		$role = $case['role'] ?? '';
		$expect = $case['expect'] ?? '';
		$allow = 'allow' === ( $case['setup']['wstm125_site_kit_permission'] ?? '' );
		if ( 'wstm125_no_read' === $role && 'failure' === $expect && $allow && 'ability_invalid_permissions' === ( $case['expect_error_reason'] ?? '' ) ) {
			$required['local-denial'] = true;
		}
		if ( 'wstm125_read' === $role && 'success' === $expect ) {
			$required['read-allowed'] = true;
		}
		if ( 'subscriber' === $role ) {
			if ( 'success' === $expect && $allow ) {
				$required['subscriber-allowed'] = true;
			}
			if ( 'failure' === $expect && ! $allow ) {
				$required['upstream-denial'] = true;
			}
		}
	}
	foreach ( $required as $scenario => $present ) {
		if ( ! $present ) {
			$errors[] = "{$ability} must keep its Site Kit {$scenario} manifest case.";
		}
	}
}

foreach ( array( 'list-posts', 'list-pages' ) as $ability_slug ) {
	$ability = "webmastery-site-toolkit-for-mcp/{$ability_slug}";
	if ( ! webmastery_mcp_security_qa_has_filtered_total_case( $manifest, $ability, 'private' ) ) {
		$errors[] = "{$ability} must keep a private-status filtered-total manifest case.";
	}
	if ( ! webmastery_mcp_security_qa_has_missing_path_case( $manifest, $ability, array( 'data.items.0.author_login' ) ) ) {
		$errors[] = "{$ability} must keep an author_login absence assertion.";
	}
}

if ( ! webmastery_mcp_security_qa_has_missing_path_case( $manifest, 'webmastery-site-toolkit-for-mcp/list-users', array( 'data.items.0.login', 'data.items.0.email' ) ) ) {
	$errors[] = 'webmastery-site-toolkit-for-mcp/list-users must keep login/email absence assertions for lower-privilege user-listing cases.';
}

if ( ! webmastery_mcp_security_qa_has_missing_path_case( $manifest, 'webmastery-site-toolkit-for-mcp/get-user', array( 'data.login', 'data.email' ) ) ) {
	$errors[] = 'webmastery-site-toolkit-for-mcp/get-user must keep login/email absence assertions for lower-privilege user-read cases.';
}

if ( ! webmastery_mcp_security_qa_has_missing_path_case( $manifest, 'webmastery-site-toolkit-for-mcp/get-site-info', array( 'data.wordpress_version', 'data.active_theme.version' ) ) ) {
	$errors[] = 'webmastery-site-toolkit-for-mcp/get-site-info must keep version fingerprinting absence assertions for low-privilege cases.';
}

$media_requirements = [
	'upload-image with featured image metadata' => [ 'author', 'success', null ],
	'upload-image as subscriber' => [ 'subscriber', 'failure', null ],
	'upload-image rejects private URL' => [ 'author', 'failure', 'invalid_url' ],
	'upload-image rejects non-image MIME' => [ 'author', 'failure', 'unsupported_mime_type' ],
	'upload-image rejects empty file' => [ 'author', 'failure', 'invalid_file' ],
	'upload-image rejects incomplete PNG header' => [ 'author', 'failure', 'invalid_file' ],
	'upload-image rejects IPv6 literal' => [ 'author', 'failure', 'invalid_url' ],
	'upload-image requires featured image target' => [ 'author', 'failure', 'missing_post_id' ],
];
foreach ( $media_requirements as $label => [ $role, $expect, $code ] ) {
	$found = false;
	foreach ( $manifest as $case ) {
		if ( 'webmastery-site-toolkit-for-mcp/upload-image' === ( $case['ability'] ?? '' )
			&& $label === ( $case['label'] ?? '' ) && $role === ( $case['role'] ?? '' )
			&& $expect === ( $case['expect'] ?? '' ) && ( null === $code || $code === ( $case['expect_error_reason'] ?? '' ) ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$errors[] = "upload-image must retain its {$label} manifest scenario with the expected role and result.";
	}
}

$verification = 'webmastery-site-toolkit-for-mcp/webmaster-verification-status';
foreach ( array( 'subscriber', 'author' ) as $role ) {
	$role_cases = array_filter(
		$manifest,
		static function ( $case ) use ( $role ) {
			return $role === ( $case['role'] ?? '' ) && 'success' === ( $case['expect'] ?? '' );
		}
	);
	if ( ! webmastery_mcp_security_qa_has_missing_path_case( $role_cases, $verification, array( 'data.google.site_kit', 'data.checks.google_site_kit' ) ) ) {
		$errors[] = "{$verification} must keep successful {$role} cases omitting both private Site Kit projections.";
	}
}
foreach ( $manifest as $case ) {
	if ( $verification === ( $case['ability'] ?? '' ) && ( ! isset( $case['assert_wstm114_http_calls'] ) || ! is_int( $case['assert_wstm114_http_calls'] ) || $case['assert_wstm114_http_calls'] < 0 ) ) {
		$errors[] = "{$verification} must assert actual HTTP counts for every fixture case.";
	}
}

if ( $errors ) {
	webmastery_mcp_security_qa_fail( $errors );
}

printf(
	"PASS security QA policy: %d required negative-case abilities, sensitive-field absence assertions, and permission callback scan\n",
	count( $required_failure_cases )
);
