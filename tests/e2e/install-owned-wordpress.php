<?php

declare(strict_types=1);

require_once __DIR__ . '/fresh-install-preparation.php';

if ('cli' !== PHP_SAPI) {
	http_response_code(403);
	exit('CLI only.');
}
if ('1' !== getenv('E2E_OPT_IN') ||
	!function_exists('posix_geteuid') || 33 !== posix_geteuid() || 33 !== posix_getegid()) {
	throw new RuntimeException('WordPress initialization requires the nonroot disposable CLI.');
}
$verify = 2 === $argc && '--verify' === $argv[1];
$observe = 2 === $argc && '--observe' === $argv[1];
if (!$verify && !$observe && 1 !== $argc) {
	throw new RuntimeException('Initialization accepts only fixed verification or no-deletion observation selectors.');
}
$password = $verify ? '' : stream_get_contents(STDIN, 66);
if (!$verify && (!is_string($password) || 1 !== preg_match('/\A[a-f0-9]{64}\n\z/', $password))) {
	throw new RuntimeException('Private initialization password input is invalid.');
}
$owner = Wstm167_Fresh_Install::owner();
$artifact_root = Wstm108_Files::directory('/qa-artifacts');
Wstm167_Fresh_Install::check(33 === $artifact_root['uid'] && 33 === $artifact_root['gid']
	&& 0700 === ($artifact_root['mode'] & 0777), 'private initialization artifact root');
$receipt = '/qa-artifacts/fresh-install.private.json';
$url = getenv('WSTM_INITIAL_SITE_URL');
if ('http://localhost' !== $url) {
	throw new RuntimeException('WordPress initialization requires the fixed internal QA URL.');
}
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/wp-load.php';
Wstm167_Fresh_Install::check(realpath(ABSPATH) === '/var/www/html' && !is_multisite(),
	'fixed native single-site bootstrap');
Wstm167_Fresh_Install::check(!file_exists(WP_CONTENT_DIR . '/install.php') && !is_link(WP_CONTENT_DIR . '/install.php'),
	'foreign initializer customization');
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true');
$preparation = new Wstm167_Fresh_Install($GLOBALS['wpdb']);
if ($verify) {
	Wstm167_Fresh_Install::check(is_blog_installed(), 'verification requires installed WordPress');
	$verified = Wstm167_Fresh_Install::verify_receipt($receipt, $owner, $GLOBALS['wpdb']);
	echo json_encode(array('status' => 'fresh-install-verified', 'receipt_sha256' => $verified['sha256']),
		JSON_THROW_ON_ERROR) . "\n";
	exit(0);
}
if (is_blog_installed()) {
	throw new RuntimeException('Initialization refuses an already installed database.');
}
$before = $preparation->preinstall();
$preparation->pin_native();
$reservation = Wstm108_Files::create($receipt, json_encode(array(
	'schema' => 'wstm-fresh-install-v1', 'owner' => $owner, 'complete' => false, 'preinstall' => $before,
), JSON_THROW_ON_ERROR));
Wstm167_Fresh_Install::check($before === $preparation->preinstall(), 'pre-install domain drift');
$preparation->recheck();
$observer = array($preparation, 'observe');
$phase = 'initializer';
try {
	add_filter('query', $observer, PHP_INT_MAX);
	try {
		$result = wp_install('MCP E2E', 'admin', 'admin@test.local', true, '', trim($password));
	} finally {
		remove_filter('query', $observer, PHP_INT_MAX);
		$preparation->finish_query();
	}
	if (is_wp_error($result) || !is_array($result) || !isset($result['user_id']) ||
		!is_blog_installed()) {
		throw new RuntimeException('Owned WordPress initialization failed.');
	}
	$phase = 'preparation';
	$prepared = $preparation->prepare((int) $result['user_id'], $observe);
	$phase = 'completion';
	update_option('home', $url);
	update_option('siteurl', $url);
	if (get_option('home') !== $url || get_option('siteurl') !== $url) {
		throw new RuntimeException('Owned WordPress URL initialization failed.');
	}
	$reservation = Wstm108_Files::update($receipt, $reservation, json_encode(array(
		'schema' => 'wstm-fresh-install-v1', 'owner' => $owner, 'complete' => true,
		'preinstall' => $before, 'preparation' => $prepared,
	), JSON_THROW_ON_ERROR));
	Wstm167_Fresh_Install::verify_receipt($receipt, $owner, $GLOBALS['wpdb']);
} catch (Throwable $error) {
	try {
		$preparation->retain_failure($receipt, $reservation, $owner, $before, $error, $phase);
	} catch (Throwable $record_error) {
		throw new RuntimeException('Fresh installation failure evidence could not be retained: ' .
			get_class($record_error) . '.', 0, $error);
	}
	throw $error;
}
echo "Owned WordPress initialized; private password was not printed.\n";
