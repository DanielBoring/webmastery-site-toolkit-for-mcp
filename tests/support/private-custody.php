<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/release-recovery.php';

final class Wstm_Test_Custody_PackFailure extends RuntimeException {
	public Throwable $secondary;

	public function __construct(Throwable $primary, Throwable $secondary) {
		parent::__construct($primary->getMessage(), 0, $primary);
		$this->secondary = $secondary;
	}
}

/**
 * Test-only byte custody. Drivers are trusted supervisor/API boundaries, not
 * values supplied by an archive. There is deliberately no default executable,
 * network client, key generation, upload, cleanup or production-export route.
 */
final class Wstm_Test_Custody {
	public const FILE_LIMIT = 33554432;
	public const PLAIN_LIMIT = 268435456;
	public const TRANSFER_LIMIT = 270532608;
	public const MANIFEST_LIMIT = 1048576;
	public const FILE_COUNT = 10000;
	public const CIPHERTEXT = 'private-test-custody.age';
	public const RECEIPT = 'WSTM_TEST_CUSTODY_V1 ';
	public const CONTEXT_RECEIPT = 'WSTM_TEST_CUSTODY_CONTEXT_V1 ';
	public const CONTEXT_FIELDS = array(
		'repository', 'repository_id', 'run_id', 'attempt', 'event', 'event_sha',
		'pull_request_head_sha', 'workflow_commit', 'workflow_path', 'workflow_ref',
	);

	public static function workflow_context(array $context): array {
		release_require(array_keys($context) === self::CONTEXT_FIELDS, 'Missing or extra workflow context fields.');
		release_require('DanielBoring/webmastery-site-toolkit-for-mcp' === $context['repository'], 'Workflow context repository mismatch.');
		foreach (array('repository_id', 'run_id', 'attempt') as $field) {
			release_require(recovery_id($context[$field]) === $context[$field], 'Noncanonical workflow context identity.');
		}
		foreach (array('event_sha', 'workflow_commit') as $field) { recovery_sha($context[$field]); }
		release_require(in_array($context['event'], array('push', 'pull_request', 'workflow_dispatch'), true), 'Unsupported workflow context event.');
		if ('pull_request' === $context['event']) {
			recovery_sha($context['pull_request_head_sha']);
		} else {
			release_require(null === $context['pull_request_head_sha'], 'Unexpected pull-request head context.');
		}
		release_require(is_string($context['workflow_path']) && 1 === preg_match('#^\.github/workflows/[a-zA-Z0-9_-]+\.ya?ml$#D', $context['workflow_path']), 'Invalid workflow context path.');
		$prefix = $context['repository'] . '/' . $context['workflow_path'] . '@';
		release_require(is_string($context['workflow_ref']) && str_starts_with($context['workflow_ref'], $prefix)
			&& 1 === preg_match('#^refs/(?:heads/[^\x00-\x20\x7f]+|tags/[^\x00-\x20\x7f]+|pull/[1-9][0-9]*/merge)$#D', substr($context['workflow_ref'], strlen($prefix))),
			'Workflow context reference mismatch.');
		return $context;
	}

