<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/e2e/bounded-list-verifier.php';

/** Metadata mutation models only; no process launch or runtime acceptance. */
final class Wstm121ProductRecordModel {
	public const SURFACES = array( 'posts', 'pages', 'cpt', 'media', 'seo', 'readability', 'orphans' );

	public static function receipt( array $receipt, int $seconds ): void {
		$start = Wstm121Plan::unsigned( $receipt['start_ns'] ?? null, 'process start' );
		$end = Wstm121Plan::unsigned( $receipt['end_ns'] ?? null, 'process end' );
		wstm121_require( $end >= $start && $end - $start <= $seconds * 1000000000, 'Process deadline exceeded or clock invalid.' );
		wstm121_require( 0 === ( $receipt['exit_code'] ?? null ) && true === ( $receipt['exit_observed'] ?? null )
			&& true === ( $receipt['stdout_eof'] ?? null ) && true === ( $receipt['stderr_eof'] ?? null )
			&& false === ( $receipt['truncated'] ?? null ) && array_key_exists( 'failure', $receipt ) && null === $receipt['failure'],
			'Incomplete or failed original process receipt.' );
		wstm121_require( array() === ( $receipt['secondary_errors'] ?? null ), 'Process cleanup/flush errors invalidate the capture.' );
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$bytes = Wstm121Plan::unsigned( $receipt[ $stream . '_bytes' ] ?? null, $stream . ' bytes' );
			wstm121_require( $bytes <= 4194304, 'Modeled process capture exceeds its declared byte limit.' );
			wstm121_require( $bytes === ( $receipt[ $stream . '_received' ] ?? null )
				&& 0 === ( $receipt[ $stream . '_diagnostic_bytes' ] ?? null )
				&& 0 === ( $receipt[ $stream . '_observed_not_retained' ] ?? null ), 'Process capture discarded or hid original bytes.' );
			Wstm121Plan::digest( $receipt[ $stream . '_sha256' ] ?? null );
		}
	}

	public static function directories( array $directories ): array {
		wstm121_require( array_keys( $directories ) === self::SURFACES, 'Missing, duplicate or reordered surfaces.' );
		$seen = array();
		foreach ( $directories as $name => $directory ) {
			wstm121_require( is_string( $name ) && is_string( $directory ) && is_dir( $directory ) && ! is_link( $directory ),
				'Invalid product record directory mapping.' );
			$canonical = realpath( $directory );
			wstm121_require( false !== $canonical && ! isset( $seen[ $canonical ] ), 'Product record directories must be distinct.' );
			$seen[ $canonical ] = true;
		}
		return $directories;
	}
}
