<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';
require_once __DIR__ . '/destructive-safety-boot.php';
require_once __DIR__ . '/untrusted-content-runtime.php';

/** Owns only two new MU loaders and one private lock; never writes wp-config. */
final class Wstm108_Lifecycle {
	private string $root;
	private string $plugin;
	private string $lock;
	private array $binding;

	public function __construct( string $root, string $plugin, string $lock, array $binding ) {
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
	}

	private function require( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'WSTM108 ' . $message );
		}
	}

	private function state(): array {
		Wstm108_Files::directory( $this->root );
		Wstm108_Files::directory( $this->plugin );
		$lock = Wstm108_Files::directory( $this->lock );
		$file = Wstm108_Files::file( $this->lock . '/state.json' );
		$state = json_decode( $file['bytes'], true, 512, JSON_THROW_ON_ERROR );
		$this->require( $this->binding === ( $state['binding'] ?? null ) && $this->root === ( $state['root'] ?? null )
			&& $this->plugin === ( $state['plugin'] ?? null ) && $lock === ( $state['lock_identity'] ?? null ), 'Foreign private stage state; retaining all resources.' );
		$this->require( 'Windows' === PHP_OS_FAMILY || ( 0700 === ( $lock['mode'] & 0777 ) && 0600 === ( $file['identity']['mode'] & 0777 ) ), 'Private stage state permissions changed.' );
		$this->assert_unchanged( $state );
		return $state;
	}

	private function save( array $state ): void {
		$before = $this->state();
		$this->require( $before['binding'] === $state['binding'], 'Stage binding cannot be changed.' );
		$path = $this->lock . '/state.json';
		Wstm108_Files::update( $path, Wstm108_Files::file( $path ), json_encode( $state, JSON_THROW_ON_ERROR ) );
	}

	private function assert_unchanged( array $state ): void {
		Wstm108_Files::assert_file( $this->root . '/wp-config.php', $state['config'] );
		$this->require( Wstm108_Files::directory( $this->root . '/wp-content/mu-plugins' ) === $state['mu_identity'], 'Original MU directory metadata changed.' );
		foreach ( $state['sources'] as $path => $expected ) {
			Wstm108_Files::assert_file( $this->plugin . '/' . $path, $expected );
		}
	}

	public function acquire(): void {
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
		Wstm108_Files::create( $this->lock . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
	}

	public function install_probe( array $original ): void {
		$state = $this->state();
		$this->require( ! isset( $state['original'] ) && false === ( $original['optin_defined'] ?? null )
			&& false === ( $original['owner_defined'] ?? null ), 'Original runtime was already captured or has foreign constants.' );
		$state['original'] = $original;
		$this->save( $state );
		$state['probe'] = Wstm108_Files::create( $state['probe_path'], $state['probe_bytes'], true );
		$this->save( $state );
	}

	public function enable(): void {
		$state = $this->state();
		$this->require( isset( $state['original_attestation'] ) && ! isset( $state['loader'] ), 'Original CLI and HTTP state must be proven before runtime activation.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		$state['loader'] = Wstm108_Files::create( $state['loader_path'], $state['loader_bytes'], true );
		$this->save( $state );
	}

	public function bind_context( array $context, string $sha256 ): void {
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
	}

	public function require_enabled( array $runtime, string $sha256 ): void {
		$state = $this->state();
		$this->require( $sha256 === ( $state['context_sha256'] ?? null ) && isset( $state['enabled_attestation'] )
			&& $runtime === $state['enabled_attestation']['runtime'], 'No exact owned CLI/HTTP enabled attestation before credentials.' );
		Wstm108_Files::assert_file( $state['probe_path'], $state['probe'] );
		Wstm108_Files::assert_file( $state['loader_path'], $state['loader'] );
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
		$this->require( array( '.', '..', 'resources.json', 'state.json' ) === scandir( $this->lock ), 'Unknown private lock entry; do not retire owned resource evidence.' );
	}

	public function finalize( Wstm108_Proof $certificate ): void {
		$state = $this->final_state( $certificate );
		$this->require( array( '.', '..', 'state.json' ) === scandir( $this->lock ), 'Private journal or unknown lock entries remain; retaining evidence.' );
		Wstm108_Files::remove( $state['probe_path'], $state['probe'] );
		$private = Wstm108_Files::file( $this->lock . '/state.json' );
		Wstm108_Files::remove( $this->lock . '/state.json', $private );
		if ( ! @rmdir( $this->lock ) ) {
			$this->require( Wstm108_Files::directory( $this->lock ) === $state['lock_identity'], 'Stage lock changed during finalization; refusing further mutation.' );
			Wstm108_Files::create( $this->lock . '/state.json', $private['bytes'] );
			throw new RuntimeException( 'WSTM108 Cannot retire owned stage lock; private state retained.' );
		}
		clearstatcache();
		$this->require( false === @lstat( $this->lock ) && false === @lstat( $state['probe_path'] ), 'Owned stage paths reappeared; refusing further deletion.' );
	}
}
