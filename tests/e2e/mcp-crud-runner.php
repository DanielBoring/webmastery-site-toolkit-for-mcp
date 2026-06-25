<?php

declare(strict_types=1);

final class Webmastery_MCP_E2E_Failure extends RuntimeException {}

final class Webmastery_MCP_E2E_Client {
	private string $endpoint;
	private string $username;
	private string $password;
	private ?string $session_id = null;
	private int $request_id = 1;

	public function __construct( string $endpoint, string $username, string $password ) {
		$this->endpoint = $endpoint;
		$this->username = $username;
		$this->password = $password;
	}

	public function initialize(): void {
		$response = $this->request(
			'POST',
			array(
				'jsonrpc' => '2.0',
				'id'      => $this->request_id++,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => (object) array(),
					'clientInfo'      => array(
						'name'    => 'webmastery-mcp-e2e-crud',
						'version' => '1.0.0',
					),
				),
			)
		);

		$session_id = $this->header( $response['headers'], 'mcp-session-id' );
		if ( null === $session_id || '' === trim( $session_id ) ) {
			throw new Webmastery_MCP_E2E_Failure( 'Initialize response did not include Mcp-Session-Id.' );
		}

		$this->assert_jsonrpc_success( $response['json'] ?? null, 'initialize' );
		$this->session_id = trim( $session_id );

		$this->notification( 'notifications/initialized' );
	}

	public function close(): void {
		if ( null === $this->session_id ) {
			return;
		}

		$this->request( 'DELETE', null, true, true );
		$this->session_id = null;
	}

	public function call( string $method, array $params = array() ): array {
		$response = $this->request(
			'POST',
			array(
				'jsonrpc' => '2.0',
				'id'      => $this->request_id++,
				'method'  => $method,
				'params'  => (object) $params,
			),
			true
		);

		return $this->assert_jsonrpc_success( $response['json'] ?? null, $method );
	}

	public function notification( string $method, array $params = array() ): void {
		$this->request(
			'POST',
			array(
				'jsonrpc' => '2.0',
				'method'  => $method,
				'params'  => (object) $params,
			),
			true,
			true
		);
	}

	private function request( string $method, ?array $body = null, bool $use_session = false, bool $allow_empty_json = false ): array {
		$headers = array(
			'Authorization: Basic ' . base64_encode( $this->username . ':' . $this->password ),
		);

		if ( null !== $body ) {
			$headers[] = 'Content-Type: application/json';
		}
		if ( $use_session ) {
			if ( null === $this->session_id ) {
				throw new Webmastery_MCP_E2E_Failure( "Cannot send {$method} request without an MCP session." );
			}
			$headers[] = 'Mcp-Session-Id: ' . $this->session_id;
		}

		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => $method,
					'header'        => implode( "\r\n", $headers ),
					'content'       => null === $body ? '' : $this->encode_json( $body ),
					'ignore_errors' => true,
					'timeout'       => 30,
				),
			)
		);

		$raw_response = file_get_contents( $this->endpoint, false, $context );
		$response_headers = $http_response_header ?? array();
		$status = $this->status_code( $response_headers );

		if ( false === $raw_response ) {
			$error = error_get_last();
			throw new Webmastery_MCP_E2E_Failure( 'HTTP request failed: ' . ( $error['message'] ?? 'unknown error' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new Webmastery_MCP_E2E_Failure( "HTTP {$method} returned {$status}: {$raw_response}" );
		}

		if ( '' === trim( $raw_response ) && $allow_empty_json ) {
			return array(
				'headers' => $response_headers,
				'json'    => null,
			);
		}

		$json = json_decode( $raw_response, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Webmastery_MCP_E2E_Failure( 'Invalid JSON response: ' . json_last_error_msg() . " Body: {$raw_response}" );
		}

		return array(
			'headers' => $response_headers,
			'json'    => $json,
		);
	}

	private function assert_jsonrpc_success( $json, string $label ): array {
		if ( ! is_array( $json ) ) {
			throw new Webmastery_MCP_E2E_Failure( "{$label} did not return a JSON-RPC response." );
		}

		if ( isset( $json['error'] ) ) {
			throw new Webmastery_MCP_E2E_Failure( "{$label} returned JSON-RPC error: " . $this->encode_json( $json['error'] ) );
		}

		if ( ! array_key_exists( 'result', $json ) || ! is_array( $json['result'] ) ) {
			throw new Webmastery_MCP_E2E_Failure( "{$label} response did not include an object result." );
		}

		return $json['result'];
	}

	private function header( array $headers, string $name ): ?string {
		$needle = strtolower( $name ) . ':';
		foreach ( $headers as $header ) {
			$header = (string) $header;
			if ( str_starts_with( strtolower( $header ), $needle ) ) {
				return trim( substr( $header, strlen( $needle ) ) );
			}
		}

		return null;
	}

	private function status_code( array $headers ): int {
		$status_line = (string) ( $headers[0] ?? '' );
		if ( preg_match( '/^HTTP\/\S+\s+(\d{3})\b/', $status_line, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	private function encode_json( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new Webmastery_MCP_E2E_Failure( 'Could not encode JSON: ' . json_last_error_msg() );
		}

		return $json;
	}
}

function webmastery_mcp_e2e_env( string $name, ?string $default = null ): string {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		if ( null !== $default ) {
			return $default;
		}
		throw new Webmastery_MCP_E2E_Failure( "Missing required environment variable: {$name}" );
	}

	return $value;
}

function webmastery_mcp_e2e_json( $value ): string {
	$json = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		throw new Webmastery_MCP_E2E_Failure( 'Could not encode JSON: ' . json_last_error_msg() );
	}

	return $json;
}

