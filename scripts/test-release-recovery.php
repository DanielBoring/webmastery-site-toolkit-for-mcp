<?php

declare(strict_types=1);

require_once __DIR__ . '/release-recovery.php';

function recovery_fixture(): array {
	$repo = array('id' => 123, 'full_name' => 'DanielBoring/webmastery-site-toolkit-for-mcp');
	$run = array('id' => 456, 'repository' => $repo, 'head_repository' => $repo, 'workflow_id' => 789, 'path' => '.github/workflows/release.yml', 'event' => 'push', 'status' => 'completed', 'conclusion' => 'failure', 'head_sha' => str_repeat('a', 40), 'head_branch' => 'v2.6.0', 'run_attempt' => 1);
	$artifact = array('id' => 100, 'name' => 'release-456-1', 'expired' => false, 'size_in_bytes' => 1000, 'digest' => 'sha256:' . str_repeat('b', 64), 'created_at' => '2026-09-21T13:21:56Z', 'expires_at' => '2026-10-21T13:21:55Z', 'workflow_run' => array('id' => 456, 'repository_id' => 123, 'head_repository_id' => 123, 'head_sha' => $run['head_sha'], 'head_branch' => 'v2.6.0'));
	$steps = array();
	foreach (array('Gate exact tag and current main ancestry', 'Gate static and unit QA before packaging or production approval', 'Test release safeguard regressions', 'Validate source metadata', 'Build once and test the package with pinned and current Plugin Check', 'Seal original ZIP, scoped notes, listing assets and source manifest', 'Upload immutable run-scoped validated bundle') as $name) {
		$steps[] = array('name' => $name, 'status' => 'completed', 'conclusion' => 'success', 'started_at' => '2026-09-21T13:21:54Z', 'completed_at' => '2026-09-21T13:21:56Z');
	}
	return array(
		'input' => array('run-id' => '456', 'artifact-id' => '100', 'repository' => $repo['full_name'], 'event' => 'workflow_dispatch', 'ref' => 'refs/tags/v-release-recovery-fixture', 'actor' => 'DanielBoring', 'triggering-actor' => 'DanielBoring'),
		'repo' => $repo,
		'permission' => array('permission' => 'admin', 'user' => array('login' => 'DanielBoring')),
		'workflow' => array('id' => 789, 'path' => '.github/workflows/release.yml', 'state' => 'active'),
		'run' => $run,
		'attempt' => $run,
		'artifact' => $artifact,
		'job' => array('id' => 999, 'name' => '5 - Validate Release', 'status' => 'completed', 'conclusion' => 'success', 'run_id' => 456, 'run_attempt' => 1, 'head_sha' => $run['head_sha'], 'head_branch' => 'v2.6.0', 'steps' => $steps, 'started_at' => '2026-09-21T13:16:44Z', 'completed_at' => '2026-09-21T13:21:58Z'),
		'tag' => array('ref' => 'refs/tags/v2.6.0', 'object' => array('type' => 'tag', 'sha' => str_repeat('c', 40))),
		'annotation' => array('sha' => str_repeat('c', 40), 'tag' => 'v2.6.0', 'object' => array('type' => 'commit', 'sha' => $run['head_sha'])),
	);
}

function recovery_fixture_api(array $fixture, string $path): array {
	$base = 'repos/DanielBoring/webmastery-site-toolkit-for-mcp';
	$routes = array(
		$base => $fixture['repo'],
		$base . '/collaborators/DanielBoring/permission' => $fixture['permission'],
		$base . '/actions/workflows/release.yml' => $fixture['workflow'],
		$base . '/actions/runs/456' => $fixture['run'],
		$base . '/actions/artifacts/100' => $fixture['artifact'],
		$base . '/actions/runs/456/artifacts?per_page=100&page=1' => $fixture['artifacts-page'] ?? array('total_count' => 1, 'artifacts' => array($fixture['artifact'])),
		$base . '/actions/runs/456/attempts/1' => $fixture['attempt'],
		$base . '/actions/runs/456/attempts/1/jobs?per_page=100&page=1' => $fixture['jobs-page'] ?? array('total_count' => 1, 'jobs' => array($fixture['job'])),
		$base . '/git/ref/tags/' . $fixture['run']['head_branch'] => $fixture['tag'],
		$base . '/git/tags/' . $fixture['tag']['object']['sha'] => $fixture['annotation'],
	);
	release_require(isset($routes[$path]), 'Unexpected API request: ' . $path);
	return $routes[$path];
}

