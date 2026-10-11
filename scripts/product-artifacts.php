<?php

declare(strict_types=1);

require_once __DIR__ . '/../tests/e2e/product-runtime-context.php';

final class Wstm_Product_Artifacts {
	private const MAGIC = "WSTM-PRODUCT-EXPORT-1\n";
	private const FILE_BYTES = 33554432;

	public static function permitted(string $stage, string $path, bool $directory = false): bool {
		if ('bounded' === $stage) {
			if ($directory) {
				if (in_array($path, array('small', 'large'), true)) {
					return true;
				}
				return 1 === preg_match('~\A(small|large)/operation-(0|[1-9][0-9]{0,3})\z~', $path, $matches) &&
					(int) $matches[2] <= ('small' === $matches[1] ? 1124 : 335);
			}
			return 'product-verdict.private.json' === $path ||
				1 === preg_match('~\A(?:small|large)/(?:setup-before|pre-acquisition|state|seed-measurements|plan|before|after|cleanup-readback)\.json\z~', $path) ||
				(1 === preg_match('~\A(small|large)/operation-(0|[1-9][0-9]{0,3})/result\.json\z~', $path, $matches) &&
					(int) $matches[2] <= ('small' === $matches[1] ? 1124 : 335)) ||
				1 === preg_match('~\Alarge/(?:reference-parity|projection-probes|window-cache-faults)\.json\z~', $path);
		}
		if ('untrusted' === $stage) {
			if ($directory) {
				return 'wire' === $path;
			}
			return in_array($path, array('product-verdict.private.json', 'runner.json', 'runner.json.http.jsonl', 'resources.json', 'wire/private-wire.json'), true) ||
				(1 === preg_match('~\Awire/wire-([0-9]{6})\.bin\z~', $path, $matches) &&
					(int) $matches[1] >= 1 && (int) $matches[1] <= 4096);
		}
		return false;
	}

	private static function charge(string $stage, string $path, int $size, array &$budget): void {
		if ($size < 1 || $size > self::FILE_BYTES || !self::permitted($stage, $path)) {
			throw new RuntimeException('Product artifact role or per-file bound failed.');
		}
		$domain = 'bounded' === $stage ? 'bounded' : (0 === strpos($path, 'wire/') || 'runner.json.http.jsonl' === $path ? 'wire' : 'summary');
		$limit = 'bounded' === $stage ? 536870912 : 134217728;
		$budget[$domain] = ($budget[$domain] ?? 0) + $size;
		$budget['files'] = ($budget['files'] ?? 0) + 1;
		if ($budget[$domain] > $limit || $budget['files'] > ('bounded' === $stage ? 4096 : 4101)) {
			throw new RuntimeException('Product artifact aggregate bound failed.');
		}
	}

	private static function write($handle, string $bytes): void {
		$offset = 0;
		while ($offset < strlen($bytes)) {
			$count = fwrite($handle, substr($bytes, $offset));
			if (false === $count || 0 === $count) {
				throw new RuntimeException('Product artifact write failed.');
			}
			$offset += $count;
		}
	}

	private static function identity(string $path, bool $directory): array {
		clearstatcache(true, $path);
		$stat = lstat($path);
		if (false === $stat || realpath($path) !== $path || is_link($path) ||
			($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000) ||
			33 !== $stat['uid'] || 33 !== $stat['gid'] || (!$directory && 1 !== $stat['nlink'])) {
			throw new RuntimeException('Product artifact identity is not an owned regular path.');
		}
		return $stat;
	}