function webmastery_mcp_e2e_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new Webmastery_MCP_E2E_Failure( $message );
	}
}

function webmastery_mcp_e2e_extract_tool_payload( array $result, string $label ) {
	if ( array_key_exists( 'structuredContent', $result ) && is_array( $result['structuredContent'] ) ) {
		return $result['structuredContent'];
	}

	if ( array_key_exists( 'content', $result ) && is_array( $result['content'] ) ) {
		foreach ( $result['content'] as $content ) {
			if ( ! is_array( $content ) || ( $content['type'] ?? '' ) !== 'text' || ! is_string( $content['text'] ?? null ) ) {
				continue;
			}

			$decoded = json_decode( $content['text'], true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}
	}

	if ( array_key_exists( 'success', $result ) ) {
		return $result;
	}

	throw new Webmastery_MCP_E2E_Failure( "{$label} response did not include parseable tool payload: " . webmastery_mcp_e2e_json( $result ) );
}

function webmastery_mcp_e2e_call_tool( Webmastery_MCP_E2E_Client $client, string $name, array $arguments, string $label ) {
	$result = $client->call(
		'tools/call',
		array(
			'name'      => $name,
			'arguments' => $arguments,
		)
	);

	if ( true === ( $result['isError'] ?? false ) ) {
		return array(
			'success' => false,
			'error'   => 'MCP tool result isError=true',
			'raw'     => $result,
		);
	}

	return webmastery_mcp_e2e_extract_tool_payload( $result, $label );
}

function webmastery_mcp_e2e_execute_ability( Webmastery_MCP_E2E_Client $client, string $ability, array $parameters, string $label ) {
	$payload = webmastery_mcp_e2e_call_tool(
		$client,
		'mcp-adapter-execute-ability',
		array(
			'ability_name' => $ability,
			'parameters'   => (object) $parameters,
		),
		$label
	);

	if (
		is_array( $payload )
		&& true === ( $payload['success'] ?? false )
		&& is_array( $payload['data'] ?? null )
		&& array_key_exists( 'success', $payload['data'] )
	) {
		return $payload['data'];
	}

	return $payload;
}

function webmastery_mcp_e2e_pass( array &$summary, string $label ): void {
	$summary['passed']++;
	$summary['cases'][] = array(
		'label'  => $label,
		'passed' => true,
	);
	echo "PASS {$label}\n";
}

function webmastery_mcp_e2e_fail( array &$summary, string $label, Throwable $throwable ): void {
	$summary['failed']++;
	$summary['cases'][] = array(
		'label'   => $label,
		'passed'  => false,
		'message' => $throwable->getMessage(),
	);
	echo "FAIL {$label}: {$throwable->getMessage()}\n";
}

function webmastery_mcp_e2e_write_summary( string $path, array $summary ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new Webmastery_MCP_E2E_Failure( "Could not create artifact directory: {$dir}" );
	}

	file_put_contents( $path, webmastery_mcp_e2e_json( $summary ) . "\n" );
}

