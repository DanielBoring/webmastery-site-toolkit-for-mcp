<?php

declare(strict_types=1);

namespace Wstm_Session_Read_Model {
	final class State {
		public static array $users = array();
		public static array $meta = array();
		public static array $cache = array();
		public static array $deletes = array();
		public static int $reads = 0;
	}

	function get_userdata(int $id) {
		return State::$users[$id] ?? false;
	}

	function get_user_by(string $field, string $value) {
		foreach (State::$users as $user) {
			if (('login' === $field ? $user->user_login : $user->user_email) === $value) {
				return $user;
			}
		}
		return false;
	}

	function get_user_meta(int $id, string $key = '', bool $single = false) {
		if (!array_key_exists($id, State::$cache)) {
			State::$cache[$id] = State::$meta[$id] ?? array();
		}
		$meta = State::$cache[$id];
		return '' === $key ? $meta : ($single ? ($meta[$key][0] ?? '') : ($meta[$key] ?? array()));
	}

	function wp_cache_delete(int $id, string $group): bool {
		if ('user_meta' !== $group) {
			throw new \RuntimeException('Unexpected cache group.');
		}
		State::$deletes[] = array($id, $group);
		$present = array_key_exists($id, State::$cache);
		unset(State::$cache[$id]);
		return $present;
	}

	function user_can(int $id, string $cap): bool {
		return false;
	}

	final class SessionManager {
		public static function get_all_user_sessions(int $user_id): array {
			++State::$reads;
			if (!$user_id) {
				return array();
			}
			$sessions = get_user_meta($user_id, 'mcp_adapter_sessions', true);
			return is_array($sessions) ? $sessions : array();
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	use Wstm_Session_Read_Model\State;
	use Wstm_Session_Read_Model\SessionManager;

	require_once dirname(__DIR__) . '/e2e/untrusted-content-files.php';

	final class UntrustedSessionReadBoundaryTest extends TestCase {
		private string $directory;
		private string $journal;
		private object $resources;
		private const RUN = 'wstm108-0123456789abcdef';
		private const TOKEN = '11111111-1111-4111-8111-111111111111';

		protected function setUp(): void {
			$this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-session-read-' . bin2hex(random_bytes(8));
			self::assertTrue(mkdir($this->directory, 0700));
			$this->directory = realpath($this->directory);
			$this->journal = $this->directory . DIRECTORY_SEPARATOR . 'resources.json';
			State::$users = State::$meta = State::$cache = State::$deletes = array();
			State::$reads = 0;
			if (!class_exists('Wstm_Session_Read_Model\\Wstm108_Resources', false)) {
				$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-resources.php');
				$header = "<?php\n\ndeclare(strict_types=1);";
				$include = "require_once __DIR__ . '/untrusted-content-files.php';";
				self::assertSame(0, strpos($source, $header));
				self::assertSame(1, substr_count($source, $include));
				$source = str_replace("'\\\\WP\\\\MCP\\\\Transport\\\\Infrastructure\\\\SessionManager'",
					"'\\\\Wstm_Session_Read_Model\\\\SessionManager'", $source, $count);
				self::assertSame(1, $count);
				eval('namespace Wstm_Session_Read_Model; use \RuntimeException; use \ReflectionClass; use \Wstm108_Files; '
					. str_replace($include, '', substr($source, strlen($header))));
			}
			$binding = array('owner' => str_repeat('a', 32), 'project' => 'unit-test',
				'identity_kind' => 'source', 'identity_sha256' => str_repeat('b', 64));
			$this->resources = new \Wstm_Session_Read_Model\Wstm108_Resources($this->journal, $binding, self::RUN);
			$this->resources->intent('user', array('role' => 'administrator'));
			State::$users[1] = (object) array('ID' => 1, 'user_login' => self::RUN . '-administrator',
				'user_email' => self::RUN . '-administrator@example.test', 'roles' => array('administrator'));
			State::$meta[1] = array('wstm108_owner' => array($binding['owner']));
			$this->resources->user_created('administrator', 1);
			$this->resources->user('administrator', 1);
			State::$cache[99] = array('foreign' => array('unchanged'));
			State::$deletes = array();
		}

		protected function tearDown(): void {
			if (file_exists($this->journal)) {
				self::assertTrue(unlink($this->journal));
			}
			self::assertTrue(rmdir($this->directory));
		}

		private function invoke(string $method, array $arguments = []) {
			$reflection = new ReflectionMethod($this->resources, $method);
			$reflection->setAccessible(true);
			return $reflection->invokeArgs($this->resources, $arguments);
		}

		private function record(string $boundary = 'gateway'): array {
			return array('created_at' => 100000, 'last_activity' => 100000,
				'client_params' => array('clientInfo' => array('name' => self::RUN . ':' . $boundary . ':administrator')));
		}

		private function intent(string $boundary = 'gateway'): void {
			$this->resources->intent('session', array('id' => 1, 'boundary' => $boundary));
			self::assertSame(array(), SessionManager::get_all_user_sessions(1));
		}

		private function state(): array {
			return json_decode(Wstm108_Files::file($this->journal)['bytes'], true, 128, JSON_THROW_ON_ERROR);
		}

		/** @dataProvider boundaries */
		public function testExternalHttpWriteAfterNegativeCachePrimeEnrollsExactBackingSession(string $boundary): void {
			$this->intent($boundary);
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $this->record($boundary)));
			self::assertSame(array(), SessionManager::get_all_user_sessions(1));
			$backing = serialize(State::$meta);
			$this->resources->session(1, self::TOKEN, $boundary);
			$state = $this->state();
			self::assertSame(array('id' => 1, 'token' => self::TOKEN, 'boundary' => $boundary),
				$state['sessions'][$boundary . ':administrator']);
			self::assertTrue($state['intents'][1]['resolved']);
			self::assertSame($backing, serialize(State::$meta));
			self::assertSame(array('foreign' => array('unchanged')), State::$cache[99]);
			self::assertSame(array(array(1, 'user_meta'), array(1, 'user_meta')), State::$deletes);
		}

