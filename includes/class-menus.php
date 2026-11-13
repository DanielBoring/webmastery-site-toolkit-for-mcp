<?php

defined( 'ABSPATH' ) || exit;

/**
 * Classic navigation menus (the nav_menu taxonomy and nav_menu_item posts).
 *
 * Block-theme Navigation blocks (wp_navigation posts) are a different data model and
 * are out of scope. Creating, deleting or moving items, changing an item's linked
 * object and assigning menus to theme locations are out of scope as well.
 */
class Webmastery_MCP_Menus {

	/** Menus returned by list-menus at most. */
	private const MENU_LIMIT = 100;

	/** Ancestor steps walked when checking a new parent for cycles. */
	private const ANCESTOR_LIMIT = 100;

	private const MAX_CLASSES = 20;

	private const TITLE_LIMIT = 200;

	private const DESCRIPTION_LIMIT = 1000;

	private const URL_LIMIT = 2048;

	private const CLASS_LIMIT = 100;

	/** URL schemes accepted for custom links (relative and anchor references have none). */
	private const URL_PROTOCOLS = [ 'http', 'https', 'mailto', 'tel' ];

	/** Fields of update-menu-item that change something. */
	private const UPDATABLE = [ 'title', 'url', 'target', 'attr_title', 'description', 'classes', 'position', 'parent_item_id' ];

	/** Item record fields that hold stored, user-controlled text. */
	private const ITEM_TEXT = [ 'title', 'url', 'attr_title', 'description', 'classes', 'xfn' ];

