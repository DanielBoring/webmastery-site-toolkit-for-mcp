<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/product-evidence-verifier.php';
require_once __DIR__ . '/fixtures/product-evidence-model.php';
require_once dirname( __DIR__, 2 ) . '/scripts/product-artifacts.php';
require_once __DIR__ . '/fixtures/product-artifact-export.php';

final class ProductEvidenceVerifierTest extends TestCase {
	public function testExplicitFailedPreAcquisitionUsesActualClosedRecordsWithoutClaimingProducerSuccess(): void {
		$context = Wstm_Product_Evidence_Model::context(); $bytes = json_encode( $context, JSON_THROW_ON_ERROR );
		$verdict = $this->verdict();
		$verdict['status'] = 'failed'; $verdict['exit'] = 1; $verdict['private_error'] = 'Original empty-table refusal';
		$verdict['phase'] = 'pre-acquisition'; $verdict['catalog'] = 'small';
		$pre = array( 'schema' => 'wstm-bounded-pre-acquisition-v1', 'context_sha256' => hash( 'sha256', $bytes ),
			'binding' => $verdict['binding'], 'namespace' => 'w121_' . substr( $context['run'], 0, 12 ),
			'token' => $context['token'], 'small_catalog' => true, 'acquisition_started' => false );
		$before = Wstm_Product_Evidence_Model::snapshot();
		$readback = array( 'remaining' => array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0, 'postmeta' => 0 ),
			'snapshot' => $before );
		$parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-pre-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $parent, 0700 ) );
		$root = $parent . DIRECTORY_SEPARATOR . 'bounded';
		self::assertTrue( mkdir( $root, 0700 ) ); self::assertTrue( mkdir( $root . DIRECTORY_SEPARATOR . 'small', 0700 ) );
		$files = array( 'product-verdict.private.json' => $verdict, 'small/pre-acquisition.json' => $pre,
			'small/setup-before.json' => $before, 'small/cleanup-readback.json' => $readback );
		try {
			foreach ( $files as $name => $data ) {
				self::assertNotFalse( file_put_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $name ),
					json_encode( $data, JSON_THROW_ON_ERROR ) ) );
			}
			$capture = $parent . DIRECTORY_SEPARATOR . 'capture.private';
			self::assertNotFalse( file_put_contents( $capture, \WstmProductArtifactExport\capture( dirname( $parent ), basename( $parent ) ) ) );
			$imported = $parent . DIRECTORY_SEPARATOR . 'imported';
			$frames = Wstm_Product_Artifacts::import( 'bounded', $capture, $imported );
			self::assertCount( 4, $frames );
			foreach ( $frames as $frame ) {
				self::assertSame( file_get_contents( $root . '/' . $frame['path'] ), file_get_contents( $imported . '/' . $frame['path'] ) );
			}
			$import_result = Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $imported, $bytes );
			self::assertSame( 'cleanup-records-verified-live-absence-unresolved', $import_result['status'] );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify( 'bounded', $imported, $bytes ) );
			$result = Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $root, $bytes );
			self::assertSame( 1, $result['count'] );
			self::assertSame( 'cleanup-records-verified-live-absence-unresolved', $result['status'] );
			self::assertSame( 'failed', Wstm_Product_Evidence_Verifier::verdict( 'bounded', $verdict, $context, hash( 'sha256', $bytes ) ) );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify( 'bounded', $root, $bytes ) );
			foreach ( array( 'phase', 'started', 'token', 'digest', 'dependency', 'snapshot' ) as $kind ) {
				$changed = $pre; $final = $verdict; $after = $readback;
				switch ( $kind ) {
					case 'phase': unset( $final['phase'], $final['catalog'] ); break;
					case 'started': $changed['acquisition_started'] = true; break;
					case 'token': $changed['token'] = str_repeat( 'd', 64 ); break;
					case 'digest': $changed['context_sha256'] = str_repeat( 'd', 64 ); break;
					case 'dependency': $after['remaining']['postmeta'] = 1; break;
					case 'snapshot': ++$after['snapshot']['commentmeta']['rows']; break;
				}
				$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::pre_acquisition_cleanup(
					$changed, $before, $after, $context, $final, 'small' ) );
			}
			$lost = $verdict; unset( $lost['phase'], $lost['catalog'] );
			file_put_contents( $root . DIRECTORY_SEPARATOR . 'product-verdict.private.json', json_encode( $lost, JSON_THROW_ON_ERROR ) );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $root, $bytes ) );
		} finally {
			if ( isset( $imported ) && is_dir( $imported ) ) {
				foreach ( array_keys( $files ) as $name ) { unlink( $imported . '/' . $name ); }
				rmdir( $imported . DIRECTORY_SEPARATOR . 'small' ); rmdir( $imported );
			}
			if ( isset( $capture ) && file_exists( $capture ) ) { unlink( $capture ); }
			foreach ( array_keys( $files ) as $name ) {
				unlink( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $name ) );
			}
			rmdir( $root . DIRECTORY_SEPARATOR . 'small' ); rmdir( $root ); rmdir( $parent );
		}
	}

	private function refuses( callable $call ): void {
		try { $call(); } catch ( RuntimeException $error ) { self::assertNotSame( '', $error->getMessage() ); return; }
		self::fail( 'Altered private evidence accepted.' );
	}

	private function verdict( string $stage = 'bounded' ): array {
		$context = Wstm_Product_Evidence_Model::context();
		$verdict = array( 'schema' => 'wstm-product-verdict-v1', 'context_sha256' => hash( 'sha256', json_encode( $context ) ),
			'binding' => array( 'owner' => $context['run'], 'project' => $context['project'], 'identity_kind' => 'package', 'identity_sha256' => $context['identity_sha256'] ),
			'status' => 'passed', 'cleanup_complete' => true, 'php' => '8.2.31', 'uid' => 33, 'exit' => 0 );
		if ( 'bounded' === $stage ) { $verdict['catalogs'] = array(); }
		else { $verdict += array( 'expected_labels' => array(), 'observed_labels' => array(), 'passed' => 298, 'failed' => 0 ); }
		return $verdict;
	}

	public function testBothIndependentPlansRecomputeAllNumericRecordsWithoutPassFlags(): void {
		$count = 0;
		foreach ( array( true, false ) as $small ) {
			$state = Wstm_Product_Evidence_Model::state( $small );
			Wstm_Product_Evidence_Verifier::state( $state, Wstm_Product_Evidence_Model::context(), $small );
			$jobs = $small ? Wstm_Product_Evidence_Verifier::small_plan( $state ) : Wstm121Plan::selected( $state );
			self::assertCount( $small ? 1125 : 336, $jobs );
			self::assertCount( count( $jobs ), array_unique( array_column( $jobs, 'coverage_key' ) ) );
			foreach ( $jobs as $job ) {
				$record = Wstm_Product_Evidence_Model::record( $state, $job );
				self::assertFalse( $record['assertions_passed'] );
				$derived = Wstm_Product_Evidence_Verifier::operation( $state, $job, $record );
				self::assertSame( $record['item_ids'], $derived['item_ids'] ); ++$count;
			}
		}
		self::assertSame( 1461, $count );
	}

	public static function mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'query', 'candidate', 'cap', 'sql', 'response', 'clock', 'memory', 'mutation', 'failure', 'job', 'payload', 'continuation' ) );
	}

	/** @dataProvider mutations */
	public function testRawNumericMutationsCannotHideBehindSuccessfulFlags( string $kind ): void {
		$state = Wstm_Product_Evidence_Model::state( false ); $job = Wstm121Plan::selected( $state )[0]; $record = Wstm_Product_Evidence_Model::record( $state, $job );
		$record['assertions_passed'] = true;
		switch ( $kind ) {
			case 'query': $record['queries'][0]['no_found_rows'] = false; break;
			case 'candidate': array_pop( $record['queries'][0]['returned_ids'] ); break;
			case 'cap': $record['raw_cap_calls'][] = array( 'requested' => array( 'read_post', 42, 1 ), 'mapped' => array() ); break;
			case 'sql': $record['sql'][] = 'DELETE FROM owned_posts'; break;
			case 'response': $record['response']['data']['items'][0]['id'] = 1; break;
			case 'clock': $record['operation_end']['time_ns'] = 9; break;
			case 'memory': $record['operation_end']['peak_allocated_bytes'] = 100; break;
			case 'mutation': $record['mutations'][] = 'save_post'; break;
			case 'failure': $record['failure'] = 'retained'; break;
			case 'job': $record['job']['mode'] = 'all-denied'; break;
			case 'payload': ++$record['payload_bytes']; break;
			case 'continuation': $record['response']['data']['next_page'] = null; break;
		}
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::operation( $state, $job, $record ) );
	}

	public function testStateOracleSourcesAndOwnerMutationRefuse(): void {
		$original = Wstm_Product_Evidence_Model::state( true ); $context = Wstm_Product_Evidence_Model::context();
		foreach ( array( 'token', 'namespace', 'source', 'referenced', 'duplicate', 'size' ) as $kind ) {
			$state = $original;
			switch ( $kind ) {
				case 'token': $state['token'] = str_repeat( 'd', 64 ); break;
				case 'namespace': $state['namespace'] = 'w121_foreign'; break;
				case 'source': $state['sources']['loaded']['Webmastery_MCP_Posts']['sha256'] = str_repeat( 'd', 64 ); break;
				case 'referenced': array_pop( $state['referenced'] ); break;
				case 'duplicate': $state['catalog']['post'][1] = $state['catalog']['post'][0]; break;
				case 'size': array_pop( $state['catalog']['page'] ); break;
			}
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::state( $state, $context, true ) );
		}
	}

	public function testClosedVerdictsBindLaneUidContextAndExactTypes(): void {
		$context = Wstm_Product_Evidence_Model::context();
		foreach ( array( 'bounded', 'untrusted' ) as $stage ) {
			$original = $this->verdict( $stage );
			self::assertSame( 'passed', Wstm_Product_Evidence_Verifier::verdict( $stage, $original, $context, $original['context_sha256'] ) );
			foreach ( array( 'uid', 'php', 'binding', 'hash', 'extra', 'exit', 'null-version' ) as $kind ) {
				$changed = $original;
				switch ( $kind ) {
					case 'uid': $changed['uid'] = '33'; break;
					case 'php': $changed['php'] = '8.4.1'; break;
					case 'binding': $changed['binding']['project'] = 'foreign'; break;
					case 'hash': $changed['context_sha256'] = str_repeat( 'd', 64 ); break;
					case 'extra': $changed['secret'] = 'not-public'; break;
					case 'exit': $changed['exit'] = false; break;
					case 'null-version': $changed['php'] = null; break;
				}
				$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verdict( $stage, $changed, $context, $original['context_sha256'] ) );
			}
		}
	}

	public function testCleanupMustUseActualTypedZeroCountsAndPreseedEquality(): void {
		$before = Wstm_Product_Evidence_Model::snapshot();
		$original = array( 'remaining' => array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0 ), 'snapshot' => $before );
		Wstm_Product_Evidence_Verifier::cleanup( $before, $original ); self::addToAssertionCount( 1 );
		foreach ( array( 'nonzero', 'string', 'snapshot', 'flag' ) as $kind ) {
			$changed = $original;
			if ( 'nonzero' === $kind ) { $changed['remaining']['actor'] = 1; }
			if ( 'string' === $kind ) { $changed['remaining']['posts'] = '0'; }
			if ( 'snapshot' === $kind ) { $changed['snapshot'] = array(); }
			if ( 'flag' === $kind ) { $changed['passed'] = true; }
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::cleanup( $before, $changed ) );
		}
	}

	public function testTypedWireFramesAndRolesRetainOriginalBinding(): void {
		$event = array( 'boundary' => 'individual:subscriber', 'body' => '{"result":{}}', 'status' => 200 );
		$bytes = "WSTM108-WIRE-1\n" . serialize( $event );
		$record = array( 'length' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'body_length' => strlen( $event['body'] ),
			'body_sha256' => hash( 'sha256', $event['body'] ), 'status' => 200 );
		self::assertSame( $event, Wstm_Product_Evidence_Verifier::frame( $record, $bytes ) );
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::frame( $record, str_replace( 'subscriber', 'adminxxxxx', $bytes ) ) );
		$record['body_sha256'] = str_repeat( 'd', 64 );
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::frame( $record, $bytes ) );
	}

	public function testActualAnnotationReplayRejectsHintAndGatewayMetadataMutation(): void {
		$annotations = array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
		$registered = array( 'name' => 'webmastery-site-toolkit-for-mcp/bulk-publish-posts', 'label' => 'Publish',
			'meta' => array( 'annotations' => $annotations ) );
		$descriptor = (object) array( 'title' => 'Publish', 'annotations' => (object) array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'title' => 'Publish' ) );
		$info = array( 'result' => (object) array( 'structuredContent' => (object) array( 'name' => $registered['name'], 'meta' => (object) array( 'annotations' => (object) $annotations ) ) ) );
		Wstm_Product_Evidence_Verifier::annotation( $registered, $descriptor, $info ); self::addToAssertionCount( 1 );
		$descriptor->annotations->destructiveHint = false;
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::annotation( $registered, $descriptor, $info ) );
		$descriptor->annotations->destructiveHint = true; $info['result']->structuredContent->meta->annotations->readonly = true;
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::annotation( $registered, $descriptor, $info ) );
	}

	public function testDuplicateNestedJsonKeysRefuseAndStoredColonStringsSurvive(): void {
		self::assertSame( array( 'body' => '{"a":1}', 'nested' => array( 'a' => 1 ) ),
			Wstm_Product_Evidence_Reader::decode_json( '{"body":"{\\"a\\":1}","nested":{"a":1}}' ) );
		$this->refuses( static fn() => Wstm_Product_Evidence_Reader::decode_json( '{"nested":{"a":1,"\\u0061":2}}' ) );
	}

	public function testReaderPreservesExportsAndRetainsFailedReservationsWithoutRetry(): void {
		$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-evidence-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0700 ) ); $path = $root . DIRECTORY_SEPARATOR . 'record.json';
		file_put_contents( $path, '{"a":1}' );
		try {
			$reader = new Wstm_Product_Evidence_Reader( str_replace( '\\', '/', realpath( $root ) ), 'bounded' );
			self::assertSame( array( 'a' => 1 ), $reader->json( 'record.json' ) );
			$reader->finish(); self::assertSame( '{"a":1}', file_get_contents( $path ) );
			$property = new ReflectionProperty( $reader, 'bytes' ); $property->setAccessible( true );
			$property->setValue( $reader, array( 'output' => 536870912, 'wire' => 0 ) );
			$this->refuses( static fn() => $reader->read( 'record.json' ) );
			$this->refuses( static fn() => $reader->read( 'record.json' ) );
			self::assertSame( 536870912, $property->getValue( $reader )['output'] );
			$reader = new Wstm_Product_Evidence_Reader( str_replace( '\\', '/', realpath( $root ) ), 'bounded' );
			$reader->read( 'record.json' ); file_put_contents( $path, '{"a":2}' );
			$this->refuses( static fn() => $reader->finish() );
		} finally { unlink( $path ); rmdir( $root ); }
	}

	public function testCompleteSelectedBoundedExportAndFailedCleanupUseEveryEnteredCatalog(): void {
		$parent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-evidence-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $parent, 0700 ) );
		$root = $parent . DIRECTORY_SEPARATOR . 'bounded';
		self::assertTrue( mkdir( $root, 0700 ) ); $export = array( 'files' => array(), 'directories' => array() );
		try {
			$export = Wstm_Product_Evidence_Model::export( $root );
			$summary = Wstm_Product_Evidence_Verifier::verify( 'bounded', $root, $export['context'] );
			self::assertSame( array( 'stage' => 'bounded', 'status' => 'records-verified-live-absence-unresolved',
				'count' => 1461, 'lane' => '8.2', 'uid' => 33 ), $summary );
			$capture = $parent . DIRECTORY_SEPARATOR . 'capture.private';
			self::assertNotFalse( file_put_contents( $capture, \WstmProductArtifactExport\capture( dirname( $parent ), basename( $parent ) ) ) );
			$imported = $parent . DIRECTORY_SEPARATOR . 'imported';
			$frames = Wstm_Product_Artifacts::import( 'bounded', $capture, $imported );
			self::assertCount( count( $export['files'] ), $frames );
			self::assertSame( $summary, Wstm_Product_Evidence_Verifier::verify( 'bounded', $imported, $export['context'] ) );
			$probe_path = $imported . '/large/window-cache-faults.json';
			$probe_bytes = file_get_contents( $probe_path );
			$probe = json_decode( $probe_bytes, true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( '', $probe['phases']['priming']['private_database_error'] );
			self::assertSame( 'before-next-query', $probe['phases']['priming']['failure_witness']['boundary'] );
			foreach ( array( 'missing', 'foreign', 'nonexecuted' ) as $fault ) {
				$changed = $probe;
				if ( 'missing' === $fault ) { unset( $changed['phases']['priming']['failure_witness'] ); }
				if ( 'foreign' === $fault ) { $changed['phases']['priming']['failure_witness']['query_sha256'] = str_repeat( 'a', 64 ); }
				if ( 'nonexecuted' === $fault ) { $changed['phases']['priming']['failure_witness']['result_failed'] = false; }
				file_put_contents( $probe_path, json_encode( $changed, JSON_THROW_ON_ERROR ) );
				$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify( 'bounded', $imported, $export['context'] ) );
			}
			file_put_contents( $probe_path, $probe_bytes );
			self::assertSame( $summary, Wstm_Product_Evidence_Verifier::verify( 'bounded', $imported, $export['context'] ) );
			foreach ( array( 'small', 'large' ) as $catalog ) {
				$pre = $catalog . '/pre-acquisition.json';
				self::assertSame( file_get_contents( $root . '/' . $pre ), file_get_contents( $imported . '/' . $pre ) );
				self::assertContains( $pre, array_column( $frames, 'path' ) );
			}
			$path = $root . DIRECTORY_SEPARATOR . 'product-verdict.private.json'; $bytes = file_get_contents( $path ); $verdict = json_decode( $bytes, true );
			$verdict['status'] = 'failed'; $verdict['exit'] = 1; $verdict['private_error'] = 'private failure';
			$verdict['catalogs'] = array_slice( $verdict['catalogs'], 0, 1 ); file_put_contents( $path, json_encode( $verdict ) );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify( 'bounded', $root, $export['context'] ) );
			self::assertSame( 2, Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $root, $export['context'] )['count'] );
			$cleanup = $root . DIRECTORY_SEPARATOR . 'large' . DIRECTORY_SEPARATOR . 'cleanup-readback.json';
			$record = json_decode( file_get_contents( $cleanup ), true ); $record['remaining']['posts'] = 1;
			file_put_contents( $cleanup, json_encode( $record ) );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $root, $export['context'] ) );
			unlink( $cleanup );
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verify_cleanup( 'bounded', $root, $export['context'] ) );
		} finally {
			if ( isset( $imported ) && is_dir( $imported ) ) {
				foreach ( array_reverse( $export['files'] ) as $path ) { unlink( $imported . substr( $path, strlen( $root ) ) ); }
				foreach ( array_reverse( $export['directories'] ) as $path ) { rmdir( $imported . substr( $path, strlen( $root ) ) ); }
				rmdir( $imported );
			}
			if ( isset( $capture ) && file_exists( $capture ) ) { unlink( $capture ); }
			foreach ( array_reverse( $export['files'] ) as $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
			foreach ( array_reverse( $export['directories'] ) as $path ) { rmdir( $path ); }
			rmdir( $root ); rmdir( $parent );
		}
	}

	public function testTypedCaseOracleReplayAndRawRoleBindingRejectIndependentMutations(): void {
		$expected = array( 'id' => 42, 'title' => 'Stored', 'content' => 'Do not execute.', 'meta' => array( 'untrusted_fields' => 'literal stored key' ) );
		$data = (object) $expected; $data->untrusted_fields = array( 'title', 'content' );
		$response = array( 'success' => true, 'data' => $data );
		$case = array( 'label' => 'individual:post:get', 'passed' => false, 'expected' => $expected, 'response' => $response );
		$text = json_encode( $response, JSON_THROW_ON_ERROR );
		$event = array( 'boundary' => 'individual:administrator', 'method' => 'tools/call', 'status' => 200, 'transport_error' => false,
			'request' => array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array() ),
			'body' => json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) ) ), JSON_THROW_ON_ERROR ) );
		self::assertNull( Wstm_Product_Evidence_Verifier::replay_case( $case, Wstm_Product_Evidence_Verifier::case_response( $case, $event ) ) );
		$changed = $event; $changed['boundary'] = 'individual:subscriber';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::case_response( $case, $changed ) );
		$changed = $event; $changed['method'] = 'tools/list';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::case_response( $case, $changed ) );
		$changed = $event; $changed['request']['id'] = 3;
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::case_response( $case, $changed ) );
		$changed = $event; $changed['body'] = str_replace( 'Stored', 'Altered', $changed['body'] );
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::case_response( $case, $changed ) );
		$changed = $case; $changed['expected']['title'] = 'Altered';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::replay_case( $changed, $response ) );
		$changed = $response; $changed['data'] = clone $data; $changed['data']->untrusted_fields[] = 'meta';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::replay_case( $case, $changed ) );
		$case['label'] = 'individual:post:list:summary-default';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::replay_case( $case, $response ) );
	}

	public function testRetainedExpectedRecordsAreNotAllReportedMissing(): void {
		$record = (object) array( 'id' => 42, 'title' => '', 'untrusted_fields' => array( 'title' ) );
		$response = array( 'success' => true, 'data' => (object) array( 'items' => array( $record ) ) );
		$case = array( 'label' => 'gateway:post:list', 'expected' => array( 'id' => 42, 'title' => '' ) );
		self::assertNull( Wstm_Product_Evidence_Verifier::replay_case( $case, $response ) );
		$response['data']->items[] = clone $record;
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::replay_case( $case, $response ) );
		self::assertCount( 298, Wstm108_Plan::labels( Wstm108_Plan::native_from_manifest( dirname( __DIR__ ) . '/e2e/abilities-manifest.json' ) ) );
		$case['label'] = 'gateway:post:revisions';
		$this->expectException( Wstm_Product_Evidence_Gap::class );
		Wstm_Product_Evidence_Verifier::replay_case( $case, array( 'success' => true, 'data' => new stdClass() ) );
	}

	public function testClosedCompleteAndFailedSchemasIncludeEverySupportedLane(): void {
		foreach ( array( '8.0', '8.1', '8.2', '8.4' ) as $lane ) {
			$context = Wstm_Product_Evidence_Model::context(); $context['php_floor'] = $lane;
			self::assertSame( $context, Wstm_Product_Evidence_Verifier::context( json_encode( $context ) ) );
			$verdict = $this->verdict(); $verdict['php'] = $lane . '.1';
			self::assertSame( 'passed', Wstm_Product_Evidence_Verifier::verdict( 'bounded', $verdict, $context, $verdict['context_sha256'] ) );
			foreach ( array( '8.0', '8.1', '8.2', '8.4' ) as $observed ) {
				if ( $observed === $lane ) { continue; }
				$wrong = $verdict; $wrong['php'] = $observed . '.1';
				$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verdict( 'bounded', $wrong, $context, $wrong['context_sha256'] ) );
			}
			$verdict['status'] = 'failed'; $verdict['exit'] = 1; $verdict['private_error'] = 'private';
			self::assertSame( 'failed', Wstm_Product_Evidence_Verifier::verdict( 'bounded', $verdict, $context, $verdict['context_sha256'] ) );
			$verdict['private_error'] = null;
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::verdict( 'bounded', $verdict, $context, $verdict['context_sha256'] ) );
		}
		$context = Wstm_Product_Evidence_Model::context(); $context['extra'] = 'private';
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::context( json_encode( $context ) ) );
		$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::context( str_repeat( ' ', 4097 ) ) );
	}

	public function testPathLimitsAndClosedInventoriesStaySticky(): void {
		$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-evidence-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0700 ) ); $path = $root . DIRECTORY_SEPARATOR . 'record.json'; file_put_contents( $path, '{}' );
		try {
			$reader = new Wstm_Product_Evidence_Reader( $root, 'untrusted' ); $reader->listing();
			file_put_contents( $root . DIRECTORY_SEPARATOR . 'unexpected.json', '{}' );
			$this->refuses( static fn() => $reader->finish() );
			$this->refuses( static fn() => $reader->read( 'record.json' ) );
			$reader = new Wstm_Product_Evidence_Reader( $root, 'bounded' );
			$property = new ReflectionProperty( $reader, 'files' ); $property->setAccessible( true );
			$property->setValue( $reader, array_fill_keys( range( 0, 4095 ), array() ) );
			$this->refuses( static fn() => $reader->read( 'record.json' ) );
			self::assertSame( '{}', file_get_contents( $path ) );
			$reader = new Wstm_Product_Evidence_Reader( $root, 'bounded' );
			$this->refuses( static fn() => $reader->read( '../record.json' ) );
		} finally { unlink( $path ); unlink( $root . DIRECTORY_SEPARATOR . 'unexpected.json' ); rmdir( $root ); }
	}

	public function testSafetyDenialsReplayReasonsInsteadOfReportingExistingFactsMissing(): void {
		foreach ( array( 'no-opaque-head' => 'generated_head_key_authorization_unavailable',
			'private-image-url-still-denied' => 'invalid_url', 'no-combined-seo-alias-write' => 'metadata_requires_separate_call' ) as $suffix => $reason ) {
			$case = array( 'label' => 'gateway:' . $suffix, 'passed' => true );
			$response = array( 'success' => false, 'error' => array( 'code' => wstm118_expected_code( $reason ),
				'reason' => $reason, 'message' => 'Finite error.', 'details' => new stdClass() ) );
			$gap = Wstm_Product_Evidence_Verifier::replay_case( $case, $response );
			self::assertSame( 'no-combined-seo-alias-write' === $suffix ? $suffix . ': owned metadata snapshots before and after denial' : null, $gap );
			$response['error']['reason'] = 'invalid_input'; $response['error']['code'] = 'invalid_input';
			$this->refuses( static fn() => Wstm_Product_Evidence_Verifier::replay_case( $case, $response ) );
		}
	}

	public function testProtectedAcquisitionRejectsSizeDriftBeforeReadingReservedBytes(): void {
		$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-evidence-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0700 ) ); $path = $root . DIRECTORY_SEPARATOR . 'record.json'; file_put_contents( $path, '{}' );
		try {
			$original = Wstm108_Files::file( $path );
			self::assertSame( $original, Wstm108_Files::read_bound( $path, $original['identity'], 2 ) );
			file_put_contents( $path, '{"changed":true}' );
			$this->refuses( static fn() => Wstm108_Files::read_bound( $path, $original['identity'], 2 ) );
			file_put_contents( $path, '' );
			self::assertSame( '', Wstm108_Files::read_bound( $path, $original['identity'], 0 )['bytes'] );
			$this->refuses( static fn() => Wstm108_Files::read_bound( $path, $original['identity'], 33554433 ) );
		} finally { unlink( $path ); rmdir( $root ); }
	}

	public function testHostCliKeepsExceptionsPrivateAndOnlyExportsFiveClosedFields(): void {
		$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-evidence-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0700 ) ); $path = $root . DIRECTORY_SEPARATOR . 'context.json';
		file_put_contents( $path, json_encode( Wstm_Product_Evidence_Model::context(), JSON_THROW_ON_ERROR ) );
		try {
			$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/e2e/product-evidence-verifier.php', 'bounded', $root, $path ),
				array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process ); fclose( $pipes[0] );
			$stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 1, proc_close( $process ) );
			self::assertSame( array( 'stage' => 'bounded', 'status' => 'refused', 'count' => 0, 'lane' => 'unknown', 'uid' => null ),
				json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR ) );
			self::assertStringContainsString( 'RuntimeException', $stderr );
			self::assertStringNotContainsString( $root, $stdout );
			self::assertStringNotContainsString( str_repeat( 'b', 64 ), $stdout );
		} finally { unlink( $path ); rmdir( $root ); }
	}
}
