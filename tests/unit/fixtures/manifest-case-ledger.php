<?php
/**
 * Reviewed additions and assertion changes made to tests/e2e/abilities-manifest.json
 * after the historical 601-case manifest was sealed.
 *
 * Historical tooling tests reconstruct older manifest views from the sealed 601 cases.
 * sealed() reverses every recorded step, so those tests keep checking the exact sealed
 * view while new cases are added. Any manifest change that is not recorded fails.
 *
 * Record a manifest change (run from the repository root, after editing the manifest):
 *   php tests/unit/fixtures/manifest-case-ledger.php record <issue> "<summary>" [<git-ref>]
 * <git-ref> (default HEAD) holds the manifest before the change. Verify with:
 *   php tests/unit/fixtures/manifest-case-ledger.php check
 */

declare(strict_types=1);

final class ManifestCaseLedger {
	public const SEALED_COUNT = 601;
	public const SEALED_SHA256 = 'b9766cc3faf1144b7987913df8dfaaa68bcf3637c63dfbdcc36e09d1ac09b754';

	public static function root(): string {
		return dirname( __DIR__, 3 );
	}

	public static function fingerprint( $value ): string {
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public static function decode( string $json ): array {
		$manifest = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $manifest ) || array_values( $manifest ) !== $manifest ) {
			throw new RuntimeException( 'Manifest root must be a list.' );
		}
		return $manifest;
	}

	public static function live(): array {
		return self::decode( (string) file_get_contents( self::root() . '/tests/e2e/abilities-manifest.json' ) );
	}

	public static function ledger(): object {
		$ledger = json_decode( (string) file_get_contents( __DIR__ . '/manifest-case-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
		if ( 1 !== ( $ledger->schema ?? null ) || ! is_array( $ledger->steps ?? null ) ) {
			throw new RuntimeException( 'Unknown manifest case ledger schema.' );
		}
		if ( self::SEALED_COUNT !== $ledger->sealed_count || self::SEALED_SHA256 !== $ledger->sealed_sha256 ) {
			throw new RuntimeException( 'Manifest case ledger must start from the sealed 601-case manifest.' );
		}
		return $ledger;
	}

	/** Reverse one recorded step; refuses any case, order or count difference from the record. */
	public static function reverse_step( array $manifest, object $step ): array {
		if ( count( $manifest ) !== $step->after_count || self::fingerprint( $manifest ) !== $step->after_sha256 ) {
			throw new RuntimeException( "Manifest differs from recorded step {$step->issue}; record the change in the manifest case ledger." );
		}
		$labels = array_flip( array_column( $manifest, 'label' ) );
		foreach ( $step->changed as $change ) {
			$index = $labels[ $change->label ] ?? null;
			if ( null === $index || self::fingerprint( $manifest[ $index ] ) !== self::fingerprint( $change->after )
				|| $change->label !== $change->before->label ) {
				throw new RuntimeException( "Changed case drift: {$change->label}" );
			}
			$manifest[ $index ] = $change->before;
		}
		$indexes = array_column( $step->added, 'index' );
		rsort( $indexes );
		$added = array_column( $step->added, null, 'index' );
		if ( count( $added ) !== count( $step->added ) ) {
			throw new RuntimeException( 'Duplicate added case index.' );
		}
		foreach ( $indexes as $index ) {
			if ( ! isset( $manifest[ $index ] ) || self::fingerprint( $manifest[ $index ] ) !== self::fingerprint( $added[ $index ]->case ) ) {
				throw new RuntimeException( "Added case drift at index {$index}." );
			}
			array_splice( $manifest, $index, 1 );
		}
		if ( count( $manifest ) !== $step->before_count || self::fingerprint( $manifest ) !== $step->before_sha256 ) {
			throw new RuntimeException( "Step {$step->issue} does not reconstruct its recorded predecessor." );
		}
		return $manifest;
	}

	/** The exact sealed 601-case manifest that the historical projections were built from. */
	public static function sealed( ?array $manifest = null, ?object $ledger = null ): array {
		$manifest = $manifest ?? self::live();
		$ledger = $ledger ?? self::ledger();
		foreach ( array_reverse( $ledger->steps ) as $step ) {
			$manifest = self::reverse_step( $manifest, $step );
		}
		if ( self::SEALED_COUNT !== count( $manifest ) || self::SEALED_SHA256 !== self::fingerprint( $manifest ) ) {
			throw new RuntimeException( 'Manifest does not reduce to the sealed 601 cases; record the change: php tests/unit/fixtures/manifest-case-ledger.php record <issue> "<summary>"' );
		}
		return $manifest;
	}

	/** Build a step from the manifest at $before_json to the live manifest; refuses removals and reordering. */
	public static function build_step( int $issue, string $summary, array $before, array $after ): object {
		$before_labels = array_column( $before, 'label' );
		$after_labels = array_column( $after, 'label' );
		if ( count( $after_labels ) !== count( array_unique( $after_labels ) ) ) {
			throw new RuntimeException( 'Duplicate manifest label.' );
		}
		if ( array_diff( $before_labels, $after_labels ) ) {
			throw new RuntimeException( 'Removing or renaming cases is not supported by the ledger.' );
		}
		if ( array_values( array_intersect( $after_labels, $before_labels ) ) !== $before_labels ) {
			throw new RuntimeException( 'Reordering existing cases is not supported by the ledger.' );
		}
		$old = array_column( $before, null, 'label' );
		$step = (object) array(
			'issue' => $issue,
			'summary' => $summary,
			'before_count' => count( $before ),
			'before_sha256' => self::fingerprint( $before ),
			'after_count' => count( $after ),
			'after_sha256' => self::fingerprint( $after ),
			'added' => array(),
			'changed' => array(),
		);
		foreach ( $after as $index => $case ) {
			if ( ! isset( $old[ $case->label ] ) ) {
				$step->added[] = (object) array( 'index' => $index, 'case' => $case );
			} elseif ( self::fingerprint( $old[ $case->label ] ) !== self::fingerprint( $case ) ) {
				$step->changed[] = (object) array( 'label' => $case->label, 'before' => $old[ $case->label ], 'after' => $case );
			}
		}
		if ( ! $step->added && ! $step->changed ) {
			throw new RuntimeException( 'No manifest change to record.' );
		}
		if ( self::fingerprint( self::reverse_step( $after, $step ) ) !== $step->before_sha256 ) {
			throw new RuntimeException( 'Recorded step does not reverse exactly.' );
		}
		return $step;
	}

	public static function write( object $ledger ): void {
		$json = json_encode( $ledger, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		file_put_contents( __DIR__ . '/manifest-case-ledger.json', $json . "\n" );
	}

	public static function cli( array $argv ): int {
		$command = $argv[1] ?? '';
		if ( 'check' === $command ) {
			$live = self::live();
			self::sealed( $live );
			printf( "PASS manifest case ledger: %d live cases reduce to the sealed %d cases.\n", count( $live ), self::SEALED_COUNT );
			return 0;
		}
		if ( 'record' === $command && isset( $argv[2], $argv[3] ) && ctype_digit( $argv[2] ) ) {
			$ref = $argv[4] ?? 'HEAD';
			if ( 1 !== preg_match( '/^[A-Za-z0-9._\/~^-]+$/D', $ref ) ) {
				throw new RuntimeException( 'Invalid git ref.' );
			}
			$before = shell_exec( 'git -C ' . escapeshellarg( self::root() ) . ' show ' . escapeshellarg( $ref . ':tests/e2e/abilities-manifest.json' ) );
			if ( ! is_string( $before ) || '' === $before ) {
				throw new RuntimeException( 'Cannot read the manifest at ' . $ref );
			}
			$ledger = self::ledger();
			$before = self::decode( $before );
			$last = end( $ledger->steps );
			if ( false !== $last && self::fingerprint( $before ) === $last->before_sha256 ) {
				// Re-recording an uncommitted step for the same base replaces it.
				array_pop( $ledger->steps );
			}
			self::sealed( $before, $ledger );
			$ledger->steps[] = self::build_step( (int) $argv[2], $argv[3], $before, self::live() );
			self::write( $ledger );
			self::sealed();
			$step = end( $ledger->steps );
			printf( "Recorded step %d: %d -> %d cases. Set the case count in tests/e2e/product-evidence-verifier.php to %d.\n", $step->issue, $step->before_count, $step->after_count, $step->after_count );
			return 0;
		}
		fwrite( STDERR, "Usage: php tests/unit/fixtures/manifest-case-ledger.php check\n       php tests/unit/fixtures/manifest-case-ledger.php record <issue> \"<summary>\" [<git-ref>]\n" );
		return 2;
	}
}

if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	exit( ManifestCaseLedger::cli( $argv ) );
}
