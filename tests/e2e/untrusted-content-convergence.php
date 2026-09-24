<?php

declare(strict_types=1);

require_once __DIR__ . '/destructive-safety-boot.php';
require_once __DIR__ . '/untrusted-content-evidence.php';

function wstm108_converge( callable $request, Wstm108_Evidence $evidence, string $phase, array $identity, array $expected, array $stale, callable $sleep, callable $clock ): array {
	return wstm116_converge_boot(
		static function ( float $timeout ) use ( $request, $evidence, $phase, $identity, $expected, $stale ): array {
			try {
				$response = $request( $timeout );
			} catch ( Throwable $error ) {
				$evidence->append( array( 'phase' => $phase, 'boundary' => 'owned-get-probe', 'status' => 0, 'body' => '', 'transport_error' => $error->getMessage() ) );
				throw $error;
			}
			$id = $evidence->append( array( 'phase' => $phase, 'boundary' => 'owned-get-probe' ) + $response );
			if ( 200 === ( $response['status'] ?? null ) && is_string( $response['body'] ?? null ) ) {
				$body = json_decode( $response['body'], true, 512, JSON_THROW_ON_ERROR );
				if ( is_array( $body ) ) {
					wstm116_validate_boot( $body, $identity );
					if ( $body['runtime'] === $expected || in_array( $body['runtime'], $stale, true ) ) {
						$evidence->validate( $id, $body['runtime'] === $expected ? 'owned-current-get' : 'owned-stale-get' );
					}
				}
			}
			return $response;
		},
		static function ( array $attempt ) use ( $evidence, $phase ): void {
			$id = $evidence->append( array( 'phase' => $phase, 'convergence' => $attempt ) );
			$evidence->validate( $id, 'convergence' );
		},
		$identity, $expected, $stale, $sleep, $clock
	);
}