	private static function hash(mixed $value): string {
		release_require(is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/D', $value), 'Invalid custody digest.');
		return $value;
	}

	private static function recipient(string $value): void {
		release_require(1 === preg_match('/^age1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{58}$/D', $value), 'Native X25519 recipient required.');
	}

	public static function name(string $name): void {
		release_require(strlen($name) > 0 && strlen($name) <= 240 && 1 === preg_match('#^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$#D', $name), 'Unsafe custody member name.');
		foreach (explode('/', $name) as $part) {
			release_require(!in_array($part, array('.', '..'), true) && '.' !== substr($part, -1), 'Unsafe custody path component.');
			release_require(1 !== preg_match('/^(?:CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/iD', $part), 'Reserved custody path component.');
		}
	}

	private static function regular(string $path, int $limit): array {
		clearstatcache(true, $path);
		release_require(file_exists($path) || is_link($path), 'Missing custody file.');
		$stat = lstat($path);
		release_require(is_array($stat) && 0100000 === ($stat['mode'] & 0170000) && 1 === $stat['nlink']
			&& $stat['size'] >= 0 && $stat['size'] <= $limit && !is_link($path), 'Missing, linked or oversized custody file.');
		release_require(realpath($path) === $path, 'Custody paths must be canonical and contain no links.');
		return $stat;
	}

	public static function pin(string $path, int $limit): array {
		$before = self::regular($path, $limit);
		$stream = fopen($path, 'rb');
		release_require(is_resource($stream), 'Cannot open custody file.');
		try {
			release_require(fstat($stream) === $before, 'Custody descriptor changed.');
			$hash = hash_init('sha256');
			$count = 0;
			while (!feof($stream)) {
				$bytes = fread($stream, 65536);
				release_require(false !== $bytes && ('' !== $bytes || feof($stream)), 'Custody read failed.');
				$count += strlen($bytes);
				release_require($count <= $limit, 'Custody read exceeded bound.');
				hash_update($hash, $bytes);
			}
			$after = self::regular($path, $limit);
			foreach (array('dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime') as $field) {
				release_require($before[$field] === $after[$field] && fstat($stream)[$field] === $after[$field], 'Custody file changed during read.');
			}
			release_require($count === $before['size'], 'Incomplete custody read.');
			return array('bytes' => $count, 'sha256' => hash_final($hash));
		} finally {
			fclose($stream);
		}
	}

	private static function exact(string $path, array $pin, int $limit): void {
		release_require(is_int($pin['bytes'] ?? null) && self::hash($pin['sha256'] ?? null) === $pin['sha256'], 'Invalid custody file pin.');
		release_require(self::pin($path, $limit) === $pin, 'Custody bytes do not match authenticated pin.');
	}

	private static function fresh(string $path): void {
		clearstatcache(true, $path);
		release_require(!file_exists($path) && !is_link($path), 'Custody destination already exists.');
		release_require(realpath(dirname($path)) === dirname($path), 'Custody parent must already be canonical and private.');
	}

	private static function write(string $path, string $bytes): void {
		self::fresh($path);
		$stream = fopen($path, 'xb');
		release_require(is_resource($stream), 'Cannot exclusively create custody output.');
		try {
			release_require(strlen($bytes) === fwrite($stream, $bytes) && fflush($stream), 'Incomplete custody write.');
		} finally {
			fclose($stream);
		}
	}

	/** Driver must retain original statuses and bounded binary stdout/stderr. */
	private static function age(array $binary, array $arguments, string $output, int $limit, callable $capture): void {
		self::exact($binary['path'], array('bytes' => $binary['bytes'], 'sha256' => $binary['sha256']), self::TRANSFER_LIMIT);
		self::fresh($output);
		$result = $capture(array_merge(array($binary['path']), $arguments), $output, array(
			'stdout_bytes' => $limit, 'stderr_bytes' => 65536, 'timeout_seconds' => 600,
			'exclusive_binary_output' => true, 'preserve_failure' => true,
		));
		// Early authenticated age chunks are not a complete authenticated message.
		release_require(0 === ($result['native_exit'] ?? null) && true === ($result['capture_complete'] ?? null)
			&& true === ($result['stdout_eof'] ?? null) && true === ($result['stderr_eof'] ?? null)
			&& false === ($result['overflow'] ?? null) && $output === ($result['stdout_path'] ?? null),
			'Age or its original capture failed; retain quarantine without interpretation.');
		self::exact($binary['path'], array('bytes' => $binary['bytes'], 'sha256' => $binary['sha256']), self::TRANSFER_LIMIT);
		release_require(is_array($result['stdout_pin'] ?? null), 'Missing original capture pin.');
		self::exact($output, $result['stdout_pin'], $limit);
	}

	private static function content_binding(array $grant): array {
		// Artifact IDs exist only after upload; never create a circular manifest.
		unset($grant['artifact_id']);
		return $grant;
	}

	/**
	 * The API driver authenticates TLS/GitHub responses and preserves originals.
	 * The job-log driver downloads ONLY this exact authenticated job's original
	 * log, with a bounded transfer; no caller-supplied or encrypted receipt.
	 */
	public static function origin(array $grant, callable $api, callable $job_log, int $now): array {
		release_require('DanielBoring/webmastery-site-toolkit-for-mcp' === ($grant['repository'] ?? null), 'Custody repository mismatch.');
		foreach (array('repository_id', 'head_repository_id', 'workflow_id', 'run_id', 'attempt', 'job_id', 'artifact_id') as $field) {
			release_require(recovery_id($grant[$field] ?? null) === $grant[$field], 'Noncanonical custody identity.');
		}
		foreach (array('head_sha', 'checkout_sha', 'checkout_tree') as $field) { recovery_sha($grant[$field] ?? null); }
		$expected_context = array();
		foreach (self::CONTEXT_FIELDS as $field) {
			release_require(array_key_exists($field, $grant), 'Missing workflow context grant field.');
			$expected_context[$field] = $grant[$field];
		}
		self::workflow_context($expected_context);
		self::hash($grant['workflow_sha256'] ?? null);
		self::recipient($grant['recipient'] ?? '');
		release_require(1 === preg_match('/^[a-f0-9]{32}$/D', $grant['nonce'] ?? ''), 'Missing custody window.');
		release_require(recovery_time($grant['deadline'] ?? null) > $now, 'Custody grant expired.');
		release_require(1 === preg_match('#^\.github/workflows/[a-zA-Z0-9_-]+\.ya?ml$#D', $grant['workflow_path'] ?? ''), 'Invalid workflow path.');
		release_require(in_array($grant['event'] ?? null, array('push', 'pull_request', 'workflow_dispatch'), true), 'Unsupported custody workflow event.');
		$base = 'repos/' . $grant['repository'];
		$run = $api($base . '/actions/runs/' . $grant['run_id'] . '/attempts/' . $grant['attempt']);
		foreach (array('id' => 'run_id', 'run_attempt' => 'attempt', 'workflow_id' => 'workflow_id') as $field => $key) {
			release_require(recovery_id($run[$field] ?? null) === $grant[$key], 'Wrong custody run, attempt or workflow.');
		}
		release_require(($run['repository']['full_name'] ?? null) === $grant['repository']
			&& recovery_id($run['repository']['id'] ?? null) === $grant['repository_id']
			&& recovery_id($run['head_repository']['id'] ?? null) === $grant['head_repository_id']
			&& ($run['head_sha'] ?? null) === $grant['head_sha'] && ($run['path'] ?? null) === $grant['workflow_path']
			&& ($run['event'] ?? null) === $grant['event'] && 'completed' === ($run['status'] ?? null), 'Wrong custody source/run identity.');
		// The executed workflow commit is not the run head, PR head or checkout.
		$workflow = $api($base . '/contents/' . $grant['workflow_path'] . '?ref=' . $grant['workflow_commit']);
		release_require('base64' === ($workflow['encoding'] ?? null) && is_string($workflow['content'] ?? null), 'Missing original workflow bytes.');
		$workflow_bytes = base64_decode(str_replace(array("\r", "\n"), '', $workflow['content']), true);
		release_require(false !== $workflow_bytes && hash('sha256', $workflow_bytes) === $grant['workflow_sha256'], 'Original workflow source mismatch.');
		$commit = $api($base . '/git/commits/' . $grant['checkout_sha']);
		release_require(($commit['sha'] ?? null) === $grant['checkout_sha'] && ($commit['tree']['sha'] ?? null) === $grant['checkout_tree'], 'Checkout commit/tree mismatch.');
		$jobs = recovery_collection($api, $base . '/actions/runs/' . $grant['run_id'] . '/attempts/' . $grant['attempt'] . '/jobs', 'jobs');
		$jobs = array_values(array_filter($jobs, static fn($job) => recovery_id($job['id'] ?? null) === $grant['job_id']));
		release_require(1 === count($jobs), 'Missing exact attempt job.');
		$job = $jobs[0];
		release_require(recovery_id($job['run_id'] ?? null) === $grant['run_id'] && recovery_id($job['run_attempt'] ?? null) === $grant['attempt']
			&& ($job['head_sha'] ?? null) === $grant['head_sha'] && ($job['name'] ?? null) === $grant['job_name']
			&& 'completed' === ($job['status'] ?? null), 'Wrong custody job.');
		$steps = array_values(array_filter($job['steps'] ?? array(), static fn($step) => ($step['name'] ?? null) === 'Upload encrypted private test custody'));
		release_require(1 === count($steps) && 'completed' === ($steps[0]['status'] ?? null) && 'success' === ($steps[0]['conclusion'] ?? null), 'No successful custody upload step.');
		$context_steps = array_values(array_filter($job['steps'] ?? array(), static fn($step) => ($step['name'] ?? null) === 'Record executed workflow identity'));
		release_require(1 === count($context_steps) && 'completed' === ($context_steps[0]['status'] ?? null)
			&& 'success' === ($context_steps[0]['conclusion'] ?? null), 'No successful workflow context receipt step.');
		$artifact = $api($base . '/actions/artifacts/' . $grant['artifact_id']);
		$name = 'test-custody-' . $grant['run_id'] . '-' . $grant['attempt'] . '-' . $grant['job_id'] . '-' . $grant['nonce'];
		release_require(recovery_id($artifact['id'] ?? null) === $grant['artifact_id'] && ($artifact['name'] ?? null) === $name
			&& false === ($artifact['expired'] ?? null) && recovery_time($artifact['expires_at'] ?? null) > $now
			&& is_int($artifact['size_in_bytes'] ?? null) && $artifact['size_in_bytes'] > 0 && $artifact['size_in_bytes'] <= self::TRANSFER_LIMIT,
			'Expired, oversized or wrong custody artifact.');
		release_require(is_string($artifact['digest'] ?? null) && 1 === preg_match('/^sha256:[a-f0-9]{64}$/D', $artifact['digest']), 'Artifact digest is mandatory.');
		foreach (array('id' => 'run_id', 'repository_id' => 'repository_id', 'head_repository_id' => 'head_repository_id') as $field => $key) {
			release_require(recovery_id($artifact['workflow_run'][$field] ?? null) === $grant[$key], 'Artifact origin mismatch.');
		}
		release_require(($artifact['workflow_run']['head_sha'] ?? null) === $grant['head_sha'], 'Artifact head mismatch.');
		$created = recovery_time($artifact['created_at'] ?? null);
		release_require(recovery_time($steps[0]['started_at'] ?? null) <= $created && $created <= recovery_time($steps[0]['completed_at'] ?? null), 'Artifact outside exact upload interval.');
		$members = recovery_collection($api, $base . '/actions/runs/' . $grant['run_id'] . '/artifacts', 'artifacts');
		$members = array_values(array_filter($members, static fn($item) => ($item['name'] ?? null) === $name));
		release_require(1 === count($members) && $members[0] === $artifact, 'Artifact membership changed or is ambiguous.');
		$log = $job_log($base . '/actions/jobs/' . $grant['job_id'] . '/logs', self::MANIFEST_LIMIT);
		release_require(is_string($log) && strlen($log) <= self::MANIFEST_LIMIT, 'Missing bounded authenticated job log.');
		preg_match_all('/^(?:[0-9T:.Z-]+ )?' . preg_quote(self::RECEIPT, '/') . '(\{[^\r\n]+\})\r?$/m', $log, $matches);
		release_require(1 === count($matches[1]), 'Missing or ambiguous authenticated upload receipt.');
		$receipt = json_decode($matches[1][0], true, 32, JSON_THROW_ON_ERROR);
		release_require(is_array($receipt) && ($receipt['grant'] ?? null) === $grant
			&& ($receipt['artifact_digest'] ?? null) === $artifact['digest'], 'Authenticated receipt origin mismatch.');
		preg_match_all('/^(?:[0-9T:.Z-]+ )?' . preg_quote(self::CONTEXT_RECEIPT, '/') . '(\{[^\r\n]+\})\r?$/m', $log, $context_matches);
		release_require(1 === count($context_matches[1]), 'Missing or ambiguous authenticated workflow context receipt.');
		$context = json_decode($context_matches[1][0], true, 32, JSON_THROW_ON_ERROR);
		release_require(is_array($context) && self::workflow_context($context) === $expected_context, 'Authenticated workflow context differs from grant.');
		self::hash($receipt['ciphertext']['sha256'] ?? null);
		release_require(is_int($receipt['ciphertext']['bytes'] ?? null) && $receipt['ciphertext']['bytes'] > 0
			&& $receipt['ciphertext']['bytes'] <= self::TRANSFER_LIMIT, 'Invalid ciphertext size.');
		return array('grant' => $grant, 'artifact' => $artifact, 'receipt' => $receipt);
	}

	private static function zip(string $path): ZipArchive {
		self::regular($path, self::TRANSFER_LIMIT);
		$zip = new ZipArchive();
		release_require(true === $zip->open($path, ZipArchive::RDONLY), 'Cannot read custody archive.');
		return $zip;
	}

	private static function member_stream(ZipArchive $zip, string $name, int $limit): array {
		$stat = $zip->statName($name, ZipArchive::FL_UNCHANGED);
		release_require(is_array($stat) && $stat['size'] <= $limit && $stat['size'] >= 0, 'Missing or oversized custody member.');
		release_require($zip->getExternalAttributesName($name, $opsys, $attributes), 'Missing archive type.');
		$type = ($attributes >> 16) & 0170000;
		release_require(0 === $type || 0100000 === $type, 'Archive links and special entries refused.');
		$stream = $zip->getStream($name);
		release_require(is_resource($stream), 'Cannot read custody member.');
		return array($stream, $stat['size']);
	}

	private static function member(ZipArchive $zip, string $name, int $limit): string {
		list($stream, $size) = self::member_stream($zip, $name, $limit);
		try {
			$bytes = stream_get_contents($stream, $limit + 1);
			release_require(is_string($bytes) && strlen($bytes) === $size && strlen($bytes) <= $limit && feof($stream), 'Incomplete or oversized custody member.');
			return $bytes;
		} finally {
			fclose($stream);
		}
	}

	private static function copy_member(ZipArchive $zip, string $name, string $output, int $limit): void {
		self::fresh($output);
		list($input, $size) = self::member_stream($zip, $name, $limit);
		try {
			$stream = fopen($output, 'xb');
			release_require(is_resource($stream), 'Cannot exclusively create custody output.');
			try {
				$count = 0;
				while (!feof($input)) {
					$bytes = fread($input, 65536);
					release_require(false !== $bytes && ('' !== $bytes || feof($input)), 'Custody archive read failed.');
					$count += strlen($bytes);
					release_require($count <= $limit && $count <= $size, 'Custody member exceeds bound.');
					release_require(strlen($bytes) === fwrite($stream, $bytes), 'Incomplete custody member write.');
				}
				release_require($count === $size && fflush($stream), 'Incomplete custody member copy.');
			} finally {
				fclose($stream);
			}
		} finally {
			fclose($input);
		}
	}

	/** Closed explicit regular-file input; nonregular observations stay JSON data. */
	public static function pack(array $grant, array $files, array $observations, string $archive, ?ZipArchive $writer = null): array {
		release_require(count($files) > 0 && count($files) <= self::FILE_COUNT, 'Invalid custody file count.');
		self::fresh($archive);
		$zip = $writer ?? new ZipArchive();
		release_require(true === $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL), 'Cannot exclusively create private archive.');
		$manifest = array('version' => 1, 'grant' => self::content_binding($grant), 'files' => array(), 'observations' => $observations);
		$seen = array();
		$total = 0;
		$primary = null;
		try {
			foreach ($files as $name => $path) {
				self::name($name);
				release_require(!isset($seen[strtolower($name)]), 'Case-colliding custody names.');
				$seen[strtolower($name)] = true;
				$pin = self::pin($path, self::FILE_LIMIT);
				$total += $pin['bytes'];
				release_require($total <= self::PLAIN_LIMIT, 'Private archive exceeds total bound.');
				$member = sprintf('files/%05d', count($manifest['files']));
				$manifest['files'][] = array('name' => $name, 'member' => $member, 'pin' => $pin);
				release_require($zip->addFile($path, $member) && $zip->setCompressionName($member, ZipArchive::CM_STORE), 'Cannot store original custody bytes.');
			}
			$json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
			release_require(strlen($json) <= self::MANIFEST_LIMIT && $total + strlen($json) <= self::PLAIN_LIMIT, 'Private manifest exceeds bound.');
			release_require($zip->addFromString('manifest.json', $json) && $zip->setCompressionName('manifest.json', ZipArchive::CM_STORE), 'Cannot store private manifest.');
		} catch (Throwable $error) {
			$primary = $error;
		}
		try {
			release_require($zip->close(), 'Cannot finish private archive; retain partial.');
		} catch (Throwable $secondary) {
			if (null !== $primary) { throw new Wstm_Test_Custody_PackFailure($primary, $secondary); }
			throw $secondary;
		}
		if (null !== $primary) { throw $primary; }
		foreach ($manifest['files'] as $entry) { self::exact($files[$entry['name']], $entry['pin'], self::FILE_LIMIT); }
		self::inspect($archive, $grant, hash('sha256', $json));
		return array('archive' => self::pin($archive, self::TRANSFER_LIMIT), 'manifest_sha256' => hash('sha256', $json));
	}

