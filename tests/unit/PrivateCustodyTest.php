<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/support/private-custody.php';

/** These are mocked protocol/real ZIP tests, never real age or GitHub proof. */
final class PrivateCustodyTest extends TestCase {
	private string $root;
	private array $grant;
	private array $responses;
	private string $log;
	private array $binary;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-private-custody-model-' . bin2hex(random_bytes(16));
		self::assertTrue(mkdir($this->root, 0700));
		$this->root = realpath($this->root);
		$workflow = 'reviewed source fixture; not a real workflow';
		$this->grant = array(
			'repository' => 'DanielBoring/webmastery-site-toolkit-for-mcp', 'repository_id' => '1', 'head_repository_id' => '1',
			'workflow_id' => '2', 'run_id' => '3', 'attempt' => '4', 'job_id' => '5', 'artifact_id' => '6',
			'head_sha' => str_repeat('a', 40), 'checkout_sha' => str_repeat('b', 40), 'checkout_tree' => str_repeat('c', 40),
			'workflow_commit' => str_repeat('d', 40), 'event_sha' => str_repeat('e', 40), 'pull_request_head_sha' => str_repeat('f', 40),
			'workflow_ref' => 'DanielBoring/webmastery-site-toolkit-for-mcp/.github/workflows/unit-tests.yml@refs/pull/166/merge',
			'workflow_sha256' => hash('sha256', $workflow), 'workflow_path' => '.github/workflows/unit-tests.yml',
			'event' => 'pull_request', 'job_name' => 'Unit tests (8.4)', 'nonce' => str_repeat('d', 32),
			'recipient' => 'age1' . str_repeat('q', 58), 'deadline' => '2099-01-01T00:00:00Z',
		);
		$base = 'repos/' . $this->grant['repository'];
		$job = array(
			'id' => 5, 'run_id' => 3, 'run_attempt' => 4, 'head_sha' => str_repeat('a', 40),
			'name' => 'Unit tests (8.4)', 'status' => 'completed', 'conclusion' => 'failure',
			'steps' => array(
				array('name' => 'Record executed workflow identity', 'status' => 'completed', 'conclusion' => 'success'),
				array('name' => 'Upload encrypted private test custody', 'status' => 'completed', 'conclusion' => 'success',
					'started_at' => '2026-01-01T00:00:00Z', 'completed_at' => '2026-01-01T00:00:02Z'),
			),
		);
		$this->responses = array(
			$base . '/actions/runs/3/attempts/4' => array(
				'id' => 3, 'run_attempt' => 4, 'workflow_id' => 2, 'repository' => array('full_name' => $this->grant['repository'], 'id' => 1),
				'head_repository' => array('id' => 1), 'head_sha' => str_repeat('a', 40), 'path' => $this->grant['workflow_path'],
				'event' => 'pull_request', 'status' => 'completed', 'conclusion' => 'failure',
			),
			$base . '/contents/' . $this->grant['workflow_path'] . '?ref=' . $this->grant['workflow_commit'] => array('encoding' => 'base64', 'content' => base64_encode($workflow)),
			$base . '/git/commits/' . $this->grant['checkout_sha'] => array('sha' => $this->grant['checkout_sha'], 'tree' => array('sha' => $this->grant['checkout_tree'])),
			$base . '/actions/runs/3/attempts/4/jobs?per_page=100&page=1' => array('total_count' => 1, 'jobs' => array($job)),
		);
		$this->write('binary.mock', 'NOT AN AGE EXECUTABLE');
		$this->binary = array_merge(array('path' => $this->path('binary.mock')), Wstm_Test_Custody::pin($this->path('binary.mock'), 1024));
		$this->write('identity.mock', 'NOT A PRIVATE KEY');
	}

	private function path(string $name): string { return $this->root . DIRECTORY_SEPARATOR . $name; }

	private function write(string $name, string $bytes): void {
		$stream = fopen($this->path($name), 'xb');
		self::assertIsResource($stream);
		self::assertSame(strlen($bytes), fwrite($stream, $bytes));
		fclose($stream);
	}

	private function api(string $path): array {
		self::assertArrayHasKey($path, $this->responses, 'Unexpected authenticated API path.');
		return $this->responses[$path];
	}

	private function jobLog(string $path, int $limit): string {
		self::assertSame('repos/' . $this->grant['repository'] . '/actions/jobs/5/logs', $path);
		self::assertSame(Wstm_Test_Custody::MANIFEST_LIMIT, $limit);
		return $this->log;
	}

	private function fixture(): array {
		$this->write('stream.original', "raw\0\"\\\r\n" . "\xc3\xa9");
		$manifest = Wstm_Test_Custody::pack($this->grant, array('stdout.original' => $this->path('stream.original')),
			array('link_fixture' => array('kind' => 'symlink', 'target' => '../../never-recreate'), 'native_exit' => 1), $this->path('plain.zip'));
		$cipher = 'MOCK-CIPHERTEXT-NOT-AGE:' . file_get_contents($this->path('plain.zip'));
		$this->write('cipher.original', $cipher);
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->path('download.zip'), ZipArchive::CREATE | ZipArchive::EXCL));
		self::assertTrue($zip->addFromString(Wstm_Test_Custody::CIPHERTEXT, $cipher));
		self::assertTrue($zip->close());
		$download = Wstm_Test_Custody::pin($this->path('download.zip'), Wstm_Test_Custody::TRANSFER_LIMIT);
		$artifact = array(
			'id' => 6, 'name' => 'test-custody-3-4-5-' . $this->grant['nonce'], 'expired' => false,
			'expires_at' => '2099-01-01T00:00:00Z', 'created_at' => '2026-01-01T00:00:01Z',
			'size_in_bytes' => $download['bytes'], 'digest' => 'sha256:' . $download['sha256'],
			'workflow_run' => array('id' => 3, 'repository_id' => 1, 'head_repository_id' => 1, 'head_sha' => $this->grant['head_sha']),
		);
		$this->responses['repos/' . $this->grant['repository'] . '/actions/artifacts/6'] = $artifact;
		$this->responses['repos/' . $this->grant['repository'] . '/actions/runs/3/artifacts?per_page=100&page=1'] = array('total_count' => 1, 'artifacts' => array($artifact));
		$this->log = '2026-01-01T00:00:02.0000000Z ' . Wstm_Test_Custody::RECEIPT . json_encode(array(
			'grant' => $this->grant, 'artifact_digest' => $artifact['digest'],
			'ciphertext' => Wstm_Test_Custody::pin($this->path('cipher.original'), Wstm_Test_Custody::TRANSFER_LIMIT),
		), JSON_THROW_ON_ERROR) . "\n";
		$this->log .= Wstm_Test_Custody::CONTEXT_RECEIPT . json_encode($this->context(), JSON_THROW_ON_ERROR) . "\n";
		return $manifest;
	}

	private function context(): array {
		$context = array();
		foreach (Wstm_Test_Custody::CONTEXT_FIELDS as $field) { $context[$field] = $this->grant[$field]; }
		return $context;
	}

	public function test_executed_workflow_source_is_not_inferred_from_any_head_or_checkout(): void {
		$this->fixture();
		$requests = array();
		$result = Wstm_Test_Custody::origin($this->grant, function (string $path) use (&$requests): array {
			$requests[] = $path;
			return $this->api($path);
		}, fn($path, $limit) => $this->jobLog($path, $limit), 1767225610);
		$prefix = 'repos/' . $this->grant['repository'] . '/contents/' . $this->grant['workflow_path'] . '?ref=';
		self::assertContains($prefix . $this->grant['workflow_commit'], $requests);
		foreach (array('head_sha', 'checkout_sha', 'event_sha', 'pull_request_head_sha') as $field) {
			self::assertNotContains($prefix . $this->grant[$field], $requests);
		}
		self::assertSame($this->grant, $result['grant']);
	}

	public static function workflowContextMutations(): array {
		return array_map(static fn($name) => array($name), array(
			'missing-receipt', 'duplicate-receipt', 'missing-field', 'commit', 'reference',
			'event-sha', 'pr-head', 'extra-field', 'failed-step', 'missing-step', 'duplicate-step',
			'run-not-completed', 'job-not-completed', 'missing-grant-commit', 'missing-grant-reference',
		));
	}

	/** @dataProvider workflowContextMutations */
	public function test_workflow_context_missing_substitution_and_lifetime_guards_fail_before_age(string $mutation): void {
		$this->fixture();
		$context = $this->context();
		$original = Wstm_Test_Custody::CONTEXT_RECEIPT . json_encode($context, JSON_THROW_ON_ERROR) . "\n";
		if ('missing-receipt' === $mutation) { $this->log = str_replace($original, '', $this->log); }
		if ('duplicate-receipt' === $mutation) { $this->log .= $original; }
		if ('missing-field' === $mutation) { unset($context['workflow_commit']); }
		if ('commit' === $mutation) { $context['workflow_commit'] = $this->grant['head_sha']; }
		if ('reference' === $mutation) { $context['workflow_ref'] = str_replace('@refs/pull/166/merge', '@refs/heads/main', $context['workflow_ref']); }
		if ('event-sha' === $mutation) { $context['event_sha'] = $this->grant['head_sha']; }
		if ('pr-head' === $mutation) { $context['pull_request_head_sha'] = $this->grant['checkout_sha']; }
		if ('extra-field' === $mutation) { $context['extra'] = 'not server-bound'; }
		if ($context !== $this->context()) {
			$this->log = str_replace($original, Wstm_Test_Custody::CONTEXT_RECEIPT . json_encode($context, JSON_THROW_ON_ERROR) . "\n", $this->log);
		}
		$base = 'repos/' . $this->grant['repository'];
		$jobs = $base . '/actions/runs/3/attempts/4/jobs?per_page=100&page=1';
		if ('failed-step' === $mutation) { $this->responses[$jobs]['jobs'][0]['steps'][0]['conclusion'] = 'failure'; }
		if ('missing-step' === $mutation) { array_shift($this->responses[$jobs]['jobs'][0]['steps']); }
		if ('duplicate-step' === $mutation) { $this->responses[$jobs]['jobs'][0]['steps'][] = $this->responses[$jobs]['jobs'][0]['steps'][0]; }
		if ('run-not-completed' === $mutation) { $this->responses[$base . '/actions/runs/3/attempts/4']['status'] = 'in_progress'; }
		if ('job-not-completed' === $mutation) { $this->responses[$jobs]['jobs'][0]['status'] = 'in_progress'; }
		if ('missing-grant-commit' === $mutation) { unset($this->grant['workflow_commit']); }
		if ('missing-grant-reference' === $mutation) { unset($this->grant['workflow_ref']); }
		$this->expectException(RuntimeException::class);
		$this->receive(static function (): array { self::fail('Workflow origin must fail before age.'); });
	}

	private static function receiptStep(string $workflow): string {
		return implode("\n", array(
			'      - name: Record executed workflow identity',
			'        if: github.repository == \'DanielBoring/webmastery-site-toolkit-for-mcp\'',
			'        env:',
			'          WSTM_CUSTODY_REPOSITORY: ${{ github.repository }}',
			'          WSTM_CUSTODY_REPOSITORY_ID: ${{ github.repository_id }}',
			'          WSTM_CUSTODY_RUN_ID: ${{ github.run_id }}',
			'          WSTM_CUSTODY_ATTEMPT: ${{ github.run_attempt }}',
			'          WSTM_CUSTODY_EVENT: ${{ github.event_name }}',
			'          WSTM_CUSTODY_EVENT_SHA: ${{ github.sha }}',
			'          WSTM_CUSTODY_PULL_REQUEST_HEAD_SHA: ${{ github.event.pull_request.head.sha || \'none\' }}',
			'          WSTM_CUSTODY_WORKFLOW_COMMIT: ${{ github.workflow_sha }}',
			'          WSTM_CUSTODY_WORKFLOW_PATH: .github/workflows/' . $workflow,
			'          WSTM_CUSTODY_WORKFLOW_REF: ${{ github.workflow_ref }}',
			'        run: php tests/support/private-custody-context.php',
			'', '',
		));
	}

	public static function receiptWorkflowJobs(): array {
		return array(
			'unit' => array('unit-tests.yml', 'unit-tests', array('Checkout code', 'Set up PHP',
				'Verify restored matrix PHP and unchanged companion', 'Record executed workflow identity', 'Get Composer cache directory')),
			'contract' => array('e2e-qa.yml', 'ability-contract-qa', array('Checkout code', 'Set up host QA PHP',
				'Bind canonical host QA interpreter', 'Record executed workflow identity', 'Set up Docker Compose', 'Run Ability Contract QA')),
			'http' => array('e2e-qa.yml', 'full-mcp-e2e-qa', array('Checkout code', 'Set up host QA PHP',
				'Bind canonical host QA interpreter', 'Record executed workflow identity', 'Set up Docker Compose', 'Run Full MCP E2E QA')),
			'package' => array('release-package-qa.yml', 'release-package-qa', array('Checkout code', 'Set up PHP',
				'Record executed workflow identity', 'Test release safeguard regressions', 'Run Release Package QA')),
		);
	}

	/** @dataProvider receiptWorkflowJobs */
	public function test_receipt_step_uses_only_explicit_server_context_environment_fields(string $file, string $job, array $order): void {
		$workflow = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/' . $file));
		$pattern = '/^  ' . preg_quote($job, '/') . ':\n.*?(?=^  [a-zA-Z0-9_-]+:|\z)/ms';
		self::assertSame(1, preg_match_all($pattern, $workflow, $jobs), 'The actual runtime job must be unique.');
		$source = $jobs[0][0];
		$pattern = '/^      - name: Record executed workflow identity\n.*?(?=^      - |\z)/ms';
		self::assertSame(1, preg_match_all($pattern, $source, $steps), 'Exactly one receipt belongs to this job.');
		self::assertSame(self::receiptStep($file), $steps[0][0], 'Only the explicit server fields and fixed helper invocation are permitted.');
		$previous = -1;
		foreach ($order as $name) {
			$needle = '      - name: ' . $name . "\n";
			self::assertSame(1, substr_count($source, $needle), 'Required step must be unique: ' . $name);
			$position = strpos($source, $needle);
			self::assertNotFalse($position);
			self::assertTrue($position > $previous, 'Checkout and PHP readiness must precede receipt, then runtime: ' . $name);
			$previous = $position;
		}
		$helper = file_get_contents(dirname(__DIR__) . '/support/private-custody-context.php');
		self::assertStringNotContainsString('file_get_contents', $helper);
		self::assertStringNotContainsString('shell_exec', $helper);
		self::assertStringContainsString('Wstm_Test_Custody::workflow_context($context)', $helper);
	}

	public static function runtimeReceiptWorkflows(): array {
		return array(
			array('e2e-qa.yml', 2, 'ebba5699845902d3b844677186c1c66b0224d30100fee805c149f571c07454b0'),
			array('release-package-qa.yml', 1, 'dae903274394edb1e7bc86b564793c337dd6cdd94283b00986d71df3bfa85884'),
		);
	}

	/** @dataProvider runtimeReceiptWorkflows */
	public function test_runtime_receipts_preserve_all_other_published_53d_workflow_bytes(string $file, int $expected, string $hash): void {
		$workflow = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/' . $file));
		$original = str_replace(self::receiptStep($file), '', $workflow, $count);
		self::assertSame($expected, $count);
		self::assertSame($hash, hash('sha256', $original), 'Triggers, selectors, PHP, runtime commands, gates and evidence policies must remain unchanged.');
	}

	public static function contextCliCases(): array {
		return array(
			array('pull-request'), array('push'), array('missing-commit'), array('invalid-reference'),
			array('dispatch'), array('push-missing-pr-head'), array('push-empty-pr-head'), array('pr-sentinel'),
			'e2e-pr' => array('pull-request', 'e2e-qa.yml'),
			'e2e-dispatch' => array('dispatch', 'e2e-qa.yml'),
			'package-pr' => array('pull-request', 'release-package-qa.yml'),
			'package-dispatch' => array('dispatch', 'release-package-qa.yml'),
		);
	}

	/** @dataProvider contextCliCases */
	public function test_actual_receipt_cli_with_mock_server_environment(string $mode, string $workflow = 'unit-tests.yml'): void {
		$context = $this->context();
		$context['workflow_path'] = '.github/workflows/' . $workflow;
		$context['workflow_ref'] = str_replace('unit-tests.yml', $workflow, $context['workflow_ref']);
		if (in_array($mode, array('push', 'dispatch', 'push-missing-pr-head', 'push-empty-pr-head'), true)) {
			$context['event'] = 'dispatch' === $mode ? 'workflow_dispatch' : 'push';
			$context['pull_request_head_sha'] = null;
			$context['workflow_ref'] = str_replace('@refs/pull/166/merge', '@refs/heads/main', $context['workflow_ref']);
		}
		$environment = getenv();
		foreach ($context as $field => $value) {
			$environment['WSTM_CUSTODY_' . strtoupper($field)] = 'pull_request_head_sha' === $field && null === $value ? 'none' : $value;
		}
		if ('missing-commit' === $mode) { unset($environment['WSTM_CUSTODY_WORKFLOW_COMMIT']); }
		if ('invalid-reference' === $mode) { $environment['WSTM_CUSTODY_WORKFLOW_REF'] = 'not-a-workflow-reference'; }
		if ('push-missing-pr-head' === $mode) { unset($environment['WSTM_CUSTODY_PULL_REQUEST_HEAD_SHA']); }
		if ('push-empty-pr-head' === $mode) { $environment['WSTM_CUSTODY_PULL_REQUEST_HEAD_SHA'] = ''; }
		if ('pr-sentinel' === $mode) { $environment['WSTM_CUSTODY_PULL_REQUEST_HEAD_SHA'] = 'none'; }
		$this->write('context.native-status.initial', json_encode(array(
			'native_exit' => null, 'state' => 'not_observed',
			'limits' => 'No per-child timer or file quota; enclosing QA supervisor bounds the PHPUnit root, not independently attested descendants.',
		), JSON_THROW_ON_ERROR));
		// Exclusive file descriptors retain partial output without dual-pipe drains.
		$process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/support/private-custody-context.php'),
			array(
				1 => array('file', $this->path('context.stdout.original'), 'xb'),
				2 => array('file', $this->path('context.stderr.original'), 'xb'),
			), $pipes, $this->root, $environment);
		self::assertIsResource($process);
		$status = proc_close($process);
		$this->write('context.native-status.final', json_encode(array(
			'proc_close_return' => $status, 'native_exit' => $status >= 0 ? $status : null,
			'state' => $status >= 0 ? 'exited' : 'unknown',
		), JSON_THROW_ON_ERROR));
		$stdout = file_get_contents($this->path('context.stdout.original'));
		$stderr = file_get_contents($this->path('context.stderr.original'));
		$errors = array(
			'missing-commit' => 'Missing server workflow context',
			'invalid-reference' => 'Workflow context reference mismatch',
			'push-missing-pr-head' => 'Missing server workflow context: pull_request_head_sha',
			'push-empty-pr-head' => 'Missing server workflow context: pull_request_head_sha',
			'pr-sentinel' => 'Invalid source or tag SHA.',
		);
		if (isset($errors[$mode])) {
			self::assertSame(255, $status);
			self::assertStringNotContainsString(Wstm_Test_Custody::CONTEXT_RECEIPT, $stdout);
			self::assertStringContainsString($errors[$mode], $stdout . $stderr);
			return;
		}
		self::assertSame(0, $status);
		self::assertSame('', $stderr);
		self::assertSame(Wstm_Test_Custody::CONTEXT_RECEIPT . json_encode($context, JSON_THROW_ON_ERROR) . PHP_EOL, $stdout);
	}

	private function capture(int $exit = 0, bool $complete = true): callable {
		return function (array $argv, string $stdout, array $bounds) use ($exit, $complete): array {
			self::assertSame(array($this->binary['path'], '--decrypt', '--identity', $this->path('identity.mock'), $this->path('cipher.received')), $argv);
			self::assertSame(600, $bounds['timeout_seconds']);
			self::assertSame(65536, $bounds['stderr_bytes']);
			self::assertTrue($bounds['exclusive_binary_output']);
			// Even a complete, valid ZIP before a final-authentication failure must not be interpreted.
			$this->write(basename($stdout), file_get_contents($this->path('plain.zip')));
			return array('native_exit' => $exit, 'capture_complete' => $complete, 'stdout_eof' => true, 'stderr_eof' => true,
				'overflow' => false, 'stdout_path' => $stdout, 'stdout_pin' => Wstm_Test_Custody::pin($stdout, Wstm_Test_Custody::TRANSFER_LIMIT));
		};
	}

	private function receive(callable $capture): array {
		return Wstm_Test_Custody::receive($this->grant, fn($path) => $this->api($path), fn($path, $limit) => $this->jobLog($path, $limit),
			$this->binary, $this->path('identity.mock'), $this->path('download.zip'), $this->path('cipher.received'),
			$this->path('quarantine.zip'), $this->path('readback'), $capture, static fn() => 1767225610);
	}

	public function test_mock_success_retains_exact_bytes_without_promoting_failed_tests_or_ack(): void {
		$original = $this->fixture();
		$result = $this->receive($this->capture());
		self::assertSame('not_asserted', $result['test_acceptance']);
		self::assertSame('unresolved', $result['producer_survival_until_receiver_ack']);
		self::assertSame($original['manifest_sha256'], $result['manifest_sha256']);
		self::assertSame(file_get_contents($this->path('stream.original')), file_get_contents($this->path('readback') . DIRECTORY_SEPARATOR . '00000'));
		self::assertSame(array('.', '..', '00000', 'manifest.json'), scandir($this->path('readback')));
		self::assertStringNotContainsString('manifest_sha256', $this->log);
		self::assertStringNotContainsString('stdout.original', $this->log);
	}

	public static function failedCaptures(): array {
		return array('final chunk missing despite valid early ZIP' => array(1, true), 'native zero incomplete capture' => array(0, false));
	}

	/** @dataProvider failedCaptures */
	public function test_final_authentication_or_capture_failure_never_interprets_quarantine(int $exit, bool $complete): void {
		$this->fixture();
		try {
			$this->receive($this->capture($exit, $complete));
			self::fail('Failed decryption must refuse.');
		} catch (RuntimeException $error) {
			self::assertStringContainsString('without interpretation', $error->getMessage());
			self::assertFileExists($this->path('quarantine.zip'));
			self::assertFileDoesNotExist($this->path('readback'));
			self::assertFileExists($this->path('download.zip'));
		}
	}

	public static function substitutions(): array {
		return array('run' => array('run_id'), 'attempt' => array('attempt'), 'job' => array('job_id'),
			'artifact' => array('artifact_id'), 'head' => array('head_sha'), 'workflow' => array('workflow_sha256'),
			'checkout' => array('checkout_tree'), 'sender repository' => array('head_repository_id'));
	}

	/** @dataProvider substitutions */
	public function test_decryptable_sender_origin_substitution_is_refused_before_age(string $field): void {
		$this->fixture();
		$grant = $this->grant;
		$grant[$field] = str_contains($field, 'sha') || 'checkout_tree' === $field ? str_repeat('e', strlen($grant[$field])) : '99';
		$this->expectException(RuntimeException::class);
		Wstm_Test_Custody::origin($grant, function ($path): array {
			if (!isset($this->responses[$path])) { throw new RuntimeException('Wrong authenticated origin path.'); }
			return $this->responses[$path];
		}, fn($path, $limit) => $this->jobLog($path, $limit), 1767225610);
	}

	public function test_digest_mismatch_is_fatal_not_a_downloader_warning(): void {
		$this->fixture();
		file_put_contents($this->path('download.zip'), 'changed', FILE_APPEND);
		try {
			$this->receive(static function (): array { self::fail('Age must not run on changed download.'); });
			self::fail('Changed artifact must refuse.');
		} catch (RuntimeException $error) {
			self::assertStringContainsString('authenticated pin', $error->getMessage());
			self::assertFileDoesNotExist($this->path('cipher.received'));
		}
	}

	public function test_encrypted_self_asserted_origin_cannot_replace_authenticated_job_receipt(): void {
		$this->fixture();
		$this->log = '';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('authenticated upload receipt');
		$this->receive(static function (): array { self::fail('Missing sender proof must fail before age.'); });
	}

	public function test_archive_member_tampering_cannot_be_accepted_by_original_manifest(): void {
		$original = $this->fixture();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->path('plain.zip')));
		self::assertTrue($zip->addFromString('files/00000', 'changed'));
		self::assertTrue($zip->close());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Private member bytes differ');
		Wstm_Test_Custody::inspect($this->path('plain.zip'), $this->grant, $original['manifest_sha256']);
	}

	public function test_mock_encrypt_uses_one_native_recipient_and_stdout_not_overwriting_output_option(): void {
		$original = $this->fixture();
		$pin = Wstm_Test_Custody::encrypt($this->binary, $this->grant, $this->path('plain.zip'), $original['archive'],
			$this->path('encrypted.mock'), function (array $argv, string $stdout, array $bounds): array {
				self::assertSame(array($this->binary['path'], '--encrypt', '--recipient', $this->grant['recipient'], $this->path('plain.zip')), $argv);
				self::assertSame(Wstm_Test_Custody::TRANSFER_LIMIT, $bounds['stdout_bytes']);
				$this->write(basename($stdout), 'MOCK ciphertext, no cryptography performed');
				return array('native_exit' => 0, 'capture_complete' => true, 'stdout_eof' => true, 'stderr_eof' => true,
					'overflow' => false, 'stdout_path' => $stdout, 'stdout_pin' => Wstm_Test_Custody::pin($stdout, 1024));
			});
		self::assertSame(Wstm_Test_Custody::pin($this->path('encrypted.mock'), 1024), $pin);
	}

	public function test_destination_collision_preserves_existing_and_original_bytes(): void {
		$this->fixture();
		$this->write('cipher.received', 'must survive');
		try {
			$this->receive(static function (): array { self::fail('Existing destination must refuse before age.'); });
			self::fail('Existing destination must refuse.');
		} catch (RuntimeException $error) {
			self::assertStringContainsString('already exists', $error->getMessage());
			self::assertSame('must survive', file_get_contents($this->path('cipher.received')));
			self::assertFileExists($this->path('download.zip'));
		}
	}

	public function test_short_transfer_is_not_accepted_or_retried(): void {
		$this->fixture();
		$stream = fopen($this->path('download.zip'), 'c+b');
		self::assertTrue(ftruncate($stream, 12));
		fclose($stream);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('authenticated pin');
		$this->receive(static function (): array { self::fail('Truncated transfer must refuse before age.'); });
	}

	public function test_missing_digest_is_not_replaced_by_self_asserted_manifest(): void {
		$this->fixture();
		unset($this->responses['repos/' . $this->grant['repository'] . '/actions/artifacts/6']['digest']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('digest is mandatory');
		$this->receive(static function (): array { self::fail('Missing API digest must refuse before age.'); });
	}

	public function test_case_colliding_private_names_are_not_silently_omitted(): void {
		$this->write('original', 'same bytes');
		try {
			Wstm_Test_Custody::pack($this->grant, array('Name' => $this->path('original'), 'name' => $this->path('original')), array(), $this->path('collision.zip'));
			self::fail('Case collision must refuse.');
		} catch (RuntimeException $error) {
			self::assertStringContainsString('Case-colliding', $error->getMessage());
			self::assertFileExists($this->path('collision.zip'));
			self::assertSame('same bytes', file_get_contents($this->path('original')));
		}
	}

	public function test_early_pack_failure_preserves_primary_and_separate_close_failure(): void {
		$this->write('original', 'original bytes remain');
		$zip = $this->getMockBuilder(ZipArchive::class)->onlyMethods(array('open', 'close'))->getMock();
		$zip->expects(self::once())->method('open')->with($this->path('failed-pack.zip'), ZipArchive::CREATE | ZipArchive::EXCL)
			->willReturnCallback(function (): bool {
				$this->write('failed-pack.zip', 'MOCK partial archive before first member');
				return true;
			});
		$zip->expects(self::once())->method('close')->willReturn(false);
		try {
			Wstm_Test_Custody::pack($this->grant, array('../first-invalid-name' => $this->path('original')), array(), $this->path('failed-pack.zip'), $zip);
			self::fail('Early failure must survive a failed close.');
		} catch (Wstm_Test_Custody_PackFailure $error) {
			self::assertSame('Unsafe custody path component.', $error->getMessage());
			self::assertInstanceOf(RuntimeException::class, $error->getPrevious());
			self::assertSame($error->getMessage(), $error->getPrevious()->getMessage());
			self::assertSame('Cannot finish private archive; retain partial.', $error->secondary->getMessage());
			self::assertSame('MOCK partial archive before first member', file_get_contents($this->path('failed-pack.zip')));
			self::assertSame('original bytes remain', file_get_contents($this->path('original')));
		}
	}

	public function test_link_entry_is_never_materialized_even_with_valid_zip_bytes(): void {
		$original = $this->fixture();
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->path('plain.zip')));
		self::assertTrue($zip->setExternalAttributesName('files/00000', ZipArchive::OPSYS_UNIX, 0120777 << 16));
		self::assertTrue($zip->close());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('links and special entries');
		Wstm_Test_Custody::inspect($this->path('plain.zip'), $this->grant, $original['manifest_sha256']);
	}

	public static function unsafeNames(): array {
		return array_map(static fn($name) => array($name), array('../escape', '/absolute', 'C:/drive', '\\\\server\\share', 'name:stream', 'NUL.txt', 'con', 'dir/COM1', 'trailing.', 'a//b'));
	}

	/** @dataProvider unsafeNames */
	public function test_nonportable_archive_names_are_refused(string $name): void {
		$this->expectException(RuntimeException::class);
		Wstm_Test_Custody::name($name);
	}
}
