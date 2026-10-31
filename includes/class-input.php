<?php

defined( 'ABSPATH' ) || exit;

/**
 * Validate raw callback input without coercion, queries, or capability hooks.
 */
final class Webmastery_MCP_Input {
	/**
	 * Schema-derived diagnostics tied to error identity, never error data.
	 *
	 * @var WeakMap<WP_Error, array{field: string, reason: string}>|null
	 */
	private static ?WeakMap $diagnostics = null;

	public static function per_page( array $input, int $default_per_page = 20, int $maximum = 100, ?int $minimum = 1 ): int {
		$value = (int) ( $input['per_page'] ?? $default_per_page );
		return min( null === $minimum ? $value : max( $minimum, $value ), $maximum );
	}

	public static function page( array $input ): int {
		return max( 1, (int) ( $input['page'] ?? 1 ) );
	}

	public static function pagination( array $input, int $default_per_page = 20, int $maximum = 100, ?int $minimum = 1 ): array {
		return [
			'per_page' => self::per_page( $input, $default_per_page, $maximum, $minimum ),
			'page'     => self::page( $input ),
		];
	}

	public static function register_args( array $args, string $name ): array {
		if ( ! Webmastery_MCP_Response::owns( $name ) ) {
			return $args;
		}
		if ( isset( $args['input_schema'] ) && ! is_array( $args['input_schema'] ) ) {
			return $args;
		}

		$no_input             = empty( $args['input_schema'] );
		$schema               = self::close_schema( $no_input ? [
			'type' => 'object',
			'properties' => [],
			'default' => [],
		] : $args['input_schema'] );
		$args['input_schema'] = $schema;

		foreach ( [ 'execute_callback', 'permission_callback' ] as $key ) {
			if ( ! is_callable( $args[ $key ] ?? null ) ) {
				continue;
			}
			$callback      = $args[ $key ];
			$is_permission = 'permission_callback' === $key;
			$args[ $key ]  = static function ( $input = [] ) use ( $callback, $schema, $name, $no_input, $is_permission ) {
				if ( $no_input && null === $input ) {
					$input = [];
				}
				if ( self::is_type( $input, 'object' )
					&& preg_match( '~^webmastery-site-toolkit-for-mcp/(?:create|update)-(?:post|page|cpt-.+)$~', $name ) ) {
					$metadata_error = Webmastery_MCP_Posts::reject_combined_metadata( (array) $input );
					if ( null !== $metadata_error ) {
						return $is_permission
							? Webmastery_MCP_Response::legacy_wp_error( $metadata_error['error']['reason'], $metadata_error['error']['message'], (array) $metadata_error['error']['details'] )
							: $metadata_error;
					}
				}
				$error = self::validate( $input, $schema );
				if ( null !== $error ) {
					if ( ! $is_permission && self::is_type( $input, 'object' ) ) {
						$interlock = self::interlock_error( (array) $input, $name );
						if ( null !== $interlock ) {
							return $interlock;
						}
					}
					return $is_permission ? Webmastery_MCP_Response::permission_error( $error ) : Webmastery_MCP_Response::from_wp_error( $error );
				}
				$input = self::normalize_objects( $input, $schema );
				return $no_input ? $callback() : $callback( $input );
			};
		}
		return $args;
	}

	private static function interlock_error( array $input, string $name ): ?array {
		// Preserve the existing direct-callback diagnostics, not a validation bypass.
		switch ( $name ) {
			case 'webmastery-site-toolkit-for-mcp/bulk-publish-posts':
			case 'webmastery-site-toolkit-for-mcp/bulk-trash-posts':
				return Webmastery_MCP_Posts::bulk_input_error( $input );
			case 'webmastery-site-toolkit-for-mcp/delete-media':
				return Webmastery_MCP_Media::delete_input_error( $input );
			case 'webmastery-site-toolkit-for-mcp/delete-category':
			case 'webmastery-site-toolkit-for-mcp/delete-tag':
				return Webmastery_MCP_Taxonomy::delete_input_error( $input );
		}
		return null;
	}

	public static function close_schema( array $schema ): array {
		// A declared property set is closed; unstructured values and explicit maps are not.
		if ( 'object' === ( $schema['type'] ?? null ) && array_key_exists( 'properties', $schema )
			&& ! array_key_exists( 'additionalProperties', $schema ) ) {
			$schema['additionalProperties'] = false;
		}
		foreach ( $schema['properties'] ?? [] as $key => $property ) {
			$schema['properties'][ $key ] = self::close_schema( $property );
		}
		foreach ( [ 'items', 'additionalProperties' ] as $key ) {
			if ( isset( $schema[ $key ] ) && is_array( $schema[ $key ] ) ) {
				$schema[ $key ] = self::close_schema( $schema[ $key ] );
			}
		}
		return $schema;
	}

	public static function validate( $value, array $schema ): ?WP_Error {
		$diagnostic = self::diagnose( $value, $schema );
		if ( null !== $diagnostic ) {
			$error = Webmastery_MCP_Response::local_error( 'ability_invalid_input', 'Ability input does not match its schema.' );
			self::remember( $error, $diagnostic );
			return $error;
		}
		return null;
	}

