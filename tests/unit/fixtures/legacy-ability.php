<?php

// Model only the uncaught callback boundary; real core lifecycle proof is E2E.
class WP_Ability {
	public int $calls = 0;
	public $callback;

	protected function invoke_callback( callable $callback, $input = null ) {
		++$this->calls;
		return $callback( $input );
	}

	public function check_permissions( $input = null ) {
		return $this->invoke_callback( $this->callback, $input );
	}
}
