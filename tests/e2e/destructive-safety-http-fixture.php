<?php
/**
 * Install as an MU loader only in an explicitly disposable WordPress runtime.
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WSTM116_DISPOSABLE_RUNTIME' ) || true !== WSTM116_DISPOSABLE_RUNTIME ) {
	return;
}
require_once WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/tests/e2e/destructive-safety-fixture.php';
add_filter( 'rest_pre_dispatch', 'wstm116_http_observer', PHP_INT_MIN, 3 );
