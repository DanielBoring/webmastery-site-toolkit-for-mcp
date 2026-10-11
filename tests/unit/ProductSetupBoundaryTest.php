<?php

declare(strict_types=1);

namespace Wstm_Setup_Model {
	final class State {
		public static array $response;
		public static array $response_queue = array();
		public static array $requests = array();
		public static array $descriptions = array();
		public static array $names;
		public static bool $action = true;
		public static array $context;
		public static int $assertions = 0;
		public static bool $context_refused = false;
		public static array $user_input;
		public static array $user;
		public static array $meta;
	}

	function wstm108_assert(bool $condition, string $message): void {
		if (!$condition) { throw new \RuntimeException($message); }
	}
	function wstm108_rpc_response(array $event): array { return \wstm108_rpc_response($event); }
	function wstm108_native_observations(array $event, int $actor): array { return \wstm108_native_observations($event, $actor); }
	function wstm108_decode_tool($tool, bool $gateway): array { return \wstm108_typed_tool($tool, $gateway); }
	function get_current_user_id(): int { return 7; }
	function wp_get_ability(string $ability) {
		if (!array_key_exists($ability, State::$descriptions)) { throw new \RuntimeException('Unmodeled native ability lookup.'); }
		return new class(State::$descriptions[$ability]) {
			private string $description;
			public function __construct(string $description) { $this->description = $description; }
			public function get_description(): string { return $this->description; }
		};
	}
	function wstm108_wire_canonical($value): string { return \wstm108_wire_canonical($value); }
	function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
	function wp_remote_post(string $url, array $options): array {
		State::$requests[] = array('url' => $url, 'options' => $options);
		return State::$response_queue ? array_shift(State::$response_queue) : State::$response;
	}
	function is_wp_error($value): bool { return $value instanceof \WP_Error; }
	function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
	function wp_remote_retrieve_body(array $response): string { return $response['body']; }
	function wp_remote_retrieve_headers(array $response): array { return $response['headers']; }
	function wp_remote_retrieve_header(array $response, string $key): string { return \wstm108_rpc_header($response['headers'], $key); }
	function doing_action(string $name): bool { return State::$action && 'mcp_adapter_init' === $name; }
	function wp_get_abilities(): array {
		return array_map(static fn($name) => new class($name) {
			private string $name;
			public function __construct(string $name) { $this->name = $name; }
			public function get_name(): string { return $this->name; }
		}, State::$names);
	}
	final class Wstm108_Files {
		public static function assert_file(string $path, array $file): void {
			if ('/qa-artifacts/context.json' !== $path || $file !== State::$context['file']) {
				throw new \RuntimeException('Context binding mismatch.');
			}
			++State::$assertions;
		}
	}
	function wp_slash($value) {
		return is_string($value) ? addslashes($value) : $value;
	}
	function wp_generate_password(int $length): string { return 'untouched-password-\\quotes"'; }
	function wp_insert_user(array $input): int {
		State::$user_input = $input;
		// Exact native sequence for this finite no-tags/no-percent payload domain:
		// sanitize_text_field, wp_filter_kses, compacted users-table wp_unslash.
		$sanitized = trim(preg_replace('/[\r\n\t ]+/', ' ', $input['display_name']));
		if (strpos($sanitized, '<') !== false || preg_match('/%[a-f0-9]{2}/i', $sanitized)) {
			throw new \RuntimeException('Outside captured native text/KSES model domain.');
		}
		State::$user = array('display_name' => stripslashes(addslashes(stripslashes($sanitized))));
		return 1;
	}
	function update_user_meta(int $id, string $key, $value): void {
		State::$meta[$key] = array(is_string($value) ? stripslashes($value) : $value);
	}
	function get_user_meta(int $id, string $key, bool $single) { return State::$meta[$key] ?? array(); }
	function wp_cache_delete(int $id, string $group): void {
		if (1 !== $id || 'user_meta' !== $group) { throw new \RuntimeException('Wrong cache scope.'); }
	}
	final class WP_User {
		public string $display_name;
		public array $roles = array('subscriber');
		public array $allcaps = array();
		public function __construct(int $id) { $this->display_name = State::$user['display_name']; }
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use Wstm_Setup_Model\State;

