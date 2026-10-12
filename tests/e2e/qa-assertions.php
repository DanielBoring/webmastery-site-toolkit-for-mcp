<?php

function webmastery_mcp_qa_term_state( array $expected, array $result ): bool {
	$id = $expected['id'] ?? $result['data']['id'] ?? null;
	if ( ! is_int( $id ) || $id < 1 || ! in_array( $expected['taxonomy'] ?? null, array( 'category', 'post_tag' ), true ) ) {
		return false;
	}
	$term = get_term( $id, $expected['taxonomy'] );
	if ( ! empty( $expected['deleted'] ) ) {
		return null === $term;
	}
	if ( ! $term instanceof WP_Term ) {
		return false;
	}
	foreach ( $expected as $field => $value ) {
		if ( 'id' === $field || 'deleted' === $field ) {
			continue;
		}
		if ( $term->$field !== $value ) {
			return false;
		}
	}
	return true;
}

function webmastery_mcp_qa_bucket_counts( array $result, string $ability ): bool {
	$health  = 'webmastery-site-toolkit-for-mcp/site-health-check' === $ability;
	if ( ! $health && 'webmastery-site-toolkit-for-mcp/security-audit' !== $ability ) {
		return false;
	}
	$keys    = $health ? array( 'critical', 'recommended', 'good' ) : array( 'fail', 'warn', 'pass' );
	$data    = $result['data'] ?? array();
	$summary = $data['summary'] ?? null;
	$buckets = $health ? ( $data['results'] ?? null ) : $data;
	if ( ! is_array( $summary ) || array_keys( $summary ) !== $keys || ! is_array( $buckets ) ) {
		return false;
	}
	$total = 0;
	foreach ( $keys as $key ) {
		$items = $buckets[ $key ] ?? null;
		if ( ! is_int( $summary[ $key ] ) || ! is_array( $items ) || array_values( $items ) !== $items
			|| count( $items ) !== $summary[ $key ] ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item[ $health ? 'test' : 'check' ] ?? null )
				|| ! is_string( $item['label'] ?? null ) ) {
				return false;
			}
		}
		$total += count( $items );
	}
	return $total > 0;
}
