<?php

defined( 'ABSPATH' ) || exit;

/**
 * Broken-link scan of one post or page.
 *
 * Internal links are resolved offline with url_to_postid() and
 * attachment_url_to_postid(); no HTTP request is made for them. External links
 * are only requested when check_external is true (administrators), through the
 * shared SSRF guard (Webmastery_MCP_Safe_Http) with hard per-call bounds. No
 * result is cached.
 */
class Webmastery_MCP_Link_Scan {

	public const NAME = 'webmastery-site-toolkit-for-mcp/scan-broken-links';

	/** Neutral User-Agent for external checks: names the plugin, never the site. */
	public const USER_AGENT = 'WebmasterySiteToolkitForMCP-LinkCheck/1.0 (WordPress plugin)';

	private const MAX_LINKS = 50;

	private const DEFAULT_LINKS = 25;

	/** HTTP requests per call, counting redirect hops and GET fallbacks. */
	private const MAX_REQUESTS = 10;

	/** HTTP requests to one host per call. */
	private const MAX_PER_HOST = 2;

	private const MAX_REDIRECTS = 3;

	/** Seconds of wall-clock time after which no further request starts. */
	private const BUDGET = 20.0;

	private const ANCHOR_LIMIT = 100;

	/** Bytes of markup after an opening <a> tag searched for its text. */
	private const ANCHOR_SCAN_BYTES = 2000;

	/** Response bytes read by the ranged GET fallback. */
	private const GET_BYTES = 1024;

	public const STATUSES = [ 'ok', 'redirect', 'broken', 'client_error', 'server_error', 'blocked', 'unreachable', 'not_published', 'not_found', 'unresolved', 'not_checked' ];

