<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-verifier.php';

final class BoundedProbeVerifierTest extends TestCase {
	private static function state(): array {
		return array( 'cpt' => 'w121_0123456789ab', 'catalog' => array( 'post' => array( 201, 200, 199 ), 'attachment' => range( 100, 1 ) ),
			'referenced' => array( 100, 99, 98, 97, 96 ), 'revision' => 300 );
	}

	private static function reference(): array {
		$state = self::state();
		$expected = array();
		foreach ( $state['catalog']['attachment'] as $id ) {
			$expected[ $id ] = in_array( $id, $state['referenced'], true );
		}
		$sql = array( "SELECT meta_value FROM wp_postmeta WHERE meta_key='_thumbnail_id' AND meta_value IN ('94')",
			"SELECT MAX(post_content LIKE '%literal%') FROM wp_posts" );
		$failures = array();
		foreach ( array( 'thumbnail', 'content' ) as $index => $phase ) {
			$failures[ $phase ] = array(
				'helper' => array( 'code' => 'content_hygiene_query_failed' ),
				'forced_input' => array( 'media_id' => 94, 'confirm' => true, 'force' => true ),
				'forced_response' => array( 'success' => false, 'error' => array( 'code' => 'upstream_failed', 'reason' => 'content_hygiene_query_failed' ) ),
				'injected' => array_fill( 0, 2, array( 'original' => $sql[ $index ], 'replacement' => 'SELECT wstm121_deliberate_missing_column FROM wp_posts LIMIT 1' ) ),
			);
		}
		return array( 'ids' => $state['catalog']['attachment'], 'legacy' => $expected, 'single' => $expected, 'batch' => $expected,
			'before' => array( 'snapshot' => 'unchanged' ), 'after' => array( 'snapshot' => 'unchanged' ),
			'mutations' => array(), 'sql' => $sql, 'read_failures' => $failures );
	}

	private static function cache(): array {
		$response = array( 'success' => true, 'data' => array( 'items' => array( array( 'id' => 201 ), array( 'id' => 200 ) ), 'page' => 1, 'per_page' => 2, 'next_page' => 2 ) );
		$phase = array( 'injected' => 1, 'request' => 'SELECT ID FROM wp_posts LIMIT 3', 'sql' => array( 'SELECT ID FROM wp_posts LIMIT 3' ),
			'query_start' => 10, 'query_end' => 11, 'private_database_error' => 'Unknown wstm121_deliberate_missing_column',
			'generation_before' => 'old', 'generation_after' => 'new',
			'failed_response' => array( 'success' => false, 'error' => array( 'code' => 'upstream_failed', 'message' => 'The list query could not be completed.' ) ),
			'retry' => array( 'query_start' => 11, 'query_end' => 12, 'response' => $response ),
			'warm_stale' => array( 'query_start' => 12, 'query_end' => 12, 'response' => $response, 'last_error' => 'wstm121_stale_error_without_new_sql' ) );
		return array( 'input' => array( 'page' => 1, 'per_page' => 2, 'orderby' => 'id', 'order' => 'DESC' ),
			'expected_ids' => array( 201, 200 ), 'before' => array( 'same' ), 'after' => array( 'same' ),
			'phases' => array( 'candidate' => $phase, 'priming' => $phase ) );
	}

	public function test_original_reference_and_cache_observations_are_accepted(): void {
		Wstm121Verifier::reference_probe( self::state(), self::reference() );
		Wstm121Verifier::cache_probe( self::state(), self::cache() );
		self::addToAssertionCount( 2 );
	}

