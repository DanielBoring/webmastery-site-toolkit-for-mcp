<?php

declare(strict_types=1);

require_once __DIR__ . '/release-fresh-validation.php';

function fresh_fixture(): array {
	$input = array('repository' => 'DanielBoring/webmastery-site-toolkit-for-mcp', 'event' => 'workflow_dispatch',
		'ref' => 'refs/tags/v-release-recovery-fresh-300', 'control-source' => str_repeat('a', 40), 'control-object' => str_repeat('b', 40),
		'actor' => 'maintainer', 'triggering-actor' => 'reviewer', 'run-id' => '456', 'run-attempt' => '1',
		'artifact-id' => '100', 'artifact-digest' => str_repeat('d', 64));
	$base = 'repos/' . $input['repository'];
	$repo = array('full_name' => $input['repository'], 'id' => 123, 'default_branch' => 'main');
	$api = array($base => $repo,
		$base . '/actions/workflows/fresh-release-validation.yml' => array('id' => 789, 'path' => FRESH_RELEASE_WORKFLOW, 'state' => 'active'),
		$base . '/git/ref/heads/main' => array('ref' => 'refs/heads/main', 'object' => array('type' => 'commit', 'sha' => str_repeat('c', 40))));
	foreach (array('maintainer', 'reviewer') as $actor) {
		$api[$base . '/collaborators/' . $actor . '/permission'] = array('permission' => 'write', 'user' => array('login' => $actor));
	}
	foreach (array('v-release-recovery-fresh-300' => array(str_repeat('b', 40), str_repeat('a', 40)),
		'v3.0.0' => array(FRESH_RELEASE_TAG_OBJECT, FRESH_RELEASE_SOURCE)) as $name => $pair) {
		$api[$base . '/git/ref/tags/' . $name] = array('ref' => 'refs/tags/' . $name, 'object' => array('type' => 'tag', 'sha' => $pair[0]));
		$api[$base . '/git/tags/' . $pair[0]] = array('sha' => $pair[0], 'tag' => $name, 'object' => array('type' => 'commit', 'sha' => $pair[1]));
		$api[$base . '/compare/' . $pair[1] . '...' . str_repeat('c', 40)] = array('status' => 'ahead', 'merge_base_commit' => array('sha' => $pair[1]));
	}
	$run = array('id' => 456, 'run_attempt' => 1, 'event' => 'workflow_dispatch', 'path' => FRESH_RELEASE_WORKFLOW, 'workflow_id' => 789,
		'head_sha' => $input['control-source'], 'head_branch' => 'v-release-recovery-fresh-300', 'status' => 'in_progress', 'conclusion' => null,
		'repository' => $repo, 'head_repository' => $repo);
	$api[$base . '/actions/runs/456/attempts/1'] = $run;
	$steps = array();
	foreach (array('Admit reviewed control and locked original source', 'Test trusted fresh-validation safeguards', 'Verify original checkout and metadata',
		'Install original locked development dependencies', 'Run original static and complete unit QA',
		'Run original release safeguards', 'Build once and test original ZIP with both Plugin Check versions',
		'Recheck immutable identities and seal fresh provenance', 'Upload new immutable validated bundle') as $name) {
		$steps[] = array('name' => $name, 'status' => 'completed', 'conclusion' => 'success',
			'started_at' => '2026-10-13T16:00:00Z', 'completed_at' => '2026-10-13T17:00:00Z');
	}
	$job = array('id' => 999, 'name' => 'Validate original immutable v3.0.0 afresh', 'status' => 'completed', 'conclusion' => 'success',
		'run_id' => 456, 'run_attempt' => 1, 'head_sha' => $input['control-source'], 'head_branch' => $run['head_branch'], 'steps' => $steps,
		'started_at' => '2026-10-13T16:00:00Z', 'completed_at' => '2026-10-13T17:00:00Z');
	$api[$base . '/actions/runs/456/attempts/1/jobs?per_page=100&page=1'] = array('total_count' => 1, 'jobs' => array($job));
	$artifact = array('id' => 100, 'name' => 'fresh-release-456-1', 'expired' => false, 'size_in_bytes' => 1024,
		'digest' => 'sha256:' . $input['artifact-digest'], 'created_at' => '2026-10-13T16:59:00Z', 'expires_at' => '2026-11-13T16:59:00Z',
		'workflow_run' => array('id' => 456, 'repository_id' => 123, 'head_repository_id' => 123, 'head_sha' => $input['control-source'], 'head_branch' => $run['head_branch']));
	$api[$base . '/actions/artifacts/100'] = $artifact;
	$api[$base . '/actions/runs/456/artifacts?per_page=100&page=1'] = array('total_count' => 1, 'artifacts' => array($artifact));
	return array($input, $api);
}

