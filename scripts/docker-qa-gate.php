<?php

declare(strict_types=1);

function docker_qa_gate_passes(string $detector, string $should_run, string $contract, string $transport): bool {
	if ('success' !== $detector) {
		return false;
	}

	if ('false' === $should_run) {
		return 'skipped' === $contract && 'skipped' === $transport;
	}

	return 'true' === $should_run && 'success' === $contract && 'success' === $transport;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	$passed = count($argv) === 5 && docker_qa_gate_passes($argv[1], $argv[2], $argv[3], $argv[4]);
	fwrite($passed ? STDOUT : STDERR, $passed ? "Docker QA gate passed.\n" : "Docker QA gate failed: detector or runtime checks did not complete as expected.\n");
	exit($passed ? 0 : 1);
}
