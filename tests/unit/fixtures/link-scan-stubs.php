<?php
/**
 * Loads the production Scan Broken Links ability, the shared link parser and the shared
 * SSRF guard into one namespace with a scripted WordPress, DNS and HTTP layer.
 *
 * No real network or DNS is used: gethostbynamel(), dns_get_record(), curl_setopt()
 * and wp_safe_remote_request() below record calls and return scripted answers.
 * State lives in WstmLinkScan\State; LinkScanTest resets it before each test.
 */

namespace WstmLinkScan;

if ( ! class_exists( '\\WpOrg\\Requests\\Exception', false ) ) {
	eval( 'namespace WpOrg\\Requests; class Exception extends \\Exception { public function __construct( $message, $type ) { parent::__construct( $message ); } }' );
}

require_once __DIR__ . '/shared-helper-loader.php';
\wstm_test_load_shared_helpers( __NAMESPACE__ );

/** Minimal WP_Post stand-in. */
class WP_Post {
	public $ID           = 0;
	public $post_type    = 'post';
	public $post_status  = 'publish';
	public $post_title   = '';
	public $post_content = '';

	public function __construct( array $fields = array() ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}

final class State {
	/** @var array<int, string> Capabilities of the current user. */
	public static array $caps = array();
	/** @var array<int, int> Post IDs edit_post is denied for. */
	public static array $uneditable = array();
	/** @var array<int, int> Post IDs read_post is denied for. */
	public static array $unreadable = array();
	/** @var array<int, WP_Post> */
	public static array $posts = array();
	/** @var array<string, int> url_to_postid() answers. */
	public static array $routes = array();
	/** @var array<string, int> attachment_url_to_postid() answers. */
	public static array $attachments = array();
	/** @var array<int, string> Every offline lookup, in order ("url:..." / "attachment:..."). */
	public static array $lookups = array();
	/** @var array<string, array<int, array<int, string>>> Queued IPv4 answers per host; the last one repeats. */
	public static array $a = array();
	/** @var array<string, array<int, array<string, string>>|false> AAAA/CNAME answers per host. */
	public static array $dns = array();
	/** @var array<int, string> Every DNS lookup ("A:host" / "DNS:host"). */
	public static array $dns_calls = array();
	/** @var array<string, mixed> Scripted responses per "METHOD url": array{code, headers?} or \WP_Error. */
	public static array $responses = array();
	/** @var array<int, array{url: string, args: array<string, mixed>}> Requests that reached the transport. */
	public static array $sent = array();
	/** @var array<int, array{option: int, value: mixed}> */
	public static array $curl = array();
	/** @var array<string, array<int, callable>> */
	public static array $hooks = array();
	public static bool $curl_available = true;
	/** Whether the scripted transport is cURL (fires http_api_curl). */
	public static bool $curl_transport = true;
	/** @var array<int, string> URLs core's wp_http_validate_url() rejects. */
	public static array $core_rejects = array();
	/** @var array<int, string> */
	public static array $core_validated = array();
	public static float $now = 1000.0;
	/** Seconds each request takes on the fake clock. */
	public static float $latency = 0.1;
	/** Seconds each IPv4 lookup takes on the fake clock. */
	public static float $dns_latency = 0.0;
	/** Whether WordPress would send requests through an HTTP proxy. */
	public static bool $proxy = false;
	/** @var array<string, int> Body bytes the server sends per "METHOD url" (default 0). */
	public static array $body_bytes = array();
	/** @var array<int, string> Transport class each request used. */
	public static array $transports = array();
	/** @var array<int, int> Body bytes actually transferred per request. */
	public static array $transferred = array();
	/** @var array<string, array<string, mixed>> */
	public static array $registered = array();

	public static function reset(): void {
		self::$caps           = array( 'edit_posts', 'read' );
		self::$uneditable     = self::$unreadable = self::$routes = self::$attachments = self::$lookups = array();
		self::$posts          = self::$a = self::$dns = self::$dns_calls = self::$responses = self::$sent = array();
		self::$curl           = self::$hooks = self::$core_rejects = self::$core_validated = self::$registered = array();
		self::$curl_available = true;
		self::$curl_transport = true;
		self::$now            = 1000.0;
		self::$latency        = 0.1;
		self::$dns_latency    = 0.0;
		self::$proxy          = false;
		self::$body_bytes     = self::$transports = self::$transferred = array();
	}

	/** @return array<int, mixed> CURLOPT_RESOLVE values in the order they were set. */
	public static function resolves(): array {
		return array_values( array_map( static fn( $call ) => $call['value'], array_filter( self::$curl, static fn( $call ) => CURLOPT_RESOLVE === $call['option'] ) ) );
	}

