<?php

declare(strict_types=1);

namespace Wstm_Evidence_Rows {
	function add_filter(string $hook, callable $callback, int $priority, int $count): void {
		if ('user_has_cap' !== $hook || PHP_INT_MAX !== $priority || 4 !== $count) { throw new \RuntimeException('Native observer wiring changed.'); }
		$GLOBALS['wstm_evidence_observer'] = $callback;
	}
	function get_post(int $id) { return (object) array('post_name' => in_array($id, array(42, 43), true) ? 'wstm108-owned-post' : 'foreign-post'); }
	function wstm108_provider_keys(string $provider): array { return \wstm108_provider_keys($provider); }
	function wstm108_native_capability_result(array $caps, array $required): bool { return \wstm108_native_capability_result($caps, $required); }
	function wstm108_native_observe(array &$records, array $record): void { \wstm108_native_observe($records, $record); }
	function get_post_meta(int $id, string $key, bool $single) {
		if (42 !== $id || 'owned' !== $key || $single) { throw new \RuntimeException('Native multiple-row contract changed.'); }
		return $GLOBALS['wstm_evidence_rows'];
	}
}

namespace {
	use PHPUnit\Framework\TestCase;
	require_once dirname(__DIR__) . '/e2e/untrusted-content-wire.php';

	final class EvidenceBoundaryTest extends TestCase {
		public function testActualRequestObserverRecordsNativeDecisionWithoutChangingAllcaps(): void {
			require_once dirname(__DIR__) . '/e2e/untrusted-content-plan.php';
			$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-fixture.php');
			$start = strpos($source, "\tadd_filter( 'user_has_cap',");
			$end = strpos($source, "}, PHP_INT_MAX, 4 );", $start) + strlen("}, PHP_INT_MAX, 4 );");
			self::assertIsInt($start);
			$previous = $_SERVER['HTTP_X_WSTM108_PROOF'] ?? null;
			$_SERVER['HTTP_X_WSTM108_PROOF'] = '1';
			$GLOBALS['wstm108_native_reads'] = array();
			try {
				eval('namespace Wstm_Evidence_Rows; use \RuntimeException; ' . substr($source, $start, $end - $start));
				$observe = $GLOBALS['wstm_evidence_observer'];
				$caps = array('edit_posts' => true);
				$args = array('edit_post_meta', 7, 42, '_yoast_wpseo_linkdex');
				self::assertSame($caps, $observe($caps, array('edit_posts'), $args, (object) array('ID' => 7)));
				$tuple = wstm108_native_observation_key(7, 42, '_yoast_wpseo_linkdex');
				self::assertTrue($GLOBALS['wstm108_native_reads'][$tuple]['allowed']);
				$GLOBALS['wstm108_native_reads'] = array();
				self::assertSame($caps, $observe($caps, array('do_not_allow'), $args, (object) array('ID' => 7)));
				self::assertFalse($GLOBALS['wstm108_native_reads'][$tuple]['allowed']);
				self::assertSame(7, $GLOBALS['wstm108_native_reads'][$tuple]['actor']);
				$this->expectException(\RuntimeException::class);
				$observe($caps, array('edit_posts'), $args, (object) array('ID' => 7));
			} finally {
				if (null === $previous) { unset($_SERVER['HTTP_X_WSTM108_PROOF']); } else { $_SERVER['HTTP_X_WSTM108_PROOF'] = $previous; }
				unset($GLOBALS['wstm108_native_reads'], $GLOBALS['wstm_evidence_observer']);
			}
		}

		public static function rows(): array {
			return array(array(array()), array(array('')), array(array('', 'literal', array('nested' => array(1, true, null)))));
		}

