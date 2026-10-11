<?php

use PHPUnit\Framework\TestCase;
use WstmPackageMountFixture\Fault;

require_once __DIR__ . '/fixtures/package-mount-preparation.php';

final class PackageMountPreparationTest extends TestCase {
	private string $work;
	private string $root;
	private string $zip;

	protected function setUp(): void {
		$this->work = sys_get_temp_dir() . '/wstm-mount-' . bin2hex(random_bytes(8));
		mkdir($this->work, 0700);
		$this->zip = $this->work . '/original.zip';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->zip, ZipArchive::CREATE | ZipArchive::EXCL));
		foreach (array('LICENSE', 'readme.txt', 'webmastery-site-toolkit-for-mcp.php', 'includes/test.php') as $name) {
			$zip->addFromString('webmastery-site-toolkit-for-mcp/' . $name, 'original ' . $name);
		}
		$zip->close();
		\WstmPackageMountFixture\release_extract($this->zip, $this->work . '/runtime');
		$this->root = $this->work . '/runtime/webmastery-site-toolkit-for-mcp';
		Fault::$events = array();
	}

	protected function tearDown(): void {
		Fault::$action = null;
		Fault::$open = null;
		Fault::$after = null;
		Fault::$observe = null;
		Fault::$events = array();
		$this->remove($this->work);
	}

	private function remove(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			foreach (new FilesystemIterator($path) as $entry) {
				$this->remove($entry->getPathname());
			}
			rmdir($path);
		} else {
			unlink($path);
		}
	}

	private function prepare(): void {
		\WstmPackageMountFixture\release_prepare_runtime_mounts($this->root, $this->zip);
	}

	public function test_actual_preparation_preserves_archive_checker_and_production(): void {
		\WstmPackageMountFixture\release_extract($this->zip, $this->work . '/checker');
		$before = hash_file('sha256', $this->zip);
		$expected = \WstmPackageMountFixture\release_zip_files($this->zip);
		$this->prepare();
		self::assertSame($before, hash_file('sha256', $this->zip));
		self::assertSame($expected, \WstmPackageMountFixture\release_tree($this->work . '/checker/webmastery-site-toolkit-for-mcp'));
		self::assertSame(array('.', '..'), scandir($this->root . '/tests'));
		$actual = \WstmPackageMountFixture\release_tree($this->root);
		foreach (\WstmPackageMountFixture\release_runtime_mount_files() as $name) {
			self::assertSame(hash('sha256', ''), $actual[$name]);
			self::assertSame(1, lstat($this->root . '/' . $name)['nlink']);
			unset($actual[$name]);
		}
		self::assertSame($expected, $actual);
		\WstmPackageMountFixture\release_validate_runtime_tree($this->root, $this->zip);
		$this->expectException(RuntimeException::class);
		$this->prepare();
	}

	public static function unsafe_states(): array {
		return array(
			'partial tests' => array('tests', 'directory'),
			'partial scripts' => array('scripts', 'directory'),
			'partial github' => array('.github', 'directory'),
			'foreign empty directory' => array('foreign', 'directory'),
			'foreign file' => array('foreign.php', 'file'),
			'file collision tests' => array('tests', 'file'),
			'file collision scripts' => array('scripts', 'file'),
			'file collision github' => array('.github', 'file'),
			'link tests' => array('tests', 'link'),
			'link scripts' => array('scripts', 'link'),
			'link github' => array('.github', 'link'),
			'nonempty support' => array('scripts/compatibility-baselines.php', 'file'),
			'empty support collision' => array('.github/compatibility-versions.json', 'empty'),
			'changed production' => array('includes/test.php', 'file'),
		);
	}

	/** @dataProvider unsafe_states */
	public function test_unsafe_or_partial_state_is_refused_without_further_creation(string $name, string $kind): void {
		$path = $this->root . '/' . $name;
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path));
		}
		if ('directory' === $kind) {
			mkdir($path);
		} elseif ('link' === $kind) {
			symlink($this->work . '/missing', $path);
		} else {
			file_put_contents($path, 'empty' === $kind ? '' : 'changed');
		}
		$before = scandir($this->root);
		try {
			$this->prepare();
			self::fail('Unsafe input accepted.');
		} catch (RuntimeException $error) {
			self::assertNotSame('', $error->getMessage());
			self::assertSame($before, scandir($this->root));
		}
	}

	public static function mutations(): array {
		return array('production' => array('production'), 'archive' => array('archive'), 'collision' => array('collision'), 'root replacement' => array('root'));
	}

	/** @dataProvider mutations */
	public function test_mutation_during_actual_preparation_never_returns_complete(string $kind): void {
		Fault::$action = function ($path) use ($kind): void {
			if ('production' === $kind) {
				file_put_contents($this->root . '/includes/test.php', 'changed');
			} elseif ('archive' === $kind) {
				$zip = new ZipArchive();
				$zip->open($this->zip);
				$zip->setArchiveComment('changed');
				$zip->close();
			} elseif ('root' === $kind) {
				rename($this->root, $this->root . '-original');
				mkdir($this->root);
			} else {
				mkdir($path);
			}
		};
		try {
			$this->prepare();
			self::fail('Mutation accepted.');
		} catch (RuntimeException $error) {
			self::assertCount(1, Fault::$events);
			if ('root' === $kind) {
				// The swap is inside the mkdir wrapper, after the last pathname check.
				self::assertSame(array('.', '..', 'tests'), scandir($this->root));
				self::assertDirectoryDoesNotExist($this->root . '/scripts');
				self::assertDirectoryDoesNotExist($this->root . '/.github');
			}
		}
	}

	public function test_root_symlink_is_refused(): void {
		symlink($this->root, $this->work . '/linked');
		$this->root = $this->work . '/linked';
		$this->expectException(RuntimeException::class);
		$this->prepare();
	}

	public static function creation_failures(): array {
		return array('second directory' => array('directory'), 'first file' => array('file'), 'file collision' => array('collision'), 'hardlink' => array('hardlink'), 'nonempty' => array('nonempty'));
	}

	/** @dataProvider creation_failures */
	public function test_failed_creation_retains_partial_state_and_refuses_retry(string $kind): void {
		if ('directory' === $kind) {
			Fault::$action = static function (): void {
				Fault::$action = static fn() => false;
			};
		} else {
			Fault::$open = function ($path) use ($kind) {
				if ('file' === $kind) {
					return false;
				}
				if ('collision' === $kind) {
					file_put_contents($path, '');
					return null;
				}
				Fault::$open = function () use ($path, $kind): void {
					if ('hardlink' === $kind) {
						link($path, $this->work . '/extra-link');
					} else {
						file_put_contents($path, 'changed');
					}
				};
				return null;
			};
		}
		try {
			$this->prepare();
			self::fail('Incomplete preparation accepted.');
		} catch (RuntimeException $error) {
			self::assertNotSame('', $error->getMessage());
			self::assertDirectoryExists($this->root . '/tests');
		}
		Fault::$action = null;
		Fault::$open = null;
		$this->expectException(RuntimeException::class);
		$this->prepare();
	}

	public function test_linked_ancestor_is_refused(): void {
		symlink($this->work . '/runtime', $this->work . '/linked-parent');
		$this->root = $this->work . '/linked-parent/webmastery-site-toolkit-for-mcp';
		$this->expectException(RuntimeException::class);
		$this->prepare();
	}

	public static function between_operations(): array {
		$cases = array();
		foreach (array('root', 'ancestor', 'production', 'same-byte-production', 'archive', 'foreign-entry') as $kind) {
			foreach (range(1, 6) as $index) {
				$cases[$kind . ' after creation ' . $index] = array($kind, $index);
			}
		}
		return $cases;
	}

	/** @dataProvider between_operations */
	public function test_observed_mutation_after_native_creation_stops_all_later_creations(string $kind, int $index): void {
		$changed = false;
		$original = $this->root;
		Fault::$after = function () use ($kind, $index, &$changed, &$original): void {
			if (count(Fault::$events) !== $index) {
				return;
			}
			Fault::$after = null;
			$changed = true;
			if ('root' === $kind) {
				rename($this->root, $this->root . '-original');
				mkdir($this->root);
				$original = $this->root . '-original';
			} elseif ('ancestor' === $kind) {
				$parent = dirname($this->root);
				rename($parent, $parent . '-original');
				mkdir($parent);
				// Preserve the root inode to ensure the ancestor check itself is exercised.
				rename($parent . '-original/webmastery-site-toolkit-for-mcp', $this->root);
			} elseif ('archive' === $kind) {
				$zip = new ZipArchive();
				$zip->open($this->zip);
				$zip->setArchiveComment('changed after native creation');
				$zip->close();
			} elseif ('foreign-entry' === $kind) {
				mkdir($this->root . '/foreign');
			} elseif ('same-byte-production' === $kind) {
				$path = $this->root . '/includes/test.php';
				rename($path, $this->work . '/original-production.php');
				copy($this->work . '/original-production.php', $path);
			} else {
				file_put_contents($this->root . '/includes/test.php', 'changed after native creation');
			}
		};
		try {
			$this->prepare();
			self::fail('Between-operation mutation accepted.');
		} catch (RuntimeException $error) {
			self::assertTrue($changed);
			self::assertStringContainsString('custody', $error->getMessage());
			self::assertCount($index, Fault::$events, 'A later mkdir/fopen was attempted after the observed swap.');
			if ('root' === $kind) {
				self::assertSame(array('.', '..'), scandir($this->root), 'Foreign replacement received writes.');
				self::assertDirectoryExists($original . '/tests');
				$actual = \WstmPackageMountFixture\release_tree($original);
				$production = \WstmPackageMountFixture\release_zip_files($this->zip);
				$files = \WstmPackageMountFixture\release_runtime_mount_files();
				foreach (array_slice($files, 0, max(0, $index - 3)) as $name) {
					self::assertSame(hash('sha256', ''), $actual[$name]);
					unset($actual[$name]);
				}
				self::assertSame($production, $actual, 'Partial production evidence changed in the authenticated extraction.');
			}
		}
	}

	public static function bound_parent_replacements(): array {
		return array('tests captured' => array('tests', 1), 'scripts captured' => array('scripts', 2),
			'github captured' => array('.github', 3), 'scripts after first file' => array('scripts', 4),
			'github after second file' => array('.github', 5));
	}

	/** @dataProvider bound_parent_replacements */
	public function test_bound_support_parent_replacement_gets_no_later_writes(string $name, int $index): void {
		$swapped = false;
		$path = $this->root . '/' . $name;
		Fault::$observe = function ($observed, $stat) use ($path, $index, &$swapped): void {
			if ($observed !== $path || false === $stat || count(Fault::$events) !== $index) {
				return;
			}
			Fault::$observe = null;
			// Swap after sampling the original stat, not before mkdir executes.
			rename($path, $this->work . '/captured-parent');
			mkdir($path);
			$swapped = true;
		};
		try {
			$this->prepare();
			self::fail('Captured support parent replacement accepted.');
		} catch (RuntimeException $error) {
			self::assertTrue($swapped);
			self::assertStringContainsString('custody', $error->getMessage());
			self::assertCount($index, Fault::$events);
			self::assertSame(array('.', '..'), scandir($path), 'Replacement support parent received later placeholders.');
		}
	}
}
