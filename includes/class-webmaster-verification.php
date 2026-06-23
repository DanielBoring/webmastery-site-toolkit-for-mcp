<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Webmaster_Verification {

	public static function register() {
		wp_register_ability(
			'webmastery-site-toolkit-for-mcp/webmaster-verification-status',
			array(
				'label'               => 'Webmaster Verification Status',
				'description'         => 'Check public Google and Bing webmaster verification signals without Google or Bing API credentials.',
				'category'            => 'webmastery-site-toolkit-for-mcp',
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'permission' ),
				'meta'                => array(
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => false ),
					'mcp'         => array( 'public' => true, 'type' => 'tool' ),
				),
			)
		);
	}

	public static function permission() {
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', 'Requires read capability.' );
		}

		return true;
	}

	public static function execute( $input = array() ) {
		$home_url      = home_url( '/' );
		$home_response = self::request_url( $home_url );
		$home_reached  = self::is_success_response( $home_response );

		$checks = array(
			'google_site_kit'       => self::check_google_site_kit(),
			'google_homepage_meta'  => self::check_meta_tag(
				$home_response,
				'google-site-verification',
				'Google homepage verification meta tag'
			),
			'bing_homepage_meta'    => self::check_meta_tag(
				$home_response,
				'msvalidate.01',
				'Bing homepage verification meta tag'
			),
			'bing_site_auth_xml'    => self::check_bing_site_auth_xml(),
			'dns_txt_verification'  => self::check_dns_txt_records(),
			'robots_txt'            => self::check_robots_txt(),
			'account_verification'  => self::result(
				'unknown',
				'Google Search Console and Bing Webmaster Tools account verification cannot be confirmed from public signals.',
				'API-backed account confirmation would require OAuth/API credentials and a separate security model.'
			),
		);

		$sitemap_checks = self::check_sitemaps( $checks['robots_txt']['sitemap_urls'] );
		$checks         = array_merge( $checks, $sitemap_checks );
		$summary        = self::summarize( $checks );

		return array(
			'success' => true,
			'data'    => array(
				'home_url'    => $home_url,
				'home_reached' => $home_reached,
				'summary'     => $summary,
				'checks'      => $checks,
				'google'      => array(
					'site_kit'             => $checks['google_site_kit'],
					'homepage_meta'        => $checks['google_homepage_meta'],
					'account_verification' => $checks['account_verification'],
				),
				'bing'        => array(
					'homepage_meta'        => $checks['bing_homepage_meta'],
					'site_auth_xml'        => $checks['bing_site_auth_xml'],
					'account_verification' => $checks['account_verification'],
				),
				'dns_txt'     => $checks['dns_txt_verification'],
				'robots_txt'  => $checks['robots_txt'],
				'sitemap_reachability' => $checks['sitemap_reachability'],
				'sitemaps'    => $checks['sitemap_reachability']['items'] ?? array(),
				'limitations' => array(
					'This ability checks public and WordPress-visible proof only.',
					'It does not confirm ownership inside Google Search Console or Bing Webmaster Tools accounts.',
				),
			),
		);
	}

	private static function check_google_site_kit() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = 'google-site-kit/google-site-kit.php';
		$installed   = array_key_exists( $plugin_file, get_plugins() );
		$active      = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );

		if ( $active ) {
			$status  = 'pass';
			$message = 'Google Site Kit is installed and active.';
		} elseif ( $installed ) {
			$status  = 'warn';
			$message = 'Google Site Kit is installed but inactive.';
		} else {
			$status  = 'warn';
			$message = 'Google Site Kit is not installed.';
		}

		return self::result(
			$status,
			$message,
			null,
			array(
				'installed' => $installed,
				'active'    => $active,
				'plugin'    => $plugin_file,
			)
		);
	}

	private static function check_meta_tag( $home_response, $meta_name, $label ) {
		if ( is_wp_error( $home_response['error'] ) ) {
			return self::result(
				'unknown',
				$label . ' could not be checked because the homepage request failed.',
				$home_response['error']->get_error_message()
			);
		}

		if ( ! self::is_success_response( $home_response ) ) {
			return self::result(
				'unknown',
				$label . ' could not be checked because the homepage did not return a successful response.',
				'HTTP status: ' . $home_response['status_code'],
				array( 'status_code' => $home_response['status_code'] )
			);
		}

		$content = self::find_meta_content( $home_response['body'], $meta_name );
		if ( null === $content ) {
			return self::result(
				'warn',
				$label . ' was not found on the rendered homepage.',
				null,
				array( 'found' => false )
			);
		}

		return self::result(
			'pass',
			$label . ' is present on the rendered homepage.',
			null,
			array(
				'found'   => true,
				'content' => $content,
			)
		);
	}

	private static function check_bing_site_auth_xml() {
		$url      = home_url( '/BingSiteAuth.xml' );
		$response = self::request_url( $url, 'HEAD' );

		if ( is_wp_error( $response['error'] ) ) {
			return self::result(
				'unknown',
				'BingSiteAuth.xml reachability could not be checked.',
				$response['error']->get_error_message(),
				array( 'url' => $url )
			);
		}

		if ( self::is_success_response( $response ) ) {
			return self::result(
				'pass',
				'BingSiteAuth.xml is publicly reachable.',
				null,
				array( 'url' => $url, 'status_code' => $response['status_code'] )
			);
		}

		return self::result(
			'warn',
			'BingSiteAuth.xml is not publicly reachable.',
			'HTTP status: ' . $response['status_code'],
			array( 'url' => $url, 'status_code' => $response['status_code'] )
		);
	}

	private static function check_dns_txt_records() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return self::result( 'unknown', 'DNS TXT records could not be checked because the site host is unavailable.' );
		}

		if ( ! function_exists( 'dns_get_record' ) || ! defined( 'DNS_TXT' ) ) {
			return self::result(
				'unknown',
				'DNS TXT records could not be checked because this PHP environment does not support TXT lookups.',
				null,
				array( 'host' => $host )
			);
		}

		$records = dns_get_record( $host, DNS_TXT );
		if ( false === $records ) {
			return self::result(
				'unknown',
				'DNS TXT records could not be read for the site host.',
				null,
				array( 'host' => $host )
			);
		}

		$txt_records = array();
		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) && is_string( $record['txt'] ) ) {
				$txt_records[] = $record['txt'];
			}
		}

		$google_records = array_values(
			array_filter(
				$txt_records,
				static function ( $record ) {
					return str_starts_with( $record, 'google-site-verification=' );
				}
			)
		);
		$bing_records   = array_values(
			array_filter(
				$txt_records,
				static function ( $record ) {
					return 0 === stripos( $record, 'MS=ms' );
				}
			)
		);

		$has_verification_record = ! empty( $google_records ) || ! empty( $bing_records );

		return self::result(
			$has_verification_record ? 'pass' : 'warn',
			$has_verification_record
				? 'Visible DNS TXT webmaster verification records were found.'
				: 'No visible Google or Bing DNS TXT webmaster verification records were found.',
			null,
			array(
				'host'                  => $host,
				'record_count'          => count( $txt_records ),
				'google_records'        => $google_records,
				'bing_records'          => $bing_records,
				'verification_detected' => $has_verification_record,
			)
		);
	}

	private static function check_robots_txt() {
		$url      = home_url( '/robots.txt' );
		$response = self::request_url( $url );

		if ( is_wp_error( $response['error'] ) ) {
			return self::result(
				'unknown',
				'robots.txt could not be checked.',
				$response['error']->get_error_message(),
				array( 'url' => $url, 'sitemap_urls' => array() )
			);
		}

		if ( ! self::is_success_response( $response ) ) {
			return self::result(
				'warn',
				'robots.txt is not publicly reachable.',
				'HTTP status: ' . $response['status_code'],
				array( 'url' => $url, 'status_code' => $response['status_code'], 'sitemap_urls' => array() )
			);
		}

		$sitemap_urls = self::parse_sitemap_urls( $response['body'] );

		return self::result(
			empty( $sitemap_urls ) ? 'warn' : 'pass',
			empty( $sitemap_urls )
				? 'robots.txt is reachable but does not declare sitemap URLs.'
				: 'robots.txt is reachable and declares sitemap URLs.',
			null,
			array(
				'url'          => $url,
				'status_code'  => $response['status_code'],
				'sitemap_urls' => $sitemap_urls,
			)
		);
	}

	private static function check_sitemaps( $sitemap_urls ) {
		if ( empty( $sitemap_urls ) ) {
			return array(
				'sitemap_reachability' => self::result(
					'unknown',
					'Sitemap reachability could not be checked because robots.txt did not declare sitemap URLs.',
					null,
					array( 'items' => array() )
				),
			);
		}

		$items = array();
		foreach ( $sitemap_urls as $url ) {
			$items[] = self::check_sitemap_url( $url );
		}

		$reachable_count = count(
			array_filter(
				$items,
				static function ( $item ) {
					return 'pass' === $item['status'];
				}
			)
		);
		$unknown_count   = count(
			array_filter(
				$items,
				static function ( $item ) {
					return 'unknown' === $item['status'];
				}
			)
		);

		if ( $reachable_count > 0 ) {
			$status  = 'pass';
			$message = 'At least one declared sitemap is reachable.';
		} elseif ( $unknown_count > 0 ) {
			$status  = 'unknown';
			$message = 'Declared sitemap reachability could not be fully checked.';
		} else {
			$status  = 'warn';
			$message = 'No declared sitemaps are reachable.';
		}

		return array(
			'sitemap_reachability' => self::result(
				$status,
				$message,
				null,
				array(
					'items'           => $items,
					'reachable_count' => $reachable_count,
				)
			),
		);
	}

	private static function check_sitemap_url( $url ) {
		$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$sitemap_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $home_host && $sitemap_host && strtolower( $home_host ) !== strtolower( $sitemap_host ) ) {
			return array(
				'url'     => $url,
				'status'  => 'unknown',
				'message' => 'Sitemap URL is declared on a different host, so reachability was not checked.',
			);
		}

		$response = self::request_url( $url, 'HEAD' );
		if ( is_wp_error( $response['error'] ) ) {
			return array(
				'url'     => $url,
				'status'  => 'unknown',
				'message' => $response['error']->get_error_message(),
			);
		}

		return array(
			'url'         => $url,
			'status'      => self::is_success_response( $response ) ? 'pass' : 'warn',
			'status_code' => $response['status_code'],
			'message'     => self::is_success_response( $response )
				? 'Sitemap is reachable.'
				: 'Sitemap is not reachable.',
		);
	}

	private static function request_url( $url, $method = 'GET' ) {
		$args = array(
			'method'      => $method,
			'timeout'     => 5,
			'redirection' => 3,
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'error'       => $response,
				'status_code' => null,
				'body'        => '',
			);
		}

		return array(
			'error'       => null,
			'status_code' => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => (string) wp_remote_retrieve_body( $response ),
		);
	}

	private static function is_success_response( $response ) {
		return isset( $response['status_code'] )
			&& $response['status_code'] >= 200
			&& $response['status_code'] < 300;
	}

	private static function find_meta_content( $html, $name ) {
		if ( '' === $html ) {
			return null;
		}

		$pattern = '/<meta\b(?=[^>]*\bname=["\']' . preg_quote( $name, '/' ) . '["\'])(?=[^>]*\bcontent=["\']([^"\']+)["\'])[^>]*>/i';
		if ( preg_match( $pattern, $html, $matches ) ) {
			return sanitize_text_field( html_entity_decode( $matches[1], ENT_QUOTES ) );
		}

		return null;
	}

	private static function parse_sitemap_urls( $robots_body ) {
		$urls = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $robots_body ) as $line ) {
			if ( preg_match( '/^\s*Sitemap\s*:\s*(\S+)\s*$/i', $line, $matches ) ) {
				$urls[] = esc_url_raw( $matches[1] );
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private static function result( $status, $message, $detail = null, $extra = array() ) {
		$result = array_merge(
			array(
				'status'  => $status,
				'message' => $message,
			),
			$extra
		);

		if ( null !== $detail ) {
			$result['detail'] = $detail;
		}

		return $result;
	}

	private static function summarize( $checks ) {
		$summary = array(
			'pass'    => 0,
			'warn'    => 0,
			'unknown' => 0,
		);

		foreach ( $checks as $check ) {
			$status = $check['status'] ?? 'unknown';
			if ( ! isset( $summary[ $status ] ) ) {
				$status = 'unknown';
			}
			++$summary[ $status ];
		}

		return $summary;
	}
}
