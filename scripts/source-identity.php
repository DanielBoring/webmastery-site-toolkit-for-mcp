<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI || 1 !== count($argv)) {
	throw new RuntimeException('Source identity has no caller-selected path.');
}
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		static function (SplFileInfo $entry): bool {
			$name = $entry->getFilename();
			if (in_array($name, ['.git', 'vendor', 'build', 'e2e-artifacts', '.phpunit.result.cache'], true) ||
				0 === strpos($name, 'e2e-artifacts-')) {
				return false;
			}
			if ($entry->isLink()) {
				throw new RuntimeException('Source inventory refuses symbolic links.');
			}
			return true;
		}
	)
);
$records = [];
foreach ($iterator as $entry) {
	if (!$entry->isFile()) {
		throw new RuntimeException('Source inventory requires regular files.');
	}
	$path = $entry->getPathname();
	$relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
	$before = lstat($path);
	$hash = hash_file('sha256', $path);
	clearstatcache(true, $path);
	$after = lstat($path);
	if (false === $before || false === $hash || false === $after ||
		$before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] ||
		$before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
		throw new RuntimeException('Source changed during identity acquisition.');
	}
	$records[$relative] = $relative . "\0" . $before['size'] . "\0" . $hash . "\n";
}
if ([] === $records) {
	throw new RuntimeException('Source inventory is empty.');
}
ksort($records, SORT_STRING);
echo hash('sha256', implode('', $records));
