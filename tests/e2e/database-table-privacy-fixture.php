<?php

/**
 * Owned real tables for the disposable database diagnostic privacy regression.
 */
final class Webmastery_MCP_Database_Table_Privacy_Fixture {
	public array $names = array();
	public array $cleanup = array();

	public static function expiration_window( callable $next_deadline, callable $clock, callable $wait, int $window = 15, int $budget = 90 ): array {
		if ( $window < 1 || $budget < 0 ) {
			throw new RuntimeException( 'Invalid expiration admission bounds.' );
		}
		$started = $clock();
		if ( ! is_int( $started ) || $started < 0 ) {
			throw new RuntimeException( 'Invalid expiration admission clock.' );
		}
		$now = $started;
		$waited = 0;
		for ( $attempt = 0; $attempt <= $budget; ++$attempt ) {
			if ( ! is_int( $now ) || $now < 0 || $now < $started || $now - $started > $budget ) {
				throw new RuntimeException( 'Expiration admission clock or wait budget failed.' );
			}
			if ( $now >= PHP_INT_MAX - $window ) {
				throw new RuntimeException( 'Expiration admission horizon exceeds the clock range.' );
			}
			$end = $now + $window;
			$deadline = $next_deadline( $now, $end );
			if ( null === $deadline ) {
				return array( 'started' => $now, 'ends' => $end, 'waited_seconds' => $waited );
			}
			if ( ! is_string( $deadline ) || ! ctype_digit( $deadline ) || strlen( $deadline ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $deadline ) === strlen( (string) PHP_INT_MAX ) && strcmp( $deadline, (string) PHP_INT_MAX ) > 0 ) ) {
				throw new RuntimeException( 'Invalid transient expiration deadline.' );
			}
			$deadline = (int) $deadline;
			if ( $deadline < $now || $deadline > $end ) {
				throw new RuntimeException( 'Transient expiration deadline is outside the admission window.' );
			}
			$delay = $deadline - $now + 1;
			if ( $now - $started + $delay > $budget ) {
				throw new RuntimeException( 'Expiration admission wait budget exhausted.' );
			}
			$wait( $delay );
			$waited += $delay;
			$after = $clock();
			if ( ! is_int( $after ) || $after <= $now ) {
				throw new RuntimeException( 'Expiration admission clock did not advance after waiting.' );
			}
			$now = $after;
		}
		throw new RuntimeException( 'Expiration admission attempt budget exhausted.' );
	}

	public static function in_expiration_window( array $window, int $observed ): bool {
		return $observed >= $window['started'] && $observed <= $window['ends'];
	}

	public static function private_tokens( string $name, string $prefix ): array {
		$tokens = array( $name );
		$suffix = substr( $name, strlen( $prefix ) );
		if ( str_starts_with( $suffix, 'wstm111_' ) ) {
			$tokens[] = $suffix;
		}
		return $tokens;
	}

	public function create(): void {
		global $wpdb;
		$token = strtolower( wp_generate_password( 8, false, false ) );
		$names = array(
			$wpdb->prefix . 'wstm111_' . $token . '_plugin_fingerprint',
			$wpdb->prefix . 'wstm111_' . $token . '_posts',
			$wpdb->prefix . 'wstm111_' . $token . '_users',
			$wpdb->prefix . wp_rand( 9000000, 9999999 ) . '_posts',
		);
		if ( $wpdb->users !== $wpdb->prefix . 'users' ) {
			$names[] = $wpdb->prefix . 'users';
		}
		foreach ( $names as $name ) {
			if ( strlen( $name ) > 64 ) {
				throw new RuntimeException( 'Fixture table identifier exceeds the database limit.' );
			}
			$existing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( '' !== $wpdb->last_error || null !== $existing ) {
				throw new RuntimeException( 'Could not establish exclusive ownership of fixture table.' );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id int PRIMARY KEY, fixture_value varchar(32))', $name ) ) ) {
				throw new RuntimeException( 'Could not create owned diagnostic fixture table.' );
			}
			$this->names[] = $name;
			if ( false === $wpdb->insert( $name, array( 'id' => 1, 'fixture_value' => 'diagnostic-privacy-fixture' ) ) ) {
				throw new RuntimeException( 'Could not seed owned diagnostic fixture table.' );
			}
		}
	}

	public function restore(): void {
		global $wpdb;
		foreach ( $this->names as $name ) {
			$dropped = false;
			$absent = false;
			$errors = array();
			try {
				$dropped = false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $name ) );
			} catch ( Throwable $error ) {
				$errors[] = 'DROP failed: ' . $error->getMessage();
			}
			try {
				$absent = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) && '' === $wpdb->last_error;
			} catch ( Throwable $error ) {
				$errors[] = 'Absence check failed: ' . $error->getMessage();
			}
			$this->cleanup[] = array( 'table' => $name, 'dropped' => $dropped, 'absent' => $absent, 'errors' => $errors );
		}
	}
}
