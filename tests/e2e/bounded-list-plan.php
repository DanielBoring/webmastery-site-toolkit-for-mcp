<?php

declare(strict_types=1);

require_once __DIR__ . '/bounded-list-assertions.php';

final class Wstm121Plan {
	public const WORKERS = array( 'posts' => 1228, 'pages' => 46, 'cpt' => 46, 'media' => 610, 'seo' => 1210, 'readability' => 1210, 'orphans' => 610 );
	public const PHASE_SECONDS = array(
		'seed' => 1020, 'reference' => 300, 'projection' => 300, 'cache-faults' => 300,
		'snapshot-before' => 120, 'traversal' => 14400, 'snapshot-after' => 120,
		'cleanup' => 600, 'cleanup-readback' => 120,
	);
	public const NATIVE_SECONDS = 18000;
	public const JOB_SECONDS = 20700;
	public const WORKER_SECONDS = 60;
	public const JSON_BYTES = 33554432;
	public const STREAM_BYTES = 4194304;
	public const NAMESPACE_BYTES = 8589934592;

	public static function collections( string $cpt ): array {
		return array(
			'posts' => array( 'list-posts', 'post', true ),
			'pages' => array( 'list-pages', 'page', true ),
			'cpt' => array( 'list-cpt-' . str_replace( '_', '-', $cpt ), $cpt, true ),
			'media' => array( 'list-media', 'attachment', false ),
			'seo' => array( 'get-seo-scores', 'post', false ),
			'readability' => array( 'get-readability-scores', 'post', false ),
			'orphans' => array( 'list-orphaned-media', 'attachment', false ),
		);
	}

	public static function catalog( array $state ): void {
		wstm121_require( is_string( $state['cpt'] ?? null ) && 1 === preg_match( '/\\Aw121_[a-f0-9]{12}\\z/D', $state['cpt'] ), 'Invalid owned fixture namespace.' );
		foreach ( array( 'post' => 20000, 'attachment' => 10000, 'page' => 203, $state['cpt'] => 205 ) as $type => $count ) {
			$ids = $state['catalog'][ $type ] ?? null;
			wstm121_require( is_array( $ids ) && count( $ids ) === $count, 'Every shard requires the complete fixture: ' . $type );
			$previous = PHP_INT_MAX;
			foreach ( $ids as $id ) {
				wstm121_require( is_int( $id ) && $id > 0 && $id < $previous, 'Catalog must contain distinct descending positive integer IDs.' );
				$previous = $id;
			}
		}
	}

	public static function jobs( array $state, string $shard ): array {
		self::catalog( $state );
		$collections = self::collections( $state['cpt'] );
		wstm121_require( isset( $collections[ $shard ] ), 'Unknown collection shard.' );
		list( $ability, $type, $content ) = $collections[ $shard ];
		$score = in_array( $shard, array( 'seo', 'readability' ), true );
		$last = (int) ceil( count( $state['catalog'][ $type ] ) / 100 ) + 1;
		$jobs = array();
		$append = static function ( string $cache, string $mode, array $input, string $case, ?int $eof ) use ( &$jobs, $ability, $type, $content, $score, $shard ): void {
			if ( $score ) {
				$input['post_type'] = 'post';
			}
			$jobs[] = array(
				'coverage_key' => implode( ':', array( $shard, $cache, $mode, $case ) ),
				'sequence_key' => implode( ':', array( $shard, $cache, $mode, null === $eof ? $case : 'traversal' ) ),
				'kind' => null === $eof ? 'edge' : 'traversal', 'eof_page' => $eof,
				'ability' => $ability, 'type' => $type, 'cache' => $cache, 'mode' => $mode, 'input' => $input,
				'page' => $input['page'] ?? 1, 'per_page' => $input['per_page'] ?? ( $score ? 10 : 20 ),
				'orphan' => 'orphans' === $shard, 'controlled_default_summary' => array() === $input && $content,
			);
		};
		foreach ( array( 'cold', 'warm' ) as $cache ) {
			foreach ( array( 'dense', 'sparse', 'all-denied' ) as $mode ) {
				for ( $page = 1; $page <= $last; ++$page ) {
					$append( $cache, $mode, array( 'page' => $page, 'per_page' => 100 ), 'traversal:' . $page, $last );
				}
			}
		}
		$edges = array( 'one' => array( 'per_page' => 1 ), 'default' => array() );
		if ( $content ) {
			$edges['full'] = array( 'fields' => 'full', 'per_page' => 100 );
			foreach ( array( 'date', 'title', 'modified', 'id' ) as $sort ) {
				foreach ( array( 'ASC', 'DESC' ) as $order ) {
					$edges[ 'tie:' . $sort . ':' . $order ] = array( 'orderby' => $sort, 'order' => $order, 'page' => 2, 'per_page' => 100 );
				}
			}
		}
		foreach ( $edges as $name => $input ) {
			foreach ( array( 'cold', 'warm' ) as $cache ) {
				$append( $cache, 'dense', $input, 'edge:' . $name, null );
			}
		}
		wstm121_require( self::WORKERS[ $shard ] === count( $jobs ) && count( $jobs ) === count( array_unique( array_column( $jobs, 'coverage_key' ) ) ), 'Incomplete or duplicate shard plan.' );
		return $jobs;
	}

	public static function unsigned( $value, string $name ): int {
		wstm121_require( is_int( $value ) && $value >= 0, 'Expected a nonnegative integer: ' . $name );
		return $value;
	}

	public static function digest( $value ): string {
		wstm121_require( is_string( $value ) && 1 === preg_match( '/\\A[a-f0-9]{64}\\z/D', $value ), 'Invalid SHA-256 identity.' );
		return $value;
	}

	public static function original( string $path ): array {
		wstm121_require( is_file( $path ) && ! is_link( $path ), 'Missing or linked original record: ' . $path );
		$size = filesize( $path );
		wstm121_require( is_int( $size ) && $size <= self::JSON_BYTES, 'Original record exceeds its byte limit.' );
		$record = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		wstm121_require( is_array( $record ), 'Original record must be an object or array.' );
		return $record;
	}
}
