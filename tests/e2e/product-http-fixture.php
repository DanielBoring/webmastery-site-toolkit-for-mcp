<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	http_response_code(403);
	exit('Fixture is MU-only.');
}
if ('1' !== getenv('E2E_OPT_IN')) {
	return;
}
require_once '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/product-runtime-context.php';

final class Wstm_Product_Http_Fixture {
	public static function register_individual($adapter): void {
		$context = self::context();
		$root = $context['data']['plugin_root'];
		require_once $root . '/tests/e2e/untrusted-content-plan.php';
		$expected = Wstm108_Plan::abilities(Wstm108_Plan::native_from_manifest($root . '/tests/e2e/abilities-manifest.json'));
		$names = [];
		foreach (wp_get_abilities() as $ability) {
			$name = $ability->get_name();
			if (0 === strpos($name, 'webmastery-site-toolkit-for-mcp/')) {
				if (in_array($name, $names, true)) {
					throw new RuntimeException('Duplicate product ability registration.');
				}
				$names[] = $name;
			}
		}
		sort($names, SORT_STRING);
		if ($names !== $expected || !doing_action('mcp_adapter_init')) {
			throw new RuntimeException('Product server timing or exact ability inventory refused.');
		}
		Wstm108_Files::assert_file('/qa-artifacts/context.json', $context['file']);
		$result = $adapter->create_server(
			'wstm118-individual', 'wstm118', 'tools', 'Product individual fixture',
			'Disposable individual-tool verification', '1.0.0',
			[\WP\MCP\Transport\HttpTransport::class], null, null, $names
		);
		if (is_wp_error($result)) {
			throw new RuntimeException('Native product server registration refused.');
		}
	}

	public static function context(): array {
		if (!function_exists('posix_geteuid') || !function_exists('posix_getegid') ||
			33 !== posix_geteuid() || 33 !== posix_getegid() ||
			'/qa-artifacts/context.json' !== getenv('WSTM_PRODUCT_CONTEXT')) {
			throw new RuntimeException('Actual disposable HTTP worker identity is unavailable.');
		}
		$directory = Wstm108_Files::directory('/qa-artifacts');
		if (33 !== $directory['uid'] || 33 !== $directory['gid'] || 0700 !== ($directory['mode'] & 0777)) {
			throw new RuntimeException('HTTP context directory is not private.');
		}
		$stat = lstat('/qa-artifacts/context.json');
		if (false === $stat || ($stat['mode'] & 0170000) !== 0100000 ||
			33 !== $stat['uid'] || 33 !== $stat['gid'] || 0600 !== ($stat['mode'] & 0777) ||
			1 !== $stat['nlink'] || $stat['size'] < 1 || $stat['size'] > 4096) {
			throw new RuntimeException('HTTP context file identity or byte bound failed.');
		}
		$file = Wstm108_Files::file('/qa-artifacts/context.json');
		$data = Wstm167_Product_Context::decode($file['bytes']);
		Wstm167_Product_Context::validate(
			$data,
			(string) getenv('WSTM_PRODUCT_RUN'),
			(string) getenv('WSTM_PRODUCT_TOKEN'),
			posix_geteuid(),
			PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
		);
		if (realpath(ABSPATH) !== $data['wordpress_root'] || is_multisite() ||
			get_option('wstm167_product_context') !== [
				'run' => $data['run'], 'token' => $data['token'], 'project' => $data['project'],
			]) {
			throw new RuntimeException('HTTP WordPress context does not match its owned database marker.');
		}
		Wstm108_Files::assert_file('/qa-artifacts/context.json', $file);
		$after = Wstm108_Files::directory('/qa-artifacts');
		unset($directory['nlink'], $after['nlink']);
		if ($after !== $directory) {
			throw new RuntimeException('HTTP artifact directory changed during observation.');
		}
		return ['data' => $data, 'file' => $file];
	}

	public static function permission(WP_REST_Request $request) {
		$token = getenv('WSTM_PRODUCT_TOKEN');
		$provided = $request->get_header('X-WSTM-QA-Token');
		if (!is_string($token) || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $token) ||
			!is_string($provided) || !hash_equals($token, $provided)) {
			return new WP_Error('wstm_product_fixture_denied', 'Disposable observation token required.', ['status' => 403]);
		}
		return true;
	}

	public static function observation() {
		try {
			$context = self::context();
			$data = $context['data'];
			if (!function_exists('wp_get_ability') ||
				null === wp_get_ability('webmastery-site-toolkit-for-mcp/get-post') ||
				!class_exists('Webmastery_MCP_Posts')) {
				throw new RuntimeException('Loaded toolkit registration is unavailable.');
			}
			$path = (new ReflectionClass('Webmastery_MCP_Posts'))->getFileName();
			if ($path !== $data['plugin_root'] . '/includes/class-posts.php') {
				throw new RuntimeException('Loaded toolkit is not the context plugin.');
			}
			$posts = Wstm108_Files::file($path);
			Wstm108_Files::assert_file($path, $posts);
			Wstm108_Files::assert_file('/qa-artifacts/context.json', $context['file']);
			return new WP_REST_Response([
				'schema' => 'wstm-product-web-observation-v1',
				'run' => $data['run'],
				'project' => $data['project'],
				'context_sha256' => $context['file']['sha256'],
				'token_sha256' => hash('sha256', $data['token']),
				'uid' => posix_geteuid(),
				'php' => PHP_VERSION,
				'php_floor' => $data['php_floor'],
				'plugin_root' => $data['plugin_root'],
				'loaded_posts_sha256' => $posts['sha256'],
			], 200, ['Cache-Control' => 'no-store']);
		} catch (Throwable $error) {
			error_log('WSTM product HTTP observation refused.');
			return new WP_Error('wstm_product_fixture_unavailable', 'Disposable runtime observation refused.', ['status' => 409]);
		}
	}
}

try {
	Wstm_Product_Http_Fixture::context();
	require_once '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/e2e/untrusted-content-fixture.php';
	add_action('mcp_adapter_init', [Wstm_Product_Http_Fixture::class, 'register_individual']);
} catch (Throwable $error) {
	error_log('WSTM product HTTP fixture activation refused.');
}
add_action('rest_api_init', static function (): void {
	register_rest_route('wstm167-qa/v1', '/runtime', [
		'methods' => 'GET',
		'permission_callback' => [Wstm_Product_Http_Fixture::class, 'permission'],
		'callback' => [Wstm_Product_Http_Fixture::class, 'observation'],
	]);
});
