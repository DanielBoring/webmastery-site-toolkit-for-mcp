<?php

declare(strict_types=1);

function wstm_test_legacy_artifact_output_source( string $runner, string $historical ): string {
	$require = "require_once __DIR__ . '/error-contract-assertions.php';";
	$changes = array( $require => $require . "\nrequire_once __DIR__ . '/legacy-artifacts.php';" );
	if ( 'scheduling' === $runner ) {
		$changes["\$artifact = getenv( 'WSTM113_ARTIFACT' ) ?: dirname( __DIR__, 2 ) . '/e2e-artifacts/scheduling-regression.json';"]
			= "\$artifact = getenv( 'WSTM113_ARTIFACT' ) ?: Wstm_E2E_Artifacts::path( dirname( __DIR__, 2 ) . '/e2e-artifacts/scheduling-regression.json' );";
	} elseif ( 'taxonomy' === $runner ) {
		$changes["\t\$path = WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/e2e-artifacts/taxonomy-write-summary.json';"]
			= "\t\$path = Wstm_E2E_Artifacts::path( WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/e2e-artifacts/taxonomy-write-summary.json' );";
		$changes["\tif ( false === file_put_contents( \$path, wp_json_encode( \$summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . \"\\n\" ) ) {"]
			= "\t\$json = wp_json_encode( \$summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );\n"
				. "\tif ( false === \$json || strlen( \$json ) + 1 !== file_put_contents( \$path, \$json . \"\\n\" ) ) {";
	} else {
		throw new RuntimeException( 'Unsupported legacy artifact calibration.' );
	}
	foreach ( $changes as $before => $after ) {
		$historical = str_replace( $before, $after, $historical, $count );
		if ( 1 !== $count ) { throw new RuntimeException( 'Legacy artifact calibration requires exactly one approved output site.' ); }
	}
	return $historical;
}
