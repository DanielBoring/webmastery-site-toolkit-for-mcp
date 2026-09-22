<?php
/**
 * Assert wire types before associative decoding can erase empty JSON objects.
 */

declare(strict_types=1);

function wstm116_bulk_wire_result( string $body, bool $individual, array $result ): array {
	$wire = json_decode( $body, false, 512, JSON_THROW_ON_ERROR );
	$tool = $wire->result ?? null;
	if ( ! $tool instanceof stdClass || true === ( $tool->isError ?? null ) ) {
		throw new RuntimeException( 'Missing successful bulk tool result.' );
	}
	$text = null;
	if ( isset( $tool->content ) ) {
		if ( ! is_array( $tool->content ) || 1 !== count( $tool->content ) || 'text' !== ( $tool->content[0]->type ?? null ) || ! is_string( $tool->content[0]->text ?? null ) ) {
			throw new RuntimeException( 'Bulk wire content must contain exactly one text payload.' );
		}
		$text = json_decode( $tool->content[0]->text, false, 512, JSON_THROW_ON_ERROR );
	}
	$payload = $tool->structuredContent ?? $text;
	if ( null !== $text && null !== ( $tool->structuredContent ?? null ) && serialize( $text ) !== serialize( $tool->structuredContent ) ) {
		throw new RuntimeException( 'Bulk structured and text payloads disagree.' );
	}
	if ( ! $individual ) {
		$payload = $payload->data ?? null;
	}
	if ( ! $payload instanceof stdClass || json_decode( json_encode( $payload, JSON_THROW_ON_ERROR ), true ) !== $result ) {
		throw new RuntimeException( 'Bulk wire payload differs from the transport result.' );
	}
	foreach ( $result['data']['failures'] as $index => &$failure ) {
		$details = $payload->data->failures[ $index ]->details ?? null;
		if ( ! $details instanceof stdClass ) {
			throw new RuntimeException( 'Wire per-ID details are not an object.' );
		}
		$failure['details'] = $details;
	}
	unset( $failure );
	return $result;
}
