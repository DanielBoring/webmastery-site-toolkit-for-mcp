<?php
/**
 * Runs the production upload download hooks against a scripted Requests flow.
 *
 * The class body is loaded unchanged into this namespace so DNS lookups, cURL options
 * and WordPress hooks resolve to the recording stubs below instead of the network.
 */

namespace WpOrg\Requests {
	if ( ! class_exists( Exception::class, false ) ) {
		class Exception extends \Exception {
			public function __construct( $message, $type ) {
				parent::__construct( $message );
			}
		}
	}
}

namespace WstmMediaPinning {
	final class State {
		/** @var array<string, array<int, array<int, string>>> Queued IPv4 answers per host, consumed per lookup. */
		public static array $answers = array();
		/** @var array<int, string> */
		public static array $lookups = array();
		/** @var array<string, array<int, callable>> */
		public static array $actions = array();
		/** @var array<int, array{handle: int, option: int, value: mixed}> */
		public static array $curl = array();
		/** @var array<int, array<string, mixed>> Scripted hops: url, transport, location, rewrite. */
		public static array $hops = array();
		/** @var array<int, string> URLs that reached the transport. */
		public static array $sent = array();
		/** @var array<int, string> Transport each hop used ('curl' or the scripted fallback). */
		public static array $transports = array();
		/** @var array<int, mixed> Transport option seen by requests that are not the download. */
		public static array $unrelated = array();
		public static int $curl_version = 0x080000;
		/** Whether the cURL functions exist (Requests' cURL transport is usable). */
		public static bool $curl_usable = true;
		/** Whether cURL was built with TLS support. */
		public static bool $curl_ssl = true;
		/** Requests proxy option (WordPress sets it when WP_PROXY_HOST applies to the URL). */
		public static $proxy = false;
		public static string $dir = '';

		public static function reset(): void {
			self::$answers = self::$actions = self::$curl = self::$hops = self::$sent = self::$lookups = self::$transports = self::$unrelated = array();
			self::$curl_version = 0x080000;
			self::$curl_usable  = true;
			self::$curl_ssl     = true;
			self::$proxy        = false;
		}

		/** @return array<int, mixed> CURLOPT_RESOLVE values in the order they were set. */
		public static function resolves(): array {
			return array_values( array_map( static fn( $call ) => $call['value'], array_filter( self::$curl, static fn( $call ) => CURLOPT_RESOLVE === $call['option'] ) ) );
		}
	}

	final class Hooks {
		/** @var array<string, array<int, callable>> */
		private array $hooks = array();

		public function register( $name, $callback ): void {
			$this->hooks[ $name ][] = $callback;
		}

		public function dispatch( $name, array $args ): void {
			foreach ( $this->hooks[ $name ] ?? array() as $callback ) {
				$callback( ...$args );
			}
		}
	}

	// The shared URL guard and pinning helpers resolve DNS and cURL calls in this namespace too.
	$safe_http = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-safe-http.php' );
	eval( 'namespace WstmMediaPinning; use \WP_Error; use \Webmastery_MCP_Response; ' . substr( $safe_http, 5 ) );
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-media.php' );
	eval( 'namespace WstmMediaPinning; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Untrusted; use \Webmastery_MCP_Permissions; use \Webmastery_MCP_Input; use \Webmastery_MCP_Post_Access; use \Webmastery_MCP_Post_Writes; ' . substr( $source, 5 ) );

	function gethostbynamel( $host ) {
		State::$lookups[] = $host;
		$queue = State::$answers[ $host ] ?? array();
		if ( array() === $queue ) {
			return false;
		}
		// The last answer repeats, so a rebinding host keeps returning its final address.
		return count( $queue ) > 1 ? array_shift( State::$answers[ $host ] ) : $queue[0];
	}

