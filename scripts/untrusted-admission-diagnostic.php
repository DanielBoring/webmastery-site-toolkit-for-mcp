<?php

declare(strict_types=1);

// This formats an existing witness; it never performs admission or reads evidence.
try {
	@require_once __DIR__ . '/untrusted-host-topology.php';
	$payload = getenv( 'WSTM108_ADMISSION_FAILURE' );
	if ( false === $payload || '' === $payload ) {
		echo "Untrusted admission diagnostic unavailable; QA outcome remains authoritative.\n";
	} else {
		$valid = strlen( $payload ) <= 256;
		$witness = $valid ? json_decode( $payload, false, 3, JSON_THROW_ON_ERROR ) : null;
		// Restrict lexical members as well as decoded keys (including duplicates).
		$valid = $valid && $witness instanceof stdClass
			&& 2 === count( get_object_vars( $witness ) )
			&& isset( $witness->phase, $witness->reason )
			&& is_string( $witness->phase ) && 'topology' === $witness->phase
			&& is_string( $witness->reason )
			&& in_array( $witness->reason, Wstm108_HostTopology::REFUSAL_REASONS, true )
			&& 1 === preg_match( '/\A\s*\{\s*"(phase|reason)"\s*:\s*"[a-z-]+"\s*,\s*"(phase|reason)"\s*:\s*"[a-z-]+"\s*\}\s*\z/D', $payload );
		if ( $valid ) {
			$site = getenv( 'WSTM108_ADMISSION_CALLSITE_V1' );
			$site = is_string( $site ) && strlen( $site ) <= Wstm108_AdmissionCallsite::MAX_SCALAR_BYTES
				&& Wstm108_AdmissionCallsite::allows( $witness->reason, $site ) ? $site : 'unknown';
			echo 'Untrusted admission refused: phase=topology reason=' . $witness->reason
				. ' callsite=' . $site . "; diagnostic formatting only, not admission success; QA outcome remains authoritative.\n";
		} else {
			echo "Untrusted admission diagnostic invalid; QA outcome remains authoritative.\n";
		}
	}
} catch ( Throwable $error ) {
	echo "Untrusted admission diagnostic invalid; QA outcome remains authoritative.\n";
}