	public static function encrypt(array $binary, array $grant, string $archive, array $archive_pin, string $ciphertext, callable $capture): array {
		self::recipient($grant['recipient']);
		self::exact($archive, $archive_pin, self::TRANSFER_LIMIT);
		self::age($binary, array('--encrypt', '--recipient', $grant['recipient'], $archive), $ciphertext, self::TRANSFER_LIMIT, $capture);
		self::exact($archive, $archive_pin, self::TRANSFER_LIMIT);
		return self::pin($ciphertext, self::TRANSFER_LIMIT);
	}

	/** Inspect only a completely authenticated archive; never use extractTo(). */
	public static function inspect(string $archive, array $grant, ?string $manifest_hash = null): array {
		if (null !== $manifest_hash) { self::hash($manifest_hash); }
		$zip = self::zip($archive);
		try {
			release_require($zip->numFiles >= 2 && $zip->numFiles <= self::FILE_COUNT + 1, 'Invalid closed archive count.');
			$json = self::member($zip, 'manifest.json', self::MANIFEST_LIMIT);
			release_require(null === $manifest_hash || hash('sha256', $json) === $manifest_hash, 'Original private manifest changed.');
			$manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
			release_require(1 === ($manifest['version'] ?? null) && ($manifest['grant'] ?? null) === self::content_binding($grant)
				&& is_array($manifest['files'] ?? null) && count($manifest['files']) + 1 === $zip->numFiles, 'Private manifest identity/count mismatch.');
			$members = array('manifest.json' => true);
			$names = array();
			$total = strlen($json);
			foreach ($manifest['files'] as $index => $entry) {
				self::name($entry['name']);
				release_require(!isset($names[strtolower($entry['name'])]), 'Case-colliding private names.');
				$names[strtolower($entry['name'])] = true;
				release_require($entry['member'] === sprintf('files/%05d', $index), 'Noncanonical private member.');
				$members[$entry['member']] = true;
				$bytes = self::member($zip, $entry['member'], self::FILE_LIMIT);
				release_require(array('bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)) === $entry['pin'], 'Private member bytes differ.');
				$total += strlen($bytes);
				release_require($total <= self::PLAIN_LIMIT, 'Private expanded bytes exceed bound.');
			}
			for ($i = 0; $i < $zip->numFiles; ++$i) {
				$name = $zip->getNameIndex($i, ZipArchive::FL_UNCHANGED);
				release_require(isset($members[$name]), 'Extra or duplicate private member.');
				unset($members[$name]);
			}
			release_require(array() === $members, 'Missing private member.');
			return array('manifest' => $manifest, 'manifest_sha256' => hash('sha256', $json));
		} finally {
			$zip->close();
		}
	}

	/**
	 * Download is already retained by the trusted bounded API driver. All output
	 * parents must be in its independently approved private workspace. Identity
	 * access/ACL/provenance approval is external; this function never reads a key.
	 */
	public static function receive(array $grant, callable $api, callable $job_log, array $binary, string $identity,
		string $download, string $ciphertext, string $quarantine, string $destination, callable $capture, callable $clock): array {
		$origin = self::origin($grant, $api, $job_log, $clock());
		self::exact($download, array('bytes' => $origin['artifact']['size_in_bytes'], 'sha256' => substr($origin['artifact']['digest'], 7)), self::TRANSFER_LIMIT);
		$outer = self::zip($download);
		try {
			release_require(1 === $outer->numFiles && self::CIPHERTEXT === $outer->getNameIndex(0), 'Only the fixed ciphertext artifact member is allowed.');
			self::copy_member($outer, self::CIPHERTEXT, $ciphertext, self::TRANSFER_LIMIT);
		} finally {
			$outer->close();
		}
		self::exact($ciphertext, $origin['receipt']['ciphertext'], self::TRANSFER_LIMIT);
		self::regular($identity, 65536);
		self::age($binary, array('--decrypt', '--identity', $identity, $ciphertext), $quarantine, self::TRANSFER_LIMIT, $capture);
		release_require(self::origin($grant, $api, $job_log, $clock()) === $origin, 'GitHub custody origin changed after download/decryption.');
		self::exact($ciphertext, $origin['receipt']['ciphertext'], self::TRANSFER_LIMIT);
		$plain_pin = self::pin($quarantine, self::TRANSFER_LIMIT);
		// The authenticated job's exact ciphertext binds this private manifest;
		// no private file or manifest fingerprints need to enter public logs.
		$inspected = self::inspect($quarantine, $grant);
		$manifest = $inspected['manifest'];
		self::fresh($destination);
		release_require(mkdir($destination, 0700), 'Cannot exclusively reserve private readback directory.');
		$zip = self::zip($quarantine);
		try {
			foreach ($manifest['files'] as $entry) {
				// Flat synthetic output names avoid recreating any producer path/link.
				$path = $destination . DIRECTORY_SEPARATOR . basename($entry['member']);
				self::copy_member($zip, $entry['member'], $path, self::FILE_LIMIT);
				self::exact($path, $entry['pin'], self::FILE_LIMIT);
			}
			self::write($destination . DIRECTORY_SEPARATOR . 'manifest.json', self::member($zip, 'manifest.json', self::MANIFEST_LIMIT));
			release_require(hash_file('sha256', $destination . DIRECTORY_SEPARATOR . 'manifest.json') === $inspected['manifest_sha256'], 'Private manifest readback differs.');
			release_require(count(scandir($destination)) === count($manifest['files']) + 3, 'Unexpected private readback member.');
		} finally {
			$zip->close();
		}
		self::exact($quarantine, $plain_pin, self::TRANSFER_LIMIT);
		return array('custody' => 'private_byte_copies_and_original_observations', 'test_acceptance' => 'not_asserted',
			'producer_survival_until_receiver_ack' => 'unresolved', 'manifest_sha256' => $inspected['manifest_sha256']);
	}
}
