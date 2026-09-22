<?php

declare(strict_types=1);

/**
 * Narrow lifecycle double, NOT an execution of WordPress core.
 *
 * Public method signatures, permission-error masking and 7.1 hook order follow
 * wordpress-develop/src/wp-includes/abilities-api/class-wp-ability.php.
 * Only the schema keywords below are modeled; unsupported keywords/types throw
 * rather than silently passing. REST coercion is modeled for booleans/integers,
 * not as a complete replacement for rest_validate_value_from_schema().
 * Hooks are local callbacks, not WordPress's hook implementation. The legacy
 * callback mode models 6.9's uncaught exception boundary only.
 */
class WP_Ability {
	public array $events = array();
	public array $hooks = array();
	public array $input_schema;
	public array $output_schema;
	public int $parent_validations = 0;
	public int $permission_calls = 0;
	public int $execute_calls = 0;
	public array $validated_inputs = array();
	public array $permission_inputs = array();
	public array $execute_inputs = array();
	public ?WP_Error $last_parent_error = null;
	public bool $legacy_callbacks = false;
	private string $name;
	private $permission_callback;
	private $execute_callback;

	public function __construct( string $name, array $args ) {
		$this->name                = $name;
		$this->input_schema        = $args['input_schema'] ?? array();
		$this->output_schema       = $args['output_schema'] ?? array();
		$this->permission_callback = $args['permission_callback'];
		$this->execute_callback    = $args['execute_callback'];
	}

	public function get_input_schema(): array {
		return $this->input_schema;
	}

	private function hook( string $name, $value, ...$args ) {
		$this->events[] = $name;
		return isset( $this->hooks[ $name ] ) ? ( $this->hooks[ $name ] )( $value, ...$args ) : $value;
	}

	public function normalize_input( $input = null ) {
		$this->events[] = 'normalize_input';
		if ( null === $input ) {
			$schema = $this->get_input_schema();
			if ( array_key_exists( 'default', $schema ) ) {
				$input = $schema['default'];
			}
		}
		return $this->hook( 'wp_ability_normalize_input', $input, $this->name, $this );
	}

	public function validate_input( $input = null ) {
		++$this->parent_validations;
		$this->events[]          = 'parent_validate_input';
		$this->validated_inputs[] = $input;
		$schema                 = $this->get_input_schema();
		if ( empty( $schema ) ) {
			return null === $input ? true : $this->parent_error( 'ability_missing_input_schema' );
		}
		$result = self::matches( $input, $schema ) ? true : $this->parent_error( 'ability_invalid_input' );
		$result = $this->hook( 'wp_ability_validate_input', $result, $input, $this->name );
		if ( false === $result ) {
			return $this->parent_error( 'ability_invalid_input' );
		}
		return is_wp_error( $result ) && '' !== $result->get_error_code() ? $result : true;
	}

	private function parent_error( string $code ): WP_Error {
		$this->last_parent_error = new WP_Error( $code, 'PRIVATE core diagnostic', array( 'private' => 'not public' ) );
		return $this->last_parent_error;
	}

	protected function invoke_callback( callable $callback, $input = null ) {
		$args = empty( $this->get_input_schema() ) ? array() : array( $input );
		if ( $this->legacy_callbacks ) {
			return $callback( ...$args );
		}
		try {
			return $callback( ...$args );
		} catch ( Throwable $error ) {
			return new WP_Error( 'ability_callback_exception', $error->getMessage() );
		}
	}

	public function check_permissions( $input = null ) {
		++$this->permission_calls;
		$this->events[]            = 'check_permissions';
		$this->permission_inputs[] = $input;
		if ( ! is_callable( $this->permission_callback ) ) {
			return new WP_Error( 'ability_invalid_permission_callback' );
		}
		$result = $this->invoke_callback( $this->permission_callback, $input );
		$result = $this->hook( 'wp_ability_permission_result', $result, $this->name, $input, $this );
		return is_bool( $result ) || is_wp_error( $result ) ? $result : false;
	}

	protected function do_execute( $input = null ) {
		++$this->execute_calls;
		$this->events[]         = 'do_execute';
		$this->execute_inputs[] = $input;
		$result = is_callable( $this->execute_callback )
			? $this->invoke_callback( $this->execute_callback, $input )
			: new WP_Error( 'ability_invalid_execute_callback' );
		return $this->hook( 'wp_ability_execute_result', $result, $this->name, $input, $this );
	}

