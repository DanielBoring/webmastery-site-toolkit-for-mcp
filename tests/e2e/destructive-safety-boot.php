<?php

declare(strict_types=1);

function wstm116_runtime_configuration(): array {
	return array(
		'trash_days' => EMPTY_TRASH_DAYS,
		'disposable_defined' => defined( 'WSTM116_DISPOSABLE_RUNTIME' ),
		'disposable' => defined( 'WSTM116_DISPOSABLE_RUNTIME' ) ? WSTM116_DISPOSABLE_RUNTIME : null,
		'stage_defined' => defined( 'WSTM116_STAGE_TOKEN' ),
		'stage_owner' => defined( 'WSTM116_STAGE_TOKEN' ) ? WSTM116_STAGE_TOKEN : null,
	);
}

function wstm116_stage_configuration( string $owner, int $days ): array {
	return array( 'trash_days' => $days, 'disposable_defined' => true, 'disposable' => true, 'stage_defined' => true, 'stage_owner' => $owner );
}

function wstm116_validate_boot( array $body, array $identity ): void {
	if ( array_keys( $body ) !== array( 'identity', 'runtime', 'php', 'sapi' )
		|| ! is_array( $body['identity'] ) || ! is_array( $body['runtime'] )
		|| ! is_string( $body['php'] ) || 'apache2handler' !== $body['sapi']
		|| ! is_int( $body['identity']['uid'] ?? null ) || $body['identity']['uid'] < 0 ) {
		throw new RuntimeException( 'Malformed HTTP boot attestation.' );
	}
	foreach ( $identity as $key => $value ) {
		if ( ! array_key_exists( $key, $body['identity'] ) || $body['identity'][ $key ] !== $value ) {
			throw new RuntimeException( 'Foreign HTTP boot identity or configuration digest: ' . $key );
		}
	}
	if ( array_keys( $body['identity'] ) !== array( 'owner', 'root', 'plugin_root', 'config_sha256', 'uid' ) ) {
		throw new RuntimeException( 'Unexpected HTTP boot identity fields.' );
	}
}

function wstm116_converge_boot( callable $request, callable $journal, array $identity, array $expected, array $stale, callable $sleep, callable $clock ): array {
	$deadline = $clock() + 14.0;
	for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
		$remaining = $deadline - $clock();
		if ( $remaining <= 0 ) {
			throw new RuntimeException( 'HTTP boot convergence exceeded its finite budget.' );
		}
		try {
			$response = $request( min( 2.0, $remaining ) );
		} catch ( Throwable $error ) {
			$journal( array( 'attempt' => $attempt, 'status' => null, 'transport_error' => get_class( $error ) ) );
			throw $error;
		}
		// Keep only the public probe's fixed whitelist, never arbitrary response headers or HTML.
		$body = json_decode( $response['body'] ?? '', true );
		$record = array( 'attempt' => $attempt, 'status' => $response['status'] ?? null,
			'body_sha256' => hash( 'sha256', $response['body'] ?? '' ) );
		if ( is_array( $body ) ) {
			$record['attestation'] = array(
				'identity' => array_intersect_key( is_array( $body['identity'] ?? null ) ? $body['identity'] : array(), array_flip( array( 'owner', 'root', 'plugin_root', 'config_sha256', 'uid' ) ) ),
				'runtime' => array_intersect_key( is_array( $body['runtime'] ?? null ) ? $body['runtime'] : array(), array_flip( array( 'trash_days', 'disposable_defined', 'disposable', 'stage_defined', 'stage_owner' ) ) ),
				'php' => $body['php'] ?? null, 'sapi' => $body['sapi'] ?? null,
			);
		}
		$journal( $record );
		if ( 200 !== ( $response['status'] ?? null ) || ! is_array( $body ) ) {
			throw new RuntimeException( 'HTTP boot probe denied, unavailable or malformed; not retrying.' );
		}
		wstm116_validate_boot( $body, $identity );
		if ( $clock() > $deadline ) {
			throw new RuntimeException( 'HTTP boot convergence exceeded its finite budget.' );
		}
		if ( $body['runtime'] === $expected ) {
			return $body;
		}
		if ( ! in_array( $body['runtime'], $stale, true ) ) {
			throw new RuntimeException( 'HTTP boot has an unrecognized configuration or opt-in denial.' );
		}
		if ( 5 === $attempt || $deadline - $clock() <= 1.0 ) {
			throw new RuntimeException( 'HTTP boot remained stale after bounded convergence.' );
		}
		$sleep( 1 );
	}
	throw new RuntimeException( 'HTTP boot convergence did not complete.' );
}
