<?php

defined( 'ABSPATH' ) || exit;

/**
 * Validate raw callback input without coercion, queries, or capability hooks.
 */
final class Webmastery_MCP_Input {
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
		if ( ! self::matches( $value, $schema ) ) {
			return Webmastery_MCP_Response::local_error( 'ability_invalid_input', 'Ability input does not match its schema.' );
		}
		return null;
	}

	private static function matches( $value, array $schema ): bool {
		if ( isset( $schema['type'] ) ) {
			$valid_type = false;
			foreach ( (array) $schema['type'] as $type ) {
				if ( self::is_type( $value, $type ) ) {
					$valid_type = true;
					break;
				}
			}
			if ( ! $valid_type ) {
				return false;
			}
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return false;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] )
				|| ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) {
				return false;
			}
		}
		if ( 'array' === ( $schema['type'] ?? null ) ) {
			if ( ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] )
				|| ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] ) ) {
				return false;
			}
			foreach ( $value as $item ) {
				if ( ! self::matches( $item, $schema['items'] ?? [] ) ) {
					return false;
				}
			}
		}
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$object = (array) $value;
			foreach ( $schema['required'] ?? [] as $key ) {
				if ( ! array_key_exists( $key, $object ) ) {
					return false;
				}
			}
			foreach ( $object as $key => $item ) {
				if ( array_key_exists( $key, $schema['properties'] ?? [] ) ) {
					if ( ! self::matches( $item, $schema['properties'][ $key ] ) ) {
						return false;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return false;
				} elseif ( is_array( $schema['additionalProperties'] ?? null )
					&& ! self::matches( $item, $schema['additionalProperties'] ) ) {
					return false;
				}
			}
		}
		return true;
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