	require_once dirname(__DIR__) . '/e2e/product-evidence-verifier.php';
	require_once __DIR__ . '/fixtures/product-evidence-model.php';

	final class ProductSetupBoundaryTest extends TestCase {
		private string $directory;
		private Wstm108_Evidence $evidence;
		private Wstm108_PrivateWire $wire;

		protected function setUp(): void {
			$this->directory = str_replace('\\', '/', sys_get_temp_dir()) . '/wstm-setup-' . bin2hex(random_bytes(8));
			self::assertTrue(mkdir($this->directory, 0700));
			self::assertTrue(mkdir($this->directory . '/wire', 0700));
			$context = Wstm_Product_Evidence_Model::context();
			$this->wire = Wstm108_PrivateWire::create($this->directory . '/wire',
				array('owner' => $context['run'], 'project' => $context['project'], 'identity_kind' => 'package', 'identity_sha256' => $context['identity_sha256']),
				str_repeat('d', 64), static function (): void {});
			$this->evidence = new Wstm108_Evidence($this->directory . '/runner.json');
			$this->evidence->attach($this->wire, 'runner');
			if (!class_exists('Wstm_Setup_Model\\Wstm108_Transport', false)) {
				$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-fixture.php');
				$start = strpos($source, 'final class Wstm108_Transport {');
				$end = strpos($source, "\n/**\n * Frozen", $start);
				self::assertIsInt($start); self::assertIsInt($end);
				eval('namespace Wstm_Setup_Model; use \Wstm108_Evidence; use \Throwable; use \stdClass; use \Traversable; '
					. substr($source, $start, $end - $start));
			}
			if (!class_exists('Wstm_Setup_Model\\Wstm_Product_Http_Fixture', false)) {
				$source = file_get_contents(dirname(__DIR__) . '/e2e/product-http-fixture.php');
				$start = strpos($source, "\tpublic static function register_individual");
				$end = strpos($source, "\n\tpublic static function context", $start);
				self::assertIsInt($start); self::assertIsInt($end);
				eval('namespace Wstm_Setup_Model; use \RuntimeException; use \Wstm108_Plan; final class Wstm_Product_Http_Fixture { '
					. 'public static function context(): array { if (State::$context_refused) { throw new RuntimeException("Context refused."); } return State::$context; } '
					. substr($source, $start, $end - $start) . ' }');
			}
			State::$action = true; State::$assertions = 0;
			State::$response_queue = State::$requests = State::$descriptions = array();
			State::$context_refused = false;
			State::$context = array('data' => array('plugin_root' => str_replace('\\', '/', dirname(__DIR__, 2))), 'file' => array('sha256' => str_repeat('a', 64)));
			State::$names = Wstm108_Plan::abilities(Wstm108_Plan::native_from_manifest(dirname(__DIR__) . '/e2e/abilities-manifest.json'));
		}

		protected function tearDown(): void {
			unset($this->evidence, $this->wire);
			foreach (scandir($this->directory . '/wire') as $name) {
				if ('.' !== $name && '..' !== $name) { self::assertTrue(unlink($this->directory . '/wire/' . $name)); }
			}
			self::assertTrue(rmdir($this->directory . '/wire'));
			foreach (array('runner.json', 'runner.json.http.jsonl') as $name) { self::assertTrue(unlink($this->directory . '/' . $name)); }
			self::assertTrue(rmdir($this->directory));
		}

		private function refuses(callable $call): void {
			try { $call(); } catch (Throwable $error) { self::assertNotSame('', $error->getMessage()); return; }
			self::fail('Bounded setup mutation accepted.');
		}

