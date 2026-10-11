<?php

declare(strict_types=1);

require_once __DIR__ . '/product-runtime-context.php';

final class Wstm167_Product_Output {
	private string $root;
	private array $files = array();
	private int $bytes = 0;
	private $guard;

	public function __construct( string $root, callable $guard ) {
		Wstm108_Files::directory( $root );
		$this->root = $root;
		$this->guard = $guard;
	}

	public function write( string $path, string $bytes ): void {
		( $this->guard )();
		$relative = substr( $path, strlen( $this->root ) + 1 );
		if ( 0 !== strpos( $path, $this->root . '/' )
			|| 1 !== preg_match( '/^[a-z0-9_.-]+(?:\/[a-z0-9_.-]+)*$/D', $relative )
			|| in_array( '.', explode( '/', $relative ), true ) || in_array( '..', explode( '/', $relative ), true )
			|| strlen( $bytes ) > 33554432
			|| $this->bytes + strlen( $bytes ) > 536870912
			|| ( ! array_key_exists( $path, $this->files ) && count( $this->files ) >= 4096 ) ) {
			throw new RuntimeException( 'Product private output ownership or finite quota refused.' );
		}
		if ( array_key_exists( $path, $this->files ) && null === $this->files[ $path ] ) {
			throw new RuntimeException( 'Product private output retains a failed reservation; no retry.' );
		}
		$this->bytes += strlen( $bytes );
		if ( isset( $this->files[ $path ] ) ) {
			$expected = $this->files[ $path ];
			$this->files[ $path ] = null;
			$this->files[ $path ] = Wstm108_Files::update( $path, $expected, $bytes );
		} else {
			$this->files[ $path ] = null;
			$this->files[ $path ] = Wstm108_Files::create( $path, $bytes );
		}
		( $this->guard )();
	}

	public function recheck(): void {
		( $this->guard )();
		foreach ( $this->files as $path => $file ) {
			if ( null === $file ) {
				throw new RuntimeException( 'Product output has a retained incomplete acquisition.' );
			}
			Wstm108_Files::assert_file( $path, $file );
		}
		( $this->guard )();
	}
}

function wstm121_product_job( array $state, string $shard, string $mode, int $p, int $page, string $fields = 'summary' ): array {
	list( $ability, $type, $content ) = Wstm121Plan::collections( $state['cpt'] )[ $shard ];
	$input = array( 'page' => $page, 'per_page' => $p );
	if ( $content ) {
		$input['fields'] = $fields;
	}
	if ( in_array( $shard, array( 'seo', 'readability' ), true ) ) {
		$input['post_type'] = 'post';
	}
	return array( 'coverage_key' => implode( ':', array( 'small', $shard, $mode, $p, $page, $fields ) ),
		'ability' => $ability, 'type' => $type, 'cache' => 'cold', 'mode' => $mode,
		'input' => $input, 'page' => $page, 'per_page' => $p, 'orphan' => 'orphans' === $shard,
		'controlled_default_summary' => $content && 'summary' === $fields && 20 === $p );
}

