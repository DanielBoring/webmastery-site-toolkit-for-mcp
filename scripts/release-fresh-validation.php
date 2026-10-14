<?php

declare(strict_types=1);

require_once __DIR__ . '/release-recovery.php';

const FRESH_RELEASE_VERSION = '3.0.0';
const FRESH_RELEASE_SOURCE = '2f9842bb943fb27030cabe8060ad0b10c007ac14';
const FRESH_RELEASE_TAG_OBJECT = '4bcc5bae61b7867bcb6f186713beeaeb5ed9b722';
const FRESH_RELEASE_WORKFLOW = '.github/workflows/fresh-release-validation.yml';

function fresh_identity(array $input, callable $api): array {
	release_require('DanielBoring/webmastery-site-toolkit-for-mcp' === ($input['repository'] ?? null), 'Wrong fresh-validation repository.');
	release_require('workflow_dispatch' === ($input['event'] ?? null)
		&& is_string($input['ref'] ?? null)
		&& 1 === preg_match('#^refs/tags/v-release-recovery-[a-z0-9][a-z0-9-]*$#D', $input['ref']), 'Fresh validation requires a dedicated annotated control-tag dispatch.');
	$source = recovery_sha($input['control-source'] ?? null);
	$object = recovery_sha($input['control-object'] ?? null);
	$base = 'repos/' . $input['repository'];
	$repo = $api($base);
	release_require(($repo['full_name'] ?? null) === $input['repository'] && 'main' === ($repo['default_branch'] ?? null), 'Repository/default branch identity mismatch.');
	$repository_id = recovery_id($repo['id'] ?? null);
	foreach (array_unique(array($input['actor'] ?? '', $input['triggering-actor'] ?? '')) as $actor) {
		release_require(is_string($actor) && 1 === preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*(?:\[bot\])?$/D', $actor), 'Invalid fresh-validation actor.');
		$permission = $api($base . '/collaborators/' . rawurlencode($actor) . '/permission');
		release_require(($permission['user']['login'] ?? null) === $actor && in_array($permission['permission'] ?? null, array('write', 'maintain', 'admin'), true), 'Fresh-validation actor requires repository write authorization.');
	}
	foreach (array(
		substr($input['ref'], 10) => array($object, $source),
		'v' . FRESH_RELEASE_VERSION => array(FRESH_RELEASE_TAG_OBJECT, FRESH_RELEASE_SOURCE),
	) as $name => $expected) {
		$ref = $api($base . '/git/ref/tags/' . $name);
		release_require(($ref['ref'] ?? null) === 'refs/tags/' . $name && 'tag' === ($ref['object']['type'] ?? null)
			&& ($ref['object']['sha'] ?? null) === $expected[0], 'Moved, replaced or non-annotated release/control tag.');
		$tag = $api($base . '/git/tags/' . $expected[0]);
		release_require(($tag['sha'] ?? null) === $expected[0] && ($tag['tag'] ?? null) === $name
			&& 'commit' === ($tag['object']['type'] ?? null) && ($tag['object']['sha'] ?? null) === $expected[1], 'Release/control tag source differs.');
	}
	$main = $api($base . '/git/ref/heads/main');
	release_require('refs/heads/main' === ($main['ref'] ?? null) && 'commit' === ($main['object']['type'] ?? null), 'Missing current main commit.');
	$main_sha = recovery_sha($main['object']['sha'] ?? null);
	foreach (array_unique(array($source, FRESH_RELEASE_SOURCE)) as $ancestor) {
		$compare = $api($base . '/compare/' . $ancestor . '...' . $main_sha);
		release_require(in_array($compare['status'] ?? null, array('ahead', 'identical'), true)
			&& ($compare['merge_base_commit']['sha'] ?? null) === $ancestor, 'Release/control source is not contained in current main.');
	}
	$workflow = $api($base . '/actions/workflows/fresh-release-validation.yml');
	release_require(FRESH_RELEASE_WORKFLOW === ($workflow['path'] ?? null) && 'active' === ($workflow['state'] ?? null), 'Wrong fresh-validation workflow.');
	return array(
		'mode' => 'fresh-validation',
		'version' => FRESH_RELEASE_VERSION,
		'source_sha' => FRESH_RELEASE_SOURCE,
		'tag_object' => FRESH_RELEASE_TAG_OBJECT,
		'control_ref' => $input['ref'],
		'control_source' => $source,
		'control_object' => $object,
		'run_id' => recovery_id($input['run-id'] ?? null),
		'run_attempt' => recovery_id($input['run-attempt'] ?? null),
		'repository_id' => $repository_id,
		'workflow_id' => recovery_id($workflow['id'] ?? null),
	);
}

function fresh_artifact(array $input, callable $api, int $now): array {
	$identity = fresh_identity($input, $api);
	$base = 'repos/' . $input['repository'];
	$run_id = $identity['run_id'];
	$attempt = $identity['run_attempt'];
	$run = $api($base . '/actions/runs/' . $run_id . '/attempts/' . $attempt);
	release_require(recovery_id($run['id'] ?? null) === $run_id && recovery_id($run['run_attempt'] ?? null) === $attempt
		&& 'workflow_dispatch' === ($run['event'] ?? null) && FRESH_RELEASE_WORKFLOW === ($run['path'] ?? null)
		&& recovery_id($run['workflow_id'] ?? null) === $identity['workflow_id']
		&& ($run['head_sha'] ?? null) === $identity['control_source']
		&& ($run['head_branch'] ?? null) === substr($identity['control_ref'], 10)
		&& in_array($run['status'] ?? null, array('in_progress', 'completed'), true)
		&& (('in_progress' === $run['status'] && null === ($run['conclusion'] ?? null))
			|| ('completed' === $run['status'] && in_array($run['conclusion'] ?? null, array('success', 'failure'), true))), 'Fresh run/attempt/control identity mismatch.');
	foreach (array('repository', 'head_repository') as $field) {
		release_require(($run[$field]['full_name'] ?? null) === $input['repository']
			&& recovery_id($run[$field]['id'] ?? null) === $identity['repository_id'], 'Fresh run repository mismatch.');
	}
	$jobs = recovery_collection($api, $base . '/actions/runs/' . $run_id . '/attempts/' . $attempt . '/jobs', 'jobs');
	$qa = array_values(array_filter($jobs, static fn($job) => 'Validate original immutable v3.0.0 afresh' === ($job['name'] ?? null)));
	release_require(1 === count($qa) && 'completed' === ($qa[0]['status'] ?? null) && 'success' === ($qa[0]['conclusion'] ?? null), 'Fresh validation job must complete successfully.');
	$qa = $qa[0];
	release_require(recovery_id($qa['run_id'] ?? null) === $run_id && recovery_id($qa['run_attempt'] ?? null) === $attempt
		&& ($qa['head_sha'] ?? null) === $identity['control_source']
		&& ($qa['head_branch'] ?? null) === substr($identity['control_ref'], 10), 'Fresh QA job identity mismatch.');
	release_require(is_array($qa['steps'] ?? null), 'Missing fresh QA steps.');
	foreach (array(
		'Admit reviewed control and locked original source',
		'Test trusted fresh-validation safeguards',
		'Verify original checkout and metadata',
		'Install original locked development dependencies',
		'Run original static and complete unit QA',
		'Run original release safeguards',
		'Build once and test original ZIP with both Plugin Check versions',
		'Recheck immutable identities and seal fresh provenance',
		'Upload new immutable validated bundle',
	) as $name) {
		$steps = array_values(array_filter($qa['steps'], static fn($step) => is_array($step) && ($step['name'] ?? null) === $name));
		release_require(1 === count($steps) && 'completed' === ($steps[0]['status'] ?? null) && 'success' === ($steps[0]['conclusion'] ?? null), 'Missing/failed fresh QA step: ' . $name);
	}
	$artifact_id = recovery_id($input['artifact-id'] ?? null);
	$artifact = $api($base . '/actions/artifacts/' . $artifact_id);
	release_require(recovery_id($artifact['id'] ?? null) === $artifact_id
		&& ($artifact['name'] ?? null) === 'fresh-release-' . $run_id . '-' . $attempt
		&& false === ($artifact['expired'] ?? null) && recovery_time($artifact['expires_at'] ?? null) > $now
		&& is_int($artifact['size_in_bytes'] ?? null) && $artifact['size_in_bytes'] > 0
		&& is_string($artifact['digest'] ?? null) && 1 === preg_match('/^sha256:[a-f0-9]{64}$/D', $artifact['digest'])
		&& is_string($input['artifact-digest'] ?? null)
		&& 1 === preg_match('/^[a-f0-9]{64}$/D', $input['artifact-digest'])
		&& $artifact['digest'] === 'sha256:' . $input['artifact-digest'], 'Fresh artifact identity/digest/expiry mismatch.');
	$bound = $artifact['workflow_run'] ?? array();
	release_require(recovery_id($bound['id'] ?? null) === $run_id
		&& recovery_id($bound['repository_id'] ?? null) === $identity['repository_id']
		&& recovery_id($bound['head_repository_id'] ?? null) === $identity['repository_id']
		&& ($bound['head_sha'] ?? null) === $identity['control_source']
		&& ($bound['head_branch'] ?? null) === substr($identity['control_ref'], 10), 'Fresh artifact control/run mismatch.');
	$members = recovery_collection($api, $base . '/actions/runs/' . $run_id . '/artifacts', 'artifacts');
	$matching = array_values(array_filter($members, static fn($item) => ($item['name'] ?? null) === $artifact['name']));
	release_require(1 === count($matching), 'Missing/ambiguous fresh artifact membership.');
	foreach (array('id', 'name', 'expired', 'size_in_bytes', 'digest', 'created_at', 'expires_at', 'workflow_run') as $field) {
		release_require(($matching[0][$field] ?? null) === ($artifact[$field] ?? null), 'Inconsistent fresh artifact membership.');
	}
	$created = recovery_time($artifact['created_at'] ?? null);
	release_require(recovery_time($qa['started_at'] ?? null) <= $created && $created <= recovery_time($qa['completed_at'] ?? null)
		&& recovery_time($steps[0]['started_at'] ?? null) <= $created && $created <= recovery_time($steps[0]['completed_at'] ?? null), 'Fresh artifact created outside successful upload.');
	return $identity;
}

function fresh_manifest(string $path, array $identity, bool $seal): void {
	$manifest = json_decode(release_read($path), true, 512, JSON_THROW_ON_ERROR);
	release_require(1 === ($manifest['schema'] ?? null) && FRESH_RELEASE_SOURCE === ($manifest['source_sha'] ?? null)
		&& FRESH_RELEASE_VERSION === ($manifest['version'] ?? null) && $identity['run_id'] === ($manifest['run_id'] ?? null), 'Fresh manifest source/run/version mismatch.');
	if ($seal) {
		release_require(!array_key_exists('fresh_validation', $manifest), 'Fresh provenance already exists.');
		$manifest['fresh_validation'] = $identity;
		$bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		release_require(strlen($bytes) === file_put_contents($path, $bytes), 'Cannot seal fresh provenance.');
	} else {
		release_require(($manifest['fresh_validation'] ?? null) === $identity, 'Fresh manifest provenance differs from approved control/source/run.');
	}
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	try {
		release_require(2 <= count($argv) && in_array($argv[1], array('admit', 'seal', 'verify-approved', 'verify-manifest'), true), 'Unknown fresh-validation command.');
		release_require(count($argv) === (in_array($argv[1], array('seal', 'verify-manifest'), true) ? 3 : 2), 'Unexpected fresh-validation arguments.');
		$input = array(
			'repository' => getenv('GITHUB_REPOSITORY'), 'event' => getenv('GITHUB_EVENT_NAME'), 'ref' => getenv('GITHUB_REF'),
			'control-source' => getenv('GITHUB_SHA'), 'control-object' => getenv('CONTROL_TAG_OBJECT'),
			'actor' => getenv('GITHUB_ACTOR'), 'triggering-actor' => getenv('GITHUB_TRIGGERING_ACTOR'),
			'run-id' => getenv('GITHUB_RUN_ID'),
			'run-attempt' => in_array($argv[1], array('verify-approved', 'verify-manifest'), true) ? getenv('QA_RUN_ATTEMPT') : getenv('GITHUB_RUN_ATTEMPT'),
			'artifact-id' => getenv('ARTIFACT_ID'), 'artifact-digest' => getenv('ARTIFACT_DIGEST'),
		);
		$identity = 'verify-approved' === $argv[1] ? fresh_artifact($input, 'recovery_api', time()) : fresh_identity($input, 'recovery_api');
		if (in_array($argv[1], array('seal', 'verify-manifest'), true)) {
			fresh_manifest($argv[2], $identity, 'seal' === $argv[1]);
		}
		echo "PASS locked original 3.0.0 fresh-validation identity\n";
	} catch (Throwable $error) {
		fwrite(STDERR, "Fresh validation refused: " . $error->getMessage() . "\n");
		exit(1);
	}
}
