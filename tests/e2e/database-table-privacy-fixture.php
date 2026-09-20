<?php

/**
 * Owned real tables for the disposable database diagnostic privacy regression.
 */
final class Webmastery_MCP_Database_Table_Privacy_Fixture {
	public array $names = array();
	public array $cleanup = array();

	public function create(): void {
		global $wpdb;
		$token = strtolower( wp_generate_password( 8, false, false ) );
		foreach ( array( 'plugin_fingerprint', 'posts', 'users' ) as $suffix ) {
			$name = $wpdb->prefix . 'wstm111_' . $token . '_' . $suffix;
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
			$dropped = false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $name ) );
			$absent = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) && '' === $wpdb->last_error;
			$this->cleanup[] = array( 'table' => $name, 'dropped' => $dropped, 'absent' => $absent );
		}
	}
}
