<?php

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

try {
	$root = $argv[3] ?? dirname(__DIR__);
	$metadata = release_validate_package($root, $argv[1] ?? '', $argv[2] ?? '');
	echo "PASS release package validation for {$metadata['version']}\n";
} catch (Throwable $error) {
	fwrite(STDERR, "ERROR {$error->getMessage()}\n");
	exit(1);
}