	public static function reference_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'short', 'legacy', 'single', 'batch', 'force', 'success', 'helper', 'missing-injection', 'wrong-query', 'sql-leak', 'write', 'hook', 'state', 'failure' ) );
	}

	/** @dataProvider reference_mutations */
	public function test_reference_or_force_evidence_cannot_be_relaxed( string $mutation ): void {
		$record = self::reference();
		switch ( $mutation ) {
			case 'short': array_pop( $record['ids'] ); break;
			case 'legacy': $record['legacy'][100] = false; break;
			case 'single': $record['single'][100] = 1; break;
			case 'batch': $record['batch'][100] = false; break;
			case 'force': $record['read_failures']['content']['forced_input']['force'] = false; break;
			case 'success': $record['read_failures']['content']['forced_response']['success'] = true; break;
			case 'helper': $record['read_failures']['content']['helper']['code'] = 'invalid_input'; break;
			case 'missing-injection': array_pop( $record['read_failures']['thumbnail']['injected'] ); break;
			case 'wrong-query': $record['read_failures']['content']['injected'] = $record['read_failures']['thumbnail']['injected']; break;
			case 'sql-leak': $record['read_failures']['content']['forced_response']['error']['message'] = 'wstm121_deliberate_missing_column'; break;
			case 'write': $record['sql'][] = 'DELETE FROM wp_posts'; break;
			case 'hook': $record['mutations'][] = 'deleted_post'; break;
			case 'state': $record['after'][] = 'changed'; break;
			case 'failure': $record['failure'] = 'probe failed'; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::reference_probe( self::state(), $record );
	}

	public static function cache_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'phase', 'input', 'injected', 'counter', 'generation', 'false-eof', 'sql-leak', 'retry-cached', 'retry-window', 'warm-sql', 'warm-error', 'warm-eof', 'state', 'failure' ) );
	}

	/** @dataProvider cache_mutations */
	public function test_cache_failure_retry_and_sql_free_stale_error_are_independent_requirements( string $mutation ): void {
		$record = self::cache();
		switch ( $mutation ) {
			case 'phase': unset( $record['phases']['priming'] ); break;
			case 'input': $record['input']['page'] = 2; break;
			case 'injected': $record['phases']['priming']['injected'] = 0; break;
			case 'counter': $record['phases']['candidate']['query_end'] = 11.0; break;
			case 'generation': $record['phases']['candidate']['generation_after'] = 'old'; break;
			case 'false-eof': $record['phases']['candidate']['failed_response']['success'] = true; break;
			case 'sql-leak': $record['phases']['priming']['failed_response']['error']['details'] = 'wstm121_deliberate_missing_column'; break;
			case 'retry-cached': $record['phases']['candidate']['retry']['query_end'] = 11; break;
			case 'retry-window': $record['phases']['candidate']['retry']['response']['data']['items'][0]['id'] = 199; break;
			case 'warm-sql': $record['phases']['candidate']['warm_stale']['query_end'] = 13; break;
			case 'warm-error': $record['phases']['candidate']['warm_stale']['last_error'] = ''; break;
			case 'warm-eof': $record['phases']['candidate']['warm_stale']['response']['data']['next_page'] = null; break;
			case 'state': $record['after'][] = 'changed'; break;
			case 'failure': $record['failure'] = 'query failed'; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::cache_probe( self::state(), $record );
	}

	public function test_seven_entries_cannot_reuse_one_directory_or_omit_external_custody(): void {
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::aggregate( array_fill_keys( array_keys( Wstm121Plan::WORKERS ), __DIR__ ) );
	}

	private static function projection(): array {
		$content = '<!-- wp:paragraph --><p>Stored "quoted" \\content.</p><!-- /wp:paragraph -->';
		$record = array( 'before' => array( 'same' ), 'after' => array( 'same' ), 'mutations' => array(), 'sql' => array(),
			'responses' => array(), 'stored_content' => array( 'revision' => $content ) );
		foreach ( array( 'get-post', 'get-page', 'get-cpt-w121-0123456789ab' ) as $name ) {
			$record['responses'][ $name ] = array( 'success' => true, 'data' => array(
				'title' => 'Stored', 'content' => $content, 'excerpt' => 'Controlled unchanged excerpt.',
				'slug' => 'stored', 'url' => 'https://example.test/', 'author_name' => '',
				'untrusted_fields' => array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ),
			) );
			$record['stored_content'][ $name ] = $content;
		}
		foreach ( array( 'default', 'summary', 'full' ) as $fields ) {
			$item = array( 'id' => 300, 'title' => '', 'author_name' => null, 'excerpt' => 'Controlled unchanged excerpt.',
				'untrusted_fields' => array( 'author_name', 'title', 'excerpt' ) );
			if ( 'full' === $fields ) {
				$item['content'] = $content;
				$item['untrusted_fields'][] = 'content';
			}
			$record['responses'][ 'revisions-' . $fields ] = array( 'success' => true,
				'data' => array( 'post_id' => 201, 'type' => 'post', 'revisions' => array( $item ) ) );
		}
		return $record;
	}

	public function test_getters_full_and_summary_revision_projection_are_independently_verified(): void {
		Wstm121Verifier::projection_probe( self::state(), self::projection() );
		self::addToAssertionCount( 1 );
	}

	public static function projection_mutations(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'missing-get', 'get-content', 'get-type', 'get-marker', 'summary-content', 'summary-marker',
			'full-content', 'full-type', 'revision-id', 'parent', 'type', 'excerpt', 'write', 'hook', 'state',
		) );
	}

	/** @dataProvider projection_mutations */
	public function test_projection_cannot_rewrite_values_or_mark_omitted_fields( string $mutation ): void {
		$record = self::projection();
		switch ( $mutation ) {
			case 'missing-get': unset( $record['responses']['get-page'] ); break;
			case 'get-content': $record['responses']['get-post']['data']['content'] = 'changed'; break;
			case 'get-type': $record['responses']['get-post']['data']['content'] = array(); break;
			case 'get-marker': array_pop( $record['responses']['get-post']['data']['untrusted_fields'] ); break;
			case 'summary-content': $record['responses']['revisions-default']['data']['revisions'][0]['content'] = ''; break;
			case 'summary-marker': $record['responses']['revisions-summary']['data']['revisions'][0]['untrusted_fields'][] = 'content'; break;
			case 'full-content': unset( $record['responses']['revisions-full']['data']['revisions'][0]['content'] ); break;
			case 'full-type': $record['responses']['revisions-full']['data']['revisions'][0]['content'] = false; break;
			case 'revision-id': $record['responses']['revisions-full']['data']['revisions'][0]['id'] = 301; break;
			case 'parent': $record['responses']['revisions-full']['data']['post_id'] = 200; break;
			case 'type': $record['responses']['revisions-full']['data']['type'] = 'page'; break;
			case 'excerpt': $record['responses']['revisions-summary']['data']['revisions'][0]['excerpt'] = ''; break;
			case 'write': $record['sql'][] = 'UPDATE wp_posts SET post_title = 1'; break;
			case 'hook': $record['mutations'][] = 'save_post'; break;
			case 'state': $record['after'][] = 'changed'; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::projection_probe( self::state(), $record );
	}
}
