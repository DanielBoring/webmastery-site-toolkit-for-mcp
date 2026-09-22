<?php

declare(strict_types=1);

require_once __DIR__ . '/error-contract-assertions.php';

function wstm108_wire_canonical( $value ): string {
	$value = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
	$normalize = static function ( $item ) use ( &$normalize ) {
		if ( $item instanceof stdClass ) {
			$properties = get_object_vars( $item );
			ksort( $properties );
			foreach ( $properties as &$property ) {
				$property = $normalize( $property );
			}
			return (object) $properties;
		}
		if ( is_array( $item ) ) {
			return array_map( $normalize, $item );
		}
		return $item;
	};
	return serialize( $normalize( $value ) );
}

function wstm108_typed_tool( $tool, bool $gateway ): array {
	if ( ! $tool instanceof stdClass
		|| ( property_exists( $tool, 'isError' ) && ! is_bool( $tool->isError ) )
		|| ! is_array( $tool->content ?? null ) || array( 0 ) !== array_keys( $tool->content )
		|| ! $tool->content[0] instanceof stdClass || 'text' !== ( $tool->content[0]->type ?? null )
		|| ! is_string( $tool->content[0]->text ?? null ) ) {
		throw new RuntimeException( 'WSTM108 Tool result must retain its original object and one JSON text block.' );
	}
	if ( true === ( $tool->isError ?? null ) ) {
		$outer = get_object_vars( $tool );
		$outer['content'] = array( get_object_vars( $tool->content[0] ) );
		return wstm118_wire_error( $outer );
	}
	$text = json_decode( $tool->content[0]->text, false, 512, JSON_THROW_ON_ERROR );
	$result = $tool->structuredContent ?? $text;
	if ( wstm108_wire_canonical( $result ) !== wstm108_wire_canonical( $text ) ) {
		throw new RuntimeException( 'WSTM108 Structured and original text payload types or values differ.' );
	}
	// Only the documented gateway wrapper is unwrapped, never a stored subtree.
	if ( $gateway && $result instanceof stdClass && true === ( $result->success ?? null )
		&& ( $result->data ?? null ) instanceof stdClass && true === ( $result->data->success ?? null ) ) {
		$result = $result->data;
	}
	if ( ! $result instanceof stdClass || array( 'success', 'data' ) !== array_keys( get_object_vars( $result ) )
		|| true !== $result->success || ! $result->data instanceof stdClass ) {
		throw new RuntimeException( 'WSTM108 Successful payload must retain its canonical object envelope and object data.' );
	}
	return get_object_vars( $result );
}

function wstm108_compare_typed_record( $actual, array $expected, array $fields, string $kind ): void {
	if ( ! $actual instanceof stdClass ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' record is not an original JSON object.' );
	}
	$present = array_values( array_filter( $fields, static fn( $key ) => array_key_exists( $key, $expected ) ) );
	if ( ( $actual->untrusted_fields ?? null ) !== $present ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' marker differs from the exact present-field inventory.' );
	}
	$record = clone $actual;
	unset( $record->untrusted_fields );
	if ( wstm108_wire_canonical( $record ) !== wstm108_wire_canonical( (object) $expected ) ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' original object/list/scalar shape or stored value changed.' );
	}
}