		public function testNonduplicatingProgressRetainsOriginalCaseOnceAndOneFailedFinalSummary(): void {
			$this->evidence->begin_case();
			$id = $this->evidence->append(array('boundary' => 'wordpress-oracle', 'body' => 'original'));
			$this->evidence->validate($id, 'oracle');
			$case = array('label' => 'fixture:original', 'passed' => false, 'error' => 'original failure');
			$this->evidence->case_verdict($case);
			$summary = array('status' => 'failed', 'completed' => false, 'cleanup_complete' => false,
				'passed' => 0, 'failed' => 1, 'cases' => array($case));
			for ($index = 0; $index < 20; ++$index) { $this->evidence->save($summary); }
			$state = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertCount(2, $state['events']);
			$final = $this->evidence->finalize($summary, false);
			$state = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertCount(3, $state['events']);
			self::assertSame('failed', $state['scopes']['runner']['verdict']);
			self::assertSame(filesize($this->directory . '/runner.json.http.jsonl'), $state['journal_bytes']);
			$event = unserialize(substr(file_get_contents($this->directory . '/wire/wire-000003.bin'), strlen("WSTM108-WIRE-1\n")));
			self::assertSame('wstm108-final-summary-v2', $event['schema']);
			self::assertNull($event['summary']['cases']);
			self::assertSame(array(array('label' => $case['label'], 'passed' => false, 'sha256' => hash('sha256', serialize($case)))), $event['case_refs']);
			self::assertSame('failed', $final['status']);
			$this->refuses(fn() => $this->evidence->finalize($summary, false));
		}

		public function testOrdinaryNativeServerRegistrationUsesExactManifestPlusCptAndNoOverride(): void {
			$adapter = new class {
				public array $arguments;
				public function create_server(...$arguments) { $this->arguments = $arguments; return $this; }
			};
			\Wstm_Setup_Model\Wstm_Product_Http_Fixture::register_individual($adapter);
			self::assertCount(10, $adapter->arguments);
			self::assertSame(array('wstm118-individual', 'wstm118', 'tools'), array_slice($adapter->arguments, 0, 3));
			self::assertSame(array(\WP\MCP\Transport\HttpTransport::class), $adapter->arguments[6]);
			self::assertNull($adapter->arguments[7]); self::assertNull($adapter->arguments[8]);
			self::assertSame(State::$names, $adapter->arguments[9]); self::assertCount(90, $adapter->arguments[9]);
			self::assertSame(1, State::$assertions);
			$source = file_get_contents(dirname(__DIR__) . '/e2e/product-http-fixture.php');
			self::assertStringContainsString("add_action('mcp_adapter_init', [Wstm_Product_Http_Fixture::class, 'register_individual'])", $source);
			foreach (array('error-contract-fixture', 'do_action(', 'transport_permission_callback', 'wstm118-foreign') as $forbidden) {
				self::assertStringNotContainsString($forbidden, $source);
			}
		}

		public static function inventoryFaults(): array {
			return array(array('timing'), array('missing'), array('extra'), array('duplicate'), array('native-refusal'), array('context'));
		}

		/** @dataProvider inventoryFaults */
		public function testServerRegistrationRefusesAllInventoryTimingAndNativeErrors(string $fault): void {
			if ('timing' === $fault) { State::$action = false; }
			if ('context' === $fault) { State::$context_refused = true; }
			if ('missing' === $fault) { array_pop(State::$names); }
			if ('extra' === $fault) { State::$names[] = 'webmastery-site-toolkit-for-mcp/wstm118-probe'; }
			if ('duplicate' === $fault) { State::$names[] = State::$names[0]; }
			$adapter = new class($fault) {
				private string $fault;
				public int $calls = 0;
				public function __construct(string $fault) { $this->fault = $fault; }
				public function create_server(...$args) { ++$this->calls; return new WP_Error('fixture', 'refused'); }
			};
			$this->refuses(static fn() => \Wstm_Setup_Model\Wstm_Product_Http_Fixture::register_individual($adapter));
			self::assertSame('native-refusal' === $fault ? 1 : 0, $adapter->calls);
		}