	protected function validate_output( $output ) {
		$this->events[] = 'validate_output';
		$result = empty( $this->output_schema ) || self::matches( $output, $this->output_schema )
			? true : new WP_Error( 'ability_invalid_output', 'PRIVATE output diagnostic' );
		$result = $this->hook( 'wp_ability_validate_output', $result, $output, $this->name );
		if ( false === $result ) {
			return new WP_Error( 'ability_invalid_output' );
		}
		return is_wp_error( $result ) && '' !== $result->get_error_code() ? $result : true;
	}

	public function execute( $input = null ) {
		$this->hook( 'wp_ability_invoked', $this->name, $input, $this );
		$sentinel = new stdClass();
		$pre = $this->hook( 'wp_pre_execute_ability', $sentinel, $this->name, $input, $this );
		if ( $sentinel !== $pre ) {
			return $pre;
		}
		$input = $this->normalize_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$valid = $this->validate_input( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$permission = $this->check_permissions( $input );
		if ( true !== $permission ) {
			if ( is_wp_error( $permission ) ) {
				$this->events[] = 'permission_error_masked';
			}
			return new WP_Error( 'ability_invalid_permissions', 'Native permission denial' );
		}
		$this->hook( 'wp_before_execute_ability', $this->name, $input, $this );
		$result = $this->do_execute( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$valid = $this->validate_output( $result );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$this->hook( 'wp_after_execute_ability', $this->name, $input, $result, $this );
		return $result;
	}

	private static function matches( $value, array $schema ): bool {
		$known = array( 'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'default', 'minimum', 'maximum', 'format', 'oneOf', 'anyOf' );
		if ( array_diff( array_keys( $schema ), $known ) ) {
			throw new LogicException( 'Schema keyword outside the lifecycle double boundary.' );
		}
		foreach ( array( 'oneOf', 'anyOf' ) as $keyword ) {
			if ( isset( $schema[ $keyword ] ) ) {
				$matches = count( array_filter( $schema[ $keyword ], static fn( $branch ) => self::matches( $value, $branch ) ) );
				if ( ( 'oneOf' === $keyword && 1 !== $matches )
					|| ( 'anyOf' === $keyword && 0 === $matches ) ) {
					return false;
				}
			}
		}
		if ( isset( $schema['type'] ) ) {
			$valid = false;
			foreach ( (array) $schema['type'] as $type ) {
				switch ( $type ) {
					case 'boolean':
						$valid = $valid || is_bool( $value ) || in_array( $value, array( 'true', 'false', '1', '0', 1, 0 ), true );
						break;
					case 'integer':
						$valid = $valid || ( is_numeric( $value ) && (float) $value === (float) (int) $value );
						break;
					case 'number':
						$valid = $valid || is_numeric( $value );
						break;
					case 'string':
						$valid = $valid || is_string( $value );
						break;
					case 'object':
						$valid = $valid || is_array( $value ) || $value instanceof stdClass;
						break;
					case 'array':
						$valid = $valid || is_array( $value );
						break;
					case 'null':
						$valid = $valid || null === $value;
						break;
					default:
						throw new LogicException( 'Schema type outside the lifecycle double boundary: ' . $type );
				}
			}
			if ( ! $valid ) {
				return false;
			}
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return false;
		}
		if ( isset( $schema['format'] ) ) {
			if ( 'email' !== $schema['format'] ) {
				throw new LogicException( 'Schema format outside the lifecycle double boundary.' );
			}
			if ( ! is_string( $value ) || ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
				return false;
			}
		}
		if ( is_numeric( $value ) && ( ( isset( $schema['minimum'] ) && $value < $schema['minimum'] )
			|| ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) ) ) {
			return false;
		}
		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$object = (array) $value;
			foreach ( $schema['required'] ?? array() as $key ) {
				if ( ! array_key_exists( $key, $object ) ) {
					return false;
				}
			}
			foreach ( $object as $key => $item ) {
				if ( isset( $schema['properties'][ $key ] ) ) {
					if ( ! self::matches( $item, $schema['properties'][ $key ] ) ) {
						return false;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return false;
				} elseif ( is_array( $schema['additionalProperties'] ?? null ) && ! self::matches( $item, $schema['additionalProperties'] ) ) {
					return false;
				}
			}
		}
		if ( 'array' === ( $schema['type'] ?? null ) && isset( $schema['items'] ) ) {
			foreach ( $value as $item ) {
				if ( ! self::matches( $item, $schema['items'] ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
