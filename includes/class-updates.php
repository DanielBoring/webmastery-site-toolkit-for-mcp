<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Updates {

	private const ITEM_LIMIT = 100;

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-updates-overview', [
			'label'               => 'Get Updates Overview',
			'description'         => 'Report available WordPress core, plugin and theme updates from cached update data only (no update check is run), with the time each cache was last refreshed. Sections the caller lacks update_core, update_plugins or update_themes for are omitted and named in omitted_sections.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function permission() {
		if ( current_user_can( 'update_core' ) || current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) ) {
			return true;
		}

		return Webmastery_MCP_Response::local_error( 'forbidden', 'Requires update_core, update_plugins or update_themes capability.' );
	}

	public static function execute( $input = [] ) {
		$data    = [];
		$omitted = [];
		$total   = 0;

		if ( current_user_can( 'update_core' ) ) {
			$data['core'] = self::core_section();
			$total       += true === $data['core']['update_available'] ? 1 : 0;
		} else {
			$omitted[] = 'core';
		}

		if ( current_user_can( 'update_plugins' ) ) {
			$data['plugins'] = self::plugins_section();
			$total          += $data['plugins']['count'];
		} else {
			$omitted[] = 'plugins';
		}

		if ( current_user_can( 'update_themes' ) ) {
			$data['themes'] = self::themes_section();
			$total         += $data['themes']['count'];
		} else {
			$omitted[] = 'themes';
		}

		$data['total_updates']    = $total;
		$data['omitted_sections'] = $omitted;

		return Webmastery_MCP_Response::ok( $data );
	}

	private static function core_section(): array {
		$transient = get_site_transient( 'update_core' );
		$entries   = self::field( $transient, 'updates' );
		$entries   = is_array( $entries ) ? $entries : [];
		$upgrade   = null;
		$known     = false;

		// Same interpretation as the security audit wp_version check.
		foreach ( $entries as $update ) {
			$response = self::field( $update, 'response' );
			if ( ! is_string( $response ) ) {
				continue;
			}
			$known = true;
			if ( 'upgrade' === $response ) {
				$upgrade = $update;
				break;
			}
		}

		return [
			'current_version'  => (string) self::version_token( get_bloginfo( 'version' ) ),
			'update_available' => $known ? null !== $upgrade : null,
			'latest_version'   => null !== $upgrade ? self::version_token( self::field( $upgrade, 'version' ) ) : null,
			'data_cached'      => $known,
			'last_checked'     => self::iso_time( self::field( $transient, 'last_checked' ) ),
		];
	}

	private static function plugins_section(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$transient = get_site_transient( 'update_plugins' );
		$response  = self::field( $transient, 'response' );
		$cached    = is_array( $response );
		$installed = (array) get_plugins();
		$records   = [];

		foreach ( $cached ? $response : [] as $basename => $update ) {
			$basename = (string) $basename;
			if ( ! is_array( $installed[ $basename ] ?? null ) || ( ! is_object( $update ) && ! is_array( $update ) ) ) {
				continue;
			}
			$records[ $basename ] = [
				'plugin'          => $basename,
				'name'            => self::plain_text( $installed[ $basename ]['Name'] ?? null, $basename ),
				'current_version' => self::version_token( $installed[ $basename ]['Version'] ?? null ),
				'new_version'     => self::version_token( self::field( $update, 'new_version' ) ),
			];
		}

		return self::section( $transient, $cached, $records );
	}

	private static function themes_section(): array {
		$transient = get_site_transient( 'update_themes' );
		$response  = self::field( $transient, 'response' );
		$cached    = is_array( $response );
		$records   = [];

		foreach ( $cached ? $response : [] as $stylesheet => $update ) {
			$stylesheet = (string) $stylesheet;
			if ( '' === $stylesheet || ( ! is_object( $update ) && ! is_array( $update ) ) ) {
				continue;
			}
			$theme = wp_get_theme( $stylesheet );
			if ( ! is_object( $theme ) || ! $theme->exists() ) {
				continue;
			}
			$records[ $stylesheet ] = [
				'stylesheet'      => $stylesheet,
				'name'            => self::plain_text( $theme->get( 'Name' ), $stylesheet ),
				'current_version' => self::version_token( $theme->get( 'Version' ) ),
				'new_version'     => self::version_token( self::field( $update, 'new_version' ) ),
			];
		}

		return self::section( $transient, $cached, $records );
	}

	/**
	 * Shared plugin/theme section shape: sorted by key, capped, items marked untrusted.
	 *
	 * @param mixed                               $transient Raw site transient value.
	 * @param bool                                $cached    Whether the transient had a usable response list.
	 * @param array<string, array<string, mixed>> $records Validated items keyed by basename/stylesheet.
	 */
	private static function section( $transient, bool $cached, array $records ): array {
		ksort( $records, SORT_STRING );
		$items = [];
		foreach ( array_slice( $records, 0, self::ITEM_LIMIT ) as $record ) {
			$items[] = Webmastery_MCP_Untrusted::mark( $record, [ 'name' ] );
		}

		return [
			'data_cached'  => $cached,
			'last_checked' => self::iso_time( self::field( $transient, 'last_checked' ) ),
			'count'        => count( $records ),
			'truncated'    => count( $records ) > self::ITEM_LIMIT,
			'items'        => $items,
		];
	}

	/**
	 * Read one property from a transient or record that may be an object or an array.
	 *
	 * @param mixed  $source Object, array or anything else (returns null).
	 * @param string $key   Property or array key.
	 * @return mixed
	 */
	private static function field( $source, string $key ) {
		if ( is_object( $source ) ) {
			return $source->{$key} ?? null;
		}

		return is_array( $source ) ? ( $source[ $key ] ?? null ) : null;
	}

	/**
	 * Unix timestamp to ISO 8601 UTC, or null when absent or not a positive timestamp.
	 *
	 * @param mixed $value Transient last_checked value.
	 */
	private static function iso_time( $value ): ?string {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $value );
	}

	private static function plain_text( $value, string $fallback ): string {
		$text = is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';

		return '' === $text ? $fallback : mb_substr( $text, 0, 200 );
	}

	/**
	 * Accept only a short version-shaped token (no spaces or markup); anything else is null.
	 *
	 * @param mixed $value Header or update-transient value.
	 */
	private static function version_token( $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );

		return 1 === preg_match( '/^[0-9][0-9A-Za-z.+-]{0,31}$/D', $value ) ? $value : null;
	}
}