$wordpress_url = rtrim( webmastery_mcp_e2e_env( 'WORDPRESS_URL', 'http://localhost' ), '/' );
$endpoint      = webmastery_mcp_e2e_env( 'MCP_CRUD_ENDPOINT', $wordpress_url . '/wp-json/mcp/mcp-adapter-default-server' );
$artifact_path = webmastery_mcp_e2e_env( 'MCP_CRUD_ARTIFACT', __DIR__ . '/../../e2e-artifacts/mcp-crud-summary.json' );

$summary = array(
	'endpoint'        => $endpoint,
	'transport'       => 'http',
	'created_post_id' => null,
	'passed'          => 0,
	'failed'          => 0,
	'cases'           => array(),
);

$editor_client     = new Webmastery_MCP_E2E_Client( $endpoint, webmastery_mcp_e2e_env( 'MCP_CRUD_EDITOR_USER' ), webmastery_mcp_e2e_env( 'MCP_CRUD_EDITOR_PASSWORD' ) );
$subscriber_client = new Webmastery_MCP_E2E_Client( $endpoint, webmastery_mcp_e2e_env( 'MCP_CRUD_SUBSCRIBER_USER' ), webmastery_mcp_e2e_env( 'MCP_CRUD_SUBSCRIBER_PASSWORD' ) );
$created_post_id   = null;
$deleted_post      = false;
$title_marker      = 'MCP HTTP CRUD E2E ' . gmdate( 'YmdHis' );