		public static function boundaries(): array {
			return array(array('gateway'), array('individual'));
		}

		public function testUnresolvedRecoveryUsesTheSameFreshReadAndPreservesExactIntent(): void {
			$this->intent();
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $this->record()));
			self::assertSame(array(), SessionManager::get_all_user_sessions(1));
			$intent = $this->state()['intents'][1];
			$this->invoke('recover_intent', array($intent));
			$state = $this->state();
			self::assertSame($intent['details'], $state['intents'][1]['details']);
			self::assertTrue($state['intents'][1]['resolved']);
			self::assertSame(self::TOKEN, $state['sessions']['gateway:administrator']['token']);
		}

		public function testExternalRemovalAndDeletedActorAbsenceReadInvalidatePositiveCache(): void {
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $this->record()));
			unset(State::$cache[1]);
			self::assertArrayHasKey(self::TOKEN, SessionManager::get_all_user_sessions(1));
			unset(State::$meta[1]['mcp_adapter_sessions']);
			$journal = Wstm108_Files::file($this->journal);
			self::assertSame(array(), $this->invoke('session_list', array(1)));
			State::$cache[1] = array('mcp_adapter_sessions' => array(array(self::TOKEN => $this->record())));
			unset(State::$users[1], State::$meta[1]);
			self::assertSame(array(), $this->invoke('session_list', array(1)));
			self::assertSame($journal, Wstm108_Files::file($this->journal));
			self::assertSame(array('foreign' => array('unchanged')), State::$cache[99]);
		}

		public function testCacheMissStillReadsExactBackingRecordWithoutRequiringDeleteSuccess(): void {
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $this->record()));
			unset(State::$cache[1]);
			$before = Wstm108_Files::file($this->journal);
			self::assertSame(array(self::TOKEN => $this->record()), $this->invoke('session_list', array(1)));
			self::assertSame(array(array(1, 'user_meta')), State::$deletes);
			self::assertSame($before, Wstm108_Files::file($this->journal));
		}

		public static function refusals(): array {
			return array_map(static fn($fault) => array($fault), array('missing', 'stale-positive', 'wrong-name',
				'near-name', 'wrong-type', 'empty-token', 'unknown-token', 'foreign-actor', 'changed-actor',
				'cached-owner-changed', 'cached-owner-removed', 'invalid-actor'));
		}

		/** @dataProvider refusals */
		public function testEnrollmentRefusesWithoutResolvingOrAdoptingWrongRecords(string $fault): void {
			$this->intent();
			$record = $this->record();
			$id = 1;
			$token = self::TOKEN;
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $record));
			switch ($fault) {
				case 'missing': unset(State::$meta[1]['mcp_adapter_sessions']); break;
				case 'stale-positive':
					State::$cache[1]['mcp_adapter_sessions'] = State::$meta[1]['mcp_adapter_sessions'];
					unset(State::$meta[1]['mcp_adapter_sessions']); break;
				case 'wrong-name': State::$meta[1]['mcp_adapter_sessions'][0][self::TOKEN]['client_params']['clientInfo']['name'] = 'foreign'; break;
				case 'near-name': State::$meta[1]['mcp_adapter_sessions'][0][self::TOKEN]['client_params']['clientInfo']['name'] .= ' '; break;
				case 'wrong-type': State::$meta[1]['mcp_adapter_sessions'][0][self::TOKEN]['client_params']['clientInfo']['name'] = true; break;
				case 'empty-token': $token = ''; break;
				case 'unknown-token': $token = '22222222-2222-4222-8222-222222222222'; break;
				case 'foreign-actor': $id = 99; break;
				case 'changed-actor': State::$users[1]->user_login .= '-foreign'; break;
				case 'cached-owner-changed': State::$meta[1]['wstm108_owner'] = array('foreign'); break;
				case 'cached-owner-removed': unset(State::$meta[1]['wstm108_owner']); break;
				case 'invalid-actor': $id = 0; break;
			}
			$before = Wstm108_Files::file($this->journal);
			$backing = serialize(array(State::$users, State::$meta));
			try {
				$this->resources->session($id, $token, 'gateway');
				self::fail('Enrollment must refuse the altered native association.');
			} catch (RuntimeException $error) {
				self::assertSame('WSTM108 Resource ownership or completeness check failed; retain private evidence.', $error->getMessage());
			}
			self::assertSame($before, Wstm108_Files::file($this->journal));
			self::assertFalse($this->state()['intents'][1]['resolved']);
			self::assertSame(array(), $this->state()['sessions']);
			self::assertSame($backing, serialize(array(State::$users, State::$meta)));
			self::assertSame(array('foreign' => array('unchanged')), State::$cache[99]);
		}

		public function testDuplicateRecoveryNamesAndRepeatedEnrollmentRefuse(): void {
			$this->intent();
			$other = '22222222-2222-4222-8222-222222222222';
			State::$meta[1]['mcp_adapter_sessions'] = array(array(self::TOKEN => $this->record(), $other => $this->record()));
			$before = Wstm108_Files::file($this->journal);
			try {
				$this->invoke('recover_intent', array($this->state()['intents'][1]));
				self::fail('Ambiguous session recovery must refuse.');
			} catch (RuntimeException $error) {
				self::assertStringContainsString('Resource ownership', $error->getMessage());
			}
			self::assertSame($before, Wstm108_Files::file($this->journal));
			unset(State::$meta[1]['mcp_adapter_sessions'][0][$other]);
			$this->resources->session(1, self::TOKEN, 'gateway');
			$before = Wstm108_Files::file($this->journal);
			try {
				$this->resources->session(1, self::TOKEN, 'gateway');
				self::fail('Duplicate enrollment must refuse.');
			} catch (RuntimeException $error) {
				self::assertStringContainsString('Resource ownership', $error->getMessage());
			}
			self::assertSame($before, Wstm108_Files::file($this->journal));
		}

		public function testPrivateReadBoundaryRejectsUnownedIdsBeforeCacheOrGetter(): void {
			foreach (array(0, -1, 99) as $id) {
				$reads = State::$reads;
				$deletes = State::$deletes;
				try {
					$this->invoke('session_list', array($id));
					self::fail('Unowned cache target must refuse.');
				} catch (RuntimeException $error) {
					self::assertStringContainsString('Resource ownership', $error->getMessage());
				}
				self::assertSame($reads, State::$reads);
				self::assertSame($deletes, State::$deletes);
			}
		}
	}
}
