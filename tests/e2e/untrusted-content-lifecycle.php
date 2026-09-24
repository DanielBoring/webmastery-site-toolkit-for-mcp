<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';
require_once __DIR__ . '/destructive-safety-boot.php';
require_once __DIR__ . '/untrusted-content-runtime.php';
require_once __DIR__ . '/untrusted-content-private-wire.php';

/** Owns only two new MU loaders and one private lock; never writes wp-config. */
final class Wstm108_Lifecycle {
	private string $root;
	private string $plugin;
	private string $lock;
	private array $binding;
	private ?array $anchor;
	private ?array $state_file = null;
	private ?string $state_sha256 = null;

	public function __construct( string $root, string $plugin, string $lock, array $binding, ?array $anchor = null ) {
		if ( array_keys( $binding ) !== array( 'owner', 'project', 'source_sha', 'tree_sha', 'package_sha256' )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/D', $binding['owner'] ?? '' )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $binding['project'] ?? '' )
			|| 1 !== preg_match( '/^[a-f0-9]{40}$/D', $binding['source_sha'] ?? '' )
			|| 1 !== preg_match( '/^[a-f0-9]{40}$/D', $binding['tree_sha'] ?? '' )
			|| ( null !== $binding['package_sha256'] && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $binding['package_sha256'] ) ) ) {
			throw new RuntimeException( 'WSTM108 Invalid exact stage/source/project binding.' );
		}
		$this->root = $root;
		$this->plugin = $plugin;
		$this->lock = $lock;
		$this->binding = $binding;
		$this->anchor = $anchor;
		if ( null !== $anchor ) {
			$this->require( array_keys( $anchor ) === array( 'version', 'binding', 'root', 'plugin', 'lock', 'lock_identity', 'state_identity', 'context_sha256' )
				&& 1 === $anchor['version'] && $binding === $anchor['binding']
				&& $root === $anchor['root'] && $plugin === $anchor['plugin'] && $lock === $anchor['lock']
				&& is_string( $anchor['context_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $anchor['context_sha256'] ),
				'Foreign independent journal anchor.' );
			foreach ( array( 'lock_identity' => 0040000, 'state_identity' => 0100000 ) as $key => $type ) {
				$identity = $anchor[ $key ];
				$this->require( is_array( $identity ), 'Missing independently captured filesystem identity.' );
				$keys = array_keys( $identity );
				sort( $keys, SORT_STRING );
				$this->require( array( 'dev', 'gid', 'ino', 'mode', 'nlink', 'uid' ) === $keys
					&& 6 === count( array_filter( $identity, 'is_int' ) )
					&& $type === ( $identity['mode'] & 0170000 ) && $identity['nlink'] >= 1
					&& ( 'state_identity' !== $key || 1 === $identity['nlink'] ), 'Invalid independently captured filesystem identity.' );
			}
		}
	}

	public static function decode_anchor( string $encoded ): ?array {
		if ( '' === $encoded ) {
			return null;
		}
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || base64_encode( $bytes ) !== $encoded ) {
			throw new RuntimeException( 'WSTM108 Malformed independent journal anchor.' );
		}
		$anchor = json_decode( $bytes, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $anchor ) ) {
			throw new RuntimeException( 'WSTM108 Missing independent journal anchor object.' );
		}
		return $anchor;
	}

	public function anchor(): array {
		$this->require( null !== $this->anchor && is_string( $this->anchor['context_sha256'] ), 'Journal anchor is not source-bound.' );
		$this->state();
		return $this->anchor;
	}

	public function assert_anchor(): void {
		$this->state();
	}

	private function require( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'WSTM108 ' . $message );
		}
	}

	private function state(): array {
		$this->require( null !== $this->anchor, 'No independent journal anchor; refusing authoritative state reads.' );
		Wstm108_Files::directory( $this->root );
		Wstm108_Files::directory( $this->plugin );
		$lock = Wstm108_Files::directory( $this->lock );
		$this->require( $lock === $this->anchor['lock_identity'], 'Independently bound private directory changed.' );
		$file = Wstm108_Files::read_bound( $this->lock . '/state.json', $this->anchor['state_identity'] );
		$this->require( null === $this->state_sha256 || $this->state_sha256 === $file['sha256'], 'Lifecycle generation changed outside its verified writer.' );
		$state = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$this->require( $this->binding === ( $state['binding'] ?? null ) && $this->root === ( $state['root'] ?? null )
			&& $this->plugin === ( $state['plugin'] ?? null ) && $lock === ( $state['lock_identity'] ?? null ), 'Foreign private stage state; retaining all resources.' );
		$this->require( null === $this->anchor['context_sha256'] || $this->anchor['context_sha256'] === ( $state['context_sha256'] ?? null ), 'Independently bound source context changed.' );
		$this->require( 'Windows' === PHP_OS_FAMILY || ( 0700 === ( $lock['mode'] & 0777 ) && 0600 === ( $file['identity']['mode'] & 0777 ) ), 'Private stage state permissions changed.' );
		$this->assert_unchanged( $state );
		$this->state_file = $file;
		$this->state_sha256 = $file['sha256'];
		return $state;
	}

	private function save( array $state ): void {
		$before = $this->state();
		$this->require( $before['binding'] === $state['binding'], 'Stage binding cannot be changed.' );
		$path = $this->lock . '/state.json';
		$file = Wstm108_Files::update( $path, $this->state_file, json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->state_file = $file;
		$this->state_sha256 = $file['sha256'];
	}

	private function assert_unchanged( array $state ): void {
		Wstm108_Files::assert_file( $this->root . '/wp-config.php', $state['config'] );
		$this->require( Wstm108_Files::directory( $this->root . '/wp-content/mu-plugins' ) === $state['mu_identity'], 'Original MU directory metadata changed.' );
		foreach ( $state['sources'] as $path => $expected ) {
			Wstm108_Files::assert_file( $this->plugin . '/' . $path, $expected );
		}
	}

	public function acquire(): void {
		$this->require( null === $this->anchor, 'An existing anchor cannot acquire a new lifecycle.' );
		Wstm108_Files::directory( $this->root );
		Wstm108_Files::directory( $this->plugin );
		Wstm108_Files::directory( dirname( $this->lock ) );
		$mu = Wstm108_Files::directory( $this->root . '/wp-content/mu-plugins' );
		$config = Wstm108_Files::file( $this->root . '/wp-config.php' );
		$this->require( false === strpos( $config['bytes'], 'WSTM108_' ), 'Preexisting runtime constant/configuration collision.' );
		$this->require( false === @lstat( $this->lock ), 'Private stage lock collision.' );
		$probe_path = $this->root . '/wp-content/mu-plugins/wstm108-probe.php';
		$loader_path = $this->root . '/wp-content/mu-plugins/wstm108-runtime.php';
		$this->require( array() === glob( $this->root . '/wp-content/mu-plugins/wstm108-*.php' ), 'Preexisting probe or runtime-loader collision.' );
		foreach ( array( $probe_path, $loader_path ) as $path ) {
			$this->require( false === @lstat( $path ), 'Preexisting or symlinked MU loader collision.' );
		}
		$sources = array();
		$paths = glob( $this->plugin . '/tests/e2e/untrusted-content-*.php' );
		$this->require( is_array( $paths ) && array() !== $paths, 'Missing owned source inventory.' );
		$paths[] = $this->plugin . '/tests/e2e/error-contract-fixture.php';
		foreach ( $paths as $path ) {
			$sources[ substr( $path, strlen( $this->plugin ) + 1 ) ] = Wstm108_Files::file( $path );
		}
		$this->require( @mkdir( $this->lock, 0700, false ), 'Cannot exclusively acquire the private stage lock.' );
		$owner = $this->binding['owner'];
		$probe_secret = bin2hex( random_bytes( 32 ) );
		$state = array(
			'binding' => $this->binding, 'root' => $this->root, 'plugin' => $this->plugin,
			'lock_identity' => Wstm108_Files::directory( $this->lock ), 'mu_identity' => $mu,
			'config' => $config, 'sources' => $sources, 'probe_secret' => $probe_secret,
			'probe_path' => $probe_path, 'loader_path' => $loader_path,
			'probe_bytes' => "<?php\n/* WSTM108 owned read-only probe */\ndefine('WSTM108_PROBE_OWNER', " . var_export( $owner, true ) . ");\ndefine('WSTM108_PROBE_SECRET', " . var_export( $probe_secret, true ) . ");\nrequire " . var_export( $this->plugin . '/tests/e2e/untrusted-content-probe.php', true ) . ";\n",
			'loader_bytes' => "<?php\n/* WSTM108 owned disposable runtime */\nif (defined('WSTM108_ALLOW_DISPOSABLE') || defined('WSTM108_OWNER')) { throw new RuntimeException('WSTM108 runtime constant collision.'); }\ndefine('WSTM108_ALLOW_DISPOSABLE', true);\ndefine('WSTM108_OWNER', " . var_export( $owner, true ) . ");\nrequire " . var_export( $this->plugin . '/tests/e2e/untrusted-content-fixture.php', true ) . ";\nrequire " . var_export( $this->plugin . '/tests/e2e/error-contract-fixture.php', true ) . ";\n",
		);
		$file = Wstm108_Files::create( $this->lock . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->anchor = array(
			'version' => 1, 'binding' => $this->binding, 'root' => $this->root, 'plugin' => $this->plugin, 'lock' => $this->lock,
			'lock_identity' => $state['lock_identity'], 'state_identity' => $file['identity'], 'context_sha256' => null,
		);
	}

	public function install_probe( array $original ): void {
		$state = $this->state();
		$this->require( ! isset( $state['original'] ) && false === ( $original['optin_defined'] ?? null )
			&& false === ( $original['owner_defined'] ?? null ), 'Original runtime was already captured or has foreign constants.' );
		$state['original'] = $original;
		$this->save( $state );
		$this->assert_anchor();
		$state['probe'] = Wstm108_Files::create( $state['probe_path'], $state['probe_bytes'], true );
		$this->save( $state );
	}

	public function enable(): void {
		$state = $this->state();
		$this->require( isset( $state['original_attestation'] ) && ! isset( $state['loader'] ), 'Original CLI and HTTP state must be proven before runtime activation.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		$this->assert_anchor();
		$state['loader'] = Wstm108_Files::create( $state['loader_path'], $state['loader_bytes'], true );
		$this->save( $state );
	}

	public function bind_context( array $context, string $sha256 ): void {
		$this->require( null !== $this->anchor && ( null === $this->anchor['context_sha256'] || $sha256 === $this->anchor['context_sha256'] ), 'Foreign source context for the independent anchor.' );
		$state = $this->state();
		$this->require( $this->binding === ( $context['binding'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $sha256 ), 'Foreign source context.' );
		if ( isset( $state['context'] ) ) {
			$this->require( $context === $state['context'] && $sha256 === $state['context_sha256'], 'Source context changed after acquisition.' );
			return;
		}
		$this->require( ! isset( $state['original'] ), 'Source context must be bound before WordPress state or credentials.' );
		$state['context'] = $context;
		$state['context_sha256'] = $sha256;
		$this->save( $state );
		$this->anchor['context_sha256'] = $sha256;
	}

	public function require_enabled( array $runtime, string $sha256 ): void {
		$state = $this->state();
		$this->require( $sha256 === ( $state['context_sha256'] ?? null ) && isset( $state['enabled_attestation'] )
			&& $runtime === $state['enabled_attestation']['runtime'], 'No exact owned CLI/HTTP enabled attestation before credentials.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		Wstm108_Files::assert_file( $state['loader_path'], $state['loader'] );
	}

	public function create_wire(): Wstm108_PrivateWire {
		$state = $this->state();
		$this->require( isset( $state['context_sha256'] ) && ! isset( $state['wire_identity'] ), 'Wire journal requires a newly acquired source-bound lifecycle.' );
		$wire = Wstm108_PrivateWire::create( $this->lock, $this->binding, $state['context_sha256'], fn() => $this->assert_anchor() );
		$state['wire_identity'] = $wire->identity();
		$this->save( $state );
		return $wire;
	}

	public function wire( ?array $prepared = null ): Wstm108_PrivateWire {
		$state = $this->state();
		$this->require( isset( $state['wire_identity'], $state['context_sha256'] ), 'Private original-wire journal was never durably enrolled.' );
		return Wstm108_PrivateWire::resume( $this->lock, $this->binding, $state['context_sha256'], $state['wire_identity'], function () use ( $prepared ): void {
			if ( null === $prepared ) {
				$this->assert_anchor();
			} else {
				$this->assert_wire_retirement( $prepared );
			}
		} );
	}

	public function bind_resources( array $identity ): void {
		$state = $this->state();
		$this->require( isset( $state['enabled_attestation'] ) && ! isset( $state['resource_identity'] ) && ! isset( $state['prepared'] ), 'Resource journal must be enrolled once before credentials.' );
		Wstm108_Files::read_bound( $this->lock . '/resources.json', $identity );
		$state['resource_identity'] = $identity;
		$this->save( $state );
	}

	public function resource_identity(): array {
		$state = $this->state();
		$this->require( isset( $state['resource_identity'] ), 'No independently enrolled resource journal.' );
		Wstm108_Files::read_bound( $this->lock . '/resources.json', $state['resource_identity'] );
		return $state['resource_identity'];
	}

	public function assert_original_runtime( array $runtime ): void {
		$state = $this->state();
		$this->require( $runtime === ( $state['original'] ?? null ), 'Original runtime changed after retirement preparation.' );
	}

	public function public_state(): array {
		$state = $this->state();
		return array(
			'binding' => $this->binding, 'config_sha256' => $state['config']['sha256'],
			'config_identity' => $state['config']['identity'], 'mu_identity' => $state['mu_identity'],
			'original_attestation' => $state['original_attestation'] ?? null,
			'enabled_attestation' => $state['enabled_attestation'] ?? null,
			'restored_attestation' => $state['restored_attestation'] ?? null,
		);
	}

	public function identity(): array {
		$state = $this->state();
		$identity = array( 'owner' => $this->binding['owner'], 'root' => realpath( $this->root ),
			'plugin_root' => realpath( $this->plugin ), 'config_sha256' => $state['config']['sha256'] );
		if ( isset( $state['http_uid'] ) ) {
			$identity['uid'] = $state['http_uid'];
		}
		return $identity;
	}

	public function secret(): string {
		return $this->state()['probe_secret'];
	}

	public function attest( string $phase, array $cli, callable $verify ): array {
		$state = $this->state();
		$this->require( in_array( $phase, array( 'original', 'enabled', 'restored' ), true ) && isset( $state['original'], $state['probe'] ), 'Unknown or unprepared attestation phase.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		if ( 'enabled' === $phase ) {
			Wstm108_Files::assert_file( $state['loader_path'], $state['loader'] );
			Wstm108_Runtime::validate_enabled( $state['original'], $cli, $this->binding['owner'], $this->plugin, $state['sources'] );
		} else {
			$this->require( $cli === $state['original'], 'Original CLI schema/server/observer state differs.' );
			$this->require( false === @lstat( $state['loader_path'] ), 'Runtime loader remains during original-state attestation.' );
		}
		$stale = array( $state['original'] );
		if ( isset( $state['enabled'] ) ) {
			$stale[] = $state['enabled'];
		}
		$boot = $verify( $this->identity(), $cli, $stale );
		wstm116_validate_boot( $boot, $this->identity() );
		$this->require( $boot['runtime'] === $cli, 'Actual HTTP state does not match the attested CLI state.' );
		$state['http_uid'] = $boot['identity']['uid'];
		$state[ $phase . '_attestation' ] = $boot;
		if ( 'enabled' === $phase ) {
			$state['enabled'] = $cli;
		}
		$this->save( $state );
		return $boot;
	}

	public function restore_loader(): void {
		$state = $this->state();
		if ( isset( $state['loader'] ) && false !== @lstat( $state['loader_path'] ) ) {
			$this->assert_anchor();
			Wstm108_Files::remove( $state['loader_path'], $state['loader'] );
		}
		$this->require( false === @lstat( $state['loader_path'] ), 'Unproven runtime loader remains; no original configuration is overwritten.' );
	}

	private function final_state( Wstm108_Proof $certificate ): array {
		$proof = $certificate->receipt();
		$state = $this->state();
		$this->require( isset( $state['restored_attestation'] ) && true === ( $proof['cleanup_complete'] ?? null )
			&& $this->binding === ( $proof['binding'] ?? null ), 'Source/project/owner-bound cleanup and restored HTTP state are required.' );
		$this->require( isset( $state['context_sha256'] ) && $state['context_sha256'] === ( $proof['stage_context_sha256'] ?? null )
			&& array( 'gateway', 'individual' ) === ( $proof['boundaries'] ?? null )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $proof['labels_sha256'] ?? '' )
			&& is_array( $proof['resource_proof'] ?? null ), 'Complete plan, source-context and owned-resource certificate required.' );
		$this->require( false === @lstat( $state['loader_path'] ), 'Runtime loader remains.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		return $state;
	}

	public function preflight_finalization( Wstm108_Proof $certificate ): void {
		$this->final_state( $certificate );
		$expected = array_merge( array( '.', '..', 'resources.json', 'state.json' ), $this->wire()->preflight() );
		sort( $expected, SORT_STRING );
		$this->require( $expected === scandir( $this->lock ), 'Unknown private lock entry; do not retire owned resource evidence.' );
	}

	private static function target( array $file ): array {
		return array( 'identity' => $file['identity'], 'sha256' => $file['sha256'], 'length' => strlen( $file['bytes'] ) );
	}

	public function prepare_retirement( Wstm108_Proof $certificate ): array {
		$this->preflight_finalization( $certificate );
		$state = $this->state();
		$this->require( ! isset( $state['prepared'] ), 'Retirement preparation cannot be repeated or adopted.' );
		$targets = $this->wire()->inventory();
		$targets['resources.json'] = self::target( Wstm108_Files::read_bound( $this->lock . '/resources.json', $this->resource_identity() ) );
		$targets['probe'] = self::target( $state['probe'] );
		ksort( $targets, SORT_STRING );
		$state['prepared'] = array( 'generation' => bin2hex( random_bytes( 16 ) ), 'targets' => $targets );
		$this->save( $state );
		return array(
			'version' => 1, 'binding' => $this->binding, 'context_sha256' => $state['context_sha256'],
			'generation' => $state['prepared']['generation'], 'state_sha256' => $this->state_file['sha256'],
			'inventory_sha256' => hash( 'sha256', json_encode( $targets, JSON_THROW_ON_ERROR ) ),
			'target_count' => count( $targets ) + 1,
		);
	}

	public function assert_prepared( array $prepared ): array {
		$state = $this->state();
		$this->require( array_keys( $prepared ) === array( 'version', 'binding', 'context_sha256', 'generation', 'state_sha256', 'inventory_sha256', 'target_count' )
			&& 1 === $prepared['version'] && $this->binding === $prepared['binding']
			&& $state['context_sha256'] === $prepared['context_sha256'] && isset( $state['prepared'] )
			&& $state['prepared']['generation'] === $prepared['generation'] && $this->state_file['sha256'] === $prepared['state_sha256']
			&& hash( 'sha256', json_encode( $state['prepared']['targets'], JSON_THROW_ON_ERROR ) ) === $prepared['inventory_sha256']
			&& count( $state['prepared']['targets'] ) + 1 === $prepared['target_count'], 'Foreign, stale, replayed or changed prepared retirement binding.' );
		return $state['prepared']['targets'];
	}

	public function verify_retirement_targets( array $prepared ): array {
		$targets = $this->assert_prepared( $prepared );
		$state = $this->state();
		$entries = array( '.', '..', 'state.json' );
		foreach ( $targets as $name => $expected ) {
			$this->require( in_array( $name, array( 'resources.json', 'private-wire.json', 'probe' ), true )
				|| 1 === preg_match( '/^wire-[0-9]{6}\.bin$/D', $name ), 'Foreign retirement target name.' );
			$path = 'probe' === $name ? $state['probe_path'] : $this->lock . '/' . $name;
			$actual = self::target( Wstm108_Files::read_bound( $path, $expected['identity'] ) );
			$this->require( $expected === $actual, 'Prepared retirement target identity or bytes changed.' );
			if ( 'probe' !== $name ) { $entries[] = $name; }
		}
		sort( $entries, SORT_STRING );
		$this->require( $entries === scandir( $this->lock ), 'Retirement target inventory changed after preparation.' );
		return $targets;
	}

	private function assert_wire_retirement( array $prepared ): void {
		$targets = $this->assert_prepared( $prepared );
		$state = $this->state();
		clearstatcache();
		$this->require( false === @lstat( $this->lock . '/resources.json' ) && false === @lstat( $state['loader_path'] ), 'Retired resource journal or runtime loader reappeared.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		$allowed = array_merge( array( '.', '..', 'state.json' ), array_keys( array_diff_key( $targets, array( 'probe' => true, 'resources.json' => true ) ) ) );
		$actual = scandir( $this->lock );
		$this->require( is_array( $actual ) && array() === array_diff( $actual, $allowed ), 'Unknown evidence appeared during partial wire retirement.' );
	}

	public function finalize( Wstm108_Proof $certificate, array $prepared ): void {
		$this->assert_prepared( $prepared );
		$state = $this->final_state( $certificate );
		$this->require( array( '.', '..', 'state.json' ) === scandir( $this->lock ), 'Private journal or unknown lock entries remain; retaining evidence.' );
		$this->assert_prepared( $prepared );
		Wstm108_Files::remove( $state['probe_path'], $state['probe'] );
		$this->assert_prepared( $prepared );
		$this->require( array( '.', '..', 'state.json' ) === scandir( $this->lock )
			&& false === @lstat( $state['probe_path'] ), 'Owned inventory changed during final journal retirement.' );
		$private = $this->state_file;
		Wstm108_Files::remove( $this->lock . '/state.json', $private );
		if ( ! @rmdir( $this->lock ) ) {
			throw new RuntimeException( 'WSTM108 Partial retirement: cannot remove stage directory; no journal is recreated or adopted.' );
		}
		clearstatcache();
		$this->require( false === @lstat( $this->lock ) && false === @lstat( $state['probe_path'] ), 'Owned stage paths reappeared; refusing further deletion.' );
	}
}
