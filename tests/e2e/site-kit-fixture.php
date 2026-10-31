<?php
/**
 * Plugin Name: Webmastery MCP Google Site Kit REST Fixture
 * Description: Controlled Google Site Kit REST contract fixture for E2E QA.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
	define( 'GOOGLESITEKIT_VERSION', '1.183.0' );
}

function webmastery_mcp_e2e_site_kit_admin_permission() {
	return current_user_can( 'manage_options' );
}

function webmastery_mcp_e2e_site_kit_dashboard_permission( $request = null ) {
	do_action( 'wstm125_site_kit_permission_called' );
	if ( $request instanceof WP_REST_Request && '/google-site-kit/v1/modules/pagespeed-insights/data/pagespeed' === $request->get_route()
		&& ( ! in_array( $request->get_param( 'strategy' ), array( 'mobile', 'desktop' ), true ) || ! $request->get_param( 'url' ) ) ) {
		return new WP_Error( 'fixture_invalid_pagespeed_params', 'PageSpeed permission received missing or invalid normalized parameters.' );
	}
	return apply_filters( 'wstm125_site_kit_permission', current_user_can( 'edit_posts' ) || current_user_can( 'wstm125_site_kit_shared' ) );
}

function webmastery_mcp_e2e_site_kit_modules() {
	return [
		[
			'slug'           => 'search-console',
			'name'           => 'Search Console',
			'description'    => 'Fixture module description.',
			'homepage'       => 'https://search.google.com/search-console',
			'internal'       => false,
			'order'          => 1,
			'forceActive'    => false,
			'recoverable'    => false,
			'shareable'      => true,
			'active'         => true,
			'connected'      => true,
			'disconnectedAt' => null,
			'dependencies'   => [],
			'dependants'     => [ 'analytics-4' ],
			'owner'          => [ 'id' => 1, 'login' => 'admin' ],
		],
		[
			'slug'           => 'pagespeed-insights',
			'name'           => 'PageSpeed Insights',
			'description'    => 'Fixture module description.',
			'homepage'       => 'https://pagespeed.web.dev/',
			'internal'       => false,
			'order'          => 2,
			'forceActive'    => true,
			'recoverable'    => false,
			'shareable'      => true,
			'active'         => true,
			'connected'      => true,
			'disconnectedAt' => 1704067200,
			'dependencies'   => [],
			'dependants'     => [],
			'owner'          => [ 'id' => 1, 'login' => 'admin' ],
		],
	];
}

add_action( 'rest_api_init', function () {
	$namespace = 'google-site-kit/v1';

	register_rest_route(
		$namespace,
		'/core/site/data/connection',
		[
			'methods'             => 'GET',
			'permission_callback' => 'webmastery_mcp_e2e_site_kit_admin_permission',
			'callback'            => static function () {
				return [
					'connected'          => true,
					'resettable'         => true,
					'setupCompleted'     => true,
					'hasConnectedAdmins' => true,
					'hasMultipleAdmins'  => false,
				];
			},
		]
	);

	register_rest_route(
		$namespace,
		'/core/user/data/authentication',
		[
			'methods'             => 'GET',
			'permission_callback' => 'webmastery_mcp_e2e_site_kit_dashboard_permission',
			'callback'            => static function () {
				return [
					'authenticated'         => true,
					'requiredScopes'        => [ 'openid', 'https://www.googleapis.com/auth/analytics.readonly' ],
					'grantedScopes'         => [ 'openid', 'https://www.googleapis.com/auth/analytics.readonly' ],
					'unsatisfiedScopes'     => [],
					'needsReauthentication' => false,
					'disconnectedReason'    => 'fixture_reason',
					'connectedProxyURL'     => 'https://sitekit.withgoogle.com/proxy-fixture',
				];
			},
		]
	);

	register_rest_route(
		$namespace,
		'/core/modules/data/list',
		[
			'methods'             => 'GET',
			'permission_callback' => 'webmastery_mcp_e2e_site_kit_dashboard_permission',
			'callback'            => 'webmastery_mcp_e2e_site_kit_modules',
		]
	);

	register_rest_route(
		$namespace,
		'/core/user/data/permissions',
		[
			'methods'             => 'GET',
			'permission_callback' => 'webmastery_mcp_e2e_site_kit_dashboard_permission',
			'callback'            => static function () {
				return [
					'googlesitekit_authenticate'                                      => false,
					'googlesitekit_setup'                                             => false,
					'googlesitekit_view_posts_insights'                               => true,
					'googlesitekit_view_dashboard'                                    => true,
					'googlesitekit_manage_options'                                    => false,
					'googlesitekit_update_plugins'                                    => false,
					'googlesitekit_view_splash'                                       => false,
					'googlesitekit_view_authenticated_dashboard'                      => false,
					'googlesitekit_view_wp_dashboard_widget'                          => true,
					'googlesitekit_view_admin_bar_menu'                               => true,
					'googlesitekit_view_shared_dashboard'                             => true,
					'googlesitekit_read_shared_module_data::["pagespeed-insights"]'   => true,
					'googlesitekit_manage_module_sharing_options::["pagespeed-insights"]' => false,
					'googlesitekit_delegate_module_sharing_management::["pagespeed-insights"]' => false,
				];
			},
		]
	);

	register_rest_route(
		$namespace,
		'/modules/pagespeed-insights/data/pagespeed',
		[
			'methods'             => 'GET',
			'permission_callback' => 'webmastery_mcp_e2e_site_kit_dashboard_permission',
			'callback'            => static function ( WP_REST_Request $request ) {
				$url = esc_url_raw( $request->get_param( 'url' ) );
				$strategy = $request->get_param( 'strategy' );
				if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) || '' === $url ) {
					throw new RuntimeException( 'PageSpeed dispatch received missing or invalid normalized parameters.' );
				}
				return (object) [
					'id'                   => $url,
					'analysisUTCTimestamp' => '2026-07-11T12:00:00.000Z',
					'loadingExperience'    => [
						'overall_category' => 'AVERAGE',
						'metrics'          => [
							'LARGEST_CONTENTFUL_PAINT_MS'   => [ 'percentile' => 4100, 'category' => 'SLOW', 'distributions' => [ [ 'proportion' => 1 ] ] ],
							'CUMULATIVE_LAYOUT_SHIFT_SCORE' => [ 'percentile' => 13, 'category' => 'AVERAGE', 'distributions' => [ [ 'proportion' => 1 ] ] ],
							'INTERACTION_TO_NEXT_PAINT'     => [ 'percentile' => 51, 'category' => 'FAST', 'distributions' => [ [ 'proportion' => 1 ] ] ],
						],
					],
					'originLoadingExperience' => [ 'overall_category' => 'FAST' ],
					'lighthouseResult' => [
						'userAgent'   => 'Sensitive fixture agent',
						'environment' => [ 'hostUserAgent' => 'Sensitive fixture host' ],
						'categories'  => [
							'performance'    => [ 'score' => 'desktop' === $strategy ? 0.99 : 0.98, 'title' => 'Performance' ],
							'accessibility'  => [ 'score' => 0.91, 'title' => 'Accessibility' ],
							'best-practices' => [ 'score' => 0.96, 'title' => 'Best Practices' ],
							'seo'            => [ 'score' => 0.93, 'title' => 'SEO' ],
						],
						'audits'      => [
							'largest-contentful-paint' => [
								'score'        => 0.72,
								'numericValue' => 4100,
								'numericUnit'  => 'millisecond',
								'displayValue' => '4.1 s',
								'description'  => 'Large fixture description that must not be exposed.',
								'details'      => [ 'items' => [ [ 'url' => 'https://third-party.example/' ] ] ],
							],
						],
						'i18n'         => [ 'rendererFormattedStrings' => [ 'varianceDisclaimer' => 'Sensitive fixture string' ] ],
						'entities'     => [ [ 'name' => 'Third party fixture', 'origins' => [ 'https://third-party.example/' ] ] ],
						'fullPageScreenshot' => [
							'screenshot' => [ 'data' => 'data:image/webp;base64,c2Vuc2l0aXZlLWZpeHR1cmU=' ],
						],
					],
				];
			},
		]
	);
} );
