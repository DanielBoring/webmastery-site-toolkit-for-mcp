<?php

declare(strict_types=1);

require_once __DIR__ . '/bounded-list-assertions.php';

final class Wstm121Plan {
	public const JSON_BYTES = 33554432;

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

	public static function selected( array $state ): array {
		self::catalog( $state );
		$jobs = array();
		foreach ( self::collections( $state['cpt'] ) as $shard => list( $ability, $type, $content ) ) {
			$count = count( $state['catalog'][ $type ] );
			foreach ( array( 'cold', 'warm' ) as $cache ) {
				foreach ( array( 'dense', 'sparse', 'all-denied' ) as $mode ) {
					foreach ( array( 20, 100 ) as $p ) {
						$pages = array( 'first' => 1, 'intermediate' => (int) ceil( 100 / $p ) + 1,
							'final' => (int) ceil( $count / $p ), 'eof' => (int) ceil( $count / $p ) + 1 );
						foreach ( $pages as $label => $page ) {
							$input = array( 'page' => $page, 'per_page' => $p );
							if ( in_array( $shard, array( 'seo', 'readability' ), true ) ) {
								$input['post_type'] = 'post';
							}
							$jobs[] = array(
								'coverage_key' => implode( ':', array( $shard, $cache, $mode, $p, $label ) ),
								'ability' => $ability, 'type' => $type, 'cache' => $cache, 'mode' => $mode,
								'input' => $input, 'page' => $page, 'per_page' => $p, 'orphan' => 'orphans' === $shard,
								'controlled_default_summary' => $content && 20 === $p,
							);
						}
					}
				}
			}
		}
		wstm121_require( 336 === count( $jobs ) && 336 === count( array_unique( array_column( $jobs, 'coverage_key' ) ) ), 'Selected coverage is incomplete.' );
		return $jobs;
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