function wstm121_product_catalog( array $state, string $directory, bool $small ): array {
	$jobs = array();
	if ( $small ) {
		foreach ( Wstm121Plan::collections( $state['cpt'] ) as $shard => $collection ) {
			foreach ( array( 'dense', 'sparse', 'all-denied' ) as $mode ) {
				foreach ( array( 1, 20, 100 ) as $p ) {
					$last = (int) ceil( count( $state['catalog'][ $collection[1] ] ) / $p ) + 1;
					for ( $page = 1; $page <= $last; ++$page ) {
						$jobs[] = wstm121_product_job( $state, $shard, $mode, $p, $page );
					}
				}
			}
			if ( $collection[2] ) {
				foreach ( array( 'cold', 'warm' ) as $cache ) {
					$full = wstm121_product_job( $state, $shard, 'dense', 20, 1, 'full' );
					$full['cache'] = $cache;
					$full['coverage_key'] .= ':' . $cache;
					$full['edge'] = true;
					$jobs[] = $full;
					$default = wstm121_product_job( $state, $shard, 'dense', 20, 1 );
					$default['input'] = array();
					$default['cache'] = $cache;
					$default['coverage_key'] .= ':default:' . $cache;
					$default['edge'] = true;
					$jobs[] = $default;
					$filtered = wstm121_product_job( $state, $shard, 'dense', 20, 1 );
					$filtered['input'] += array( 'author' => $state['user'], 'status' => 'publish', 'search' => 'Controlled tied title' );
					$filtered['cache'] = $cache;
					$filtered['coverage_key'] .= ':owned-filters:' . $cache;
					$filtered['edge'] = true;
					$jobs[] = $filtered;
					foreach ( array( 'date', 'title', 'modified', 'id' ) as $sort ) {
						foreach ( array( 'ASC', 'DESC' ) as $order ) {
							$tie = wstm121_product_job( $state, $shard, 'dense', 20, 2 );
							$tie['input'] += array( 'orderby' => $sort, 'order' => $order );
							$tie['cache'] = $cache;
							$tie['coverage_key'] .= ':tie:' . $sort . ':' . $order . ':' . $cache;
							$tie['edge'] = true;
							$jobs[] = $tie;
						}
					}
				}
			}
		}
	} else {
		$jobs = Wstm121Plan::selected( $state );
	}
	wstm121_json_write( $directory . '/plan.json', array( 'small' => $small, 'jobs' => $jobs,
		'oracle' => 'Token-owned catalog read before invocation; expected IDs computed from window, authorization and independent reference fixtures.' ) );
	$before = wstm121_snapshot();
	wstm121_json_write( $directory . '/before.json', $before );
	$sequences = array();
	$observed = array();
	foreach ( $jobs as $index => $job ) {
		$operation = $directory . '/operation-' . $index;
		wstm121_require( mkdir( $operation, 0700 ), 'Operation output collision.' );
		$record = wstm121_worker( $state, $job, $operation );
		$observed[] = $record['job']['coverage_key'];
		if ( $small && empty( $job['edge'] ) ) {
			$key = implode( ':', array( $job['ability'], $job['mode'], $job['per_page'] ) );
			$sequences[ $key ] = array_merge( $sequences[ $key ] ?? array(), $record['item_ids'] );
			if ( null === $record['next_page'] ) {
				$catalog = $state['catalog'][ $job['type'] ];
				$ranks = array_flip( $catalog );
				$expected = array_values( array_filter( $catalog, static function ( int $id ) use ( $ranks, $job, $state ): bool {
					$rank = $ranks[ $id ];
					$allowed = 'dense' === $job['mode'] || ( 'sparse' === $job['mode'] && 0 === intdiv( $rank, 20 ) % 2 && 0 === $rank % 3 );
					return $allowed && ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) );
				} ) );
				wstm121_require( $expected === $sequences[ $key ]
					&& count( $sequences[ $key ] ) === count( array_unique( $sequences[ $key ] ) ),
					'Small full sequence lost, duplicated or reordered eligible IDs.' );
			}
		}
		unset( $record );
	}
	$after = wstm121_snapshot();
	wstm121_json_write( $directory . '/after.json', $after );
	wstm121_require( $before === $after, 'Measured read-only operations changed persisted fixture state.' );
	$expected = array_column( $jobs, 'coverage_key' );
	wstm121_require( $expected === $observed && count( $observed ) === count( array_unique( $observed ) ),
		'Product observation inventory differs from its preselected plan.' );
	return array( 'observations' => count( $jobs ), 'expected_labels' => $expected, 'observed_labels' => $observed );
}

