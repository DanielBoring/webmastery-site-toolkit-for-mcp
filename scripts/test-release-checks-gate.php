<?php

declare(strict_types=1);

// Offline regressions for scripts/release-checks-gate.php using a mocked API.

require_once __DIR__ . '/release-checks-gate.php';

const CHECKS_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function checks_run(int $id, string $name, string $conclusion = 'success', string $completed = '2026-10-14T10:00:00Z', array $overrides = array()): array {
	return array_merge(array(
		'id'           => $id,
		'name'         => $name,
		'head_sha'     => CHECKS_SHA,
		'status'       => 'completed',
		'conclusion'   => $conclusion,
		'completed_at' => $completed,
		'app'          => array('slug' => 'github-actions'),
	), $overrides);
}

function checks_green(): array {
	$runs = array();
	foreach (array_keys(release_checks_required()) as $index => $name) {
		$runs[] = checks_run($index + 1, $name);
	}
	return $runs;
}

function checks_api(array $runs, ?int $total = null): callable {
	return static function (string $path) use ($runs, $total): array {
		release_require('repos/' . RELEASE_CHECKS_REPOSITORY . '/commits/' . CHECKS_SHA . '/check-runs?per_page=100&page=1' === $path, 'Unexpected API path: ' . $path);
		return array('total_count' => $total ?? count($runs), 'check_runs' => $runs);
	};
}

function checks_problems(array $runs, ?int $total = null): array {
	return release_checks_problems(RELEASE_CHECKS_REPOSITORY, CHECKS_SHA, checks_api($runs, $total));
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
	$runs = array_values(array_filter(checks_green(), static fn($run) => '5 - Release Package QA' !== $run['name']));
	$blocked($runs, 'gh workflow run release-package-qa.yml --ref main');
});
$test('missing floor run names the workflow to dispatch', static function () use ($blocked): void {
	$runs = array_values(array_filter(checks_green(), static fn($run) => 'WordPress 6.9 / PHP 8.0 product runtime' !== $run['name']));
	$blocked($runs, 'gh workflow run minimum-runtime-qa.yml --ref main');
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
	$runs[] = checks_run(99, '2 - Unit Tests', 'failure', '2026-10-14T11:00:00Z');
	$blocked($runs, '"2 - Unit Tests" latest result');
});
$test('a later success overrides an earlier failure', static function (): void {
	$runs = checks_green();
	$runs[1] = checks_run(2, '2 - Unit Tests', 'failure', '2026-10-14T09:00:00Z');
	$runs[] = checks_run(99, '2 - Unit Tests', 'success', '2026-10-14T11:00:00Z');
	release_require(array() === checks_problems($runs), 'Later success should release.');
});
$test('in-progress runs are not evidence', static function () use ($blocked): void {
	$runs = checks_green();
	$runs[0] = checks_run(1, '1 - Static QA', 'success', '2026-10-14T10:00:00Z', array('status' => 'in_progress', 'conclusion' => null));
	$blocked($runs, '"1 - Static QA" has not completed');
});
$test('non-Actions apps and other commits are not evidence', static function () use ($blocked): void {
	$runs = checks_green();
	$runs[2]['app']['slug'] = 'some-other-app';
	$blocked($runs, '"Docker QA gate" has not completed');
	$runs = checks_green();
	$runs[2]['head_sha'] = str_repeat('b', 40);
	$blocked($runs, '"Docker QA gate" has not completed');
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
		static fn() => checks_problems(array_merge(checks_green(), array(checks_run(1, '1 - Static QA')))),
		static fn() => checks_problems(array(checks_run(1, '1 - Static QA', 'success', 'yesterday'))),
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
