<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Users {

	public static function register() {
		self::register_list();
		self::register_get();
		self::register_user_access_audit();
	}

	private static function normalize( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( $user );
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

	public static function audit_permission() {
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error( 'forbidden', 'Requires edit_users capability.' );
		}

		return true;
	}

	private static function normalize_admin_account( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( $user );
		if ( ! $user ) {
			return null;
		}

		return [
			'id'         => (int) $user->ID,
			'login'      => $user->user_login,
			'email'      => $user->user_email,
			'registered' => $user->user_registered,
			'last_login' => self::get_last_login( (int) $user->ID ),
		];
	}

	private static function get_last_login( $user_id ) {
		$meta_keys = [
			'last_login',
			'last_login_at',
			'last_login_time',
			'wp-last-login',
			'wfls-last-login',
		];

		foreach ( $meta_keys as $meta_key ) {
			$value = get_user_meta( $user_id, $meta_key, true );
			if ( '' !== $value ) {
				return is_numeric( $value ) ? gmdate( 'c', (int) $value ) : (string) $value;
			}
		}

		return null;
	}

	private static function normalize_application_password( $user, $password ) {
		$last_used = $password['last_used'] ?? null;
		if ( is_numeric( $last_used ) ) {
			$last_used = gmdate( 'c', (int) $last_used );
		}

		return [
			'user_id'    => (int) $user->ID,
			'user_login' => $user->user_login,
			'app_name'   => (string) ( $password['name'] ?? '' ),
			'last_used'  => $last_used ? (string) $last_used : null,
		];
	}

	private static function register_list() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/list-users', [
			'label'               => 'List Users',
			'description'         => 'List WordPress users with optional role, search, and pagination filters.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/get-user', [
			'label'               => 'Get User',
			'description'         => 'Get a single WordPress user by ID.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
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

	private static function register_user_access_audit() {
		wp_register_ability( 'webmastery-site-toolkit-for-mcp/user-access-audit', [
			'label'               => 'User Access Audit',
			'description'         => 'Audit administrator accounts, default admin username usage, and issued application passwords.',
			'category'            => 'webmastery-site-toolkit-for-mcp',
			'execute_callback'    => [ self::class, 'execute_user_access_audit' ],
			'permission_callback' => [ self::class, 'audit_permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				'mcp'         => [ 'public' => true, 'type' => 'tool' ],
			],
		] );
	}

	public static function execute_user_access_audit( $input = [] ) {
		$query        = new WP_User_Query(
			[
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);
		$admin_users  = $query->get_results();
		$admin_count  = count( $admin_users );
		$admin_exists = (bool) get_user_by( 'login', 'admin' );

		$application_passwords = [];
		$passwords_skipped     = false;
		$passwords_skip_reason = null;

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			$passwords_skipped     = true;
			$passwords_skip_reason = 'WP_Application_Passwords is unavailable on this WordPress installation.';
		} elseif ( ! current_user_can( 'edit_users' ) ) {
			$passwords_skipped     = true;
			$passwords_skip_reason = 'Application passwords require edit_users capability.';
		} else {
			foreach ( $admin_users as $admin_user ) {
				$passwords = WP_Application_Passwords::get_user_application_passwords( (int) $admin_user->ID );
				foreach ( $passwords as $password ) {
					$application_passwords[] = self::normalize_application_password( $admin_user, $password );
				}
			}
		}

		$warnings = [];
		if ( $admin_exists ) {
			$warnings[] = "Username 'admin' exists — common brute-force target";
		}
		if ( $admin_count > 0 ) {
			$warnings[] = sprintf( '%d administrator account(s) detected — review whether all require full admin access', $admin_count );
		}
		if ( $application_passwords ) {
			$warnings[] = sprintf( '%d application password(s) issued to administrator account(s) — review and revoke unused credentials', count( $application_passwords ) );
		}
		if ( $passwords_skipped ) {
			$warnings[] = $passwords_skip_reason;
		}

		return [
			'success' => true,
			'data'    => [
				'admin_accounts'                => array_values( array_filter( array_map( [ self::class, 'normalize_admin_account' ], $admin_users ) ) ),
				'admin_count'                   => $admin_count,
				'default_admin_username_exists' => $admin_exists,
				'application_passwords'         => $application_passwords,
				'warnings'                      => $warnings,
				'metadata'                      => [
					'application_passwords_skipped'     => $passwords_skipped,
					'application_passwords_skip_reason' => $passwords_skip_reason,
					'required_capability'                => 'edit_users',
				],
			],
		];
	}
}