function wstm121_product_main(): int {
	$product = Wstm167_Product_Context::open();
	$directory = $product->stage( 'bounded' );
	$data = $product->data();
	$GLOBALS['wstm167_product_plugin_root'] = $data['plugin_root'];
	$GLOBALS['wstm167_product_output'] = new Wstm167_Product_Output( $directory,
		static function () use ( $product ): void { $product->recheck(); } );
	$namespace = 'w121_' . substr( $data['run'], 0, 12 );
	wstm121_require( ! defined( 'ABSPATH' ), 'Standalone WordPress bootstrap required.' );
	$GLOBALS['wp_filter']['init'][0][] = array(
		'function' => static function () use ( $namespace ): void {
			wstm121_require( ! post_type_exists( $namespace ), 'Fixture CPT registration collision.' );
			register_post_type( $namespace, array( 'public' => true, 'show_ui' => true,
				'supports' => array( 'title', 'editor', 'excerpt' ), 'capability_type' => 'post', 'map_meta_cap' => true ) );
		}, 'accepted_args' => 0,
	);
	define( 'WP_USE_THEMES', false );
	try {
		wstm121_json_write( $directory . '/product-verdict.private.json', array( 'schema' => 'wstm-product-verdict-v1',
			'context_sha256' => $product->sha256(), 'binding' => $product->binding(),
			'status' => 'bootstrapping', 'cleanup_complete' => false ) );
		wstm121_require( ! defined( 'DISABLE_WP_CRON' ) && ! defined( 'DOING_CRON' ), 'Fresh cron bootstrap required.' );
		$config_path = $data['wordpress_root'] . '/wp-config.php';
		$config = Wstm108_Files::file( $config_path );
		Wstm167_Product_Context::cron_config( $config['bytes'] );
		require $data['wordpress_root'] . '/wp-load.php';
		Wstm108_Files::assert_file( $config_path, $config );
		Wstm167_Product_Context::cron_runtime( defined( 'DISABLE_WP_CRON' ), defined( 'DISABLE_WP_CRON' ) ? DISABLE_WP_CRON : null,
			defined( 'DOING_CRON' ) && DOING_CRON, did_action( 'wp_cron' ) );
		$product->wordpress();
		wstm121_require( version_compare( $GLOBALS['wp_version'], '6.9', '>=' )
			&& ! wp_using_ext_object_cache() && defined( 'WPSEO_VERSION' ), 'Actual WordPress/provider/cache prerequisites required.' );
		wp_get_abilities();
	} catch ( Throwable $error ) {
		wstm121_json_write( $directory . '/product-verdict.private.json', array( 'schema' => 'wstm-product-verdict-v1',
			'context_sha256' => $product->sha256(), 'binding' => $product->binding(), 'status' => 'failed',
			'phase' => 'bootstrap', 'cleanup_complete' => false, 'private_error' => $error->getMessage(), 'exit' => 1 ) );
		throw $error;
	}
	$old_user = get_current_user_id();
	$summary = array( 'schema' => 'wstm-product-verdict-v1', 'context_sha256' => $product->sha256(),
		'binding' => $product->binding(), 'php' => PHP_VERSION, 'uid' => posix_geteuid(),
		'status' => 'in_progress', 'cleanup_complete' => false, 'catalogs' => array() );
	$original_failure = null;
	$cleanup_failure = null;
	$state = array();
	$catalog_cleaned = false;
	$acquisition_started = false;
	try {
		foreach ( array( true, false ) as $small ) {
			$product->wordpress();
			$catalog_directory = $directory . ( $small ? '/small' : '/large' );
			wstm121_require( mkdir( $catalog_directory, 0700 ), 'Catalog output collision.' );
			$state = array( 'namespace' => $namespace, 'token' => $data['token'], 'cpt' => $namespace,
				'sources' => wstm121_sources(), 'small_catalog' => $small );
			$catalog_cleaned = false;
			$acquisition_started = false;
			wstm121_json_write( $catalog_directory . '/setup-before.json', wstm121_snapshot() );
			Wstm167_Product_Context::pre_acquisition_absence( $data, $GLOBALS['wpdb'] );
			wstm121_json_write( $catalog_directory . '/pre-acquisition.json', array(
				'schema' => 'wstm-bounded-pre-acquisition-v1', 'context_sha256' => $product->sha256(),
				'binding' => $product->binding(), 'namespace' => $namespace, 'token' => $data['token'],
				'small_catalog' => $small, 'acquisition_started' => false,
			) );
			wstm121_seed( $state, $catalog_directory, static function () use ( &$acquisition_started ): void {
				$acquisition_started = true;
			} );
			$inventory = wstm121_product_catalog( $state, $catalog_directory, $small );
			if ( ! $small ) {
				wp_set_current_user( $state['user'] );
				wstm121_parity( $state, $catalog_directory );
				wstm121_projection_probes( $state, $catalog_directory );
				wstm121_window_faults( $state, $catalog_directory );
			}
			$product->wordpress();
			wstm121_require( $state['sources'] === wstm121_sources(), 'Product source changed during measured catalog.' );
			wstm121_cleanup( $state );
			$catalog_cleaned = true;
			wstm121_cleanup_readback( $state, $catalog_directory );
			$state = array();
			$summary['catalogs'][] = array( 'small' => $small, 'inventory' => $inventory, 'cleanup_complete' => true );
		}
		$product->wordpress();
		$GLOBALS['wstm167_product_output']->recheck();
		$summary['cleanup_complete'] = true;
		$summary['status'] = 'passed';
	} catch ( Throwable $error ) {
		$original_failure = $error;
		$summary['status'] = 'failed';
		$summary['private_error'] = $error->getMessage();
		if ( $state && ! $acquisition_started && ! file_exists( $catalog_directory . '/state.json' )
			&& ! is_link( $catalog_directory . '/state.json' ) ) {
			$summary['phase'] = 'pre-acquisition';
			$summary['catalog'] = $small ? 'small' : 'large';
		}
	} finally {
		if ( $state ) {
			try {
				$product->wordpress();
				if ( 'pre-acquisition' === ( $summary['phase'] ?? null ) ) {
					$before = wstm121_json_read( $catalog_directory . '/setup-before.json' );
					$readback = array( 'remaining' => Wstm167_Product_Context::pre_acquisition_absence( $data, $GLOBALS['wpdb'] ),
						'snapshot' => wstm121_snapshot() );
					wstm121_json_write( $catalog_directory . '/cleanup-readback.json', $readback );
					wstm121_require( $before === $readback['snapshot'], 'Pre-acquisition persisted state changed.' );
					wstm121_require( file_exists( $catalog_directory . '/pre-acquisition.json' )
						&& ! file_exists( $catalog_directory . '/state.json' ) && ! is_link( $catalog_directory . '/state.json' ),
						'Pre-acquisition record or state boundary changed.' );
				} elseif ( ! $catalog_cleaned ) {
					wstm121_cleanup( $state );
				}
				if ( 'pre-acquisition' !== ( $summary['phase'] ?? null ) ) {
					wstm121_cleanup_readback( $state, $catalog_directory );
				}
				$summary['cleanup_complete'] = true;
			} catch ( Throwable $error ) {
				$cleanup_failure = $error;
				$summary['private_cleanup_error'] = $error->getMessage();
			}
		}
		wp_set_current_user( $old_user );
		try {
			Wstm108_Files::assert_file( $config_path, $config );
			Wstm167_Product_Context::cron_runtime( defined( 'DISABLE_WP_CRON' ), DISABLE_WP_CRON,
				defined( 'DOING_CRON' ) && DOING_CRON, did_action( 'wp_cron' ) );
		} catch ( Throwable $error ) {
			$cleanup_failure = $error;
			$summary['cleanup_complete'] = false;
			$summary['status'] = 'failed';
			$summary['private_error'] = $summary['private_error'] ?? $error->getMessage();
			$summary['private_cleanup_error'] = $error->getMessage();
		}
		$summary['exit'] = null === $original_failure && null === $cleanup_failure && 'passed' === $summary['status'] ? 0 : 1;
		wstm121_json_write( $directory . '/product-verdict.private.json', $summary );
	}
	echo 'Product bounded stage: ' . $summary['status'] . "\n";
	return $summary['exit'];
}
