<?php

defined( 'ABSPATH' ) || exit;

/**
 * Server-side request forgery guard shared by image URL uploads and the link scan.
 *
 * Validation (validate_public_url()) accepts only http(s) URLs whose host is a public IPv4
 * literal or a name whose IPv4, IPv6 and CNAME answers are all public.
 * Pinning (pin_entries()) turns the validated IPv4 answers into cURL resolve entries so the
 * connection goes to exactly the addresses that were validated (no second DNS
 * answer can redirect it to a private address).
 */
final class Webmastery_MCP_Safe_Http {

	/** Seconds a single pinned request may take at most. */
	public const MAX_TIMEOUT = 5;

	/**
	 * Whether an address is private, reserved, loopback, link-local, documentation or not an IP.
	 *
	 * @param string $ip Address.
	 */
	public static function is_private_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		$packed = inet_pton( $ip );
		if ( false !== $packed && 16 === strlen( $packed ) ) {
			if ( "\xff" === $packed[0] || "\x20\x01\x0d\xb8" === substr( $packed, 0, 4 ) ) {
				return true;
			}
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				return self::is_private_ip( inet_ntop( substr( $packed, 12 ) ) );
			}
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Default IPv4 resolver.
	 *
	 * @param string $host Hostname.
	 * @return array<int, string>|false
	 */
	public static function resolve_ipv4( $host ) {
		return gethostbynamel( $host );
	}

