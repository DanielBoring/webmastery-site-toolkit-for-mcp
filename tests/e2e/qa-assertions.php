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
	if ( ! is_array( $summary ) || ! is_array( $buckets ) ) {
		return false;
	}
	if ( $health && array_key_exists( 'errors', $summary ) ) {
		// Site health also reports tests that could not run and tests left out by its cap.
		$errors = $data['errors'] ?? null;
		if ( array_keys( $summary ) !== array_merge( $keys, array( 'errors', 'skipped' ) ) || ! is_int( $summary['skipped'] )
			|| $summary['skipped'] < 0 || ! is_int( $summary['errors'] ) || ! is_array( $errors )
			|| array_values( $errors ) !== $errors || count( $errors ) !== $summary['errors'] ) {
			return false;
		}
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || array( 'test' ) !== array_keys( $error ) || ! is_string( $error['test'] ) ) {
				return false;
			}
		}
		$summary = array_intersect_key( $summary, array_flip( $keys ) );
	}
	if ( array_keys( $summary ) !== $keys ) {
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
