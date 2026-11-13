<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shared `<a href>` extraction and site-membership rules.
 *
 * Used by SEO Analyze Post (link counts) and Scan Broken Links. Pure string
 * handling: no database, filesystem or network access.
 */
final class Webmastery_MCP_Links {

	/**
	 * Anchor tags with a quoted href, in document order.
	 *
	 * @param string $content Stored post content.
	 * @return array<int, array{href: string, end: int}> Decoded, trimmed href and the byte offset just after the opening tag.
	 */
	public static function anchors( $content ): array {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $content, $matches, PREG_OFFSET_CAPTURE );
		$anchors = [];
		foreach ( $matches[1] as $index => $href ) {
			$anchors[] = [
				'href' => trim( html_entity_decode( $href[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'end'  => $matches[0][ $index ][1] + strlen( $matches[0][ $index ][0] ),
			];
		}
		return $anchors;
	}

	/**
	 * Classify one decoded href as a web link, or null when it is not one.
	 *
	 * Non-web schemes (mailto:, tel:, javascript:, data: ...), empty or unparsable
	 * references and host-less network paths return null. Site membership compares
	 * complete hostnames (bare and www. are the same site), not URL prefixes,
	 * schemes or ports; relative references (including #fragments) are internal.
	 *
	 * @param string $href      Decoded href.
	 * @param string $home_host Host from normalize_host() of the site home URL.
	 * @return array{internal: bool, scheme: string, host: string, parts: array<string, mixed>}|null
	 */
	public static function classify( string $href, string $home_host ): ?array {
		$parsed = wp_parse_url( $href );
		if ( '' === $href || false === $parsed ) {
			return null;
		}
		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( '' !== $scheme && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}
		$host = self::normalize_host( $parsed['host'] ?? '' );
		if ( '' === $host && ( '' !== $scheme || str_starts_with( $href, '//' ) ) ) {
			return null;
		}
		return [
			'internal' => '' === $host || ( '' !== $home_host && $host === $home_host ),
			'scheme'   => $scheme,
			'host'     => $host,
			'parts'    => $parsed,
		];
	}

	/**
	 * Lowercase a hostname and drop one leading "www." label.
	 *
	 * @param string $host Hostname.
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Resolve a web reference against an absolute http(s) base URL (RFC 3986 section 5.2).
	 *
	 * The result never carries a fragment or userinfo; default ports are dropped and
	 * scheme and host are lowercased. Returns null when no absolute http(s) URL results.
	 *
	 * @param string $href Decoded href accepted by classify().
	 * @param string $base Absolute base URL (the post permalink).
	 * @return array{url: string, userinfo: bool}|null
	 */
	public static function absolute( string $href, string $base ): ?array {
		$ref  = wp_parse_url( $href );
		$base = wp_parse_url( $base );
		if ( false === $ref || false === $base || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return null;
		}
		if ( isset( $ref['scheme'] ) ) {
			$target = $ref;
		} elseif ( str_starts_with( $href, '//' ) ) {
			$target           = $ref;
			$target['scheme'] = $base['scheme'];
		} else {
			$target = [
				'scheme' => $base['scheme'],
				'host'   => $base['host'],
			];
			if ( isset( $base['port'] ) ) {
				$target['port'] = $base['port'];
			}
			$path = (string) ( $ref['path'] ?? '' );
			if ( '' === $path ) {
				$target['path'] = $base['path'] ?? '/';
				if ( isset( $ref['query'] ) ) {
					$target['query'] = $ref['query'];
				} elseif ( isset( $base['query'] ) ) {
					$target['query'] = $base['query'];
				}
			} else {
				if ( ! str_starts_with( $path, '/' ) ) {
					$base_path = '/' . ltrim( (string) ( $base['path'] ?? '' ), '/' );
					$path      = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 ) . $path;
				}
				$target['path'] = $path;
				if ( isset( $ref['query'] ) ) {
					$target['query'] = $ref['query'];
				}
			}
		}
		$scheme = strtolower( (string) ( $target['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $target['host'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return null;
		}
		$port = isset( $target['port'] ) ? (int) $target['port'] : null;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}
		$url = $scheme . '://' . $host . ( null === $port ? '' : ':' . $port ) . self::remove_dot_segments( (string) ( $target['path'] ?? '' ) );
		if ( isset( $target['query'] ) && '' !== $target['query'] ) {
			$url .= '?' . $target['query'];
		}
		return [
			'url'      => $url,
			'userinfo' => isset( $target['user'] ) || isset( $target['pass'] ),
		];
	}

	/**
	 * RFC 3986 dot-segment removal; an empty path becomes "/".
	 *
	 * @param string $path URL path.
	 */
	private static function remove_dot_segments( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		$output = [];
		foreach ( explode( '/', $path ) as $index => $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( count( $output ) > 1 ) {
					array_pop( $output );
				}
				continue;
			}
			$output[] = 0 === $index ? '' : $segment;
		}
		$last = substr( $path, strrpos( $path, '/' ) + 1 );
		if ( '.' === $last || '..' === $last ) {
			$output[] = '';
		}
		$result = implode( '/', $output );
		return str_starts_with( $result, '/' ) ? $result : '/' . $result;
	}
}
