<?php

declare(strict_types=1);

function docker_qa_safe_summary(array $input): array {
	// Only counts are published: transport errors can contain credentials or response bodies.
	$summary = array();
	foreach (array('registered_abilities', 'covered_abilities', 'test_cases', 'negative_cases', 'passed', 'failed') as $key) {
		if (isset($input[$key]) && is_int($input[$key]) && $input[$key] >= 0) {
			$summary[$key] = $input[$key];
		}
	}
	$summary['summary_available'] = isset($summary['passed'], $summary['failed']);
	return $summary;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	if (count($argv) !== 4 || !in_array($argv[3], array('contract', 'transport'), true)) {
		fwrite(STDERR, "Usage: php scripts/docker-qa-summary.php INPUT OUTPUT contract|transport\n");
		exit(1);
	}
	$input = is_file($argv[1]) ? json_decode((string) file_get_contents($argv[1]), true) : null;
	$summary = docker_qa_safe_summary(is_array($input) ? $input : array());
	$json = json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
	if (!is_dir(dirname($argv[2]))) {
		mkdir(dirname($argv[2]), 0777, true);
	}
	if (false === file_put_contents($argv[2], $json)) {
		exit(1);
	}
	$step_summary = getenv('GITHUB_STEP_SUMMARY');
	if (false !== $step_summary && '' !== $step_summary) {
		file_put_contents($step_summary, "## Docker QA: {$argv[3]}\n\n```json\n{$json}```\n", FILE_APPEND);
	}
	echo $json;
	exit($summary['summary_available'] ? 0 : 1);
}
