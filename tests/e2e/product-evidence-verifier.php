<?php

declare(strict_types=1);

require_once __DIR__ . '/product-runtime-context.php';
require_once __DIR__ . '/bounded-list-verifier.php';
require_once __DIR__ . '/untrusted-content-plan.php';
require_once __DIR__ . '/untrusted-content-wire.php';
require_once __DIR__ . '/untrusted-content-private-wire.php';
require_once __DIR__ . '/untrusted-content-resources.php';
require_once __DIR__ . '/untrusted-content-evidence.php';

final class Wstm_Product_Evidence_Gap extends RuntimeException {}

final class Wstm_Product_Evidence_Reader {
	private string $root;
	private array $anchor;
	private array $files = array();
	private array $directories = array();
	private array $inventories = array();
	private array $bytes = array( 'output' => 0, 'wire' => 0 );
	private int $output_limit;
	private bool $failed = false;

	public function __construct( string $root, string $stage ) {
		$this->root = $root;
		$this->anchor = Wstm108_Files::directory( $root );
		$this->output_limit = 'bounded' === $stage ? 536870912 : 134217728;
	}

	public function listing( string $relative = '' ): array {
		$path = '' === $relative ? $this->root : $this->path( $relative );
		$this->directory( $path );
		$handle = opendir( $path );
		if ( false === $handle ) { throw new RuntimeException( 'Cannot inspect selected export directory.' ); }
		$names = array();
		try {
			while ( false !== ( $name = readdir( $handle ) ) ) {
				if ( '.' === $name || '..' === $name ) { continue; }
				if ( count( $names ) >= 4096 ) { throw new RuntimeException( 'Export directory entry limit exceeded.' ); }
				$names[] = $name;
			}
		} finally { closedir( $handle ); }
		sort( $names, SORT_STRING );
		$this->directory( $path );
		if ( isset( $this->inventories[ $relative ] ) && $this->inventories[ $relative ] !== $names ) {
			$this->failed = true; throw new RuntimeException( 'Selected export inventory changed.' );
		}
		$this->inventories[ $relative ] = $names;
		return $names;
	}

	private function directory( string $path ): void {
		if ( $this->failed ) { throw new RuntimeException( 'Failed acquisition cannot retry.' ); }
		if ( ! isset( $this->directories[ $path ] ) && count( $this->directories ) + count( $this->files ) >= 4096 ) {
			$this->failed = true; throw new RuntimeException( 'Finite export path limit exhausted.' );
		}
		$this->failed = true;
		$identity = Wstm108_Files::directory( $path );
		if ( isset( $this->directories[ $path ] ) && $identity !== $this->directories[ $path ] ) {
			throw new RuntimeException( 'Export directory custody changed.' );
		}
		$this->directories[ $path ] = $identity; $this->failed = false;
	}

	private function path( string $relative ): string {
		if ( 1 !== preg_match( '~^[a-zA-Z0-9_-]+(?:[./][a-zA-Z0-9_-]+)*$~D', $relative )
			|| false !== strpos( $relative, '..' ) ) {
			throw new RuntimeException( 'Unsupported export-relative path.' );
		}
		return $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	}

	public function read( string $relative ): string {
		$path = $this->path( $relative );
		$this->directory( $this->root );
		$parent = $this->root;
		foreach ( array_slice( explode( '/', $relative ), 0, -1 ) as $part ) {
			$parent .= DIRECTORY_SEPARATOR . $part; $this->directory( $parent );
		}
		return $this->acquire( $path, $relative, 0 === strpos( $relative, 'wire/' ) || 'runner.json.http.jsonl' === $relative ? 'wire' : 'output' );
	}

	public function authority( string $file ): string {
		if ( ! in_array( $file, array( 'class-posts.php', 'class-media.php', 'class-seo.php', 'class-content-hygiene.php',
			'class-custom-post-types.php', 'class-list-query.php' ), true ) ) { throw new RuntimeException( 'Unsupported QA source subject.' ); }
		$parent = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'includes'; $this->directory( $parent );
		return hash( 'sha256', $this->acquire( $parent . DIRECTORY_SEPARATOR . $file, '#authority-' . $file, 'output' ) );
	}

	public function manifest(): string {
		$this->directory( __DIR__ );
		return $this->acquire( __DIR__ . DIRECTORY_SEPARATOR . 'abilities-manifest.json', '#manifest', 'output' );
	}

	private function acquire( string $path, string $relative, string $domain ): string {
		if ( $this->failed ) { throw new RuntimeException( 'Failed acquisition cannot retry.' ); }
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || 0100000 !== ( $stat['mode'] & 0170000 ) || 1 !== $stat['nlink']
			|| ! is_int( $stat['size'] ) || $stat['size'] < 0 || $stat['size'] > 33554432 ) {
			$this->failed = true;
			throw new RuntimeException( 'Missing, unsafe or oversized export file: ' . $relative );
		}
		$limit = 'wire' === $domain ? 134217728 : $this->output_limit;
		if ( ( ! isset( $this->files[ $relative ] ) && count( $this->files ) + count( $this->directories ) >= 4096 )
			|| $this->bytes[ $domain ] + $stat['size'] > $limit ) {
			$this->failed = true;
			throw new RuntimeException( 'Finite export read budget exhausted before acquisition.' );
		}
		$this->bytes[ $domain ] += $stat['size'];
		$this->failed = true;
		$identity = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
		$file = Wstm108_Files::read_bound( $path, $identity, $stat['size'] );
		if ( strlen( $file['bytes'] ) !== $stat['size'] ) { throw new RuntimeException( 'Export size drift.' ); }
		$binding = array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'size' => $stat['size'], 'path' => $path, 'domain' => $domain );
		if ( isset( $this->files[ $relative ] ) && $this->files[ $relative ] !== $binding ) {
			throw new RuntimeException( 'Export bytes or custody changed.' );
		}
		$this->files[ $relative ] = $binding;
		$this->failed = false;
		return $file['bytes'];
	}

	public function json( string $relative ): array {
		return self::decode_json( $this->read( $relative ) );
	}

	public static function decode_json( string $bytes ): array {
		$data = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) { throw new RuntimeException( 'Expected private JSON object or list.' ); }
		$stack = array(); $offset = 0;
		while ( preg_match( '/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]]/', $bytes, $token, PREG_OFFSET_CAPTURE, $offset ) ) {
			$text = $token[0][0]; $offset = $token[0][1] + strlen( $text );
			if ( '{' === $text || '[' === $text ) {
				$stack[] = array( 'object' => '{' === $text, 'keys' => array() );
			} elseif ( '}' === $text || ']' === $text ) {
				array_pop( $stack );
			} elseif ( $stack && $stack[ count( $stack ) - 1 ]['object'] && preg_match( '/\\G\\s*:/', $bytes, $unused, 0, $offset ) ) {
				$key = json_decode( $text, true, 2, JSON_THROW_ON_ERROR ); $index = count( $stack ) - 1;
				if ( isset( $stack[ $index ]['keys'][ $key ] ) ) { throw new RuntimeException( 'Duplicate private JSON field.' ); }
				$stack[ $index ]['keys'][ $key ] = true;
			}
		}
		return $data;
	}

	public function finish(): void {
		foreach ( array_keys( $this->directories ) as $path ) { $this->directory( $path ); }
		foreach ( $this->files as $relative => $file ) { $this->acquire( $file['path'], $relative, $file['domain'] ); }
		foreach ( array_keys( $this->inventories ) as $relative ) { $this->listing( $relative ); }
		foreach ( array_keys( $this->directories ) as $path ) { $this->directory( $path ); }
		if ( $this->anchor !== Wstm108_Files::directory( $this->root ) ) {
			throw new RuntimeException( 'Selected export root changed.' );
		}
	}
}

final class Wstm_Product_Evidence_Verifier {
	public static function check( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Product evidence refused: ' . $reason ); }
	}

	private static function keys( array $data, array $keys ): void {
		$actual = array_keys( $data );
		sort( $actual );
		sort( $keys );
		self::check( $actual === $keys, 'closed record fields' );
	}

