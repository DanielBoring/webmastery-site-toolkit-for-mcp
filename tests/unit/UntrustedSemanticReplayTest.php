<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/product-evidence-verifier.php';
require_once __DIR__ . '/fixtures/product-evidence-model.php';

final class UntrustedSemanticReplayTest extends TestCase {
	private ?string $root = null;

	protected function setUp(): void {
		gc_collect_cycles();
		gc_mem_caches();
	}

	protected function tearDown(): void {
		if ( null === $this->root ) {
			gc_collect_cycles();
			gc_mem_caches();
			return;
		}
		foreach ( scandir( $this->root . '/wire' ) as $name ) {
			if ( '.' === $name || '..' === $name ) { continue; }
			if ( 'private-wire.json' !== $name && 1 !== preg_match( '/^wire-[0-9]{6}\\.bin$/D', $name ) ) { throw new RuntimeException( 'Foreign test artifact retained.' ); }
			unlink( $this->root . '/wire/' . $name );
		}
		rmdir( $this->root . '/wire' );
		foreach ( array( 'runner.json', 'runner.json.http.jsonl', 'resources.json', 'product-verdict.private.json' ) as $name ) {
			if ( file_exists( $this->root . '/' . $name ) ) { unlink( $this->root . '/' . $name ); }
		}
		rmdir( $this->root ); $this->root = null;
		gc_collect_cycles();
		gc_mem_caches();
	}

	private function export( ?callable $mutate = null ): array {
		$this->root = sys_get_temp_dir() . '/wstm-complete-untrusted-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->root, 0700 ) );
		return Wstm_Product_Evidence_Model::untrusted_export( $this->root, $mutate );
	}

	public function testEveryIndependentlyPlannedLabelReplaysWithoutTrustingCasePassFlags(): void {
		$model = Wstm_Product_Evidence_Model::untrusted_model(); $count = 0;
		self::assertCount( 298, $model['models'] );
		self::assertSame( Wstm108_Plan::labels( $model['native'] ), array_column( array_column( $model['models'], 'case' ), 'label' ) );
		foreach ( $model['models'] as $entry ) {
			$case = $entry['case']; $case['passed'] = false;
			if ( 0 === strpos( $case['label'], 'annotations:' ) ) {
				$name = substr( $case['label'], 12 );
				Wstm_Product_Evidence_Verifier::annotation( $model['registered'][ $name ], $case['descriptor'], $case['get_info'] );
			} else {
				self::assertSame( $entry['abilities'], Wstm_Product_Evidence_Verifier::case_abilities( $case['label'] ) );
				self::assertNull( Wstm_Product_Evidence_Verifier::replay_case( $case, $case['response'], $entry['responses'] ), $case['label'] );
			}
			++$count;
		}
		self::assertSame( 298, $count );
		$cases = array_column(array_column($model['models'], 'case'), null, 'label');
		foreach (array('gateway', 'individual') as $boundary) {
			foreach (array('_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_seopress_news_disabled', '_seopress_video_disabled') as $key) {
				self::assertFalse($cases[$boundary . ':seo-key-policy:' . $key]['response']['success']);
				$provider = 0 === strpos($key, '_yoast_') ? 'yoast' : 'seopress';
				self::assertTrue($cases[$boundary . ':' . $provider . ':metadata']['readable'][$key]);
			}
		}
	}

