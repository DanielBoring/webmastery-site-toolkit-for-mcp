<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_Untrusted {

	/**
	 * Mark present fields on a plugin-owned record, never inside stored maps.
	 *
	 * @param array<string, mixed> $record Authorized response record.
	 * @param array<int, string>   $fields Record-relative field names.
	 * @return array<string, mixed>
	 */
	public static function mark( array $record, array $fields ): array {
		$record['untrusted_fields'] = array_values(
			array_unique(
				array_filter( $fields, static fn( $field ) => array_key_exists( $field, $record ) )
			)
		);
		return $record;
	}
}
