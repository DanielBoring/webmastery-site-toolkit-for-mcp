<?php

declare(strict_types=1);

require_once __DIR__ . '/compatibility-baselines.php';

function webmastery_mcp_compatibility_matrix( array $baseline, array $latest ): array {
	$lane = static function ( string $label, string $wp, string $adapter, string $digest, string $php = '8.2', string $mysql = '8.0.36', string $policy = 'pinned' ): array {
		return array(
			'label'              => $label,
			'wordpress-image'    => "wordpress:{$wp}-php{$php}-apache",
			'mysql-image'        => "mysql:{$mysql}",
			'mcp-adapter-zip'    => "https://github.com/WordPress/mcp-adapter/releases/download/v{$adapter}/mcp-adapter.zip",
			'mcp-adapter-sha256' => $digest,
			'dependency-policy' => $policy,
		);
	};
	return array(
		'include' => array(
			$lane( 'supported-floor', '6.9', $baseline['mcp_adapter'], $baseline['mcp_adapter_sha256'], '8.1' ),
			$lane( 'current-baseline', $baseline['wordpress'], $baseline['mcp_adapter'], $baseline['mcp_adapter_sha256'] ),
			$lane( 'latest-mcp-adapter', $baseline['wordpress'], $latest['mcp_adapter'], $latest['mcp_adapter_sha256'] ),
			$lane( 'latest-wordpress', $latest['wordpress'], $baseline['mcp_adapter'], $baseline['mcp_adapter_sha256'] ),
			$lane( 'combined-latest', $latest['wordpress'], $latest['mcp_adapter'], $latest['mcp_adapter_sha256'] ),
			$lane( 'php-8-4', $baseline['wordpress'], $baseline['mcp_adapter'], $baseline['mcp_adapter_sha256'], '8.4' ),
			$lane( 'mysql-8-4', $baseline['wordpress'], $baseline['mcp_adapter'], $baseline['mcp_adapter_sha256'], '8.2', '8.4' ),
			$lane( 'latest-seo', $latest['wordpress'], $latest['mcp_adapter'], $latest['mcp_adapter_sha256'], '8.2', '8.0.36', 'latest-seo' ),
		),
	);
}

if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	try {
		echo json_encode(
			webmastery_mcp_compatibility_matrix(
				webmastery_mcp_read_baselines( $argv[1] ?? '' ),
				webmastery_mcp_read_baselines( $argv[2] ?? '' )
			),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
		) . PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