		private function events(string $boundary = 'individual:administrator'): array {
			return Wstm_Product_Evidence_Model::setup_events($boundary, array_values(Wstm_Product_Evidence_Model::untrusted_model()['descriptors']));
		}

		public static function rpcFaults(): array {
			return array_map(static fn($fault) => array($fault), array('http', 'fixture', 'json', 'error', 'version', 'id-string',
				'id-bool', 'id-missing', 'id-foreign', 'protocol', 'result', 'capabilities', 'server-info', 'header-duplicate', 'header-type'));
		}

		private static function mutate(array &$event, string $fault): void {
			if ('http' === $fault) { $event['status'] = 404; return; }
			if ('fixture' === $fault) { unset($event['headers']['x-wstm108-fixture']); return; }
			if ('json' === $fault) { $event['body'] = '{'; return; }
			if ('header-duplicate' === $fault) { $event['headers']['X-WSTM108-Fixture'] = '1'; return; }
			if ('header-type' === $fault) { $event['headers']['x-wstm108-fixture'] = array('1'); return; }
			$body = json_decode($event['body']);
			switch ($fault) {
				case 'error': $body->error = (object) array('code' => -1); break;
				case 'version': $body->jsonrpc = '1.0'; break;
				case 'id-string': $body->id = '1'; break;
				case 'id-bool': $body->id = true; break;
				case 'id-missing': unset($body->id); break;
				case 'id-foreign': $body->id = 99; break;
				case 'protocol': $body->result->protocolVersion = 'foreign'; break;
				case 'result': $body->result = array(); break;
				case 'capabilities': $body->result->capabilities = array(); break;
				case 'server-info': $body->result->serverInfo->version = false; break;
			}
			$event['body'] = json_encode($body);
		}

		/** @dataProvider rpcFaults */
		public function testOriginalResponseRetainedBeforeEveryRefusalAndIntentNeverResolves(string $fault): void {
			$event = $this->events()[0]; self::mutate($event, $fault); State::$response = $event;
			$resolved = 0;
			$client = new \Wstm_Setup_Model\Wstm108_Transport(true, 'fixture-login', 'fixture-password',
				$this->evidence, 'administrator', $event['request']['params']->clientInfo['name'],
				static function () use (&$resolved): void { ++$resolved; });
			$this->refuses(static fn() => $client->rpc('initialize', (array) $event['request']['params']));
			self::assertSame(0, $resolved);
			$state = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertCount(1, $state['events']); self::assertSame('committed', $state['events'][0]['state']);
			$frame = Wstm_Product_Evidence_Verifier::frame($state['events'][0], file_get_contents($this->directory . '/wire/wire-000001.bin'));
			self::assertSame($event['body'], $frame['body']); self::assertSame($event['headers'], $frame['headers']);
			$this->refuses(static function () use ($event): void {
				$states = array(); Wstm_Product_Evidence_Verifier::setup_event($event, $states, 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16));
			});
		}

		public function testSuccessfulEnvelopePrecedesNativeEnrollmentAndEmptyNotificationIsPreserved(): void {
			$events = $this->events(); $states = array(); $resolved = 0;
			$client = new \Wstm_Setup_Model\Wstm108_Transport(true, 'fixture-login', 'fixture-password',
				$this->evidence, 'administrator', $events[0]['request']['params']->clientInfo['name'],
				static function () use (&$resolved): void { ++$resolved; });
			foreach ($events as $event) {
				State::$response = $event;
				$result = $client->rpc($event['method'], (array) $event['request']['params']);
				self::assertSame(wstm108_wire_canonical($result), wstm108_wire_canonical(Wstm_Product_Evidence_Verifier::setup_event($event, $states, 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16))));
			}

