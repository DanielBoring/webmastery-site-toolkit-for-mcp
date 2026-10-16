<?php

declare(strict_types=1);

// Offline regressions for scripts/release-checks-gate.php using a mocked API.

require_once __DIR__ . '/release-checks-gate.php';

const CHECKS_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function checks_run(int $id, string $name, string $conclusion = 'success', string $completed = '2026-10-14T10:00:00Z', array $overrides = array()): array {
	return array_merge(array(
		'id'           => $id,
		'name'         => $name,
		'check_suite'  => array('id' => checks_suite($name)),
		'head_sha'     => CHECKS_SHA,
		'status'       => 'completed',
		'conclusion'   => $conclusion,
		'completed_at' => $completed,
		'app'          => array('slug' => 'github-actions'),
	), $overrides);
}

/** Each required check has its own workflow run and check suite in the fixture. */
function checks_suite(string $name): int {
	return 500 + (int) array_search($name, array_keys(release_checks_required()), true);
}

function checks_workflow_runs(array $overrides = array()): array {
	$runs = array();
	foreach (release_checks_required() as $name => $workflow) {
		$runs[] = array_merge(array(
			'id'             => 900 + count($runs),
			'check_suite_id' => checks_suite($name),
			'path'           => '.github/workflows/' . $workflow,
			'head_branch'    => 'main',
			'head_sha'       => CHECKS_SHA,
			'event'          => 'schedule',
		), $overrides[$name] ?? array());
	}
	return $runs;
}

function checks_green(): array {
	$runs = array();
	foreach (array_keys(release_checks_required()) as $index => $name) {
		$runs[] = checks_run($index + 1, $name);
	}
	return $runs;
}

function checks_api(array $runs, ?int $total = null, ?array $workflow_runs = null): callable {
	$workflow_runs ??= checks_workflow_runs();
	return static function (string $path) use ($runs, $total, $workflow_runs): array {
		$base = 'repos/' . RELEASE_CHECKS_REPOSITORY;
		if ($base . '/actions/runs?head_sha=' . CHECKS_SHA . '&per_page=100&page=1' === $path) {
			return array('total_count' => count($workflow_runs), 'workflow_runs' => $workflow_runs);
		}
		release_require($base . '/commits/' . CHECKS_SHA . '/check-runs?per_page=100&page=1' === $path, 'Unexpected API path: ' . $path);
		return array('total_count' => $total ?? count($runs), 'check_runs' => $runs);
	};
}

function checks_problems(array $runs, ?int $total = null, ?array $workflow_runs = null): array {
	return release_checks_problems(RELEASE_CHECKS_REPOSITORY, CHECKS_SHA, checks_api($runs, $total, $workflow_runs));
}

$count = 0;
$test = static function (string $name, callable $body) use (&$count): void {
	$body();
	++$count;
	echo "PASS {$name}\n";
};
$blocked = static function (array $runs, string $expected): void {
	$problems = checks_problems($runs);
	release_require(1 === count($problems), 'Expected exactly one problem, got: ' . json_encode($problems));
	release_require(str_contains($problems[0], $expected), 'Problem does not mention ' . $expected . ': ' . $problems[0]);
};

