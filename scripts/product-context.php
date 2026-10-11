<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI || 4 !== count($argv)) {
	throw new RuntimeException('CLI context creation requires identity kind, SHA256 and actual runtime lane.');
}
require_once dirname(__DIR__) . '/tests/e2e/product-runtime-context.php';

$data = [
	'schema' => 'wstm-product-context-v1',
	'run' => getenv('WSTM_PRODUCT_RUN'),
	'token' => getenv('WSTM_PRODUCT_TOKEN'),
	'project' => getenv('COMPOSE_PROJECT_NAME'),
	'url' => 'http://localhost',
	'artifact_root' => '/qa-artifacts',
	'plugin_root' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp',
	'wordpress_root' => '/var/www/html',
	'identity_kind' => $argv[1],
	'identity_sha256' => $argv[2],
	'uid' => 33,
	'php_floor' => $argv[3],
];
// These are expected container observations, not facts about this host interpreter.
Wstm167_Product_Context::validate($data, (string) $data['run'], (string) $data['token'], 33, $argv[3]);
echo json_encode($data, JSON_THROW_ON_ERROR);