	public function testCompleteOriginalFramed298LabelExportVerifiesThroughSupportedHostApi(): void {
		$export = $this->export();
		self::assertSame( array( 'stage' => 'untrusted', 'status' => 'records-verified-live-absence-unresolved',
			'count' => 298, 'lane' => '8.2', 'uid' => 33 ), Wstm_Product_Evidence_Verifier::verify( 'untrusted', $this->root, $export['context'] ) );
		$public = json_decode( file_get_contents( $this->root . '/runner.json' ), true );
		self::assertCount( 298, $public['cases'] );
		$index = json_decode(file_get_contents($this->root . '/wire/private-wire.json'), true);
		self::assertCount(298, array_filter($index['events'], static fn($record) => 'case-result' === $record['parser']));
		$finals = array_values(array_filter($index['events'], static fn($record) => 'local-summary' === $record['parser']));
		self::assertCount(1, $finals);
		self::assertSame(count($index['events']), $finals[0]['id']);
		$final_event = Wstm_Product_Evidence_Verifier::frame($finals[0], file_get_contents($this->root . '/wire/' . $finals[0]['file']));
		self::assertNull($final_event['summary']['cases']);
		self::assertCount(298, $final_event['case_refs']);
		foreach ( $public['cases'] as $case ) {
			self::assertArrayNotHasKey( 'semantic', $case ); self::assertArrayNotHasKey( 'expected', $case );
			self::assertArrayNotHasKey( 'response', $case ); self::assertArrayNotHasKey( 'readable', $case );
		}

		$path = $this->root . '/product-verdict.private.json';
		$verdict_bytes = file_get_contents( $path ); $verdict = json_decode( $verdict_bytes, true );
		$verdict['passed'] = 297; file_put_contents( $path, json_encode( $verdict ) );
		try {
			Wstm_Product_Evidence_Verifier::verify( 'untrusted', $this->root, $export['context'] );
			self::fail( 'Changed complete count was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'final private summary seal/count association', $error->getMessage() );
		} finally { file_put_contents( $path, $verdict_bytes ); }
		$path = $this->root . '/wire/private-wire.json';
		$wire_bytes = file_get_contents( $path ); $wire = json_decode( $wire_bytes, true );
		$wire['scopes']['runner']['semantic_sha256'] = str_repeat( '0', 64 ); file_put_contents( $path, json_encode( $wire ) );
		try {
			Wstm_Product_Evidence_Verifier::verify( 'untrusted', $this->root, $export['context'] );
			self::fail( 'Changed final semantic seal was accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'final private summary seal/count association', $error->getMessage() );
		} finally { file_put_contents( $path, $wire_bytes ); }
	}

	public static function scoreObservationFaults(): array {
		$cases = array();
		foreach (array('gateway', 'individual') as $boundary) {
			foreach (array('get-seo-scores', 'get-readability-scores') as $ability) {
				foreach (array('actor', 'foreign-post', 'missing', 'key', 'conflicting-duplicate', 'restricted', 'denied') as $fault) { $cases[] = array($boundary, $ability, $fault); }
			}
		}
		return $cases;
	}

	/** @dataProvider scoreObservationFaults */
	public function testReboundScoreObservationsCannotHideForeignMissingOrConflictingTuples(string $boundary, string $ability, string $fault): void {
		$export = $this->export(static function (array &$case, array &$event) use ($boundary, $ability, $fault): void {
			if ($case['label'] !== $boundary . ':' . $ability) { return; }
			$records = json_decode(base64_decode($event['headers']['X-WSTM108-Native-Reads'], true), true);
			$key = $case['score_key']; $tuple = wstm108_native_observation_key(7, 42, $key); $record = $records[$tuple];
			unset($records[$tuple]);
			switch ($fault) {
				case 'actor': $record['actor'] = 8; break;
				case 'foreign-post': $record['post_id'] = 99; break;
				case 'key': $record['key'] = '_yoast_wpseo_focuskw'; break;
				case 'missing': break;
				case 'restricted': $event['request_headers'] = array('X-WSTM108-Deny-Key' => $key, 'X-WSTM108-Deny-Post' => '42'); break;
				case 'denied': $record['allowed'] = false; break;
			}
			if ('missing' !== $fault) { $records[wstm108_native_observation_key($record['actor'], $record['post_id'], $record['key'])] = $record; }
			$bytes = json_encode($records, JSON_THROW_ON_ERROR);
			if ('conflicting-duplicate' === $fault) {
				$record['allowed'] = false;
				$bytes = substr($bytes, 0, -1) . ',' . json_encode($tuple) . ':' . json_encode($record, JSON_THROW_ON_ERROR) . '}';
			}
			$event['headers']['X-WSTM108-Native-Reads'] = base64_encode($bytes);
		});
		$this->expectException(RuntimeException::class);
		$messages = array('actor' => 'Native per-key actor/post/key/type association refused',
			'foreign-post' => 'score native owned actor/post/key association', 'key' => 'score native owned actor/post/key association',
			'missing' => 'required owned score native observation missing or denied', 'denied' => 'required owned score native observation missing or denied',
			'conflicting-duplicate' => 'Native observation map/domain or unique encoding invalid',
			'restricted' => 'Restricted native capability was allowed');
		$this->expectExceptionMessage($messages[$fault]);
		Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context']);
	}

	public function testReboundSuccessfulSetupWitnessCannotHideWrongOriginalInitializeIdentity(): void {
		$this->root = sys_get_temp_dir() . '/wstm-complete-untrusted-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($this->root, 0700));
		$export = Wstm_Product_Evidence_Model::untrusted_export($this->root, null, static function (array &$event): void {
			if ('initialize' === $event['method'] && 'individual:administrator' === $event['boundary']) {
				$body = json_decode($event['body']); $body->id = '1'; $event['body'] = json_encode($body);
			}
		});
		try {
			Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context']);
			self::fail('Successful witness/hash rebound hid altered initialize response identity.');
		} catch (RuntimeException $error) {
			self::assertStringContainsString('Typed JSON-RPC response identity', $error->getMessage());
		}
	}

	public function testRealSharedCatalogAnnotationObjectsHaveAliasIndependentTerminalSeal(): void {
		$export = $this->export();
		$producer = $export['summary'];
		$catalog = $producer['catalogs']['individual:administrator'];
		$annotations = array_values(array_filter($producer['cases'], static fn($case) => 0 === strpos($case['label'], 'annotations:')));
		self::assertCount(90, $catalog); self::assertCount(90, $annotations);
		foreach ($annotations as $index => $case) { self::assertSame($catalog[$index], $case['descriptor']); }
		$wire = json_decode(file_get_contents($this->root . '/wire/private-wire.json'), true);
		$cases = array(); $final = null;
		foreach ($wire['events'] as $record) {
			if (!in_array($record['parser'], array('case-result', 'local-summary'), true)) { continue; }
			$event = Wstm_Product_Evidence_Verifier::frame($record, file_get_contents($this->root . '/wire/' . $record['file']));
			if ('case-result' === $record['parser']) { $cases[] = $event['case']; } else { $final = $event; }
		}
		self::assertCount(298, $cases); self::assertCount(298, $final['case_refs']);
		foreach ($cases as $index => $case) {
			self::assertSame(array('label' => $case['label'], 'passed' => $case['passed'],
				'sha256' => hash('sha256', serialize($case))), $final['case_refs'][$index]);
		}
		$reconstructed = $final['summary']; $reconstructed['cases'] = $cases;
		self::assertNotSame(serialize($producer), serialize($reconstructed), 'Real cross-frame object topology must differ.');
		self::assertSame(array_keys($producer), array_keys($reconstructed));
		self::assertSame(Wstm108_Evidence::summary_commitment($producer), Wstm108_Evidence::summary_commitment($reconstructed));
		$producer_public = Wstm108_Evidence::public_summary($producer);
		$reconstructed_public = Wstm108_Evidence::public_summary($reconstructed);
		self::assertSame($producer_public['private_summary_sha256'], $reconstructed_public['private_summary_sha256']);
		self::assertSame(json_encode($producer_public, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
			json_encode($reconstructed_public, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
		self::assertSame($wire['scopes']['runner']['semantic_sha256'],
			hash('sha256', json_encode(Wstm108_Evidence::public_summary($reconstructed), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
		self::assertSame(298, Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context'])['count']);
	}

	public static function summaryMutations(): array {
		return array_map(static fn($kind) => array($kind), array('content', 'scalar-type', 'object-array',
			'empty-shape', 'property-order', 'key-order', 'list-order', 'list-key'));
	}

	public function testActual1053Byte18LevelSharedDagFrameMakesControlledCommitmentRefusal(): void {
		$dag = new stdClass();
		for ($level = 0; $level < 18; ++$level) { $dag = (object) array('left' => $dag, 'right' => $dag); }
		$event = array('boundary' => 'local-summary', 'summary' => array('dag' => $dag, 'padding' => ''));
		$bytes = "WSTM108-WIRE-1\n" . serialize($event);
		self::assertLessThanOrEqual(1053, strlen($bytes));
		$padding = 0;
		for ($attempt = 0; $attempt < 4 && strlen($bytes) !== 1053; ++$attempt) {
			$padding += 1053 - strlen($bytes);
			$event['summary']['padding'] = str_repeat('x', $padding);
			$bytes = "WSTM108-WIRE-1\n" . serialize($event);
		}
		self::assertSame(1053, strlen($bytes));
		$record = array('length' => 1053, 'sha256' => hash('sha256', $bytes),
			'body_length' => null, 'body_sha256' => null, 'status' => null);
		$accepted = Wstm_Product_Evidence_Verifier::frame($record, $bytes);
		self::assertSame($accepted['summary']['dag']->left, $accepted['summary']['dag']->right);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Summary commitment');
		Wstm108_Evidence::public_summary($accepted['summary']);
	}

	public function testObjectAndArrayCyclesRefuseWithContentFreeFiniteExceptions(): void {
		$object = new stdClass(); $object->self = $object;
		$array = array(); $array['self'] = &$array;
		foreach (array(array('subject' => $object), array('subject' => &$array)) as $summary) {
			try { Wstm108_Evidence::summary_commitment($summary); self::fail('Cyclic summary accepted.'); }
			catch (RuntimeException $error) {
				self::assertStringStartsWith('WSTM108 Summary commitment', $error->getMessage());
				self::assertStringNotContainsString('subject', $error->getMessage());
				self::assertStringNotContainsString('self', $error->getMessage());
			}
		}
		unset($array['self'], $object->self);
	}

	public function testExpandedNodeBoundaryRefusesBeforeVisitingNextValue(): void {
		$method = new ReflectionMethod(Wstm108_Evidence::class, 'summary_value'); $method->setAccessible(true);
		$hash = hash_init('sha256'); $active = new SplObjectStorage();
		$budget = array('nodes' => 131071, 'bytes' => 0);
		$args = array($hash, null, 0, &$budget, $active); $method->invokeArgs(null, $args);
		self::assertSame(array('nodes' => 131072, 'bytes' => 2), $budget);
		try { $method->invokeArgs(null, $args); self::fail('Expanded node boundary exceeded.'); }
		catch (RuntimeException $error) { self::assertStringContainsString('node', $error->getMessage()); }
		self::assertSame(array('nodes' => 131072, 'bytes' => 2), $budget);
		$budget = array('nodes' => 131071, 'bytes' => 0);
		$args = array($hash, array('owned' => 'value'), 0, &$budget, $active);
		try { $method->invokeArgs(null, $args); self::fail('Container capacity preflight accepted unavailable children.'); }
		catch (RuntimeException $error) { self::assertStringContainsString('container node', $error->getMessage()); }
		self::assertSame(0, $budget['bytes']);
	}

	public function testByteBoundaryChargesBeforeChunkAllocationAndHashUpdate(): void {
		$emit = new ReflectionMethod(Wstm108_Evidence::class, 'summary_emit'); $emit->setAccessible(true);
		$hash = hash_init('sha256'); $budget = array('nodes' => 0, 'bytes' => 33554429);
		$args = array($hash, 'abc', &$budget); $emit->invokeArgs(null, $args);
		self::assertSame(33554432, $budget['bytes']);
		$before = hash_final(hash_copy($hash));
		$args = array($hash, 'd', &$budget);
		try { $emit->invokeArgs(null, $args); self::fail('Encoded byte boundary exceeded.'); }
		catch (RuntimeException $error) { self::assertStringContainsString('byte boundary', $error->getMessage()); }
		self::assertSame($before, hash_final(hash_copy($hash)));
		self::assertSame(33554432, $budget['bytes']);
		$method = new ReflectionMethod(Wstm108_Evidence::class, 'summary_value'); $method->setAccessible(true);
		$budget = array('nodes' => 0, 'bytes' => 33554422);
		$value = str_repeat('private-marker-', 8192); $active = new SplObjectStorage();
		$args = array($hash, $value, 0, &$budget, $active);
		try { $method->invokeArgs(null, $args); self::fail('String copied or hashed beyond remaining byte reservation.'); }
		catch (RuntimeException $error) {
			self::assertStringContainsString('byte boundary', $error->getMessage());
			self::assertStringNotContainsString('private-marker', $error->getMessage());
		}
		self::assertSame(array('nodes' => 1, 'bytes' => 33554422), $budget);
		self::assertSame($before, hash_final(hash_copy($hash)));
	}

	/** @dataProvider summaryMutations */
	public function testExactTypedSummaryCommitmentRejectsContentTypeOrderAndListShapeChanges(string $kind): void {
		$summary = array('catalogs' => array((object) array('name' => 'original', 'schema' => new stdClass())),
			'cases' => array(array('label' => 'original', 'typed' => array(1, 1.0, true, null))));
		$changed = unserialize(serialize($summary));
		switch ($kind) {
			case 'content': $changed['catalogs'][0]->name = 'changed'; break;
			case 'scalar-type': $changed['cases'][0]['typed'][0] = 1.0; break;
			case 'object-array': $changed['catalogs'][0] = (array) $changed['catalogs'][0]; break;
			case 'empty-shape': $changed['catalogs'][0]->schema = array(); break;
			case 'property-order': $changed['catalogs'][0] = (object) array_reverse((array) $changed['catalogs'][0], true); break;
			case 'key-order': $changed = array_reverse($changed, true); break;
			case 'list-order': $changed['cases'][0]['typed'] = array_reverse($changed['cases'][0]['typed']); break;
			case 'list-key': $changed['catalogs'] = array(1 => $changed['catalogs'][0]); break;
		}
		self::assertNotSame(Wstm108_Evidence::summary_commitment($summary), Wstm108_Evidence::summary_commitment($changed));
		self::assertNotSame(
			hash('sha256', json_encode(Wstm108_Evidence::public_summary(array('cases' => array(), 'subject' => $summary)), JSON_THROW_ON_ERROR)),
			hash('sha256', json_encode(Wstm108_Evidence::public_summary(array('cases' => array(), 'subject' => $changed)), JSON_THROW_ON_ERROR)));
	}

	public function testReboundTerminalCaseReferenceCannotHideChangedOriginalCaseProof(): void {
		$export = $this->export();
		$path = $this->root . '/wire/private-wire.json';
		$wire = json_decode(file_get_contents($path), true);
		$last = count($wire['events']) - 1; $record = $wire['events'][$last];
		$frame_path = $this->root . '/wire/' . $record['file'];
		$event = Wstm_Product_Evidence_Verifier::frame($record, file_get_contents($frame_path));
		$event['case_refs'][0]['sha256'] = str_repeat('0', 64);
		$bytes = "WSTM108-WIRE-1\n" . serialize($event);
		self::assertSame($record['length'], strlen($bytes));
		$wire['events'][$last]['sha256'] = hash('sha256', $bytes);
		file_put_contents($frame_path, $bytes);
		file_put_contents($path, json_encode($wire, JSON_THROW_ON_ERROR));
		$journal_path = $this->root . '/runner.json.http.jsonl'; $journal = '';
		foreach (explode("\n", file_get_contents($journal_path)) as $line) {
			if ('' === $line) { continue; }
			$witness = json_decode($line, true);
			if ($record['id'] === $witness['id']) { $witness['sha256'] = hash('sha256', $bytes); }
			$journal .= json_encode($witness, JSON_THROW_ON_ERROR) . "\n";
		}
		self::assertSame($wire['journal_bytes'], strlen($journal));
		file_put_contents($journal_path, $journal);
		$public_path = $this->root . '/runner.json'; $public = json_decode(file_get_contents($public_path), true);
		$public['http_journal_sha256'] = hash('sha256', $journal);
		file_put_contents($public_path, json_encode($public, JSON_THROW_ON_ERROR));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('final summary original case references');
		Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context']);
	}

	public function testFullyReboundTerminalContentTypeAndOrderMutationsStillRefuseScopeSeal(): void {
		$export = $this->export();
		$index_path = $this->root . '/wire/private-wire.json';
		$journal_path = $this->root . '/runner.json.http.jsonl';
		$public_path = $this->root . '/runner.json';
		$index_bytes = file_get_contents($index_path); $original_index = json_decode($index_bytes, true);
		$last = count($original_index['events']) - 1; $record = $original_index['events'][$last];
		$frame_path = $this->root . '/wire/' . $record['file'];
		$frame_bytes = file_get_contents($frame_path);
		$journal_bytes = file_get_contents($journal_path); $public_bytes = file_get_contents($public_path);
		foreach (array('content', 'object-array', 'catalog-order', 'summary-key-order') as $kind) {
			try {
				$event = Wstm_Product_Evidence_Verifier::frame($record, $frame_bytes);
				$catalog = &$event['summary']['catalogs']['individual:administrator'];
				if ('content' === $kind) { $catalog[0]->title = 'changed original content'; }
				if ('object-array' === $kind) { $catalog[0] = (array) $catalog[0]; }
				if ('catalog-order' === $kind) { $catalog = array_reverse($catalog); }
				if ('summary-key-order' === $kind) { $event['summary'] = array_reverse($event['summary'], true); }
				unset($catalog);
				$bytes = "WSTM108-WIRE-1\n" . serialize($event);
				$index = $original_index;
				$index['events'][$last]['length'] = strlen($bytes);
				$index['events'][$last]['sha256'] = hash('sha256', $bytes);
				file_put_contents($frame_path, $bytes);
				$journal = '';
				foreach (explode("\n", $journal_bytes) as $line) {
					if ('' === $line) { continue; }
					$witness = json_decode($line, true);
					if ($record['id'] === $witness['id']) {
						$witness['length'] = strlen($bytes); $witness['sha256'] = hash('sha256', $bytes);
					}
					$journal .= json_encode($witness, JSON_THROW_ON_ERROR) . "\n";
				}
				$index['journal_bytes'] = strlen($journal);
				$index['index_bytes'] = max($index['index_bytes'], strlen(json_encode($index, JSON_THROW_ON_ERROR)));
				file_put_contents($index_path, json_encode($index, JSON_THROW_ON_ERROR));
				file_put_contents($journal_path, $journal);
				$public = json_decode($public_bytes, true); $public['http_journal_sha256'] = hash('sha256', $journal);
				file_put_contents($public_path, json_encode($public, JSON_THROW_ON_ERROR));
				try {
					Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context']);
					self::fail('Rebound terminal ' . $kind . ' accepted with original scope seal.');
				} catch (RuntimeException $error) {
					self::assertStringContainsString('final private summary seal/count association', $error->getMessage(), $kind);
				}
			} finally {
				file_put_contents($index_path, $index_bytes); file_put_contents($frame_path, $frame_bytes);
				file_put_contents($journal_path, $journal_bytes); file_put_contents($public_path, $public_bytes);
				gc_collect_cycles(); gc_mem_caches();
			}
		}
	}

	public function testRealProtectedCommentCastsRemainInAllOriginalDenialSnapshotsAndCompleteReplay(): void {
		$model = Wstm_Product_Evidence_Model::untrusted_model();
		$denials = array_values(array_filter($model['models'], static fn($entry) => false !== strpos($entry['case']['label'], ':subscriber-denied:')));
		self::assertCount(28, $denials);
		foreach ($denials as $entry) {
			$semantic = $entry['case']['semantic'];
			self::assertSame($semantic['before'], $semantic['after']);
			$comment = $semantic['before'][1];
			self::assertSame(array('comment_ID', 'comment_content', "\0*\0children", "\0*\0populated_children", "\0*\0post_fields"), array_keys($comment));
			self::assertNull($comment["\0*\0children"]); self::assertFalse($comment["\0*\0populated_children"]);
			self::assertSame(array('ID' => 42, 'post_type' => 'post'), $comment["\0*\0post_fields"]);
		}
		try { wstm108_wire_canonical($denials[0]['case']); self::fail('Native protected keys unexpectedly survived the old JSON object round trip.'); }
		catch (JsonException $error) { self::assertSame(JSON_ERROR_INVALID_PROPERTY_NAME, $error->getCode()); }
		$cases = array_column($model['models'], 'case');
		$restored = unserialize(serialize($cases), array('allowed_classes' => array('stdClass')));
		self::assertSame(Wstm108_Evidence::summary_commitment($cases), Wstm108_Evidence::summary_commitment($restored));
		$export = $this->export();
		self::assertSame(298, Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context'])['count']);
	}

	public static function protectedSnapshotMutations(): array {
		return array_map(static fn($kind) => array($kind), array('value', 'scalar-type', 'null-type', 'nul-key', 'key-order', 'nested-type'));
	}

	/** @dataProvider protectedSnapshotMutations */
	public function testProtectedSnapshotMutationsRefuseAfterCompleteHashRebound(string $kind): void {
		$export = $this->export(static function (array &$case) use ($kind): void {
			if ('gateway:subscriber-denied:get-post' !== $case['label']) { return; }
			$before = Wstm108_Evidence::summary_commitment($case['semantic']['after']);
			$comment = &$case['semantic']['after'][1];
			switch ($kind) {
				case 'value': $comment['comment_content'] .= 'changed'; break;
				case 'scalar-type': $comment["\0*\0populated_children"] = 0; break;
				case 'null-type': $comment["\0*\0children"] = ''; break;
				case 'nul-key':
					$comment["\0*\0foreign_children"] = $comment["\0*\0children"]; unset($comment["\0*\0children"]); break;
				case 'key-order': $comment = array_reverse($comment, true); break;
				case 'nested-type': $comment["\0*\0post_fields"]['ID'] = '42'; break;
			}
			self::assertNotSame($before, Wstm108_Evidence::summary_commitment($case['semantic']['after']));
		});
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('denial changed owned state');
		Wstm_Product_Evidence_Verifier::verify('untrusted', $this->root, $export['context']);
	}

	public static function semanticMutations(): array {
		return array_map( static fn( $value ) => array( $value ), array( 'patch-before', 'patch-after', 'patch-block',
			'revision-list', 'revision-restore', 'audit-account', 'audit-app', 'meta-read', 'meta-update', 'meta-readback',
			'meta-delete', 'denial-write', 'alias-write', 'readable-key', 'provider-raw-key', 'provider-unavailable',
			'provider-nested', 'score-key', 'score-value', 'trap-map', 'trap-unavailable', 'trap-provider', 'missing', 'schema', 'extra',
			'revision-missing-first', 'revision-reordered', 'metadata-missing-first' ) );
	}

	/** @dataProvider semanticMutations */
	public function testMutatedOraclesOrOriginalValuesRefuseEvenWithSuccessfulFlags( string $mutation ): void {
		$suffixes = array( 'patch-before' => 'patch-content-block', 'patch-after' => 'patch-content-block', 'patch-block' => 'patch-content-block',
			'revision-list' => 'post:revisions', 'revision-restore' => 'post:revisions', 'audit-account' => 'user-access-audit', 'audit-app' => 'user-access-audit',
			'meta-read' => 'standalone-meta-read-update', 'meta-update' => 'standalone-meta-read-update', 'meta-readback' => 'standalone-meta-read-update',
			'meta-delete' => 'standalone-meta-delete', 'denial-write' => 'subscriber-denied:update-post', 'alias-write' => 'no-combined-seo-alias-write',
			'readable-key' => 'seo-key-policy:_yoast_wpseo_title', 'provider-raw-key' => 'yoast:metadata', 'provider-unavailable' => 'yoast:metadata',
			'provider-nested' => 'yoast:metadata', 'score-key' => 'get-seo-scores', 'score-value' => 'get-seo-scores',
			'trap-map' => 'seo-forbidden-read-trap:yoast_meta_description', 'trap-unavailable' => 'seo-forbidden-read-trap:yoast_meta_description',
			'trap-provider' => 'seo-forbidden-read-trap:yoast_meta_description', 'missing' => 'post:revisions', 'schema' => 'post:revisions', 'extra' => 'post:revisions',
			'revision-missing-first' => 'post:revisions', 'revision-reordered' => 'post:revisions', 'metadata-missing-first' => 'standalone-meta-read-update' );
		$entries = Wstm_Product_Evidence_Model::untrusted_model()['models']; $entry = null;
		foreach ( $entries as $model ) { if ( 'gateway:' . $suffixes[ $mutation ] === $model['case']['label'] ) { $entry = $model; break; } }
		self::assertNotNull( $entry ); $case = $entry['case']; $responses = $entry['responses']; $response = $case['response'];
		switch ( $mutation ) {
			case 'patch-before': $case['semantic']['before'] .= 'changed'; break;
			case 'patch-after': $case['semantic']['after'] .= 'changed'; break;
			case 'patch-block': $case['semantic']['blocks_before'][0]['hash'] = str_repeat( '0', 64 ); break;
			case 'revision-list': $responses[0]['data']->revisions[0]->content = 'changed'; break;
			case 'revision-restore': $response['data']->post->untrusted_fields = array(); break;
			case 'audit-account': $response['data']->admin_accounts[0]->last_login = 0; break;
			case 'audit-app': $response['data']->application_passwords[0]->app_name = 'changed'; break;
			case 'meta-read': $responses[0]['data']->meta->wstm108_payload[0]->untrusted_fields = array(); break;
			case 'meta-update': $response['data']->current_value->list[0] = '1'; break;
			case 'meta-readback': $case['semantic']['after']['list'][0] = '1'; break;
			case 'meta-delete': $case['semantic']['after'][] = 'remaining'; break;
			case 'denial-write': $case['semantic']['after'][0]['title'] = 'changed'; break;
			case 'alias-write': $case['semantic']['after']['owned']['success'] = true; break;
			case 'readable-key': $case['expected']['meta'] = array( 'foreign' => array( 'value' ) ); break;
			case 'provider-raw-key': $response['data']->raw_meta->title->key = 'foreign'; break;
			case 'provider-unavailable': $response['data']->unavailable_fields->canonical_url->code = 'allowed'; break;
			case 'provider-nested': $case['nested_expected']['list'][0] = '1'; break;
			case 'score-key': $case['score_key'] = '_yoast_wpseo_content_score'; break;
			case 'score-value': $response['data']->items[0]->score = '42'; break;
			case 'trap-map': $case['semantic']['restricted']['_yoast_wpseo_metadesc'] = true; break;
			case 'trap-unavailable': $responses[0]['data']->unavailable_fields->yoast_meta_description->reason = 'allowed'; break;
			case 'trap-provider': $response['data']->untrusted_fields = array(); break;
			case 'missing': unset( $case['semantic'] ); break;
			case 'schema': $case['semantic']['schema'] = 'future'; break;
			case 'extra': $case['semantic']['ignored'] = true; break;
			case 'revision-missing-first':
			case 'metadata-missing-first': array_shift( $responses ); break;
			case 'revision-reordered': $responses = array_reverse( $responses ); break;
		}
		$this->expectException( RuntimeException::class );
		Wstm_Product_Evidence_Verifier::replay_case( $case, $response, $responses );
	}

	public function testFullyRehashedSuccessfulCaptureCannotHideDeniedMutation(): void {
		$export = $this->export( static function ( array &$case, array &$event, int $index ): void {
			if ( 'gateway:subscriber-denied:update-post' === $case['label'] ) { $case['semantic']['after'][0]['title'] = 'changed'; }
		} );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'denial changed owned state' );
		Wstm_Product_Evidence_Verifier::verify( 'untrusted', $this->root, $export['context'] );
	}

	public function testFullyRehashedCaptureMustRetainActualRestrictedRequestHeaders(): void {
		$export = $this->export( static function ( array &$case, array &$event, int $index ): void {
			if ( 'gateway:seo-forbidden-read-trap:yoast_meta_description' === $case['label'] ) {
				$event['request_headers']['X-WSTM108-Deny-Key'] = 'foreign';
			}
		} );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'actual restricted-read headers' );
		Wstm_Product_Evidence_Verifier::verify( 'untrusted', $this->root, $export['context'] );
	}

	public function testActualDiscoveredGatewayAndIndividualAbilityAssociation(): void {
		$method = new ReflectionMethod( Wstm_Product_Evidence_Verifier::class, 'call_identity' ); $method->setAccessible( true );
		$tool = (object) array( 'name' => 'actual_execute', 'inputSchema' => (object) array( 'properties' => (object) array(
			'ability_name' => new stdClass(), 'parameters' => new stdClass() ) ) );
		$event = array( 'boundary' => 'gateway:administrator', 'request' => array( 'params' => array(
			'name' => 'actual_execute', 'arguments' => array( 'ability_name' => 'webmastery-site-toolkit-for-mcp/get-post', 'parameters' => array( 'post_id' => 42 ) ) ) ) );
		$catalogs = array( 'gateway:administrator' => array( $tool ) );
		self::assertSame( array( 'post_id' => 42 ), $method->invoke( null, $event, 'get-post', array(), $catalogs ) );
		$variants = array();
		$changed = $event; $changed['request']['params']['name'] = 'guessed_execute'; $variants[] = array( $changed, array() );
		$changed = $event; $changed['request']['params']['arguments']['ability_name'] = 'webmastery-site-toolkit-for-mcp/update-post'; $variants[] = array( $changed, array() );
		$changed = $event; $changed['boundary'] = 'individual:administrator'; $variants[] = array( $changed, array( 'webmastery-site-toolkit-for-mcp/get-post' => 'actual_individual' ) );
		foreach ( $variants as [ $changed, $descriptors ] ) {
			try { $method->invoke( null, $changed, 'get-post', $descriptors, $catalogs ); self::fail( 'Foreign actual tool association was accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'association', $error->getMessage() ); }
		}
	}
}
