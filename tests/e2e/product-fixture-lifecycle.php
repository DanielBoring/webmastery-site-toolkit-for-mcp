<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
	http_response_code(403);
	exit('CLI only.');
}
if (2 !== $argc || !in_array($argv[1], array('install', 'remove'), true)) {
	throw new RuntimeException('Product fixture lifecycle requires install or remove.');
}
require_once __DIR__ . '/product-runtime-context.php';
$context = Wstm167_Product_Context::open();
$source = __DIR__ . '/product-http-fixture.php';
$target = '/var/www/html/wp-content/mu-plugins/wstm-product-context.php';
if ('install' === $argv[1] && (file_exists($target) || is_link($target))) {
	throw new RuntimeException('Product fixture installation refuses an occupied destination.');
}
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
if (!function_exists('wp_get_abilities') || !is_array(wp_get_abilities())) {
	throw new RuntimeException('Toolkit ability registration is unavailable.');
}
$context->wordpress();
$source_stat = lstat($source);
if (false === $source_stat || is_link($source) || ($source_stat['mode'] & 0170000) !== 0100000 ||
	$source_stat['size'] < 1 || $source_stat['size'] > 65536) {
	throw new RuntimeException('Selected product fixture source is unavailable or oversized.');
}
$bytes = file_get_contents($source, false, null, 0, 65537);
if (false === $bytes || strlen($bytes) !== $source_stat['size']) {
	throw new RuntimeException('Selected product fixture source read was incomplete.');
}
$digest = hash('sha256', $bytes);
if ('install' === $argv[1]) {
	umask(0077);
	$handle = fopen($target, 'xb');
	if (false === $handle) {
		throw new RuntimeException('Exclusive product fixture reservation failed.');
	}
	$offset = 0;
	while ($offset < strlen($bytes)) {
		$written = fwrite($handle, substr($bytes, $offset));
		if (false === $written || 0 === $written) {
			throw new RuntimeException('Product fixture write failed; retained state requires recovery.');
		}
		$offset += $written;
	}
	if (!fflush($handle) || !fclose($handle)) {
		throw new RuntimeException('Product fixture write completion failed.');
	}
}
clearstatcache(true, $target);
$stat = lstat($target);
$target_digest = hash_file('sha256', $target);
if (false === $stat || is_link($target) || ($stat['mode'] & 0170000) !== 0100000 ||
	33 !== $stat['uid'] || 33 !== $stat['gid'] || 1 !== $stat['nlink'] ||
	0600 !== ($stat['mode'] & 0777) || $stat['size'] !== strlen($bytes) ||
	!is_string($target_digest) || !hash_equals($digest, $target_digest)) {
	throw new RuntimeException('Product fixture identity or source bytes changed; retirement refused.');
}
if ('remove' === $argv[1]) {
	if (!unlink($target)) {
		throw new RuntimeException('Owned product fixture removal failed.');
	}
	clearstatcache(true, $target);
	if (file_exists($target) || is_link($target)) {
		throw new RuntimeException('Owned product fixture remains after removal.');
	}
}
$context->recheck();
echo 'Owned product fixture ' . $argv[1] . " completed.\n";
