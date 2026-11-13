<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Themes {

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-themes', [
			'label'               => 'List Themes',
			'description'         => 'List installed themes sorted by stylesheet, with the active theme, its parent, block-theme support, header version requirements and, for callers who can update themes, cached update availability. Follow next_page until it is null.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
					'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				],
			],
			'execute_callback'    => [ self::class, 'execute_list_themes' ],
			'permission_callback' => Webmastery_MCP_Permissions::cap( 'switch_themes' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_list_themes( $input = [] ) {
		$per_page = Webmastery_MCP_Input::per_page( $input, 50 );
		$page     = Webmastery_MCP_Input::page( $input );

		// errors => null includes broken themes; allowed => true limits multisite to the current site's themes.
		$themes = [];
		foreach ( (array) wp_get_themes( [ 'errors' => null, 'allowed' => true ] ) as $stylesheet => $theme ) {
			$themes[ (string) $stylesheet ] = $theme;
		}
		ksort( $themes, SORT_STRING );

		$active = (string) get_stylesheet();
		$parent = (string) get_template();
		$parent = $parent !== $active ? $parent : null;

		$updates = null;
		$cached  = false;
		if ( current_user_can( 'update_themes' ) ) {
			$transient = get_site_transient( 'update_themes' );
			$cached    = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response );
			$updates   = $cached ? $transient->response : [];
		}

		$window = array_slice( $themes, ( $page - 1 ) * $per_page, $per_page, true );
		$items  = [];
		foreach ( $window as $stylesheet => $theme ) {
			$items[] = self::normalize_theme( (string) $stylesheet, $theme, $active, $parent, $updates );
		}

		$data = [
			'items'             => $items,
			'page'              => $page,
			'per_page'          => $per_page,
			'next_page'         => count( $themes ) > $page * $per_page ? $page + 1 : null,
			'active_stylesheet' => $active,
			'parent_stylesheet' => $parent,
		];
		if ( null !== $updates ) {
			$data['update_data_cached'] = $cached;
		}

		return Webmastery_MCP_Response::ok( $data );
	}

	private static function normalize_theme( string $stylesheet, $theme, string $active, ?string $active_parent, ?array $updates ): array {
		$state = [
			'stylesheet'          => $stylesheet,
			'is_active'           => $stylesheet === $active,
			'is_parent_of_active' => null !== $active_parent && $stylesheet === $active_parent,
		];

		// A broken theme exposes no header data and never its error text.
		if ( ! $theme instanceof WP_Theme || false !== $theme->errors() ) {
			return $state + [ 'broken' => true ];
		}

		$template = (string) $theme->get_template();
		$item     = $state + [
			'broken'         => false,
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'author'         => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'parent'         => '' !== $template && $template !== $stylesheet ? $template : null,
			'is_block_theme' => (bool) $theme->is_block_theme(),
			'requires_wp'    => self::version_token( $theme->get( 'RequiresWP' ) ),
			'requires_php'   => self::version_token( $theme->get( 'RequiresPHP' ) ),
		];

		if ( null !== $updates ) {
			$update                   = $updates[ $stylesheet ] ?? null;
			$update                   = is_object( $update ) ? (array) $update : $update;
			$item['update_available'] = is_array( $update );
			$item['new_version']      = is_array( $update ) ? self::version_token( $update['new_version'] ?? null ) : null;
		}

		return Webmastery_MCP_Untrusted::mark( $item, [ 'name', 'version', 'author' ] );
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
