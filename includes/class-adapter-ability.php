<?php

defined( 'ABSPATH' ) || exit;

/**
 * Supply the Adapter's optional preparation method only on cores that lack it.
 */
final class Webmastery_MCP_Adapter_Ability extends Webmastery_MCP_Ability {
	/**
	 * Prepare input and preserve the error contract for the calling boundary.
	 *
	 * @param mixed $input Ability input.
	 * @return mixed Normalized input or the rejection for this caller.
	 */
	public function prepare_input( $input = null ) {
		$input = $this->normalize_input( $input );
		$error = is_wp_error( $input ) ? $input : $this->validate_input( $input );
		if ( ! is_wp_error( $error ) ) {
			return $input;
		}

		if ( ! self::is_mcp_tool_precheck() ) {
			return $error;
		}
		if ( 'ability_invalid_input' === $error->get_error_code() ) {
			$metadata = Webmastery_MCP_Input::combined_metadata_error( $this->get_name(), $input );
			if ( null !== $metadata ) {
				return Webmastery_MCP_Response::legacy_wp_error( $metadata['error']['reason'], $metadata['error']['message'], (array) $metadata['error']['details'] );
			}
		}
		return Webmastery_MCP_Response::permission_error( $error );
	}

	/**
	 * A native caller can also invoke the Adapter gateway's permission check.
	 * Require the real tool handler and its check; ignore arguments and objects.
	 */
	private static function is_mcp_tool_precheck(): bool {
		// Only frame names are used, on failures in the explicitly supported Adapter release.
		$stack  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 11 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$frames = [];
		foreach ( array_slice( $stack, 2 ) as $frame ) {
			$class    = $frame['class'] ?? '';
			$function = $frame['function'];
			$frames[] = $class . '::' . ( str_contains( $function, '{closure' ) ? '{closure}' : $function );
			if ( 'WP\\MCP\\Handlers\\Tools\\ToolsHandler' === $class && 'call_tool' === $function ) {
				break;
			}
		}

		$checker   = 'WP\\MCP\\Domain\\Utils\\AbilityPermissionChecker::check';
		$tool_path = [
			$checker,
			'WP\\MCP\\Domain\\Tools\\McpTool::{closure}',
			'WP\\MCP\\Domain\\Tools\\McpTool::guard',
			'WP\\MCP\\Domain\\Tools\\McpTool::check_permission',
			'WP\\MCP\\Handlers\\Tools\\ToolsHandler::call_tool',
		];

		// The gateway resolves and checks the inner ability through its own permission callback.
		$gateway_prefix = [
			$checker,
			'WP\\MCP\\Abilities\\ExecuteAbilityAbility::check_permission',
			WP_Ability::class . '::invoke_callback',
			WP_Ability::class . '::check_permissions',
		];
		$gateway_path   = array_merge( $gateway_prefix, $tool_path );
		return $frames === $tool_path || $frames === $gateway_path;
	}
}
