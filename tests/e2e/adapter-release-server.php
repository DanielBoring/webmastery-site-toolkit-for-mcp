<?php

declare(strict_types=1);

if ('1' !== getenv('E2E_OPT_IN') || !preg_match('/\A[a-f0-9]{64}\z/', (string) getenv('WSTM_PRODUCT_TOKEN'))) {
	throw new RuntimeException('Assessment server is disposable-only.');
}

add_filter('wp_is_application_passwords_available', '__return_true');
add_action('mcp_adapter_init', static function ($adapter): void {
	$tools = [];
	foreach (wp_get_abilities() as $ability) {
		if (0 === strpos($ability->get_name(), 'webmastery-site-toolkit-for-mcp/')) {
			$tools[] = $ability->get_name();
		}
	}
	$result = $adapter->create_server(
		'assessment-individual', 'assessment', 'individual', 'Released Toolkit assessment',
		'Disposable individual-tool projection', '1.0.0',
		[\WP\MCP\Transport\HttpTransport::class],
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		null, $tools
	);
	if (is_wp_error($result)) {
		throw new RuntimeException($result->get_error_message());
	}
});