	function dns_get_record( $host, $type ) {
		return array();
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function curl_version() {
		return array( 'version_number' => State::$curl_version, 'features' => State::$curl_ssl ? CURL_VERSION_SSL : 0 );
	}

	function function_exists( $name ) {
		if ( in_array( $name, array( 'curl_init', 'curl_setopt', 'curl_exec' ), true ) ) {
			return State::$curl_usable;
		}
		return \function_exists( $name );
	}

	function curl_setopt( $handle, $option, $value ) {
		State::$curl[] = array( 'handle' => $handle->id, 'option' => $option, 'value' => $value );
		return true;
	}

	function add_filter( $name, $callback, $priority = 10, $args = 1 ) {
		State::$actions[ $name ][] = $callback;
	}

	function add_action( $name, $callback, $priority = 10, $args = 1 ) {
		add_filter( $name, $callback );
	}

	function remove_filter( $name, $callback, $priority = 10 ) {
		State::$actions[ $name ] = array_values( array_filter( State::$actions[ $name ] ?? array(), static fn( $known ) => $known !== $callback ) );
	}

	function remove_action( $name, $callback, $priority = 10 ) {
		remove_filter( $name, $callback );
	}

	function wp_delete_file( $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Mirrors the order core uses: request args, then per hop before_request,
	 * curl.before_send (cURL only), transfer, and before_redirect for a Location.
	 * A hop's scripted transport is what WordPress would pick; a transport set in
	 * before_request takes precedence, as in Requests::request(). A hop with
	 * 'unrelated' first runs before_request for another request in the same process;
	 * 'body' reports that many received bytes through request.progress.
	 */
	function download_url( $url ) {
		$filename = tempnam( State::$dir, 'pin' );
		$args     = array( 'stream' => true, 'filename' => $filename );
		foreach ( State::$actions['http_request_args'] ?? array() as $filter ) {
			$args = $filter( $args, $url );
		}
		$options = array( 'filename' => $filename, 'hooks' => new Hooks(), 'proxy' => State::$proxy, 'transport' => null );
		$handle  = (object) array( 'id' => 1 );
		$current = $url;
		try {
			foreach ( State::$hops as $hop ) {
				if ( isset( $hop['rewrite'] ) ) {
					// A later hook changed the destination after validation.
					$current = $hop['rewrite'];
				}
				$headers = array();
				$data    = array();
				$type    = 'GET';
				if ( ! empty( $hop['unrelated'] ) ) {
					$other_url     = 'https://unrelated.test/';
					$other_options = array( 'hooks' => new Hooks(), 'proxy' => false, 'transport' => null );
					foreach ( State::$actions['requests-requests.before_request'] ?? array() as $action ) {
						$action( $other_url, $headers, $data, $type, $other_options );
					}
					State::$unrelated[] = $other_options['transport'];
				}
				foreach ( State::$actions['requests-requests.before_request'] ?? array() as $action ) {
					$action( $current, $headers, $data, $type, $options );
				}
				$transport = null !== $options['transport']
					? ( 'WpOrg\\Requests\\Transport\\Curl' === $options['transport'] ? 'curl' : (string) $options['transport'] )
					: ( $hop['transport'] ?? 'curl' );
				State::$transports[] = $transport;
				if ( 'curl' === $transport ) {
					$options['hooks']->dispatch( 'curl.before_send', array( $handle ) );
				}
				if ( isset( $hop['body'] ) ) {
					// Both transports report each received body chunk through request.progress.
					$options['hooks']->dispatch( 'request.progress', array( str_repeat( 'x', $hop['body'] ), 0 ) );
				}
				State::$sent[] = $current;
				if ( isset( $hop['location'] ) ) {
					$location = $hop['location'];
					$options['hooks']->dispatch( 'requests.before_redirect', array( $location ) );
					$current = $location;
				}
			}
		} catch ( \WpOrg\Requests\Exception $error ) {
			unlink( $filename );
			return new \WP_Error( 'http_request_failed', $error->getMessage() );
		}
		file_put_contents( $filename, 'image' );
		return $filename;
	}
}
