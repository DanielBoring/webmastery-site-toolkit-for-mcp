<?php

declare(strict_types=1);

/** One consumed inherited restriction; it never imports mapping or admission proof. */
final class Wstm108_KernelAuthorityOwner {
	private array $custody;
	private int $deadline = 0;
	private int $slots = 0;
	private array $restriction;

	private function __construct( array $custody, array $restriction ) {
		$this->custody = $custody;
		$this->restriction = $restriction;
	}
	private function __clone() {}
	public function __serialize(): array { throw new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ); }
	public function __unserialize( array $data ): void { throw new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ); }

	private static function require( bool $condition ): void {
		if ( ! $condition ) { throw new Wstm108_TopologyRefusal( 'native-coordinate-prerequisite' ); }
	}

	public static function inherited( array $context, string $action ): self {
		$descriptor = @fopen( 'php://fd/5', 'rb' );
		self::require( is_resource( $descriptor ) );
		try {
			$stat = fstat( $descriptor );
			self::require( is_array( $stat ) && 0100600 === $stat['mode'] && 1 === $stat['nlink']
				&& posix_geteuid() === $stat['uid'] && $stat['size'] > 0 && $stat['size'] <= 4096 && 0 === ftell( $descriptor ) );
			$bytes = stream_get_contents( $descriptor, 4097 );
			self::require( is_string( $bytes ) && strlen( $bytes ) === $stat['size'] && feof( $descriptor ) );
			$record = json_decode( $bytes, true, 16, JSON_THROW_ON_ERROR );
			self::require( is_array( $record ) && array_keys( $record ) === array( 'version', 'action', 'parent_pid',
				'parent_start', 'parent_namespace', 'context_sha256', 'authority_sha256', 'custody', 'remaining' )
				&& 1 === $record['version'] && $action === $record['action'] && is_int( $record['parent_pid'] )
				&& $record['parent_pid'] === posix_getppid() && $record['parent_pid'] > 0
				&& is_string( $record['parent_start'] ) && ctype_digit( $record['parent_start'] )
				&& hash( 'sha256', json_encode( $context, JSON_THROW_ON_ERROR ) ) === $record['context_sha256']
				&& hash_file( 'sha256', __DIR__ . '/untrusted-authority.php' ) === $record['authority_sha256']
				&& is_array( $record['custody'] ) && array_keys( $record['custody'] ) === array( 'directory', 'identity' )
				&& is_string( $record['custody']['directory'] ) && is_array( $record['custody']['identity'] ) );
			$parent = @file_get_contents( '/proc/' . $record['parent_pid'] . '/stat', false, null, 0, 65537 );
			self::require( is_string( $parent ) && strlen( $parent ) <= 65536
				&& 1 === preg_match( '/^([0-9]+) \(.+\) (.+)\n?$/D', $parent, $match ) && (int) $match[1] === $record['parent_pid'] );
			$fields = explode( ' ', rtrim( $match[2], "\n" ) );
			self::require( isset( $fields[19] ) && $fields[19] === $record['parent_start'] );
			clearstatcache();
			$namespace = @stat( '/proc/' . $record['parent_pid'] . '/ns/mnt' );
			$self_namespace = @stat( '/proc/self/ns/mnt' );
			$keys = array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) );
			self::require( is_array( $namespace ) && is_array( $self_namespace )
				&& $record['parent_namespace'] === array_intersect_key( $namespace, $keys )
				&& $record['parent_namespace'] === array_intersect_key( $self_namespace, $keys ) );
			$status = @file_get_contents( '/proc/' . $record['parent_pid'] . '/status', false, null, 0, 65537 );
			self::require( is_string( $status ) );
			$status = Wstm108_KernelMountModel::status( $status );
			Wstm108_KernelMountModel::safe_exec( $status, posix_geteuid(), posix_getegid(), posix_getgroups() );
			self::require( $status['Pid'] === $record['parent_pid'] );
			$info = @file_get_contents( '/proc/self/fdinfo/5', false, null, 0, 65537 );
			self::require( is_string( $info ) && strlen( $info ) <= 65536
				&& 1 === preg_match( '/^flags:\t([0-7]{1,12})$/m', $info, $flags )
				&& 0 === ( octdec( $flags[1] ) & 3 ) );
			$link = @readlink( '/proc/self/fd/5' );
			self::require( is_string( $link ) && 1 === preg_match( '/\/allocation-[a-f0-9]{32}\.private\.json$/D', $link )
				&& dirname( $link ) === $record['custody']['directory'] && realpath( $link ) === $link );
			$file = Wstm108_Files::read_bound( $link, array_intersect_key( $stat, array_flip( array( 'dev', 'ino', 'uid', 'gid', 'mode', 'nlink' ) ) ) );
			self::require( $file['bytes'] === $bytes );
			$linked = false;
			$descriptors = @scandir( '/proc/' . $record['parent_pid'] . '/fd' );
			self::require( is_array( $descriptors ) && count( $descriptors ) <= 8192 );
			foreach ( $descriptors as $fd ) {
				if ( ! ctype_digit( $fd ) ) { continue; }
				if ( @readlink( '/proc/' . $record['parent_pid'] . '/fd/' . $fd ) !== $link ) { continue; }
				clearstatcache();
				$original = @stat( '/proc/' . $record['parent_pid'] . '/fd/' . $fd );
				if ( is_array( $original ) && $original['dev'] === $stat['dev'] && $original['ino'] === $stat['ino'] ) { $linked = true; }
			}
			self::require( $linked && is_array( $record['remaining'] ) );
			$remaining = Wstm108_KernelMountModel::spend( $record['remaining'], 0, 0, 0 );
			self::require( $remaining['ns'] > 1000000000 && $remaining['launches'] > 0 && $remaining['bytes'] > 0 );
			$owner = new self( $record['custody'], $remaining );
			$owner->kernel_custody();
			return $owner;
		} finally { fclose( $descriptor ); }
	}

	public function allowance(): array {
		return $this->restriction;
	}

	public function attach( int $deadline ): void {
		self::require( $deadline > 0 );
		$this->deadline = 0 === $this->deadline ? $deadline : min( $this->deadline, $deadline );
	}

	public function pause(): void {
		$this->deadline = 0;
		$this->slots = 0;
	}

	public function kernel_query_deadline(): int {
		self::require( $this->deadline > 0 );
		return $this->deadline;
	}

	public function kernel_query_slot(): void {
		self::require( ++$this->slots <= $this->restriction['launches'] && hrtime( true ) < $this->deadline );
	}

	public function kernel_custody(): array {
		self::require( $this->custody['identity'] === Wstm108_Files::directory( $this->custody['directory'] )
			&& 0040700 === $this->custody['identity']['mode'] && posix_geteuid() === $this->custody['identity']['uid'] );
		return $this->custody;
	}

	public function kernel_record( string $stem, array $custody, array $files, array $intent ): void {
		self::require( $custody === $this->kernel_custody() && 1 === preg_match( '/^kernel-[a-f0-9]{32}$/D', $stem ) );
		Wstm108_Files::assert_file( $custody['directory'] . '/' . $stem . '.intent.private.json', $intent );
		foreach ( $files as $suffix => $file ) { Wstm108_Files::assert_file( $custody['directory'] . '/' . $stem . '.' . $suffix . '.private', $file ); }
	}
}
