<?php

declare(strict_types=1);

require_once __DIR__ . '/destructive-safety-boot.php';

/**
 * Filesystem-only lifecycle; never bootstraps WordPress or touches plugin bytes.
 */
final class Wstm116_Lifecycle {
	private string $root;
	private string $plugin;
	private string $lock;
	private string $token;

	public function __construct( string $root, string $plugin, string $lock, string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			throw new RuntimeException( 'Invalid stage ownership token.' );
		}
		$this->root = $root;
		$this->plugin = $plugin;
		$this->lock = $lock;
		$this->token = $token;
	}

	private function require( bool $ok, string $message ): void {
		if ( ! $ok ) {
			throw new RuntimeException( $message );
		}
	}

	private function read( string $path ): string {
		$this->require( is_file( $path ) && ! is_link( $path ), 'Missing or symlinked lifecycle file: ' . $path );
		$data = file_get_contents( $path );
		$this->require( false !== $data, 'Cannot read lifecycle file.' );
		return $data;
	}

	private function create( string $path, string $data ): void {
		$handle = fopen( $path, 'x' );
		$this->require( false !== $handle, 'Lifecycle file collision: ' . $path );
		try {
			$this->require( strlen( $data ) === fwrite( $handle, $data ) && fflush( $handle ), 'Cannot persist lifecycle file.' );
		} finally {
			fclose( $handle );
		}
	}

	private function state(): array {
		$this->directory( $this->root );
		$this->directory( $this->root . '/wp-content/mu-plugins' );
		$this->directory( $this->plugin );
		$this->directory( $this->lock );
		$state = json_decode( $this->read( $this->lock . '/state.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->require( $this->token === $state['owner'] && $this->root === $state['root'] && $this->plugin === $state['plugin'], 'Stage ownership mismatch.' );
		return $state;
	}

	private function directory( string $path ): void {
		$resolved = realpath( $path );
		$this->require( false !== $resolved && is_dir( $path ) && ! is_link( $path )
			&& str_replace( '\\', '/', $resolved ) === str_replace( '\\', '/', $path ), 'Missing, symlinked or unresolved lifecycle directory.' );
	}

	private function config_metadata( array $state ): void {
		$path = $this->root . '/wp-config.php';
		clearstatcache( true, $path );
		$this->require( $state['mode'] === ( fileperms( $path ) & 0777 ), 'Configuration permissions changed; backup retained.' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->require( $state['uid'] === fileowner( $path ) && $state['gid'] === filegroup( $path ), 'Configuration ownership changed; backup retained.' );
		}
	}

	private function save_state( array $state ): void {
		$this->state();
		$temp = $this->lock . '/state-' . $this->token . '.json';
		$this->create( $temp, json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->require( chmod( $temp, 0600 ) && rename( $temp, $this->lock . '/state.json' ), 'Cannot persist private stage state.' );
	}

	private function config( string $original, int $days ): string {
		$this->require( false === strpos( $original, 'WSTM116_' ), 'Existing destructive runtime configuration collision.' );
		// Only conventional literal definitions can be replaced without evaluating site code.
		$pattern = '/\bdefine\s*\(\s*([\'"])EMPTY_TRASH_DAYS\1\s*,\s*[0-9]+\s*\)\s*;/';
		$body = preg_replace( $pattern, ';', $original, -1, $count );
		$this->require( is_string( $body ) && $count <= 1 && false === strpos( $body, 'EMPTY_TRASH_DAYS' ), 'Unsupported existing trash configuration; refusing to guess.' );
		return "<?php\n/* WSTM116 {$this->token} */\ndefine('WSTM116_DISPOSABLE_RUNTIME', true);\ndefine('WSTM116_STAGE_TOKEN', '{$this->token}');\ndefine('EMPTY_TRASH_DAYS', {$days});\n?>" . $body;
	}

	public function acquire(): void {
		$this->directory( $this->root );
		$this->directory( $this->root . '/wp-content/mu-plugins' );
		$this->directory( $this->plugin );
		$this->directory( dirname( $this->lock ) );
		$this->require( ! file_exists( $this->lock ) && ! is_link( $this->lock ), 'Stage lock collision.' );
		$config = $this->read( $this->root . '/wp-config.php' );
		$enabled = $this->config( $config, 30 );
		$disabled = $this->config( $config, 0 );
		$fixtures = array(
			$this->root . '/wp-content/mu-plugins/wstm116-http.php' => $this->read( $this->plugin . '/tests/e2e/destructive-safety-http-fixture.php' ),
			$this->root . '/wp-content/mu-plugins/wstm116-individual.php' => $this->read( $this->plugin . '/tests/e2e/error-contract-fixture.php' ),
		);
		foreach ( $fixtures as $path => &$contents ) {
			$source = $this->plugin . '/tests/e2e/' . ( false !== strpos( $path, 'individual' ) ? 'error-contract-fixture.php' : 'destructive-safety-http-fixture.php' );
			$contents = "<?php /* WSTM116 {$this->token} */\nif (!defined('WSTM116_DISPOSABLE_RUNTIME') || WSTM116_DISPOSABLE_RUNTIME !== true || !defined('WSTM116_STAGE_TOKEN') || WSTM116_STAGE_TOKEN !== '{$this->token}') { return; }\nrequire " . var_export( $source, true ) . ";\n";
		}
		unset( $contents );
		foreach ( $fixtures as $path => $contents ) {
			$this->require( ! file_exists( $path ) && ! is_link( $path ), 'MU fixture collision: ' . $path );
		}
		$this->require( array() === glob( $this->root . '/wp-content/mu-plugins/wstm116-probe-*.php' ), 'Read-only probe collision.' );
		$this->read( $this->plugin . '/tests/e2e/destructive-safety-probe.php' );
		$this->require( mkdir( $this->lock, 0700 ), 'Cannot exclusively acquire stage lock.' );
		// Backups stay outside the document root and outside published artifacts.
		$state = array(
			'owner' => $this->token, 'root' => $this->root, 'plugin' => $this->plugin,
			'original' => base64_encode( $config ), 'mode' => fileperms( $this->root . '/wp-config.php' ) & 0777,
			'uid' => fileowner( $this->root . '/wp-config.php' ), 'gid' => filegroup( $this->root . '/wp-config.php' ),
			'enabled' => $enabled, 'disabled' => $disabled, 'fixtures' => $fixtures,
			'probe_path' => $this->root . '/wp-content/mu-plugins/wstm116-probe-' . $this->token . '.php',
			'probe_contents' => "<?php /* WSTM116 {$this->token} */\ndefine('WSTM116_PROBE_OWNER', '{$this->token}');\nrequire " . var_export( $this->plugin . '/tests/e2e/destructive-safety-probe.php', true ) . ";\n",
		);
		$this->create( $this->lock . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->require( chmod( $this->lock . '/state.json', 0600 ), 'Cannot protect configuration backup.' );
	}

	public function prepare( array $original_runtime ): void {
		$state = $this->state();
		$this->require( ! isset( $state['original_runtime'] ), 'Original runtime already captured.' );
		$this->require( $this->read( $this->root . '/wp-config.php' ) === base64_decode( $state['original'], true ), 'Original configuration changed before capture.' );
		$this->require( array_keys( $original_runtime ) === array( 'trash_days', 'disposable_defined', 'disposable', 'stage_defined', 'stage_owner' )
			&& is_int( $original_runtime['trash_days'] ?? null ) && false === ( $original_runtime['disposable_defined'] ?? null )
			&& null === $original_runtime['disposable'] && false === ( $original_runtime['stage_defined'] ?? null )
			&& null === $original_runtime['stage_owner'], 'Unexpected original runtime configuration.' );
		$state['original_runtime'] = $original_runtime;
		$this->save_state( $state );
		$this->require( ! file_exists( $state['probe_path'] ) && ! is_link( $state['probe_path'] ), 'Read-only probe collision.' );
		$this->create( $state['probe_path'], $state['probe_contents'] );
		$this->require( chmod( $state['probe_path'], 0644 ), 'Cannot make read-only probe readable.' );
	}

	public function identity(): array {
		$state = $this->state();
		$identity = array( 'owner' => $this->token, 'root' => realpath( $this->root ), 'plugin_root' => realpath( $this->plugin ),
			'config_sha256' => hash( 'sha256', $this->read( $this->root . '/wp-config.php' ) ) );
		if ( isset( $state['http_uid'] ) ) {
			$identity['uid'] = $state['http_uid'];
		}
		return $identity;
	}

	public function attest( string $mode, array $cli_runtime, callable $verify ): array {
		$state = $this->state();
		$this->require( in_array( $mode, array( 'original', 'enabled', 'disabled' ), true ) && isset( $state['original_runtime'] ), 'Missing original runtime capture.' );
		$expected = 'original' === $mode ? $state['original_runtime'] : wstm116_stage_configuration( $this->token, 'enabled' === $mode ? 30 : 0 );
		$config = 'original' === $mode ? base64_decode( $state['original'], true ) : $state[ $mode ];
		$this->require( $config === $this->read( $this->root . '/wp-config.php' ) && $expected === $cli_runtime, 'CLI configuration does not match the owned transition.' );
		$this->require( $state['probe_contents'] === $this->read( $state['probe_path'] ), 'Read-only probe changed outside ownership.' );
		$stale = array( $state['original_runtime'], wstm116_stage_configuration( $this->token, 30 ), wstm116_stage_configuration( $this->token, 0 ) );
		$boot = $verify( $this->identity(), $expected, $stale );
		wstm116_validate_boot( $boot, $this->identity() );
		$this->require( $boot['runtime'] === $expected, 'HTTP convergence was not verified.' );
		$state['http_uid'] = $boot['identity']['uid'];
		$this->save_state( $state );
		return $boot;
	}

	public function configure( string $mode ): void {
		$this->require( in_array( $mode, array( 'enabled', 'disabled' ), true ), 'Invalid trash mode.' );
		$state = $this->state();
		$this->require( isset( $state['original_runtime'], $state['http_uid'] ), 'Original HTTP runtime must be attested before configuration.' );
		$this->config_metadata( $state );
		$current = $this->read( $this->root . '/wp-config.php' );
		$this->require( in_array( $current, array( base64_decode( $state['original'], true ), $state['enabled'], $state['disabled'] ), true ), 'Configuration changed outside stage ownership.' );
		foreach ( $state['fixtures'] as $path => $contents ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				$this->require( $contents === $this->read( $path ), 'MU fixture changed outside stage ownership.' );
			} else {
				$this->create( $path, $contents );
				$this->require( chmod( $path, 0644 ), 'Cannot make owned MU fixture readable.' );
			}
		}
		$this->replace_config( $state[ $mode ], $state );
	}

	private function replace_config( string $contents, array $state ): void {
		token_get_all( $contents, TOKEN_PARSE );
		$temp = $this->root . '/wp-config-wstm116-' . $this->token . '.php';
		$this->create( $temp, $contents );
		try {
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				$this->require( chown( $temp, $state['uid'] ) && chgrp( $temp, $state['gid'] ), 'Cannot preserve config ownership.' );
			}
			$this->require( chmod( $temp, $state['mode'] ), 'Cannot preserve config permissions.' );
			$this->require( rename( $temp, $this->root . '/wp-config.php' ), 'Cannot atomically replace configuration.' );
		} finally {
			if ( file_exists( $temp ) ) {
				$this->require( $contents === $this->read( $temp ) && unlink( $temp ), 'Cannot remove owned configuration temporary file.' );
			}
		}
	}

	public function restore(): void {
		$state = $this->state();
		$errors = array();
		try {
			$this->config_metadata( $state );
			$current = $this->read( $this->root . '/wp-config.php' );
			$original = base64_decode( $state['original'], true );
			$this->require( in_array( $current, array( $original, $state['enabled'], $state['disabled'] ), true ), 'Refusing to overwrite externally changed configuration; backup retained.' );
			$this->replace_config( $original, $state );
			$this->require( $original === $this->read( $this->root . '/wp-config.php' ), 'Configuration restoration differs from original bytes.' );
			$this->config_metadata( $state );
		} catch ( Throwable $error ) {
			$errors[] = $error;
		}
		// Remove every still-owned mutation loader even when configuration restoration failed.
		foreach ( $state['fixtures'] as $path => $contents ) {
			try {
				if ( file_exists( $path ) || is_link( $path ) ) {
					$this->require( $contents === $this->read( $path ), 'Refusing to delete externally changed fixture; backup retained.' );
					$this->require( unlink( $path ), 'Cannot remove owned MU fixture.' );
				}
				$this->require( ! file_exists( $path ) && ! is_link( $path ), 'Owned MU fixture remains.' );
			} catch ( Throwable $error ) {
				$errors[] = $error;
			}
		}
		if ( $errors ) {
			throw new RuntimeException( implode( ' ', array_map( static fn( Throwable $error ): string => $error->getMessage(), $errors ) ), 0, $errors[0] );
		}
	}

	public function has_original_runtime(): bool {
		return isset( $this->state()['original_runtime'] );
	}

	public function finalize_restore( ?array $boot ): void {
		$state = $this->state();
		$this->config_metadata( $state );
		$this->require( array( '.', '..', 'state.json' ) === scandir( $this->lock ), 'Unexpected private lock entry; backup and probe retained.' );
		$this->require( base64_decode( $state['original'], true ) === $this->read( $this->root . '/wp-config.php' ), 'Original config bytes not restored; backup retained.' );
		foreach ( $state['fixtures'] as $path => $contents ) {
			$this->require( ! file_exists( $path ) && ! is_link( $path ), 'Mutation fixture remains; backup retained.' );
		}
		if ( isset( $state['original_runtime'] ) ) {
			$this->require( null !== $boot, 'Original HTTP runtime not verified; backup retained.' );
			wstm116_validate_boot( $boot, $this->identity() );
			$this->require( $boot['runtime'] === $state['original_runtime'], 'Original HTTP runtime differs; backup retained.' );
			$this->require( $state['probe_contents'] === $this->read( $state['probe_path'] ) && unlink( $state['probe_path'] ), 'Cannot remove owned read-only probe; backup retained.' );
		} else {
			$this->require( ! file_exists( $state['probe_path'] ) && ! is_link( $state['probe_path'] ), 'Unexpected probe; backup retained.' );
		}
		$this->require( unlink( $this->lock . '/state.json' ), 'Cannot retire private backup.' );
		if ( ! @rmdir( $this->lock ) ) {
			$this->directory( $this->lock );
			$this->create( $this->lock . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
			$this->require( chmod( $this->lock . '/state.json', 0600 ), 'Cannot protect retained backup after lock retirement failure.' );
			throw new RuntimeException( 'Cannot retire stage ownership lock; backup retained.' );
		}
	}
}
