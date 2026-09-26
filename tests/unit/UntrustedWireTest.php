<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-wire.php';

final class UntrustedWireTest extends TestCase {
	private static function wire( object $payload, bool $structured = true ): object {
		$text = json_encode( $payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
		$tool = (object) array( 'content' => array( (object) array( 'type' => 'text', 'text' => $text ) ) );
		if ( $structured ) {
			$tool->structuredContent = json_decode( $text, false, 512, JSON_THROW_ON_ERROR );
		}
		return $tool;
	}

	public function test_original_typed_success_and_nested_user_owned_markers_survive_both_boundaries(): void {
		$record = json_decode( '{"meta":{"untrusted_fields":"literal","error":{"details":{}},"list":[],"integer":1,"float":1.0},"untrusted_fields":["meta"]}', false, 512, JSON_THROW_ON_ERROR );
		$expected = array( 'meta' => clone $record->meta );
		$payload = (object) array( 'success' => true, 'data' => $record );
		$before = serialize( $record );
		foreach ( array( false, true ) as $gateway ) {
			$envelope = $gateway ? (object) array( 'success' => true, 'data' => $payload ) : $payload;
			foreach ( array( false, true ) as $structured ) {
				$data = wstm108_typed_tool( self::wire( $envelope, $structured ), $gateway )['data'];
				self::assertInstanceOf( stdClass::class, $data->meta->error->details );
				self::assertSame( array(), $data->meta->list );
				self::assertSame( 1, $data->meta->integer );
				self::assertSame( 1.0, $data->meta->float );
				wstm108_compare_typed_record( $data, $expected, array( 'meta' ), 'meta' );
			}
		}
		self::assertSame( $before, serialize( $record ) );
	}

	public static function changes(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'object-to-list', 'list-to-object', 'integer-to-float', 'float-to-integer',
			'number-to-string', 'nested-marker', 'missing-marker', 'extra-marker',
			'root-list', 'text-object-list-drift', 'structured-object-list-drift',
		) );
	}

	/** @dataProvider changes */
	public function test_typed_comparison_rejects_type_loss_and_only_strips_the_owned_record_marker( string $change ): void {
		$actual = json_decode( '{"meta":{"object":{},"list":[],"integer":1,"float":1.0,"untrusted_fields":"literal"},"untrusted_fields":["meta"]}', false, 512, JSON_THROW_ON_ERROR );
		$expected = array( 'meta' => clone $actual->meta );
		if ( 'object-to-list' === $change ) { $actual->meta->object = array(); }
		if ( 'list-to-object' === $change ) { $actual->meta->list = new stdClass(); }
		if ( 'integer-to-float' === $change ) { $actual->meta->integer = 1.0; }
		if ( 'float-to-integer' === $change ) { $actual->meta->float = 1; }
		if ( 'number-to-string' === $change ) { $actual->meta->integer = '1'; }
		if ( 'nested-marker' === $change ) { unset( $actual->meta->untrusted_fields ); }
		if ( 'missing-marker' === $change ) { unset( $actual->untrusted_fields ); }
		if ( 'extra-marker' === $change ) { $actual->untrusted_fields[] = 'absent'; }
		if ( 'root-list' === $change ) { $actual = array(); }
		$this->expectException( RuntimeException::class );
		if ( in_array( $change, array( 'text-object-list-drift', 'structured-object-list-drift' ), true ) ) {
			$tool = self::wire( (object) array( 'success' => true, 'data' => $actual ) );
			if ( 'text-object-list-drift' === $change ) {
				$tool->content[0]->text = str_replace( '"object":{}', '"object":[]', $tool->content[0]->text );
			} else {
				$tool->structuredContent->data->meta->object = array();
			}
			wstm108_typed_tool( $tool, false );
			return;
		}
		wstm108_compare_typed_record( $actual, $expected, array( 'meta' ), 'meta' );
	}

	public function test_canonical_errors_remain_unchanged_with_omitted_or_null_structured_content(): void {
		$error = json_decode( '{"success":false,"error":{"code":"forbidden","reason":"forbidden","message":"Denied.","details":{}}}', false, 512, JSON_THROW_ON_ERROR );
		$wire = self::wire( $error, false );
		$wire->isError = true;
		foreach ( array( false, true ) as $with_null ) {
			if ( $with_null ) {
				$wire->structuredContent = null;
			}
			$parsed = wstm108_typed_tool( $wire, false );
			self::assertSame( false, $parsed['success'] );
			self::assertInstanceOf( stdClass::class, $parsed['error']['details'] );
			self::assertSame( array( 'success', 'error' ), array_keys( $parsed ) );
		}
		$wire->structuredContent = new stdClass();
		$this->expectException( RuntimeException::class );
		wstm108_typed_tool( $wire, false );
	}
}
