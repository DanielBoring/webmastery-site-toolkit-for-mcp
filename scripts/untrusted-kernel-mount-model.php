<?php

declare(strict_types=1);

/** Pure parsers and arithmetic. None of these results is a native proof. */
final class Wstm108_KernelMountModel {
	private static function require( bool $condition, string $reason = 'unreadable-kernel-evidence' ): void {
		if ( ! $condition ) { throw new Wstm108_TopologyRefusal( $reason ); }
	}

	public static function integer( string $value, bool $positive = false ): int {
		self::require( 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value )
			&& ( strlen( $value ) < strlen( (string) PHP_INT_MAX )
				|| ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) );
		$result = (int) $value;
		self::require( ! $positive || $result > 0 );
		return $result;
	}

	public static function status( string $bytes ): array {
		self::require( '' !== $bytes && strlen( $bytes ) <= 65536 && "\n" === substr( $bytes, -1 ) );
		$fields = array();
		foreach ( explode( "\n", substr( $bytes, 0, -1 ) ) as $line ) {
			self::require( 1 === preg_match( '/^([A-Za-z][A-Za-z0-9_]*):[ \t]*(.*)$/D', $line, $match )
				&& ! array_key_exists( $match[1], $fields ) );
			$fields[ $match[1] ] = $match[2];
		}
		$result = array();
		foreach ( array( 'Pid', 'Tgid', 'Threads', 'NoNewPrivs' ) as $key ) {
			self::require( isset( $fields[ $key ] ) );
			$result[ $key ] = self::integer( $fields[ $key ] );
		}
		foreach ( array( 'Uid', 'Gid' ) as $key ) {
			self::require( isset( $fields[ $key ] ) && 1 === preg_match( '/^[0-9]+(?:[ \t]+[0-9]+){3}$/D', $fields[ $key ] ) );
			$result[ $key ] = array_map( array( self::class, 'integer' ), preg_split( '/[ \t]+/', $fields[ $key ] ) );
		}
		self::require( isset( $fields['Groups'] ) && 1 === preg_match( '/^(?:[0-9]+(?:[ \t]+[0-9]+)*[ \t]*)?$/D', $fields['Groups'] ) );
		$groups = '' === trim( $fields['Groups'] ) ? array() : array_map( array( self::class, 'integer' ), preg_split( '/[ \t]+/', trim( $fields['Groups'] ) ) );
		self::require( count( $groups ) <= 65536 && count( $groups ) === count( array_unique( $groups ) ) );
		sort( $groups, SORT_NUMERIC );
		$result['Groups'] = $groups;
		foreach ( array( 'CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd' ) as $key ) {
			self::require( isset( $fields[ $key ] ) && 1 === preg_match( '/^[a-fA-F0-9]{16}$/D', $fields[ $key ] ) );
			$result[ $key ] = strtolower( $fields[ $key ] );
		}
		self::require( $result['Pid'] > 0 && $result['Pid'] === $result['Tgid'] && 1 === $result['Threads']
			&& in_array( $result['NoNewPrivs'], array( 0, 1 ), true ) );
		return $result;
	}

	public static function safe_exec( array $status, int $uid, int $gid, array $groups ): void {
		self::require( array_keys( $status ) === array( 'Pid', 'Tgid', 'Threads', 'NoNewPrivs', 'Uid', 'Gid', 'Groups',
			'CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd' ), 'native-coordinate-prerequisite' );
		self::require( $status['Pid'] > 0 && $status['Pid'] === $status['Tgid'] && 1 === $status['Threads']
			&& in_array( $status['NoNewPrivs'], array( 0, 1 ), true ), 'native-coordinate-prerequisite' );
		sort( $groups, SORT_NUMERIC );
		self::require( $uid > 0 && array_fill( 0, 4, $uid ) === $status['Uid']
			&& array_fill( 0, 4, $gid ) === $status['Gid'] && $groups === $status['Groups'], 'native-coordinate-prerequisite' );
		foreach ( array( 'CapEff', 'CapPrm', 'CapInh', 'CapAmb' ) as $key ) {
			self::require( '0000000000000000' === $status[ $key ], 'native-coordinate-prerequisite' );
		}
		self::require( 1 === $status['NoNewPrivs'] || '0000000000000000' === $status['CapBnd'], 'native-coordinate-prerequisite' );
	}

	public static function fdinfo( string $bytes ): array {
		self::require( strlen( $bytes ) <= 65536
			&& 1 === preg_match( '/^pos:\t([0-9]+)\nflags:\t([0-7]{1,12})\nmnt_id:\t([1-9][0-9]*)\nino:\t([1-9][0-9]*)\n$/D', $bytes, $match ) );
		$flags = octdec( $match[2] );
		self::require( is_int( $flags ) && 0 === self::integer( $match[1] )
			&& ( $flags & 0x2a0000 ) === 0x2a0000 && 0 === ( $flags & ~0x2a8000 ) );
		return array( 'mount_id' => self::integer( $match[3], true ), 'ino' => self::integer( $match[4], true ) );
	}

	public static function spend( array $remaining, int $elapsed, int $launches, int $bytes ): array {
		self::require( array_keys( $remaining ) === array( 'ns', 'launches', 'bytes' )
			&& count( array_filter( $remaining, 'is_int' ) ) === 3
			&& $elapsed >= 0 && $launches >= 0 && $bytes >= 0
			&& $remaining['ns'] <= 8000000000 && $remaining['launches'] <= 8 && $remaining['bytes'] <= 16777216
			&& $remaining['ns'] >= $elapsed && $remaining['launches'] >= $launches && $remaining['bytes'] >= $bytes );
		return array( 'ns' => $remaining['ns'] - $elapsed, 'launches' => $remaining['launches'] - $launches, 'bytes' => $remaining['bytes'] - $bytes );
	}

	public static function deadline( int $outer, int $now, array $remaining ): int {
		$remaining = self::spend( $remaining, 0, 0, 0 );
		self::require( $now >= 0 && $outer > $now && $remaining['ns'] > 1000000000
			&& $remaining['launches'] > 0 && $now <= PHP_INT_MAX - $remaining['ns'], 'native-coordinate-prerequisite' );
		$deadline = min( $outer, $now + $remaining['ns'] );
		self::require( $deadline - $now > 1000000000, 'native-coordinate-prerequisite' );
		return $deadline;
	}

	public static function selected_row( string $path, array $mounts, int $mount_id, string $device ): array {
		$path = self::path( $path );
		$selected = null;
		foreach ( $mounts as $mount ) {
			if ( $mount_id === $mount['id'] ) {
				self::require( null === $selected, 'ambiguous-stacked-mount' );
				$selected = $mount;
			}
		}
		self::require( null !== $selected && self::contains( $selected['point'], $path )
			&& $device === $selected['device'], 'kernel-coordinate-or-identity-disagrees' );
		return $selected;
	}

	public static function path( string $path ): string {
		self::require( strlen( $path ) <= 4096, 'noncanonical-path' );
		self::require( '' !== $path && '/' === $path[0]
			&& ! preg_match( '/[\x00-\x1f\x7f]|\/\/|(?:^|\/)\.\.?(?:\/|$)/', $path ), 'noncanonical-path' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	public static function contains( string $parent, string $child ): bool {
		return '/' === $parent || $parent === $child || 0 === strpos( $child, $parent . '/' );
	}

	public static function exposes( array $owned, array $exposure, bool $directory ): bool {
		return $owned['device'] === $exposure['device']
			&& ( self::contains( $owned['path'], $exposure['path'] )
				|| ( $directory && self::contains( $exposure['path'], $owned['path'] ) ) );
	}

	public static function coordinate( string $path, array $mounts, int $mount_id, string $device ): array {
		$path = self::path( $path );
		$selected = self::selected_row( $path, $mounts, $mount_id, $device );
		self::require( in_array( $selected['type'], array( 'ext4', 'tmpfs' ), true ), 'unsupported-filesystem-coordinate' );
		$relative = substr( $path, '/' === $selected['point'] ? 1 : strlen( $selected['point'] ) );
		return array( 'device' => $device, 'path' => self::path( '/' . trim( trim( $selected['root'], '/' ) . '/' . ltrim( $relative, '/' ), '/' ) ),
			'mount_id' => $mount_id, 'type' => $selected['type'] );
	}
}
