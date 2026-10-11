<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/fresh-install-preparation.php';

final class Wstm_Fresh_Database_Model {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $comments = 'wp_comments';
	public string $commentmeta = 'wp_commentmeta';
	public string $users = 'wp_users';
	public string $usermeta = 'wp_usermeta';
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $present = array();
	public array $calls = array();
	public $count = '0';
	public bool $unreadable = false;
	public array $rows = array();
	public array $saved = array();
	public ?int $fail_delete = null;
	public array $deleted = array();
	public array $originals = array();
	public array $schema_rows = array();
	public string $last_query = '';
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public $after_observation = null;
	public bool $fail_commit = false;
	public bool $fail_after_create = false;
	public $calendar_observer = null;
	public ?string $calendar_fault = null;

	public function tables( string $scope, bool $prefix ): array { return array( 'wp_posts', 'wp_options' ); }
	public function get_col( string $sql ): array {
		$this->calls[] = $sql;
		if ( $this->unreadable ) { $this->last_error = 'Private read error'; }
		return $this->present;
	}
	public function get_var( string $sql ) {
		$this->calls[] = $sql;
		if ( false !== strpos( $sql, 'SELECT ENGINE' ) ) { return 'InnoDB'; }
		return $this->count;
	}
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $value ) { $sql = preg_replace( '/%s/', "'" . (string) $value . "'", $sql, 1 ); }
		return $sql;
	}
	public function query( string $sql ) {
		$this->calls[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { $this->saved = $this->rows; }
		if ( 'ROLLBACK' === $sql ) { $this->rows = $this->saved; }
		if ( 'COMMIT' === $sql && $this->fail_commit ) { return false; }
		return 0;
	}
	public function get_results( string $sql, $format ): array {
		$this->calls[] = $sql;
		preg_match( '/FROM wp_([a-z_]+) /', $sql, $matches );
		if ( 3 === count( $this->deleted ) && null !== $this->after_observation ) {
			$observe = $this->after_observation; $this->after_observation = null; $observe( $this );
		}
		return $this->rows[ $matches[1] ];
	}
}

function wstm_fresh_test_pin( string $path, array $pin ): void {
	if ( '/native/mock' === $path && array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) === $pin ) { return; }
	$bytes = $GLOBALS['wstm_fresh_test_db']->originals[ $path ] ?? null;
	if ( ! is_string( $bytes ) || array( 'sha256' => hash( 'sha256', $bytes ) ) !== $pin ) {
		throw new RuntimeException( 'Invalid mocked acquisition.' );
	}
}

function wstm_fresh_test_create( string $path, string $bytes ): array {
	$db = $GLOBALS['wstm_fresh_test_db'];
	if ( '/qa-artifacts/fresh-install.after.private.json' === $path && $db->fail_after_create ) {
		throw new RuntimeException( 'Modeled after original acquisition failed.' );
	}
	if ( isset( $db->originals[ $path ] ) ) { throw new RuntimeException( 'Original acquisition collision.' ); }
	$db->originals[ $path ] = $bytes;
	return array( 'sha256' => hash( 'sha256', $bytes ) );
}

function wstm_fresh_test_update( string $path, array $expected, string $bytes ): array {
	wstm_fresh_test_pin( $path, $expected );
	$GLOBALS['wstm_fresh_test_db']->originals[ $path ] = $bytes;
	return array( 'sha256' => hash( 'sha256', $bytes ) );
}

function wstm_fresh_test_get_option( string $name ) {
	foreach ( $GLOBALS['wstm_fresh_test_db']->rows['options'] as $row ) {
		if ( $name === $row['option_name'] ) { return $row['option_value']; }
	}
	throw new RuntimeException( 'Missing native option in fixture.' );
}

function wstm_fresh_test_update_option( string $name, $value ): bool {
	foreach ( $GLOBALS['wstm_fresh_test_db']->rows['options'] as &$row ) {
		if ( $name === $row['option_name'] ) { $row['option_value'] = (string) $value; return true; }
	}
	throw new RuntimeException( 'Unexpected option acquisition.' );
}

