<?php

defined( 'ABSPATH' ) || exit;

class Unlock_MCP_Users {

	public static function register() {
		self::register_list();
		self::register_get();
	}

	private static function normalize( $user ) {
		$user = get_userdata( $user );
		if ( ! $user ) {
			return null;
		}

		return [
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'nicename'     => $user->user_nicename,
			'email'        => $user->user_email,
			'url'          => $user->user_url,
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
			'registered'   => $user->user_registered,
		];
	}

	public static function permission() {
		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'forbidden', 'Requires list_users capability.' );
		}

		return true;
	}

	private static function register_list() {
		wp_register_ability( 'unlock-mcp-potential/list-users', [
			'label'               => 'List Users',
			'description'         => 'List WordPress users with optional role, search, and pagination filters.',
			'category'            => 'unlock-mcp-potential',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'role'     => [ 'type' => 'string', 'description' => 'Filter by role slug (for example: administrator, editor, author)' ],
					'search'   => [ 'type' => 'string', 'description' => 'Searches login, email, URL, and display name' ],
					'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'orderby'  => [ 'type' => 'string', 'enum' => [ 'ID', 'login', 'nicename', 'email', 'url', 'registered', 'display_name' ], 'default' => 'ID' ],
					'order'    => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
				],
			],
			'execute_callback'    => function ( $input ) {
				$per_page = min( (int) ( $input['per_page'] ?? 20 ), 100 );
				$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

				$args = [
					'number'      => $per_page,
					'offset'      => ( $page - 1 ) * $per_page,
					'count_total' => true,
					'orderby'     => $input['orderby'] ?? 'ID',
					'order'       => strtoupper( $input['order'] ?? 'DESC' ),
				];

				if ( ! empty( $input['role'] ) ) {
					$args['role'] = sanitize_key( $input['role'] );
				}

				if ( ! empty( $input['search'] ) ) {
					$search = trim( sanitize_text_field( $input['search'] ), '*' );
					if ( '' !== $search ) {
						$args['search'] = '*' . $search . '*';
					}
				}

				$query = new WP_User_Query( $args );
				$users = $query->get_results();
				$total = (int) $query->get_total();

				return [
					'success' => true,
					'data'    => [
						'items'       => array_values( array_filter( array_map( [ self::class, 'normalize' ], $users ) ) ),
						'total'       => $total,
						'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
					],
				];
			},
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	private static function register_get() {
		wp_register_ability( 'unlock-mcp-potential/get-user', [
			'label'               => 'Get User',
			'description'         => 'Get a single WordPress user by ID.',
			'category'            => 'unlock-mcp-potential',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'user_id' => [ 'type' => 'integer', 'description' => 'User ID' ],
				],
				'required'   => [ 'user_id' ],
			],
			'execute_callback'    => function ( $input ) {
				$user = get_user_by( 'id', absint( $input['user_id'] ) );

				if ( ! $user ) {
					return [ 'success' => false, 'error' => 'User not found.' ];
				}

				return [ 'success' => true, 'data' => self::normalize( $user ) ];
			},
			'permission_callback' => [ self::class, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}
}