		/** @dataProvider rows */
		public function testActualNativeExpectedRowsKeepAbsenceEmptyAndMultiplicityDistinct(array $rows): void {
			if (!function_exists('Wstm_Evidence_Rows\\wstm108_expected_post_meta')) {
				$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-fixture.php');
				$start = strpos($source, 'function wstm108_expected_post_meta(');
				$end = strpos($source, "\nfunction wstm108_expected_post(", $start);
				self::assertIsInt($start); self::assertIsInt($end);
				eval('namespace Wstm_Evidence_Rows; ' . substr($source, $start, $end - $start));
			}
			$GLOBALS['wstm_evidence_rows'] = $rows;
			try {
				$expected = \Wstm_Evidence_Rows\wstm108_expected_post_meta(42, 'owned');
				self::assertSame(array('post_id' => 42, 'meta' => array('owned' => $rows)), $expected);
				self::assertSame(count($rows), count($expected['meta']['owned']));
			} finally { unset($GLOBALS['wstm_evidence_rows']); }
		}

		public function testNativePrimitiveDecisionAddsNoCapabilitiesAndHonorsDenial(): void {
			$allcaps = array('edit_posts' => true, 'do_not_allow' => true);
			self::assertTrue(wstm108_native_capability_result($allcaps, array('edit_posts', 'exist')));
			self::assertFalse(wstm108_native_capability_result($allcaps, array('edit_posts', 'do_not_allow')));
			self::assertFalse(wstm108_native_capability_result($allcaps, array('edit_others_posts')));
			self::assertSame(array('edit_posts' => true, 'do_not_allow' => true), $allcaps);
		}

		private function event(): array {
			$key = '_yoast_wpseo_linkdex';
			return array('boundary' => 'gateway:administrator', 'headers' => array('X-WSTM108-Native-Reads' => base64_encode(json_encode(
				array(wstm108_native_observation_key(7, 42, $key) => array('actor' => 7, 'post_id' => 42, 'key' => $key, 'allowed' => true)), JSON_THROW_ON_ERROR))));
		}

		public static function scoreBoundaries(): array {
			$cases = array();
			foreach (array('gateway', 'individual') as $boundary) {
				foreach (array('_yoast_wpseo_linkdex', '_yoast_wpseo_content_score') as $key) { $cases[] = array($boundary, $key); }
			}
			return $cases;
		}

		/** @dataProvider scoreBoundaries */
		public function testActualObserverKeepsTwoOwnedScoreSubjectsAndRepeatedTupleWithoutChangingPermissions(string $boundary, string $key): void {
			$source = file_get_contents(dirname(__DIR__) . '/e2e/untrusted-content-fixture.php');
			$start = strpos($source, "\tadd_filter( 'user_has_cap',");
			$end = strpos($source, "}, PHP_INT_MAX, 4 );", $start) + strlen("}, PHP_INT_MAX, 4 );");
			$previous = $_SERVER['HTTP_X_WSTM108_PROOF'] ?? null;
			$_SERVER['HTTP_X_WSTM108_PROOF'] = '1'; $GLOBALS['wstm108_native_reads'] = array();
			try {
				eval('namespace Wstm_Evidence_Rows; use \RuntimeException; ' . substr($source, $start, $end - $start));
				$observe = $GLOBALS['wstm_evidence_observer']; $caps = array('edit_posts' => true);
				foreach (array(42, 43, 42) as $id) {
					self::assertSame($caps, $observe($caps, array('edit_posts'), array('edit_post_meta', 7, $id, $key), (object) array('ID' => 7)));
				}
				self::assertCount(2, $GLOBALS['wstm108_native_reads']);
				$event = array('boundary' => $boundary . ':administrator', 'headers' => array(
					'X-WSTM108-Native-Reads' => base64_encode(json_encode($GLOBALS['wstm108_native_reads'], JSON_THROW_ON_ERROR))));
				self::assertSame($GLOBALS['wstm108_native_reads'], wstm108_native_observations($event, 7));
				foreach (array(42, 43) as $id) { self::assertTrue($GLOBALS['wstm108_native_reads'][wstm108_native_observation_key(7, $id, $key)]['allowed']); }
				$this->expectException(RuntimeException::class);
				$observe($caps, array('do_not_allow'), array('edit_post_meta', 7, 42, $key), (object) array('ID' => 7));
			} finally {
				if (null === $previous) { unset($_SERVER['HTTP_X_WSTM108_PROOF']); } else { $_SERVER['HTTP_X_WSTM108_PROOF'] = $previous; }
				unset($GLOBALS['wstm108_native_reads'], $GLOBALS['wstm_evidence_observer']);
			}
		}

