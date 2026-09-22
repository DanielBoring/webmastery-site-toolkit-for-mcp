<?php

require_once __DIR__ . '/untrusted-content-files.php';

/**
 * Own both proof artifacts before WordPress or any fixture mutation can run.
 * Keep the exclusive-create handles: subsequent writes never reopen a path.
 */
final class Wstm108_Evidence {
	/** @var array<string, resource> */
	private array $handles = array();
	private array $secrets = array();

	public function __construct( string $summary_path ) {
		\Wstm108_Files::directory( dirname( $summary_path ) );
		$paths = array( 'summary' => $summary_path, 'journal' => $summary_path . '.http.jsonl' );
		foreach ( $paths as $path ) {
			clearstatcache( true, $path );
			if ( file_exists( $path ) || is_link( $path ) ) {
				throw new RuntimeException( 'WSTM108 Evidence path already exists; refusing to overwrite: ' . $path );
			}
		}
		try {
			foreach ( $paths as $kind => $path ) {
				$handle = @fopen( $path, 'x+b' );
				if ( false === $handle ) {
					throw new RuntimeException( 'WSTM108 Cannot exclusively reserve evidence path: ' . $path );
				}
				$this->handles[ $kind ] = $handle;
			}
		} catch ( Throwable $error ) {
			$this->close();
			// Never unlink by name: a concurrent process may have replaced the path.
			// Any partial reservation is retained, so retries also fail closed.
			throw $error;
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function close(): void {
		foreach ( $this->handles as $handle ) {
			fclose( $handle );
		}
		$this->handles = array();
	}

	public function secret( string $secret ): void {
		if ( '' !== $secret ) {
			$this->secrets[] = $secret;
			$this->secrets[] = trim( json_encode( $secret, JSON_THROW_ON_ERROR ), '"' );
			$this->secrets[] = rawurlencode( $secret );
		}
	}

	public function redact( string $text ): string {
		return str_replace( $this->secrets, '[REDACTED]', $text );
	}

	public function append( array $event ): void {
		if ( isset( $event['body'] ) && is_string( $event['body'] ) ) {
			// Preserve malformed UTF-8 bytes without encoding credentials into a bypass.
			$event['body_base64'] = base64_encode( $this->redact( $event['body'] ) );
		}
		$this->write( 'journal', $this->redact( json_encode( $event, JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR ) ) . "\n" );
	}

	public function save( array $summary ): void {
		$text = $this->redact( json_encode( $summary, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR ) ) . "\n";
		if ( ! rewind( $this->handles['summary'] ) || ! ftruncate( $this->handles['summary'], 0 ) ) {
			throw new RuntimeException( 'WSTM108 Cannot reset owned proof summary.' );
		}
		$this->write( 'summary', $text );
	}

	private function write( string $kind, string $text ): void {
		$offset = 0;
		while ( $offset < strlen( $text ) ) {
			$written = fwrite( $this->handles[ $kind ], substr( $text, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'WSTM108 Cannot persist owned ' . $kind . ' evidence.' );
			}
			$offset += $written;
		}
		if ( ! fflush( $this->handles[ $kind ] ) ) {
			throw new RuntimeException( 'WSTM108 Cannot flush owned ' . $kind . ' evidence.' );
		}
	}
}