	public static function annotate_error( WP_Error $error, $value, array $schema ): void {
		// Keep the parent object, message and data intact. Never parse its diagnostics.
		if ( 'ability_invalid_input' === $error->get_error_code() ) {
			self::remember( $error, self::diagnose( $value, $schema ) );
		}
	}

	public static function error_details( WP_Error $error ): array {
		return 'ability_invalid_input' === $error->get_error_code() ? ( self::$diagnostics[ $error ] ?? [] ) : [];
	}

	/**
	 * Replace a diagnostic without retaining its error object.
	 *
	 * @param WP_Error                                  $error Error identity.
	 * @param array{field: string, reason: string}|null $diagnostic Safe schema diagnostic.
	 */
	private static function remember( WP_Error $error, ?array $diagnostic ): void {
		self::$diagnostics ??= new WeakMap();
		unset( self::$diagnostics[ $error ] );
		if ( null !== $diagnostic ) {
			self::$diagnostics[ $error ] = $diagnostic;
		}
	}

	private static function child_path( string $path, string $suffix ): string {
		// Bound output without changing how deeply validation checks the input.
		if ( '...' === substr( $path, -3 ) ) {
			return $path;
		}
		return strlen( $path . $suffix ) > 240 ? $path . '...' : $path . $suffix;
	}

	private static function property_path( string $path, $key ): string {
		// Only declared schema names reach this method; other keys use a wildcard.
		$suffix = is_string( $key ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $key ) ? '.' . $key : '.*';
		return self::child_path( $path, $suffix );
	}

	/**
	 * Find the first strict failure without reading provider diagnostics.
	 *
	 * @param mixed  $value Raw input.
	 * @param array  $schema Public schema.
	 * @param string $path Bounded schema path.
	 * @return array{field: string, reason: string}|null
	 */
	private static function diagnose( $value, array $schema, string $path = '$' ): ?array {
		if ( isset( $schema['type'] ) ) {
			$valid_type = false;
			foreach ( (array) $schema['type'] as $type ) {
				if ( self::is_type( $value, $type ) ) {
					$valid_type = true;
					break;
				}
			}
			if ( ! $valid_type ) {
				return [ 'field' => $path, 'reason' => 'type' ];
			}
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return [ 'field' => $path, 'reason' => 'enum' ];
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] )
				|| ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) {
				return [ 'field' => $path, 'reason' => 'bounds' ];
			}
		}
		if ( 'array' === ( $schema['type'] ?? null ) ) {
			if ( ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] )
				|| ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) ) {
				return [ 'field' => $path, 'reason' => 'bounds' ];
			}
			foreach ( $value as $item ) {
				$diagnostic = self::diagnose( $item, $schema['items'] ?? [], self::child_path( $path, '[]' ) );
				if ( null !== $diagnostic ) {
					return $diagnostic;
				}
			}
		}
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$object = (array) $value;
			foreach ( $schema['required'] ?? [] as $key ) {
				if ( ! array_key_exists( $key, $object ) ) {
					$field = array_key_exists( $key, $schema['properties'] ?? [] ) ? self::property_path( $path, $key ) : self::child_path( $path, '.*' );
					return [ 'field' => $field, 'reason' => 'required' ];
				}
			}
			foreach ( $object as $key => $item ) {
				if ( array_key_exists( $key, $schema['properties'] ?? [] ) ) {
					$diagnostic = self::diagnose( $item, $schema['properties'][ $key ], self::property_path( $path, $key ) );
					if ( null !== $diagnostic ) {
						return $diagnostic;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return [ 'field' => self::child_path( $path, '.*' ), 'reason' => 'unknown_property' ];
				} elseif ( is_array( $schema['additionalProperties'] ?? null ) ) {
					$diagnostic = self::diagnose( $item, $schema['additionalProperties'], self::child_path( $path, '.*' ) );
					if ( null !== $diagnostic ) {
						return $diagnostic;
					}
				}
			}
		}
		return null;
	}

	private static function is_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'null':
				return null === $value;
			case 'boolean':
				return is_bool( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
			case 'string':
				return is_string( $value );
			case 'array':
				return is_array( $value ) && ( [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
			case 'object':
				if ( $value instanceof stdClass ) {
					return true;
				}
				if ( ! is_array( $value ) ) {
					return false;
				}
				return [] === $value || ! self::is_type( $value, 'array' );
			default:
				return false;
		}
	}

	private static function normalize_objects( $value, array $schema ) {
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$value = (array) $value;
			foreach ( $value as $key => $item ) {
				$child = $schema['properties'][ $key ] ?? ( $schema['additionalProperties'] ?? [] );
				if ( is_array( $child ) ) {
					$value[ $key ] = self::normalize_objects( $item, $child );
				}
			}
		} elseif ( 'array' === ( $schema['type'] ?? null ) && isset( $schema['items'] ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize_objects( $item, $schema['items'] );
			}
		}
		return $value;
	}
}
