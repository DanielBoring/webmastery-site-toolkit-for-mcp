<?php

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

try {
	$command = $argv[1] ?? '';
	$root = dirname(__DIR__);
	switch ($command) {
		case 'version':
			echo release_metadata($root)['version'] . "\n";
			break;
		case 'bundle':
			release_bundle($root, $argv[2], $argv[3], $argv[4], $argv[5]);
			break;
		case 'verify':
			release_verify_bundle($root, $argv[2], $argv[3], $argv[4], $argv[5]);
			break;
		case 'extract':
			release_extract($argv[2], $argv[3]);
			break;
		case 'compare-tree':
			release_require(release_tree($argv[2]) === release_zip_files($argv[3]), 'SVN tag does not match validated ZIP; refuse retagging.');
			break;
		case 'compare-zip':
			release_require(release_zip_files($argv[2]) === release_zip_files($argv[3]), 'Public WordPress ZIP does not match validated package.');
			break;
		case 'state':
			$version = release_version($argv[2]);
			$svn = simplexml_load_string(release_read($argv[3]), SimpleXMLElement::class, LIBXML_NONET);
			release_require(false !== $svn && isset($svn->list), 'Invalid SVN listing.');
			$versions = array();
			$svn_exists = false;
			foreach ($svn->list->entry as $entry) {
				$name = (string) $entry->name;
				release_require('dir' === (string) $entry['kind'], 'Unexpected file in SVN tags.');
				// Historical tags may use major.minor, but still participate in ordering.
				release_version($name, false);
				$versions[] = 2 === count(explode('.', $name)) ? $name . '.0' : $name;
				$svn_exists = $svn_exists || $name === $version;
			}
			$pages = json_decode(release_read($argv[4]), true, 512, JSON_THROW_ON_ERROR);
			release_require(is_array($pages) && count($pages) > 0 && array_values($pages) === $pages, 'Invalid GitHub releases response.');
			$github_exists = false;
			foreach ($pages as $page) {
				release_require(is_array($page), 'Invalid GitHub releases page.');
				foreach ($page as $release) {
					$tag = $release['tag_name'] ?? '';
					if ('v' . $version === $tag) {
						release_require(!$github_exists && empty($release['draft']) && empty($release['prerelease']), 'Duplicate/draft/prerelease requires manual investigation.');
						$github_exists = true;
					}
					if (!empty($release['draft']) || !empty($release['prerelease'])) {
						continue;
					}
					release_require(str_starts_with($tag, 'v'), 'Unrecognized published GitHub release tag.');
					$versions[] = release_version(substr($tag, 1));
				}
			}
			$listing = json_decode(release_read($argv[5]), true, 512, JSON_THROW_ON_ERROR);
			release_require(RELEASE_SLUG === ($listing['slug'] ?? null), 'Invalid public plugin response.');
			$versions[] = release_version($listing['version'] ?? '');
			release_assert_not_older($version, $versions);
			echo $svn_exists ? "svn_exists=true\n" : "svn_exists=false\n";
			echo $github_exists ? "github_exists=true\n" : "github_exists=false\n";
			break;
		case 'listing':
			$listing = json_decode(release_read($argv[2]), true, 512, JSON_THROW_ON_ERROR);
			release_require(RELEASE_SLUG === ($listing['slug'] ?? null) && $argv[3] === ($listing['version'] ?? null), 'Public listing has not reached expected version.');
			break;
		case 'decision':
			$flags = array_slice($argv, 3);
			release_require(4 === count($flags), 'Recovery requires four state flags.');
			foreach ($flags as $flag) {
				release_require(in_array($flag, array('true', 'false'), true), 'Invalid recovery flag.');
			}
			echo release_recovery($argv[2], array(), 'true' === $flags[0], 'true' === $flags[1], 'true' === $flags[2], 'true' === $flags[3]) . "\n";
			break;
		case 'checker-verdict':
			$report = release_plugin_check_report(release_read($argv[2]));
			release_require(false !== file_put_contents($argv[3], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"), 'Cannot write Plugin Check verdict.');
			foreach ($report['findings'] as $finding) {
				echo "{$finding['type']} {$finding['file']}:{$finding['line']} {$finding['code']}: {$finding['message']}\n";
			}
			release_require(0 === $report['errors'], "Plugin Check reported {$report['errors']} ERROR(s); exit status alone is not a passing verdict.");
			echo "PASS Plugin Check: 0 errors, {$report['warnings']} warnings retained.\n";
			break;
		default:
			throw new RuntimeException('Unknown release helper command.');
	}
} catch (Throwable $error) {
	fwrite(STDERR, "ERROR {$error->getMessage()}\n");
	exit(1);
}
