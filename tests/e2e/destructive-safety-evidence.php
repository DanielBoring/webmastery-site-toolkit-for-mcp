<?php

declare(strict_types=1);

final class Wstm116_Evidence {
	private $summary;
	private $journal;

	public function __construct( string $path ) {
		$this->summary = @fopen( $path, 'x' );
		if ( false === $this->summary ) {
			throw new RuntimeException( 'Refusing artifact collision.' );
		}
		$this->journal = @fopen( $path . '.jsonl', 'x' );
		if ( false === $this->journal ) {
			fclose( $this->summary );
			throw new RuntimeException( 'Refusing wire journal collision; reserved summary retained.' );
		}
		$this->save( array( 'completed' => false, 'phase' => 'reserved before WordPress bootstrap' ) );
	}

	public function append( array $entry ): void {
		$this->write( $this->journal, json_encode( $entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	public function save( array $summary ): void {
		$data = json_encode( $summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! rewind( $this->summary ) || ! ftruncate( $this->summary, 0 ) ) {
			throw new RuntimeException( 'Cannot update exclusively reserved evidence.' );
		}
		$this->write( $this->summary, $data );
	}

	private function write( $handle, string $data ): void {
		if ( strlen( $data ) !== fwrite( $handle, $data ) || ! fflush( $handle ) ) {
			throw new RuntimeException( 'Failed to persist destructive runtime evidence.' );
		}
	}
}
