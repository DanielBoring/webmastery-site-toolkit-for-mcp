<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/product-artifacts.php';
require_once __DIR__ . '/fixtures/product-artifact-export.php';

final class ProductArtifactsTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wstm-artifact-codec-' . bin2hex(random_bytes(12));
		self::assertTrue(mkdir($this->root, 0700));
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			if ($entry->isDir() && !$entry->isLink()) {
				self::assertTrue(rmdir($entry->getPathname()));
			} else {
				self::assertTrue(unlink($entry->getPathname()));
			}
		}
		self::assertTrue(rmdir($this->root));
	}

	private function archive(string $bytes): string {
		$path = $this->root . DIRECTORY_SEPARATOR . 'capture.private';
		self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
		return $path;
	}

	private static function frame(string $path, string $bytes, ?string $hash = null): string {
		return 'F ' . $path . ' ' . strlen($bytes) . ' ' . ($hash ?? hash('sha256', $bytes)) . "\n" . $bytes . "\n";
	}

	public function test_exact_bytes_and_hashes_survive_private_import(): void {
		$body = "{\"private\": \"embedded\\nvalue\"}\n";
		$wire = "WSTM108-WIRE-1\n" . serialize(array('body' => "\0\r\n\xff"));
		$archive = $this->archive(
			"WSTM-PRODUCT-EXPORT-1\n" .
			self::frame('runner.json', $body) .
			self::frame('wire/wire-000001.bin', $wire) . "E 2\n"
		);
		$destination = $this->root . DIRECTORY_SEPARATOR . 'out';
		$files = Wstm_Product_Artifacts::import('untrusted', $archive, $destination);
		self::assertSame($body, file_get_contents($destination . '/runner.json'));
		self::assertSame($wire, file_get_contents($destination . '/wire/wire-000001.bin'));
		self::assertSame(
			array(
				array('path' => 'runner.json', 'bytes' => strlen($body), 'sha256' => hash('sha256', $body)),
				array('path' => 'wire/wire-000001.bin', 'bytes' => strlen($wire), 'sha256' => hash('sha256', $wire)),
			),
			$files
		);
	}

	public function test_actual_bounded_export_import_retains_exact_pre_acquisition_bytes_and_failed_verdict(): void {
		$run = $this->root . DIRECTORY_SEPARATOR . 'run';
		$bounded = $run . DIRECTORY_SEPARATOR . 'bounded';
		self::assertTrue(mkdir($run, 0700));
		self::assertTrue(mkdir($bounded, 0700));
		$pre = "{\"schema\":\"wstm-bounded-pre-acquisition-v1\",\"private\":\"inert\\nvalue\"}\n";
		$verdict = "{\"status\":\"failed\",\"exit\":1,\"cleanup_complete\":true}\n";
		$expected = array('product-verdict.private.json' => $verdict);
		foreach (array('small', 'large') as $catalog) {
			self::assertTrue(mkdir($bounded . DIRECTORY_SEPARATOR . $catalog, 0700));
			$expected[$catalog . '/pre-acquisition.json'] = $pre;
		}
		ksort($expected, SORT_STRING);
		foreach ($expected as $name => $bytes) {
			self::assertSame(strlen($bytes), file_put_contents($bounded . '/' . $name, $bytes));
		}
		$bytes = \WstmProductArtifactExport\capture($this->root, 'run');
		self::assertSame(1, \WstmProductArtifactExport\Wstm167_Product_Context::$rechecks);
		$destination = $this->root . DIRECTORY_SEPARATOR . 'out';
		$files = Wstm_Product_Artifacts::import('bounded', $this->archive($bytes), $destination);
		self::assertSame(array_keys($expected), array_column($files, 'path'));
		foreach ($files as $file) {
			self::assertSame($expected[$file['path']], file_get_contents($destination . '/' . $file['path']));
			self::assertSame(hash('sha256', $expected[$file['path']]), $file['sha256']);
			self::assertSame(strlen($expected[$file['path']]), $file['bytes']);
			self::assertSame(0600, fileperms($destination . '/' . $file['path']) & 0777);
		}
		self::assertSame('failed', json_decode(file_get_contents($destination . '/product-verdict.private.json'), true)['status']);
	}

	public static function invalid_pre_acquisition_streams(): array {
		$magic = "WSTM-PRODUCT-EXPORT-1\n";
		return array(
			'unknown catalog' => array($magic . self::frame('foreign/pre-acquisition.json', '{}') . "E 1\n"),
			'unknown filename' => array($magic . self::frame('small/pre-acquisition-extra.json', '{}') . "E 1\n"),
			'secret filename' => array($magic . self::frame('small/credentials.json', '{}') . "E 1\n"),
			'parent traversal' => array($magic . self::frame('small/../pre-acquisition.json', '{}') . "E 1\n"),
			'absolute path' => array($magic . self::frame('/small/pre-acquisition.json', '{}') . "E 1\n"),
			'wrong hash' => array($magic . self::frame('small/pre-acquisition.json', '{}', str_repeat('0', 64)) . "E 1\n"),
			'oversized' => array($magic . "F small/pre-acquisition.json 33554433 " . str_repeat('0', 64) . "\n"),
			'zero size' => array($magic . "F small/pre-acquisition.json 0 " . hash('sha256', '') . "\n\nE 1\n"),
			'duplicate' => array($magic . self::frame('small/pre-acquisition.json', '{}') . self::frame('small/pre-acquisition.json', '{}') . "E 2\n"),
		);
	}

	/** @dataProvider invalid_pre_acquisition_streams */
	public function test_pre_acquisition_import_keeps_closed_paths_hashes_and_bounds(string $bytes): void {
		$this->expectException(RuntimeException::class);
		Wstm_Product_Artifacts::import('bounded', $this->archive($bytes), $this->root . DIRECTORY_SEPARATOR . 'out');
	}

	public static function invalid_pre_acquisition_entries(): array {
		return array(array('unknown'), array('directory'), array('symlink'), array('special'));
	}

	/** @dataProvider invalid_pre_acquisition_entries */
	public function test_actual_export_refuses_unknown_directories_links_and_special_entries_before_any_frame(string $kind): void {
		$run = $this->root . DIRECTORY_SEPARATOR . 'run';
		$bounded = $run . DIRECTORY_SEPARATOR . 'bounded';
		self::assertTrue(mkdir($run, 0700));
		self::assertTrue(mkdir($bounded, 0700));
		self::assertTrue(mkdir($bounded . DIRECTORY_SEPARATOR . 'small', 0700));
		$path = $bounded . DIRECTORY_SEPARATOR . 'small' . DIRECTORY_SEPARATOR . 'pre-acquisition.json';
		if ('unknown' === $kind) {
			file_put_contents($path . '.secret', '{}');
		} elseif ('directory' === $kind) {
			self::assertTrue(mkdir($path, 0700));
		} elseif ('symlink' === $kind) {
			file_put_contents($this->root . DIRECTORY_SEPARATOR . 'outside', '{}');
			if (!@symlink($this->root . DIRECTORY_SEPARATOR . 'outside', $path)) {
				self::markTestSkipped('Symbolic-link test primitive unavailable.');
			}
		} else {
			if (!function_exists('posix_mkfifo')) {
				self::markTestSkipped('FIFO test primitive unavailable.');
			}
			self::assertTrue(posix_mkfifo($path, 0600));
		}
		try {
			\WstmProductArtifactExport\capture($this->root, 'run');
			self::fail('Invalid product entry accepted.');
		} catch (RuntimeException $error) {
			self::assertNotSame('', $error->getMessage());
			self::assertSame(0, ftell(\WstmProductArtifactExport\STDOUT));
			self::assertSame(0, \WstmProductArtifactExport\Wstm167_Product_Context::$rechecks);
		}
	}

	public static function invalid_streams(): array {
		$magic = "WSTM-PRODUCT-EXPORT-1\n";
		$frame = self::frame('runner.json', '{}');
		return array(
			'wrong magic' => array("wrong\n"),
			'truncation' => array($magic . "F runner.json 3 " . hash('sha256', '{}') . "\n{}"),
			'hash mutation' => array($magic . self::frame('runner.json', '{}', str_repeat('0', 64)) . "E 1\n"),
			'unknown role' => array($magic . self::frame('unexpected.json', '{}') . "E 1\n"),
			'parent traversal' => array($magic . self::frame('../outside.json', '{}') . "E 1\n"),
			'absolute path' => array($magic . self::frame('/runner.json', '{}') . "E 1\n"),
			'duplicate path' => array($magic . $frame . $frame . "E 2\n"),
			'wrong count' => array($magic . $frame . "E 2\n"),
			'no completion' => array($magic . $frame),
			'trailing data' => array($magic . $frame . "E 1\n{}"),
			'oversized body declaration' => array($magic . "F runner.json 33554433 " . str_repeat('0', 64) . "\n"),
			'frame outside ordinal range' => array($magic . self::frame('wire/wire-004097.bin', '{}') . "E 1\n"),
		);
	}

	/** @dataProvider invalid_streams */
	public function test_invalid_or_incomplete_streams_are_refused(string $bytes): void {
		$this->expectException(RuntimeException::class);
		Wstm_Product_Artifacts::import('untrusted', $this->archive($bytes), $this->root . DIRECTORY_SEPARATOR . 'out');
	}

	public function test_existing_output_is_never_reused_or_overwritten(): void {
		$destination = $this->root . DIRECTORY_SEPARATOR . 'out';
		self::assertTrue(mkdir($destination, 0700));
		file_put_contents($destination . '/runner.json', 'retained');
		try {
			Wstm_Product_Artifacts::import('untrusted', $this->archive("WSTM-PRODUCT-EXPORT-1\nE 0\n"), $destination);
			self::fail('Import reused an existing output directory.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('fresh private destination', $exception->getMessage());
			self::assertSame('retained', file_get_contents($destination . '/runner.json'));
		}
	}

	public function test_catalog_role_and_index_boundaries_are_exact(): void {
		foreach (array('small', 'large') as $catalog) {
			self::assertTrue(Wstm_Product_Artifacts::permitted('bounded', $catalog . '/pre-acquisition.json'));
			self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', $catalog . '/pre-acquisition.json', true));
			self::assertFalse(Wstm_Product_Artifacts::permitted('untrusted', $catalog . '/pre-acquisition.json'));
		}
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'pre-acquisition.json'));
		self::assertTrue(Wstm_Product_Artifacts::permitted('bounded', 'small/operation-1124/result.json'));
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'small/operation-1125/result.json'));
		self::assertTrue(Wstm_Product_Artifacts::permitted('bounded', 'large/operation-335/result.json'));
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'large/operation-336/result.json'));
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'small/reference-parity.json'));
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'large/operation-01/result.json'));
		self::assertFalse(Wstm_Product_Artifacts::permitted('bounded', 'large/operation-336', true));
		self::assertTrue(Wstm_Product_Artifacts::permitted('bounded', 'large/operation-335', true));
		self::assertFalse(Wstm_Product_Artifacts::permitted('unknown', 'runner.json'));
	}

	public function test_exact_per_file_and_aggregate_bounds_do_not_mix_wire_and_summary(): void {
		$charge = new ReflectionMethod(Wstm_Product_Artifacts::class, 'charge');
		$charge->setAccessible(true);
		$budget = array();
		foreach (array('product-verdict.private.json', 'resources.json', 'runner.json', 'runner.json.http.jsonl') as $path) {
			$arguments = array('untrusted', $path, 33554432, &$budget);
			$charge->invokeArgs(null, $arguments);
		}
		self::assertSame(100663296, $budget['summary']);
		self::assertSame(33554432, $budget['wire']);
		$arguments = array('untrusted', 'wire/wire-000001.bin', 33554432, &$budget);
		$charge->invokeArgs(null, $arguments);
		self::assertSame(67108864, $budget['wire']);
		$arguments = array('untrusted', 'runner.json', 33554432, &$budget);
		$charge->invokeArgs(null, $arguments);
		$this->expectException(RuntimeException::class);
		$arguments = array('untrusted', 'runner.json', 1, &$budget);
		$charge->invokeArgs(null, $arguments);
	}

	public function test_bounded_aggregate_limit_accepts_exact_threshold_then_refuses_one_byte(): void {
		$charge = new ReflectionMethod(Wstm_Product_Artifacts::class, 'charge');
		$charge->setAccessible(true);
		$budget = array();
		for ($index = 0; $index < 16; ++$index) {
			$arguments = array('bounded', 'large/operation-' . $index . '/result.json', 33554432, &$budget);
			$charge->invokeArgs(null, $arguments);
		}
		self::assertSame(536870912, $budget['bounded']);
		$this->expectException(RuntimeException::class);
		$arguments = array('bounded', 'large/operation-16/result.json', 1, &$budget);
		$charge->invokeArgs(null, $arguments);
	}

	public function test_wire_domain_includes_journal_and_index_at_exact_existing_limit(): void {
		$charge = new ReflectionMethod(Wstm_Product_Artifacts::class, 'charge');
		$charge->setAccessible(true);
		$budget = array();
		foreach (array('runner.json.http.jsonl', 'wire/private-wire.json', 'wire/wire-000001.bin', 'wire/wire-000002.bin') as $path) {
			$arguments = array('untrusted', $path, 33554432, &$budget);
			$charge->invokeArgs(null, $arguments);
		}
		self::assertSame(134217728, $budget['wire']);
		$this->expectException(RuntimeException::class);
		$arguments = array('untrusted', 'wire/wire-000003.bin', 1, &$budget);
		$charge->invokeArgs(null, $arguments);
	}
}