$count = 0;
$test = static function (string $name, callable $body) use (&$count): void {
	$body();
	++$count;
	echo "PASS {$name}\n";
};
$resolve = static fn(array $fixture) => recovery_resolve($fixture['input'], static fn($path) => recovery_fixture_api($fixture, $path), strtotime('2026-09-21T14:00:00Z'));
$reject = static function (string $name, callable $mutate, string $expected) use ($test, $resolve): void {
	$test($name, static function () use ($mutate, $expected, $resolve): void {
		$fixture = recovery_fixture();
		$mutate($fixture);
		try {
			$resolve($fixture);
		} catch (RuntimeException $error) {
			release_require(str_contains($error->getMessage(), $expected), 'Unexpected rejection: ' . $error->getMessage() . ' (expected ' . $expected . ')');
			return;
		}
		throw new RuntimeException('Expected recovery rejection: ' . $expected);
	});
};
$test('failed publication run with successful original QA reuses exact identity', static function () use ($resolve): void {
	$output = $resolve(recovery_fixture());
	release_require($output === array('version' => '2.6.0', 'source-sha' => str_repeat('a', 40), 'tag-object' => str_repeat('c', 40), 'run-id' => '456', 'run-attempt' => '1', 'artifact-id' => '100', 'artifact-digest' => 'sha256:' . str_repeat('b', 64)), 'Resolved identity differs.');
});
$test('earlier successful QA attempt remains explicitly bound after a later failed attempt', static function () use ($resolve): void {
	$fixture = recovery_fixture();
	$fixture['run']['run_attempt'] = 2;
	release_require('1' === $resolve($fixture)['run-attempt'], 'Selected latest attempt instead of artifact attempt.');
});
$test('artifact list property order does not alter identity', static function () use ($resolve): void {
	$fixture = recovery_fixture();
	$artifact = array_reverse($fixture['artifact'], true);
	$artifact['workflow_run'] = array_reverse($artifact['workflow_run'], true);
	$fixture['artifacts-page'] = array('total_count' => 1, 'artifacts' => array($artifact));
	release_require('100' === $resolve($fixture)['artifact-id'], 'API property order rejected.');
});
foreach (array('', '0', '01', '-1', '1.0', "456\n", '1;echo unsafe', '../456', '9999999999999999999') as $invalid) {
	foreach (array('run-id', 'artifact-id') as $field) {
		$reject('invalid ' . $field . ' ' . json_encode($invalid), static function (&$f) use ($field, $invalid): void { $f['input'][$field] = $invalid; }, 'GitHub ID');
	}
}
foreach (array('refs/heads/main', 'refs/tags/v2.6.0', 'refs/tags/v-release-recovery-', "refs/tags/v-release-recovery-ok\n") as $ref) {
	$reject('reject dispatch ref ' . json_encode($ref), static function (&$f) use ($ref): void { $f['input']['ref'] = $ref; }, 'control-tag dispatch');
}
$mutations = array(
	'foreign input repository' => array('input', 'repository', 'foreign/repo', 'restricted'),
	'wrong entry event' => array('input', 'event', 'push', 'control-tag dispatch'),
	'invalid actor' => array('input', 'actor', '../admin', 'invalid recovery actor'),
	'missing triggering actor' => array('input', 'triggering-actor', '', 'invalid recovery actor'),
	'read-only actor' => array('permission', 'permission', 'read', 'write authorization'),
	'wrong repository identity' => array('repo', 'full_name', 'foreign/repo', 'Repository identity'),
	'wrong workflow path' => array('workflow', 'path', '.github/workflows/other.yml', 'workflow identity'),
	'disabled workflow' => array('workflow', 'state', 'disabled_manually', 'workflow identity'),
	'wrong run ID' => array('run', 'id', 457, 'Run ID mismatch'),
	'PR run' => array('run', 'event', 'pull_request', 'tag-push'),
	'dispatched original run' => array('run', 'event', 'workflow_dispatch', 'tag-push'),
	'wrong original workflow ID' => array('run', 'workflow_id', 790, 'tag-push'),
	'wrong original workflow path' => array('run', 'path', '.github/workflows/other.yml', 'tag-push'),
	'pending run' => array('run', 'status', 'in_progress', 'completed'),
	'cancelled run' => array('run', 'conclusion', 'cancelled', 'completed'),
	'branch source' => array('run', 'head_branch', 'main', 'release tag'),
	'non-semver source tag' => array('run', 'head_branch', 'v2.6.0-rc1', 'Invalid release'),
	'invalid source SHA' => array('run', 'head_sha', 'main', 'Invalid source'),
	'wrong artifact ID' => array('artifact', 'id', 101, 'Artifact ID'),
	'wrong artifact name' => array('artifact', 'name', 'release-457-1', 'Artifact name'),
	'ambiguous attempt name' => array('artifact', 'name', 'release-456-01', 'Artifact name'),
	'future attempt' => array('artifact', 'name', 'release-456-2', 'newer'),
	'expired artifact flag' => array('artifact', 'expired', true, 'expired'),
	'expired artifact date' => array('artifact', 'expires_at', '2026-09-20T14:00:00Z', 'expired'),
	'invalid calendar date' => array('artifact', 'expires_at', '2026-09-31T14:00:00Z', 'Invalid evidence timestamp'),
	'empty artifact' => array('artifact', 'size_in_bytes', 0, 'empty'),
	'missing digest' => array('artifact', 'digest', '', 'archive digest'),
	'wrong attempt response' => array('attempt', 'run_attempt', 2, 'attempt/source'),
	'failed QA job' => array('job', 'conclusion', 'failure', 'did not succeed'),
	'pending QA job' => array('job', 'status', 'in_progress', 'did not succeed'),
	'wrong QA source' => array('job', 'head_sha', str_repeat('d', 40), 'QA job source'),
	'wrong QA run' => array('job', 'run_id', 457, 'QA job source'),
	'wrong QA attempt' => array('job', 'run_attempt', 2, 'QA job source'),
	'wrong QA tag' => array('job', 'head_branch', 'v2.5.0', 'QA job source'),
	'missing QA steps' => array('job', 'steps', null, 'Missing original QA steps'),
	'artifact predates upload' => array('artifact', 'created_at', '2026-09-21T13:16:45Z', 'during the successful QA upload'),
	'artifact postdates QA' => array('artifact', 'created_at', '2026-09-21T13:22:00Z', 'during the successful QA upload'),
	'wrong tag ref' => array('tag', 'ref', 'refs/tags/v2.5.0', 'release ref'),
	'wrong annotation tag' => array('annotation', 'tag', 'v2.5.0', 'annotated release tag'),
);
foreach ($mutations as $name => [$object, $field, $value, $error]) {
	$reject($name, static function (&$f) use ($object, $field, $value): void { $f[$object][$field] = $value; }, $error);
}
foreach (array('repository', 'head_repository') as $field) {
	$reject('foreign run ' . $field, static function (&$f) use ($field): void { $f['run'][$field]['id'] = 124; }, 'repository mismatch');
}
foreach (array('id' => 457, 'repository_id' => 124, 'head_repository_id' => 124, 'head_sha' => str_repeat('d', 40), 'head_branch' => 'v2.5.0') as $field => $value) {
	$reject('artifact identity ' . $field, static function (&$f) use ($field, $value): void { $f['artifact']['workflow_run'][$field] = $value; }, 'Artifact run/source/repository');
}
foreach (range(0, 6) as $index) {
	$reject('required QA step failure ' . $index, static function (&$f) use ($index): void { $f['job']['steps'][$index]['conclusion'] = 'skipped'; }, 'required QA step');
}
$reject('duplicate required QA step', static function (&$f): void { $f['job']['steps'][] = $f['job']['steps'][0]; }, 'required QA step');
$reject('absent QA', static function (&$f): void { $f['jobs-page'] = array('total_count' => 0, 'jobs' => array()); }, 'exactly one');
$reject('ambiguous QA', static function (&$f): void {
	$other = $f['job'];
	$other['id'] = 998;
	$f['jobs-page'] = array('total_count' => 2, 'jobs' => array($f['job'], $other));
}, 'exactly one');
$reject('missing run artifact membership', static function (&$f): void { $f['artifacts-page'] = array('total_count' => 0, 'artifacts' => array()); }, 'membership');
$reject('inconsistent run artifact digest', static function (&$f): void {
	$other = $f['artifact'];
	$other['digest'] = 'sha256:' . str_repeat('d', 64);
	$f['artifacts-page'] = array('total_count' => 1, 'artifacts' => array($other));
}, 'membership is inconsistent');
$reject('ambiguous run artifact membership', static function (&$f): void {
	$other = $f['artifact'];
	$other['id'] = 101;
	$f['artifacts-page'] = array('total_count' => 2, 'artifacts' => array($f['artifact'], $other));
}, 'membership');
$reject('moved release tag', static function (&$f): void { $f['annotation']['object']['sha'] = str_repeat('d', 40); }, 'no longer identifies');
$reject('nested annotation denied', static function (&$f): void { $f['annotation']['object']['type'] = 'tag'; }, 'annotated release tag');
$test('pagination includes subsequent pages', static function (): void {
	$items = recovery_collection(static fn($path) => array('total_count' => 2, 'jobs' => array(array('id' => str_ends_with($path, 'page=1') ? 1 : 2))), 'fixture', 'jobs');
	release_require(2 === count($items), 'Second evidence page missing.');
});
foreach (array('API denied', 'malformed JSON shape', 'duplicate pages', 'incomplete pages', 'changing total') as $mode) {
	$test($mode . ' fails closed', static function () use ($mode): void {
		try {
			recovery_collection(static function ($path) use ($mode): array {
				if ('API denied' === $mode) {
					throw new RuntimeException('HTTP 403');
				}
				if ('malformed JSON shape' === $mode) {
					return array('message' => 'Not found');
				}
				$first = str_ends_with($path, 'page=1');
				return array('total_count' => !$first && 'changing total' === $mode ? 3 : 2, 'jobs' => !$first && 'incomplete pages' === $mode ? array() : array(array('id' => 1)));
			}, 'fixture', 'jobs');
		} catch (RuntimeException $error) {
			return;
		}
		throw new RuntimeException('Unsafe API evidence accepted.');
	});
}
$test('distinct triggering actor is independently authorized', static function () use ($resolve): void {
	$f = recovery_fixture();
	$f['input']['triggering-actor'] = 'ReadOnly';
	$queried = false;
	try {
		recovery_resolve($f['input'], static function ($path) use ($f, &$queried): array {
			if (str_contains($path, '/collaborators/ReadOnly/permission')) {
				$queried = true;
				return array('permission' => 'read', 'user' => array('login' => 'ReadOnly'));
			}
			return recovery_fixture_api($f, $path);
		}, strtotime('2026-09-21T14:00:00Z'));
	} catch (RuntimeException $error) {
		release_require($queried && str_contains($error->getMessage(), 'write authorization'), 'Triggering actor was not checked.');
		return;
	}
	throw new RuntimeException('Read-only rerun actor accepted.');
});
$test('postapproval reruns resolver and retains complete approved identity', static function () use ($resolve): void {
	$f = recovery_fixture();
	$approved = $resolve($f);
	$requests = array();
	recovery_revalidate($approved, $f['input'], static function ($path) use ($f, &$requests): array {
		$requests[] = $path;
		return recovery_fixture_api($f, $path);
	}, strtotime('2026-09-21T15:00:00Z'));
	foreach (array('/permission', '/actions/runs/456', '/actions/artifacts/100', '/attempts/1/jobs?per_page=100&page=1') as $required) {
		release_require(count(array_filter($requests, static fn($path) => str_ends_with($path, $required))) === 1, 'Missing postapproval evidence fetch: ' . $required);
	}
});
foreach (array('version', 'source-sha', 'tag-object', 'run-id', 'run-attempt', 'artifact-id', 'artifact-digest') as $field) {
	foreach (array('missing', 'mismatch') as $mode) {
		$test('postapproval approved ' . $field . ' ' . $mode . ' rejected', static function () use ($resolve, $field, $mode): void {
			$f = recovery_fixture();
			$approved = $resolve($f);
			if ('missing' === $mode) {
				unset($approved[$field]);
			} else {
				$approved[$field] .= '-different';
			}
			try {
				recovery_revalidate($approved, $f['input'], static fn($path) => recovery_fixture_api($f, $path), strtotime('2026-09-21T15:00:00Z'));
			} catch (RuntimeException $error) {
				release_require(str_contains($error->getMessage(), 'approved recovery identity') || str_contains($error->getMessage(), 'changed after approval: ' . $field), 'Wrong approved identity rejection.');
				return;
			}
			throw new RuntimeException('Approved identity drift accepted: ' . $field);
		});
	}
}
$postapproval_mutations = array(
	'artifact expires during approval' => static function (&$f): void { $f['artifact']['expires_at'] = '2026-09-21T14:30:00Z'; },
	'artifact is deleted or expired' => static function (&$f): void { $f['artifact']['expired'] = true; },
	'original QA no longer successful' => static function (&$f): void { $f['job']['conclusion'] = 'failure'; },
	'original run provenance changes' => static function (&$f): void { $f['run']['workflow_id'] = 790; },
	'artifact ownership changes' => static function (&$f): void { $f['artifact']['workflow_run']['repository_id'] = 124; },
	'actor authorization revoked' => static function (&$f): void { $f['permission']['permission'] = 'read'; },
	'immutable archive digest changes' => static function (&$f): void { $f['artifact']['digest'] = 'sha256:' . str_repeat('d', 64); },
	'release tag annotation changes on same source' => static function (&$f): void {
		$f['tag']['object']['sha'] = str_repeat('d', 40);
		$f['annotation']['sha'] = str_repeat('d', 40);
	},
	'all API source fields change consistently' => static function (&$f): void {
		foreach (array('run', 'attempt', 'job') as $key) {
			$f[$key]['head_sha'] = str_repeat('d', 40);
		}
		$f['artifact']['workflow_run']['head_sha'] = str_repeat('d', 40);
		$f['annotation']['object']['sha'] = str_repeat('d', 40);
	},
	'all API version fields change consistently' => static function (&$f): void {
		foreach (array('run', 'attempt', 'job') as $key) {
			$f[$key]['head_branch'] = 'v2.7.0';
		}
		$f['artifact']['workflow_run']['head_branch'] = 'v2.7.0';
		$f['tag']['ref'] = 'refs/tags/v2.7.0';
		$f['annotation']['tag'] = 'v2.7.0';
	},
);
foreach ($postapproval_mutations as $name => $mutate) {
	$test('postapproval ' . $name . ' rejected', static function () use ($resolve, $mutate): void {
		$f = recovery_fixture();
		$approved = $resolve($f);
		$mutate($f);
		try {
			recovery_revalidate($approved, $f['input'], static fn($path) => recovery_fixture_api($f, $path), strtotime('2026-09-21T15:00:00Z'));
		} catch (RuntimeException $error) {
			release_require(!str_contains($error->getMessage(), 'Unexpected API request'), 'Fixture did not exercise postapproval validation.');
			return;
		}
		throw new RuntimeException('Postapproval evidence drift accepted.');
	});
}
$test('workflow lanes preserve protected original-artifact publication', static function (): void {
	$workflow = release_read(dirname(__DIR__) . '/.github/workflows/release.yml');
	foreach (array(
		"- '!v-release-recovery-*'",
		"if: github.event_name == 'push'",
		"if: github.event_name == 'workflow_dispatch'",
		'needs: [release-qa, recover]',
		"(github.event_name == 'push' && needs.release-qa.result == 'success' && needs.recover.result == 'skipped')",
		"(github.event_name == 'workflow_dispatch' && needs.recover.result == 'success' && needs.release-qa.result == 'skipped')",
		'${{ !cancelled() &&',
		'group: wordpress-org-production-publish',
		'cancel-in-progress: false',
		'name: wordpress-org',
		'name: Publish release (GitHub approval required)',
		'actions: read',
		'run-id: ${{ env.ORIGINAL_RUN_ID }}',
		'artifact-ids: ${{ env.ARTIFACT_ID }}',
		'github-token: ${{ github.token }}',
		'ref: ${{ env.SOURCE_SHA }}',
		'verify build/release-bundle "$SOURCE_SHA" "$ORIGINAL_RUN_ID" "$RELEASE_VERSION"',
		'test "$REMOTE_TAG_OBJECT" = "$RELEASE_TAG_OBJECT"',
		'VERSION: ${{ env.RELEASE_VERSION }}',
		'subject-path: build/webmastery-site-toolkit-for-mcp-${{ env.RELEASE_VERSION }}.zip',
	) as $required) {
		release_require(str_contains($workflow, $required), 'Missing workflow guard: ' . $required);
	}
	release_require(1 === substr_count($workflow, 'name: Publish release (GitHub approval required)'), 'Publication pipeline duplicated.');
	$recovery = explode("\n  publish:", explode("\n  recover:", $workflow)[1])[0];
	release_require(!str_contains($recovery, 'release-qa.sh') && !str_contains($recovery, 'composer qa') && !str_contains($recovery, 'build-release'), 'Recovery rebuilds or reruns original QA.');
	release_require(!preg_match('/run:.*\$\{\{\s*inputs\./', $workflow), 'Dispatch input interpolated into shell.');
	release_require(2 === substr_count($workflow, 'NOT WordPress.org staff review'), 'Both lanes need preapproval explanation.');
});
$test('postapproval control and resolver gates precede frozen checkout, download and secrets', static function (): void {
	$workflow = release_read(dirname(__DIR__) . '/.github/workflows/release.yml');
	$publish = explode("\n  publish:", $workflow)[1];
	$prior = -1;
	foreach (array('name: Checkout recovery control ref again after approval', 'bash scripts/release-control-check.sh verify', 'name: Set up PHP', 'php scripts/release-recovery.php verify-approved', 'name: Checkout validated source without persistent credentials', 'name: Download only the original QA job', 'SVN_USERNAME: ${{ secrets.SVN_USERNAME }}') as $required) {
		$position = strpos($publish, $required);
		release_require(false !== $position && $position > $prior, 'Unsafe postapproval ordering: ' . $required);
		$prior = $position;
	}
	release_require(str_contains($workflow, 'control-tag-object: ${{ steps.control.outputs.control-tag-object }}'), 'Control object is not exported before approval.');
	release_require(str_contains($publish, 'APPROVED_CONTROL_TAG_OBJECT: ${{ needs.recover.outputs.control-tag-object }}'), 'Control object is not bound after approval.');
	release_require(str_contains($workflow, 'artifact-digest: ${{ steps.resolve.outputs.artifact-digest }}'), 'Archive digest is not exported before approval.');
	foreach (array('version', 'source-sha', 'tag-object', 'run-id', 'run-attempt', 'artifact-id', 'artifact-digest') as $field) {
		$binding = 'APPROVED_' . strtoupper(str_replace('-', '_', $field)) . ': ${{ needs.recover.outputs.' . $field . ' }}';
		release_require(str_contains($publish, $binding), 'Missing postapproval binding: ' . $field);
	}
});
echo "PASS {$count} release recovery tests\n";
