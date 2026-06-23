<?php

declare(strict_types=1);

$repo_root     = dirname(__DIR__);
$manifest_path = $repo_root . '/tests/e2e/abilities-manifest.json';
$allowed_roles = array(
	'admin'        => true,
	'author'       => true,
	'book_manager' => true,
	'case_manager' => true,
	'editor'       => true,
	'no_role'      => true,
	'subscriber'   => true,
);

function webmastery_mcp_manifest_fail( array $errors ): void {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "ERROR {$error}\n" );
	}

	exit( 1 );
}

function webmastery_mcp_manifest_path( int $index, string $field ): string {
	return "case {$index} {$field}";
}

if ( ! file_exists( $manifest_path ) ) {
	webmastery_mcp_manifest_fail( array( "Missing manifest: {$manifest_path}" ) );
}

$raw = file_get_contents( $manifest_path );
if ( false === $raw ) {
	webmastery_mcp_manifest_fail( array( "Could not read manifest: {$manifest_path}" ) );
}

$manifest = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
	webmastery_mcp_manifest_fail( array( 'Invalid JSON: ' . json_last_error_msg() ) );
}

if ( ! is_array( $manifest ) ) {
	webmastery_mcp_manifest_fail( array( 'Manifest root must be an array.' ) );
}

$errors          = array();
$labels          = array();
$ability_summary = array();

foreach ( $manifest as $index => $case ) {
	$case_number = $index + 1;

	if ( ! is_array( $case ) ) {
		$errors[] = "case {$case_number} must be an object.";
		continue;
	}

	foreach ( array( 'ability', 'label', 'expect' ) as $field ) {
		if ( ! isset( $case[ $field ] ) || ! is_string( $case[ $field ] ) || '' === trim( $case[ $field ] ) ) {
			$errors[] = webmastery_mcp_manifest_path( $case_number, $field ) . ' must be a non-empty string.';
		}
	}

	$ability = (string) ( $case['ability'] ?? '' );
	if ( '' !== $ability && ! str_starts_with( $ability, 'webmastery-site-toolkit-for-mcp/' ) ) {
		$errors[] = webmastery_mcp_manifest_path( $case_number, 'ability' ) . ' must use the webmastery-site-toolkit-for-mcp/ prefix.';
	}

	$label = (string) ( $case['label'] ?? '' );
	if ( '' !== $label ) {
		if ( isset( $labels[ $label ] ) ) {
			$errors[] = "case {$case_number} label duplicates case {$labels[ $label ]}: {$label}";
		}
		$labels[ $label ] = $case_number;
	}

	$expect = (string) ( $case['expect'] ?? '' );
	if ( '' !== $expect && ! in_array( $expect, array( 'success', 'failure' ), true ) ) {
		$errors[] = webmastery_mcp_manifest_path( $case_number, 'expect' ) . ' must be success or failure.';
	}

	$role = (string) ( $case['role'] ?? 'subscriber' );
	if ( ! isset( $allowed_roles[ $role ] ) ) {
		$errors[] = webmastery_mcp_manifest_path( $case_number, 'role' ) . " uses unknown role: {$role}";
	}

	if ( array_key_exists( 'assert_values', $case ) && ! is_array( $case['assert_values'] ) ) {
		$errors[] = webmastery_mcp_manifest_path( $case_number, 'assert_values' ) . ' must be an object.';
	}

	foreach ( array( 'assert_paths', 'assert_contains', 'assert_not_contains' ) as $field ) {
		if ( array_key_exists( $field, $case ) && ! is_array( $case[ $field ] ) ) {
			$errors[] = webmastery_mcp_manifest_path( $case_number, $field ) . ' must be an array.';
		}
	}

	if ( '' !== $ability ) {
		if ( ! isset( $ability_summary[ $ability ] ) ) {
			$ability_summary[ $ability ] = array(
				'success' => 0,
				'failure' => 0,
			);
		}

		if ( isset( $ability_summary[ $ability ][ $expect ] ) ) {
			$ability_summary[ $ability ][ $expect ]++;
		}
	}
}

foreach ( $ability_summary as $ability => $summary ) {
	if ( 0 === $summary['success'] ) {
		$errors[] = "{$ability} must include at least one success case.";
	}
	if ( $summary['failure'] > 0 && 0 === $summary['success'] ) {
		$errors[] = "{$ability} has failure cases but no success case.";
	}
}

if ( $errors ) {
	webmastery_mcp_manifest_fail( $errors );
}

ksort( $ability_summary );

$negative_cases = 0;
foreach ( $ability_summary as $summary ) {
	$negative_cases += $summary['failure'];
}

printf(
	"PASS E2E manifest structure: %d abilities, %d cases, %d negative cases\n",
	count( $ability_summary ),
	count( $manifest ),
	$negative_cases
);