	/** @return array<int, string> "METHOD url" of every request sent. */
	public static function sent(): array {
		return array_map( static fn( $request ) => $request['args']['method'] . ' ' . $request['url'], self::$sent );
	}
}

$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-link-scan.php' );
eval( 'namespace WstmLinkScan; use \WP_Error; use \Webmastery_MCP_Response; ' . substr( $source, 5 ) );

function wp_register_ability( $name, $args ) {
	State::$registered[ $name ] = $args;
}

function current_user_can( $capability, ...$args ) {
	$id = (int) ( $args[0] ?? 0 );
	if ( 'edit_post' === $capability ) {
		return in_array( 'edit_posts', State::$caps, true ) && ! in_array( $id, State::$uneditable, true );
	}
	if ( 'read_post' === $capability ) {
		return isset( State::$posts[ $id ] ) && ! in_array( $id, State::$unreadable, true );
	}
	return in_array( $capability, State::$caps, true );
}

function get_post( $id ) {
	$id   = $id instanceof WP_Post ? $id->ID : (int) $id;
	$post = State::$posts[ $id ] ?? null;
	return $post ? clone $post : null;
}

function get_permalink( $post ) {
	return 'https://example.test/2026/hello-world/';
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wp_get_upload_dir() {
	return array( 'baseurl' => 'https://example.test/wp-content/uploads' );
}

function url_to_postid( $url ) {
	State::$lookups[] = 'url:' . $url;
	return State::$routes[ $url ] ?? 0;
}

function attachment_url_to_postid( $url ) {
	State::$lookups[] = 'attachment:' . $url;
	return State::$attachments[ $url ] ?? 0;
}

function is_post_publicly_viewable( $post ) {
	return 'publish' === $post->post_status && in_array( $post->post_type, array( 'post', 'page' ), true );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function gethostbynamel( $host ) {
	State::$dns_calls[] = 'A:' . $host;
	State::$now        += State::$dns_latency;
	$queue = State::$a[ $host ] ?? array();
	if ( array() === $queue ) {
		return false;
	}
	return count( $queue ) > 1 ? array_shift( State::$a[ $host ] ) : $queue[0];
}

function dns_get_record( $host, $type ) {
	State::$dns_calls[] = 'DNS:' . $host;
	return State::$dns[ $host ] ?? array();
}

function function_exists( $name ) {
	if ( in_array( $name, array( 'curl_init', 'curl_setopt' ), true ) ) {
		return State::$curl_available;
	}
	return \function_exists( $name );
}

function curl_version() {
	return array( 'version_number' => 0x080000 );
}

function curl_setopt( $handle, $option, $value ) {
	State::$curl[] = array( 'option' => $option, 'value' => $value );
	$handle->options[ $option ] = $value;
	return true;
}

function curl_getinfo( $handle, $option ) {
	return CURLINFO_RESPONSE_CODE === $option ? ( $handle->code ?? 0 ) : null;
}

function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
	State::$hooks[ $name ][] = $callback;
}

function add_action( $name, $callback, $priority = 10, $args = 1 ) {
	add_filter( $name, $callback );
}

function remove_filter( $name, $callback, $priority = 10 ) {
	State::$hooks[ $name ] = array_values( array_filter( State::$hooks[ $name ] ?? array(), static fn( $known ) => $known !== $callback ) );
}

function remove_action( $name, $callback, $priority = 10 ) {
	remove_filter( $name, $callback );
}

function wp_http_validate_url( $url ) {
	State::$core_validated[] = $url;
	return in_array( $url, State::$core_rejects, true ) ? false : $url;
}

/**
 * Mirrors core's order: http_request_args filters, Requests' before_request (options by
 * reference; a forced transport wins, a thrown Requests exception becomes a WP_Error),
 * http_api_curl (cURL transport only), then the scripted transfer. A cURL progress
 * callback set on the handle sees the body bytes and can abort the transfer. No
 * redirects are followed here; the ability must do that.
 */
function wp_safe_remote_request( $url, $args = array() ) {
	$args['reject_unsafe_urls'] = true;
	$args                       = array( 'timeout' => 5, 'redirection' => 5, 'cookies' => array(), 'headers' => array() ) + $args;
	foreach ( State::$hooks['http_request_args'] ?? array() as $filter ) {
		$args = $filter( $args, $url );
	}
	$request_url = $url;
	$headers     = $args['headers'];
	$data        = null;
	$type        = $args['method'];
	$options     = array( 'follow_redirects' => true, 'transport' => null ) + ( State::$proxy ? array( 'proxy' => 'proxy.test:3128' ) : array() );
	try {
		foreach ( State::$hooks['requests-requests.before_request'] ?? array() as $action ) {
			$action( $request_url, $headers, $data, $type, $options );
		}
	} catch ( \WpOrg\Requests\Exception $error ) {
		return new \WP_Error( 'http_request_failed', $error->getMessage() );
	}
	$transport            = $options['transport'] ?? ( State::$curl_transport ? 'WpOrg\\Requests\\Transport\\Curl' : 'WpOrg\\Requests\\Transport\\Fsockopen' );
	State::$transports[]  = $transport;
	$key                  = $args['method'] . ' ' . $url;
	$response             = State::$responses[ $key ] ?? array( 'code' => 200 );
	$handle               = (object) array( 'id' => 1, 'options' => array() );
	if ( 'WpOrg\\Requests\\Transport\\Curl' === $transport ) {
		foreach ( State::$hooks['http_api_curl'] ?? array() as $action ) {
			$action( $handle, $args, $url );
		}
	}
	State::$sent[] = array( 'url' => $url, 'args' => $args, 'options' => $options );
	State::$now   += State::$latency;
	if ( $response instanceof \WP_Error ) {
		return $response;
	}
	$handle->code = $response['code'];
	$bytes        = State::$body_bytes[ $key ] ?? 0;
	$progress     = $handle->options[ \defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION ] ?? null;
	$sent         = 0;
	while ( $sent < $bytes ) {
		$sent = min( $bytes, $sent + 16384 );
		if ( null !== $progress && false === ( $handle->options[ CURLOPT_NOPROGRESS ] ?? true ) && 0 !== $progress( $handle, $bytes, $sent, 0, 0 ) ) {
			State::$transferred[] = $sent;
			return new \WP_Error( 'http_request_failed', 'cURL error 42: Callback aborted' );
		}
	}
	State::$transferred[] = $sent;
	return array(
		'response' => array( 'code' => $response['code'] ),
		'headers'  => $response['headers'] ?? array(),
		'body'     => 'SECRET BODY',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ $header ] ?? '';
}
