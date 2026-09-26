<?php
declare(strict_types=1);

final class InputSchemaJournalFake {
	private string $directory;
	private array $nodes = array();
	private int $inode = 1000000;
	private ?Closure $decision = null;
	public array $replacements = array();

	public function __construct( string $directory ) {
		$this->directory = $this->normalize( $directory );
	}

	private function normalize( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	private function owns( string $path ): bool {
		return in_array( $this->normalize( $path ), array( $this->directory . '/state.json', $this->directory . '/next.json' ), true );
	}

	private function physical_collision( string $path ): void {
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'Unexpected physical journal in memory-only fixture scope.' );
		}
	}

	public function decide_replacement( ?callable $decision ): void {
		$this->decision = null === $decision ? null : Closure::fromCallable( $decision );
	}

	public function node( string $path ): ?array {
		return $this->nodes[ $this->normalize( $path ) ] ?? null;
	}

	public function bytes( string $path ): string {
		$node = $this->node( $path );
		if ( null === $node || $node['link'] ) {
			throw new RuntimeException( 'Missing regular fake journal.' );
		}
		return $node['bytes'];
	}

	public function change_bytes( string $path, string $bytes ): void {
		$path = $this->normalize( $path );
		$this->bytes( $path );
		$this->nodes[ $path ]['bytes'] = $bytes;
		$this->nodes[ $path ]['stat']['size'] = strlen( $bytes );
	}

	public function change_metadata( string $path, array $changes ): void {
		$path = $this->normalize( $path );
		$this->bytes( $path );
		$this->nodes[ $path ]['stat'] = array_replace( $this->nodes[ $path ]['stat'], $changes );
	}

	public function dangling_link( string $path ): void {
		$path = $this->normalize( $path );
		$this->bytes( $path );
		$this->nodes[ $path ]['link'] = true;
		$this->nodes[ $path ]['bytes'] = 'missing-journal-target';
		$this->nodes[ $path ]['stat']['mode'] = 0120777;
		$this->nodes[ $path ]['stat']['size'] = strlen( 'missing-journal-target' );
	}

	public function __invoke( string $operation, string $path, ?int $mode, array $context ) {
		$path = $this->normalize( $path );
		if ( 'list' === $operation && $path === $this->directory ) {
			$entries = ( $context['native'] )();
			foreach ( $this->nodes as $name => $node ) {
				$this->physical_collision( $name );
				$entries[] = basename( $name );
			}
			sort( $entries, SORT_STRING );
			return $entries;
		}
		if ( ! $this->owns( $path ) ) {
			if ( 'replace_journal' === $operation && $this->owns( $context['destination'] ) ) {
				throw new RuntimeException( 'Replacement crosses the fake journal boundary.' );
			}
			return ( $context['native'] )();
		}
		$this->physical_collision( $path );
		$node = $this->nodes[ $path ] ?? null;
		$exists = null !== $node && ! $node['link'];
		$regular = $exists && 0100000 === ( $node['stat']['mode'] & 0170000 );
		switch ( $operation ) {
			case 'exists':
				return $exists;
			case 'is_file':
				return $regular;
			case 'is_link':
				return null !== $node && $node['link'];
			case 'stat':
				return $exists ? $node['stat'] : false;
			case 'lstat':
				return null !== $node ? $node['stat'] : false;
			case 'size':
				return $regular ? $node['stat']['size'] : false;
			case 'read':
				return $regular ? $node['bytes'] : false;
			case 'chmod':
				if ( ! $regular ) {
					return false;
				}
				$this->nodes[ $path ]['stat']['mode'] = 0100000 | $mode;
				return true;
			case 'remove':
				if ( null === $node ) {
					return false;
				}
				unset( $this->nodes[ $path ] );
				return true;
			case 'create':
				if ( null !== $node ) {
					return false;
				}
				$parent = stat( dirname( $path ) );
				if ( ! is_array( $parent ) || ! is_int( $mode ) || ! is_string( $context['bytes'] ?? null ) ) {
					throw new RuntimeException( 'Invalid fake journal creation.' );
				}
				$this->nodes[ $path ] = array(
					'bytes' => $context['bytes'], 'link' => false,
					'stat' => array( 'mode' => 0100000 | $mode, 'uid' => $parent['uid'], 'gid' => $parent['gid'],
						'dev' => $parent['dev'], 'ino' => ++$this->inode, 'nlink' => 1, 'size' => strlen( $context['bytes'] ) ),
				);
				return true;
			case 'replace_journal':
				return $this->replace( $path, $this->normalize( $context['destination'] ) );
		}
		throw new RuntimeException( 'Unsupported fake journal operation.' );
	}

	private function replace( string $source, string $destination ): bool {
		if ( $source !== $this->directory . '/next.json' || $destination !== $this->directory . '/state.json' ) {
			throw new RuntimeException( 'Invalid fake journal replacement paths.' );
		}
		$this->physical_collision( $destination );
		$before = array( 'source' => $this->node( $source ), 'destination' => $this->node( $destination ) );
		$index = count( $this->replacements );
		$this->replacements[] = array( 'before' => $before, 'outcome' => 'attempted' );
		try {
			$allowed = null === $this->decision ? true : ( $this->decision )( $before );
		} catch ( Throwable $error ) {
			$this->replacements[ $index ]['outcome'] = 'thrown';
			throw $error;
		}
		if ( ! is_bool( $allowed ) ) {
			throw new RuntimeException( 'Invalid fake journal replacement decision.' );
		}
		if ( ! $allowed || null === $before['source'] || $before['source']['link'] ) {
			$this->replacements[ $index ]['outcome'] = 'false';
			return false;
		}
		$this->nodes[ $destination ] = $before['source'];
		unset( $this->nodes[ $source ] );
		$this->replacements[ $index ]['outcome'] = 'success';
		return true;
	}
}