$test('all five gates succeeded on the commit', static function (): void {
	release_require(array() === checks_problems(checks_green()), 'Green commit was blocked.');
});
$test('missing package QA names the workflow to dispatch', static function () use ($blocked): void {
	$runs = array_values(array_filter(checks_green(), static fn($run) => '05 - Release Package QA / package' !== $run['name']));
	$blocked($runs, 'gh workflow run 05-release-package-qa.yml --ref main');
});
$test('missing floor run names the workflow to dispatch', static function () use ($blocked): void {
	$runs = array_values(array_filter(checks_green(), static fn($run) => '06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0' !== $run['name']));
	$blocked($runs, 'gh workflow run 06-minimum-runtime-qa.yml --ref main');
});
foreach (array('skipped', 'failure', 'cancelled', 'neutral', 'timed_out') as $conclusion) {
	$test("a {$conclusion} producer job does not count", static function () use ($blocked, $conclusion): void {
		$runs = checks_green();
		$runs[3]['conclusion'] = $conclusion;
		$blocked($runs, '"' . $conclusion . '", not success');
	});
}
$test('a later failure overrides an earlier success', static function () use ($blocked): void {
	$runs = checks_green();
	$runs[] = checks_run(99, '02 - Unit Tests', 'failure', '2026-10-14T11:00:00Z');
	$blocked($runs, '"02 - Unit Tests" latest main result');
});
$test('a later success overrides an earlier failure', static function (): void {
	$runs = checks_green();
	$runs[1] = checks_run(2, '02 - Unit Tests', 'failure', '2026-10-14T09:00:00Z');
	$runs[] = checks_run(99, '02 - Unit Tests', 'success', '2026-10-14T11:00:00Z');
	release_require(array() === checks_problems($runs), 'Later success should release.');
});
$test('in-progress runs are not evidence', static function () use ($blocked): void {
	$runs = checks_green();
	$runs[0] = checks_run(1, '01 - Static QA', 'success', '2026-10-14T10:00:00Z', array('status' => 'in_progress', 'conclusion' => null));
	$blocked($runs, '"01 - Static QA" has not completed');
});
$test('non-Actions apps and other commits are not evidence', static function () use ($blocked): void {
	$runs = checks_green();
	$runs[2]['app']['slug'] = 'some-other-app';
	$blocked($runs, '"03-04 - Docker QA" has not completed');
	$runs = checks_green();
	$runs[2]['head_sha'] = str_repeat('b', 40);
	$blocked($runs, '"03-04 - Docker QA" has not completed');
});
$test('pull-request runs on the same commit are not main evidence', static function () use ($blocked): void {
	$workflow_runs = checks_workflow_runs(array('05 - Release Package QA / package' => array('event' => 'pull_request', 'head_branch' => 'feature')));
	$problems = checks_problems(checks_green(), null, $workflow_runs);
	release_require(1 === count($problems) && str_contains($problems[0], '"05 - Release Package QA / package" has not completed on main'), 'PR run counted: ' . json_encode($problems));
});
$test('only main push, schedule and dispatch runs count', static function (): void {
	foreach (array('push', 'schedule', 'workflow_dispatch') as $event) {
		$workflow_runs = checks_workflow_runs(array('03-04 - Docker QA' => array('event' => $event)));
		release_require(array() === checks_problems(checks_green(), null, $workflow_runs), $event . ' main run was rejected.');
	}
	foreach (array('pull_request', 'pull_request_target', 'merge_group') as $event) {
		$workflow_runs = checks_workflow_runs(array('03-04 - Docker QA' => array('event' => $event)));
		release_require(1 === count(checks_problems(checks_green(), null, $workflow_runs)), $event . ' run was accepted.');
	}
});
$test('a same-named job in another workflow or branch is not evidence', static function (): void {
	foreach (array(
		array('path' => '.github/workflows/some-other.yml'),
		array('head_branch' => 'release-candidate'),
		array('head_sha' => str_repeat('b', 40)),
	) as $override) {
		$workflow_runs = checks_workflow_runs(array('06 - Minimum Runtime QA / WordPress 6.9 PHP 8.0' => $override));
		$problems = checks_problems(checks_green(), null, $workflow_runs);
		release_require(1 === count($problems) && str_contains($problems[0], 'gh workflow run 06-minimum-runtime-qa.yml'), 'Foreign run counted: ' . json_encode($override));
	}
	$runs = checks_green();
	unset($runs[2]['check_suite']);
	release_require(1 === count(checks_problems($runs)), 'Check without a suite counted.');
});
$test('unrelated check names are ignored', static function (): void {
	$runs = checks_green();
	$runs[] = checks_run(50, 'Workflow lint', 'failure');
	release_require(array() === checks_problems($runs), 'Unrelated check blocked release.');
});
$test('invalid inputs and inconsistent pagination fail closed', static function (): void {
	foreach (array(
		static fn() => release_checks_problems('someone/else', CHECKS_SHA, checks_api(checks_green())),
		static fn() => release_checks_problems(RELEASE_CHECKS_REPOSITORY, 'not-a-sha', checks_api(checks_green())),
		static fn() => checks_problems(checks_green(), 99),
		static fn() => checks_problems(array_merge(checks_green(), array(checks_run(1, '01 - Static QA')))),
		static fn() => checks_problems(array(checks_run(1, '01 - Static QA', 'success', 'yesterday'))),
	) as $index => $call) {
		$threw = false;
		try {
			$call();
		} catch (RuntimeException $error) {
			$threw = true;
		}
		release_require($threw, 'Case ' . $index . ' did not fail closed.');
	}
});
echo "PASS {$count} release checks gate tests\n";
