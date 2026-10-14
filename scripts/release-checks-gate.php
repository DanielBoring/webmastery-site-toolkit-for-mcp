<?php

declare(strict_types=1);

// Release gate: the tagged commit must already have passed QA on main.
// A release promotes evidence from main instead of re-running it. Read-only.

require_once __DIR__ . '/release-recovery.php';

const RELEASE_CHECKS_REPOSITORY = 'DanielBoring/webmastery-site-toolkit-for-mcp';

/**
 * Check runs that must have succeeded on the tagged commit, with the workflow
 * to dispatch on main when one is missing. Aggregate gates may pass on a
 * detector skip; the two producer jobs must actually have run.
 */
function release_checks_required(): array {
	return array(
		'1 - Static QA'                           => 'coding-standards.yml',
		'2 - Unit Tests'                          => 'unit-tests.yml',
		'Docker QA gate'                          => 'e2e-qa.yml',
		'5 - Release Package QA'                  => 'release-package-qa.yml',
		'WordPress 6.9 / PHP 8.0 product runtime' => 'minimum-runtime-qa.yml',
	);
}

/** Events that produce main-branch evidence; pull-request runs on the same commit do not count. */
const RELEASE_CHECKS_EVENTS = array('push', 'schedule', 'workflow_dispatch');

/**
 * Returns a list of problems; an empty list means the commit may be released.
 * A check run counts only when its workflow run is the expected workflow file,
 * on main, from a push, schedule or dispatch, for exactly this commit.
 */
function release_checks_problems(string $repository, string $sha, callable $api): array {
	release_require(RELEASE_CHECKS_REPOSITORY === $repository, 'Release checks are restricted to the release repository.');
	recovery_sha($sha);
	$base = 'repos/' . $repository;
	// Map each check suite to the workflow run that produced it.
	$suites = array();
	foreach (recovery_collection($api, $base . '/actions/runs?head_sha=' . $sha, 'workflow_runs') as $run) {
		$suite = $run['check_suite_id'] ?? null;
		if (is_int($suite) || is_string($suite)) {
			$suites[recovery_id($suite)] = $run;
		}
	}
	$runs = recovery_collection($api, $base . '/commits/' . $sha . '/check-runs', 'check_runs');
	$latest = array();
	foreach ($runs as $run) {
		$name = $run['name'] ?? null;
		if (!is_string($name) || !array_key_exists($name, release_checks_required())) {
			continue;
		}
		if ('github-actions' !== ($run['app']['slug'] ?? null) || $sha !== ($run['head_sha'] ?? null) || 'completed' !== ($run['status'] ?? null)) {
			continue;
		}
		$suite = $run['check_suite']['id'] ?? null;
		$workflow = (is_int($suite) || is_string($suite)) ? ($suites[(string) $suite] ?? null) : null;
		if (!is_array($workflow)
			|| '.github/workflows/' . release_checks_required()[$name] !== ($workflow['path'] ?? null)
			|| 'main' !== ($workflow['head_branch'] ?? null)
			|| !in_array($workflow['event'] ?? null, RELEASE_CHECKS_EVENTS, true)
			|| $sha !== ($workflow['head_sha'] ?? null)) {
			continue;
		}
		$completed = recovery_time($run['completed_at'] ?? null);
		if (!isset($latest[$name]) || $completed >= $latest[$name]['time']) {
			$latest[$name] = array('time' => $completed, 'conclusion' => $run['conclusion'] ?? null);
		}
	}
	$problems = array();
	foreach (release_checks_required() as $name => $workflow) {
		if (!isset($latest[$name])) {
			$problems[] = sprintf('"%s" has not completed on main for %s. Dispatch it on main while main is at this commit: gh workflow run %s --ref main', $name, $sha, $workflow);
		} elseif ('success' !== $latest[$name]['conclusion']) {
			$problems[] = sprintf('"%s" latest main result for %s is "%s", not success. Fix it, or re-run %s on main.', $name, $sha, (string) $latest[$name]['conclusion'], $workflow);
		}
	}
	return $problems;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	if (3 !== count($argv)) {
		fwrite(STDERR, "Usage: php scripts/release-checks-gate.php OWNER/REPO COMMIT_SHA\n");
		exit(78);
	}
	try {
		$problems = release_checks_problems($argv[1], $argv[2], 'recovery_api');
	} catch (Throwable $error) {
		fwrite(STDERR, 'Release checks gate failed: ' . $error->getMessage() . "\n");
		exit(1);
	}
	if (array() !== $problems) {
		fwrite(STDERR, "Release blocked; the tagged commit lacks passing QA on main:\n- " . implode("\n- ", $problems) . "\n");
		exit(1);
	}
	echo 'PASS release checks: ' . implode(', ', array_keys(release_checks_required())) . ' succeeded on ' . $argv[2] . "\n";
}