	public static function register() {
		wp_register_ability( self::NAME, [
			'label'               => 'Scan Broken Links',
			'description'         => 'Check the <a href> links in one post or page. Internal links (same site host) are resolved without any HTTP request: ok, not_published, not_found (trashed or deleted object) or unresolved (no post or attachment matches, e.g. archives). External links are listed as not_checked unless check_external is true, which needs manage_options and sends a HEAD request (ranged GET fallback) from the server to each public external URL: at most 10 requests, 2 per host and about 20 seconds per call; continue with next_offset. Private, reserved and local addresses are never requested. Results are not cached.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'        => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Post or page ID to scan.' ],
					'check_external' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Request external links from the server (requires manage_options).' ],
					'max_links'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LINKS, 'default' => self::DEFAULT_LINKS, 'description' => 'Unique links considered in this call.' ],
					'offset'         => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Index of the first unique link; use next_offset to continue.' ],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	/**
	 * Permission callback.
	 *
	 * Like the comment moderation callbacks, a missing object is left to execution
	 * (not_found) once the caller holds a base editing capability, so callers
	 * without any editing capability cannot probe IDs.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	public static function permission( $input = [] ) {
		$check = self::check( $input );
		return is_wp_error( $check ) && 'not_found' === $check->get_error_code() ? true : $check;
	}

	/**
	 * Full check used by execution: forbidden, not_found or true.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	private static function check( $input ) {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
		}
		if ( true === ( $input['check_external'] ?? false ) && ! current_user_can( 'manage_options' ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'check_external requires manage_options: external link checks send requests from the server to third-party sites.' );
		}
		$id   = absint( $input['post_id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return Webmastery_MCP_Response::local_error( 'not_found', 'Post or page not found.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires edit_post capability for this post or page.' );
		}
		return true;
	}

	public static function execute( $input = [] ) {
		$permission = self::check( $input );
		if ( is_wp_error( $permission ) ) {
			return Webmastery_MCP_Response::from_wp_error( $permission );
		}
		return Webmastery_MCP_Response::ok( self::scan( (array) $input, static fn() => microtime( true ) ) );
	}

	/**
	 * Scan after the permission check.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @param callable             $clock Returns the current time in seconds (float).
	 * @return array<string, mixed>
	 */
	private static function scan( array $input, callable $clock ): array {
		$started  = (float) $clock();
		$id       = absint( $input['post_id'] ?? 0 );
		$post     = get_post( $id );
		$external = true === ( $input['check_external'] ?? false );
		$max      = max( 1, min( self::MAX_LINKS, (int) ( $input['max_links'] ?? self::DEFAULT_LINKS ) ) );
		$offset   = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$base = get_permalink( $post );
		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/' );
		}
		[ 'links' => $links, 'skipped' => $skipped ] = self::collect( (string) $post->post_content, $base );

		$internal_total = count( array_filter( $links, static fn( $link ) => $link['internal'] ) );
		$window         = array_slice( $links, $offset, $max );
		$state          = [
			'clock'    => $clock,
			'started'  => $started,
			'requests' => 0,
			'checks'   => 0,
			'hosts'    => [],
			'disabled' => false,
		];
		$by_status      = array_fill_keys( self::STATUSES, 0 );
		$records        = [];
		$resume         = null;

		foreach ( $window as $index => $link ) {
			$http_status = null;
			$target_id   = null;
			if ( $link['internal'] ) {
				[ $status, $target_id ] = self::internal_status( $link['url'] );
			} elseif ( ! $external ) {
				$status = 'not_checked';
			} elseif ( $link['userinfo'] ) {
				// Never send credentials embedded in stored content.
				$status = 'blocked';
			} else {
				[ $status, $http_status, $bounded ] = self::external_status( $link['url'], $state );
				if ( $bounded && null === $resume ) {
					$resume = $offset + $index;
				}
			}
			++$by_status[ $status ];
			$records[] = Webmastery_MCP_Untrusted::mark(
				[
					'url'          => $link['url'],
					'kind'         => $link['internal'] ? 'internal' : 'external',
					'status'       => $status,
					'http_status'  => $http_status,
					'anchor_text'  => $link['anchor_text'],
					'occurrences'  => $link['occurrences'],
					'target_id'    => $target_id,
				],
				[ 'url', 'anchor_text' ]
			);
		}

		$end = $offset + count( $window );
		return [
			'post_id'          => $id,
			'checked_external' => $external,
			'links'            => $records,
			'summary'          => [
				'total_links' => count( $links ),
				'internal'    => $internal_total,
				'external'    => count( $links ) - $internal_total,
				'skipped'     => $skipped,
				'by_status'   => $by_status,
			],
			'next_offset'      => $resume ?? ( $end < count( $links ) ? $end : null ),
		];
	}

	/**
	 * Unique web links in document order.
	 *
	 * @param string $content Stored post content.
	 * @param string $base    Absolute URL relative references resolve against.
	 * @return array{links: array<int, array<string, mixed>>, skipped: int}
	 */
	private static function collect( string $content, string $base ): array {
		$home_host = Webmastery_MCP_Links::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$links     = [];
		$skipped   = 0;
		foreach ( Webmastery_MCP_Links::anchors( $content ) as $anchor ) {
			$href = $anchor['href'];
			$link = str_starts_with( $href, '#' ) ? null : Webmastery_MCP_Links::classify( $href, $home_host );
			$abs  = null === $link ? null : Webmastery_MCP_Links::absolute( $href, $base );
			if ( null === $abs ) {
				++$skipped;
				continue;
			}
			$key = $abs['url'] . ( $abs['userinfo'] ? "\0userinfo" : '' );
			if ( isset( $links[ $key ] ) ) {
				++$links[ $key ]['occurrences'];
				if ( '' === $links[ $key ]['anchor_text'] ) {
					$links[ $key ]['anchor_text'] = self::anchor_text( $content, $anchor['end'] );
				}
				continue;
			}
			$links[ $key ] = [
				'url'         => $abs['url'],
				'internal'    => $link['internal'],
				'userinfo'    => $abs['userinfo'],
				'anchor_text' => self::anchor_text( $content, $anchor['end'] ),
				'occurrences' => 1,
			];
		}
		return [
			'links'   => array_values( $links ),
			'skipped' => $skipped,
		];
	}

	/**
	 * Plain text between an opening <a> tag and the next </a>, at most 100 characters.
	 *
	 * @param string $content Stored post content.
	 * @param int    $start   Offset just after the opening tag.
	 */
	private static function anchor_text( string $content, int $start ): string {
		$slice = substr( $content, $start, self::ANCHOR_SCAN_BYTES );
		$close = stripos( $slice, '</a' );
		$text  = wp_strip_all_tags( false === $close ? '' : substr( $slice, 0, $close ) );
		$text  = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return mb_substr( $text, 0, self::ANCHOR_LIMIT );
	}

	/**
	 * Offline status of a same-host URL.
	 *
	 * @param string $url Absolute URL on the site host.
	 * @return array{0: string, 1: int|null} Status and the target ID when the caller can read it.
	 */
	private static function internal_status( string $url ): array {
		$parts    = wp_parse_url( $url );
		$path     = (string) ( $parts['path'] ?? '/' );
		$uploads  = wp_get_upload_dir();
		$base_url = is_array( $uploads ) && is_string( $uploads['baseurl'] ?? null ) ? rtrim( $uploads['baseurl'], '/' ) : '';
		$base     = '' === $base_url ? false : wp_parse_url( $base_url );

		if ( is_array( $base ) && Webmastery_MCP_Links::normalize_host( (string) ( $base['host'] ?? '' ) ) === Webmastery_MCP_Links::normalize_host( (string) ( $parts['host'] ?? '' ) )
			&& str_starts_with( $path, rtrim( (string) ( $base['path'] ?? '' ), '/' ) . '/' ) ) {
			// Look up the file path under the configured uploads URL (one postmeta query; original files only, not resized copies).
			$id = (int) attachment_url_to_postid( $base_url . substr( $path, strlen( rtrim( (string) ( $base['path'] ?? '' ), '/' ) ) ) );
			if ( $id <= 0 ) {
				return [ 'unresolved', null ];
			}
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
				return [ 'not_found', self::readable_id( $post ? $id : 0 ) ];
			}
			// The file itself is served by the web server whatever its parent's status.
			return [ 'ok', self::readable_id( $id ) ];
		}

		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( ! isset( $parts['query'] ) && rtrim( $path, '/' ) === $home_path ) {
			return [ 'ok', null ];
		}

		$id = (int) url_to_postid( $url );
		if ( $id <= 0 ) {
			return [ 'unresolved', null ];
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return [ 'not_found', null ];
		}
		if ( 'trash' === $post->post_status ) {
			return [ 'not_found', self::readable_id( $id ) ];
		}
		if ( is_post_publicly_viewable( $post ) ) {
			return [ 'ok', self::readable_id( $id ) ];
		}
		// url_to_postid() returns ?p=, ?page_id= and ?attachment_id= IDs without a visibility check:
		// a non-public object the caller cannot read reports like a missing ID, as WordPress shows it (404).
		return current_user_can( 'read_post', $id ) ? [ 'not_published', $id ] : [ 'not_found', null ];
	}