try {
	$editor_client->initialize();
	webmastery_mcp_e2e_pass( $summary, 'initialize editor MCP HTTP session' );

	$tools = $editor_client->call( 'tools/list' );
	$tool_names = array_map(
		static function ( $tool ) {
			return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
		},
		(array) ( $tools['tools'] ?? array() )
	);
	webmastery_mcp_e2e_assert( in_array( 'mcp-adapter-execute-ability', $tool_names, true ), 'tools/list did not include mcp-adapter-execute-ability.' );
	webmastery_mcp_e2e_pass( $summary, 'tools/list exposes execute-ability gateway' );

	$discovered = webmastery_mcp_e2e_call_tool( $editor_client, 'mcp-adapter-discover-abilities', array(), 'discover abilities' );
	$ability_names = array_map(
		static function ( $ability ) {
			return is_array( $ability ) ? (string) ( $ability['name'] ?? '' ) : '';
		},
		(array) ( $discovered['abilities'] ?? array() )
	);
	foreach ( array(
		'webmastery-site-toolkit-for-mcp/create-post',
		'webmastery-site-toolkit-for-mcp/get-post',
		'webmastery-site-toolkit-for-mcp/update-post',
		'webmastery-site-toolkit-for-mcp/delete-post',
	) as $ability_name ) {
		webmastery_mcp_e2e_assert( in_array( $ability_name, $ability_names, true ), "discover-abilities did not include {$ability_name}." );
	}
	webmastery_mcp_e2e_pass( $summary, 'discover abilities exposes post CRUD abilities' );

	$create = webmastery_mcp_e2e_execute_ability(
		$editor_client,
		'webmastery-site-toolkit-for-mcp/create-post',
		array(
			'title'   => $title_marker,
			'content' => 'Created through real MCP HTTP JSON-RPC.',
			'status'  => 'draft',
		),
		'create post'
	);
	webmastery_mcp_e2e_assert( true === ( $create['success'] ?? false ), 'create-post did not succeed: ' . webmastery_mcp_e2e_json( $create ) );
	$created_post_id = (int) ( $create['data']['id'] ?? 0 );
	$summary['created_post_id'] = $created_post_id;
	webmastery_mcp_e2e_assert( $created_post_id > 0, 'create-post did not return a post ID.' );
	webmastery_mcp_e2e_assert( 'draft' === ( $create['data']['status'] ?? null ), 'create-post did not create a draft post.' );
	webmastery_mcp_e2e_pass( $summary, 'create post through MCP' );

	$get = webmastery_mcp_e2e_execute_ability(
		$editor_client,
		'webmastery-site-toolkit-for-mcp/get-post',
		array( 'post_id' => $created_post_id ),
		'get post'
	);
	webmastery_mcp_e2e_assert( true === ( $get['success'] ?? false ), 'get-post did not succeed: ' . webmastery_mcp_e2e_json( $get ) );
	webmastery_mcp_e2e_assert( $created_post_id === (int) ( $get['data']['id'] ?? 0 ), 'get-post returned the wrong post ID.' );
	webmastery_mcp_e2e_assert( $title_marker === ( $get['data']['title'] ?? null ), 'get-post returned the wrong title.' );
	webmastery_mcp_e2e_pass( $summary, 'read post through MCP' );

	$updated_title = $title_marker . ' Updated';
	$update = webmastery_mcp_e2e_execute_ability(
		$editor_client,
		'webmastery-site-toolkit-for-mcp/update-post',
		array(
			'post_id' => $created_post_id,
			'title'   => $updated_title,
			'content' => 'Updated through real MCP HTTP JSON-RPC.',
		),
		'update post'
	);
	webmastery_mcp_e2e_assert( true === ( $update['success'] ?? false ), 'update-post did not succeed: ' . webmastery_mcp_e2e_json( $update ) );
	webmastery_mcp_e2e_assert( $updated_title === ( $update['data']['title'] ?? null ), 'update-post returned the wrong title.' );
	webmastery_mcp_e2e_assert( 'Updated through real MCP HTTP JSON-RPC.' === ( $update['data']['content'] ?? null ), 'update-post returned the wrong content.' );
	webmastery_mcp_e2e_pass( $summary, 'update post through MCP' );

	$delete = webmastery_mcp_e2e_execute_ability(
		$editor_client,
		'webmastery-site-toolkit-for-mcp/delete-post',
		array( 'post_id' => $created_post_id ),
		'delete post'
	);
	webmastery_mcp_e2e_assert( true === ( $delete['success'] ?? false ), 'delete-post did not succeed: ' . webmastery_mcp_e2e_json( $delete ) );
	webmastery_mcp_e2e_assert( $created_post_id === (int) ( $delete['data']['id'] ?? 0 ), 'delete-post returned the wrong post ID.' );
	webmastery_mcp_e2e_assert( 'trash' === ( $delete['data']['status'] ?? null ), 'delete-post did not report trash status.' );
	$deleted_post = true;
	webmastery_mcp_e2e_pass( $summary, 'delete post through MCP' );

	$subscriber_client->initialize();
	webmastery_mcp_e2e_pass( $summary, 'initialize subscriber MCP HTTP session' );

	$denied = webmastery_mcp_e2e_execute_ability(
		$subscriber_client,
		'webmastery-site-toolkit-for-mcp/create-post',
		array(
			'title'   => $title_marker . ' Denied',
			'content' => 'Subscriber should not create this post.',
			'status'  => 'draft',
		),
		'create post as subscriber'
	);
	webmastery_mcp_e2e_assert( true !== ( $denied['success'] ?? false ), 'subscriber create-post unexpectedly succeeded.' );
	webmastery_mcp_e2e_pass( $summary, 'permission denial propagates through MCP' );
} catch ( Throwable $throwable ) {
	webmastery_mcp_e2e_fail( $summary, 'MCP HTTP CRUD workflow', $throwable );
} finally {
	if ( $created_post_id && ! $deleted_post ) {
		try {
			webmastery_mcp_e2e_execute_ability(
				$editor_client,
				'webmastery-site-toolkit-for-mcp/delete-post',
				array( 'post_id' => $created_post_id ),
				'cleanup post'
			);
			$summary['cleanup'] = 'trashed created post';
		} catch ( Throwable $cleanup_error ) {
			$summary['cleanup'] = 'failed: ' . $cleanup_error->getMessage();
		}
	}

	try {
		$editor_client->close();
		$subscriber_client->close();
	} catch ( Throwable $close_error ) {
		$summary['session_cleanup_warning'] = $close_error->getMessage();
	}

	webmastery_mcp_e2e_write_summary( $artifact_path, $summary );
	echo "SUMMARY {$summary['passed']} passed, {$summary['failed']} failed\n";
}

exit( $summary['failed'] > 0 ? 1 : 0 );
