<?php
/**
 * Actual HTTP transport shared by the disposable metadata regression runners.
 */

final class Wstm110_Metadata_Transport {
	private string $url;
	private array $credential;
	private string $session = '';
	private bool $individual;
	private array $tools = array();
	private int $request_id = 0;
	public array $last_response = array();
	public string $last_response_body = '';
	public array $last_events = array();

	public function catalog(): array {
		return $this->tools;
	}

	public function __construct( bool $individual, array $credential ) {
		$this->individual = $individual;
		$this->credential = $credential;
		$this->url = 'http://localhost/wp-json/' . ( $individual ? 'wstm118/tools' : 'mcp/mcp-adapter-default-server' );
	}

	public function initialize(): void {
		$this->rpc( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'metadata-boundary-qa', 'version' => '1' ) ) );
		if ( '' === $this->session ) {
			throw new RuntimeException( 'Metadata HTTP initialization returned no session.' );
		}
		$this->rpc( 'notifications/initialized', array() );
		$response = $this->rpc( 'tools/list', array() );
		$this->tools = $response['result']['tools'] ?? array();
		if ( ! $this->tools ) {
			throw new RuntimeException( 'Metadata HTTP tools/list returned no tools.' );
		}
	}

	public function tool_name( string $ability_name ): string {
		if ( ! $this->individual ) {
			$matches = array_filter( $this->tools, static fn( $tool ) => 'mcp-adapter-execute-ability' === $tool['name'] );
		} else {
			$ability = wp_get_ability( $ability_name );
			if ( ! $ability ) {
				throw new RuntimeException( 'Missing ability for tools/list calibration: ' . $ability_name );
			}
			// Discover the unique advertised descriptor, not an assumed name-sanitization rule.
			$matches = array_filter( $this->tools, static fn( $tool ) => trim( $ability->get_description() ) === ( $tool['description'] ?? null ) );
		}
		if ( 1 !== count( $matches ) ) {
			throw new RuntimeException( 'Cannot identify exactly one advertised HTTP tool for ' . $ability_name );
		}
		return array_values( $matches )[0]['name'];
	}

	public function execute( string $ability, array $input, string $observation_token = '' ): array {
		$this->last_response = array();
		$this->last_response_body = '';
		$this->last_events = array();
		$arguments = $this->individual ? (object) $input : array( 'ability_name' => $ability, 'parameters' => (object) $input );
		$response = $this->rpc( 'tools/call', array( 'name' => $this->tool_name( $ability ), 'arguments' => $arguments ), $observation_token );
		$this->last_response = $response;
		$tool = $response['result'] ?? null;
		if ( ! is_array( $tool ) ) {
			throw new RuntimeException( 'Metadata HTTP response has no tool result.' );
		}
		if ( true === ( $tool['isError'] ?? null ) ) {
			return wstm118_wire_error( $tool );
		}
		$result = $tool['structuredContent'] ?? null;
		if ( null === $result ) {
			if ( 1 !== count( $tool['content'] ?? array() ) || 'text' !== ( $tool['content'][0]['type'] ?? null ) ) {
				throw new RuntimeException( 'Metadata HTTP success lacks one structured/text payload.' );
			}
			$result = json_decode( $tool['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR );
		}
		if ( ! $this->individual && isset( $result['data']['success'] ) ) {
			$result = $result['data'];
		}
		if ( ! is_array( $result ) || true !== ( $result['success'] ?? null ) ) {
			throw new RuntimeException( 'Metadata HTTP failure was not signaled with isError:true.' );
		}
		return $result;
	}

	private function rpc( string $method, array $params, string $token = '' ): array {
		$this->last_response_body = '';
		$headers = array( 'Content-Type' => 'application/json', 'Authorization' => 'Basic ' . base64_encode( $this->credential['login'] . ':' . $this->credential['password'] ) );
		if ( '' !== $this->session ) {
			$headers['Mcp-Session-Id'] = $this->session;
		}
		if ( '' !== $token ) {
			$headers['X-WSTM110-Observation'] = $token;
		}
		$body = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params );
		if ( 'notifications/initialized' !== $method ) {
			$body['id'] = ++$this->request_id;
		}
		$response = wp_remote_post( $this->url, array( 'headers' => $headers, 'body' => wp_json_encode( $body ), 'timeout' => 45 ) );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Metadata HTTP request failed: ' . $response->get_error_message() );
		}
		$this->last_response_body = wp_remote_retrieve_body( $response );
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'Metadata HTTP status ' . $status );
		}
		$this->session = wp_remote_retrieve_header( $response, 'mcp-session-id' ) ?: $this->session;
		$this->last_events = array();
		if ( '' !== $token ) {
			$evidence = wp_remote_retrieve_header( $response, 'x-wstm110-mutation-events' );
			$decoded = is_string( $evidence ) ? base64_decode( $evidence, true ) : false;
			if ( false === $decoded || '' === $decoded ) {
				throw new RuntimeException( 'Metadata HTTP mutation observer did not return evidence.' );
			}
			$this->last_events = json_decode( $decoded, true, 512, JSON_THROW_ON_ERROR );
		}
		$text = $this->last_response_body;
		$payload = '' === $text ? array() : json_decode( $text, true, 512, JSON_THROW_ON_ERROR );
		if ( isset( $payload['error'] ) ) {
			throw new RuntimeException( 'Metadata HTTP JSON-RPC failure: ' . wp_json_encode( $payload['error'] ) );
		}
		return $payload;
	}

	public function close(): void {
		if ( '' === $this->session ) {
			return;
		}
		$response = wp_remote_request( $this->url, array(
			'method' => 'DELETE', 'timeout' => 30,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->credential['login'] . ':' . $this->credential['password'] ), 'Mcp-Session-Id' => $this->session ),
		) );
		if ( is_wp_error( $response ) || ! in_array( wp_remote_retrieve_response_code( $response ), array( 200, 202, 204 ), true ) ) {
			throw new RuntimeException( 'Metadata HTTP session cleanup failed.' );
		}
		$this->session = '';
	}
}
