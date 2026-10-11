<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/product-summary.php';

final class ProductSummaryTest extends TestCase {
	private function observations(string $lane = '8.2'): array {
		$context = [
			'schema' => 'wstm-product-context-v1', 'run' => str_repeat('a', 32),
			'token' => str_repeat('b', 64), 'project' => 'wstm-summary-test', 'url' => 'http://localhost',
			'artifact_root' => '/qa-artifacts',
			'plugin_root' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp',
			'wordpress_root' => '/var/www/html', 'identity_kind' => 'source',
			'identity_sha256' => str_repeat('c', 64), 'uid' => 33, 'php_floor' => $lane,
		];
		$bytes = json_encode($context, JSON_THROW_ON_ERROR);
		$records = ['runtime' => [
			'schema' => 'wstm-product-web-observation-v1', 'run' => $context['run'],
			'project' => $context['project'], 'context_sha256' => hash('sha256', $bytes),
			'token_sha256' => hash('sha256', $context['token']), 'uid' => 33, 'php' => $lane . '.34',
			'php_floor' => $lane, 'plugin_root' => $context['plugin_root'], 'loaded_posts_sha256' => str_repeat('d', 64),
		]];
		foreach ([
			'untrusted' => ['untrusted', 'records-verified-live-absence-unresolved', 298],
			'untrusted-live' => ['untrusted', 'live-absence-verified', 1],
			'bounded' => ['bounded', 'records-verified-live-absence-unresolved', 1461],
			'bounded-live' => ['bounded', 'live-absence-verified', 2],
		] as $name => $values) {
			$records[$name] = ['stage' => $values[0], 'status' => $values[1], 'count' => $values[2], 'lane' => $lane, 'uid' => 33];
		}
		return [$bytes, $records];
	}

	public function testExactClosedSummaryForEveryInheritedLane(): void {
		foreach (['8.0', '8.1', '8.2', '8.4'] as $lane) {
			[$bytes, $records] = $this->observations($lane);
			$this->assertSame([
				'schema' => 'wstm-product-qa-summary-v1', 'status' => 'passed', 'lane' => $lane,
				'uid' => 33, 'untrusted_cases' => 298, 'bounded_observations' => 1461,
			], Wstm_Product_Summary::verify($bytes, $records));
		}
	}

	public function testEveryObservationRejectsOmissionAlterationAndDisclosure(): void {
		[$bytes, $original] = $this->observations();
		foreach (array_keys($original) as $name) {
			$missing = $original;
			unset($missing[$name]);
			$this->refuses($bytes, $missing);
			foreach ($original[$name] as $key => $value) {
				$changed = $original;
				$changed[$name][$key] = 'php' === $key ? '8.4.1' : (is_int($value) ? $value + 1 : $value . '-changed');
				$this->refuses($bytes, $changed);
			}
			$extra = $original;
			$extra[$name]['raw_token'] = str_repeat('e', 64);
			$this->refuses($bytes, $extra);
		}
		$string_count = $original;
		$string_count['untrusted']['count'] = '298';
		$this->refuses($bytes, $string_count);
	}

	private function refuses(string $context, array $records): void {
		try {
			Wstm_Product_Summary::verify($context, $records);
			$this->fail('Incomplete or changed observations were accepted.');
		} catch (RuntimeException $exception) {
			$this->assertNotSame('', $exception->getMessage());
		}
	}
}