function wstm_fresh_test_delete_post( int $id, bool $force ) {
	$db = $GLOBALS['wstm_fresh_test_db']; $db->deleted[] = $id;
	if ( $db->fail_delete === $id ) { return false; }
	if ( 1 === $id && null !== $db->calendar_observer ) {
		$key = 'wp_calendar_block_has_published_posts'; $existing = null;
		foreach ( $db->rows['options'] as $index => $row ) { if ( $key === $row['option_name'] ) { $existing = $index; } }
		if ( 'missing' !== $db->calendar_fault && 'no-write' !== $db->calendar_fault ) {
			if ( null === $existing ) {
				$sql = $db->prepare( "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)",
					$key, '1', 'auto' );
			} else {
				$auto = in_array( $db->rows['options'][ $existing ]['autoload'], array( 'auto-on', 'auto-off' ), true );
				$sql = $db->prepare( 'UPDATE `wp_options` SET `option_value` = %s' . ( $auto ? ', `autoload` = %s' : '' ) . ' WHERE `option_name` = %s',
					...( $auto ? array( '1', 'auto', $key ) : array( '1', $key ) ) );
			}
			$observe = $db->calendar_observer;
			$observe( 'sql' === $db->calendar_fault ? $sql . ' ' : $sql );
			$db->last_query = $sql;
			$db->rows_affected = in_array( $db->calendar_fault, array( 'failed', 'unchanged-update' ), true ) ? 0 : 1;
			$db->insert_id = null === $existing ? 108 : 107;
			if ( 'allocator' === $db->calendar_fault ) { $db->insert_id = 109; }
			if ( 'collision' === $db->calendar_fault ) { $db->insert_id = 91; }
			if ( 'zero-id' === $db->calendar_fault ) { $db->insert_id = 0; }
			if ( 'result-query' === $db->calendar_fault ) { $db->last_query .= ' '; }
			if ( null === $existing ) {
				$db->rows['options'][] = array( 'option_id' => '108', 'option_name' => $key, 'option_value' => '1', 'autoload' => 'auto' );
			} else {
				$db->rows['options'][ $existing ]['option_value'] = '1';
				if ( $auto ) { $db->rows['options'][ $existing ]['autoload'] = 'auto'; }
			}
			if ( 'repeated' === $db->calendar_fault ) { $observe( $sql ); }
		}
	}
	foreach ( array( 'posts' => 'ID', 'postmeta' => 'post_id', 'comments' => 'comment_post_ID', 'term_relationships' => 'object_id' ) as $table => $key ) {
		$db->rows[ $table ] = array_values( array_filter( $db->rows[ $table ], static fn( $row ) => (string) $id !== $row[ $key ] ) );
	}
	if ( 1 === $id ) { $db->rows['term_taxonomy'][0]['count'] = '0'; }
	return (object) array( 'ID' => $id );
}

function wstm_fresh_test_filter( string $name, $callback, int $priority ): void {
	$GLOBALS['wstm_fresh_test_db']->calendar_observer = $callback;
}

function wstm_fresh_test_remove_filter( string $name, $callback, int $priority ): void {
	$GLOBALS['wstm_fresh_test_db']->calendar_observer = null;
}

function wstm_fresh_test_trace( int $flags, int $limit ): array {
	$db = $GLOBALS['wstm_fresh_test_db']; $calendar = ABSPATH . 'wp-includes/blocks/calendar.php';
	$present = false;
	foreach ( $db->saved['options'] as $row ) { if ( 'wp_calendar_block_has_published_posts' === $row['option_name'] ) { $present = true; } }
	$trace = array(
		array( 'function' => 'query', 'object' => $db, 'file' => ABSPATH . ( $present ? 'wp-includes/class-wpdb.php' : 'wp-includes/option.php' ) ),
		array( 'function' => 'update_option', 'file' => $calendar, 'args' => array( 'wp_calendar_block_has_published_posts', true ) ),
		array( 'function' => 'block_core_calendar_update_has_published_posts', 'file' => $calendar ),
		array( 'function' => 'block_core_calendar_update_has_published_post_on_delete', 'args' => array( 1 ) ),
		array( 'function' => 'wp_delete_post', 'file' => dirname( __DIR__ ) . '/e2e/fresh-install-preparation.php', 'args' => array( 1, true ) ),
		array( 'function' => $present ? 'update' : 'add_option', 'file' => ABSPATH . 'wp-includes/option.php' ),
	);
	if ( 'foreign' === $db->calendar_fault ) { $trace[1]['file'] = '/foreign/calendar.php'; }
	if ( 'foreign-db' === $db->calendar_fault ) { $trace[0]['object'] = new Wstm_Fresh_Database_Model(); }
	if ( 'foreign-query' === $db->calendar_fault ) { $trace[0]['file'] = '/foreign/query.php'; }
	if ( 'transition' === $db->calendar_fault ) { $trace[3]['function'] = 'block_core_calendar_update_has_published_post_on_transition_post_status'; }
	if ( 'value-arg' === $db->calendar_fault ) { $trace[1]['args'][1] = false; }
	return $trace;
}

function wstm_fresh_test_schema( string $scope ): string {
	$result = '';
	foreach ( array( 'posts', 'postmeta', 'comments', 'term_relationships' ) as $table ) {
		$result .= "CREATE TABLE wp_{$table} (\n";
		$rows = $GLOBALS['wstm_fresh_test_db']->schema_rows ?: $GLOBALS['wstm_fresh_test_db']->rows;
		foreach ( $rows[ $table ][0] as $key => $value ) {
			$result .= 'ID' === $key ? "ID bigint NOT NULL auto_increment,\n" : "{$key} text default '{$value}',\n";
		}
		$result .= "PRIMARY KEY (fixture)\n);\n";
	}
	return $result;
}