$tests = 0;
$test = static function (string $label, callable $body) use (&$tests): void {
	$body();
	++$tests;
	echo "PASS {$label}\n";
};
$call = static function (array $input, array $responses): array {
	return fresh_artifact($input, static function (string $path) use ($responses): array {
		release_require(isset($responses[$path]), 'Fixture missing API path: ' . $path);
		return $responses[$path];
	}, strtotime('2026-10-13T18:00:00Z'));
};
$test('successful fresh QA pending protected publication', static function () use ($call): void {
	list($input, $api) = fresh_fixture();
	$identity = $call($input, $api);
	release_require(FRESH_RELEASE_SOURCE === $identity['source_sha'] && $input['control-source'] === $identity['control_source']
		&& 'fresh-validation' === $identity['mode'], 'Control source incorrectly substituted for original source.');
});
$test('failed publication can retain its successful fresh QA attempt', static function () use ($call): void {
	list($input, $api) = fresh_fixture();
	$path = 'repos/' . $input['repository'] . '/actions/runs/456/attempts/1';
	$api[$path]['status'] = 'completed';
	$api[$path]['conclusion'] = 'failure';
	release_require('1' === $call($input, $api)['run_attempt'], 'Successful QA attempt was not retained.');
});
foreach (array('event' => 'push', 'ref' => 'refs/heads/main', 'repository' => 'attacker/fork', 'actor' => 'unknown',
	'triggering-actor' => '', 'control-source' => FRESH_RELEASE_SOURCE, 'control-object' => FRESH_RELEASE_TAG_OBJECT,
	'artifact-digest' => str_repeat('e', 64), 'artifact-id' => '101', 'run-attempt' => '2') as $field => $value) {
	$test('reject input ' . $field, static function () use ($call, $field, $value): void {
		list($input, $api) = fresh_fixture();
		$input[$field] = $value;
		try {
			$call($input, $api);
		} catch (RuntimeException $error) {
			return;
		}
		throw new RuntimeException('Invalid dispatch identity accepted.');
	});
}
list($sample, $responses) = fresh_fixture();
$base = 'repos/' . $sample['repository'];
$mutations = array(
	'read-only actor' => array('/collaborators/maintainer/permission', array('permission'), 'read'),
	'triggering actor revoked' => array('/collaborators/reviewer/permission', array('permission'), 'read'),
	'wrong default branch' => array('', array('default_branch'), 'develop'),
	'moved release tag' => array('/git/ref/tags/v3.0.0', array('object', 'sha'), str_repeat('e', 40)),
	'lightweight release tag' => array('/git/ref/tags/v3.0.0', array('object', 'type'), 'commit'),
	'wrong original source' => array('/git/tags/' . FRESH_RELEASE_TAG_OBJECT, array('object', 'sha'), str_repeat('e', 40)),
	'moved control tag' => array('/git/ref/tags/v-release-recovery-fresh-300', array('object', 'sha'), str_repeat('e', 40)),
	'control not in main' => array('/compare/' . $sample['control-source'] . '...' . str_repeat('c', 40), array('status'), 'diverged'),
	'release not in main' => array('/compare/' . FRESH_RELEASE_SOURCE . '...' . str_repeat('c', 40), array('merge_base_commit', 'sha'), str_repeat('e', 40)),
	'wrong workflow' => array('/actions/runs/456/attempts/1', array('path'), '.github/workflows/release.yml'),
	'wrong run source' => array('/actions/runs/456/attempts/1', array('head_sha'), FRESH_RELEASE_SOURCE),
	'cancelled run' => array('/actions/runs/456/attempts/1', array('status'), 'cancelled'),
	'failed QA' => array('/actions/runs/456/attempts/1/jobs?per_page=100&page=1', array('jobs', 0, 'conclusion'), 'failure'),
	'incomplete QA' => array('/actions/runs/456/attempts/1/jobs?per_page=100&page=1', array('jobs', 0, 'status'), 'in_progress'),
	'skipped package check' => array('/actions/runs/456/attempts/1/jobs?per_page=100&page=1', array('jobs', 0, 'steps', 6, 'conclusion'), 'skipped'),
	'missing upload step' => array('/actions/runs/456/attempts/1/jobs?per_page=100&page=1', array('jobs', 0, 'steps'), array()),
	'wrong artifact source' => array('/actions/artifacts/100', array('workflow_run', 'head_sha'), FRESH_RELEASE_SOURCE),
	'expired artifact' => array('/actions/artifacts/100', array('expired'), true),
	'empty artifact' => array('/actions/artifacts/100', array('size_in_bytes'), 0),
	'wrong artifact attempt' => array('/actions/artifacts/100', array('name'), 'fresh-release-456-2'),
	'artifact outside upload' => array('/actions/artifacts/100', array('created_at'), '2026-10-13T15:59:00Z'),
	'missing artifact membership' => array('/actions/runs/456/artifacts?per_page=100&page=1', array('artifacts'), array()),
	'incomplete artifact collection' => array('/actions/runs/456/artifacts?per_page=100&page=1', array('total_count'), 2),
);
foreach ($mutations as $label => $change) {
	$test($label, static function () use ($call, $base, $change): void {
		list($input, $api) = fresh_fixture();
		$target =& $api[$base . $change[0]];
		foreach ($change[1] as $field) {
			$target =& $target[$field];
		}
		$target = $change[2];
		unset($target);
		try {
			$call($input, $api);
		} catch (RuntimeException $error) {
			return;
		}
		throw new RuntimeException('Invalid fresh evidence accepted.');
	});
}
$test('seal binds new run/control and rejects tampered provenance', static function () use ($call): void {
	list($input, $api) = fresh_fixture();
	$identity = $call($input, $api);
	$path = tempnam(sys_get_temp_dir(), 'wstm-fresh-manifest-');
	release_require(false !== $path, 'Cannot reserve manifest fixture.');
	try {
		file_put_contents($path, json_encode(array('schema' => 1, 'source_sha' => FRESH_RELEASE_SOURCE, 'run_id' => '456', 'version' => '3.0.0', 'files' => array())));
		fresh_manifest($path, $identity, true);
		fresh_manifest($path, $identity, false);
		$identity['source_sha'] = $identity['control_source'];
		try {
			fresh_manifest($path, $identity, false);
		} catch (RuntimeException $error) {
			return;
		}
		throw new RuntimeException('Tampered fresh provenance accepted.');
	} finally {
		unlink($path);
	}
});
$test('synthetic fresh provenance retains package verifier compatibility across repository versions', static function () use ($call): void {
	list($input, $api) = fresh_fixture();
	$identity = $call($input, $api);
	$temporary = tempnam(sys_get_temp_dir(), 'wstm-fresh-bundle-');
	release_require(false !== $temporary, 'Cannot reserve bundle fixture.');
	unlink($temporary);
	release_require(mkdir($temporary, 0700), 'Cannot create bundle fixture.');
	try {
		$root = $temporary . '/synthetic-source';
		foreach (array('includes', '.github', '.wordpress-org') as $directory) {
			release_require(mkdir($root . '/' . $directory, 0700, true), 'Cannot create synthetic source directory.');
		}
		require_once __DIR__ . '/compatibility-baselines.php';
		$baseline_path = dirname(__DIR__) . '/.github/compatibility-versions.json';
		$baseline = webmastery_mcp_read_baselines($baseline_path);
		$tested = implode('.', array_slice(explode('.', $baseline['wordpress']), 0, 2));
		file_put_contents($root . '/' . RELEASE_SLUG . '.php', "<?php\n/**\n * Version: 3.0.0\n * Text Domain: " . RELEASE_SLUG . "\n * Requires at least: 6.9\n * Requires PHP: 8.0\n */\n");
		file_put_contents($root . '/readme.txt', "=== Synthetic release fixture ===\nStable tag: 3.0.0\nRequires at least: 6.9\nRequires PHP: 8.0\nTested up to: {$tested}\n\nSynthetic regression fixture, not production evidence.\n\n== Changelog ==\n= 3.0.0 =\nFixture notes.\n\n== Upgrade Notice ==\n= 3.0.0 =\nFixture notice.\n");
		file_put_contents($root . '/CHANGELOG.md', "## 3.0.0\nFixture notes.\n");
		file_put_contents($root . '/LICENSE', "Synthetic fixture license.\n");
		file_put_contents($root . '/includes/fixture.php', "<?php\nreturn true;\n");
		file_put_contents($root . '/.wordpress-org/icon-128x128.png', 'Synthetic fixture asset bytes.');
		release_require(copy($baseline_path, $root . '/.github/compatibility-versions.json'), 'Cannot copy fixture baseline.');
		$zip = new ZipArchive();
		release_require(true === $zip->open($temporary . '/original.zip', ZipArchive::CREATE | ZipArchive::EXCL), 'Cannot create fixture ZIP.');
		foreach (release_source_files($root) as $name => $hash) {
			release_require($zip->addFile($root . '/' . $name, RELEASE_SLUG . '/' . $name), 'Cannot add fixture package file.');
		}
		release_require($zip->close(), 'Cannot close fixture ZIP.');
		release_bundle($root, $temporary . '/original.zip', $temporary . '/bundle', FRESH_RELEASE_SOURCE, '456');
		fresh_manifest($temporary . '/bundle/manifest.json', $identity, true);
		fresh_manifest($temporary . '/bundle/manifest.json', $identity, false);
		release_verify_bundle($root, $temporary . '/bundle', FRESH_RELEASE_SOURCE, '456', '3.0.0');
		file_put_contents($temporary . '/bundle/notes.md', 'tampered');
		try {
			release_verify_bundle($root, $temporary . '/bundle', FRESH_RELEASE_SOURCE, '456', '3.0.0');
		} catch (RuntimeException $error) {
			return;
		}
		throw new RuntimeException('Tampered fresh bundle accepted by package verifier.');
	} finally {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $entry) {
			if ($entry->isDir()) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}
		rmdir($temporary);
	}
});
$test('workflow gate order and locked original-source commands', static function (): void {
	$workflow = release_read(dirname(__DIR__) . '/.github/workflows/fresh-release-validation.yml');
	release_require(!str_contains($workflow, 'inputs:') && !str_contains($workflow, 'workflow_call:'), 'Caller can select fresh validation identity.');
	foreach (array('timeout-minutes: 120', "COMPOSER_PROCESS_TIMEOUT: '0'", "REQUIRE_CURRENT_PLUGIN_CHECK: '1'",
		'run: composer qa', 'run: composer test:release-safeguards', 'run: bash scripts/release-qa.sh 3.0.0',
		'if: ${{ !cancelled() && needs.validate.result == \'success\' }}',
		'group: wordpress-org-production-publish', 'cancel-in-progress: false', 'name: wordpress-org',
		'php scripts/release-fresh-validation.php verify-approved', 'php scripts/release-fresh-validation.php verify-manifest',
		'QA_RUN_ATTEMPT: ${{ needs.validate.outputs.run-attempt }}') as $guard) {
		release_require(str_contains($workflow, $guard), 'Missing fresh workflow guard: ' . $guard);
	}
	$publish = explode("\n  publish:", $workflow)[1];
	$previous = -1;
	foreach (array('bash scripts/release-control-check.sh verify', 'php scripts/release-fresh-validation.php verify-approved',
		'name: Checkout validated original source', 'name: Download only this fresh QA',
		'php scripts/release-fresh-validation.php verify-manifest', 'scripts/release-tools.php verify',
		'scripts/release-publish-check.sh preflight', 'SVN_USERNAME: ${{ secrets.SVN_USERNAME }}') as $guard) {
		$position = strpos($publish, $guard);
		release_require(false !== $position && $position > $previous, 'Unsafe fresh publish order: ' . $guard);
		$previous = $position;
	}
	release_require(!str_contains($publish, 'release-qa.sh') && !str_contains($publish, 'composer '), 'Package rebuilt after approval.');
	release_require(2 === substr_count($workflow, 'ref: ' . FRESH_RELEASE_SOURCE), 'Checkout source is not locked.');
});
echo "PASS {$tests} fresh-validation tests\n";
