<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/destructive-safety-wire.php';

final class DestructiveWireTest extends TestCase {
	public static function boundaries(): array {
		return array( array( false, 'both' ), array( true, 'both' ), array( false, 'text' ), array( true, 'text' ), array( false, 'structured' ), array( true, 'structured' ) );
	}

	private function response( bool $individual, string $format, $details = null ): array {
		$success = array( 'success' => true, 'data' => array( 'failures' => array(
			array( 'id' => 10000001, 'code' => 'not_found', 'reason' => 'not_found', 'message' => 'Post not found.', 'details' => $details ?? (object) array() ),
		), 'success_count' => 0, 'dry_run' => true ) );
		$payload = $individual ? $success : array( 'success' => true, 'data' => $success );
		$tool = array( 'isError' => false );
		if ( 'text' !== $format ) {
			$tool['structuredContent'] = $payload;
		}
		if ( 'structured' !== $format ) {
			$tool['content'] = array( array( 'type' => 'text', 'text' => json_encode( $payload, JSON_THROW_ON_ERROR ) ) );
		}
		return array( json_encode( array( 'result' => $tool ), JSON_THROW_ON_ERROR ), json_decode( json_encode( $success ), true ) );
	}

	/** @dataProvider boundaries */
	public function test_empty_object_is_not_lost_by_associative_transport( bool $individual, string $format ): void {
		[ $body, $result ] = $this->response( $individual, $format );
		self::assertSame( array(), $result['data']['failures'][0]['details'] );
		$decoded = wstm116_bulk_wire_result( $body, $individual, $result );
		self::assertInstanceOf( stdClass::class, $decoded['data']['failures'][0]['details'] );
		self::assertSame( '{}', json_encode( $decoded['data']['failures'][0]['details'] ) );
	}

	/** @dataProvider boundaries */
	public function test_actual_wire_array_is_still_rejected( bool $individual, string $format ): void {
		[ $body, $result ] = $this->response( $individual, $format, array() );
		$this->expectExceptionMessage( 'Wire per-ID details are not an object.' );
		wstm116_bulk_wire_result( $body, $individual, $result );
	}

	public function test_disagreeing_text_cannot_hide_a_structured_array(): void {
		[ $body, $result ] = $this->response( true, 'both' );
		$wire = json_decode( $body );
		$wire->result->structuredContent->data->failures[0]->details = array();
		$this->expectExceptionMessage( 'Bulk structured and text payloads disagree.' );
		wstm116_bulk_wire_result( json_encode( $wire ), true, $result );
	}

	public function test_structured_object_cannot_hide_a_text_array(): void {
		[ $body, $result ] = $this->response( true, 'both' );
		$wire = json_decode( $body );
		$text = json_decode( $wire->result->content[0]->text );
		$text->data->failures[0]->details = array();
		$wire->result->content[0]->text = json_encode( $text );
		$this->expectExceptionMessage( 'Bulk structured and text payloads disagree.' );
		wstm116_bulk_wire_result( json_encode( $wire ), true, $result );
	}

	public function test_text_and_structured_scalar_types_cannot_disagree(): void {
		[ $body, $result ] = $this->response( true, 'both' );
		$wire = json_decode( $body );
		$text = json_decode( $wire->result->content[0]->text );
		$text->data->failures[0]->id = '10000001';
		$wire->result->content[0]->text = json_encode( $text );
		$this->expectExceptionMessage( 'Bulk structured and text payloads disagree.' );
		wstm116_bulk_wire_result( json_encode( $wire ), true, $result );
	}

	public function test_transport_and_raw_response_must_describe_the_same_result(): void {
		[ $body, $result ] = $this->response( false, 'both' );
		$result['data']['failures'][0]['id']++;
		$this->expectExceptionMessage( 'Bulk wire payload differs from the transport result.' );
		wstm116_bulk_wire_result( $body, false, $result );
	}

	public function test_runner_captures_state_and_hooks_before_wire_shape_assertion(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/destructive-safety-runner.php' );
		self::assertLessThan( strpos( $source, '$result = wstm116_bulk_wire_result(' ), strpos( $source, "\$entry['after'] = wstm116_snapshot(" ) );
		self::assertLessThan( strpos( $source, '$result = wstm116_bulk_wire_result(' ), strpos( $source, "\$entry['hooks'] = \$events;" ) );
		self::assertStringNotContainsString( "wp_json_encode( \$wire['structuredContent'] )", $source );
	}
}
