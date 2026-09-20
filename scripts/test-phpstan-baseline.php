<?php

declare(strict_types=1);

$repository = dirname(__DIR__);
$work = $repository . '/build/phpstan-tests-' . bin2hex(random_bytes(6));
if (!mkdir($work, 0777, true)) {
	throw new RuntimeException('Could not create PHPStan fixture directory.');
}

function phpstan_test_remove(string $path): void {
	if (is_dir($path) && !is_link($path)) {
		foreach (new FilesystemIterator($path) as $file) {
			phpstan_test_remove($file->getPathname());
		}
		if (!rmdir($path)) {
			throw new RuntimeException("Could not remove fixture directory: {$path}");
		}
	} elseif (!unlink($path)) {
		throw new RuntimeException("Could not remove fixture file: {$path}");
	}
}

register_shutdown_function(static function () use ($work): void {
	phpstan_test_remove($work);
});

function phpstan_test_require(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function phpstan_test_write(string $path, string $contents): void {
	phpstan_test_require(strlen($contents) === file_put_contents($path, $contents), "Could not write fixture: {$path}");
}

function phpstan_test_run(array $arguments, int $expected_status): string {
	global $repository;
	$command = array_merge(
		array(PHP_BINARY, $repository . '/vendor/phpstan/phpstan/phpstan'),
		$arguments,
		array('--no-interaction', '--no-ansi', '--memory-limit=2G')
	);
	$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $repository);
	phpstan_test_require(is_resource($process), 'Could not start PHPStan.');
	$output = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	phpstan_test_require($expected_status === $status, "Expected PHPStan exit {$expected_status}, got {$status}:\n{$output}\n{$errors}");
	return $output;
}

function phpstan_test_analyse(string $configuration, array $paths, int $status): array {
	$output = phpstan_test_run(
		array_merge(array('analyse', '--configuration=' . $configuration, '--no-progress', '--error-format=json'), $paths),
		$status
	);
	$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	phpstan_test_require(is_array($result) && isset($result['totals'], $result['files'], $result['errors']), 'Missing PHPStan JSON diagnostics.');
	return $result;
}

function phpstan_test_identifiers(array $result): array {
	$identifiers = array();
	foreach ($result['files'] as $file) {
		foreach ($file['messages'] as $message) {
			$identifiers[] = $message['identifier'];
		}
	}
	sort($identifiers);
	return $identifiers;
}

$configuration = $repository . '/phpstan.neon.dist';
require_once $repository . '/vendor/autoload.php';
// Use the public container API: dump-parameters formats regex escapes through the console formatter.
$factory = new PHPStan\DependencyInjection\ContainerFactory($repository);
$parameters = $factory->create($work . '/container', array($configuration), array())->getParameters();
phpstan_test_require(5 === $parameters['level'], 'The repository must analyse at level 5.');
phpstan_test_require(80000 === $parameters['phpVersion'], 'Analysis must target PHP 8.0.');
phpstan_test_require(true === $parameters['reportUnmatchedIgnoredErrors'], 'Unmatched ignores must fail.');
phpstan_test_require(false === $parameters['treatPhpDocTypesAsCertain'], 'Preserve the existing PHPDoc certainty policy.');
phpstan_test_require(array() === $parameters['excludePaths']['analyse'] && array() === $parameters['excludePaths']['analyseAndScan'], 'Do not exclude production paths.');

$normalize = static fn(string $path): string => str_replace('\\', '/', $path);
$expected_paths = array($normalize($repository . '/webmastery-site-toolkit-for-mcp.php'), $normalize($repository . '/includes'));
phpstan_test_require($expected_paths === array_map($normalize, $parameters['paths']), 'Analyse the entry point and every include.');
$baseline_count = 0;
foreach ($parameters['ignoreErrors'] as $ignore) {
	phpstan_test_require(is_array($ignore), 'Baseline ignores must not be global patterns.');
	$keys = array_keys($ignore);
	sort($keys);
	phpstan_test_require(array('count', 'identifier', 'message', 'path') === $keys, 'Every ignore needs only message, identifier, count, and path.');
	phpstan_test_require(is_int($ignore['count']) && $ignore['count'] > 0, 'Baseline counts must be positive integers.');
	phpstan_test_require(is_string($ignore['identifier']) && '' !== $ignore['identifier'], 'Baseline identifiers must be explicit.');
	phpstan_test_require(str_starts_with($ignore['message'], '#^') && str_ends_with($ignore['message'], '$#'), 'Baseline messages must be anchored.');
	$path = $normalize($ignore['path']);
	phpstan_test_require(
		str_starts_with($path, $normalize($repository . '/includes/')) && is_file($ignore['path']) && !strpbrk($path, '*?[]'),
		'Baseline paths must name individual production files.'
	);
	$baseline_count += $ignore['count'];
}
printf("PASS repository configuration and narrow baseline: %d entries, %d errors\n", count($parameters['ignoreErrors']), $baseline_count);