final class FreshInstallPreparationTest extends TestCase {
	private function loadAcquisitionModel(): void {
		if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
		if ( ! class_exists( 'Wstm167_Fresh_Install_Acquisition_Model', false ) ) {
			$source = file_get_contents( dirname( __DIR__ ) . '/e2e/fresh-install-preparation.php' );
			$source = substr( $source, strpos( $source, 'final class Wstm167_Fresh_Install' ) );
			// Only external acquisitions are replaced; the preparation guards and transaction body are unchanged.
			$source = str_replace( array( 'Wstm167_Fresh_Install', 'Wstm108_Files::assert_file(', 'Wstm108_Files::create(', 'Wstm108_Files::update(', 'wp_delete_post(', 'get_option(', 'update_option(', 'wp_get_db_schema(', 'add_filter(', 'remove_filter(', 'debug_backtrace(', '__FILE__' ),
				array( 'Wstm167_Fresh_Install_Acquisition_Model', 'wstm_fresh_test_pin(', 'wstm_fresh_test_create(', 'wstm_fresh_test_update(', 'wstm_fresh_test_delete_post(', 'wstm_fresh_test_get_option(', 'wstm_fresh_test_update_option(', 'wstm_fresh_test_schema(', 'wstm_fresh_test_filter(', 'wstm_fresh_test_remove_filter(', 'wstm_fresh_test_trace(', var_export( dirname( __DIR__ ) . '/e2e/fresh-install-preparation.php', true ) ), $source );
			eval( $source );
		}
	}

