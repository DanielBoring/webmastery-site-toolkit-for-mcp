<?php

defined( 'ABSPATH' ) || exit;

/**
 * Preserve the core lifecycle while normalizing only this plugin's boundaries.
 */
final class Webmastery_MCP_Ability extends WP_Ability {
	public function validate_input( $input = null ) {
		$result = parent::validate_input( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$schema = $this->get_input_schema();
		if ( empty( $schema ) ) {
			return $result;
		}
		return Webmastery_MCP_Input::validate( $input, $schema ) ?? $result;
	}

	protected function invoke_callback( callable $callback, $input = null ) {
		try {
			return parent::invoke_callback( $callback, $input );
		} catch ( Throwable ) {
			// WordPress 6.9 does not yet turn callback exceptions into native errors.
			return Webmastery_MCP_Response::local_error( 'ability_callback_exception', 'The ability could not complete the operation.' );
		}
	}

	public function execute( $input = null ) {
		$result = parent::execute( $input );
		return is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
	}

	public function check_permissions( $input = null ) {
		$result = parent::check_permissions( $input );
		if ( true === $result ) {
			return true;
		}
		if ( ! is_wp_error( $result ) ) {
			$result = Webmastery_MCP_Response::local_error( 'forbidden', 'You do not have permission to execute this ability.' );
		}
		return Webmastery_MCP_Response::permission_error( $result );
	}
}
