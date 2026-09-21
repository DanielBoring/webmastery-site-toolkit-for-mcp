<?php

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

function recovery_id(mixed $value): string {
	release_require(is_string($value) || is_int($value), 'Expected a numeric GitHub ID.');
	$value = (string) $value;
	release_require(1 === preg_match('/^[1-9][0-9]{0,17}$/D', $value), 'Expected a canonical positive GitHub ID.');
	return $value;
}

function recovery_sha(mixed $value): string {
	release_require(is_string($value) && 1 === preg_match('/^[a-f0-9]{40}$/D', $value), 'Invalid source or tag SHA.');
	return $value;
}

function recovery_time(mixed $value): int {
	release_require(is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value), 'Missing or invalid evidence timestamp.');
	$time = strtotime($value);
	release_require(false !== $time && gmdate('Y-m-d\TH:i:s\Z', $time) === $value, 'Invalid evidence timestamp.');
	return $time;
}

function recovery_api(string $path): array {
	$process = proc_open(array('gh', 'api', '--method', 'GET', $path), array(1 => array('pipe', 'w'), 2 => STDERR), $pipes);
	release_require(is_resource($process), 'Cannot start read-only GitHub API request.');
	$json = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$status = proc_close($process);
	release_require(0 === $status && false !== $json, 'GitHub API request failed; recovery denied.');
	$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	release_require(is_array($data), 'Malformed GitHub API response.');
	return $data;
}

function recovery_collection(callable $api, string $path, string $key): array {
	$items = array();
	$total = null;
	for ($page = 1; $page <= 100; ++$page) {
		$data = $api($path . '?per_page=100&page=' . $page);
		release_require(is_int($data['total_count'] ?? null) && $data['total_count'] >= 0 && is_array($data[$key] ?? null), 'Malformed paginated evidence.');
		release_require(null === $total || $total === $data['total_count'], 'Evidence changed during pagination.');
		$total = $data['total_count'];
		foreach ($data[$key] as $item) {
			release_require(is_array($item), 'Malformed collection item.');
			$id = recovery_id($item['id'] ?? null);
			release_require(!isset($items[$id]), 'Duplicate evidence across pages.');
			$items[$id] = $item;
		}
		release_require(count($items) <= $total, 'Inconsistent evidence count.');
		if (count($items) === $total) {
			return array_values($items);
		}
		release_require(count($data[$key]) > 0, 'Incomplete paginated evidence.');
	}
	throw new RuntimeException('Evidence pagination exceeded bounded limit.');
}

function recovery_run(array $run, string $repository, string $repository_id, string $workflow_id, string $run_id): void {
	release_require(recovery_id($run['id'] ?? null) === $run_id, 'Run ID mismatch.');
	release_require(($run['repository']['full_name'] ?? null) === $repository && recovery_id($run['repository']['id'] ?? null) === $repository_id, 'Run repository mismatch.');
	release_require(($run['head_repository']['full_name'] ?? null) === $repository && recovery_id($run['head_repository']['id'] ?? null) === $repository_id, 'Run head repository mismatch.');
	release_require('push' === ($run['event'] ?? null) && '.github/workflows/release.yml' === ($run['path'] ?? null) && recovery_id($run['workflow_id'] ?? null) === $workflow_id, 'Original run must be the tag-push release workflow.');
	release_require('completed' === ($run['status'] ?? null) && in_array($run['conclusion'] ?? null, array('success', 'failure'), true), 'Original run must be completed, not pending or cancelled.');
	recovery_sha($run['head_sha'] ?? null);
	release_require(is_string($run['head_branch'] ?? null) && str_starts_with($run['head_branch'], 'v'), 'Missing original release tag.');
	release_version(substr($run['head_branch'], 1));
	recovery_id($run['run_attempt'] ?? null);
}