		public function testCollectorRefusesForeignActorsAndExhaustionBeforeChangingExistingEvidence(): void {
			$records = array(); $record = array('actor' => 7, 'post_id' => 42, 'key' => '_yoast_wpseo_linkdex', 'allowed' => true);
			wstm108_native_observe($records, $record); $original = $records;
			try { $foreign = $record; $foreign['actor'] = 8; wstm108_native_observe($records, $foreign); self::fail('Foreign actor accepted.'); }
			catch (RuntimeException $error) { self::assertSame($original, $records); }
			for ($id = 43; $id < 1000; ++$id) {
				$record['post_id'] = $id; $before = $records;
				try { wstm108_native_observe($records, $record); }
				catch (RuntimeException $error) {
					self::assertStringContainsString('byte limit', $error->getMessage());
					self::assertSame($before, $records);
					self::assertLessThanOrEqual(16384, strlen(base64_encode(json_encode($records, JSON_THROW_ON_ERROR))));
					return;
				}
			}
			self::fail('Existing header limit was not enforced.');
		}

		public function testInspectOnlyNativeReadIsIndependentOfStandaloneWritablePolicy(): void {
			$key = '_yoast_wpseo_linkdex';
			self::assertSame(array($key => true), wstm108_native_readability($this->event(), 42, array($key), 7));
		}

		public static function faults(): array {
			return array_map(static fn($fault) => array($fault), array('actor', 'post', 'key', 'type', 'missing', 'duplicate', 'restricted', 'boundary', 'encoding', 'duplicate-record', 'extra-record'));
		}

		/** @dataProvider faults */
		public function testNativeBoundaryRefusesForeignStaleDuplicateAndRestrictedObservations(string $fault): void {
			$event = $this->event(); $key = '_yoast_wpseo_linkdex';
			$record = array('actor' => 7, 'post_id' => 42, 'key' => $key, 'allowed' => true);
			if ('actor' === $fault) { $record['actor'] = 8; }
			if ('post' === $fault) { $record['post_id'] = 43; }
			if ('key' === $fault) { $record['key'] = 'foreign'; }
			if ('type' === $fault) { $record['allowed'] = 1; }
			$tuple = wstm108_native_observation_key(7, 42, $key);
			$event['headers']['X-WSTM108-Native-Reads'] = base64_encode(json_encode('missing' === $fault ? array() : array($tuple => $record), JSON_THROW_ON_ERROR));
			if ('duplicate-record' === $fault) {
				$value = json_encode($record, JSON_THROW_ON_ERROR);
				$event['headers']['X-WSTM108-Native-Reads'] = base64_encode('{"' . $tuple . '":' . $value . ',"' . $tuple . '":' . $value . '}');
			}
			if ('extra-record' === $fault) { $event['headers']['X-WSTM108-Native-Reads'] = base64_encode(json_encode(array($tuple => $record, 'foreign' => $record), JSON_THROW_ON_ERROR)); }
			if ('duplicate' === $fault) { $event['headers']['x-wstm108-native-reads'] = $event['headers']['X-WSTM108-Native-Reads']; }
			if ('restricted' === $fault) { $event['request_headers'] = array('X-WSTM108-Deny-Post' => '42', 'X-WSTM108-Deny-Key' => $key); }
			if ('boundary' === $fault) { $event['boundary'] = 'gateway:subscriber'; }
			if ('encoding' === $fault) { $event['headers']['X-WSTM108-Native-Reads'] .= '!'; }
			$this->expectException(\RuntimeException::class);
			wstm108_native_readability($event, 42, array($key), 7);
		}
	}
}
