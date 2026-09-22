<?php

declare(strict_types=1);

require_once __DIR__ . '/destructive-safety-boot.php';
require_once __DIR__ . '/untrusted-content-evidence.php';

function wstm108_converge( callable $request, Wstm108_Evidence $evidence, string $phase, array $identity, array $expected, array $stale, callable $sleep, callable $clock ): array {
	return wstm116_converge_boot(
		static function ( float $timeout ) use ( $request, $evidence, $phase ): array {
			try {
				$response = $request( $timeout );
			} catch ( Throwable $error ) {
				$evidence->append( array( 'phase' => $phase, 'boundary' => 'owned-get-probe', 'status' => 0, 'body' => '', 'transport_error' => $error->getMessage() ) );
				throw $error;
			}
			$evidence->append( array( 'phase' => $phase, 'boundary' => 'owned-get-probe' ) + $response );
			return $response;
		},
		static function ( array $attempt ) use ( $evidence, $phase ): void {
			$evidence->append( array( 'phase' => $phase, 'convergence' => $attempt ) );
		},
		$identity, $expected, $stale, $sleep, $clock
	);
}
