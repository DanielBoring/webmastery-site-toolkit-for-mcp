<?php

declare(strict_types=1);

require_once __DIR__ . '/product-runtime-context.php';

final class Wstm167_Fresh_Default_Count_Refusal extends RuntimeException {
	private array $diagnostic;

	public function __construct( string $table, int $expected, int $actual ) {
		$this->diagnostic = array( 'table' => $table, 'expected' => $expected, 'actual' => $actual );
		parent::__construct( "Fresh installation refused: foreign or missing bootstrap dependency; table={$table}, expected={$expected}, actual={$actual}." );
	}

	public function diagnostic(): array { return $this->diagnostic; }
}

final class Wstm167_Fresh_Install {
	private const TABLES = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
		'comments', 'commentmeta', 'users', 'usermeta', 'options' );
	private array $pins = array();
	private array $inserts = array();
	private array $retained = array();
	private ?array $preservation_diagnostic = null;
	private array $query_results = array();
	private ?array $pending_query = null;
	private ?array $calendar = null;
	private ?array $calendar_pending = null;
	private ?array $calendar_row = null;
	private ?string $calendar_sql = null;
	private ?int $deleting = null;
	private $db;

	public static function check( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Fresh installation refused: ' . $reason . '.' ); }
	}

	public function __construct( $db ) {
		$this->db = $db;
		foreach ( self::TABLES as $name ) {
			self::check( is_string( $db->$name ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $db->$name ),
				'native table identity' );
		}
	}

	public static function owner(): array {
		$run = getenv( 'WSTM_PRODUCT_RUN' ); $token = getenv( 'WSTM_PRODUCT_TOKEN' );
		self::check( is_string( $run ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $run )
			&& is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $token )
			&& substr( $token, 0, 32 ) === $run, 'fresh owner tuple' );
		return array( 'run' => $run, 'token' => $token );
	}

	public function preinstall(): array {
		$allowed = $this->db->tables( 'all', true );
		self::check( is_array( $allowed ) && count( $allowed ) <= 32, 'native table domain' );
		foreach ( $allowed as $table ) {
			self::check( is_string( $table ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table ), 'native table identifier' );
		}
		$tables = $this->db->get_col( 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME LIMIT 33' );
		self::check( '' === $this->db->last_error && is_array( $tables ) && count( $tables ) <= 32
			&& array() === array_diff( $tables, $allowed ), 'foreign or unreadable pre-install table domain' );
		$before = array();
		foreach ( $tables as $table ) {
			$count = $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
			self::check( '' === $this->db->last_error && ( '0' === $count || 0 === $count ), 'nonempty pre-install table' );
			$before[ $table ] = 0;
		}
		return $before;
	}

	public function pin_native(): void {
		foreach ( array( 'wp-admin/includes/upgrade.php', 'wp-admin/includes/schema.php',
			'wp-admin/includes/class-wp-privacy-policy-content.php', 'wp-includes/class-wpdb.php',
			'wp-includes/post.php', 'wp-includes/comment.php', 'wp-includes/meta.php', 'wp-includes/option.php',
			'wp-includes/blocks/calendar.php' ) as $relative ) {
			$path = ABSPATH . $relative;
			$current = Wstm108_Files::file( $path );
			$original = Wstm108_Files::file( '/usr/src/wordpress/' . $relative );
			self::check( $current['sha256'] === $original['sha256'], 'native source differs from selected image' );
			$this->pins[ $path ] = $current;
			$this->pins[ '/usr/src/wordpress/' . $relative ] = $original;
		}
		foreach ( array( 'wp_install', 'wp_install_defaults', 'wp_get_db_schema' ) as $name ) {
			$function = new ReflectionFunction( $name );
			$expected = 'wp_get_db_schema' === $name ? 'schema.php' : 'upgrade.php';
			self::check( $function->getFileName() === ABSPATH . 'wp-admin/includes/' . $expected,
				'overridden native initializer' );
		}
		self::check( get_class( $this->db ) === 'wpdb', 'native database implementation' );
		foreach ( array( 'block_core_calendar_update_has_published_posts', 'block_core_calendar_update_has_published_post_on_delete' ) as $name ) {
			self::check( ( new ReflectionFunction( $name ) )->getFileName() === ABSPATH . 'wp-includes/blocks/calendar.php',
				'overridden native calendar callback' );
		}
		self::check( 10 === has_action( 'delete_post', 'block_core_calendar_update_has_published_post_on_delete' ),
			'native calendar deletion hook' );
	}

	public function recheck(): void {
		foreach ( $this->pins as $path => $pin ) { Wstm108_Files::assert_file( $path, $pin ); }
	}

	private function native_insert( string $table, array $trace ): array {
		$data = null;
		foreach ( $trace as $index => $frame ) {
			$caller = $trace[ $index + 1 ] ?? array();
			if ( 'insert' === ( $frame['function'] ?? null ) && $this->db === ( $frame['object'] ?? null )
				&& ABSPATH . 'wp-admin/includes/upgrade.php' === ( $frame['file'] ?? null )
				&& $table === ( $frame['args'][0] ?? null ) && 'wp_install_defaults' === ( $caller['function'] ?? null )
				&& ABSPATH . 'wp-admin/includes/upgrade.php' === ( $caller['file'] ?? null ) ) {
				self::check( null === $data, 'ambiguous native insert frame' );
				$data = $frame['args'][1] ?? null;
			}
		}
		self::check( is_array( $data ) && count( $data ) > 0 && count( $data ) <= 32,
			'unattributed native fixture insert' );
		foreach ( $data as $key => $value ) {
			self::check( is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $key )
				&& ( is_string( $value ) || is_int( $value ) ) && strlen( (string) $value ) <= 65536,
				'native fixture field bound/type' );
		}
		return $data;
	}

	public function observe( string $sql ): string {
		$this->finish_query();
		foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships' ) as $name ) {
			$table = $this->db->$name;
			if ( 1 !== preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE)\b/i', $sql )
				|| 1 !== preg_match( '/(?<![A-Za-z0-9_])' . preg_quote( $table, '/' ) . '(?![A-Za-z0-9_])/', $sql ) ) {
				continue;
			}
			self::check( 1 === preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $table, '/' ) . '`?\s*\(/i', $sql ),
				'non-insert fixture write during initialization' );
			$data = $this->native_insert( $table, debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 16 ) );
			self::check( count( $this->inserts ) < 8, 'excessive native fixture insert' );
			$this->inserts[] = array( 'table' => $name, 'data' => $data );
			$this->pending_query = array( 'attempt' => count( $this->inserts ) - 1, 'table' => $name,
				'query_sha256' => hash( 'sha256', $sql ) );
		}
		return $sql;
	}

	public function finish_query(): void {
		if ( null === $this->pending_query ) { return; }
		$this->query_results[] = $this->query_result( $this->pending_query );
		$this->pending_query = null;
	}

	private function query_result( array $pending ): array {
		self::check( is_string( $this->db->last_query ) && hash( 'sha256', $this->db->last_query ) === $pending['query_sha256'],
			'native query result association' );
		self::check( is_string( $this->db->last_error ) && strlen( $this->db->last_error ) <= 65536
			&& is_int( $this->db->rows_affected ) && $this->db->rows_affected >= 0 && $this->db->rows_affected <= 256
			&& ( is_int( $this->db->insert_id ) || is_string( $this->db->insert_id ) )
			&& 1 === preg_match( '/^[0-9]{1,20}$/D', (string) $this->db->insert_id ), 'bounded native query result' );
		$error = $this->db->last_error;
		$kind = '' === $error ? 'none' : 'native-error'; $field = null;
		if ( 1 === preg_match( "/^Field '([A-Za-z0-9_]{1,64})' doesn't have a default value$/D", $error, $match ) ) {
			$kind = 'missing-default'; $field = $match[1];
		}
		return $pending + array(
			'rows_affected' => $this->db->rows_affected, 'insert_id' => $this->db->insert_id,
			'error_kind' => $kind, 'error_field' => $field, 'error_sha256' => hash( 'sha256', $error ),
			'status' => '' === $error && 1 === $this->db->rows_affected ? 'observed-success' : 'observed-failure',
		);
	}

	private function calendar_expectation( array $before ): void {
		$matches = array_values( array_filter( $before['options'], static fn( $row ) => 'wp_calendar_block_has_published_posts' === $row['option_name'] ) );
		self::check( count( $matches ) <= 1, 'ambiguous native calendar baseline' );
		$this->calendar_row = $matches[0] ?? null;
		$operation = null === $this->calendar_row ? 'insert' : 'update';
		if ( 'insert' === $operation ) {
			$this->calendar_sql = $this->db->prepare(
				"INSERT INTO `{$this->db->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)",
				'wp_calendar_block_has_published_posts', '1', 'auto' );
		} else {
			self::check( array( 'option_id', 'option_name', 'option_value', 'autoload' ) === array_keys( $this->calendar_row ),
				'native calendar baseline field order' );
			$autoload = $this->calendar_row['autoload'];
			$auto = in_array( $autoload, array( 'auto', 'auto-on', 'auto-off' ), true ) && 'auto' !== $autoload;
			$this->calendar_sql = $this->db->prepare(
				"UPDATE `{$this->db->options}` SET `option_value` = %s" . ( $auto ? ', `autoload` = %s' : '' ) . ' WHERE `option_name` = %s',
				...( $auto ? array( '1', 'auto', 'wp_calendar_block_has_published_posts' ) : array( '1', 'wp_calendar_block_has_published_posts' ) ) );
		}
	}

	public function observe_calendar( string $sql ): string {
		$this->finish_calendar_query();
		if ( 1 !== preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE)\b/i', $sql )
			|| false === strpos( $sql, $this->db->options ) || false === strpos( $sql, 'wp_calendar_block_has_published_posts' ) ) { return $sql; }
		self::check( 1 === $this->deleting && null === $this->calendar && $sql === $this->calendar_sql,
			'foreign or repeated native calendar write' );
		$trace = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 16 );
		$this->calendar_trace( $trace );
		$this->calendar_pending = array( 'table' => 'options', 'operation' => null === $this->calendar_row ? 'insert' : 'update',
			'query_sha256' => hash( 'sha256', $sql ) );
		return $sql;
	}

	private function calendar_trace( array $trace ): void {
		self::check( count( $trace ) <= 16, 'native calendar trace bound' );
		$found = array();
		foreach ( $trace as $frame ) {
			$name = $frame['function'] ?? '';
			if ( 'query' === $name && $this->db === ( $frame['object'] ?? null )
				&& ( null === $this->calendar_row ? ABSPATH . 'wp-includes/option.php' : ABSPATH . 'wp-includes/class-wpdb.php' )
					=== ( $frame['file'] ?? null ) ) { $found['database'] = true; }
			if ( 'update_option' === $name && ABSPATH . 'wp-includes/blocks/calendar.php' === ( $frame['file'] ?? null )
				&& array( 'wp_calendar_block_has_published_posts', true ) === ( $frame['args'] ?? null ) ) { $found['option'] = true; }
			if ( 'block_core_calendar_update_has_published_posts' === $name
				&& ABSPATH . 'wp-includes/blocks/calendar.php' === ( $frame['file'] ?? null ) ) { $found['calendar'] = true; }
			if ( 'block_core_calendar_update_has_published_post_on_delete' === $name
				&& array( 1 ) === ( $frame['args'] ?? null ) ) { $found['hook'] = true; }
			if ( 'wp_delete_post' === $name && __FILE__ === ( $frame['file'] ?? null )
				&& array( 1, true ) === ( $frame['args'] ?? null ) ) { $found['deletion'] = true; }
			if ( ( null === $this->calendar_row ? 'add_option' : 'update' ) === $name
				&& ABSPATH . 'wp-includes/option.php' === ( $frame['file'] ?? null ) ) { $found['writer'] = true; }
		}
		self::check( 6 === count( $found ), 'unattributed native calendar write' );
		$this->recheck();
	}

	private function finish_calendar_query(): void {
		if ( null === $this->calendar_pending ) { return; }
		$result = $this->query_result( $this->calendar_pending );
		$this->calendar = $result;
		$this->calendar_pending = null;
		self::check( 'none' === $result['error_kind'] && ( 'insert' === $result['operation']
			? 1 === $result['rows_affected'] && 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', (string) $result['insert_id'] )
			: $result['rows_affected'] <= 1 && ( ! self::calendar_update_changes( $this->calendar_row )
				|| 1 === $result['rows_affected'] ) ), 'native calendar write result/allocator' );
		$this->calendar['status'] = 'observed-success';
	}

	private static function calendar_update_changes( array $row ): bool {
		return '1' !== $row['option_value'] || in_array( $row['autoload'], array( 'auto-on', 'auto-off' ), true );
	}

	public function evidence(): array {
		foreach ( $this->retained as $name => $pin ) { Wstm108_Files::assert_file( '/qa-artifacts/' . $name, $pin ); }
		return array( 'attempts' => count( $this->inserts ), 'query_results' => $this->query_results,
			'records' => array_map( static fn( $pin ) => $pin['sha256'], $this->retained ),
			'native_sources' => array_map( static fn( $pin ) => $pin['sha256'], $this->pins ), 'calendar' => $this->calendar );
	}

	public function failure_record( Throwable $error, string $phase ): array {
		self::check( in_array( $phase, array( 'initializer', 'preparation', 'completion' ), true ), 'failure phase' );
		return array( 'phase' => $phase,
			'kind' => $error instanceof Wstm167_Fresh_Default_Count_Refusal ? 'native-default-count' : 'guard-refusal',
			'diagnostic' => $error instanceof Wstm167_Fresh_Default_Count_Refusal ? $error->diagnostic() : $this->preservation_diagnostic,
			'evidence' => $this->evidence() );
	}

	public function retain_failure( string $path, array $reservation, array $owner, array $preinstall, Throwable $error, string $phase ): void {
		Wstm108_Files::update( $path, $reservation, json_encode( array(
			'schema' => 'wstm-fresh-install-v1', 'owner' => $owner, 'complete' => false,
			'preinstall' => $preinstall, 'failure' => $this->failure_record( $error, $phase ),
		), JSON_THROW_ON_ERROR ) );
	}

	public function snapshot( bool $lock = false ): array {
		$tables = array(); $bytes = 0;
		foreach ( self::TABLES as $name ) {
			$suffix = $lock ? ' FOR UPDATE' : '';
			$rows = $this->db->get_results( "SELECT * FROM {$this->db->$name} ORDER BY 1,2 LIMIT 257{$suffix}", ARRAY_A );
			self::check( '' === $this->db->last_error && is_array( $rows ) && count( $rows ) <= 256, 'bounded native baseline' );
			foreach ( $rows as $row ) {
				self::check( is_array( $row ) && count( $row ) <= 64, 'native baseline row shape' );
				foreach ( $row as $value ) {
					self::check( null === $value || is_string( $value ), 'native database field type' );
					$bytes += null === $value ? 0 : strlen( $value );
					self::check( $bytes <= 33554432, 'native baseline byte bound' );
				}
			}
			$tables[ $name ] = $rows;
		}
		return $tables;
	}

	public function native_columns(): array {
		$schema = wp_get_db_schema( 'blog' ); $columns = array();
		self::check( is_string( $schema ) && strlen( $schema ) <= 33554432, 'native schema byte bound' );
		foreach ( array( 'posts', 'postmeta', 'comments', 'term_relationships' ) as $name ) {
			self::check( 1 === preg_match( '/CREATE TABLE ' . preg_quote( $this->db->$name, '/' ) . ' \((.*?)\n\)/s', $schema, $match ),
				'unambiguous native fixture schema' );
			$columns[ $name ] = array();
			foreach ( explode( "\n", $match[1] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || 1 === preg_match( '/^(PRIMARY|KEY|UNIQUE|FULLTEXT) /', $line ) ) { continue; }
				self::check( 1 === preg_match( '/^([A-Za-z0-9_]+)\s+(.+)$/', $line, $field ), 'native schema column grammar' );
				$auto = false !== stripos( $field[2], 'auto_increment' );
				$value = null; $required = false;
				if ( ! $auto ) {
					if ( false !== stripos( $field[2], 'default' ) ) {
						self::check( 1 === preg_match( "/\\bdefault\\s+(?:'([^'\\\\]*)'|(NULL|-?[0-9]+))(?=\\s|,|$)/i", $field[2], $default ),
							'native schema literal default' );
						$value = isset( $default[2] ) && 'NULL' === strtoupper( $default[2] ) ? null
							: ( $default[1] !== '' ? $default[1] : ( $default[2] ?? '' ) );
					} else {
						$required = false !== stripos( $field[2], 'NOT NULL' );
					}
				}
				self::check( ! array_key_exists( $field[1], $columns[ $name ] ), 'duplicate native schema column' );
				$columns[ $name ][ $field[1] ] = array( 'auto' => $auto, 'value' => $value, 'required' => $required );
			}
			self::check( count( $columns[ $name ] ) > 0 && count( $columns[ $name ] ) <= 64, 'native fixture schema shape' );
		}
		return $columns;
	}

	public static function defaults( array $tables, array $inserts, int $admin, array $columns ): array {
		self::check( $admin > 0 && count( $inserts ) === 7, 'complete native default insert inventory' );
		$expected = array( 'posts' => 3, 'postmeta' => 2, 'comments' => 1, 'commentmeta' => 0, 'term_relationships' => 1 );
		foreach ( array_merge( array_keys( $expected ), array( 'users' ) ) as $name ) {
			self::check( isset( $tables[ $name ] ) && is_array( $tables[ $name ] )
				&& array_values( $tables[ $name ] ) === $tables[ $name ], 'native default table/list shape' );
		}
		foreach ( $expected as $name => $count ) {
			if ( count( $tables[ $name ] ) !== $count ) {
				throw new Wstm167_Fresh_Default_Count_Refusal( $name, $count, count( $tables[ $name ] ) );
			}
			$observed = array_values( array_filter( $inserts, static fn( $record ) => $name === $record['table'] ) );
			self::check( count( $observed ) === $count, 'native insert/row inventory mismatch' );
			foreach ( $observed as $index => $record ) {
				self::check( array_keys( $columns[ $name ] ) === array_keys( $tables[ $name ][ $index ] )
					&& array() === array_diff( array_keys( $record['data'] ), array_keys( $columns[ $name ] ) ), 'native complete row shape' );
				foreach ( $columns[ $name ] as $key => $column ) {
					self::check( ! $column['required'] || array_key_exists( $key, $record['data'] ), 'missing native required inserted field' );
					$value = array_key_exists( $key, $record['data'] ) ? (string) $record['data'][ $key ]
						: ( $column['auto'] ? (string) ( $index + 1 ) : $column['value'] );
					self::check( $value === $tables[ $name ][ $index ][ $key ], 'native inserted/default field changed' );
				}
			}
		}
		self::check( array( '1', '2', '3' ) === array_column( $tables['posts'], 'ID' )
			&& array( (string) $admin, (string) $admin, (string) $admin ) === array_column( $tables['posts'], 'post_author' )
			&& array( 'post', 'page', 'page' ) === array_column( $tables['posts'], 'post_type' )
			&& array( 'publish', 'publish', 'draft' ) === array_column( $tables['posts'], 'post_status' )
			&& array( '2', '3' ) === array_column( $tables['postmeta'], 'post_id' )
			&& array( '_wp_page_template', '_wp_page_template' ) === array_column( $tables['postmeta'], 'meta_key' )
			&& array( 'default', 'default' ) === array_column( $tables['postmeta'], 'meta_value' )
			&& '1' === $tables['comments'][0]['comment_post_ID'] && '1' === $tables['term_relationships'][0]['object_id']
			&& count( $tables['users'] ) === 1 && (string) $admin === $tables['users'][0]['ID'],
			'native default identities/dependencies' );
		return array( 1, 2, 3 );
	}

	public function prepare( int $admin, bool $observation = false ): array {
		self::check( array() !== $this->pins, 'native preparation pins required' );
		foreach ( self::TABLES as $name ) {
			$engine = $this->db->get_var( $this->db->prepare(
				'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $this->db->$name ) );
			self::check( '' === $this->db->last_error && 'InnoDB' === $engine, 'transactional native preparation tables' );
		}
		self::check( false !== $this->db->query( 'START TRANSACTION' ) && '' === $this->db->last_error, 'native preparation transaction' );
		try {
		$this->recheck(); $before = $this->snapshot( true );
		$retained = array(
			'fresh-install.before.private.json' => Wstm108_Files::create( '/qa-artifacts/fresh-install.before.private.json',
				json_encode( $before, JSON_THROW_ON_ERROR ) ),
		);
		$this->retained = $retained;
		$retained['fresh-install.inserts.private.json'] = Wstm108_Files::create( '/qa-artifacts/fresh-install.inserts.private.json',
			json_encode( $this->inserts, JSON_THROW_ON_ERROR ) );
		$this->retained = $retained;
		$ids = self::defaults( $before, $this->inserts, $admin, $this->native_columns() );
		self::check( '3' === get_option( 'wp_page_for_privacy_policy' ) || 3 === get_option( 'wp_page_for_privacy_policy' ),
			'native privacy-page association' );
		$this->recheck();
		self::check( $before === $this->snapshot(), 'bootstrap baseline changed before deletion' );
		self::check( ! $observation, 'observation stops before default deletion' );
		$this->calendar_expectation( $before );
		$calendar_observer = array( $this, 'observe_calendar' );
		add_filter( 'query', $calendar_observer, PHP_INT_MAX );
		try {
			foreach ( $ids as $id ) {
				$this->deleting = $id;
				self::check( is_object( wp_delete_post( $id, true ) ), 'native owned default deletion failed' );
				$this->finish_calendar_query();
			}
		} finally { remove_filter( 'query', $calendar_observer, PHP_INT_MAX ); $this->deleting = null; }
		if ( '0' !== (string) get_option( 'wp_page_for_privacy_policy' ) ) {
			self::check( update_option( 'wp_page_for_privacy_policy', 0 ), 'owned default privacy-page reference update' );
		}
		self::check( '0' === (string) get_option( 'wp_page_for_privacy_policy' ), 'owned default privacy-page reference retirement' );
		$this->recheck(); $after = $this->snapshot();
		$retained['fresh-install.after.private.json'] = Wstm108_Files::create( '/qa-artifacts/fresh-install.after.private.json',
			json_encode( $after, JSON_THROW_ON_ERROR ) );
		$this->retained = $retained;
		$expected = self::expected_after( $before, $this->calendar );
		$this->preservation_diagnostic = self::preservation_difference( $expected, $after );
		foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships' ) as $name ) {
			self::check( array() === $after[ $name ], 'bootstrap fixture dependencies remain' );
		}
		self::check( $expected === $after, 'unrelated administrator/taxonomy/options/native data changed' );
		self::check( false !== $this->db->query( 'COMMIT' ) && '' === $this->db->last_error, 'native preparation commit' );
		} catch ( Throwable $error ) {
			self::check( false !== $this->db->query( 'ROLLBACK' ) && '' === $this->db->last_error,
				'native preparation rollback unresolved' );
			throw $error;
		}
		self::check( $expected === $this->snapshot(), 'native preparation post-commit drift' );
		foreach ( $retained as $name => $pin ) { Wstm108_Files::assert_file( '/qa-artifacts/' . $name, $pin ); }
		return array( 'defaults' => $ids, 'before_sha256' => hash( 'sha256', serialize( $before ) ),
			'after_sha256' => hash( 'sha256', serialize( $after ) ),
			'records' => array_map( static fn( $pin ) => $pin['sha256'], $retained ),
			'native_sources' => array_map( static fn( $pin ) => $pin['sha256'], $this->pins ), 'calendar' => $this->calendar );
	}

	private static function preservation_difference( array $expected, array $actual ): ?array {
		if ( $expected === $actual ) { return null; }
		$describe = static function ( string $kind, ?string $table, ?int $row, ?string $field, $left, $right ) use ( $expected, $actual ): array {
			return array( 'kind' => $kind, 'table' => $table, 'row' => $row, 'field' => $field,
				'expected_type' => gettype( $left ), 'actual_type' => gettype( $right ),
				'expected_sha256' => hash( 'sha256', serialize( $left ) ), 'actual_sha256' => hash( 'sha256', serialize( $right ) ),
				'expected_snapshot_sha256' => hash( 'sha256', json_encode( $expected, JSON_THROW_ON_ERROR ) ),
				'actual_snapshot_sha256' => hash( 'sha256', json_encode( $actual, JSON_THROW_ON_ERROR ) ) );
		};
		if ( array_keys( $expected ) !== array_keys( $actual ) ) {
			return $describe( 'table-order', null, null, null, array_keys( $expected ), array_keys( $actual ) );
		}
		foreach ( self::TABLES as $table ) {
			$left = $expected[ $table ]; $right = $actual[ $table ];
			if ( $left === $right ) { continue; }
			if ( array_keys( $left ) !== array_keys( $right ) ) {
				return $describe( 'row-inventory', $table, null, null, $left, $right );
			}
			$left_rows = array_map( static fn( $row ) => hash( 'sha256', serialize( $row ) ), $left );
			$right_rows = array_map( static fn( $row ) => hash( 'sha256', serialize( $row ) ), $right );
			sort( $left_rows ); sort( $right_rows );
			if ( $left_rows === $right_rows ) { return $describe( 'row-order', $table, null, null, $left, $right ); }
			foreach ( $left as $index => $row ) {
				$observed = $right[ $index ];
				if ( $row === $observed ) { continue; }
				if ( array_keys( $row ) !== array_keys( $observed ) ) {
					$left_keys = array_keys( $row ); $right_keys = array_keys( $observed );
					sort( $left_keys ); sort( $right_keys );
					return $describe( $left_keys === $right_keys ? 'field-order' : 'field-inventory',
						$table, $index, null, array_keys( $row ), array_keys( $observed ) );
				}
				foreach ( $row as $field => $value ) {
					if ( $value === $observed[ $field ] ) { continue; }
					$safe_field = is_string( $field ) && 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/D', $field ) ? $field : null;
					return $describe( gettype( $value ) === gettype( $observed[ $field ] ) ? 'field-value' : 'field-type',
						$table, $index, $safe_field, $value, $observed[ $field ] );
				}
			}
		}
		throw new RuntimeException( 'Fresh preservation difference could not be classified.' );
	}

	private static function expected_after( array $before, ?array $calendar = null ): array {
		$expected = $before;
		foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships' ) as $name ) { $expected[ $name ] = array(); }
		foreach ( $expected['term_taxonomy'] as &$row ) {
			if ( $row['term_taxonomy_id'] === $before['term_relationships'][0]['term_taxonomy_id'] ) { $row['count'] = '0'; }
		}
		unset( $row );
		foreach ( $expected['options'] as &$row ) {
			if ( 'wp_page_for_privacy_policy' === $row['option_name'] ) { $row['option_value'] = '0'; }
		}
		unset( $row );
		if ( null === $calendar ) {
			$existing = array_values( array_filter( $before['options'], static fn( $row ) => 'wp_calendar_block_has_published_posts' === $row['option_name'] ) );
			self::check( 1 === count( $existing ) && '1' === $existing[0]['option_value'], 'native calendar write evidence missing' );
			return $expected;
		}
		self::check( is_array( $calendar ) && array( 'table', 'operation', 'query_sha256', 'rows_affected', 'insert_id',
			'error_kind', 'error_field', 'error_sha256', 'status' ) === array_keys( $calendar )
			&& 'options' === $calendar['table'] && 'none' === $calendar['error_kind'] && null === $calendar['error_field']
			&& 'observed-success' === $calendar['status']
			&& hash( 'sha256', '' ) === $calendar['error_sha256']
			&& is_string( $calendar['query_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $calendar['query_sha256'] )
			&& is_int( $calendar['rows_affected'] ) && $calendar['rows_affected'] >= 0 && $calendar['rows_affected'] <= 1
			&& ( is_int( $calendar['insert_id'] ) || is_string( $calendar['insert_id'] ) )
			&& 1 === preg_match( '/^[0-9]{1,20}$/D', (string) $calendar['insert_id'] ), 'native calendar evidence required' );
		$present = false;
		foreach ( $expected['options'] as &$row ) {
			if ( 'wp_calendar_block_has_published_posts' !== $row['option_name'] ) { continue; }
			self::check( ! $present && 'update' === $calendar['operation'], 'native calendar existing-row association' );
			self::check( ! self::calendar_update_changes( $row ) || 1 === $calendar['rows_affected'],
				'native calendar existing-row update result' );
			$present = true; $row['option_value'] = '1';
			if ( in_array( $row['autoload'], array( 'auto', 'auto-on', 'auto-off' ), true ) ) { $row['autoload'] = 'auto'; }
		}
		unset( $row );
		if ( ! $present ) {
			self::check( 'insert' === $calendar['operation'] && 1 === $calendar['rows_affected']
				&& 'observed-success' === $calendar['status']
				&& 1 === preg_match( '/^[1-9][0-9]{0,19}$/D', (string) $calendar['insert_id'] )
				&& count( $expected['options'] ) < 256, 'native calendar new-row allocator association' );
			foreach ( $expected['options'] as $row ) {
				self::check( $row['option_id'] !== (string) $calendar['insert_id'], 'native calendar allocator collision' );
			}
			$expected['options'][] = array( 'option_id' => (string) $calendar['insert_id'],
				'option_name' => 'wp_calendar_block_has_published_posts', 'option_value' => '1', 'autoload' => 'auto' );
		}
		return $expected;
	}

	public static function verify_receipt( string $path, array $owner, $db ): array {
		$pin = Wstm108_Files::file( $path );
		self::check( 33 === $pin['identity']['uid'] && 33 === $pin['identity']['gid']
			&& 0600 === ( $pin['identity']['mode'] & 0777 ), 'fresh receipt custody' );
		$record = json_decode( $pin['bytes'], true, 32, JSON_THROW_ON_ERROR );
		self::check( is_array( $record ) && 'wstm-fresh-install-v1' === ( $record['schema'] ?? null )
			&& $owner === ( $record['owner'] ?? null ) && true === ( $record['complete'] ?? null )
			&& array( 1, 2, 3 ) === ( $record['preparation']['defaults'] ?? null ), 'fresh receipt binding/completion' );
		$native = new self( $db ); $native->pin_native();
		self::check( array( 'schema', 'owner', 'complete', 'preinstall', 'preparation' ) === array_keys( $record )
			&& is_array( $record['preinstall'] )
			&& array( 'defaults', 'before_sha256', 'after_sha256', 'records', 'native_sources', 'calendar' ) === array_keys( $record['preparation'] )
			&& is_string( $record['preparation']['before_sha256'] ) && is_string( $record['preparation']['after_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['preparation']['before_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['preparation']['after_sha256'] ), 'fresh receipt strict inventory' );
		foreach ( $record['preinstall'] as $table => $count ) {
			self::check( is_string( $table ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $table ) && 0 === $count,
				'fresh receipt pre-install zero type' );
		}
		self::check( array_map( static fn( $source ) => $source['sha256'], $native->pins )
			=== ( $record['preparation']['native_sources'] ?? null ), 'fresh receipt native source association' );
		self::check( array( 'fresh-install.before.private.json', 'fresh-install.inserts.private.json', 'fresh-install.after.private.json' )
			=== array_keys( $record['preparation']['records'] ), 'fresh receipt original record inventory' );
		$originals = array();
		foreach ( $record['preparation']['records'] as $name => $hash ) {
			$original = Wstm108_Files::file( '/qa-artifacts/' . $name );
			self::check( 33 === $original['identity']['uid'] && 33 === $original['identity']['gid']
				&& 0600 === ( $original['identity']['mode'] & 0777 ) && $hash === $original['sha256'], 'fresh original record custody/digest' );
			$originals[ $name ] = json_decode( $original['bytes'], true, 64, JSON_THROW_ON_ERROR );
			Wstm108_Files::assert_file( '/qa-artifacts/' . $name, $original );
		}
		self::check( $record['preparation']['before_sha256'] === hash( 'sha256', serialize( $originals['fresh-install.before.private.json'] ) )
			&& $record['preparation']['after_sha256'] === hash( 'sha256', serialize( $originals['fresh-install.after.private.json'] ) ),
			'fresh native typed baseline commitments' );
		self::defaults( $originals['fresh-install.before.private.json'], $originals['fresh-install.inserts.private.json'],
			(int) $originals['fresh-install.before.private.json']['users'][0]['ID'], $native->native_columns() );
		$native->calendar_expectation( $originals['fresh-install.before.private.json'] );
		if ( null !== $record['preparation']['calendar'] ) {
			self::check( hash( 'sha256', $native->calendar_sql ) === $record['preparation']['calendar']['query_sha256'],
				'fresh calendar native query commitment' );
		}
		self::check( self::expected_after( $originals['fresh-install.before.private.json'], $record['preparation']['calendar'] ) === $originals['fresh-install.after.private.json'],
			'fresh originals preserve all unrelated native data' );
		foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta' ) as $name ) {
			self::check( is_string( $db->$name ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $db->$name ), 'clean catalog table' );
			$value = $db->get_var( "SELECT COUNT(*) FROM {$db->$name}" );
			self::check( '' === $db->last_error && ( '0' === $value || 0 === $value ), 'fresh catalog no longer empty' );
		}
		Wstm108_Files::assert_file( $path, $pin );
		return $pin;
	}
}