	public static function context( string $bytes ): array {
		$data = Wstm167_Product_Context::decode( $bytes );
		self::check( is_string( $data['run'] ?? null ) && is_string( $data['token'] ?? null )
			&& is_string( $data['php_floor'] ?? null ), 'context scalar binding' );
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, $data['php_floor'] );
		return $data;
	}

	public static function verdict( string $stage, array $verdict, array $context, string $context_hash ): string {
		self::check( in_array( $stage, array( 'untrusted', 'bounded' ), true ), 'stage selector' );
		$binding = array( 'owner' => $context['run'], 'project' => $context['project'],
			'identity_kind' => $context['identity_kind'], 'identity_sha256' => $context['identity_sha256'] );
		self::check( 'wstm-product-verdict-v1' === ( $verdict['schema'] ?? null )
			&& $context_hash === ( $verdict['context_sha256'] ?? null ) && $binding === ( $verdict['binding'] ?? null ), 'verdict context binding' );
		$common = array( 'schema', 'context_sha256', 'binding', 'status', 'cleanup_complete' );
		$status = $verdict['status'] ?? null;
		if ( 'bootstrapping' === $status ) {
			self::keys( $verdict, 'untrusted' === $stage ? array_merge( $common, array( 'php', 'uid' ) ) : $common );
			self::check( false === $verdict['cleanup_complete'], 'bootstrap cleanup' );
		} elseif ( 'bootstrap' === ( $verdict['phase'] ?? null ) ) {
			self::keys( $verdict, array_merge( $common, array( 'phase', 'private_error', 'exit' ) ) );
			self::check( ( 'untrusted' === $stage ? 'refused-before-bootstrap' : 'failed' ) === $status
				&& false === $verdict['cleanup_complete'] && is_string( $verdict['private_error'] )
				&& ( 'untrusted' === $stage ? 2 : 1 ) === $verdict['exit'], 'bootstrap refusal' );
		} else {
			$fields = 'untrusted' === $stage
				? array( 'php', 'uid', 'expected_labels', 'observed_labels', 'passed', 'failed', 'exit' )
				: array( 'php', 'uid', 'catalogs', 'exit' );
			if ( 'bounded' === $stage && 'failed' === $status ) {
				$fields[] = 'private_error';
				if ( array_key_exists( 'private_cleanup_error', $verdict ) ) { $fields[] = 'private_cleanup_error'; }
				if ( array_key_exists( 'phase', $verdict ) ) {
					$fields[] = 'phase'; $fields[] = 'catalog';
					self::check( 'pre-acquisition' === $verdict['phase']
						&& in_array( $verdict['catalog'] ?? null, array( 'small', 'large' ), true ), 'failed pre-acquisition phase' );
				}
			}
			self::keys( $verdict, array_merge( $common, $fields ) );
			self::check( in_array( $status, 'bounded' === $stage ? array( 'passed', 'failed' ) : array( 'passed', 'failed', 'incomplete' ), true )
				&& is_bool( $verdict['cleanup_complete'] ) && ( 'passed' === $status ? 0 : 1 ) === $verdict['exit'], 'final status types' );
			if ( 'untrusted' === $stage ) {
				foreach ( array( 'expected_labels', 'observed_labels' ) as $key ) {
					self::check( is_array( $verdict[ $key ] ) && array_values( $verdict[ $key ] ) === $verdict[ $key ], 'label list' );
					foreach ( $verdict[ $key ] as $label ) { self::check( is_string( $label ), 'label type' ); }
				}
				foreach ( array( 'passed', 'failed' ) as $key ) {
					self::check( is_int( $verdict[ $key ] ) && $verdict[ $key ] >= 0, 'outcome count' );
				}
			} else {
				self::check( is_array( $verdict['catalogs'] ) && array_values( $verdict['catalogs'] ) === $verdict['catalogs']
					&& count( $verdict['catalogs'] ) <= 2, 'catalog list' );
				foreach ( $verdict['catalogs'] as $catalog ) {
					self::check( is_array( $catalog ), 'catalog record type' ); self::keys( $catalog, array( 'small', 'inventory', 'cleanup_complete' ) );
					self::check( is_bool( $catalog['small'] ) && true === $catalog['cleanup_complete'] && is_array( $catalog['inventory'] ), 'catalog verdict types' );
					self::keys( $catalog['inventory'], array( 'observations', 'expected_labels', 'observed_labels' ) );
					self::check( is_int( $catalog['inventory']['observations'] ) && $catalog['inventory']['observations'] >= 0, 'observation count type' );
					foreach ( array( 'expected_labels', 'observed_labels' ) as $key ) {
						$labels = $catalog['inventory'][ $key ]; self::check( is_array( $labels ) && array_values( $labels ) === $labels, 'inventory list type' );
						foreach ( $labels as $label ) { self::check( is_string( $label ), 'inventory label type' ); }
					}
				}
				foreach ( array( 'private_error', 'private_cleanup_error' ) as $key ) {
					if ( array_key_exists( $key, $verdict ) ) { self::check( is_string( $verdict[ $key ] ), 'private failure type' ); }
				}
			}
		}
		if ( array_key_exists( 'php', $verdict ) ) {
			self::check( is_string( $verdict['php'] ) && 1 === preg_match( '/^([0-9]+\\.[0-9]+)\\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/D', $verdict['php'], $matches )
				&& $context['php_floor'] === $matches[1] && 33 === ( $verdict['uid'] ?? null ), 'observed interpreter lane and UID' );
		}
		return $status;
	}

	public static function cleanup( array $before, array $readback ): void {
		self::snapshot( $before ); self::snapshot( $readback['snapshot'] ?? array() );
		self::keys( $readback, array( 'remaining', 'snapshot' ) );
		self::check( array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0 ) === $readback['remaining']
			&& $before === $readback['snapshot'], 'actual bound cleanup readback' );
	}

	public static function pre_acquisition( array $record, array $context, bool $small ): void {
		self::keys( $record, array( 'schema', 'context_sha256', 'binding', 'namespace', 'token', 'small_catalog', 'acquisition_started' ) );
		self::check( 'wstm-bounded-pre-acquisition-v1' === $record['schema']
			&& is_string( $record['context_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $record['context_sha256'] ),
			'pre-acquisition schema/digest' );
		self::check( array( 'owner' => $context['run'], 'project' => $context['project'],
			'identity_kind' => $context['identity_kind'], 'identity_sha256' => $context['identity_sha256'] ) === $record['binding']
			&& 'w121_' . substr( $context['run'], 0, 12 ) === $record['namespace'] && $context['token'] === $record['token']
			&& $small === $record['small_catalog'] && false === $record['acquisition_started'], 'pre-acquisition binding/phase' );
	}

	public static function pre_acquisition_cleanup( array $record, array $before, array $readback, array $context,
		array $verdict, string $catalog ): void {
		self::pre_acquisition( $record, $context, 'small' === $catalog );
		self::check( 'failed' === $verdict['status'] && 1 === $verdict['exit']
			&& 'pre-acquisition' === ( $verdict['phase'] ?? null ) && $catalog === ( $verdict['catalog'] ?? null )
			&& $verdict['context_sha256'] === $record['context_sha256'], 'explicit failed non-acquisition' );
		self::snapshot( $before ); self::snapshot( $readback['snapshot'] ?? array() );
		self::keys( $readback, array( 'remaining', 'snapshot' ) );
		self::check( array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0, 'postmeta' => 0 ) === $readback['remaining']
			&& $before === $readback['snapshot'], 'pre-acquisition native unchanged snapshot/dependencies' );
	}

	public static function snapshot( array $snapshot ): void {
		self::keys( $snapshot, array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
			'comments', 'commentmeta', 'users', 'usermeta', 'options' ) );
		foreach ( $snapshot as $table ) {
			self::check( is_array( $table ), 'snapshot table type' ); self::keys( $table, array( 'rows', 'sha256' ) );
			self::check( is_int( $table['rows'] ) && $table['rows'] >= 0 && is_string( $table['sha256'] )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $table['sha256'] ), 'snapshot count/digest' );
		}
	}

	public static function small_plan( array $state ): array {
		$jobs = array();
		foreach ( Wstm121Plan::collections( $state['cpt'] ) as $surface => $collection ) {
			foreach ( array( 'dense', 'sparse', 'all-denied' ) as $mode ) {
				foreach ( array( 1, 20, 100 ) as $p ) {
					for ( $page = 1; $page <= (int) ceil( count( $state['catalog'][ $collection[1] ] ) / $p ) + 1; ++$page ) {
						$jobs[] = self::small_job( $surface, $collection, $mode, $p, $page );
					}
				}
			}
			if ( ! $collection[2] ) { continue; }
			foreach ( array( 'cold', 'warm' ) as $cache ) {
				$full = self::small_job( $surface, $collection, 'dense', 20, 1, 'full' );
				$full['cache'] = $cache; $full['coverage_key'] .= ':' . $cache; $full['edge'] = true; $jobs[] = $full;
				$default = self::small_job( $surface, $collection, 'dense', 20, 1 );
				$default['input'] = array(); $default['cache'] = $cache;
				$default['coverage_key'] .= ':default:' . $cache; $default['edge'] = true; $jobs[] = $default;
				$filter = self::small_job( $surface, $collection, 'dense', 20, 1 );
				$filter['input'] += array( 'author' => $state['user'], 'status' => 'publish', 'search' => 'Controlled tied title' );
				$filter['cache'] = $cache; $filter['coverage_key'] .= ':owned-filters:' . $cache; $filter['edge'] = true; $jobs[] = $filter;
				foreach ( array( 'date', 'title', 'modified', 'id' ) as $sort ) {
					foreach ( array( 'ASC', 'DESC' ) as $order ) {
						$tie = self::small_job( $surface, $collection, 'dense', 20, 2 );
						$tie['input'] += array( 'orderby' => $sort, 'order' => $order ); $tie['cache'] = $cache;
						$tie['coverage_key'] .= ':tie:' . $sort . ':' . $order . ':' . $cache; $tie['edge'] = true; $jobs[] = $tie;
					}
				}
			}
		}
		self::check( 1125 === count( $jobs ), 'independent small inventory' );
		return $jobs;
	}

	private static function small_job( string $surface, array $collection, string $mode, int $p, int $page, string $fields = 'summary' ): array {
		$input = array( 'page' => $page, 'per_page' => $p );
		if ( $collection[2] ) { $input['fields'] = $fields; }
		if ( in_array( $surface, array( 'seo', 'readability' ), true ) ) { $input['post_type'] = 'post'; }
		return array( 'coverage_key' => implode( ':', array( 'small', $surface, $mode, $p, $page, $fields ) ),
			'ability' => $collection[0], 'type' => $collection[1], 'cache' => 'cold', 'mode' => $mode, 'input' => $input,
			'page' => $page, 'per_page' => $p, 'orphan' => 'orphans' === $surface,
			'controlled_default_summary' => $collection[2] && 'summary' === $fields && 20 === $p );
	}

	public static function state( array $state, array $context, bool $small, ?Wstm_Product_Evidence_Reader $reader = null ): void {
		$namespace = 'w121_' . substr( $context['run'], 0, 12 );
		self::check( $namespace === ( $state['namespace'] ?? null ) && $namespace === ( $state['cpt'] ?? null )
			&& $context['token'] === ( $state['token'] ?? null ) && $small === ( $state['small_catalog'] ?? null )
			&& is_int( $state['user'] ?? null ) && $state['user'] > 0
			&& is_int( $state['revision'] ?? null ) && $state['revision'] > 0, 'catalog owner/actor binding' );
		$counts = $small ? array( 'post' => 43, 'attachment' => 43, 'page' => 46, $namespace => 43 )
			: array( 'post' => 20000, 'attachment' => 10000, 'page' => 203, $namespace => 205 );
		self::check( is_array( $state['catalog'] ?? null ) && array_keys( $state['catalog'] ) === array_keys( $counts ), 'catalog domains' );
		$all = array();
		foreach ( $counts as $type => $count ) {
			$ids = $state['catalog'][ $type ];
			self::check( is_array( $ids ) && array_values( $ids ) === $ids && count( $ids ) === $count, 'catalog scale' );
			$previous = PHP_INT_MAX;
			foreach ( $ids as $id ) {
				self::check( is_int( $id ) && $id > 0 && $id < $previous && ! isset( $all[ $id ] ), 'catalog ID order/uniqueness' );
				$all[ $id ] = true; $previous = $id;
			}
		}
		$attachments = $state['catalog']['attachment'];
		$referenced = array_values( array_filter( $attachments, static fn( $id, $rank ) => 0 === $rank % 5, ARRAY_FILTER_USE_BOTH ) );
		foreach ( array( 1, 2, 3, 4 ) as $rank ) { $referenced[] = $attachments[ $rank ]; }
		self::check( array_values( array_unique( $referenced ) ) === ( $state['referenced'] ?? null ), 'independent reference oracle' );
		self::check( ! isset( $all[ $state['revision'] ] ), 'revision outside list catalogs' );
		$sources = $state['sources'] ?? null;
		self::check( is_array( $sources ) && array_keys( $sources ) === array( 'sha256', 'loaded' )
			&& is_array( $sources['sha256'] ) && count( $sources['sha256'] ) > 0 && count( $sources['sha256'] ) <= 256, 'finite source records' );
		foreach ( $sources['sha256'] as $hash ) { self::check( is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash ), 'source digest' ); }
		$classes = array( 'Webmastery_MCP_Posts' => 'class-posts.php', 'Webmastery_MCP_Media' => 'class-media.php',
			'Webmastery_MCP_SEO' => 'class-seo.php', 'Webmastery_MCP_Content_Hygiene' => 'class-content-hygiene.php',
			'Webmastery_MCP_Custom_Post_Types' => 'class-custom-post-types.php', 'Webmastery_MCP_List_Query' => 'class-list-query.php' );
		self::check( is_array( $sources['loaded'] ) && array_keys( $sources['loaded'] ) === array_keys( $classes ), 'loaded source domain' );
		$standalone = null === $reader;
		$reader = $reader ?? new Wstm_Product_Evidence_Reader( dirname( __DIR__, 2 ), 'bounded' );
		foreach ( $classes as $class => $file ) {
			$loaded = $sources['loaded'][ $class ];
			self::check( is_array( $loaded ), 'loaded record type' ); self::keys( $loaded, array( 'path', 'sha256' ) );
			$original = $reader->authority( $file );
			self::check( $context['plugin_root'] . '/includes/' . $file === $loaded['path']
				&& $original === $loaded['sha256'], 'loaded bytes differ from immutable QA authority' );
			$matches = array();
			foreach ( $sources['sha256'] as $path => $hash ) {
				if ( 'includes/' . $file === $path || $loaded['path'] === $path ) { $matches[] = $hash; }
			}
			if ( $standalone ) { $reader->finish(); }
			self::check( array( $loaded['sha256'] ) === $matches, 'loaded source inventory linkage' );
		}
	}

	public static function operation( array $state, array $job, array $record ): array {
		self::check( ! array_key_exists( 'failure', $record ), 'operation failure retained' );
		return Wstm121Verifier::operation( $state, $job, $record );
	}

	private static function bounded( Wstm_Product_Evidence_Reader $reader, array $context, array $verdict ): int {
		self::check( array( 'large', 'product-verdict.private.json', 'small' ) === $reader->listing(), 'closed bounded root inventory' );
		$total = 0; $catalogs = array(); $previous_sources = null;
		foreach ( array( 'small', 'large' ) as $catalog ) {
			$small = 'small' === $catalog;
			$state = $reader->json( $catalog . '/state.json' ); self::state( $state, $context, $small, $reader );
			$pre = $reader->json( $catalog . '/pre-acquisition.json' ); self::pre_acquisition( $pre, $context, $small );
			self::check( $verdict['context_sha256'] === $pre['context_sha256'], 'pre-acquisition context digest' );
			self::check( null === $previous_sources || $previous_sources === $state['sources'], 'cross-catalog source drift' );
			$previous_sources = $state['sources'];
			$jobs = $small ? self::small_plan( $state ) : Wstm121Plan::selected( $state );
			$plan = $reader->json( $catalog . '/plan.json' ); self::keys( $plan, array( 'small', 'jobs', 'oracle' ) );
			self::check( $small === $plan['small'] && $jobs === $plan['jobs']
				&& 'Token-owned catalog read before invocation; expected IDs computed from window, authorization and independent reference fixtures.' === $plan['oracle'], 'independently selected plan' );
			$seed = $reader->json( $catalog . '/seed-measurements.json' );
			self::check( array_map( 'count', $state['catalog'] ) === ( $seed['catalog_counts'] ?? null )
				&& ( $small ? 0 : 100 ) === ( $seed['large_posts'] ?? null ) && 56320 === ( $seed['large_body_bytes'] ?? null )
				&& is_string( $seed['large_count_sql'] ?? null ) && false !== stripos( $seed['large_count_sql'], 'OCTET_LENGTH' ), 'seed scale observations' );
			$sequences = array(); $observed = array(); $expected_files = array( 'after.json', 'before.json', 'cleanup-readback.json', 'plan.json', 'pre-acquisition.json', 'seed-measurements.json', 'setup-before.json', 'state.json' );
			foreach ( $jobs as $index => $job ) {
				$directory = $catalog . '/operation-' . $index;
				self::check( array( 'result.json' ) === $reader->listing( $directory ), 'closed operation files' );
				$record = $reader->json( $directory . '/result.json' );
				$derived = self::operation( $state, $job, $record ); ++$total;
				$observed[] = $job['coverage_key']; $expected_files[] = 'operation-' . $index;
				if ( $small && empty( $job['edge'] ) ) {
					$key = $job['ability'] . ':' . $job['mode'] . ':' . $job['per_page'];
					$sequences[ $key ] = array_merge( $sequences[ $key ] ?? array(), $derived['item_ids'] );
					if ( null === $derived['next_page'] ) {
						$catalog_ids = $state['catalog'][ $job['type'] ]; $ranks = array_flip( $catalog_ids );
						$expected = array_values( array_filter( $catalog_ids, static function ( int $id ) use ( $job, $state, $ranks ): bool {
							$rank = $ranks[ $id ];
							return ( 'dense' === $job['mode'] || ( 'sparse' === $job['mode'] && 0 === intdiv( $rank, 20 ) % 2 && 0 === $rank % 3 ) )
								&& ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) );
						} ) );
						self::check( $expected === $sequences[ $key ], 'small full traversal sequence' );
					}
				}
			}
			$before = $reader->json( $catalog . '/before.json' ); $after = $reader->json( $catalog . '/after.json' );
			self::snapshot( $before ); self::snapshot( $after ); self::check( $before === $after, 'persisted no-write equality' );
			self::cleanup( $reader->json( $catalog . '/setup-before.json' ), $reader->json( $catalog . '/cleanup-readback.json' ) );
			if ( ! $small ) {
				foreach ( array( 'reference-parity.json' => 'reference_probe', 'projection-probes.json' => 'projection_probe', 'window-cache-faults.json' => 'cache_probe' ) as $file => $method ) {
					Wstm121Verifier::$method( $state, $reader->json( $catalog . '/' . $file ) ); $expected_files[] = $file;
				}
			}
			sort( $expected_files, SORT_STRING );
			self::check( $expected_files === $reader->listing( $catalog ), 'catalog file inventory' );
			$catalogs[] = array( 'small' => $small, 'inventory' => array( 'observations' => count( $jobs ),
				'expected_labels' => $observed, 'observed_labels' => $observed ), 'cleanup_complete' => true );
		}
		self::check( $catalogs === $verdict['catalogs'] && true === $verdict['cleanup_complete'], 'catalog verdict agrees with recomputation' );
		return $total;
	}

	public static function annotation( array $registered, object $descriptor, $info ): void {
		self::check( is_string( $registered['name'] ?? null ) && is_string( $registered['label'] ?? null ), 'registered annotation identity' );
		foreach ( array( 'readonly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint' ) as $source => $wire ) {
			$value = $registered['meta']['annotations'][ $source ] ?? null;
			self::check( is_bool( $value ) && $value === ( $descriptor->annotations->$wire ?? null ), 'actual annotation hint' );
		}
		self::check( trim( $registered['label'] ) === ( $descriptor->title ?? null )
			&& ( $registered['meta']['annotations']['title'] ?? trim( $registered['label'] ) ) === ( $descriptor->annotations->title ?? null ), 'annotation title' );
		$tool = is_array( $info ) ? ( $info['result'] ?? null ) : ( is_object( $info ) ? ( $info->result ?? null ) : null );
		self::check( $tool instanceof stdClass, 'gateway info original' );
		$wire = $tool->structuredContent ?? json_decode( $tool->content[0]->text ?? '', false, 512, JSON_THROW_ON_ERROR );
		self::check( $wire instanceof stdClass && $registered['name'] === ( $wire->name ?? null )
			&& wstm108_wire_canonical( (object) $registered['meta']['annotations'] ) === wstm108_wire_canonical( $wire->meta->annotations ?? null ), 'gateway info annotations' );
		if ( 'webmastery-site-toolkit-for-mcp/bulk-publish-posts' === $registered['name'] ) {
			self::check( true === $descriptor->annotations->destructiveHint, 'guarded publish annotation' );
		}
	}

	public static function frame( array $record, string $bytes ): array {
		self::check( is_int( $record['length'] ?? null ) && strlen( $bytes ) === $record['length']
			&& hash( 'sha256', $bytes ) === ( $record['sha256'] ?? null ) && 0 === strpos( $bytes, "WSTM108-WIRE-1\n" ), 'wire frame byte binding' );
		// stdClass preserves original JSON shape; no application class may be instantiated.
		$event = @unserialize( substr( $bytes, 15 ), array( 'allowed_classes' => array( 'stdClass' ) ) );
		self::check( is_array( $event ), 'wire event type' );
		$body = $event['body'] ?? null;
		self::check( ( is_string( $body ) ? strlen( $body ) : null ) === ( $record['body_length'] ?? null )
			&& ( is_string( $body ) ? hash( 'sha256', $body ) : null ) === ( $record['body_sha256'] ?? null )
			&& ( is_int( $event['status'] ?? null ) ? $event['status'] : null ) === ( $record['status'] ?? null ), 'original body/status binding' );
		return $event;
	}

	/** Pure replay of the entered setup sequence, never a live absence observation. */
	public static function setup_event( array $event, array &$states, string $run ): array {
		self::check( 1 === preg_match( '/^(gateway|individual):(administrator|subscriber|reader)$/D', $event['boundary'] ?? '', $parts ), 'setup actor/boundary' );
		$boundary = $event['boundary'];
		$decoded = wstm108_rpc_response( $event );
		$request = $event['request'];
		$method = $event['method'];
		$state = $states[ $boundary ] ?? null;
		if ( 'initialize' === $method ) {
			$client = $request['params']->clientInfo ?? null;
			self::check( is_array( $client ), 'original initialize clientInfo array' );
			self::keys( $client, array( 'name', 'version' ) );
			self::check( null === $state && 1 === $request['id']
				&& '2025-11-25' === ( $request['params']->protocolVersion ?? null )
				&& ( $request['params']->capabilities ?? null ) instanceof stdClass
				&& $run . ':' . $parts[1] . ':' . $parts[2] === $client['name']
				&& '1' === $client['version'], 'original initialize request identity' );
			$token = wstm108_rpc_header( $event['headers'], 'mcp-session-id' );
			self::check( '' !== $token, 'original initialize session header' );
			$states[ $boundary ] = array( 'token' => $token, 'notified' => false, 'id' => 1, 'cursor' => null,
				'complete' => false, 'tools' => array(), 'seen' => array() );
			return $decoded;
		}
		self::check( is_array( $state ) && is_array( $event['request_headers'] ?? null )
			&& $state['token'] === wstm108_rpc_header( $event['request_headers'], 'mcp-session-id' ), 'setup session request association' );
		$token = wstm108_rpc_header( $event['headers'], 'mcp-session-id' );
		self::check( '' === $token || $token === $state['token'], 'setup session response association' );
		if ( 'notifications/initialized' === $method ) {
			self::check( false === $state['notified'], 'duplicate initialized notification' );
			$state['notified'] = true;
		} else {
			self::check( 'tools/list' === $method && true === $state['notified'] && false === $state['complete']
				&& $request['id'] === $state['id'] + 1
				&& $state['cursor'] === ( $request['params']->cursor ?? null ), 'catalog request order/cursor/identity' );
			$tools = $decoded['result']->tools ?? null;
			$cursor = $decoded['result']->nextCursor ?? null;
			self::check( is_array( $tools ) && ( null === $cursor || ( is_string( $cursor ) && '' !== $cursor
				&& ! isset( $state['seen'][ $cursor ] ) ) ), 'catalog native sequence/cursor' );
			foreach ( $tools as $tool ) {
				self::check( $tool instanceof stdClass, 'catalog original descriptor' );
				Wstm108_Evidence::tool( $tool );
				self::check( ! isset( $state['tools'][ $tool->name ] ), 'duplicate catalog descriptor identity' );
				$state['tools'][ $tool->name ] = $tool;
			}
			self::check( count( $state['tools'] ) <= ( 'individual' === $parts[1] ? 90 : 3 ), 'finite setup catalog' );
			if ( null !== $cursor ) { $state['seen'][ $cursor ] = true; }
			$state['id'] = $request['id']; $state['cursor'] = $cursor; $state['complete'] = null === $cursor;
			if ( $state['complete'] ) {
				self::check( ( 'individual' === $parts[1] ? 90 : 3 ) === count( $state['tools'] ), 'exact setup catalog count' );
			}
		}
		$states[ $boundary ] = $state;
		return $decoded;
	}

	public static function case_response( array $case, array $event, bool $final = true ): array {
		$label = $case['label'] ?? '';
		self::check( is_string( $label ) && 1 === preg_match( '/^(gateway|individual):(.+)$/D', $label, $parts ), 'planned case boundary' );
		$actor = 0 === strpos( $parts[2], 'subscriber-denied:' ) ? 'subscriber'
			: ( 0 === strpos( $parts[2], 'user:reader:' ) ? 'reader' : 'administrator' );
		self::check( $parts[1] . ':' . $actor === ( $event['boundary'] ?? null ) && 'tools/call' === ( $event['method'] ?? null )
			&& ( $event['status'] ?? 0 ) >= 200 && ( $event['status'] ?? 0 ) < 300
			&& false === ( $event['transport_error'] ?? null ) && is_array( $event['request'] ?? null )
			&& 'tools/call' === ( $event['request']['method'] ?? null ), 'actual actor/request transport binding' );
		$body = json_decode( $event['body'] ?? '', false, 512, JSON_THROW_ON_ERROR );
		self::check( $body instanceof stdClass && ! property_exists( $body, 'error' ), 'actual JSON-RPC response' );
		self::check( '2.0' === ( $event['request']['jsonrpc'] ?? null ) && is_int( $event['request']['id'] ?? null )
			&& $event['request']['id'] > 0 && '2.0' === ( $body->jsonrpc ?? null )
			&& $event['request']['id'] === ( $body->id ?? null ), 'JSON-RPC request/response identity' );
		Wstm_Product_Evidence_Reader::decode_json( $event['body'] );
		if ( is_string( $body->result->content[0]->text ?? null ) ) { Wstm_Product_Evidence_Reader::decode_json( $body->result->content[0]->text ); }
		$response = wstm108_typed_tool( $body->result ?? null, 'gateway' === $parts[1] );
		if ( $final ) { self::check( wstm108_wire_canonical( $response ) === wstm108_wire_canonical( $case['response'] ?? null ), 'case response differs from original actor wire' ); }
		return $response;
	}

	private static function selected_record( $items, array $expected, string $key = 'id' ): object {
		self::check( is_array( $items ) && is_int( $expected[ $key ] ?? null ), 'record selection oracle' );
		$matches = array_values( array_filter( $items, static fn( $item ) => $item instanceof stdClass && ( $item->$key ?? null ) === $expected[ $key ] ) );
		self::check( 1 === count( $matches ), 'owned record absent or duplicated' );
		return $matches[0];
	}

	private static function semantic( array $case, array $keys ): array {
		$record = $case['semantic'] ?? null;
		if ( ! is_array( $record ) ) { throw new Wstm_Product_Evidence_Gap( 'Missing independent semantic capture: ' . $case['label'] ); }
		self::keys( $record, array_merge( array( 'schema' ), $keys ) );
		self::check( 'wstm108-semantic-replay-v1' === $record['schema'], 'semantic capture version' );
		return $record;
	}

	private static function compare( $actual, array $expected, string $kind ): void {
		wstm108_compare_typed_record( $actual, $expected, wstm108_fields( $kind ), $kind );
	}

	private static function no_write( array $case ): void {
		$record = self::semantic( $case, array( 'before', 'after' ) );
		self::check( is_array( $record['before'] ) && $record['before'] === $record['after'], 'denial changed owned state' );
	}

	private static function findings( object $data ): void {
		self::check( is_array( $data->issues ?? null ) && is_array( $data->good ?? null ), 'assessment sequences' );
		foreach ( array_merge( $data->issues, $data->good ) as $finding ) {
			self::check( $finding instanceof stdClass && is_string( $finding->message ?? null )
				&& false === strpos( $finding->message, 'INERT TEST DATA' ), 'stored SEO data in assessment text' );
		}
	}

	private static function provider( object $actual, array $expected, string $provider, array $readable ): void {
		self::compare( $actual, $expected, 'provider' );
		self::check( true === ( $expected[ $provider . '_active' ] ?? null ) && is_array( $expected['metadata'] ?? null )
			&& is_array( $expected['raw_meta'] ?? null ) && is_array( $expected['unavailable_fields'] ?? null ), 'active provider oracle' );
		$metadata = array(); $raw = array(); $unavailable = array();
		foreach ( wstm108_provider_keys( $provider ) as $field => $key ) {
			self::check( is_bool( $readable[ $key ] ?? null ), 'provider key policy coverage' );
			if ( $readable[ $key ] ) {
				$metadata[] = $field; $raw[] = $field;
				self::check( $key === ( $expected['raw_meta'][ $field ]['key'] ?? null ), 'provider raw key association' );
			} else {
				$unavailable[] = $field;
				self::check( wstm108_wire_canonical( wstm108_unavailable() )
					=== wstm108_wire_canonical( $expected['unavailable_fields'][ $field ] ?? null ), 'provider unavailable policy' );
			}
		}
		self::check( $metadata === array_keys( $expected['metadata'] ) && $raw === array_keys( $expected['raw_meta'] )
			&& $unavailable === array_keys( $expected['unavailable_fields'] ), 'provider readable/unavailable domains' );
	}

	public static function case_abilities( string $label ): array {
		self::check( 1 === preg_match( '/^(gateway|individual):(.+)$/D', $label, $parts ), 'case ability selector' );
		$suffix = $parts[2];
		if ( preg_match( '/^(post|page|wstm108_record):(get|list|create|update)(?::summary-(?:default|explicit))?$/D', $suffix, $match ) ) {
			$type = 'wstm108_record' === $match[1] ? 'cpt-wstm108-record' : $match[1];
			return array( $match[2] . '-' . ( 'list' === $match[2] && 'wstm108_record' !== $match[1] ? $type . 's' : $type ) );
		}
		if ( preg_match( '/^media:(list|get|update)$/D', $suffix, $match ) ) { return array( $match[1] . '-media' ); }
		if ( preg_match( '/^comments:(list|reply|update)$/D', $suffix, $match ) ) { return array( $match[1] . ( 'list' === $match[1] ? '-comments' : '-comment' ) ); }
		if ( preg_match( '/^user:(administrator|reader):(list|get)$/D', $suffix, $match ) ) { return array( $match[2] . ( 'list' === $match[2] ? '-users' : '-user' ) ); }
		if ( 0 === strpos( $suffix, 'subscriber-denied:' ) ) { return array( substr( $suffix, strlen( 'subscriber-denied:' ) ) ); }
		if ( 0 === strpos( $suffix, 'seo-key-policy:' ) ) { return array( 'get-post-meta' ); }
		if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
			return array( 'seo-analyze-post', 'get-' . ( false !== strpos( $suffix, ':yoast_' ) ? 'yoast' : 'seopress' ) . '-metadata' );
		}
		if ( in_array( $suffix, array( 'post:revisions', 'page:revisions' ), true ) ) { return array( 'list-revisions', 'restore-revision' ); }
		if ( 0 === strpos( $suffix, 'patch-post-content:' ) ) { return array( 'patch-post-content' ); }
		$names = array( 'blocks' => array( 'list-content-blocks' ), 'patch-content-block' => array( 'patch-content-block' ),
			'user-access-audit' => array( 'user-access-audit' ), 'standalone-meta-read-update' => array( 'get-post-meta', 'update-post-meta' ),
			'standalone-meta-delete' => array( 'delete-post-meta' ), 'seo-metrics' => array( 'seo-analyze-post' ),
			'yoast:metadata' => array( 'get-yoast-metadata' ), 'seopress:metadata' => array( 'get-seopress-metadata' ),
			'get-seo-scores' => array( 'get-seo-scores' ), 'get-readability-scores' => array( 'get-readability-scores' ),
			'no-opaque-head' => array( 'get-yoast-metadata' ), 'no-combined-seo-alias-write' => array( 'update-post' ),
			'private-image-url-still-denied' => array( 'upload-image' ), 'seo-site-overview-records' => array( 'seo-site-overview' ) );
		self::check( isset( $names[ $suffix ] ), 'unknown semantic ability label' ); return $names[ $suffix ];
	}

	private static function call_identity( array $event, string $ability, array $descriptors, array $catalogs ): array {
		$params = (array) ( $event['request']['params'] ?? array() );
		$arguments = (array) ( $params['arguments'] ?? array() );
		$name = 'webmastery-site-toolkit-for-mcp/' . $ability;
		if ( 0 === strpos( $event['boundary'], 'gateway:' ) ) {
			$tool = wstm108_gateway_descriptor( $catalogs[ $event['boundary'] ] ?? array(), 'execute' );
			self::check( $tool->name === ( $params['name'] ?? null ) && $name === ( $arguments['ability_name'] ?? null )
				&& ( is_array( $arguments['parameters'] ?? null ) || ( $arguments['parameters'] ?? null ) instanceof stdClass ), 'actual gateway ability association' );
			return (array) $arguments['parameters'];
		}
		self::check( isset( $descriptors[ $name ] ) && $descriptors[ $name ] === ( $params['name'] ?? null ), 'actual individual descriptor association' );
		return $arguments;
	}

	/** Replays retained independent comparisons; missing oracles never become passed flags. */
	public static function replay_case( array $case, array $response, array $responses = array() ): ?string {
		$label = $case['label']; $suffix = substr( $label, strpos( $label, ':' ) + 1 );
		if ( 0 === strpos( $suffix, 'subscriber-denied:' ) ) {
			$expected = $case['expected'] ?? null;
			self::check( is_array( $expected ), 'captured permission oracle' );
			wstm118_error_envelope( $response );
			self::check( wstm108_wire_canonical( $expected ) === wstm108_wire_canonical( $response )
				&& 'forbidden' === ( $response['error']['code'] ?? null ) && ! isset( $response['untrusted_fields'] ), 'canonical permission denial' );
			if ( ! isset( $case['semantic'] ) ) { return 'subscriber-denied: owned post/comment snapshots before and after denial'; }
			self::no_write( $case ); return null;
		}
		if ( 0 === strpos( $suffix, 'seo-key-policy:' ) ) {
			if ( false === ( $response['success'] ?? null ) ) {
				wstm118_error_envelope( $response ); self::check( 'forbidden' === $response['error']['code'], 'SEO key denial taxonomy' ); return null;
			}
			self::check( is_array( $case['expected'] ?? null ) && array_keys( $case['expected']['meta'] ?? array() )
				=== array( substr( $suffix, strlen( 'seo-key-policy:' ) ) ), 'readable key oracle association' );
			self::compare( $response['data'] ?? null, $case['expected'], 'meta' ); return null;
		}
		$reasons = array( 'no-opaque-head' => 'generated_head_key_authorization_unavailable',
			'no-combined-seo-alias-write' => 'metadata_requires_separate_call', 'private-image-url-still-denied' => 'invalid_url' );
		if ( isset( $reasons[ $suffix ] ) ) {
			wstm118_error_envelope( $response ); self::check( $reasons[ $suffix ] === $response['error']['reason'], 'retained safety error reason' );
			if ( 'no-combined-seo-alias-write' === $suffix ) {
				if ( ! isset( $case['semantic'] ) ) { return $suffix . ': owned metadata snapshots before and after denial'; }
				self::no_write( $case );
			}
			return null;
		}
		self::check( array( 'success', 'data' ) === array_keys( $response ) && true === $response['success']
			&& $response['data'] instanceof stdClass, 'original successful typed case response' );
		$data = $response['data']; $expected = $case['expected'] ?? null;
		if ( 'patch-content-block' === $suffix ) {
			$oracle = self::semantic( $case, array( 'before', 'after', 'blocks_before', 'blocks_after' ) );
			self::check( is_array( $expected ) && is_string( $oracle['before'] ) && is_string( $oracle['after'] )
				&& is_array( $oracle['blocks_before'] ) && is_array( $oracle['blocks_after'] )
				&& hash( 'sha256', $oracle['before'] ) === ( $expected['content_hash_before'] ?? null )
				&& hash( 'sha256', $oracle['after'] ) === ( $expected['content_hash_after'] ?? null )
				&& $oracle['after'] === ( $expected['content'] ?? null )
				&& ( $oracle['blocks_before'][0]['hash'] ?? null ) === ( $expected['block_hash_before'] ?? null )
				&& ( $oracle['blocks_after'][0]['hash'] ?? null ) === ( $expected['block_hash_after'] ?? null ), 'independent patch readbacks' );
			self::compare( $data, $expected, 'content-patch' ); return null;
		}
		if ( in_array( $suffix, array( 'post:revisions', 'page:revisions' ), true ) ) {
			$oracle = self::semantic( $case, array( 'revisions', 'revision', 'post' ) );
			self::check( 2 === count( $responses ) && is_array( $oracle['revisions'] ) && count( $oracle['revisions'] ) > 0
				&& is_array( $responses[0]['data']->revisions ?? null )
				&& count( $oracle['revisions'] ) === count( $responses[0]['data']->revisions ), 'original revision sequence' );
			foreach ( $oracle['revisions'] as $index => $revision ) { self::compare( $responses[0]['data']->revisions[ $index ], $revision, 'revision' ); }
			self::check( is_array( $oracle['revision'] ) && is_array( $oracle['post'] )
				&& $oracle['revisions'][0]['id'] === $oracle['revision']['id'], 'restored revision selection' );
			self::compare( $data->revision ?? null, $oracle['revision'], 'revision' );
			self::compare( $data->post ?? null, $oracle['post'], 'post' ); return null;
		}
		if ( 'user-access-audit' === $suffix ) {
			$oracle = self::semantic( $case, array( 'account', 'application' ) );
			self::check( is_array( $oracle['account'] ) && is_array( $oracle['application'] )
				&& $oracle['account']['id'] === $oracle['application']['user_id'], 'audit actor association' );
			self::compare( self::selected_record( $data->admin_accounts ?? null, $oracle['account'] ), $oracle['account'], 'account' );
			self::compare( self::selected_record( $data->application_passwords ?? null, $oracle['application'], 'user_id' ), $oracle['application'], 'application' ); return null;
		}
		if ( 'standalone-meta-read-update' === $suffix ) {
			$oracle = self::semantic( $case, array( 'read', 'update', 'before', 'value', 'after' ) );
			self::check( 2 === count( $responses ) && is_array( $oracle['read'] ) && is_array( $oracle['update'] )
				&& $oracle['read']['post_id'] === $oracle['update']['post_id']
				&& array( $oracle['before'] ) === ( $oracle['read']['meta']['wstm108_payload'] ?? null )
				&& 'wstm108_payload' === ( $oracle['update']['meta_key'] ?? null )
				&& $oracle['before'] === ( $oracle['update']['previous_value'] ?? null )
				&& $oracle['value'] === ( $oracle['update']['current_value'] ?? null )
				&& true === ( $oracle['update']['updated'] ?? null ) && $oracle['value'] === $oracle['after'], 'metadata mutation storage readback' );
			self::compare( $responses[0]['data'] ?? null, $oracle['read'], 'meta' );
			self::compare( $data, $oracle['update'], 'meta-update' ); return null;
		}
		if ( in_array( $suffix, array( 'get-seo-scores', 'get-readability-scores' ), true ) ) {
			self::check( true === ( $case['providers']['yoast'] ?? null ) && is_array( $expected )
				&& ( 'get-seo-scores' === $suffix ? '_yoast_wpseo_linkdex' : '_yoast_wpseo_content_score' ) === ( $case['score_key'] ?? null ), 'score provider/key association' );
			self::compare( self::selected_record( $data->items ?? null, $expected, 'post_id' ), $expected, 'score' ); return null;
		}
		if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
			$oracle = self::semantic( $case, array( 'field', 'key', 'provider', 'restricted', 'metrics', 'unavailable', 'metadata' ) );
			$field = substr( $suffix, strlen( 'seo-forbidden-read-trap:' ) );
			$map = array( 'yoast_meta_description' => array( 'yoast', '_yoast_wpseo_metadesc' ),
				'seopress_meta_description' => array( 'seopress', '_seopress_titles_desc' ), 'yoast_focus_keyword' => array( 'yoast', '_yoast_wpseo_focuskw' ),
				'seopress_focus_keywords' => array( 'seopress', '_seopress_analysis_target_kw' ) );
			self::check( isset( $map[ $field ] ) && $field === $oracle['field'] && $map[ $field ] === array( $oracle['provider'], $oracle['key'] )
				&& is_array( $case['readable'] ?? null ), 'trap field/provider/key association' );
			$restricted = $case['readable']; $restricted[ $oracle['key'] ] = false;
			self::check( $restricted === $oracle['restricted'] && 2 === count( $responses ) && is_array( $oracle['metrics'] )
				&& ! array_key_exists( $field, $oracle['metrics'] ) && wstm108_wire_canonical( wstm108_unavailable() )
				=== wstm108_wire_canonical( $oracle['unavailable'] ), 'restricted key oracle' );
			$metrics = $responses[0]['data'] ?? null;
			self::check( $metrics instanceof stdClass && wstm108_wire_canonical( $oracle['unavailable'] )
				=== wstm108_wire_canonical( $metrics->unavailable_fields->$field ?? null ), 'actual unavailable field policy' );
			self::compare( $metrics->metrics ?? null, $oracle['metrics'], 'metrics' );
			self::check( is_array( $oracle['metadata'] ), 'restricted provider oracle' );
			self::check( is_array( $case['native_provider_readable'] ?? null )
				&& false === ( $case['native_provider_readable'][ $oracle['key'] ] ?? null ), 'restricted provider native key coverage' );
			self::provider( $data, $oracle['metadata'], $oracle['provider'], $case['native_provider_readable'] ); return null;
		}
		$kind = null; $list = false;
		if ( preg_match( '/^(post|page|wstm108_record):(get|list|update|create)(?::(summary-default|summary-explicit))?$/D', $suffix, $match ) ) {
			$kind = 'post'; $list = 'list' === $match[2];
			if ( isset( $match[3] ) ) { self::check( is_array( $expected ) && ! array_key_exists( 'content', $expected ), 'summary expected projection' ); }
		} elseif ( preg_match( '/^media:(list|get|update)$/D', $suffix, $match ) ) { $kind = 'media'; $list = 'list' === $match[1]; }
		elseif ( preg_match( '/^comments:(list|reply|update)$/D', $suffix, $match ) ) { $kind = 'comment'; $list = 'list' === $match[1]; }
		elseif ( preg_match( '/^user:(administrator|reader):(list|get)$/D', $suffix, $match ) ) { $kind = 'user'; $list = 'list' === $match[2]; }
		if ( null !== $kind ) {
			self::check( is_array( $expected ), 'captured ' . $kind . ' oracle' );
			$actual = $list ? self::selected_record( $data->items ?? null, $expected ) : $data;
			wstm108_compare_typed_record( $actual, $expected, wstm108_fields( $kind ), $kind ); return null;
		}
		if ( 'blocks' === $suffix ) {
			self::check( is_array( $expected ) && is_array( $data->blocks ?? null ) && count( $expected ) === count( $data->blocks ), 'block sequence oracle' );
			foreach ( $expected as $index => $block ) { wstm108_compare_typed_record( $data->blocks[ $index ], $block, wstm108_fields( 'block' ), 'block' ); }
			return null;
		}
		if ( 0 === strpos( $suffix, 'patch-post-content:' ) ) {
			self::check( is_array( $expected ) && is_array( $expected['target'] ?? null ) && is_array( $expected['post'] ?? null ), 'full patch oracle' );
			wstm108_compare_typed_record( $data->target ?? null, $expected['target'], wstm108_fields( 'patch-target' ), 'patch-target' );
			wstm108_compare_typed_record( $data->post ?? null, $expected['post'], wstm108_fields( 'post' ), 'post' );
			$actual = clone $data; $actual->target = clone $data->target; $actual->post = clone $data->post;
			unset( $actual->target->untrusted_fields, $actual->post->untrusted_fields );
			self::check( wstm108_wire_canonical( (object) $expected ) === wstm108_wire_canonical( $actual ), 'full patch response' ); return null;
		}
		if ( 'seo-metrics' === $suffix ) {
			self::check( is_array( $case['expected_metrics'] ?? null ), 'captured metrics oracle' );
			wstm108_compare_typed_record( $data->metrics ?? null, $case['expected_metrics'], wstm108_fields( 'metrics' ), 'metrics' );
			self::findings( $data );
			return null;
		}
		if ( 'standalone-meta-delete' === $suffix ) {
			self::check( is_array( $expected ), 'captured meta-delete oracle' );
			wstm108_compare_typed_record( $data, $expected, wstm108_fields( 'meta-delete' ), 'meta-delete' );
			if ( ! isset( $case['semantic'] ) ) { return 'standalone-meta-delete: actual post-delete metadata readback'; }
			$oracle = self::semantic( $case, array( 'before', 'after' ) );
			self::check( is_array( $oracle['before'] ) && 2 === count( $oracle['before'] ) && array() === $oracle['after'], 'metadata deletion readback' ); return null;
		}
		if ( 'seo-site-overview-records' === $suffix ) {
			self::check( is_array( $expected ) && array_keys( $expected ) === array( 'sitemap', 'robots_txt' ), 'captured endpoint oracle records' );
			foreach ( array( 'sitemap' => 'sitemap', 'robots_txt' => 'robots' ) as $field => $kind ) {
				self::check( is_array( $expected[ $field ] ), 'endpoint oracle type' );
				wstm108_compare_typed_record( $data->$field ?? null, $expected[ $field ], wstm108_fields( $kind ), $kind );
			}
			return null;
		}
		if ( in_array( $suffix, array( 'yoast:metadata', 'seopress:metadata' ), true ) && is_array( $expected ) ) {
			$provider = explode( ':', $suffix )[0];
			self::check( is_array( $case['readable'] ?? null ) && array_key_exists( 'nested_expected', $case ), 'captured provider policy/nested oracle' );
			self::provider( $data, $expected, $provider, $case['readable'] );
			$key = wstm108_provider_keys( $provider )['opengraph_title'];
			self::check( true === ( $case['readable'][ $key ] ?? null )
				&& wstm108_wire_canonical( $case['nested_expected'] ) === wstm108_wire_canonical( $data->metadata->opengraph_title ?? null ), 'nested provider control' ); return null;
		}
		$missing = array(
			'patch-content-block' => 'before/after stored content and block oracles',
			'post:revisions' => 'revision list plus restored revision/post WordPress oracle records',
			'page:revisions' => 'revision list plus restored revision/post WordPress oracle records',
			'user-access-audit' => 'WordPress account and application-password oracle records',
			'standalone-meta-read-update' => 'before/read/update/post-update stored metadata oracle records',
		);
		return $suffix . ': ' . ( $missing[ $suffix ] ?? 'case-specific WordPress oracle/state readback (not a passed flag)' );
	}

	private static function untrusted( Wstm_Product_Evidence_Reader $reader, array $context, array $verdict ): int {
		self::check( array( 'product-verdict.private.json', 'resources.json', 'runner.json', 'runner.json.http.jsonl', 'wire' ) === $reader->listing(), 'closed untrusted root' );
		$resources = $reader->json( 'resources.json' );
		self::check( $verdict['binding'] === ( $resources['binding'] ?? null ) && 'cleaned' === ( $resources['phase'] ?? null ), 'resource ledger cleanup binding' );
		$summary = $reader->json( 'runner.json' );
		$manifest = Wstm_Product_Evidence_Reader::decode_json( $reader->manifest() );
		self::check( 601 === count( $manifest ) && array_values( $manifest ) === $manifest, 'maintained manifest case inventory' );
		$native = array();
		foreach ( $manifest as $case ) {
			self::check( is_array( $case ) && is_string( $case['ability'] ?? null ), 'trusted manifest ability identity' ); $native[] = $case['ability'];
		}
		$native = array_values( array_unique( $native, SORT_STRING ) ); sort( $native, SORT_STRING );
		$labels = Wstm108_Plan::labels( $native );
		self::check( 298 === count( $labels ) && $labels === $verdict['expected_labels'] && $labels === $verdict['observed_labels']
			&& $labels === ( $summary['expected_labels'] ?? null ) && $verdict['binding'] === ( $summary['binding'] ?? null )
			&& $verdict['context_sha256'] === ( $summary['stage_context_sha256'] ?? null ), 'untrusted inventory/context' );
		$journal = $reader->read( 'runner.json.http.jsonl' );
		self::check( hash( 'sha256', $journal ) === ( $summary['http_journal_sha256'] ?? null ), 'journal hash' );
		$wire_bytes = $reader->read( 'wire/private-wire.json' );
		$wire = Wstm_Product_Evidence_Reader::decode_json( $wire_bytes );
		self::keys( $wire, array( 'version', 'binding', 'context_sha256', 'events', 'scopes', 'retirement', 'removal_pending_id', 'journal_bytes', 'index_bytes' ) );
		self::check( strlen( $journal ) === $wire['journal_bytes'], 'original journal exact byte reservation' );
		self::check( is_int( $wire['index_bytes'] ) && $wire['index_bytes'] >= strlen( $wire_bytes )
			&& $wire['index_bytes'] <= 33554432, 'original monotonic index reservation' );
		self::check( 2 === ( $wire['version'] ?? null ) && $verdict['binding'] === ( $wire['binding'] ?? null )
			&& $verdict['context_sha256'] === ( $wire['context_sha256'] ?? null ) && 'pending' === ( $wire['retirement'] ?? null )
			&& array_key_exists( 'removal_pending_id', $wire ) && null === $wire['removal_pending_id']
			&& array( 'runner' ) === array_keys( $wire['scopes'] ?? array() )
			&& is_array( $wire['events'] ?? null ) && count( $wire['events'] ) > 0 && count( $wire['events'] ) <= 4096, 'current runner wire scope' );
		$witnesses = array(); $journal_count = 0;
		foreach ( explode( "\n", $journal ) as $line ) {
			if ( '' === $line ) { continue; }
			self::check( ++$journal_count <= 8192, 'finite journal records' );
			$entry = Wstm_Product_Evidence_Reader::decode_json( $line );
			Wstm108_PrivateWire::assert_witness( $entry ); $witnesses[ $entry['id'] ][] = $entry;
		}
		$cases = array(); $frames = array( 'private-wire.json' ); $catalog_tools = array(); $catalogs = array(); $original_summary = null;
		$original_events = array(); $calls = array(); $setup = array();
		foreach ( $wire['events'] as $index => $record ) {
			self::check( is_array( $record ), 'wire record type' );
			self::keys( $record, array( 'id', 'scope', 'file', 'length', 'sha256', 'body_length', 'body_sha256',
				'status', 'state', 'identity', 'parser', 'removed', 'origin', 'validation' ) );
			self::check( is_array( $record ) && $index + 1 === ( $record['id'] ?? null )
				&& sprintf( 'wire-%06d.bin', $index + 1 ) === ( $record['file'] ?? null ) && 'runner' === ( $record['scope'] ?? null )
				&& 'validated' === ( $record['state'] ?? null ) && false === ( $record['removed'] ?? null ), 'wire ordinal/state' );
			$file = $record['file']; $frames[] = $file;
			$event = self::frame( $record, $reader->read( 'wire/' . $file ) );
			$original_events[ $record['id'] ] = $event;
			$entries = $witnesses[ $record['id'] ] ?? array();
			self::check( 2 === count( $entries ) && 'committed' === $entries[0]['state'] && 'validated' === $entries[1]['state'], 'capture/validation witnesses' );
			foreach ( $entries as $entry ) {
				foreach ( array( 'id', 'scope', 'length', 'sha256', 'body_length', 'body_sha256', 'status', 'removed', 'origin' ) as $key ) {
					self::check( $record[ $key ] === $entry[ $key ], 'witness original mismatch' );
				}
			}
			self::check( $record['parser'] === $entries[1]['parser'] && $record['validation'] === $entries[1]['validation'], 'parser projection binding' );
			if ( in_array( $event['method'] ?? null, array( 'initialize', 'notifications/initialized', 'tools/list' ), true ) ) {
				$parser = array( 'initialize' => 'initialize', 'notifications/initialized' => 'notification', 'tools/list' => 'catalog' )[ $event['method'] ];
				self::check( $parser === $record['parser'], 'setup parser matches actual method' );
				self::setup_event( $event, $setup, 'wstm108-' . substr( $context['run'], 0, 16 ) );
			} else {
				self::check( ! in_array( $record['parser'], array( 'initialize', 'notification', 'catalog' ), true ), 'setup witness method association' );
			}
			if ( 'case-result' === $record['parser'] ) {
				self::check( 'case-result' === ( $event['boundary'] ?? null ) && is_array( $event['case'] ?? null ), 'bound case original' );
				$cases[] = $event['case'];
			}
			if ( 'local-summary' === $record['parser'] ) {
				self::check( null === $original_summary && $record['id'] === count( $wire['events'] ) && 'local-summary' === ( $event['boundary'] ?? null )
					&& array_keys( $event ) === array( 'boundary', 'schema', 'summary', 'case_refs' )
					&& 'wstm108-final-summary-v2' === $event['schema']
					&& is_array( $event['summary'] ?? null ) && array_key_exists( 'cases', $event['summary'] ) && null === $event['summary']['cases']
					&& is_array( $event['case_refs'] ), 'unique nonduplicating private final summary' );
				$references = array_map( static fn( $case ) => array( 'label' => $case['label'],
					'passed' => $case['passed'], 'sha256' => hash( 'sha256', serialize( $case ) ) ), $cases );
				self::check( $references === $event['case_refs'], 'final summary original case references' );
				$original_summary = $event['summary'];
				$original_summary['cases'] = $cases;
			}
			if ( 'catalog' === $record['parser'] ) {
				$body = json_decode( $event['body'] ?? '', false, 512, JSON_THROW_ON_ERROR );
				$tools = $body->result->tools ?? null;
				self::check( is_array( $tools ), 'actual catalog sequence' );
				foreach ( $tools as $tool ) { self::check( $tool instanceof stdClass, 'catalog descriptor type' ); $catalog_tools[] = $tool; }
				self::check( is_string( $event['boundary'] ?? null ), 'catalog actor boundary' );
				$catalogs[ $event['boundary'] ] = array_merge( $catalogs[ $event['boundary'] ] ?? array(), $tools );
				$projection = array( 'tools' => array_map( static fn( $tool ) => Wstm108_Evidence::tool( $tool ), $tools ),
					'next_cursor_sha256' => hash( 'sha256', wstm108_wire_canonical( $body->result->nextCursor ?? null ) ) );
				self::check( json_decode( json_encode( $projection, JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR )
					=== $record['validation'], 'actual catalog parser projection' );
			}
			if ( 'canonical-tool' === $record['parser'] ) {
				self::check( 1 === preg_match( '/^(gateway|individual):(administrator|subscriber|reader|admin)$/D', $event['boundary'] ?? '', $role ), 'tool actor/boundary' );
				$body = json_decode( $event['body'] ?? '', false, 512, JSON_THROW_ON_ERROR );
				$result = wstm108_typed_tool( $body->result ?? null, 'gateway' === $role[1] );
				self::check( array( 'is_error' => false === $result['success'], 'payload_sha256' => hash( 'sha256', wstm108_wire_canonical( $result ) ) )
					=== $record['validation'], 'actual canonical parser projection' );
				$calls[ $record['id'] ] = $event;
			}
		}
		sort( $frames, SORT_STRING ); self::check( $frames === $reader->listing( 'wire' ), 'frame inventory' );
		foreach ( Wstm108_Plan::ACTORS as $actor ) {
			foreach ( Wstm108_Plan::BOUNDARIES as $boundary ) {
				self::check( true === ( $setup[ $boundary . ':' . $actor ]['complete'] ?? null ), 'complete six-client setup' );
				if ( 'individual' === $boundary ) {
					self::check( wstm108_wire_canonical( $setup[ 'individual:administrator' ]['tools'] )
						=== wstm108_wire_canonical( $setup[ $boundary . ':' . $actor ]['tools'] ), 'same exact individual descriptors for every actor' );
				}
			}
		}
		self::check( 6 === count( $setup ), 'exact setup boundary inventory' );
		self::check( is_array( $original_summary ) && count( $witnesses ) === count( $wire['events'] ), 'private summary/witness coverage' );
		Wstm108_Plan::validate_cases( $cases, $native );
		self::check( Wstm108_Evidence::summary_commitment( $cases ) === Wstm108_Evidence::summary_commitment( $original_summary['cases'] ?? null ), 'private original case inventory' );
		$projection = Wstm108_Evidence::public_summary( $original_summary );
		self::check( 'wstm108-typed-value-stream-v2' === ( $summary['private_summary_encoding'] ?? null )
			&& $projection['private_summary_encoding'] === $summary['private_summary_encoding'],
			'exact typed summary commitment encoding' );
		foreach ( array( 'registered', 'cases', 'actors', 'providers' ) as $key ) {
			self::check( wstm108_wire_canonical( $projection[ $key ] ?? null ) === wstm108_wire_canonical( $summary[ $key ] ?? null ), 'public/private summary projection: ' . $key );
		}
		$scope = $wire['scopes']['runner']; self::check( is_array( $scope ), 'runner scope type' );
		self::keys( $scope, array( 'verdict', 'semantic_sha256', 'cases' ) );
		self::check( 'passed' === $scope['verdict'] && is_string( $scope['semantic_sha256'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $scope['semantic_sha256'] ) && is_array( $scope['cases'] )
			&& $labels === array_keys( $scope['cases'] ), 'complete runner case scope' );
		self::check( hash( 'sha256', json_encode( $projection, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) === $scope['semantic_sha256']
			&& true === ( $original_summary['completed'] ?? null ) && true === ( $original_summary['cleanup_complete'] ?? null )
			&& 'passed' === ( $original_summary['status'] ?? null ) && 298 === ( $original_summary['passed'] ?? null )
			&& 0 === ( $original_summary['failed'] ?? null ) && 298 === $verdict['passed'] && 0 === $verdict['failed']
			&& 298 === ( $summary['passed'] ?? null ) && 0 === ( $summary['failed'] ?? null ), 'final private summary seal/count association' );
		$gaps = array(); $descriptors = array();
		self::check( array( 'yoast' => true, 'seopress' => true ) === ( $original_summary['providers'] ?? null ), 'actual active provider prerequisites' );
		foreach ( $cases as $index => $case ) {
			$label = $labels[ $index ];
			$binding = $scope['cases'][ $label ]; self::check( is_array( $binding ), 'case binding type' ); self::keys( $binding, array( 'passed', 'events', 'sha256' ) );
			self::check( true === $case['passed'] && true === $binding['passed'] && hash( 'sha256', serialize( $case ) ) === $binding['sha256']
				&& is_array( $binding['events'] ) && array_values( $binding['events'] ) === $binding['events'] && count( $binding['events'] ) > 0, 'original case hash/events' );
			$previous = 0; $case_calls = array();
			foreach ( $binding['events'] as $id ) {
				self::check( is_int( $id ) && $id > $previous && isset( $original_events[ $id ] ), 'case original event reference' );
				$previous = $id; if ( isset( $calls[ $id ] ) ) { $case_calls[] = $calls[ $id ]; }
			}
			self::check( Wstm108_Evidence::summary_commitment( $case ) === Wstm108_Evidence::summary_commitment( $original_events[ $previous ]['case'] ?? null ), 'case final original' );
			if ( 0 === strpos( $label, 'annotations:' ) ) {
				$name = substr( $label, 12 ); $registered = $original_summary['registered'][ $name ] ?? null;
				self::check( is_array( $registered ) && ( $case['descriptor'] ?? null ) instanceof stdClass, 'annotation original metadata' );
				self::annotation( $registered, $case['descriptor'], $case['get_info'] ?? null );
				$matches = array_filter( $catalog_tools, static fn( $tool ) => wstm108_wire_canonical( $tool ) === wstm108_wire_canonical( $case['descriptor'] ) );
				self::check( count( $matches ) > 0, 'annotation not in actual captured catalog' );
				$descriptors[ $name ] = $case['descriptor']->name;
				$info_events = array();
				foreach ( $binding['events'] as $id ) {
					if ( 'gateway-info' === $wire['events'][ $id - 1 ]['parser'] ) { $info_events[] = $original_events[ $id ]; }
				}
				self::check( 1 === count( $info_events ), 'annotation original info count' );
				$info_params = (array) ( $info_events[0]['request']['params'] ?? array() );
				$info_arguments = (array) ( $info_params['arguments'] ?? array() );
				$info_tool = wstm108_gateway_descriptor( $catalogs['gateway:administrator'] ?? array(), 'info' );
				$info_body = json_decode( $info_events[0]['body'], false, 512, JSON_THROW_ON_ERROR );
				self::check( 1 === count( $info_events ) && 'gateway:administrator' === $info_events[0]['boundary']
					&& 'tools/call' === ( $info_events[0]['method'] ?? null ) && false === ( $info_events[0]['transport_error'] ?? null )
					&& ( $info_events[0]['status'] ?? 0 ) >= 200 && ( $info_events[0]['status'] ?? 0 ) < 300
					&& '2.0' === ( $info_events[0]['request']['jsonrpc'] ?? null ) && 'tools/call' === ( $info_events[0]['request']['method'] ?? null )
					&& is_int( $info_events[0]['request']['id'] ?? null ) && $info_events[0]['request']['id'] > 0
					&& '2.0' === ( $info_body->jsonrpc ?? null ) && $info_events[0]['request']['id'] === ( $info_body->id ?? null )
					&& $info_tool->name === ( $info_params['name'] ?? null ) && $name === ( $info_arguments['ability_name'] ?? null )
					&& wstm108_wire_canonical( $case['get_info'] ) === wstm108_wire_canonical( $info_body ),
					'annotation gateway info original association' );
			} else {
				self::check( count( $case_calls ) > 0, 'case has no original canonical tool call' );
				$abilities = self::case_abilities( $label );
				self::check( count( $abilities ) === count( $case_calls ), 'complete original semantic call sequence' );
				$responses = array(); $inputs = array();
				foreach ( $case_calls as $call_index => $event ) {
					$inputs[] = self::call_identity( $event, $abilities[ $call_index ], $descriptors, $catalogs );
					$responses[] = self::case_response( $case, $event, $call_index === count( $case_calls ) - 1 );
				}
				$response = $responses[ count( $responses ) - 1 ];
				list( $boundary, $suffix ) = explode( ':', $label, 2 );
				if ( in_array( $suffix, array( 'get-seo-scores', 'get-readability-scores' ), true ) ) {
					$actor_id = $original_summary['actors']['administrator']['id'] ?? 0;
					$key = 'get-seo-scores' === $suffix ? '_yoast_wpseo_linkdex' : '_yoast_wpseo_content_score';
					$observations = wstm108_native_observations( $case_calls[0], $actor_id );
					foreach ( $observations as $record ) {
						$owned = $resources['posts'][ $record['post_id'] ] ?? null;
						self::check( $key === $record['key'] && is_array( $owned ) && 'post' === ( $owned['type'] ?? null )
							&& $actor_id === ( $owned['author'] ?? null ), 'score native owned actor/post/key association' );
					}
					$post_id = $case['expected']['post_id'] ?? 0;
					self::check( is_int( $post_id ) && true === ( $observations[ wstm108_native_observation_key( $actor_id, $post_id, $key ) ]['allowed'] ?? null ),
						'required owned score native observation missing or denied' );
				}
				if ( 0 === strpos( $suffix, 'seo-key-policy:' ) ) {
					$key = substr( $suffix, strlen( 'seo-key-policy:' ) );
					self::check( $key === ( $inputs[0]['meta_key'] ?? null ), 'actual key read association' );
				}
				if ( 'seo-metrics' === $suffix || in_array( $suffix, array( 'yoast:metadata', 'seopress:metadata' ), true )
					|| 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
					self::check( is_array( $case['readable'] ?? null ) && is_array( $case['providers'] ?? null ), 'required readable/provider association' );
				}
				if ( isset( $case['readable'] ) ) {
					$keys = in_array( $suffix, array( 'yoast:metadata', 'seopress:metadata' ), true )
						? array_values( wstm108_provider_keys( explode( ':', $suffix )[0] ) )
						: array( '_yoast_wpseo_metadesc', '_seopress_titles_desc', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' );
					$native_policy = wstm108_native_readability( $case_calls[0], $inputs[0]['post_id'] ?? 0, $keys, $original_summary['actors']['administrator']['id'] ?? 0 );
					self::check( $native_policy === $case['readable'] && $original_summary['providers'] === ( $case['providers'] ?? null ), 'observed native HTTP/provider association' );
					if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) && count( $case_calls ) > 1 ) {
						self::check( wstm108_native_readability( $case_calls[1], $inputs[1]['post_id'] ?? 0,
							array_values( wstm108_provider_keys( $case['semantic']['provider'] ) ), $original_summary['actors']['administrator']['id'] ?? 0 ) === ( $case['native_provider_readable'] ?? null ),
							'restricted provider native observation' );
					}
				}
				if ( isset( $case['providers'] ) ) { self::check( $original_summary['providers'] === $case['providers'], 'provider prerequisite association' ); }
				if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
					foreach ( $case_calls as $event ) {
						self::check( ( $case['semantic']['key'] ?? null ) === ( $event['request_headers']['X-WSTM108-Deny-Key'] ?? null )
							&& (string) ( $inputs[0]['post_id'] ?? 0 ) === ( $event['request_headers']['X-WSTM108-Deny-Post'] ?? null ), 'actual restricted-read headers' );
					}
				}
				if ( in_array( $suffix, array( 'post:revisions', 'page:revisions' ), true ) ) {
					self::check( ( $case['semantic']['post']['id'] ?? null ) === ( $inputs[0]['post_id'] ?? null )
						&& ( $case['semantic']['revision']['id'] ?? null ) === ( $inputs[1]['revision_id'] ?? null ), 'actual restored revision request' );
				}
				if ( 'standalone-meta-read-update' === $suffix ) {
					foreach ( $inputs as $input ) {
						self::check( ( $case['semantic']['read']['post_id'] ?? null ) === ( $input['post_id'] ?? null )
							&& 'wstm108_payload' === ( $input['meta_key'] ?? null ), 'actual metadata subject/key association' );
					}
					self::check( wstm108_wire_canonical( $case['semantic']['value'] ?? null )
						=== wstm108_wire_canonical( $inputs[1]['meta_value'] ?? null ), 'actual metadata mutation input' );
				}
				if ( 'standalone-meta-delete' === $suffix ) {
					self::check( ( $case['expected']['post_id'] ?? null ) === ( $inputs[0]['post_id'] ?? null )
						&& ( $case['expected']['meta_key'] ?? null ) === ( $inputs[0]['meta_key'] ?? null ), 'actual metadata deletion input' );
				}
				if ( 'patch-content-block' === $suffix ) {
					self::check( ( $case['expected']['id'] ?? null ) === ( $inputs[0]['content_id'] ?? null )
						&& ( $case['expected']['content_hash_before'] ?? null ) === ( $inputs[0]['expected_content_hash'] ?? null )
						&& ( $case['expected']['block_hash_before'] ?? null ) === ( $inputs[0]['expected_block_hash'] ?? null )
						&& 'block_path' === ( $inputs[0]['target_type'] ?? null ) && '0' === ( $inputs[0]['block_path'] ?? null ),
						'actual patch subject/precondition association' );
				}
				$gap = self::replay_case( $case, $response, $responses );
				if ( null !== $gap ) { $gaps[] = $label . ' => ' . $gap; }
			}
		}
		self::check( true === ( $summary['completed'] ?? null ) && true === $verdict['cleanup_complete'], 'untrusted completion/cleanup' );
		Wstm108_Resources::validate_proof( $summary['resource_proof'] ?? array(), $verdict['binding'] );
		if ( $gaps ) {
			$reader->finish();
			throw new Wstm_Product_Evidence_Gap( 'Missing retained semantic capture: ' . implode( '; ', $gaps )
				. '. Available case.expected/expected_metrics and original typed/annotation records were replayed; no failed or missing oracle is replaced by passed flags.' );
		}
		return count( $labels );
	}

	public static function verify( string $stage, string $selected_root, string $retained_context_bytes ): array {
		$context = self::context( $retained_context_bytes );
		self::check( in_array( $stage, array( 'untrusted', 'bounded' ), true ), 'stage selector' );
		$reader = new Wstm_Product_Evidence_Reader( $selected_root, $stage );
		$verdict = $reader->json( 'product-verdict.private.json' );
		$status = self::verdict( $stage, $verdict, $context, hash( 'sha256', $retained_context_bytes ) );
		self::check( 'passed' === $status, 'non-complete producer verdict; retain failed evidence' );
		$count = 'bounded' === $stage ? self::bounded( $reader, $context, $verdict ) : self::untrusted( $reader, $context, $verdict );
		$reader->finish();
		return array( 'stage' => $stage, 'status' => 'records-verified-live-absence-unresolved', 'count' => $count,
			'lane' => $context['php_floor'], 'uid' => 33 );
	}

	public static function verify_cleanup( string $stage, string $selected_root, string $retained_context_bytes ): array {
		$context = self::context( $retained_context_bytes ); self::check( 'bounded' === $stage, 'untrusted cleanup requires retained resource-specific absence observations; ledger flags are insufficient' );
		$reader = new Wstm_Product_Evidence_Reader( $selected_root, $stage );
		$verdict = $reader->json( 'product-verdict.private.json' );
		self::verdict( $stage, $verdict, $context, hash( 'sha256', $retained_context_bytes ) );
		$entered = $reader->listing(); $count = 0;
		self::check( count( $entered ) > 1 && array() === array_diff( $entered, array( 'small', 'large', 'product-verdict.private.json' ) ), 'entered cleanup catalogs' );
		foreach ( array( 'small', 'large' ) as $catalog ) {
			if ( ! in_array( $catalog, $entered, true ) ) { continue; }
			$inventory = $reader->listing( $catalog );
			if ( ! in_array( 'state.json', $inventory, true ) ) {
				self::check( array( 'cleanup-readback.json', 'pre-acquisition.json', 'setup-before.json' ) === $inventory,
					'closed pre-acquisition catalog' );
				self::pre_acquisition_cleanup( $reader->json( $catalog . '/pre-acquisition.json' ),
					$reader->json( $catalog . '/setup-before.json' ), $reader->json( $catalog . '/cleanup-readback.json' ),
					$context, $verdict, $catalog ); ++$count;
				continue;
			}
			self::check( in_array( 'state.json', $inventory, true ) && in_array( 'setup-before.json', $inventory, true )
				&& in_array( 'cleanup-readback.json', $inventory, true ), 'entered catalog lacks actual cleanup observations' );
			foreach ( $inventory as $file ) {
				$operation = 1 === preg_match( '/^operation-(0|[1-9][0-9]*)$/D', $file, $match )
					&& (int) $match[1] < ( 'small' === $catalog ? 1125 : 336 );
				self::check( in_array( $file, array( 'state.json', 'pre-acquisition.json', 'setup-before.json', 'cleanup-readback.json', 'before.json', 'after.json', 'plan.json',
					'seed-measurements.json', 'reference-parity.json', 'projection-probes.json', 'window-cache-faults.json' ), true )
					|| $operation, 'unknown cleanup catalog path' );
			}
			$state = $reader->json( $catalog . '/state.json' );
			self::state( $state, $context, 'small' === $catalog, $reader );
			$pre = $reader->json( $catalog . '/pre-acquisition.json' ); self::pre_acquisition( $pre, $context, 'small' === $catalog );
			self::check( $pre['context_sha256'] === $verdict['context_sha256']
				&& $catalog !== ( $verdict['catalog'] ?? null ), 'acquired catalog cannot claim pre-acquisition' );
			self::cleanup( $reader->json( $catalog . '/setup-before.json' ), $reader->json( $catalog . '/cleanup-readback.json' ) ); ++$count;
		}
		$reader->finish();
		return array( 'stage' => $stage, 'status' => 'cleanup-records-verified-live-absence-unresolved', 'count' => $count,
			'lane' => $context['php_floor'], 'uid' => 33 );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$cleanup_only = '--cleanup' === ( $argv[1] ?? null );
	$offset = $cleanup_only ? 2 : 1; $stage = $argv[ $offset ] ?? '';
	try {
		Wstm_Product_Evidence_Verifier::check( 'cli' === PHP_SAPI && ( $cleanup_only ? 5 : 4 ) === count( $argv ), 'CLI requires optional --cleanup, stage, selected export root and retained context file' );
		$path = $argv[ $offset + 2 ]; $stat = @lstat( $path );
		Wstm_Product_Evidence_Verifier::check( is_array( $stat ) && $stat['size'] > 0 && $stat['size'] <= 4096, 'retained context bound before read' );
		$context = Wstm108_Files::file( $path );
		$summary = $cleanup_only ? Wstm_Product_Evidence_Verifier::verify_cleanup( $stage, $argv[ $offset + 1 ], $context['bytes'] )
			: Wstm_Product_Evidence_Verifier::verify( $stage, $argv[ $offset + 1 ], $context['bytes'] );
		Wstm108_Files::assert_file( $path, $context );
		echo json_encode( $summary, JSON_THROW_ON_ERROR ) . "\n";
		exit( 0 );
	} catch ( Throwable $error ) {
		// Parent captures stderr privately; stdout is the only supported public projection.
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
		echo json_encode( array( 'stage' => in_array( $stage, array( 'bounded', 'untrusted' ), true ) ? $stage : 'unknown',
			'status' => $error instanceof Wstm_Product_Evidence_Gap ? 'unresolved' : 'refused',
			'count' => 0, 'lane' => 'unknown', 'uid' => null ), JSON_THROW_ON_ERROR ) . "\n";
		exit( 1 );
	}
}
