<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI || '1' !== getenv('E2E_OPT_IN') ||
	!function_exists('posix_geteuid') || 33 !== posix_geteuid() ||
	!preg_match('/\A[a-f0-9]{64}\z/', (string) getenv('WSTM_PRODUCT_TOKEN'))) {
	throw new RuntimeException('Installation requires the owned disposable nonroot CLI.');
}
$password = stream_get_contents(STDIN, 66);
if (!is_string($password) || !preg_match('/\A[a-f0-9]{64}\n\z/', $password)) {
	throw new RuntimeException('Invalid private installation password.');
}
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
if (is_blog_installed() || '/var/www/html/' !== ABSPATH || is_multisite() ||
	file_exists(WP_CONTENT_DIR . '/install.php')) {
	throw new RuntimeException('Assessment requires an uninstalled native single-site WordPress.');
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
$result = wp_install('Released Adapter assessment', 'admin', 'admin@example.invalid', true, '', trim($password));
if (is_wp_error($result) || !is_blog_installed()) {
	throw new RuntimeException('Assessment installation failed.');
}
update_option('home', 'http://localhost');
update_option('siteurl', 'http://localhost');
add_option('wstm_adapter_assessment_owner', getenv('WSTM_PRODUCT_TOKEN'));
echo "Fresh assessment installation completed; native defaults retained.\n";
