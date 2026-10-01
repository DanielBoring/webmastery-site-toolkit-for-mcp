<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-input.php';

final class InputProofTest extends TestCase {
	private function runner_fragment( string $start_marker, string $end_marker ): string {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' ) );
		self::assertSame( 1, substr_count( $source, $start_marker ) );
		self::assertSame( 1, substr_count( $source, $end_marker ) );
		$start = strpos( $source, $start_marker );
		$end = strpos( $source, $end_marker );
		self::assertGreaterThan( $start, $end );
		return substr( $source, $start, $end - $start );
	}

	private function isolated_runner_result( string $code ): array {
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__, 2 ) );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		return json_decode( $output, true, 32, JSON_THROW_ON_ERROR );
	}

	public static function parent_reader_cases(): array {
		$semantics = 'Hierarchical detach/omission changed parent semantics.';
		$shape = 'Missing or malformed positive parent row.';
		$identity = 'Malformed or nonrepresentable positive parent identity.';
		$cases = array(
			'HTTP separate stores' => array( 'normal', 'http', array( null, null ) ),
			'direct coherent store' => array( 'normal', 'direct', array( null, null ) ),
			'permission has no parent query' => array( 'normal', 'permission', array( null, null ) ),
			'native integer columns' => array( 'integers', 'http', array( null, null ) ),
			'old cached reader rejects SQL detach' => array( 'old-reader', 'http', array( $semantics, null ) ),
			'SQL nonzero with cached zero' => array( 'inverse', 'http', array( $semantics, null ) ),
			'old reader falsely accepts SQL nonzero' => array( 'old-reader-inverse', 'http', array( null, null ) ),
			'next local seed must invalidate target' => array( 'no-invalidation', 'http', array( null, 'Local seed read stale cache.' ) ),
			'SQL error' => array( 'sql-error', 'http', array( 'Cannot read positive parent control.', 'Cannot read positive parent control.' ) ),
			'wrong owned ID' => array( 'wrong-id', 'http', array( 'Positive parent row belongs to another target.', 'Positive parent row belongs to another target.' ) ),
			'maximum representable parent remains wrong' => array( 'maximum', 'http', array( $semantics, $semantics ) ),
		);
		foreach ( array( 'missing', 'false-row', 'object-row', 'missing-parent', 'extra-column' ) as $variant ) {
			$cases[ $variant ] = array( $variant, 'http', array( $shape, $shape ) );
		}
		foreach ( array( 'null', 'boolean', 'float', 'array', 'object', 'empty', 'negative', 'negative-integer', 'leading-zero', 'whitespace', 'decimal', 'exponent', 'overflow', 'same-width-overflow', 'overflow-id', 'boolean-id' ) as $variant ) {
			$cases[ $variant ] = array( $variant, 'http', array( $identity, $identity ) );
		}
		return $cases;
	}

	/** @dataProvider parent_reader_cases */
	public function test_actual_parent_controls_use_fresh_owned_sql_and_targeted_preseed_invalidation( string $variant, string $boundary, array $errors ): void {
		$fragment = $this->runner_fragment( "\tforeach ( array( array( 'parent' => 0 ), array() )", "} catch ( Throwable \$error ) {\n\t\$summary['fatal']" );
		$old_reader = str_starts_with( $variant, 'old-reader' );
		if ( $old_reader ) {
			$start = strpos( $fragment, "\t\t\t\tglobal \$wpdb;" );
			$last = "\t\t\t\twstm126_require( \$expected_parent === \$row['post_parent'], 'Hierarchical detach/omission changed parent semantics.' );";
			self::assertNotFalse( $start );
			self::assertSame( 1, substr_count( $fragment, $last ) );
			$end = strpos( $fragment, $last ) + strlen( $last );
			$fragment = substr_replace( $fragment, "\t\t\t\twstm126_require( \$expected_parent === (int) get_post( \$posts['page'] )->post_parent, 'Hierarchical detach/omission changed parent semantics.' );", $start, $end - $start );
		}
		if ( 'no-invalidation' === $variant ) {
			$fragment = str_replace( "\t\t\tclean_post_cache( \$posts['page'] );\n", '', $fragment, $count );
			self::assertSame( 1, $count );
		}
		// Only the runner fragment is real; these separate stores model process-local cache.
		$code = '$variant=' . var_export( $variant, true ) . ';$boundary=' . var_export( $boundary, true ) . ';' . <<<'PHP'
define('ARRAY_A', 'ARRAY_A');
function wstm126_require($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
$posts = ['page' => 42, 'parent-page' => 9]; $users = ['administrator' => 7]; $run = 'owned-proof';
$database = ['post_parent' => 9, 'post_title' => 'original'];
$cache = [42 => $database, 99 => ['post_parent' => 77, 'post_title' => 'unrelated']];
$invalidations = []; $seed_reads = []; $invoke_parents = []; $records = []; $measuring = false;
function wp_set_current_user($id) { wstm126_require(7 === $id, 'Wrong actor.'); }
function clean_post_cache($id) {
	wstm126_require(42 === $id && !$GLOBALS['measuring'], 'Wrong or measured invalidation.');
	$GLOBALS['invalidations'][] = $id; unset($GLOBALS['cache'][$id]);
}
function get_post($id) {
	wstm126_require(42 === $id, 'Wrong cached target.');
	if (!isset($GLOBALS['cache'][$id])) { $GLOBALS['cache'][$id] = $GLOBALS['database']; }
	return (object) $GLOBALS['cache'][$id];
}
function wp_update_post($input, $errors) {
	wstm126_require(['ID' => 42, 'post_parent' => 9] === $input && true === $errors && !$GLOBALS['measuring'], 'Wrong or measured local seed.');
	$old = get_post(42); $GLOBALS['seed_reads'][] = $old->post_parent;
	wstm126_require($GLOBALS['database']['post_parent'] === $old->post_parent, 'Local seed read stale cache.');
	$GLOBALS['database']['post_parent'] = 9; $GLOBALS['cache'][42] = $GLOBALS['database']; return 42;
}
$wpdb = new class {
	public string $posts = 'owned_posts';
	public string $last_error = '';
	public array $queries = [];
	public function prepare($query, $id) {
		wstm126_require('SELECT ID, post_parent FROM owned_posts WHERE ID = %d' === $query && 42 === $id, 'Unbounded parent query.');
		return 'SELECT ID, post_parent FROM owned_posts WHERE ID = 42';
	}
	public function get_row($query, $format) {
		wstm126_require('SELECT ID, post_parent FROM owned_posts WHERE ID = 42' === $query && ARRAY_A === $format && !$GLOBALS['measuring'], 'Wrong or measured oracle query.');
		$this->queries[] = $query; $variant = $GLOBALS['variant'];
		$row = ['ID' => '42', 'post_parent' => (string) $GLOBALS['database']['post_parent']];
		if ('sql-error' === $variant) { $this->last_error = 'modeled SQL failure'; return $row; }
		if ('missing' === $variant) { return null; }
		if ('false-row' === $variant) { return false; }
		if ('object-row' === $variant) { return (object) $row; }
		if ('missing-parent' === $variant) { unset($row['post_parent']); }
		if ('extra-column' === $variant) { $row['unexpected'] = 1; }
		if ('wrong-id' === $variant) { $row['ID'] = '43'; }
		if ('overflow-id' === $variant) { $row['ID'] = (string) PHP_INT_MAX . '0'; }
		if ('boolean-id' === $variant) { $row['ID'] = true; }
		if ('integers' === $variant) { $row = ['ID' => 42, 'post_parent' => $GLOBALS['database']['post_parent']]; }
		$invalid = ['null' => null, 'boolean' => false, 'float' => 0.0, 'array' => [], 'object' => (object) [], 'empty' => '', 'negative' => '-1',
			'negative-integer' => -1, 'leading-zero' => '00', 'whitespace' => ' 0', 'decimal' => '0.0', 'exponent' => '0e0',
			'overflow' => (string) PHP_INT_MAX . '0', 'same-width-overflow' => substr((string) PHP_INT_MAX, 0, -1) . '8', 'maximum' => (string) PHP_INT_MAX];
		if (array_key_exists($variant, $invalid)) { $row['post_parent'] = $invalid[$variant]; }
		return $row;
	}
};
$invoke = static function ($slug, $input, $role, &$entry) use ($boundary, $variant) {
	wstm126_require('update-page' === $slug && 42 === $input['page_id'] && 'administrator' === $role, 'Wrong invocation.');
	$GLOBALS['measuring'] = true;
	try {
		$entry['before'] = $GLOBALS['database'];
		if ('permission' !== $boundary) {
			if (array_key_exists('parent', $input)) {
				wstm126_require(0 === $input['parent'], 'Detach lost integer zero.');
				$GLOBALS['database']['post_parent'] = str_contains($variant, 'inverse') ? 13 : 0;
			}
			$GLOBALS['database']['post_title'] = $input['title'];
			if ('direct' === $boundary) { $GLOBALS['cache'][42] = $GLOBALS['database']; }
			if (str_contains($variant, 'inverse') && array_key_exists('parent', $input)) { $GLOBALS['cache'][42]['post_parent'] = 0; }
		}
		$entry['after'] = $GLOBALS['database'];
		$entry['evidence'] = ['callbacks' => [['calls' => 1]], 'mutations' => 'permission' === $boundary ? [] : ['post-write']];
		$GLOBALS['invoke_parents'][] = $GLOBALS['database']['post_parent'];
		return ['success' => true];
	} finally { $GLOBALS['measuring'] = false; }
};
$record = static function ($label, $callback) use (&$records) {
	$entry = []; $error = null;
	try { $callback($entry); } catch (RuntimeException $failure) { $error = $failure->getMessage(); }
	$records[] = ['label' => $label, 'error' => $error, 'parent' => $entry['parent'] ?? null];
};
PHP;
		$code .= 'eval(' . var_export( $fragment, true ) . ');echo json_encode([$records,$invalidations,$seed_reads,$invoke_parents,$wpdb->queries,$cache[99]],JSON_THROW_ON_ERROR);';
		[ $records, $invalidations, $seed_reads, $invoke_parents, $queries, $unrelated ] = $this->isolated_runner_result( $code );
		self::assertSame( array( 'allowed hierarchical detach/omission 0', 'allowed hierarchical detach/omission 1' ), array_column( $records, 'label' ) );
		self::assertSame( $errors, array_column( $records, 'error' ) );
		self::assertSame( 'no-invalidation' === $variant ? array() : array( 42, 42 ), $invalidations );
		$inverse = str_contains( $variant, 'inverse' );
		self::assertSame( array( 9, 'permission' === $boundary || 'no-invalidation' === $variant ? 9 : ( $inverse ? 13 : 0 ) ), $seed_reads );
		self::assertSame( 'no-invalidation' === $variant ? array( 0 ) : array( 'permission' === $boundary ? 9 : ( $inverse ? 13 : 0 ), 9 ), $invoke_parents );
		self::assertCount( $old_reader || 'permission' === $boundary ? 0 : ( 'no-invalidation' === $variant ? 1 : 2 ), $queries );
		self::assertSame( array( 'post_parent' => 77, 'post_title' => 'unrelated' ), $unrelated );
		foreach ( $records as $index => $record ) {
			$has_proof = ! $old_reader && 'permission' !== $boundary && ( null === $errors[ $index ] || 'Hierarchical detach/omission changed parent semantics.' === $errors[ $index ] );
			self::assertSame(
				$has_proof ? array( 'id' => 42, 'expected' => 0 === $index ? 0 : 9, 'observed' => 'maximum' === $variant ? PHP_INT_MAX : $invoke_parents[ $index ] ) : null,
				$record['parent']
			);
		}
	}

	public static function fixture_date_cases(): array {
		return array(
			'fixed dates' => array( 'normal', null, 5, true ),
			'old floating draft seed' => array( 'old-floating', null, 5, false ),
			'changed captured local date' => array( 'changed-local', 'Fixture dates differ from the fixed ownership plan.', 0, null ),
			'zero captured GMT date' => array( 'zero-gmt', 'Fixture dates differ from the fixed ownership plan.', 0, null ),
			'missing captured GMT column' => array( 'missing-gmt', 'Fixture dates differ from the fixed ownership plan.', 0, null ),
			'missing captured target' => array( 'missing-target', 'Fixture dates differ from the fixed ownership plan.', 0, null ),
			'empty local conversion' => array( 'empty-local', 'Cannot resolve fixed fixture date.', 0, null ),
			'zero local conversion' => array( 'zero-local', 'Cannot resolve fixed fixture date.', 0, null ),
		);
	}

	/** @dataProvider fixture_date_cases */
	public function test_actual_seed_verifies_fixed_dates_before_immutable_identity_capture( string $variant, ?string $error, int $created, ?bool $stable ): void {
		$fragment = $this->runner_fragment( "\t\$seed_gmt =", "\t\$invoke = static function" );
		if ( 'old-floating' === $variant ) {
			foreach ( array(
				", 'post_date' => \$seed_date ) );" => ' ) );',
				", 'post_date' => \$seed_date, 'post_date_gmt' => \$seed_gmt" => '',
				"\t\twstm126_require( isset( \$created[ \$id ] ) && \$seed_date === ( \$created[ \$id ]['post_date'] ?? null ) && \$seed_gmt === ( \$created[ \$id ]['post_date_gmt'] ?? null ), 'Fixture dates differ from the fixed ownership plan.' );\n" => '',
			) as $before => $after ) {
				$fragment = str_replace( $before, $after, $fragment, $count );
				self::assertSame( 1, $count );
			}
		}
		$code = 'require ' . var_export( dirname( __DIR__ ) . '/e2e/input-schema-cleanup.php', true ) . ';$variant=' . var_export( $variant, true ) . ';' . <<<'PHP'
define('ARRAY_A', 'ARRAY_A');
function wstm126_require($valid, $message) { if (!$valid) { throw new RuntimeException($message); } }
function get_date_from_gmt($date) {
	wstm126_require('2000-01-01 12:00:00' === $date, 'Seed is not historical UTC.');
	if ('empty-local' === $GLOBALS['variant']) { return ''; }
	if ('zero-local' === $GLOBALS['variant']) { return '0000-00-00 00:00:00'; }
	return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Honolulu'))->format('Y-m-d H:i:s');
}
function is_wp_error($id) { return false; }
$users = ['administrator' => 7]; $run = 'owned-proof'; $posts = []; $rows = []; $plans = []; $identities = []; $inputs = [];
$journal = new class {
	public function plan($name, $identity) { $GLOBALS['plans'][$name] = $identity; }
	public function created($name, $kind, $id, $identity) {
		wstm126_require('posts' === $kind && isset($GLOBALS['plans'][$name]), 'Missing immutable plan.');
		foreach ($GLOBALS['plans'][$name] as $field => $value) { wstm126_require($value === $identity[$field], 'Captured identity differs from plan.'); }
		$GLOBALS['identities'][$id] = $identity;
	}
};
function wp_insert_post($input, $errors) {
	wstm126_require(true === $errors && 'draft' === $input['post_status'], 'Wrong draft seed.');
	$id = 42 + count($GLOBALS['rows']); $GLOBALS['inputs'][] = $input;
	$GLOBALS['rows'][$id] = $input + ['ID' => (string) $id, 'guid' => 'owned:' . $id, 'post_date' => '2026-09-24 20:00:00', 'post_date_gmt' => '0000-00-00 00:00:00'];
	return $id;
}
$wpdb = new class {
	public string $posts = 'owned_posts';
	public string $postmeta = 'owned_postmeta';
	public string $users = 'owned_users';
	public string $usermeta = 'owned_usermeta';
	public string $terms = 'owned_terms';
	public string $term_taxonomy = 'owned_term_taxonomy';
	public string $term_relationships = 'owned_term_relationships';
	public string $comments = 'owned_comments';
	public string $commentmeta = 'owned_commentmeta';
	public string $links = 'owned_links';
	public string $options = 'owned_options';
	public string $last_error = '';
	public int $queries = 0;
	private array $expected = [
		'SELECT * FROM owned_posts ORDER BY ID', 'SELECT * FROM owned_postmeta ORDER BY meta_id',
		'SELECT * FROM owned_users ORDER BY ID', 'SELECT * FROM owned_usermeta ORDER BY umeta_id',
		'SELECT * FROM owned_terms ORDER BY term_id', 'SELECT * FROM owned_term_taxonomy ORDER BY term_taxonomy_id',
		'SELECT * FROM owned_term_relationships ORDER BY object_id, term_taxonomy_id',
		'SELECT * FROM owned_comments ORDER BY comment_ID', 'SELECT * FROM owned_commentmeta ORDER BY meta_id',
		'SELECT * FROM owned_links ORDER BY link_id',
		"SELECT option_value FROM owned_options WHERE option_name = 'cron'",
		"SELECT option_value FROM owned_options WHERE option_name = 'wstm126_http'",
	];
	public function prepare($query, $name) {
		wstm126_require('SELECT option_value FROM owned_options WHERE option_name = %s' === $query && in_array($name, ['cron', 'wstm126_http'], true), 'Unexpected snapshot option identity.');
		return "SELECT option_value FROM owned_options WHERE option_name = '" . $name . "'";
	}
	public function get_results($query, $format) {
		wstm126_require(ARRAY_A === $format && $this->queries < 60 && $this->expected[$this->queries % 12] === $query, 'Unexpected snapshot query or order.');
		$this->queries++;
		switch ($query) {
			case 'SELECT * FROM owned_posts ORDER BY ID':
				$rows = $GLOBALS['rows']; $id = array_key_last($rows);
				wstm126_require(count($rows) >= 1 && count($rows) <= 5 && array_keys($rows) === range(42, 41 + count($rows)), 'Unexpected seeded post IDs.');
				foreach ($rows as $key => $row) {
					$type = ['post', 'page', 'mcp_book', 'mcp_case_study', 'parent-page'][$key - 42];
					wstm126_require((string) $key === ($row['ID'] ?? null) && 7 === ($row['post_author'] ?? null)
						&& ('parent-page' === $type ? 'page' : $type) === ($row['post_type'] ?? null)
						&& 'owned-proof-' . $type === ($row['post_name'] ?? null), 'Unexpected seeded post identity.');
				}
				if ('changed-local' === $GLOBALS['variant']) { $rows[$id]['post_date'] = '2000-01-02 02:00:00'; }
				if ('zero-gmt' === $GLOBALS['variant']) { $rows[$id]['post_date_gmt'] = '0000-00-00 00:00:00'; }
				if ('missing-gmt' === $GLOBALS['variant']) { unset($rows[$id]['post_date_gmt']); }
				if ('missing-target' === $GLOBALS['variant']) { unset($rows[$id]); }
				return array_values($rows);
			case 'SELECT * FROM owned_postmeta ORDER BY meta_id':
			case 'SELECT * FROM owned_users ORDER BY ID':
			case 'SELECT * FROM owned_usermeta ORDER BY umeta_id':
			case 'SELECT * FROM owned_terms ORDER BY term_id':
			case 'SELECT * FROM owned_term_taxonomy ORDER BY term_taxonomy_id':
			case 'SELECT * FROM owned_term_relationships ORDER BY object_id, term_taxonomy_id':
			case 'SELECT * FROM owned_comments ORDER BY comment_ID':
			case 'SELECT * FROM owned_commentmeta ORDER BY meta_id':
			case 'SELECT * FROM owned_links ORDER BY link_id':
				return [];
			case "SELECT option_value FROM owned_options WHERE option_name = 'cron'":
				return [['option_value' => serialize([])]];
			case "SELECT option_value FROM owned_options WHERE option_name = 'wstm126_http'":
				return [['option_value' => serialize(['owner' => 'owned-proof'])]];
			default:
				throw new RuntimeException('Unexpected snapshot table.');
		}
	}
};
$error = null;
PHP;
		$code .= 'try{eval(' . var_export( $fragment, true ) . ');}catch(RuntimeException $failure){$error=$failure->getMessage();}' . <<<'PHP'
$stable = null;
if (count($identities) > 0) {
	$stable = true;
	foreach ($rows as $id => $row) {
		$row['post_title'] = 'legitimate later write';
		if ('0000-00-00 00:00:00' === $row['post_date_gmt']) { $row['post_date'] = '2026-09-24 20:01:00'; }
		$stable = $stable && $identities[$id] === Wstm126_Cleanup::post_identity($row);
	}
}
echo json_encode([$error,$plans,$identities,$inputs,$stable,$wpdb->queries],JSON_THROW_ON_ERROR);
PHP;
		[ $actual_error, $plans, $identities, $inputs, $actual_stable, $queries ] = $this->isolated_runner_result( $code );
		self::assertSame( $error, $actual_error );
		self::assertCount( $created, $identities );
		self::assertSame( $stable, $actual_stable );
		self::assertCount( null === $error ? 5 : ( str_contains( $variant, 'local' ) && str_starts_with( (string) $error, 'Cannot resolve' ) ? 0 : 1 ), $plans );
		self::assertCount( count( $plans ), $inputs );
		self::assertSame( 12 * count( $inputs ), $queries, 'Each seed must use the complete real SQL/options snapshot.' );
		if ( 'normal' === $variant ) {
			self::assertSame( array( 'post:post', 'post:page', 'post:mcp_book', 'post:mcp_case_study', 'post:parent-page' ), array_keys( $plans ) );
			foreach ( $inputs as $input ) {
				self::assertSame( '2000-01-01 02:00:00', $input['post_date'] );
				self::assertSame( '2000-01-01 12:00:00', $input['post_date_gmt'] );
			}
			foreach ( $identities as $identity ) {
				self::assertSame( array( 'ID', 'post_author', 'post_type', 'post_name', 'post_date', 'guid' ), array_keys( $identity ) );
				self::assertSame( '2000-01-01 02:00:00', $identity['post_date'] );
			}
		}
	}

	public static function registry_initialization_cases(): array {
		return array(
			'lazy registration' => array( 'lazy', 'ready', '', 1 ),
			'old order mutant' => array( 'old-order', ReflectionException::class, 'Class "Webmastery_MCP_Ability" does not exist', 0 ),
			'missing API' => array( 'missing-api', RuntimeException::class, 'Required native abilities runtime unavailable.', 0 ),
			'missing ability class' => array( 'missing-class', ReflectionException::class, 'Class "Webmastery_MCP_Ability" does not exist', 1 ),
			'foreign ability source' => array( 'foreign-ability', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
			'foreign input source' => array( 'foreign-input', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
			'foreign response source' => array( 'foreign-response', RuntimeException::class, 'Mixed loaded production sources.', 1 ),
		);
	}

	public function test_plugin_loads_and_registers_input_guard_exactly_once_after_permissions(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/webmastery-site-toolkit-for-mcp.php' );
		$permissions = "require_once __DIR__ . '/includes/class-permissions.php';";
		$input = "require_once __DIR__ . '/includes/class-input.php';";
		$filter = "add_filter( 'wp_register_ability_args', [ Webmastery_MCP_Input::class, 'register_args' ], 20, 2 );";
		foreach ( array( $permissions, $input, $filter ) as $statement ) {
			self::assertSame( 1, substr_count( $source, $statement ), 'Loader wiring must appear exactly once: ' . $statement );
		}
		self::assertSame( 1, substr_count( $source, '/includes/class-input.php' ) );
		self::assertSame( 1, substr_count( $source, 'Webmastery_MCP_Input::class' ) );
		self::assertLessThan( strpos( $source, $input ), strpos( $source, $permissions ) );
		self::assertLessThan( strpos( $source, $filter ), strpos( $source, $input ) );
	}

	/** @dataProvider registry_initialization_cases */
	public function test_runner_initializes_lazy_registry_before_source_reflection( string $variant, string $expected_type, string $expected_message, int $expected_initializations ): void {
		$root = dirname( __DIR__, 2 );
		$runner = dirname( __DIR__ ) . '/e2e/input-schema-runner.php';
		$source = str_replace( "\r\n", "\n", file_get_contents( $runner ) );
		$start_marker = "if ( ! defined( 'WSTM126_DISPOSABLE_RUNTIME' )";
		$end_marker = "\$summary['source_hashes'] =";
		self::assertSame( 1, substr_count( $source, $start_marker ) );
		self::assertSame( 1, substr_count( $source, $end_marker ) );
		$start = strpos( $source, $start_marker );
		$end = strpos( $source, $end_marker );
		self::assertGreaterThan( $start, $end );
		$preflight = substr( $source, $start, $end - $start );
		if ( 'old-order' === $variant ) {
			$preflight = str_replace(
				"wstm126_require( function_exists( 'wp_get_abilities' ), 'Required native abilities runtime unavailable.' );\nwp_get_abilities();\n",
				'',
				$preflight,
				$count
			);
			self::assertSame( 1, $count, 'Restore exactly the original pre-initialization order.' );
		}
		// Evaluate the actual preflight without bootstrapping WordPress or seeding fixtures.
		$preflight = str_replace( '__DIR__', var_export( dirname( $runner ), true ), $preflight );
		$code = 'define("ABSPATH",' . var_export( $root . '/', true ) . ');'
			. 'define("WSTM126_DISPOSABLE_RUNTIME",true);define("WSTM126_STAGE_TOKEN",str_repeat("a",32));'
			. '$stage_token=WSTM126_STAGE_TOKEN;$source_sha=str_repeat("b",40);$project="registry-test";$boundary="direct";'
			. '$GLOBALS["initializations"]=0;$GLOBALS["observations"]=0;class WP_Ability{}class WP_Error{}'
			. 'function wstm126_begin(){}'
			. 'function wstm126_require($valid,$message){if(!$valid){throw new RuntimeException($message);}}'
			. 'function get_option($name,$default){$GLOBALS["observations"]++;return false;}'
			. 'function get_bloginfo($name){return "isolated-double";}';
		foreach ( array( 'input', 'response' ) as $name ) {
			$code .= 'foreign-' . $name === $variant
				? 'class Webmastery_MCP_' . ucfirst( $name ) . '{}'
				: 'require ' . var_export( $root . '/includes/class-' . $name . '.php', true ) . ';';
		}
		if ( 'missing-api' !== $variant ) {
			$initialize = 'missing-class' === $variant ? '' : (
				'foreign-ability' === $variant ? 'class Webmastery_MCP_Ability{}' : 'require ' . var_export( $root . '/includes/class-ability.php', true ) . ';'
			);
			$code .= 'function wp_get_abilities(){$GLOBALS["initializations"]++;' . $initialize . 'return [];}';
		}
		$code .= '$before=class_exists("Webmastery_MCP_Ability",false);$type="ready";$message="";$verified=false;'
			. 'try{eval(' . var_export( $preflight, true ) . ');$verified=true;}'
			. 'catch(Throwable $error){$type=get_class($error);$message=$error->getMessage();}'
			. 'echo json_encode([$type,$message,$GLOBALS["initializations"],$GLOBALS["observations"],$before,$verified,$summary["expected_cases"]],JSON_THROW_ON_ERROR);';
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		self::assertSame(
			array( $expected_type, $expected_message, $expected_initializations, 1, false, 'ready' === $expected_type, 152 ),
			json_decode( $output, true, 32, JSON_THROW_ON_ERROR )
		);
	}

	public function test_runtime_adds_exact_typed_destructive_matrix_and_hashes_native_validator(): void {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-runner.php' ) );
		self::assertStringContainsString( "__DIR__ . '/../../includes/class-ability.php'", $source );
		$start = strpos( $source, "\tforeach ( array(\n\t\t'bulk-trash-posts'" );
		$end = strpos( $source, "\tforeach ( \$cases as \$label =>" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$posts = array( 'post' => 42 );
		foreach ( array( 'direct', 'ability', 'permission', 'http', 'individual' ) as $boundary ) {
			$cases = array();
			eval( substr( $source, $start, $end - $start ) );
			self::assertCount( 43, $cases );
			foreach ( array( 'bulk-trash-posts' => 'ids', 'bulk-publish-posts' => 'ids', 'delete-media' => 'media_id', 'delete-category' => 'category_id', 'delete-tag' => 'tag_id' ) as $slug => $key ) {
				$base = array( $key => 'ids' === $key ? array( 42 ) : 42 );
				foreach ( array( array(), array( 'confirm' => false ), array( 'confirm' => null ), array( 'confirm' => 'true' ), array( 'confirm' => 1 ) ) as $index => $flags ) {
					self::assertSame( array( $slug, $base + $flags, 'direct' === $boundary ? 'missing_confirmation' : 'ability_invalid_input' ), $cases[ "{$slug}:confirm:{$index}" ] );
				}
				$flag = 'delete-media' === $slug ? 'force' : ( str_starts_with( $slug, 'bulk-' ) ? 'dry_run' : null );
				if ( null !== $flag ) {
					foreach ( array( 'true', 'false', 0, 1, null, array() ) as $index => $value ) {
						self::assertSame( array( $slug, $base + array( 'confirm' => true, $flag => $value ), 'direct' === $boundary ? 'invalid_input' : 'ability_invalid_input' ), $cases[ "{$slug}:{$flag}:{$index}" ] );
					}
				}
			}
		}
	}

	public static function unsafe_invocations(): array {
		return array_map( static fn( $value ) => array( $value ), array( null, '', '0', 'true', ' 1', '1 ' ) );
	}

	/** @dataProvider unsafe_invocations */
	public function test_runtime_opt_in_fails_before_bootstrap( ?string $value ): void {
		$path = dirname( __DIR__ ) . '/e2e/input-schema-runner.php';
		$code = 'putenv(' . var_export( 'WSTM126_DISPOSABLE' . ( null === $value ? '' : '=' . $value ), true ) . '); require ' . var_export( $path, true ) . ';';
		$process = proc_open( array( PHP_BINARY, '-r', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertNotSame( 0, proc_close( $process ) );
		self::assertStringContainsString( 'WSTM126_DISPOSABLE=1', $output );
		self::assertStringNotContainsString( 'wp-load.php', $output );
	}

	public function test_type_enum_and_closed_key_mutants_reach_the_sentinel_callback(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-input.php' );
		$mutants = array(
			array( 'if ( ! $valid_type )', 'if ( false )', array( 'value' => 42 ) ),
			array( "isset( \$schema['enum'] ) &&", "false && isset( \$schema['enum'] ) &&", array( 'value' => 'allowed!' ) ),
			array( "false === ( \$schema['additionalProperties'] ?? true )", "false && false === ( \$schema['additionalProperties'] ?? true )", array( 'value' => 'allowed', 'unknown' => true ) ),
		);
		foreach ( $mutants as $index => [ $before, $after, $input ] ) {
			$mutant = str_replace( $before, $after, $source, $count );
			self::assertSame( 1, $count, 'Mutation did not target exactly one production check.' );
			$namespace = 'Wstm126Mutant' . $index;
			eval( 'namespace ' . $namespace . '; use \WP_Error; use \stdClass; use \Webmastery_MCP_Response; use \Webmastery_MCP_Posts; use \Webmastery_MCP_Media; use \Webmastery_MCP_Taxonomy; ' . substr( $mutant, 5 ) );
			$calls = 0;
			$args = array(
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string', 'enum' => array( 'allowed' ) ) ) ),
				'execute_callback' => static function () use ( &$calls ) { $calls++; return array( 'success' => true ); },
			);
			if ( 0 === $index ) {
				unset( $args['input_schema']['properties']['value']['enum'] );
			}
			$normal = Webmastery_MCP_Input::register_args( $args, 'webmastery-site-toolkit-for-mcp/probe' );
			self::assertFalse( $normal['execute_callback']( $input )['success'] );
			self::assertSame( 0, $calls );
			$class = $namespace . '\\Webmastery_MCP_Input';
			$broken = $class::register_args( $args, 'webmastery-site-toolkit-for-mcp/probe' );
			self::assertTrue( $broken['execute_callback']( $input )['success'], 'Negative control did not remove the intended safeguard.' );
			self::assertSame( 1, $calls, 'Mutation must reach a real sentinel, not merely change the error message.' );
		}
	}

	public function test_state_hook_and_callback_work_oracles_reject_negative_controls(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/input-schema-fixture.php' );
		$start = strpos( $source, 'function wstm126_require' );
		$end = strpos( $source, "add_filter( 'wp_register_ability_args'" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		eval( 'namespace Wstm126Oracle; use \RuntimeException; ' . substr( $source, $start, $end - $start ) );
		$clean = array( 'callbacks' => array( array( 'queries' => 0, 'capabilities' => 0 ) ), 'mutations' => array() );
		Wstm126Oracle\wstm126_assert_no_work( array( 'same' ), array( 'same' ), $clean );
		$cases = array(
			array( array( 'same' ), array() ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => false ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array() ) ) ) ),
			array( array( 'different' ), $clean ),
			array( array( 'same' ), array_replace( $clean, array( 'mutations' => array( 'write-then-rollback' ) ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array( 'queries' => 1, 'capabilities' => 0 ) ) ) ) ),
			array( array( 'same' ), array_replace( $clean, array( 'callbacks' => array( array( 'queries' => 0, 'capabilities' => 1 ) ) ) ) ),
		);
		foreach ( $cases as [ $after, $evidence ] ) {
			try {
				Wstm126Oracle\wstm126_assert_no_work( array( 'same' ), $after, $evidence );
				self::fail( 'Broken no-work control passed.' );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}

	}

	public function test_missing_schema_fault_restores_only_the_probes_original_permission_callback(): void {
		require_once __DIR__ . '/fixtures/error-fixture-input-stubs.php';
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/error-contract-fixture.php' );
		$start = strpos( $source, "\t\$probe = wp_register_ability( 'webmastery-site-toolkit-for-mcp/wstm118-missing-schema'" );
		$end = strpos( $source, "\twp_register_ability( 'wstm118-foreign/probe'" );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$permissions = $executions = 0;
		$args = array(
			'permission_callback' => static function () use ( &$permissions ) { $permissions++; return true; },
			'execute_callback' => static function () use ( &$executions ) { $executions++; return array( 'success' => true ); },
		);
		eval( 'namespace Wstm126Probe; use \ReflectionProperty; use \RuntimeException; ' . substr( $source, $start, $end - $start ) );
		$schema = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'input_schema' );
		$permission = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'permission_callback' );
		$execute = new ReflectionProperty( Wstm126Probe\WP_Ability::class, 'execute_callback' );
		foreach ( array( $schema, $permission, $execute ) as $property ) { $property->setAccessible( true ); }
		self::assertFalse( $probe->registered['input_schema']['additionalProperties'] );
		self::assertSame( array(), $schema->getValue( $probe ) );
		self::assertSame( $args['permission_callback'], $permission->getValue( $probe ) );
		self::assertSame( $probe->registered['execute_callback'], $execute->getValue( $probe ), 'Do not alter core invocation or the wrapped execute callback.' );
		self::assertTrue( ( $permission->getValue( $probe ) )( array( 'probe' => 1 ) ) );
		self::assertSame( 1, $permissions );
		self::assertSame( 0, $executions );

		// Clearing only the stored schema leaves the captured raw-permission schema active.
		$permission->setValue( $probe, $probe->registered['permission_callback'] );
		$error = ( $permission->getValue( $probe ) )( array( 'probe' => 1 ) );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'ability_invalid_input', Webmastery_MCP_Response::from_wp_error( $error )['error']['reason'] );
		self::assertSame( 1, $permissions, 'The schema-only mutant must not reach the original callback.' );
		self::assertSame( 0, $executions );

		$ordinary = Webmastery_MCP_Input::register_args( $args, 'webmastery-site-toolkit-for-mcp/ordinary-empty-input' );
		self::assertInstanceOf( WP_Error::class, $ordinary['permission_callback']( array( 'probe' => 1 ) ) );
		self::assertFalse( $ordinary['execute_callback']( array( 'probe' => 1 ) )['success'] );
		self::assertSame( 1, $permissions );
		self::assertSame( 0, $executions );
	}
}
