<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI || 2 !== count($argv) || !in_array($argv[1], ['acquire', 'retire'], true)) {
	throw new RuntimeException('Owned product marker requires an exact CLI lifecycle operation.');
}
require_once __DIR__ . '/product-runtime-context.php';
$context = Wstm167_Product_Context::open();
$data = $context->data();
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
if (!function_exists('wp_get_abilities') || !is_array(wp_get_abilities())) {
	throw new RuntimeException('Toolkit ability registration is unavailable.');
}
$expected = ['run' => $data['run'], 'token' => $data['token'], 'project' => $data['project']];
$missing = new stdClass();
$previous = get_option('wstm167_product_context', $missing);
if ('acquire' === $argv[1]) {
	if ($previous !== $missing || !add_option('wstm167_product_context', $expected, '', false) ||
		get_option('wstm167_product_context') !== $expected) {
		throw new RuntimeException('Product database marker was occupied or could not be created.');
	}
	$context->wordpress();
} else {
	$context->wordpress();
	if ($previous !== $expected || !delete_option('wstm167_product_context') ||
		get_option('wstm167_product_context', $missing) !== $missing) {
		throw new RuntimeException('Owned product marker could not be retired.');
	}
}
$context->recheck();
echo 'Owned product marker ' . $argv[1] . " completed.\n";
