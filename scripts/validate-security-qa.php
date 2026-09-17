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
	'webmastery-site-toolkit-for-mcp/bulk-publish-posts',
	'webmastery-site-toolkit-for-mcp/create-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/create-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/create-page',
	'webmastery-site-toolkit-for-mcp/create-post',
	'webmastery-site-toolkit-for-mcp/deactivate-plugin',
	'webmastery-site-toolkit-for-mcp/get-environment-info',
	'webmastery-site-toolkit-for-mcp/get-user',
	'webmastery-site-toolkit-for-mcp/list-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/list-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/list-media',
	'webmastery-site-toolkit-for-mcp/list-users',
	'webmastery-site-toolkit-for-mcp/security-audit',
	'webmastery-site-toolkit-for-mcp/update-cpt-mcp-book',
	'webmastery-site-toolkit-for-mcp/update-cpt-mcp-case-study',
	'webmastery-site-toolkit-for-mcp/update-page',
	'webmastery-site-toolkit-for-mcp/update-post',
	'webmastery-site-toolkit-for-mcp/upload-image',
);

foreach ( $required_failure_cases as $ability ) {
	if ( empty( $summary[ $ability ]['failure'] ) ) {
		$errors[] = "{$ability} must keep at least one negative permission/security manifest case.";
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
			&& $expect === ( $case['expect'] ?? '' ) && ( null === $code || $code === ( $case['expect_error_code'] ?? '' ) ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$errors[] = "upload-image must retain its {$label} manifest scenario with the expected role and result.";
	}
}

if ( $errors ) {
	webmastery_mcp_security_qa_fail( $errors );
}

printf(
	"PASS security QA policy: %d required negative-case abilities, sensitive-field absence assertions, and permission callback scan\n",
	count( $required_failure_cases )
);
