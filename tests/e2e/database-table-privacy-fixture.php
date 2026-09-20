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
			$dropped = false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $name ) );
			$absent = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) && '' === $wpdb->last_error;
			$this->cleanup[] = array( 'table' => $name, 'dropped' => $dropped, 'absent' => $absent );
		}
	}
}
