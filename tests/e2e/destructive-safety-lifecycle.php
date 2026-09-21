<?php

declare(strict_types=1);

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
		$state = json_decode( $this->read( $this->lock . '/state.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->require( $this->token === $state['owner'] && $this->root === $state['root'] && $this->plugin === $state['plugin'], 'Stage ownership mismatch.' );
		return $state;
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
		$this->require( ! file_exists( $this->lock ) && ! is_link( $this->lock ), 'Stage lock collision.' );
		$config = $this->read( $this->root . '/wp-config.php' );
		$enabled = $this->config( $config, 30 );
		$disabled = $this->config( $config, 0 );
		$fixtures = array(
			$this->root . '/wp-content/mu-plugins/wstm116-http.php' => $this->read( $this->plugin . '/tests/e2e/destructive-safety-http-fixture.php' ),
			$this->root . '/wp-content/mu-plugins/wstm116-individual.php' => $this->read( $this->plugin . '/tests/e2e/error-contract-fixture.php' ),
		);
		foreach ( $fixtures as &$contents ) {
			$contents = "<?php /* WSTM116 {$this->token} */ ?>" . $contents;
		}
		unset( $contents );
		foreach ( $fixtures as $path => $contents ) {
			$this->require( ! file_exists( $path ) && ! is_link( $path ), 'MU fixture collision: ' . $path );
		}
		$this->require( mkdir( $this->lock, 0700 ), 'Cannot exclusively acquire stage lock.' );
		// Backups stay outside the document root and outside published artifacts.
		$state = array(
			'owner' => $this->token, 'root' => $this->root, 'plugin' => $this->plugin,
			'original' => base64_encode( $config ), 'mode' => fileperms( $this->root . '/wp-config.php' ) & 0777,
			'uid' => fileowner( $this->root . '/wp-config.php' ), 'gid' => filegroup( $this->root . '/wp-config.php' ),
			'enabled' => $enabled, 'disabled' => $disabled, 'fixtures' => $fixtures,
		);
		$this->create( $this->lock . '/state.json', json_encode( $state, JSON_THROW_ON_ERROR ) );
		$this->require( chmod( $this->lock . '/state.json', 0600 ), 'Cannot protect configuration backup.' );
	}

	public function configure( string $mode ): void {
		$this->require( in_array( $mode, array( 'enabled', 'disabled' ), true ), 'Invalid trash mode.' );
		$state = $this->state();
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
		$current = $this->read( $this->root . '/wp-config.php' );
		$original = base64_decode( $state['original'], true );
		$this->require( in_array( $current, array( $original, $state['enabled'], $state['disabled'] ), true ), 'Refusing to overwrite externally changed configuration; backup retained.' );
		foreach ( $state['fixtures'] as $path => $contents ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				$this->require( $contents === $this->read( $path ), 'Refusing to delete externally changed fixture; backup retained.' );
			}
		}
		$this->replace_config( $original, $state );
		foreach ( $state['fixtures'] as $path => $contents ) {
			if ( file_exists( $path ) ) {
				$this->require( unlink( $path ), 'Cannot remove owned MU fixture.' );
			}
			$this->require( ! file_exists( $path ), 'Owned MU fixture remains.' );
		}
		$this->require( $original === $this->read( $this->root . '/wp-config.php' ), 'Configuration restoration differs from original bytes.' );
		clearstatcache( true, $this->root . '/wp-config.php' );
		$this->require( $state['mode'] === ( fileperms( $this->root . '/wp-config.php' ) & 0777 ), 'Configuration permissions were not restored.' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->require( $state['uid'] === fileowner( $this->root . '/wp-config.php' ) && $state['gid'] === filegroup( $this->root . '/wp-config.php' ), 'Configuration ownership was not restored.' );
		}
		$this->require( unlink( $this->lock . '/state.json' ) && rmdir( $this->lock ), 'Cannot retire stage ownership lock.' );
	}
}
