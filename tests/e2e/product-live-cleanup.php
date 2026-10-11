<?php

declare(strict_types=1);

require_once __DIR__ . '/product-evidence-verifier.php';

final class Wstm_Product_Live_Cleanup {
	private static function check( bool $condition, string $reason ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Live product absence refused: ' . $reason ); }
	}

	private static function stage( string $stage ): void {
		self::check( in_array( $stage, array( 'bounded', 'untrusted' ), true ), 'fixed stage selector' );
	}

	private static function directory( string $path ): array {
		$identity = Wstm108_Files::directory( $path );
		self::check( 33 === $identity['uid'] && 33 === $identity['gid'] && 0700 === ( $identity['mode'] & 0777 ), 'private retained directory' );
		return $identity;
	}

	private static function file( Wstm_Product_Evidence_Reader $reader, string $root, string $relative ): array {
		$path = $root . '/' . $relative;
		clearstatcache( true, $path ); $stat = @lstat( $path );
		self::check( is_array( $stat ) && 0100000 === ( $stat['mode'] & 0170000 ) && 1 === $stat['nlink']
			&& 33 === $stat['uid'] && 33 === $stat['gid'] && 0600 === ( $stat['mode'] & 0777 )
			&& $stat['size'] >= 0 && $stat['size'] <= 33554432, 'private retained file' );
		$identity = array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) );
		$bytes = $reader->read( $relative );
		clearstatcache( true, $path ); $after = @lstat( $path );
		self::check( is_array( $after ) && $identity === array_intersect_key( $after,
			array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) && $stat['size'] === $after['size']
			&& $stat['size'] === strlen( $bytes ), 'retained file ownership drift' );
		return array( 'identity' => $identity, 'sha256' => hash( 'sha256', $bytes ), 'bytes' => $bytes );
	}

	private static function bounded_absence( array $state, array $context, $db ): void {
		$namespace = 'w121_' . substr( $context['run'], 0, 12 );
		self::check( $namespace === ( $state['namespace'] ?? null ) && $namespace === ( $state['cpt'] ?? null )
			&& $context['token'] === ( $state['token'] ?? null ) && is_int( $state['user'] ?? null ) && $state['user'] > 0,
			'bound namespace/token/actor' );
		self::check( is_object( $db ) && is_callable( array( $db, 'prepare' ) ) && is_callable( array( $db, 'get_var' ) ), 'actual WordPress database reader' );
		foreach ( array( 'posts', 'users', 'usermeta', 'options' ) as $table ) {
			self::check( is_string( $db->$table ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $db->$table ), 'database table identity' );
		}
		$subjects = array(
			array( "SELECT COUNT(*) FROM {$db->posts} WHERE post_content_filtered=%s", $state['token'] ),
			array( "SELECT COUNT(*) FROM {$db->users} WHERE ID=%d OR user_login=%s", $state['user'], $namespace ),
			array( "SELECT COUNT(*) FROM {$db->usermeta} WHERE user_id=%d", $state['user'] ),
			array( "SELECT COUNT(*) FROM {$db->options} WHERE option_name=%s", $namespace . '_control' ),
		);
		foreach ( $subjects as $subject ) {
			$sql = $db->prepare( ...$subject );
			self::check( is_string( $sql ) && 0 === strpos( $sql, 'SELECT COUNT(*) FROM ' ), 'prepared absence query' );
			$value = $db->get_var( $sql );
			self::check( '' === ( $db->last_error ?? null ) && ( 0 === $value || '0' === $value ), 'nonzero, unreadable or noncanonical live subject' );
		}
	}

	private static function catalogs( array $entries, callable $visit ): int {
		self::check( count( $entries ) > 1 && array() === array_diff( $entries,
			array( 'small', 'large', 'product-verdict.private.json' ) )
			&& in_array( 'product-verdict.private.json', $entries, true ), 'finite entered catalogs' );
		$count = 0;
		foreach ( array( 'small', 'large' ) as $catalog ) {
			if ( ! in_array( $catalog, $entries, true ) ) { continue; }
			$visit( $catalog ); ++$count;
		}
		return $count;
	}

	private static function quiet( callable $observe ): array {
		$level = ob_get_level(); $emitted = false;
		self::check( ob_start( static function ( string $bytes ) use ( &$emitted ): string {
			$emitted = $emitted || '' !== $bytes;
			return '';
		}, 4096 ), 'runtime output guard' );
		try {
			$result = $observe();
			self::check( ob_get_level() === $level + 1, 'runtime changed output buffering' );
			ob_end_flush();
			self::check( ! $emitted, 'unexpected runtime stdout' );
			return $result;
		} finally {
			while ( ob_get_level() > $level ) { self::check( @ob_end_clean(), 'cannot restore runtime output buffering' ); }
		}
	}

	public static function run( string $stage ): array {
		return self::quiet( static fn() => self::observe( $stage ) );
	}

	private static function cron_config( string $bytes ): void {
		Wstm167_Product_Context::cron_config( $bytes );
	}

	private static function cron_runtime( bool $defined, $value, bool $doing_cron, int $cron_actions ): void {
		Wstm167_Product_Context::cron_runtime( $defined, $value, $doing_cron, $cron_actions );
	}

	private static function observe( string $stage ): array {
		self::stage( $stage );
		$product = Wstm167_Product_Context::open(); $context = $product->data();
		$run = '/qa-artifacts/' . $context['run']; $root = $run . '/' . $stage;
		$run_identity = self::directory( $run ); $root_identity = self::directory( $root );
		$reader = new Wstm_Product_Evidence_Reader( $root, $stage );
		$final = self::file( $reader, $root, 'product-verdict.private.json' );
		$verdict = Wstm_Product_Evidence_Reader::decode_json( $final['bytes'] );
		$status = Wstm_Product_Evidence_Verifier::verdict( $stage, $verdict, $context, $product->sha256() );
		self::check( in_array( $status, array( 'passed', 'failed', 'incomplete' ), true )
			&& ( ! array_key_exists( 'phase', $verdict ) || ( 'bounded' === $stage
				&& 'pre-acquisition' === $verdict['phase'] ) ), 'final producer ledger' );
		self::check( ! defined( 'ABSPATH' ), 'standalone owned WordPress bootstrap' );
		self::check( ! defined( 'DISABLE_WP_CRON' ) && ! defined( 'WP_USE_THEMES' )
			&& ! defined( 'DOING_CRON' ), 'fresh bootstrap cron/theme constants' );
		$config_path = $context['wordpress_root'] . '/wp-config.php';
		$config = Wstm108_Files::file( $config_path );
		self::cron_config( $config['bytes'] );
		define( 'WP_USE_THEMES', false );
		$_SERVER['HTTP_HOST'] = 'localhost';
		require $context['wordpress_root'] . '/wp-load.php';
		Wstm108_Files::assert_file( $config_path, $config );
		self::cron_runtime( defined( 'DISABLE_WP_CRON' ), defined( 'DISABLE_WP_CRON' ) ? DISABLE_WP_CRON : null,
			defined( 'DOING_CRON' ) && DOING_CRON, did_action( 'wp_cron' ) );
		$product->wordpress();
		$count = 0;
		if ( 'untrusted' === $stage ) {
			$path = $root . '/resources.json'; $snapshot = self::file( $reader, $root, 'resources.json' );
			$ledger = Wstm_Product_Evidence_Reader::decode_json( $snapshot['bytes'] );
			self::check( $product->binding() === ( $ledger['binding'] ?? null )
				&& 'wstm108-' . substr( $context['run'], 0, 16 ) === ( $ledger['run'] ?? null )
				&& 'cleaned' === ( $ledger['phase'] ?? null ), 'final bound untrusted ledger' );
			$resources = Wstm108_Resources::resume( $path, $product->binding(), $snapshot['identity'] );
			$resources->verify_absent();
			$count = 1;
		} else {
			$entries = $reader->listing();
			$count = self::catalogs( $entries, static function ( string $catalog ) use ( $root, $reader, $context, $product, $verdict ): void {
				self::directory( $root . '/' . $catalog );
				$inventory = $reader->listing( $catalog );
				if ( ! in_array( 'state.json', $inventory, true ) ) {
					self::check( array( 'cleanup-readback.json', 'pre-acquisition.json', 'setup-before.json' ) === $inventory,
						'closed pre-acquisition catalog' );
					$pre = self::file( $reader, $root, $catalog . '/pre-acquisition.json' );
					$before = self::file( $reader, $root, $catalog . '/setup-before.json' );
					$after = self::file( $reader, $root, $catalog . '/cleanup-readback.json' );
					$baseline = Wstm_Product_Evidence_Reader::decode_json( $before['bytes'] );
					Wstm_Product_Evidence_Verifier::pre_acquisition_cleanup(
						Wstm_Product_Evidence_Reader::decode_json( $pre['bytes'] ), $baseline,
						Wstm_Product_Evidence_Reader::decode_json( $after['bytes'] ), $context, $verdict, $catalog );
					require_once __DIR__ . '/bounded-list-benchmark.php';
					$product->wordpress();
					Wstm167_Product_Context::pre_acquisition_absence( $context, $GLOBALS['wpdb'] );
					self::check( $baseline === wstm121_snapshot(), 'fresh native pre-acquisition snapshot changed' );
					$product->wordpress();
					self::check( $inventory === $reader->listing( $catalog ), 'pre-acquisition custody drift' );
					foreach ( array( 'pre-acquisition.json' => $pre, 'setup-before.json' => $before, 'cleanup-readback.json' => $after ) as $name => $pin ) {
						self::check( $pin === self::file( $reader, $root, $catalog . '/' . $name ), 'pre-acquisition file drift' );
					}
					return;
				}
				self::check( $catalog !== ( $verdict['catalog'] ?? null ), 'acquired catalog claims pre-acquisition' );
				$file = self::file( $reader, $root, $catalog . '/state.json' );
				$state = Wstm_Product_Evidence_Reader::decode_json( $file['bytes'] );
				Wstm_Product_Evidence_Verifier::state( $state, $context, 'small' === $catalog, $reader );
				$product->wordpress();
				self::bounded_absence( $state, $context, $GLOBALS['wpdb'] ?? null );
				$product->wordpress();
			} );
		}
		$reader->finish(); $product->wordpress();
		Wstm108_Files::assert_file( $config_path, $config );
		self::cron_runtime( defined( 'DISABLE_WP_CRON' ), DISABLE_WP_CRON,
			defined( 'DOING_CRON' ) && DOING_CRON, did_action( 'wp_cron' ) );
		self::check( $run_identity === self::directory( $run ) && $root_identity === self::directory( $root ), 'retained run/stage custody drift' );
		return array( 'stage' => $stage, 'status' => 'live-absence-verified', 'count' => $count,
			'lane' => $context['php_floor'], 'uid' => 33 );
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$stage = $argv[1] ?? '';
	try {
		if ( 'cli' !== PHP_SAPI || 2 !== count( $argv ) ) { throw new RuntimeException( 'Live cleanup CLI requires only the stage name.' ); }
		echo json_encode( Wstm_Product_Live_Cleanup::run( $stage ), JSON_THROW_ON_ERROR ) . "\n";
		exit( 0 );
	} catch ( Throwable $error ) {
		fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
		echo json_encode( array( 'stage' => in_array( $stage, array( 'bounded', 'untrusted' ), true ) ? $stage : 'unknown',
			'status' => 'refused', 'count' => 0, 'lane' => 'unknown', 'uid' => null ), JSON_THROW_ON_ERROR ) . "\n";
		exit( 1 );
	}
}