			self::assertSame(1, $resolved); self::assertTrue($states['individual:administrator']['complete']);
			self::assertCount(90, $states['individual:administrator']['tools']);
		}

		public static function scoreCalls(): array {
			$cases = array();
			foreach (array('gateway', 'individual') as $boundary) {
				foreach (array('get-seo-scores', 'get-readability-scores') as $ability) { $cases[] = array($boundary, $ability); }
			}
			return $cases;
		}

		/** @dataProvider scoreCalls */
		public function testActualScoreTransportCapturePreservesTwoOwnedNativeSubjects(string $boundary, string $ability): void {
			$model = Wstm_Product_Evidence_Model::untrusted_model();
			$name = 'webmastery-site-toolkit-for-mcp/' . $ability;
			$descriptor = clone $model['descriptors'][$name];
			$descriptor->description = 'Owned native description for ' . $ability;
			State::$descriptions = array($name => $descriptor->description);
			$entry = array_values(array_filter($model['models'], static fn($entry) => $entry['case']['label'] === $boundary . ':' . $ability))[0];
			$response = $entry['responses'][0]; $records = array(); $key = $entry['case']['score_key'];
			foreach (array(42, 43) as $id) {
				wstm108_native_observe($records, array('actor' => 7, 'post_id' => $id, 'key' => $key, 'allowed' => true));
			}
			State::$response = array('status' => 200, 'headers' => array('X-WSTM108-Fixture' => '1',
				'X-WSTM108-Native-Reads' => base64_encode(json_encode($records, JSON_THROW_ON_ERROR))),
				'body' => json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'result' => (object) array(
					'isError' => false, 'structuredContent' => (object) $response,
					'content' => array((object) array('type' => 'text', 'text' => json_encode($response))))), JSON_THROW_ON_ERROR));
			$client = new \Wstm_Setup_Model\Wstm108_Transport('individual' === $boundary, 'fixture-login', 'fixture-password',
				$this->evidence, 'administrator', 'owned-score-client', static function (): void {});
			$client->tools = 'individual' === $boundary ? array($descriptor) : array((object) array(
				'name' => 'owned_gateway_execute', 'inputSchema' => (object) array('properties' => (object) array(
					'ability_name' => new stdClass(), 'parameters' => new stdClass()))));
			$result = $client->execute($name, array('post_type' => 'post', 'status' => 'draft', 'per_page' => 100));
			self::assertTrue($result['success']); self::assertCount(2, $result['data']->items);
			self::assertSame($records, $client->native_observations());
			$index = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertCount(1, $index['events']);
			$event = Wstm_Product_Evidence_Verifier::frame($index['events'][0], file_get_contents($this->directory . '/wire/' . $index['events'][0]['file']));
			self::assertSame($records, wstm108_native_observations($event, 7));
			self::assertSame(State::$response['headers'], $event['headers']);
		}
		private function actualInitializeCaptures(): array {
			$run = 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16);
			$individual_tools = array_values(Wstm_Product_Evidence_Model::untrusted_model()['descriptors']);
			$gateway_tools = array();
			foreach (array('execute', 'info', 'discover') as $operation) {
				$gateway_tools[] = (object) array('name' => 'owned_gateway_' . $operation, 'inputSchema' => new stdClass());
			}
			$resolved = array();
			foreach (Wstm108_Plan::ACTORS as $actor) {
				foreach (Wstm108_Plan::BOUNDARIES as $boundary) {
					$label = $boundary . ':' . $actor;
					State::$response_queue = Wstm_Product_Evidence_Model::setup_events($label, 'individual' === $boundary ? $individual_tools : $gateway_tools);
					$client = new \Wstm_Setup_Model\Wstm108_Transport('individual' === $boundary, 'fixture-login', 'fixture-password',
						$this->evidence, $actor, $run . ':' . $label,
						static function (string $token, string $recorded_label) use (&$resolved): void {
							self::assertArrayNotHasKey($recorded_label, $resolved);
							$resolved[$recorded_label] = $token;
						});
					$client->initialize();
					self::assertSame(array(), State::$response_queue);
					self::assertCount('individual' === $boundary ? 90 : 3, $client->tools);
				}
			}
			self::assertCount(6, $resolved);
			$records = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true, 512, JSON_THROW_ON_ERROR)['events'];
			self::assertCount(18, $records);
			$events = array();
			foreach ($records as $index => $record) {
				self::assertSame('validated', $record['state']);
				$event = Wstm_Product_Evidence_Verifier::frame($record, file_get_contents($this->directory . '/wire/' . $record['file']));
				self::assertSame(json_encode($event['request'], JSON_THROW_ON_ERROR), State::$requests[$index]['options']['body']);
				$events[] = $event;
			}
			return $events;
		}

		public function testUnchangedActualInitializeCapturesAllSixClientsInExactArrayShapeAndHostReplayPasses(): void {
			$events = $this->actualInitializeCaptures();
			$states = array(); $initializations = 0;
			$run = 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16);
			foreach ($events as $event) {
				if ('initialize' === $event['method']) {
					$client = $event['request']['params']->clientInfo;
					self::assertIsArray($client);
					self::assertSame(array('name', 'version'), array_keys($client));
					self::assertSame(array('name' => $run . ':' . $event['boundary'], 'version' => '1'), $client);
					self::assertSame(json_encode((object) $client), json_encode($client));
					$model = Wstm_Product_Evidence_Model::setup_events($event['boundary'], array())[0];
					self::assertSame($client, $model['request']['params']->clientInfo);
					++$initializations;
				}
				Wstm_Product_Evidence_Verifier::setup_event($event, $states, $run);
			}
			self::assertSame(6, $initializations);
			self::assertCount(6, $states);
			foreach ($states as $boundary => $state) {
				self::assertTrue($state['complete']);
				self::assertCount(0 === strpos($boundary, 'individual:') ? 90 : 3, $state['tools']);
			}
		}

		public static function capturedClientFaults(): array {
			return array_map(static fn($fault) => array($fault), array('object', 'list', 'extra-key', 'missing-name',
				'missing-version', 'wrong-name', 'wrong-version', 'name-type', 'version-type', 'boundary'));
		}

		/** @dataProvider capturedClientFaults */
		public function testAllSixActualCapturedClientArraysRefuseWrongKeysTypesValuesAndBoundary(string $fault): void {
			$run = 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16);
			$rejected = 0;
			foreach ($this->actualInitializeCaptures() as $event) {
				if ('initialize' !== $event['method']) { continue; }
				$client = $event['request']['params']->clientInfo;
				switch ($fault) {
					case 'object': $client = (object) $client; break;
					case 'list': $client = array_values($client); break;
					case 'extra-key': $client['extra'] = 'unplanned'; break;
					case 'missing-name': unset($client['name']); break;
					case 'missing-version': unset($client['version']); break;
					case 'wrong-name': $client['name'] .= ' changed'; break;
					case 'wrong-version': $client['version'] = '2'; break;
					case 'name-type': $client['name'] = array($client['name']); break;
					case 'version-type': $client['version'] = 1; break;
					case 'boundary': $event['boundary'] = 'individual:foreign'; break;
				}
				$event['request']['params']->clientInfo = $client;
				$this->refuses(static function () use ($event, $run): void {
					$states = array();
					Wstm_Product_Evidence_Verifier::setup_event($event, $states, $run);
				});
				++$rejected;
			}
			self::assertSame(6, $rejected);
		}

		public static function seedFaults(): array {
			return array(array('none'), array('display-unslashed'), array('login-unslashed'));
		}

		/** @dataProvider seedFaults */
		public function testExactRunnerNativeSeedBoundaryKeepsAllOtherUserInputsUntouched(string $fault): void {
			$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-runner.php');
			$start = strpos($source, '$id = wp_insert_user('); $end = strpos($source, "\n\t\twstm108_assert( ! is_wp_error", $start);
			$creation = substr($source, $start, $end - $start);
			$start = strpos($source, '$actor = new WP_User( $id );'); $end = strpos($source, "\n\t\t\$credential_name =", $start);
			$readback = substr($source, $start, $end - $start);
			if ('display-unslashed' === $fault) { $creation = str_replace("'display_name' => wp_slash( \$payload )", "'display_name' => \$payload", $creation, $count); self::assertSame(1, $count); }
			if ('login-unslashed' === $fault) { $readback = str_replace("'last_login', wp_slash( \$payload )", "'last_login', \$payload", $readback, $count); self::assertSame(1, $count); }
			$call = eval('namespace Wstm_Setup_Model; return static function ($payload, $role, $login, $run, $context): void { '
				. $creation . ' $summary = array(); ' . $readback . ' };');
			$payload = 'INERT "quoted" \'single\' C:\\proof\\data ' . "\u{96EA} \u{1F600}";
			foreach (array('administrator', 'subscriber', 'reader') as $role) {
				State::$meta = array();
				$args = array($payload, $role, 'fixture-' . $role, 'fixture', array('binding' => array('owner' => 'original-owner')));
				if ('none' === $fault) { $call(...$args); }
				else { $this->refuses(static fn() => $call(...$args)); }
				self::assertSame('untouched-password-\\quotes"', State::$user_input['user_pass']);
				self::assertSame('fixture-' . $role, State::$user_input['user_login']);
				self::assertSame(array('wstm108_owner' => 'original-owner'), State::$user_input['meta_input']);
				if ('none' === $fault) {
					self::assertSame($payload, State::$user['display_name']); self::assertSame(array($payload), State::$meta['last_login']);
				}
			}
		}

		public static function failureStages(): array {
			return array(array('bootstrap'), array('setup'), array('transport'), array('case'), array('cleanup'), array('seal'));
		}

		/** @dataProvider failureStages */
		public function testFailedFinalizationBindsFullJournalAndCannotPassOrRetirePendingEvents(string $stage): void {
			$this->evidence->append(array('boundary' => 'individual:administrator', 'status' => 404, 'body' => 'retained'));
			$summary = $this->evidence->finalize(array('status' => 'failed', 'completed' => false, 'cleanup_complete' => false, 'failed' => 1, 'fatal' => $stage), false);
			self::assertSame('failed', $summary['wire_proof']['verdict']);
			self::assertSame(hash_file('sha256', $this->directory . '/runner.json.http.jsonl'), $summary['http_journal_sha256']);
			$public = json_decode(file_get_contents($this->directory . '/runner.json'), true);
			self::assertSame($summary['http_journal_sha256'], $public['http_journal_sha256']);
			self::assertFalse($public['cleanup_complete']);
			$wire = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertSame('committed', $wire['events'][0]['state']); self::assertSame('pending', $wire['retirement']);
			self::assertCount(2, $wire['events']);
			$this->refuses(fn() => $this->evidence->finalize($summary, false));
		}

		public function testSuccessfulFinalizationKeepsOriginalSemanticSealAndFullJournalAssociation(): void {
			$event = $this->evidence->append(array('boundary' => 'wordpress-oracle', 'body' => 'observed'));
			$this->evidence->validate($event, 'oracle');
			$original = array('status' => 'passed', 'completed' => true, 'cleanup_complete' => true, 'failed' => 0);
			$summary = $this->evidence->finalize($original, true);
			self::assertSame('passed', $summary['wire_proof']['verdict']);
			$wire = json_decode(file_get_contents($this->directory . '/wire/private-wire.json'), true);
			self::assertSame(hash('sha256', json_encode(Wstm108_Evidence::public_summary($original), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
				$wire['scopes']['runner']['semantic_sha256']);
			self::assertSame(hash_file('sha256', $this->directory . '/runner.json.http.jsonl'), $summary['http_journal_sha256']);
		}

		public function testUnsealedEvidenceWriteFailureIsStickyAndCannotSealOrRetry(): void {
			$property = new ReflectionProperty($this->evidence, 'files'); $property->setAccessible(true);
			$files = $property->getValue($this->evidence); $files['summary']['sha256'] = str_repeat('0', 64);
			$property->setValue($this->evidence, $files);
			$this->refuses(fn() => $this->evidence->finalize(array('status' => 'failed', 'failed' => 1), false));
			self::assertSame('pending', $this->wire->scope_proof('runner')['verdict']);
			self::assertSame('', file_get_contents($this->directory . '/runner.json'));
			$this->refuses(fn() => $this->evidence->save(array('status' => 'failed')));
			$this->refuses(fn() => $this->evidence->seal(false, array('status' => 'failed')));
		}

		public static function setupFaults(): array {
			return array_map(static fn($fault) => array($fault), array('notification-id', 'notification-body', 'notification-status',
				'notification-session', 'duplicate-initialize', 'catalog-id', 'catalog-version', 'catalog-request',
				'catalog-count', 'catalog-duplicate', 'client-name', 'client-role', 'missing-header', 'reordered',
				'cursor', 'missing-notification'));
		}

		/** @dataProvider setupFaults */
		public function testIndependentSetupReplayRefusesAssociationInventoryAndOrderingMutations(string $fault): void {
			$events = $this->events(); $run = 'wstm108-' . substr(Wstm_Product_Evidence_Model::context()['run'], 0, 16);
			switch ($fault) {
				case 'notification-id': $events[1]['request']['id'] = 1; break;
				case 'notification-body': $events[1]['body'] = '{}'; break;
				case 'notification-status': $events[1]['status'] = 200; break;
				case 'notification-session': $events[1]['request_headers']['Mcp-Session-Id'] = 'foreign'; break;
				case 'duplicate-initialize': array_splice($events, 1, 0, array($events[0])); break;
				case 'catalog-id': $body = json_decode($events[2]['body']); $body->id = '2'; $events[2]['body'] = json_encode($body); break;
				case 'catalog-version': $body = json_decode($events[2]['body']); $body->jsonrpc = '1.0'; $events[2]['body'] = json_encode($body); break;
				case 'catalog-request': $events[2]['request']['id'] = 3; $body = json_decode($events[2]['body']); $body->id = 3; $events[2]['body'] = json_encode($body); break;
				case 'catalog-count': $body = json_decode($events[2]['body']); array_pop($body->result->tools); $events[2]['body'] = json_encode($body); break;
				case 'catalog-duplicate': $body = json_decode($events[2]['body']); $body->result->tools[1] = $body->result->tools[0]; $events[2]['body'] = json_encode($body); break;
				case 'client-name': $events[0]['request']['params']->clientInfo['name'] .= ' changed'; break;
				case 'client-role': $events[0]['boundary'] = 'individual:reader'; break;
				case 'missing-header': unset($events[0]['headers']['mcp-session-id']); break;
				case 'reordered': $events = array($events[2], $events[0], $events[1]); break;
				case 'cursor': $events[2]['request']['params']->cursor = 'foreign'; break;
				case 'missing-notification': unset($events[1]); break;
			}
			$this->refuses(static function () use ($events, $run): void {
				$states = array();
				foreach ($events as $event) {
					$bytes = "WSTM108-WIRE-1\n" . serialize($event);
					$record = array('length' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
						'body_length' => strlen($event['body']), 'body_sha256' => hash('sha256', $event['body']), 'status' => $event['status']);
					Wstm_Product_Evidence_Verifier::setup_event(Wstm_Product_Evidence_Verifier::frame($record, $bytes), $states, $run);
				}
			});
		}
	}
}
