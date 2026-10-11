<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tests/e2e/product-runtime-context.php';

final class Wstm_Product_Summary {
	public static function runtime(string $context_bytes, array $runtime): array {
		$context = Wstm167_Product_Context::decode($context_bytes);
		Wstm167_Product_Context::validate($context, $context['run'], $context['token'], 33, $context['php_floor']);
		if (array_keys($runtime) !== [
			'schema', 'run', 'project', 'context_sha256', 'token_sha256', 'uid', 'php',
			'php_floor', 'plugin_root', 'loaded_posts_sha256',
		] || $runtime['schema'] !== 'wstm-product-web-observation-v1' ||
			$runtime['run'] !== $context['run'] || $runtime['project'] !== $context['project'] ||
			$runtime['context_sha256'] !== hash('sha256', $context_bytes) ||
			$runtime['token_sha256'] !== hash('sha256', $context['token']) ||
			$runtime['uid'] !== 33 || $runtime['php_floor'] !== $context['php_floor'] ||
			!is_string($runtime['php']) ||
			!preg_match('/^' . preg_quote($context['php_floor'], '/') . '\.[0-9]+(?:[-+].*)?$/D', $runtime['php']) ||
			$runtime['plugin_root'] !== $context['plugin_root'] ||
			!is_string($runtime['loaded_posts_sha256']) ||
			!preg_match('/^[a-f0-9]{64}$/D', $runtime['loaded_posts_sha256'])) {
			throw new RuntimeException('Product HTTP observation does not match the retained context.');
		}
		return $context;
	}

	public static function verify(string $context_bytes, array $records): array {
		if (array_keys($records) !== ['runtime', 'untrusted', 'untrusted-live', 'bounded', 'bounded-live'] ||
			!is_array($records['runtime'])) {
			throw new RuntimeException('Product summary requires every selected observation.');
		}
		$context = self::runtime($context_bytes, $records['runtime']);
		$expected = [
			'untrusted' => ['untrusted', 'records-verified-live-absence-unresolved', 298],
			'untrusted-live' => ['untrusted', 'live-absence-verified', 1],
			'bounded' => ['bounded', 'records-verified-live-absence-unresolved', 1461],
			'bounded-live' => ['bounded', 'live-absence-verified', 2],
		];
		foreach ($expected as $name => $values) {
			$record = $records[$name];
			if (!is_array($record) || array_keys($record) !== ['stage', 'status', 'count', 'lane', 'uid'] ||
				$record['stage'] !== $values[0] || $record['status'] !== $values[1] ||
				$record['count'] !== $values[2] || $record['lane'] !== $context['php_floor'] ||
				$record['uid'] !== 33) {
				throw new RuntimeException('Product records or live absence are incomplete.');
			}
		}
		return [
			'schema' => 'wstm-product-qa-summary-v1',
			'status' => 'passed',
			'lane' => $context['php_floor'],
			'uid' => 33,
			'untrusted_cases' => 298,
			'bounded_observations' => 1461,
		];
	}

	public static function read(string $path): string {
		clearstatcache(true, $path);
		$stat = lstat($path);
		if (false === $stat || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 ||
			$stat['nlink'] !== 1 || $stat['size'] < 1 || $stat['size'] > 4096) {
			throw new RuntimeException('Selected product summary input is unavailable or oversized.');
		}
		$bytes = file_get_contents($path, false, null, 0, 4097);
		clearstatcache(true, $path);
		$after = lstat($path);
		if (!is_string($bytes) || strlen($bytes) !== $stat['size'] || false === $after ||
			$after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino'] ||
			$after['size'] !== $stat['size'] || $after['mtime'] !== $stat['mtime']) {
			throw new RuntimeException('Selected product summary input changed during acquisition.');
		}
		return $bytes;
	}
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	if ('cli' !== PHP_SAPI || count($argv) !== 2 || !is_dir($argv[1]) || is_link($argv[1])) {
		throw new RuntimeException('Product summary requires the selected private product directory.');
	}
	$root = $argv[1];
	$context = Wstm_Product_Summary::read($root . '/context.private.json');
	$records = [];
	foreach (['runtime', 'untrusted', 'untrusted-live', 'bounded', 'bounded-live'] as $name) {
		$records[$name] = json_decode(Wstm_Product_Summary::read($root . '/' . $name . '.verified.private.json'), true, 16, JSON_THROW_ON_ERROR);
	}
	echo json_encode(Wstm_Product_Summary::verify($context, $records), JSON_THROW_ON_ERROR) . "\n";
}
