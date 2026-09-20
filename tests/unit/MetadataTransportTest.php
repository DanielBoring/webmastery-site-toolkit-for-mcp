<?php

declare(strict_types=1);

namespace Wstm110Transport;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__ ) . '/e2e/error-contract-assertions.php';

$source = file_get_contents( dirname( __DIR__ ) . '/e2e/metadata-transport.php' );
eval( 'namespace Wstm110Transport; use \RuntimeException; ' . substr( $source, 5 ) );

function wp_remote_post( $url, $args ) {
	$GLOBALS['wstm110_transport_requests'][] = array( $url, $args );
	if ( ! $GLOBALS['wstm110_transport_responses'] ) {
		throw new RuntimeException( 'Unexpected HTTP request.' );
	}
	return array_shift( $GLOBALS['wstm110_transport_responses'] );
}

function wp_remote_request( $url, $args ) {
	return wp_remote_post( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['status'];
}

function wp_remote_retrieve_header( $response, $name ) {
	return $response['headers'][ $name ] ?? '';
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_get_ability( $name ) {
	return 'fixture/read' === $name ? new class() {
		public function get_description(): string {
			return 'Fixture metadata reader.';
		}
	} : null;
}

final class MetadataTransportTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wstm110_transport_requests'] = array();
		$GLOBALS['wstm110_transport_responses'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wstm110_transport_requests'], $GLOBALS['wstm110_transport_responses'] );
	}

	private function response( array $payload, array $headers = array(), int $status = 200 ): array {
		return array( 'status' => $status, 'headers' => $headers, 'body' => json_encode( $payload, JSON_THROW_ON_ERROR ) );
	}

	private function initialize( bool $individual = false, ?array $tools = null ): Wstm110_Metadata_Transport {
		$tools ??= array( array( 'name' => $individual ? 'advertised-reader' : 'mcp-adapter-execute-ability', 'description' => 'Fixture metadata reader.' ) );
		$GLOBALS['wstm110_transport_responses'] = array(
			$this->response( array( 'result' => array() ), array( 'mcp-session-id' => 'fixture-session' ) ),
			array( 'status' => 202, 'headers' => array(), 'body' => '' ),
			$this->response( array( 'result' => array( 'tools' => $tools ) ) ),
		);
		$transport = new Wstm110_Metadata_Transport( $individual, array( 'login' => 'fixture', 'password' => 'synthetic-password' ) );
		$transport->initialize();
		return $transport;
	}

	public static function boundaries(): array {
		return array( 'gateway' => array( false ), 'individual' => array( true ) );
	}

	/** @dataProvider boundaries */
	public function test_advertised_tool_arguments_session_and_positive_observer_evidence( bool $individual ): void {
		$transport = $this->initialize( $individual );
		$success = array( 'success' => true, 'data' => array( 'id' => 17 ) );
		$events = array( array( 'hook' => 'save_post', 'id' => 17 ) );
		$payload = $individual ? $success : array( 'success' => true, 'data' => $success );
		$GLOBALS['wstm110_transport_responses'][] = $this->response(
			array( 'result' => array( 'structuredContent' => $payload ) ),
			array( 'x-wstm110-mutation-events' => base64_encode( json_encode( $events ) ) )
		);
		self::assertSame( $success, $transport->execute( 'fixture/read', array( 'post_id' => 17 ), 'observer-token' ) );
		self::assertSame( $events, $transport->last_events );
		[ $url, $request ] = $GLOBALS['wstm110_transport_requests'][3];
		self::assertSame( 'http://localhost/wp-json/' . ( $individual ? 'wstm118/tools' : 'mcp/mcp-adapter-default-server' ), $url );
		self::assertSame( 'fixture-session', $request['headers']['Mcp-Session-Id'] );
		self::assertSame( 'observer-token', $request['headers']['X-WSTM110-Observation'] );
		self::assertSame( 'Basic ' . base64_encode( 'fixture:synthetic-password' ), $request['headers']['Authorization'] );
		$body = json_decode( $request['body'], true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $individual ? 'advertised-reader' : 'mcp-adapter-execute-ability', $body['params']['name'] );
		self::assertSame( $individual ? array( 'post_id' => 17 ) : array( 'ability_name' => 'fixture/read', 'parameters' => array( 'post_id' => 17 ) ), $body['params']['arguments'] );
	}

	public function test_canonical_error_preserves_object_details_and_proves_zero_mutations(): void {
		$transport = $this->initialize();
		$error = \Webmastery_MCP_Response::error( 'invalid_input', 'Use separate metadata calls.', array(), 'metadata_requires_separate_call' );
		$GLOBALS['wstm110_transport_responses'][] = $this->response(
			array( 'result' => array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => json_encode( $error ) ) ) ) ),
			array( 'x-wstm110-mutation-events' => base64_encode( '[]' ) )
		);
		self::assertEquals( $error, $transport->execute( 'fixture/read', array(), 'observer-token' ) );
		self::assertSame( array(), $transport->last_events );
		self::assertTrue( $transport->last_response['result']['isError'] );
	}

	public static function missing_evidence(): array {
		return array( 'absent' => array( '' ), 'invalid base64' => array( '!' ), 'empty decoded' => array( base64_encode( '' ) ) );
	}

	/** @dataProvider missing_evidence */
	public function test_missing_observer_evidence_never_counts_as_zero_mutations( string $evidence ): void {
		$transport = $this->initialize();
		$GLOBALS['wstm110_transport_responses'][] = $this->response(
			array( 'result' => array( 'structuredContent' => array( 'success' => true ) ) ),
			array( 'x-wstm110-mutation-events' => $evidence )
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'observer did not return evidence' );
		$transport->execute( 'fixture/read', array(), 'observer-token' );
	}

	public function test_failure_without_wire_error_signal_fails_the_runner(): void {
		$transport = $this->initialize();
		$GLOBALS['wstm110_transport_responses'][] = $this->response( array( 'result' => array( 'structuredContent' => array( 'success' => false ) ) ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not signaled with isError:true' );
		$transport->execute( 'fixture/read', array() );
	}

	public function test_text_success_is_decoded_without_requiring_structured_content(): void {
		$transport = $this->initialize( true );
		$success = array( 'success' => true, 'data' => array( 'value' => 'fixture' ) );
		$GLOBALS['wstm110_transport_responses'][] = $this->response( array( 'result' => array( 'content' => array( array( 'type' => 'text', 'text' => json_encode( $success ) ) ) ) ) );
		self::assertSame( $success, $transport->execute( 'fixture/read', array() ) );
	}

	public function test_duplicate_descriptions_fail_discovery_instead_of_guessing(): void {
		$transport = $this->initialize( true, array(
			array( 'name' => 'first', 'description' => 'Fixture metadata reader.' ),
			array( 'name' => 'second', 'description' => 'Fixture metadata reader.' ),
		) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'exactly one advertised HTTP tool' );
		$transport->execute( 'fixture/read', array() );
	}

	public function test_failed_cleanup_retains_session_for_retry_and_success_is_idempotent(): void {
		$transport = $this->initialize();
		$GLOBALS['wstm110_transport_responses'][] = $this->response( array(), array(), 500 );
		try {
			$transport->close();
			self::fail( 'Failed cleanup must not disappear.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'Metadata HTTP session cleanup failed.', $error->getMessage() );
		}
		$GLOBALS['wstm110_transport_responses'][] = $this->response( array(), array(), 204 );
		$transport->close();
		$transport->close();
		self::assertCount( 5, $GLOBALS['wstm110_transport_requests'] );
		foreach ( array_slice( $GLOBALS['wstm110_transport_requests'], 3 ) as [ , $request ] ) {
			self::assertSame( 'DELETE', $request['method'] );
			self::assertSame( 'fixture-session', $request['headers']['Mcp-Session-Id'] );
		}
	}
}