	private static function inventory(string $root, string $stage): array {
		self::identity($root, true);
		$pending = array('');
		$files = array();
		$entries = 0;
		while ($pending) {
			$relative = array_pop($pending);
			$directory = $root . ('' === $relative ? '' : '/' . $relative);
			self::identity($directory, true);
			foreach (new DirectoryIterator($directory) as $entry) {
				if ($entry->isDot()) {
					continue;
				}
				if (++$entries > ('bounded' === $stage ? 8192 : 4102)) {
					throw new RuntimeException('Product artifact inventory exceeded its finite path bound.');
				}
				$name = $entry->getFilename();
				$path = ('' === $relative ? '' : $relative . '/') . $name;
				if ($entry->isLink()) {
					throw new RuntimeException('Product artifacts cannot contain links.');
				}
				if ($entry->isDir()) {
					if (!self::permitted($stage, $path, true)) {
						throw new RuntimeException('Unexpected product artifact directory.');
					}
					$pending[] = $path;
				} elseif ($entry->isFile() && self::permitted($stage, $path)) {
					$files[] = $path;
				} else {
					throw new RuntimeException('Unexpected product artifact file or special path.');
				}
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	public static function export(string $stage): void {
		if (!in_array($stage, array('untrusted', 'bounded'), true)) {
			throw new RuntimeException('Unknown product stage.');
		}
		$context = Wstm167_Product_Context::open();
		$data = $context->data();
		$root = $data['artifact_root'] . '/' . $data['run'] . '/' . $stage;
		$files = self::inventory($root, $stage);
		$budget = array();
		self::write(STDOUT, self::MAGIC);
		foreach ($files as $path) {
			$absolute = $root . '/' . $path;
			$before = self::identity($absolute, false);
			self::charge($stage, $path, $before['size'], $budget);
			$sha = hash_file('sha256', $absolute);
			if (false === $sha) {
				throw new RuntimeException('Product artifact hash acquisition failed.');
			}
			$input = fopen($absolute, 'rb');
			if (false === $input || false === ($opened = fstat($input)) ||
				$opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || $opened['size'] !== $before['size']) {
				throw new RuntimeException('Product artifact changed while opening.');
			}
			self::write(STDOUT, 'F ' . $path . ' ' . $before['size'] . ' ' . $sha . "\n");
			$hash = hash_init('sha256');
			$remaining = $before['size'];
			while ($remaining > 0) {
				$bytes = fread($input, min(65536, $remaining));
				if (false === $bytes || '' === $bytes) {
					throw new RuntimeException('Product artifact read was truncated.');
				}
				$remaining -= strlen($bytes);
				hash_update($hash, $bytes);
				self::write(STDOUT, $bytes);
			}
			$after = fstat($input);
			$current = self::identity($absolute, false);
			if (false === $after || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime'] ||
				$current['dev'] !== $before['dev'] || $current['ino'] !== $before['ino'] ||
				$current['size'] !== $before['size'] || $current['mtime'] !== $before['mtime'] ||
				!hash_equals($sha, hash_final($hash)) || !fclose($input)) {
				throw new RuntimeException('Product artifact changed during export.');
			}
			self::write(STDOUT, "\n");
		}
		if ($files !== self::inventory($root, $stage)) {
			throw new RuntimeException('Product artifact inventory changed during export.');
		}
		$context->recheck();
		self::write(STDOUT, 'E ' . count($files) . "\n");
		if (!fflush(STDOUT)) {
			throw new RuntimeException('Product export stream flush failed.');
		}
	}

	public static function import(string $stage, string $archive, string $destination): array {
		if (!in_array($stage, array('untrusted', 'bounded'), true) || file_exists($destination) || is_link($destination)) {
			throw new RuntimeException('Product import requires a known stage and fresh private destination.');
		}
		$parent = realpath(dirname($destination));
		if (false === $parent || is_link(dirname($destination)) || !is_dir($parent)) {
			throw new RuntimeException('Product import parent is unavailable.');
		}
		$stat = lstat($archive);
		if (false === $stat || is_link($archive) || ($stat['mode'] & 0170000) !== 0100000 || 1 !== $stat['nlink']) {
			throw new RuntimeException('Product import requires a regular single-link private capture.');
		}
		$input = fopen($archive, 'rb');
		$opened = false === $input ? false : fstat($input);
		if (false === $opened || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] ||
			$opened['size'] !== $stat['size'] || fgets($input, 128) !== self::MAGIC) {
			throw new RuntimeException('Product export header is absent or incomplete.');
		}
		if (!mkdir($destination, 0700)) {
			throw new RuntimeException('Product import destination reservation failed.');
		}
		$budget = array();
		$files = array();
		$last = '';
		while (false !== ($line = fgets($input, 256))) {
			if (1 === preg_match('/\AE ([0-9]{1,4})\n\z/', $line, $end)) {
				$extra = fread($input, 1);
				if ((int) $end[1] !== count($files) || false === $extra || '' !== $extra) {
					throw new RuntimeException('Product export has mismatched completion or trailing bytes.');
				}
				clearstatcache(true, $archive);
				$after = fstat($input);
				$current = lstat($archive);
				if (false === $after || false === $current ||
					$after['size'] !== $stat['size'] || $after['mtime'] !== $stat['mtime'] ||
					$current['dev'] !== $stat['dev'] || $current['ino'] !== $stat['ino'] ||
					$current['size'] !== $stat['size'] || $current['mtime'] !== $stat['mtime'] ||
					1 !== $current['nlink'] || is_link($archive)) {
					throw new RuntimeException('Product capture changed during private import.');
				}
				if (!fclose($input)) {
					throw new RuntimeException('Product export close failed.');
				}
				return $files;
			}
			if (1 !== preg_match('/\AF ([a-z0-9.\/-]{1,128}) ([1-9][0-9]{0,7}) ([a-f0-9]{64})\n\z/', $line, $record)) {
				throw new RuntimeException('Product export record is malformed.');
			}
			$path = $record[1];
			$size = (int) $record[2];
			self::charge($stage, $path, $size, $budget);
			if (strcmp($last, $path) >= 0) {
				throw new RuntimeException('Product export paths are duplicated or unordered.');
			}
			$last = $path;
			$target = $destination . '/' . $path;
			$directory = dirname($target);
			if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
				throw new RuntimeException('Private product artifact directory reservation failed.');
			}
			$output = fopen($target, 'xb');
			if (false === $output) {
				throw new RuntimeException('Product artifact reservation failed.');
			}
			if (!chmod($target, 0600)) {
				throw new RuntimeException('Private product artifact mode initialization failed.');
			}
			$hash = hash_init('sha256');
			$remaining = $size;
			while ($remaining > 0) {
				$bytes = fread($input, min(65536, $remaining));
				if (false === $bytes || '' === $bytes) {
					throw new RuntimeException('Product export body was truncated; partial evidence is retained.');
				}
				$remaining -= strlen($bytes);
				hash_update($hash, $bytes);
				self::write($output, $bytes);
			}
			if (fread($input, 1) !== "\n" || !hash_equals($record[3], hash_final($hash)) ||
				!fflush($output) || !fclose($output)) {
				throw new RuntimeException('Product export body hash, frame or output completion failed.');
			}
			$files[] = array('path' => $path, 'bytes' => $size, 'sha256' => $record[3]);
		}
		throw new RuntimeException('Product export completion is missing; private partial evidence is retained.');
	}
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	if ('cli' !== PHP_SAPI) {
		http_response_code(403);
		exit('CLI only.');
	}
	$mode = $argv[1] ?? '';
	if ('export' === $mode && 3 === $argc) {
		Wstm_Product_Artifacts::export($argv[2]);
	} elseif ('import' === $mode && 5 === $argc) {
		Wstm_Product_Artifacts::import($argv[2], $argv[3], $argv[4]);
	} else {
		throw new RuntimeException('Expected export <stage> or import <stage> <private archive> <fresh destination>.');
	}
}