	/**
	 * Default AAAA/CNAME resolver; false on resolver failure.
	 *
	 * @param string $host Hostname.
	 * @return array<int, array<string, mixed>>|false
	 */
	public static function resolve_dns( $host ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return false;
		}
		// A resolver failure is distinct from a successful response without AAAA records.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Returned failures become explicit WP_Error responses.
		return @dns_get_record( $host, DNS_AAAA | DNS_CNAME );
	}

	/**
	 * Validate AAAA answers and follow CNAME targets (at most 16 names, no cycles).
	 *
	 * @param string              $host        Hostname.
	 * @param string              $label       Capitalized noun for messages.
	 * @param callable            $resolve_dns AAAA/CNAME resolver.
	 * @param array<string, bool> $visited     Names already checked.
	 */
	private static function validate_dns( $host, $label, callable $resolve_dns, $visited = [] ) {
		if ( isset( $visited[ $host ] ) || count( $visited ) >= 16 ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} hostname has a cyclic or excessive DNS alias chain." );
		}
		$visited[ $host ] = true;
		$records          = $resolve_dns( $host );
		if ( false === $records ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Could not resolve ' . strtolower( $label ) . ' hostname IPv6 records.' );
		}

		foreach ( $records as $record ) {
			if ( 'AAAA' === $record['type'] && self::is_private_ip( $record['ipv6'] ) ) {
				return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must not resolve to a private or reserved address." );
			}
			if ( 'CNAME' === $record['type'] ) {
				$result = self::validate_dns( strtolower( rtrim( $record['target'], '.' ) ), $label, $resolve_dns, $visited );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Validate a URL and the addresses its host resolves to.
	 *
	 * @param string                  $url           Untrusted URL.
	 * @param array<int, string>|null $validated_ips Set on success: the IPv4 addresses that passed validation, empty for an IP literal.
	 * @param string                  $label         Capitalized noun used in error messages ("Image" for uploads).
	 * @param callable|null           $resolve_ipv4  IPv4 resolver (default gethostbynamel()).
	 * @param callable|null           $resolve_dns   AAAA/CNAME resolver (default dns_get_record()).
	 * @return string|WP_Error The sanitized URL, or an invalid_url error.
	 */
	public static function validate_public_url( $url, &$validated_ips = null, $label = 'Image', ?callable $resolve_ipv4 = null, ?callable $resolve_dns = null ) {
		$validated_ips = null;
		$resolve_ipv4  = $resolve_ipv4 ?? [ self::class, 'resolve_ipv4' ];
		$resolve_dns   = $resolve_dns ?? [ self::class, 'resolve_dns' ];

		$url = esc_url_raw( trim( (string) $url ), [ 'http', 'https' ] );

		if ( '' === $url ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must be a valid http or https URL." );
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), " \t\n\r\0\x0B." ) );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must include an http or https scheme and host." );
		}

		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must not target a local host." );
		}

		if ( false !== strpbrk( $host, ':[]' ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must use a hostname or public IPv4 address supported by the safe HTTP API." );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_private_ip( $host ) ) {
				return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must not target a private or reserved address." );
			}
			$validated_ips = [];
			return $url;
		}

		$resolved_ips = $resolve_ipv4( $host );
		if ( ! is_array( $resolved_ips ) || [] === $resolved_ips ) {
			return Webmastery_MCP_Response::local_error( 'invalid_url', 'Could not resolve ' . strtolower( $label ) . ' hostname IPv4 records.' );
		}
		foreach ( $resolved_ips as $resolved_ip ) {
			if ( self::is_private_ip( $resolved_ip ) ) {
				return Webmastery_MCP_Response::local_error( 'invalid_url', "{$label} URL must not resolve to a private or reserved address." );
			}
		}

		$result = self::validate_dns( $host, $label, $resolve_dns );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$validated_ips = array_values( array_unique( array_map( 'strval', $resolved_ips ) ) );
		return $url;
	}

	/**
	 * CURLOPT_RESOLVE entries that pin a validated URL's host to its validated IPv4 addresses.
	 *
	 * @param string                  $url Validated URL.
	 * @param array<int, string>|null $ips Addresses from validate_public_url().
	 * @return array<string, string> host:port => host:port:address[,address]; empty when nothing needs pinning.
	 */
	public static function pin_entries( $url, $ips ) {
		$ips  = array_values( array_filter( (array) $ips, static fn( $ip ) => false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) );
		$raw  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( trim( $raw, " \t\n\r\0\x0B." ) );
		if ( [] === $ips || '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [];
		}
		$port = self::port( $url );
		// Older libcurl (before 7.59.0) accepts only one address per entry.
		$curl = function_exists( 'curl_version' ) ? curl_version() : false;
		if ( ! is_array( $curl ) || ( $curl['version_number'] ?? 0 ) < 0x073B00 ) {
			$ips = array_slice( $ips, 0, 1 );
		}
		$entries = [];
		// Cover every spelling cURL may use as its DNS cache key: case and a trailing dot.
		foreach ( array_unique( [ $host, $host . '.', $raw, strtolower( $raw ) ] ) as $name ) {
			$entries[ $name . ':' . $port ] = $name . ':' . $port . ':' . implode( ',', $ips );
		}
		return $entries;
	}

	/**
	 * Explicit or default port of an http(s) URL.
	 *
	 * @param string $url URL.
	 */
	public static function port( $url ) {
		$port = wp_parse_url( $url, PHP_URL_PORT );
		return $port ? (int) $port : ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 );
	}

	/**
	 * Lowercased host:port a URL connects to.
	 *
	 * @param string $url URL.
	 */
	public static function target( $url ) {
		return strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), " \t\n\r\0\x0B." ) ) . ':' . self::port( $url );
	}

	/**
	 * Whether connections can be pinned (core's cURL transport with CURLOPT_RESOLVE).
	 */
	public static function can_pin() {
		return function_exists( 'curl_init' ) && function_exists( 'curl_setopt' ) && defined( 'CURLOPT_RESOLVE' );
	}

	/**
	 * One request to a URL that validate_public_url() accepted, pinned to the validated addresses.
	 *
	 * Uses wp_safe_remote_request() (reject_unsafe_urls) and never follows redirects:
	 * callers validate and pin every Location themselves. A last-priority
	 * http_request_args filter forces the caller's method, headers and user agent,
	 * no cookies, no streaming to a file, the response size limit and a timeout of
	 * at most MAX_TIMEOUT seconds, so other filters cannot add credentials or
	 * cookies. The request always uses core's cURL transport (forced in
	 * requests.before_request, before anything is sent); a hostname is refused
	 * before sending when WordPress would route it through an HTTP proxy, because
	 * the proxy resolves the host itself. With limit_response_size the transfer is
	 * aborted once more body bytes arrive (core only truncates what it keeps), and
	 * the status line already received is returned without headers or body. If
	 * the request was not pinned, its response is discarded and a
	 * webmastery_unpinned error is returned.
	 *
	 * @param string               $url  URL returned by validate_public_url().
	 * @param array<int, string>   $ips  Addresses validate_public_url() accepted.
	 * @param array<string, mixed> $args method, headers, user-agent, timeout, limit_response_size.
	 * @return array<string, mixed>|WP_Error HTTP API response or error.
	 */
	public static function pinned_request( $url, $ips, array $args ) {
		$pins = self::pin_entries( $url, $ips );
		if ( ! self::can_pin() ) {
			return new WP_Error( 'webmastery_unpinned', 'The HTTP transport cannot pin validated addresses.' );
		}
		$limit   = isset( $args['limit_response_size'] ) ? max( 0, (int) $args['limit_response_size'] ) : null;
		$forced  = [
			'method'              => (string) ( $args['method'] ?? 'HEAD' ),
			'headers'             => (array) ( $args['headers'] ?? [] ),
			'user-agent'          => (string) ( $args['user-agent'] ?? '' ),
			'timeout'             => max( 1, min( self::MAX_TIMEOUT, (float) ( $args['timeout'] ?? self::MAX_TIMEOUT ) ) ),
			'redirection'         => 0,
			'cookies'             => [],
			'stream'              => false,
			'blocking'            => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $limit,
		];
		$applied = false;
		$refused = false;
		$over    = false;
		$handles = [];
		$enforce = static function ( $parsed, $request_url ) use ( $url, $forced ) {
			if ( $request_url !== $url || ! is_array( $parsed ) ) {
				return $parsed;
			}
			$forced['timeout'] = min( $forced['timeout'], max( 1, (float) ( $parsed['timeout'] ?? $forced['timeout'] ) ) );
			return array_merge( $parsed, $forced );
		};
		$prepare = static function ( &$request_url, &$headers, &$data, &$type, &$options ) use ( $url, $pins, &$refused ) {
			if ( $request_url !== $url || ! is_array( $options ) ) {
				return;
			}
			if ( [] !== $pins && ! empty( $options['proxy'] ) ) {
				// A proxy resolves the host itself, so the pin would not apply: send nothing.
				$refused = true;
				throw new \WpOrg\Requests\Exception( 'Pinned requests cannot use an HTTP proxy.', 'webmastery_unpinned' );
			}
			// Only the cURL transport honours CURLOPT_RESOLVE and the transfer abort below.
			$options['transport']        = 'WpOrg\\Requests\\Transport\\Curl';
			$options['follow_redirects'] = false;
		};
		$pin     = static function ( $handle, $request, $request_url ) use ( $url, $pins, $limit, &$applied, &$handles, &$over ) {
			if ( $request_url !== $url ) {
				return;
			}
			$handles[] = $handle;
			// Connect only to the validated addresses, closing the DNS rebinding window.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pin core's existing transport, not a separate request.
			$applied = [] === $pins || curl_setopt( $handle, CURLOPT_RESOLVE, array_values( $pins ) );
			if ( null !== $limit ) {
				$option = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
				// Requests only truncates what it keeps; stop the transfer itself after the limit.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Bound core's existing transport, not a separate request.
				$applied = $applied && curl_setopt( $handle, CURLOPT_NOPROGRESS, false )
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Bound core's existing transport, not a separate request.
					&& curl_setopt( $handle, $option, static function ( $curl, $download_total, $downloaded ) use ( $limit, &$over ) {
						if ( $downloaded > $limit ) {
							$over = true;
							return 1;
						}
						return 0;
					} );
			}
		};
		add_filter( 'http_request_args', $enforce, PHP_INT_MAX, 2 );
		add_action( 'requests-requests.before_request', $prepare, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		try {
			$response = wp_safe_remote_request( $url, $forced );
		} finally {
			remove_filter( 'http_request_args', $enforce, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $prepare, PHP_INT_MAX );
			remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
			if ( [] !== $pins ) {
				$unpin = array_map( static fn( $key ) => '-' . $key, array_keys( $pins ) );
				foreach ( $handles as $handle ) {
					// A reused handle keeps resolve entries in its DNS cache; remove them on its next transfer.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Do not leave pinned addresses on a reused core handle.
					curl_setopt( $handle, CURLOPT_RESOLVE, $unpin );
				}
			}
		}
		if ( $refused || ! $applied ) {
			return new WP_Error( 'webmastery_unpinned', 'The request could not be pinned to the validated addresses.' );
		}
		if ( $over && is_wp_error( $response ) && [] !== $handles ) {
			// The size abort surfaces as a transport error; keep only the status line already received.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Read the status of core's own aborted transfer.
			$code = (int) curl_getinfo( end( $handles ), CURLINFO_RESPONSE_CODE );
			if ( $code > 0 ) {
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [
						'code'    => $code,
						'message' => '',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		}
		return $response;
	}
}