	/**
	 * The ID when the caller may read that object, otherwise null.
	 *
	 * @param int $id Post ID.
	 */
	private static function readable_id( int $id ): ?int {
		return $id > 0 && current_user_can( 'read_post', $id ) ? $id : null;
	}

	/**
	 * Check one external URL within the per-call bounds.
	 *
	 * @param string               $url   Absolute external URL without userinfo.
	 * @param array<string, mixed> $state Per-call request counters (by reference).
	 * @return array{0: string, 1: int|null, 2: bool} Status, HTTP status, and whether a per-call bound stopped the check.
	 */
	private static function external_status( string $url, array &$state ): array {
		$current   = $url;
		$redirects = 0;
		$last      = null;
		while ( true ) {
			if ( $state['disabled'] ) {
				return [ 'not_checked', null, false ];
			}
			// Bounds first, so links past a bound cost no DNS lookups either.
			if ( ! self::may_request( $current, $state ) ) {
				return [ 'not_checked', null, true ];
			}
			$ips   = null;
			$valid = Webmastery_MCP_Safe_Http::validate_public_url( $current, $ips, 'Link' );
			// DNS work counts toward the time budget too, even when nothing is requested.
			++$state['checks'];
			if ( is_wp_error( $valid ) || ! wp_http_validate_url( $valid ) ) {
				return [ 'blocked', $last, false ];
			}
			$response = self::request( $valid, $ips, 'HEAD', $state );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, [ 405, 501 ], true ) ) {
				if ( ! self::may_request( $valid, $state ) ) {
					return [ 'not_checked', null, true ];
				}
				$response = self::request( $valid, $ips, 'GET', $state );
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			}
			if ( is_wp_error( $response ) ) {
				if ( 'webmastery_unpinned' === $response->get_error_code() ) {
					$state['disabled'] = true;
					return [ 'not_checked', null, false ];
				}
				return [ 'unreachable', null, false ];
			}
			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
				$next     = '' === trim( $location ) ? null : Webmastery_MCP_Links::absolute( trim( $location ), $valid );
				if ( null === $next || $redirects >= self::MAX_REDIRECTS ) {
					return [ 'redirect', $code, false ];
				}
				if ( $next['userinfo'] ) {
					return [ 'blocked', $code, false ];
				}
				$current = $next['url'];
				$last    = $code;
				++$redirects;
				continue;
			}
			return [ self::classify_http( $code ), $code > 0 ? $code : null, false ];
		}
	}

	private static function classify_http( int $code ): string {
		if ( $code >= 200 && $code < 300 ) {
			return 'ok';
		}
		if ( 404 === $code || 410 === $code ) {
			return 'broken';
		}
		if ( $code >= 400 && $code < 500 ) {
			return 'client_error';
		}
		if ( $code >= 500 && $code < 600 ) {
			return 'server_error';
		}
		return 'unreachable';
	}

	/**
	 * Whether another request fits the per-call, per-host and time bounds.
	 *
	 * Only the first check of a call (its first validation and request) is exempt
	 * from the time budget, so every call makes progress; later validations and
	 * requests, including after blocked links whose DNS lookups were slow, are not.
	 *
	 * @param string               $url   Validated URL.
	 * @param array<string, mixed> $state Per-call counters.
	 */
	private static function may_request( string $url, array $state ): bool {
		if ( $state['requests'] >= self::MAX_REQUESTS ) {
			return false;
		}
		if ( ( $state['hosts'][ self::host_key( $url ) ] ?? 0 ) >= self::MAX_PER_HOST ) {
			return false;
		}
		return 0 === $state['checks'] || ( (float) ( $state['clock'] )() - $state['started'] ) < self::BUDGET;
	}

	private static function host_key( string $url ): string {
		return strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
	}

	/**
	 * One pinned request; counts toward the bounds.
	 *
	 * @param string               $url    Validated URL.
	 * @param array<int, string>   $ips    Validated addresses.
	 * @param string               $method HEAD or GET.
	 * @param array<string, mixed> $state  Per-call counters (by reference).
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $url, $ips, string $method, array &$state ) {
		++$state['requests'];
		$host                    = self::host_key( $url );
		$state['hosts'][ $host ] = ( $state['hosts'][ $host ] ?? 0 ) + 1;
		$remaining               = self::BUDGET - ( (float) ( $state['clock'] )() - $state['started'] );
		$args                    = [
			'method'     => $method,
			'headers'    => 'GET' === $method ? [ 'Range' => 'bytes=0-' . ( self::GET_BYTES - 1 ) ] : [],
			'user-agent' => self::USER_AGENT,
			'timeout'    => min( Webmastery_MCP_Safe_Http::MAX_TIMEOUT, max( 1, $remaining ) ),
		];
		if ( 'GET' === $method ) {
			$args['limit_response_size'] = self::GET_BYTES;
		}
		return Webmastery_MCP_Safe_Http::pinned_request( $url, (array) $ips, $args );
	}
}
