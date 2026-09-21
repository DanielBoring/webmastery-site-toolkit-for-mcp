<?php

namespace Wstm126Probe;

// A registration/Reflection seam, not a replacement for real core lifecycle proof.
class WP_Ability {
	protected array $input_schema;
	protected $permission_callback;
	protected $execute_callback;
	public array $registered;

	public function __construct( array $args ) {
		$this->registered = $args;
		foreach ( array( 'input_schema', 'permission_callback', 'execute_callback' ) as $field ) {
			$this->$field = $args[ $field ];
		}
	}
}

class Webmastery_MCP_Ability extends WP_Ability {}

function wp_register_ability( $name, $args ) {
	return new Webmastery_MCP_Ability( \Webmastery_MCP_Input::register_args( $args, $name ) );
}