// Analyse a directory, not individual CLI files: PHPStan skips unmatched-ignore checks for file-only runs.
$source = $work . '/src';
phpstan_test_require(mkdir($source), 'Could not create fixture source directory.');
$fixture = $source . '/existing.php';
$fresh = $source . '/new.php';
$baseline = $work . '/baseline.neon';
$fixture_configuration = $work . '/phpstan.neon';
$existing_error = "echo WSTM_PHPSTAN_BASELINE_FIXTURE;\n";
phpstan_test_write($fixture, "<?php\n" . str_repeat($existing_error, 2));
phpstan_test_write($fresh, "<?php\n");
$initial = phpstan_test_analyse($configuration, array($source), 1);
phpstan_test_require(array('constant.notFound', 'constant.notFound') === phpstan_test_identifiers($initial), 'The unbaselined control must report exactly two errors.');
phpstan_test_run(
	array('analyse', '--configuration=' . $configuration, '--no-progress', '--generate-baseline=' . $baseline, $source),
	0
);
$quote = static fn(string $value): string => "'" . str_replace("'", "''", $normalize($value)) . "'";
phpstan_test_write($fixture_configuration, "includes:\n\t- " . $quote($configuration) . "\n\t- " . $quote($baseline) . "\n");
$clean = phpstan_test_analyse($fixture_configuration, array($source), 0);
phpstan_test_require(0 === $clean['totals']['file_errors'] && array() === $clean['errors'], 'Only the generated fixture debt should be suppressed.');
echo "PASS generated baseline suppresses only existing fixture debt\n";

phpstan_test_write($fresh, <<<'PHP'
<?php
function phpstan_fixture_accept(int $value): void {}
phpstan_fixture_accept('wrong');
function phpstan_fixture_return(): int { return 'wrong'; }
echo WSTM_PHPSTAN_BASELINE_FIXTURE;
PHP
);
$regression = phpstan_test_analyse($fixture_configuration, array($source), 1);
phpstan_test_require(
	array('argument.type', 'constant.notFound', 'return.type') === phpstan_test_identifiers($regression) && array() === $regression['errors'],
	'New argument/return errors and the same baselined error in a different path must fail.'
);
echo "PASS new level-5 argument, return, and different-path errors fail\n";
phpstan_test_write($fresh, "<?php\n");

phpstan_test_write($fixture, "<?php\n" . str_repeat($existing_error, 2) . "echo WSTM_PHPSTAN_DIFFERENT_FIXTURE;\n");
$different = phpstan_test_analyse($fixture_configuration, array($source), 1);
phpstan_test_require(array('constant.notFound') === phpstan_test_identifiers($different), 'The same identifier with a different message must fail.');
echo "PASS same-path/same-identifier error with a different message fails\n";

phpstan_test_write($fixture, "<?php\n" . str_repeat($existing_error, 3));
$excess = phpstan_test_analyse($fixture_configuration, array($source), 1);
phpstan_test_require(str_contains(json_encode($excess, JSON_THROW_ON_ERROR), '3 times'), 'Exceeding the baseline count must be diagnosed.');
echo "PASS increasing an existing error count fails\n";

phpstan_test_write($fixture, "<?php\n" . $existing_error);
$stale_count = phpstan_test_analyse($fixture_configuration, array($source), 1);
phpstan_test_require(str_contains(json_encode($stale_count, JSON_THROW_ON_ERROR), 'only 1 time'), 'A partially fixed baseline count must fail until ratcheted.');
echo "PASS partially stale baseline count fails\n";
phpstan_test_run(
	array('analyse', '--configuration=' . $configuration, '--no-progress', '--generate-baseline=' . $baseline, $source),
	0
);
phpstan_test_analyse($fixture_configuration, array($source), 0);
echo "PASS lowering the fixed error count restores green analysis\n";

phpstan_test_write($fixture, "<?php\n");
$stale_entry = phpstan_test_analyse($fixture_configuration, array($source), 1);
phpstan_test_require(str_contains(json_encode($stale_entry, JSON_THROW_ON_ERROR), 'was not matched'), 'A fully fixed baseline entry must fail until removed.');
echo "PASS unmatched baseline entry fails\n";
phpstan_test_write($baseline, "parameters:\n\tignoreErrors: []\n");
phpstan_test_analyse($fixture_configuration, array($source), 0);
echo "PASS removing fixed baseline debt restores green analysis\n";
