<?php

declare(strict_types=1);

if ('1' !== getenv('E2E_OPT_IN') || !function_exists('posix_geteuid') ||
	33 !== posix_geteuid() || '/var/www/html/' !== ABSPATH ||
	get_option('wstm_adapter_assessment_owner') !== getenv('WSTM_PRODUCT_TOKEN')) {
	throw new RuntimeException('Adapter assessment requires the owned nonroot disposable installation.');
}

$abilities = [];
foreach (wp_get_abilities() as $ability) {
	if (0 === strpos($ability->get_name(), 'webmastery-site-toolkit-for-mcp/')) {
		$abilities[$ability->get_name()] = $ability->get_meta()['annotations'] ?? [];
	}
}
ksort($abilities);
$actors = [];
foreach (['administrator', 'subscriber'] as $role) {
	$login = 'adapter_assessment_' . $role;
	if (get_user_by('login', $login)) {
		throw new RuntimeException('Assessment actor already exists.');
	}
	$id = wp_insert_user([
		'user_login' => $login,
		'user_pass' => bin2hex(random_bytes(32)),
		'user_email' => $login . '@example.invalid',
		'role' => $role,
	]);
	if (is_wp_error($id)) {
		throw new RuntimeException($id->get_error_message());
	}
	$password = WP_Application_Passwords::create_new_application_password($id, ['name' => 'Disposable assessment']);
	if (is_wp_error($password)) {
		throw new RuntimeException($password->get_error_message());
	}
	$actors[$role] = ['login' => $login, 'password' => $password[0]];
}
$post = wp_insert_post(['post_title' => 'Adapter assessment fixture', 'post_status' => 'draft'], true);
if (is_wp_error($post)) {
	throw new RuntimeException($post->get_error_message());
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
echo json_encode([
	'owner' => getenv('WSTM_PRODUCT_TOKEN'),
	'wordpress' => get_bloginfo('version'),
	'php' => PHP_VERSION,
	'plugin' => get_plugin_data(WP_PLUGIN_DIR . '/webmastery-site-toolkit-for-mcp/webmastery-site-toolkit-for-mcp.php')['Version'],
	'adapter' => get_plugin_data(WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php')['Version'],
	'abilities' => $abilities,
	'actors' => $actors,
	'post' => $post,
], JSON_THROW_ON_ERROR) . "\n";