	public function testActualPreparationBodyUsesOnlyNativeDeletesAndRollsBackPartialFailures(): void {
		$this->loadAcquisitionModel();
		foreach ( array( null, 2 ) as $failure ) {
			list( $tables, $inserts ) = $this->defaults();
			$tables['terms'] = array( array( 'term_id' => '1', 'name' => 'Native category' ) );
			$tables['term_taxonomy'] = array( array( 'term_taxonomy_id' => '1', 'count' => '1' ) );
			$tables['usermeta'] = array( array( 'user_id' => '7', 'meta_key' => 'capabilities', 'meta_value' => 'Native admin' ) );
			$tables['options'] = array( array( 'option_id' => '90', 'option_name' => 'wp_page_for_privacy_policy', 'option_value' => '3', 'autoload' => 'yes' ),
				array( 'option_id' => '91', 'option_name' => 'unrelated', 'option_value' => 'Preserved', 'autoload' => 'yes' ) );
			$db = new Wstm_Fresh_Database_Model(); $db->rows = $tables; $db->fail_delete = $failure;
			$GLOBALS['wstm_fresh_test_db'] = $db;
			$preparation = new Wstm167_Fresh_Install_Acquisition_Model( $db );
			foreach ( array( 'pins' => array( '/native/mock' => array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) ), 'inserts' => $inserts ) as $name => $value ) {
				$property = new ReflectionProperty( $preparation, $name ); $property->setAccessible( true ); $property->setValue( $preparation, $value );
			}
			try {
				$result = $preparation->prepare( 7 );
				self::assertNull( $failure );
				self::assertSame( array( 1, 2, 3 ), $db->deleted );
				self::assertSame( array( 1, 2, 3 ), $result['defaults'] );
				self::assertContains( 'COMMIT', $db->calls ); self::assertNotContains( 'ROLLBACK', $db->calls );
				self::assertSame( $tables['users'], $db->rows['users'] );
				self::assertSame( $tables['usermeta'], $db->rows['usermeta'] );
				self::assertSame( $tables['terms'], $db->rows['terms'] );
				self::assertSame( 'Preserved', $db->rows['options'][1]['option_value'] );
				self::assertCount( 3, $db->originals ); self::assertCount( 3, $result['records'] );
			} catch ( RuntimeException $error ) {
				self::assertSame( 2, $failure );
				self::assertSame( array( 1, 2 ), $db->deleted );
				self::assertSame( $tables, $db->rows );
				self::assertContains( 'ROLLBACK', $db->calls ); self::assertNotContains( 'COMMIT', $db->calls );
				self::assertCount( 2, $db->originals );
			} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
		}
	}

	public function testEveryCountRefusalRetainsExactOriginalsWithoutDeletion(): void {
		$this->loadAcquisitionModel();
		foreach ( array( 'posts' => 3, 'posts-extra-navigation' => 3, 'postmeta' => 2, 'comments' => 1, 'commentmeta' => 0, 'term_relationships' => 1 ) as $table => $expected ) {
			list( $tables, $inserts ) = $this->defaults();
			$db = new Wstm_Fresh_Database_Model(); $db->schema_rows = $tables;
			if ( 'posts-extra-navigation' === $table ) {
				$table = 'posts'; $extra = $tables['posts'][0]; $extra['ID'] = '4';
				$extra['post_author'] = '0'; $extra['post_type'] = 'wp_navigation'; $extra['post_status'] = 'publish';
				$tables['posts'][] = $extra;
			} elseif ( 'commentmeta' === $table ) { $tables[ $table ][] = array( 'comment_id' => '1' ); }
			else { array_pop( $tables[ $table ] ); }
			foreach ( array( 'terms', 'term_taxonomy', 'usermeta', 'options' ) as $name ) { $tables[ $name ] = array(); }
			$tables = array_replace( array_fill_keys( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
				'comments', 'commentmeta', 'users', 'usermeta', 'options' ), array() ), $tables );
			$db->rows = $tables; $GLOBALS['wstm_fresh_test_db'] = $db;
			$preparation = new Wstm167_Fresh_Install_Acquisition_Model( $db );
			foreach ( array( 'pins' => array( '/native/mock' => array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) ), 'inserts' => $inserts ) as $name => $value ) {
				$property = new ReflectionProperty( $preparation, $name ); $property->setAccessible( true ); $property->setValue( $preparation, $value );
			}
			try {
				$preparation->prepare( 7 );
				self::fail( 'Mismatching native dependency accepted.' );
			} catch ( Wstm167_Fresh_Default_Count_Refusal $error ) {
				$diagnostic = array( 'table' => $table, 'expected' => $expected, 'actual' => count( $tables[ $table ] ) );
				self::assertSame( $diagnostic, $error->diagnostic() );
				self::assertSame( array(), $db->deleted ); self::assertSame( $tables, $db->rows );
				self::assertContains( 'ROLLBACK', $db->calls ); self::assertNotContains( 'COMMIT', $db->calls );
				self::assertSame( $tables, json_decode( $db->originals['/qa-artifacts/fresh-install.before.private.json'], true, 64, JSON_THROW_ON_ERROR ) );
				self::assertSame( $inserts, json_decode( $db->originals['/qa-artifacts/fresh-install.inserts.private.json'], true, 64, JSON_THROW_ON_ERROR ) );
				self::assertCount( 2, $db->originals );
				$failure = $preparation->failure_record( $error, 'preparation' );
				self::assertSame( $diagnostic, $failure['diagnostic'] );
				self::assertSame( 'native-default-count', $failure['kind'] );
				self::assertSame( 7, $failure['evidence']['attempts'] );
				self::assertSame( array(), $failure['evidence']['query_results'] );
				self::assertCount( 2, $failure['evidence']['records'] );
				$owner = array( 'run' => str_repeat( 'a', 32 ), 'token' => str_repeat( 'a', 64 ) );
				$receipt = '/qa-artifacts/fresh-install.private.json';
				$reservation = wstm_fresh_test_create( $receipt, '{"complete":false}' );
				$preparation->retain_failure( $receipt, $reservation, $owner, array(), $error, 'preparation' );
				$record = json_decode( $db->originals[ $receipt ], true, 64, JSON_THROW_ON_ERROR );
				self::assertFalse( $record['complete'] ); self::assertSame( $owner, $record['owner'] );
				self::assertSame( $failure, $record['failure'] ); self::assertSame( array(), $record['preinstall'] );
			} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
		}
	}

	public function testAfterOriginalPrecedesEveryPreservationRefusalAndRemainsPrivate(): void {
		$this->loadAcquisitionModel();
		foreach ( array( 'control', 'missing-row', 'missing-field', 'drift', 'row-order', 'field-order', 'type',
			'dependencies', 'commit', 'unreadable-after', 'missing-original' ) as $case ) {
			list( $tables, $inserts ) = $this->defaults();
			$tables['terms'] = array( array( 'term_id' => '1', 'name' => 'Native category' ) );
			$tables['term_taxonomy'] = array( array( 'term_taxonomy_id' => '1', 'count' => '1' ) );
			$tables['usermeta'] = array( array( 'user_id' => '7', 'meta_key' => 'capabilities', 'meta_value' => 'Private administrator' ) );
			$tables['options'] = array( array( 'option_id' => '90', 'option_name' => 'wp_page_for_privacy_policy', 'option_value' => '3', 'autoload' => 'yes' ),
				array( 'option_id' => '91', 'option_name' => 'unrelated', 'option_value' => 'Private preserved content', 'autoload' => 'yes' ) );
			$tables = array_replace( array_fill_keys( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
				'comments', 'commentmeta', 'users', 'usermeta', 'options' ), array() ), $tables );
			$db = new Wstm_Fresh_Database_Model(); $db->rows = $tables;
			$db->fail_commit = 'commit' === $case; $db->fail_after_create = 'missing-original' === $case;
			$actual = null;
			$db->after_observation = static function ( $model ) use ( $case, &$actual ): void {
				switch ( $case ) {
					case 'missing-row': array_splice( $model->rows['options'], 1, 1 ); break;
					case 'missing-field': unset( $model->rows['options'][1]['option_value'] ); break;
					case 'drift': $model->rows['options'][1]['option_value'] = 'Private changed content'; break;
					case 'row-order': $model->rows['options'] = array_reverse( $model->rows['options'] ); break;
					case 'field-order': $model->rows['options'][1] = array_reverse( $model->rows['options'][1], true ); break;
					case 'type': $model->rows['options'][1]['option_value'] = null; break;
					case 'dependencies': $model->rows['commentmeta'][] = array( 'comment_id' => '1' ); break;
					case 'unreadable-after': $model->last_error = 'Private modeled SQL error'; break;
				}
				$actual = $model->rows;
			};
			$GLOBALS['wstm_fresh_test_db'] = $db;
			$preparation = new Wstm167_Fresh_Install_Acquisition_Model( $db );
			foreach ( array( 'pins' => array( '/native/mock' => array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) ), 'inserts' => $inserts ) as $name => $value ) {
				$property = new ReflectionProperty( $preparation, $name ); $property->setAccessible( true ); $property->setValue( $preparation, $value );
			}
			try {
				$result = $preparation->prepare( 7 );
				self::assertSame( 'control', $case ); self::assertContains( 'COMMIT', $db->calls );
				self::assertNotContains( 'ROLLBACK', $db->calls );
				self::assertSame( hash( 'sha256', json_encode( $actual, JSON_THROW_ON_ERROR ) ), $result['records']['fresh-install.after.private.json'] );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( 'control', $case, $error->getMessage() );
				self::assertStringNotContainsString( 'Private', $error->getMessage() );
				self::assertContains( 'ROLLBACK', $db->calls ); self::assertSame( $tables, $db->rows );
				if ( 'commit' !== $case ) { self::assertNotContains( 'COMMIT', $db->calls ); }
				$missing = in_array( $case, array( 'unreadable-after', 'missing-original' ), true );
				$failure = $preparation->failure_record( $error, 'preparation' );
				if ( $missing ) {
					self::assertArrayNotHasKey( '/qa-artifacts/fresh-install.after.private.json', $db->originals );
					self::assertArrayNotHasKey( 'fresh-install.after.private.json', $failure['evidence']['records'] );
					self::assertNull( $failure['diagnostic'] );
				} else {
					$bytes = $db->originals['/qa-artifacts/fresh-install.after.private.json'];
					self::assertSame( json_encode( $actual, JSON_THROW_ON_ERROR ), $bytes );
					self::assertSame( hash( 'sha256', $bytes ), $failure['evidence']['records']['fresh-install.after.private.json'] );
					$kinds = array( 'missing-row' => 'row-inventory', 'missing-field' => 'field-inventory', 'drift' => 'field-value',
						'row-order' => 'row-order', 'field-order' => 'field-order', 'type' => 'field-type', 'dependencies' => 'row-inventory' );
					if ( 'commit' === $case ) { self::assertNull( $failure['diagnostic'] ); }
					else {
						$diagnostic = $failure['diagnostic'];
						self::assertSame( $kinds[ $case ], $diagnostic['kind'] );
						self::assertSame( 'dependencies' === $case ? 'commentmeta' : 'options', $diagnostic['table'] );
						self::assertSame( hash( 'sha256', $bytes ), $diagnostic['actual_snapshot_sha256'] );
						self::assertSame( array( 'kind', 'table', 'row', 'field', 'expected_type', 'actual_type', 'expected_sha256',
							'actual_sha256', 'expected_snapshot_sha256', 'actual_snapshot_sha256' ), array_keys( $diagnostic ) );
						if ( 'type' === $case ) {
							self::assertSame( 'string', $diagnostic['expected_type'] ); self::assertSame( 'NULL', $diagnostic['actual_type'] );
						}
						if ( in_array( $case, array( 'type', 'drift' ), true ) ) { self::assertSame( 'option_value', $diagnostic['field'] ); }
					}
				}
				$receipt = '/qa-artifacts/fresh-install.private.json';
				$owner = array( 'run' => str_repeat( 'a', 32 ), 'token' => str_repeat( 'a', 64 ) );
				$reservation = wstm_fresh_test_create( $receipt, '{"complete":false}' );
				$preparation->retain_failure( $receipt, $reservation, $owner, array(), $error, 'preparation' );
				$record = json_decode( $db->originals[ $receipt ], true, 64, JSON_THROW_ON_ERROR );
				self::assertFalse( $record['complete'] ); self::assertSame( $failure, $record['failure'] );
				self::assertStringNotContainsString( 'Private', $db->originals[ $receipt ] );
			} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
		}
	}

	public function testSevenObservedAttemptsDoNotBecomeSevenSuccessfulQueries(): void {
		$db = new Wstm_Fresh_Database_Model(); $preparation = new Wstm167_Fresh_Install( $db );
		list( , $inserts ) = $this->defaults();
		$property = new ReflectionProperty( $preparation, 'inserts' ); $property->setAccessible( true ); $property->setValue( $preparation, $inserts );
		$pending = new ReflectionProperty( $preparation, 'pending_query' ); $pending->setAccessible( true );
		foreach ( $inserts as $index => $record ) {
			$db->last_query = 'Modeled native query ' . $index;
			$db->last_error = 2 === $index ? "Field 'post_excerpt' doesn't have a default value" : '';
			$db->rows_affected = 2 === $index ? 0 : 1; $db->insert_id = 2 === $index ? 0 : $index + 1;
			$pending->setValue( $preparation, array( 'attempt' => $index, 'table' => $record['table'], 'query_sha256' => hash( 'sha256', $db->last_query ) ) );
			$preparation->finish_query();
		}
		$evidence = $preparation->evidence();
		self::assertSame( 7, $evidence['attempts'] ); self::assertCount( 7, $evidence['query_results'] );
		self::assertCount( 6, array_filter( $evidence['query_results'], static fn( $record ) => 'observed-success' === $record['status'] ) );
		self::assertSame( 'missing-default', $evidence['query_results'][2]['error_kind'] );
		self::assertSame( 'post_excerpt', $evidence['query_results'][2]['error_field'] );
		self::assertArrayNotHasKey( 'error', $evidence['query_results'][2] );
		self::assertSame( $evidence, $preparation->evidence() );
		$pending->setValue( $preparation, array( 'attempt' => 7, 'table' => 'posts', 'query_sha256' => str_repeat( 'f', 64 ) ) );
		$this->expectExceptionMessage( 'native query result association' );
		$preparation->finish_query();
	}

	private function calendarModel( ?array $cache = null ): array {
		$this->loadAcquisitionModel();
		list( $tables, $inserts ) = $this->defaults();
		$tables['terms'] = array( array( 'term_id' => '1', 'name' => 'Native category' ) );
		$tables['term_taxonomy'] = array( array( 'term_taxonomy_id' => '1', 'count' => '1' ) );
		$tables['usermeta'] = array();
		$tables['options'] = array( array( 'option_id' => '90', 'option_name' => 'wp_page_for_privacy_policy', 'option_value' => '3', 'autoload' => 'yes' ),
			array( 'option_id' => '91', 'option_name' => 'unrelated', 'option_value' => 'Private unchanged', 'autoload' => 'yes' ) );
		if ( null !== $cache ) {
			$tables['options'][] = array( 'option_id' => '92', 'option_name' => 'wp_calendar_block_has_published_posts',
				'option_value' => $cache[0], 'autoload' => $cache[1] );
		}
		$tables = array_replace( array_fill_keys( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
			'comments', 'commentmeta', 'users', 'usermeta', 'options' ), array() ), $tables );
		$db = new Wstm_Fresh_Database_Model(); $db->rows = $tables;
		$GLOBALS['wstm_fresh_test_db'] = $db;
		$preparation = new Wstm167_Fresh_Install_Acquisition_Model( $db );
		foreach ( array( 'pins' => array( '/native/mock' => array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) ), 'inserts' => $inserts ) as $name => $value ) {
			$property = new ReflectionProperty( $preparation, $name ); $property->setAccessible( true ); $property->setValue( $preparation, $value );
		}
		return array( $preparation, $db, $tables );
	}

	public function testCalendarCaptureRequiresNativeCallerUniqueWriteAndSuccessfulAllocator(): void {
		foreach ( array( null, 'missing', 'failed', 'foreign', 'foreign-db', 'foreign-query', 'transition',
			'value-arg', 'sql', 'repeated', 'allocator', 'collision', 'zero-id', 'result-query' ) as $fault ) {
			list( $preparation, $db, $before ) = $this->calendarModel(); $db->calendar_fault = $fault;
			try {
				$result = $preparation->prepare( 7 );
				self::assertNull( $fault ); self::assertSame( 'insert', $result['calendar']['operation'] );
				self::assertSame( 108, $result['calendar']['insert_id'] ); self::assertSame( 1, $result['calendar']['rows_affected'] );
				self::assertSame( '1', $db->rows['options'][2]['option_value'] );
				self::assertSame( 'auto', $db->rows['options'][2]['autoload'] );
				self::assertSame( $before['options'][1], $db->rows['options'][1] );
			} catch ( RuntimeException $error ) {
				self::assertNotNull( $fault ); self::assertContains( 'ROLLBACK', $db->calls );
				self::assertNotContains( 'COMMIT', $db->calls ); self::assertSame( $before, $db->rows );
				self::assertStringNotContainsString( 'Private', $error->getMessage() );
			} finally {
				self::assertNull( $db->calendar_observer ); unset( $GLOBALS['wstm_fresh_test_db'] );
			}
		}
	}

	public function testExistingCalendarCacheIsUpdatedOrPreservedAccordingToObservedNativeBehavior(): void {
		foreach ( array( array( '0', 'yes', null, 'yes' ), array( '1', 'off', null, 'off' ),
			array( '0', 'auto-off', null, 'auto' ), array( '1', 'auto-on', null, 'auto' ),
			array( '1', 'auto-off', 'no-write', 'auto-off' ), array( '1', 'yes', 'unchanged-update', 'yes' ),
			array( '0', 'yes', 'no-write', null ), array( '0', 'yes', 'failed', null ) ) as $case ) {
			list( $preparation, $db, $before ) = $this->calendarModel( $case ); $db->calendar_fault = $case[2];
			try {
				$result = $preparation->prepare( 7 );
				self::assertNotNull( $case[3] );
				self::assertSame( '1', $db->rows['options'][2]['option_value'] );
				self::assertSame( $case[3], $db->rows['options'][2]['autoload'] );
				self::assertSame( '92', $db->rows['options'][2]['option_id'] );
				self::assertSame( 3, count( $db->rows['options'] ) );
				if ( 'no-write' === $case[2] ) { self::assertNull( $result['calendar'] ); }
				else { self::assertSame( 'update', $result['calendar']['operation'] ); self::assertSame( 107, $result['calendar']['insert_id'] ); }
			} catch ( RuntimeException $error ) {
				self::assertNull( $case[3] ); self::assertSame( $before, $db->rows );
				self::assertContains( 'ROLLBACK', $db->calls ); self::assertNotContains( 'COMMIT', $db->calls );
			} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
		}
	}

	public function testCapturedCalendarCannotAdmitAfterTupleDriftOrAnotherOption(): void {
		foreach ( array( 'id', 'value', 'type', 'autoload', 'order', 'missing', 'extra', 'foreign-value' ) as $fault ) {
			list( $preparation, $db, $before ) = $this->calendarModel();
			$actual = null;
			$db->after_observation = static function ( $model ) use ( $fault, &$actual ): void {
				switch ( $fault ) {
					case 'id': $model->rows['options'][2]['option_id'] = '109'; break;
					case 'value': $model->rows['options'][2]['option_value'] = '0'; break;
					case 'type': $model->rows['options'][2]['option_value'] = null; break;
					case 'autoload': $model->rows['options'][2]['autoload'] = 'yes'; break;
					case 'order': $model->rows['options'][2] = array_reverse( $model->rows['options'][2], true ); break;
					case 'missing': array_pop( $model->rows['options'] ); break;
					case 'extra': $model->rows['options'][] = array( 'option_id' => '109', 'option_name' => 'foreign', 'option_value' => 'Private', 'autoload' => 'yes' ); break;
					case 'foreign-value': $model->rows['options'][1]['option_value'] = 'Private drift'; break;
				}
				$actual = $model->rows;
			};
			try { $preparation->prepare( 7 ); self::fail( 'Calendar proof admitted unrelated tuple drift.' ); }
			catch ( RuntimeException $error ) {
				self::assertContains( 'ROLLBACK', $db->calls ); self::assertNotContains( 'COMMIT', $db->calls );
				self::assertSame( $before, $db->rows );
				$bytes = $db->originals['/qa-artifacts/fresh-install.after.private.json'];
				self::assertSame( json_encode( $actual, JSON_THROW_ON_ERROR ), $bytes );
				$failure = $preparation->failure_record( $error, 'preparation' );
				self::assertSame( 108, $failure['evidence']['calendar']['insert_id'] );
				self::assertSame( hash( 'sha256', $bytes ), $failure['evidence']['records']['fresh-install.after.private.json'] );
			} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
		}
	}

	public function testObservationCannotDeleteEvenWhenAllDefaultGuardsPass(): void {
		$this->loadAcquisitionModel();
		list( $tables, $inserts ) = $this->defaults();
		foreach ( array( 'terms', 'term_taxonomy', 'usermeta' ) as $name ) { $tables[ $name ] = array(); }
		$tables['options'] = array( array( 'option_name' => 'wp_page_for_privacy_policy', 'option_value' => '3' ) );
		$db = new Wstm_Fresh_Database_Model(); $db->rows = $tables; $GLOBALS['wstm_fresh_test_db'] = $db;
		$preparation = new Wstm167_Fresh_Install_Acquisition_Model( $db );
		foreach ( array( 'pins' => array( '/native/mock' => array( 'mocked-native-file' => true, 'sha256' => str_repeat( 'b', 64 ) ) ), 'inserts' => $inserts ) as $name => $value ) {
			$property = new ReflectionProperty( $preparation, $name ); $property->setAccessible( true ); $property->setValue( $preparation, $value );
		}
		try {
			$preparation->prepare( 7, true ); self::fail( 'Observation performed deletion.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'observation stops before default deletion', $error->getMessage() );
			self::assertSame( array(), $db->deleted ); self::assertSame( $tables, $db->rows );
			self::assertContains( 'ROLLBACK', $db->calls ); self::assertNotContains( 'COMMIT', $db->calls ); self::assertCount( 2, $db->originals );
		} finally { unset( $GLOBALS['wstm_fresh_test_db'] ); }
	}

	public function testNativeInsertAttributionRequiresTheSameDatabaseAndDirectPinnedDefaultCaller(): void {
		$db = new Wstm_Fresh_Database_Model(); $preparation = new Wstm167_Fresh_Install( $db );
		$method = new ReflectionMethod( $preparation, 'native_insert' ); $method->setAccessible( true );
		$data = array( 'post_author' => 7, 'post_content' => 'Native field' );
		$trace = array(
			array( 'function' => 'insert', 'object' => $db, 'file' => ABSPATH . 'wp-admin/includes/upgrade.php', 'args' => array( 'wp_posts', $data ) ),
			array( 'function' => 'wp_install_defaults', 'file' => ABSPATH . 'wp-admin/includes/upgrade.php' ),
		);
		self::assertSame( $data, $method->invoke( $preparation, 'wp_posts', $trace ) );
		foreach ( array( 'object', 'file', 'caller', 'table', 'array', 'bound' ) as $kind ) {
			$changed = $trace;
			switch ( $kind ) {
				case 'object': $changed[0]['object'] = new Wstm_Fresh_Database_Model(); break;
				case 'file': $changed[0]['file'] = '/foreign/upgrade.php'; break;
				case 'caller': $changed[1]['function'] = 'foreign_initializer'; break;
				case 'table': $changed[0]['args'][0] = 'wp_comments'; break;
				case 'array': $changed[0]['args'][1]['post_content'] = array(); break;
				case 'bound': $changed[0]['args'][1]['post_content'] = str_repeat( 'x', 65537 ); break;
			}
			try { $method->invoke( $preparation, 'wp_posts', $changed ); self::fail( 'Unproven insert accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'Fresh installation refused', $error->getMessage() ); }
		}
	}

	private function defaults(): array {
		$tables = array(
			'posts' => array(
				array( 'ID' => '1', 'post_author' => '7', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'Native first post' ),
				array( 'ID' => '2', 'post_author' => '7', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Native page' ),
				array( 'ID' => '3', 'post_author' => '7', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'Native privacy page' ),
			),
			'postmeta' => array(
				array( 'post_id' => '2', 'meta_key' => '_wp_page_template', 'meta_value' => 'default' ),
				array( 'post_id' => '3', 'meta_key' => '_wp_page_template', 'meta_value' => 'default' ),
			),
			'comments' => array( array( 'comment_post_ID' => '1', 'comment_content' => 'Native comment' ) ),
			'commentmeta' => array(),
			'term_relationships' => array( array( 'object_id' => '1', 'term_taxonomy_id' => '1' ) ),
			'users' => array( array( 'ID' => '7' ) ),
		);
		$inserts = array();
		foreach ( $tables['posts'] as &$post ) { $post['post_password'] = ''; }
		unset( $post );
		foreach ( array( 'posts', 'postmeta', 'comments', 'term_relationships' ) as $table ) {
			foreach ( $tables[ $table ] as $row ) {
				unset( $row['ID'], $row['post_password'] );
				$inserts[] = array( 'table' => $table, 'data' => $row );
			}
		}
		$columns = array();
		foreach ( array( 'posts', 'postmeta', 'comments', 'term_relationships' ) as $table ) {
			foreach ( $tables[ $table ][0] as $key => $value ) {
				$columns[ $table ][ $key ] = array( 'auto' => 'ID' === $key, 'value' => $value, 'required' => false );
			}
		}
		return array( $tables, $inserts, $columns );
	}

	public function testAbsentAndGenuinelyEmptyNativePreinstallDomainsAreDistinguishedFromUnreadable(): void {
		$db = new Wstm_Fresh_Database_Model();
		self::assertSame( array(), ( new Wstm167_Fresh_Install( $db ) )->preinstall() );
		self::assertCount( 1, $db->calls );
		$db->present = array( 'wp_posts', 'wp_options' );
		self::assertSame( array( 'wp_posts' => 0, 'wp_options' => 0 ), ( new Wstm167_Fresh_Install( $db ) )->preinstall() );
		self::assertStringContainsString( 'LIMIT 33', $db->calls[0] );
	}

	public function testForeignNonemptyUnreadableAndNoncanonicalCountsRefuseWithoutWrites(): void {
		foreach ( array( 'foreign', 'nonempty', 'unreadable', 'null', 'false', 'numeric-string' ) as $kind ) {
			$db = new Wstm_Fresh_Database_Model(); $db->present = array( 'wp_posts' );
			if ( 'foreign' === $kind ) { $db->present[] = 'foreign_table'; }
			if ( 'unreadable' === $kind ) { $db->unreadable = true; }
			if ( 'nonempty' === $kind ) { $db->count = '1'; }
			if ( 'null' === $kind ) { $db->count = null; }
			if ( 'false' === $kind ) { $db->count = false; }
			if ( 'numeric-string' === $kind ) { $db->count = '0e0'; }
			try { ( new Wstm167_Fresh_Install( $db ) )->preinstall(); self::fail( 'Unsafe pre-install state accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'Fresh installation refused', $error->getMessage() ); }
			foreach ( $db->calls as $sql ) { self::assertSame( 0, strpos( $sql, 'SELECT ' ) ); }
		}
	}

	public function testActualDefaultComparatorRequiresAllNativeInsertsAndExactDependencies(): void {
		list( $tables, $inserts, $columns ) = $this->defaults();
		self::assertSame( array( 1, 2, 3 ), Wstm167_Fresh_Install::defaults( $tables, $inserts, 7, $columns ) );
		foreach ( array( 'foreign-post', 'foreign-comment', 'foreign-meta', 'foreign-commentmeta', 'drift', 'default-drift', 'actor', 'ids', 'missing-origin' ) as $kind ) {
			list( $tables, $inserts, $columns ) = $this->defaults();
			switch ( $kind ) {
				case 'foreign-post': $tables['posts'][] = $tables['posts'][0]; break;
				case 'foreign-comment': $tables['comments'][] = $tables['comments'][0]; break;
				case 'foreign-meta': $tables['postmeta'][] = $tables['postmeta'][0]; break;
				case 'foreign-commentmeta': $tables['commentmeta'][] = array( 'comment_id' => '1' ); break;
				case 'drift': $tables['posts'][0]['post_content'] = 'Foreign replacement'; break;
				case 'default-drift': $tables['posts'][0]['post_password'] = 'Foreign password'; break;
				case 'actor': $tables['users'][0]['ID'] = '8'; break;
				case 'ids': $tables['posts'][0]['ID'] = '9'; break;
				case 'missing-origin': array_pop( $inserts ); break;
			}
			try { Wstm167_Fresh_Install::defaults( $tables, $inserts, 7, $columns ); self::fail( 'Unproven default adopted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'Fresh installation refused', $error->getMessage() ); }
		}
	}

	public function testForeignFixtureWritesCannotAcquireNativeProvenance(): void {
		$db = new Wstm_Fresh_Database_Model(); $preparation = new Wstm167_Fresh_Install( $db );
		foreach ( array( "INSERT INTO wp_posts (post_content) VALUES ('foreign')",
			"UPDATE wp_posts SET post_title='foreign'", 'DELETE p FROM wp_posts p', 'INSERT IGNORE INTO wp_comments VALUES (1)' ) as $sql ) {
			try { $preparation->observe( $sql ); self::fail( 'Foreign write received native provenance.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'Fresh installation refused', $error->getMessage() ); }
		}
		self::assertSame( 'SELECT * FROM wp_posts', $preparation->observe( 'SELECT * FROM wp_posts' ) );
	}
}
