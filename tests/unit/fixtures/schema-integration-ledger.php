<?php

declare(strict_types=1);

function wstm126_case_hash( $value ): string {
	return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
}

function wstm126_parent_manifest( array $manifest ): array {
	$ledger = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/e2e/input-schema-integration-ledger.json' ), false, 512, JSON_THROW_ON_ERROR );
	if ( count( $manifest ) !== 589 || count( $ledger->rows ) !== 589 || count( $ledger->changes ) !== 52 || count( $ledger->additions ) !== 19 ) {
		throw new RuntimeException( 'Unexpected integration ledger or manifest count.' );
	}
	$changes = array_column( $ledger->changes, null, 'index' );
	$additions = array_column( $ledger->additions, null, 'index' );
	$parent = array();
	foreach ( $ledger->rows as $index => $row ) {
		$case = $manifest[ $index ];
		if ( $case->label !== $row->label || wstm126_case_hash( $case ) !== $row->after_sha256 ) {
			throw new RuntimeException( 'Unexpected full typed case or position: ' . $row->label );
		}
		if ( null === $row->source_index ) {
			if ( ! isset( $additions[ $index ] ) || wstm126_case_hash( $additions[ $index ]->case ) !== $row->after_sha256 ) {
				throw new RuntimeException( 'Unapproved additional case.' );
			}
			continue;
		}
		if ( $row->source_index !== count( $parent ) ) {
			throw new RuntimeException( 'Parent case order changed.' );
		}
		if ( isset( $changes[ $index ] ) ) {
			$change = $changes[ $index ];
			if ( $change->source_index !== $row->source_index || wstm126_case_hash( $change->after ) !== $row->after_sha256
				|| wstm126_case_hash( $change->before ) !== $change->before_sha256 ) {
				throw new RuntimeException( 'Invalid exact before/after case provenance.' );
			}
			$case = $change->before;
		}
		$parent[] = $case;
	}
	if ( count( $parent ) !== 570 || wstm126_case_hash( $parent ) !== 'da4395a6a9d6532c10423e25d02c710c2ed150d226dfa87a988b5594ede8fa4a' ) {
		throw new RuntimeException( 'Projection does not reconstruct exact frozen f93 parent.' );
	}
	return $parent;
}