function recovery_resolve(array $input, callable $api, int $now): array {
	$run_id = recovery_id($input['run-id'] ?? null);
	$artifact_id = recovery_id($input['artifact-id'] ?? null);
	$repository = $input['repository'] ?? null;
	release_require('DanielBoring/webmastery-site-toolkit-for-mcp' === $repository, 'Recovery is restricted to the release repository.');
	release_require('workflow_dispatch' === ($input['event'] ?? null) && is_string($input['ref'] ?? null) && 1 === preg_match('#^refs/tags/v-release-recovery-[a-z0-9][a-z0-9-]*$#D', $input['ref']), 'Recovery requires a dedicated control-tag dispatch.');
	$base = 'repos/' . $repository;
	$repo = $api($base);
	$repository_id = recovery_id($repo['id'] ?? null);
	release_require(($repo['full_name'] ?? null) === $repository, 'Repository identity mismatch.');
	foreach (array_unique(array($input['actor'] ?? '', $input['triggering-actor'] ?? '')) as $actor) {
		release_require(is_string($actor) && 1 === preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*(?:\[bot\])?$/D', $actor), 'Missing or invalid recovery actor.');
		$permission = $api($base . '/collaborators/' . rawurlencode($actor) . '/permission');
		release_require(($permission['user']['login'] ?? null) === $actor && in_array($permission['permission'] ?? null, array('admin', 'write', 'maintain'), true), 'Recovery actor requires repository write authorization.');
	}
	$workflow = $api($base . '/actions/workflows/release.yml');
	release_require('.github/workflows/release.yml' === ($workflow['path'] ?? null) && 'active' === ($workflow['state'] ?? null), 'Release workflow identity mismatch.');
	$workflow_id = recovery_id($workflow['id'] ?? null);
	$run = $api($base . '/actions/runs/' . $run_id);
	recovery_run($run, $repository, $repository_id, $workflow_id, $run_id);
	$artifact = $api($base . '/actions/artifacts/' . $artifact_id);
	release_require(recovery_id($artifact['id'] ?? null) === $artifact_id, 'Artifact ID mismatch.');
	release_require(is_string($artifact['name'] ?? null) && 1 === preg_match('/^release-' . $run_id . '-([1-9][0-9]{0,17})$/D', $artifact['name'], $match), 'Artifact name does not bind the original run and attempt.');
	$attempt = $match[1];
	release_require((int) $attempt <= (int) $run['run_attempt'], 'Artifact attempt is newer than the original run.');
	release_require(false === ($artifact['expired'] ?? null) && recovery_time($artifact['expires_at'] ?? null) > $now && is_int($artifact['size_in_bytes'] ?? null) && $artifact['size_in_bytes'] > 0, 'Artifact is expired or empty.');
	release_require(is_string($artifact['digest'] ?? null) && 1 === preg_match('/^sha256:[a-f0-9]{64}$/D', $artifact['digest']), 'Artifact is missing its immutable archive digest.');
	$identity = $artifact['workflow_run'] ?? array();
	release_require(recovery_id($identity['id'] ?? null) === $run_id && recovery_id($identity['repository_id'] ?? null) === $repository_id && recovery_id($identity['head_repository_id'] ?? null) === $repository_id && ($identity['head_sha'] ?? null) === $run['head_sha'] && ($identity['head_branch'] ?? null) === $run['head_branch'], 'Artifact run/source/repository mismatch.');
	$artifacts = recovery_collection($api, $base . '/actions/runs/' . $run_id . '/artifacts', 'artifacts');
	$matches = array_values(array_filter($artifacts, static fn($item) => ($item['name'] ?? null) === $artifact['name']));
	release_require(1 === count($matches), 'Artifact membership is absent or ambiguous.');
	foreach (array('id', 'name', 'expired', 'size_in_bytes', 'digest', 'created_at', 'expires_at') as $field) {
		release_require(($matches[0][$field] ?? null) === $artifact[$field], 'Artifact membership is inconsistent.');
	}
	foreach ($identity as $field => $value) {
		release_require(($matches[0]['workflow_run'][$field] ?? null) === $value, 'Artifact membership source is inconsistent.');
	}
	$original = $api($base . '/actions/runs/' . $run_id . '/attempts/' . $attempt);
	recovery_run($original, $repository, $repository_id, $workflow_id, $run_id);
	release_require(recovery_id($original['run_attempt']) === $attempt && $original['head_sha'] === $run['head_sha'] && $original['head_branch'] === $run['head_branch'], 'Original attempt/source mismatch.');
	$jobs = recovery_collection($api, $base . '/actions/runs/' . $run_id . '/attempts/' . $attempt . '/jobs', 'jobs');
	$qa = array_values(array_filter($jobs, static fn($job) => '5 - Validate Release' === ($job['name'] ?? null)));
	release_require(1 === count($qa), 'Expected exactly one original Validate Release job.');
	$qa = $qa[0];
	release_require('completed' === ($qa['status'] ?? null) && 'success' === ($qa['conclusion'] ?? null), 'Original Validate Release job did not succeed.');
	release_require(recovery_id($qa['run_id'] ?? null) === $run_id && recovery_id($qa['run_attempt'] ?? null) === $attempt && ($qa['head_sha'] ?? null) === $run['head_sha'] && ($qa['head_branch'] ?? null) === $run['head_branch'], 'QA job source/run/attempt mismatch.');
	release_require(is_array($qa['steps'] ?? null), 'Missing original QA steps.');
	$required = array(
		'Gate exact tag and current main ancestry',
		'Gate static and unit QA before packaging or production approval',
		'Test release safeguard regressions',
		'Validate source metadata',
		'Build once and test the package with pinned and current Plugin Check',
		'Seal original ZIP, scoped notes, listing assets and source manifest',
		'Upload immutable run-scoped validated bundle',
	);
	foreach ($required as $name) {
		$steps = array_values(array_filter($qa['steps'], static fn($step) => is_array($step) && ($step['name'] ?? null) === $name));
		release_require(1 === count($steps) && 'completed' === ($steps[0]['status'] ?? null) && 'success' === ($steps[0]['conclusion'] ?? null), 'Missing, ambiguous or failed required QA step: ' . $name);
	}
	$uploaded = recovery_time($artifact['created_at'] ?? null);
	$upload = $steps[0];
	release_require(recovery_time($qa['started_at'] ?? null) <= $uploaded && $uploaded <= recovery_time($qa['completed_at'] ?? null) && recovery_time($upload['started_at'] ?? null) <= $uploaded && $uploaded <= recovery_time($upload['completed_at'] ?? null), 'Artifact was not created during the successful QA upload.');
	$tag = $api($base . '/git/ref/tags/' . $run['head_branch']);
	release_require(($tag['ref'] ?? null) === 'refs/tags/' . $run['head_branch'], 'Original release ref mismatch.');
	$tag_sha = recovery_sha($tag['object']['sha'] ?? null);
	if ('tag' === ($tag['object']['type'] ?? null)) {
		$annotation = $api($base . '/git/tags/' . $tag_sha);
		release_require(($annotation['sha'] ?? null) === $tag_sha && ($annotation['tag'] ?? null) === $run['head_branch'] && 'commit' === ($annotation['object']['type'] ?? null), 'Invalid annotated release tag.');
		$peeled = recovery_sha($annotation['object']['sha'] ?? null);
	} else {
		release_require('commit' === ($tag['object']['type'] ?? null), 'Invalid release tag object type.');
		$peeled = $tag_sha;
	}
	release_require($peeled === $run['head_sha'], 'Original release tag no longer identifies QA source.');
	return array(
		'version' => substr($run['head_branch'], 1),
		'source-sha' => $run['head_sha'],
		'tag-object' => $tag_sha,
		'run-id' => $run_id,
		'run-attempt' => $attempt,
		'artifact-id' => $artifact_id,
		'artifact-digest' => $artifact['digest'],
	);
}

function recovery_revalidate(array $approved, array $input, callable $api, int $now): void {
	$current = recovery_resolve($input, $api, $now);
	release_require(count($approved) === count($current), 'Incomplete approved recovery identity.');
	foreach ($current as $field => $value) {
		release_require(($approved[$field] ?? null) === $value, 'Recovery identity changed after approval: ' . $field);
	}
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	try {
		release_require(2 === count($argv) && in_array($argv[1], array('resolve', 'verify-approved'), true), 'Specify resolve or verify-approved.');
		$input = array(
			'run-id' => getenv('ORIGINAL_RUN_ID'),
			'artifact-id' => getenv('ORIGINAL_ARTIFACT_ID'),
			'repository' => getenv('GITHUB_REPOSITORY'),
			'event' => getenv('GITHUB_EVENT_NAME'),
			'ref' => getenv('GITHUB_REF'),
			'actor' => getenv('GITHUB_ACTOR'),
			'triggering-actor' => getenv('GITHUB_TRIGGERING_ACTOR'),
		);
		if ('verify-approved' === $argv[1]) {
			$approved = array();
			foreach (array('version', 'source-sha', 'tag-object', 'run-id', 'run-attempt', 'artifact-id', 'artifact-digest') as $field) {
				$approved[$field] = getenv('APPROVED_' . strtoupper(str_replace('-', '_', $field)));
			}
			recovery_revalidate($approved, $input, 'recovery_api', time());
			echo "Revalidated original QA, artifact expiry/provenance, actor authorization and complete approved recovery identity.\n";
		} else {
			$output = recovery_resolve($input, 'recovery_api', time());
			$lines = '';
			foreach ($output as $key => $value) {
				$lines .= $key . '=' . $value . "\n";
			}
			$file = getenv('GITHUB_OUTPUT');
			release_require(is_string($file) && '' !== $file && false !== file_put_contents($file, $lines, FILE_APPEND), 'Cannot write resolved recovery outputs.');
			echo "Verified original successful release QA and immutable artifact; publication still requires GitHub environment approval.\n";
		}
	} catch (RuntimeException | JsonException $error) {
		fwrite(STDERR, 'Recovery denied: ' . $error->getMessage() . "\n");
		exit(1);
	}
}