	public static function register() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-menus', [
			'label'               => 'List Navigation Menus',
			'description'         => 'Read-only list of classic navigation menus (Appearance > Menus) with ID, name, slug, item count and the theme locations each menu is assigned to, plus the theme\'s registered menu locations. Block-theme Navigation blocks (wp_navigation posts) are not included: on a block theme the list can be empty even though the site header has navigation (see block_theme and classic_menus_supported). At most 100 menus are returned (truncated reports a cut-off).',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'list_menus' ],
			'permission_callback' => Webmastery_MCP_Permissions::cap( 'edit_theme_options' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-menu', [
			'label'               => 'Get Navigation Menu',
			'description'         => 'Read-only classic navigation menu with its items in menu order, one page at a time: item ID, title, URL, type (custom, post_type, taxonomy, post_type_archive), linked object and object ID, parent item ID, position, target, title attribute, CSS classes, description, XFN and status. Items that link to a post the caller cannot read are returned with title and url null and restricted true.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'menu_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Navigation menu (nav_menu term) ID from list-menus.' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100 ],
					'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				],
				'required'   => [ 'menu_id' ],
			],
			'execute_callback'    => [ self::class, 'get_menu' ],
			'permission_callback' => Webmastery_MCP_Permissions::cap( 'edit_theme_options' ),
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );

		wp_register_ability( 'webmastery-site-toolkit-for-mcp/update-menu-item', [
			'label'               => 'Update Navigation Menu Item',
			'description'         => 'Update one existing classic navigation menu item: title, url (custom links only; http, https, mailto, tel, or a root-relative, query or #anchor reference), target ("" or "_blank"), attr_title, description, classes, position, or parent_item_id (another item of the same menu, or 0; cycles are rejected). Fields you do not send are kept. Returns the updated item plus previous values of the fields you sent. Does not create, delete or move items between menus, change the linked object, or assign menus to locations.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'menu_item_id'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Menu item ID from get-menu.' ],
					'title'          => [ 'type' => 'string', 'maxLength' => self::TITLE_LIMIT, 'description' => 'Plain-text navigation label. Empty resets a page/category item to its linked object\'s title.' ],
					'url'            => [ 'type' => 'string', 'maxLength' => self::URL_LIMIT, 'description' => 'Custom link items only.' ],
					'target'         => [ 'type' => 'string', 'enum' => [ '', '_blank' ] ],
					'attr_title'     => [ 'type' => 'string', 'maxLength' => self::TITLE_LIMIT ],
					'description'    => [ 'type' => 'string', 'maxLength' => self::DESCRIPTION_LIMIT ],
					'classes'        => [
						'type'     => 'array',
						'maxItems' => self::MAX_CLASSES,
						'items'    => [ 'type' => 'string', 'maxLength' => self::CLASS_LIMIT ],
					],
					'position'       => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Stored menu order. Other items are not renumbered.' ],
					'parent_item_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Parent item in the same menu, or 0 for top level.' ],
				],
				'required'   => [ 'menu_item_id' ],
			],
			'execute_callback'    => [ self::class, 'update_menu_item' ],
			'permission_callback' => Webmastery_MCP_Permissions::cap( 'edit_theme_options' ),
			'meta'                => [
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function list_menus() {
		$assigned   = self::assigned_locations();
		$registered = get_registered_nav_menus();
		$registered = is_array( $registered ) ? $registered : [];

		$menus = wp_get_nav_menus( [ 'number' => self::MENU_LIMIT + 1 ] );
		$menus = is_array( $menus ) ? array_values( array_filter( $menus, static fn( $menu ) => $menu instanceof WP_Term ) ) : [];

		$items = [];
		foreach ( array_slice( $menus, 0, self::MENU_LIMIT ) as $menu ) {
			$items[] = self::menu_record( $menu, $assigned );
		}

		$locations = [];
		foreach ( $registered as $location => $description ) {
			$locations[] = [
				'location'    => (string) $location,
				'description' => is_string( $description ) ? $description : '',
				'menu_id'     => $assigned[ (string) $location ] ?? null,
			];
		}

		return Webmastery_MCP_Response::ok( [
			'items'                   => $items,
			'truncated'               => count( $menus ) > self::MENU_LIMIT,
			'registered_locations'    => $locations,
			'classic_menus_supported' => (bool) current_theme_supports( 'menus' ),
			'block_theme'             => (bool) wp_is_block_theme(),
		] );
	}

	public static function get_menu( $input = [] ) {
		$input    = is_array( $input ) ? $input : [];
		$per_page = Webmastery_MCP_Input::per_page( $input, 100, 200 );
		$page     = Webmastery_MCP_Input::page( $input );

		$menu = self::menu( $input['menu_id'] ?? 0 );
		if ( null === $menu ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Navigation menu not found.' );
		}

		$items = wp_get_nav_menu_items( $menu, [ 'update_menu_item_cache' => true ] );
		$items = is_array( $items ) ? array_values( array_filter( $items, 'is_object' ) ) : [];

		$records = [];
		foreach ( array_slice( $items, ( $page - 1 ) * $per_page, $per_page ) as $item ) {
			$records[] = self::item_record( $item );
		}

		return Webmastery_MCP_Response::ok( self::menu_record( $menu, self::assigned_locations() ) + [
			'items'     => $records,
			'page'      => $page,
			'per_page'  => $per_page,
			'next_page' => count( $items ) > $page * $per_page ? $page + 1 : null,
		] );
	}

	public static function update_menu_item( $input = [] ) {
		$input   = is_array( $input ) ? $input : [];
		$item_id = (int) ( $input['menu_item_id'] ?? 0 );
		$changes = array_values( array_intersect( self::UPDATABLE, array_keys( $input ) ) );
		if ( [] === $changes ) {
			return Webmastery_MCP_Response::legacy_error( 'invalid_input', 'Provide at least one field to update: ' . implode( ', ', self::UPDATABLE ) . '.' );
		}

		$post = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $post instanceof WP_Post || 'nav_menu_item' !== $post->post_type ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Menu item not found.' );
		}
		$menu_id = self::item_menu_id( $item_id );
		if ( null === $menu_id ) {
			return Webmastery_MCP_Response::legacy_error( 'precondition_failed', 'Menu item does not belong to exactly one navigation menu.' );
		}
		if ( ! current_user_can( 'edit_post', $item_id ) ) {
			return Webmastery_MCP_Response::legacy_error( 'forbidden', 'You cannot edit this menu item.' );
		}

		$args      = self::stored_args( $post );
		$is_custom = 'custom' === $args['menu-item-type'];

		foreach ( $changes as $field ) {
			$value = $input[ $field ];
			switch ( $field ) {
				case 'title':
					$title = self::plain_text( $value, self::TITLE_LIMIT );
					if ( null === $title ) {
						return self::invalid( 'title must be plain text of at most ' . self::TITLE_LIMIT . ' characters.' );
					}
					if ( '' === $title && $is_custom ) {
						return self::invalid( 'title must not be empty for a custom link item.' );
					}
					$args['menu-item-title'] = $title;
					break;
				case 'url':
					if ( ! $is_custom ) {
						return self::invalid( "url can only be changed on custom link items; this item links to a {$args['menu-item-type']} object. Change the linked page, post or term instead." );
					}
					$url = is_string( $value ) ? self::safe_url( $value ) : null;
					if ( null === $url ) {
						return Webmastery_MCP_Response::legacy_error( 'invalid_url', 'url must be an http, https, mailto or tel URL, or a relative reference starting with a single "/", "?" or "#", without whitespace, control characters or backslashes.' );
					}
					$args['menu-item-url'] = $url;
					break;
				case 'target':
					if ( ! in_array( $value, [ '', '_blank' ], true ) ) {
						return self::invalid( 'target must be "" or "_blank".' );
					}
					$args['menu-item-target'] = $value;
					break;
				case 'attr_title':
					$attr_title = self::plain_text( $value, self::TITLE_LIMIT );
					if ( null === $attr_title ) {
						return self::invalid( 'attr_title must be plain text of at most ' . self::TITLE_LIMIT . ' characters.' );
					}
					$args['menu-item-attr-title'] = $attr_title;
					break;
				case 'description':
					$description = self::plain_text( $value, self::DESCRIPTION_LIMIT, true );
					if ( null === $description ) {
						return self::invalid( 'description must be plain text of at most ' . self::DESCRIPTION_LIMIT . ' characters.' );
					}
					$args['menu-item-description'] = $description;
					break;
				case 'classes':
					$classes = self::classes( $value );
					if ( null === $classes ) {
						return self::invalid( 'classes must be an array of at most ' . self::MAX_CLASSES . ' strings of at most ' . self::CLASS_LIMIT . ' characters.' );
					}
					$args['menu-item-classes'] = implode( ' ', $classes );
					break;
				case 'position':
					if ( ! is_int( $value ) || $value < 1 ) {
						return self::invalid( 'position must be an integer of at least 1.' );
					}
					$args['menu-item-position'] = $value;
					break;
				case 'parent_item_id':
					$parent_error = self::parent_error( $value, $item_id, $menu_id );
					if ( null !== $parent_error ) {
						return Webmastery_MCP_Response::legacy_error( 'invalid_parent', $parent_error );
					}
					$args['menu-item-parent-id'] = $value;
					break;
			}
		}

		$before = self::item_record( wp_setup_nav_menu_item( $post ) );

		// wp_update_nav_menu_item() resets every omitted field to its default, so every stored value is passed back.
		$result = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			return Webmastery_MCP_Response::from_wp_error( $result );
		}
		if ( (int) $result !== $item_id ) {
			return Webmastery_MCP_Response::legacy_error( 'update_failed', 'Failed to update menu item.' );
		}

		$updated = get_post( $item_id );
		if ( ! $updated instanceof WP_Post ) {
			return Webmastery_MCP_Response::legacy_error( 'not_found', 'Menu item not found after update.' );
		}

		$previous = [];
		foreach ( $changes as $field ) {
			$previous[ $field ] = $before[ $field ];
		}

		return Webmastery_MCP_Response::ok( self::item_record( wp_setup_nav_menu_item( $updated ) ) + [
			'menu_id'  => $menu_id,
			'previous' => Webmastery_MCP_Untrusted::mark( $previous, self::ITEM_TEXT ),
		] );
	}

	/**
	 * Resolve a menu by term ID only (wp_get_nav_menu_object() would also match names and slugs).
	 *
	 * @param mixed $menu_id Requested menu ID.
	 */
	private static function menu( $menu_id ): ?WP_Term {
		if ( ! is_int( $menu_id ) || $menu_id < 1 ) {
			return null;
		}
		$menu = get_term( $menu_id, 'nav_menu' );
		return $menu instanceof WP_Term && 'nav_menu' === $menu->taxonomy ? $menu : null;
	}

	/**
	 * Registered theme locations mapped to an assigned menu ID (stale or empty assignments omitted).
	 *
	 * @return array<string, int>
	 */
	private static function assigned_locations(): array {
		$registered = get_registered_nav_menus();
		$locations  = get_nav_menu_locations();
		$assigned   = [];
		if ( ! is_array( $registered ) || ! is_array( $locations ) ) {
			return $assigned;
		}
		foreach ( $locations as $location => $menu_id ) {
			if ( array_key_exists( $location, $registered ) && is_numeric( $menu_id ) && (int) $menu_id > 0 ) {
				$assigned[ (string) $location ] = (int) $menu_id;
			}
		}
		return $assigned;
	}

	/**
	 * Public menu record with the registered locations it is assigned to.
	 *
	 * @param WP_Term            $menu     Menu term.
	 * @param array<string, int> $assigned Location to menu ID map.
	 */
	private static function menu_record( WP_Term $menu, array $assigned ): array {
		$locations = array_keys( array_filter( $assigned, static fn( $menu_id ) => (int) $menu->term_id === $menu_id ) );
		sort( $locations, SORT_STRING );
		return Webmastery_MCP_Untrusted::mark( [
			'id'         => (int) $menu->term_id,
			'name'       => (string) $menu->name,
			'slug'       => (string) $menu->slug,
			'item_count' => (int) $menu->count,
			'locations'  => array_map( 'strval', $locations ),
		], [ 'name', 'slug' ] );
	}

	/**
	 * Public item record. Items linking to a post the caller cannot read never expose its title or URL.
	 *
	 * @param object $item Item decorated by wp_setup_nav_menu_item().
	 */
	private static function item_record( $item ): array {
		$id         = (int) ( $item->db_id ?? $item->ID ?? 0 );
		$type       = (string) ( $item->type ?? '' );
		$object_id  = (int) ( $item->object_id ?? 0 );
		$restricted = 'post_type' === $type && ! current_user_can( 'read_post', $object_id );
		$classes    = array_values( array_filter( array_map( 'strval', array_filter( (array) ( $item->classes ?? [] ), 'is_scalar' ) ), static fn( $value ) => '' !== $value ) );
		$position   = get_post_field( 'menu_order', $id );

		return Webmastery_MCP_Untrusted::mark( [
			'id'             => $id,
			'title'          => $restricted ? null : (string) ( $item->title ?? '' ),
			'url'            => $restricted ? null : (string) ( $item->url ?? '' ),
			'type'           => $type,
			'object'         => (string) ( $item->object ?? '' ),
			'object_id'      => $object_id,
			'parent_item_id' => (int) ( $item->menu_item_parent ?? 0 ),
			'position'       => is_numeric( $position ) ? (int) $position : 0,
			'target'         => (string) ( $item->target ?? '' ),
			'attr_title'     => (string) ( $item->attr_title ?? '' ),
			'classes'        => $classes,
			'description'    => (string) ( $item->description ?? '' ),
			'xfn'            => (string) ( $item->xfn ?? '' ),
			'status'         => (string) ( $item->post_status ?? '' ),
			'restricted'     => $restricted,
		], self::ITEM_TEXT );
	}

	/**
	 * The single menu an item belongs to, or null when it belongs to none or several.
	 *
	 * @param int $item_id Menu item post ID.
	 */
	private static function item_menu_id( int $item_id ): ?int {
		$terms = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );
		if ( ! is_array( $terms ) || 1 !== count( $terms ) ) {
			return null;
		}
		$menu_id = (int) reset( $terms );
		return $menu_id > 0 ? $menu_id : null;
	}

	/**
	 * Every stored value of an item, in the shape wp_update_nav_menu_item() expects (unslashed).
	 *
	 * @param WP_Post $post Menu item post.
	 * @return array<string, mixed>
	 */
	private static function stored_args( WP_Post $post ): array {
		$id      = (int) $post->ID;
		$classes = get_post_meta( $id, '_menu_item_classes', true );
		$classes = is_array( $classes ) ? $classes : ( is_string( $classes ) ? explode( ' ', $classes ) : [] );
		$classes = array_values( array_filter( array_map( 'strval', array_filter( $classes, 'is_scalar' ) ), static fn( $value ) => '' !== $value ) );

		return [
			'menu-item-db-id'       => $id,
			'menu-item-object-id'   => (int) get_post_meta( $id, '_menu_item_object_id', true ),
			'menu-item-object'      => (string) get_post_meta( $id, '_menu_item_object', true ),
			'menu-item-parent-id'   => (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ),
			'menu-item-position'    => (int) $post->menu_order,
			'menu-item-type'        => (string) get_post_meta( $id, '_menu_item_type', true ),
			'menu-item-title'       => (string) $post->post_title,
			'menu-item-url'         => (string) get_post_meta( $id, '_menu_item_url', true ),
			'menu-item-description' => (string) $post->post_content,
			'menu-item-attr-title'  => (string) $post->post_excerpt,
			'menu-item-target'      => (string) get_post_meta( $id, '_menu_item_target', true ),
			'menu-item-classes'     => implode( ' ', $classes ),
			'menu-item-xfn'         => (string) get_post_meta( $id, '_menu_item_xfn', true ),
			'menu-item-status'      => (string) $post->post_status,
		];
	}

	/**
	 * Plain text bounded by character count, or null when the value is not acceptable.
	 *
	 * @param mixed $value     Raw value.
	 * @param int   $limit     Maximum characters.
	 * @param bool  $multiline Keep line breaks (descriptions).
	 */
	private static function plain_text( $value, int $limit, bool $multiline = false ): ?string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $limit ) {
			return null;
		}
		return $multiline ? trim( wp_strip_all_tags( $value ) ) : sanitize_text_field( $value );
	}

	/**
	 * Sanitized class tokens, or null when the value is not acceptable.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>|null
	 */
	private static function classes( $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_CLASSES || array_values( $value ) !== $value ) {
			return null;
		}
		$classes = [];
		foreach ( $value as $class ) {
			if ( ! is_string( $class ) || mb_strlen( $class ) > self::CLASS_LIMIT ) {
				return null;
			}
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}
		return array_values( array_unique( $classes ) );
	}

	/**
	 * A safe custom-link URL, or null. Only http, https, mailto and tel schemes, or a relative
	 * reference starting with a single "/", "?" or "#"; protocol-relative URLs are rejected.
	 *
	 * @param string $url Raw URL.
	 */
	private static function safe_url( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > self::URL_LIMIT || 1 === preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $url, $matches ) ) {
			$scheme = strtolower( $matches[1] );
			if ( ! in_array( $scheme, self::URL_PROTOCOLS, true ) || '' === $matches[2] ) {
				return null;
			}
			if ( in_array( $scheme, [ 'http', 'https' ], true ) ) {
				// Require "//host" with a non-empty host and no userinfo ("https://trusted@evil.example").
				if ( 0 !== strpos( $matches[2], '//' ) ) {
					return null;
				}
				$authority = (string) preg_replace( '/[\/?#].*$/s', '', substr( $matches[2], 2 ) );
				$host      = (string) preg_replace( '/:\d*$/', '', $authority );
				if ( '' === $host || false !== strpos( $authority, '@' ) ) {
					return null;
				}
			}
		} elseif ( ! in_array( $url[0], [ '/', '?', '#' ], true ) || 0 === strpos( $url, '//' ) ) {
			return null;
		}
		$clean = esc_url_raw( $url, self::URL_PROTOCOLS );
		return is_string( $clean ) && '' !== $clean ? $clean : null;
	}

	/**
	 * Why a new parent is not acceptable, or null.
	 *
	 * @param mixed $parent_id Requested parent item ID.
	 * @param int   $item_id   Item being updated.
	 * @param int   $menu_id   The item's menu.
	 */
	private static function parent_error( $parent_id, int $item_id, int $menu_id ): ?string {
		if ( ! is_int( $parent_id ) || $parent_id < 0 ) {
			return 'parent_item_id must be an integer of at least 0.';
		}
		if ( 0 === $parent_id ) {
			return null;
		}
		if ( $parent_id === $item_id ) {
			return 'A menu item cannot be its own parent.';
		}
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post || 'nav_menu_item' !== $parent->post_type || self::item_menu_id( $parent_id ) !== $menu_id ) {
			return 'parent_item_id must be another item of the same navigation menu.';
		}
		$current = $parent_id;
		for ( $step = 0; $step < self::ANCESTOR_LIMIT; ++$step ) {
			$current = (int) get_post_meta( $current, '_menu_item_menu_item_parent', true );
			if ( 0 === $current ) {
				return null;
			}
			if ( $current === $item_id ) {
				return 'parent_item_id would create a cycle: the requested parent is a descendant of this item.';
			}
		}
		return 'parent_item_id is nested too deeply (or its ancestors already form a cycle).';
	}

	private static function invalid( string $message ): array {
		return Webmastery_MCP_Response::legacy_error( 'invalid_input', $message );
	}
}
