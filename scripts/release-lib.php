<?php

declare(strict_types=1);

const RELEASE_SLUG = 'webmastery-site-toolkit-for-mcp';

function release_require(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function release_read(string $file): string {
	release_require(is_file($file) && !is_link($file), "Missing or unsafe file: {$file}");
	$result = file_get_contents($file);
	release_require(false !== $result, "Cannot read {$file}");
	return $result;
}

function release_version(string $version, bool $patch = true): string {
	$pattern = $patch ? '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D' : '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:\.(0|[1-9][0-9]*))?$/D';
	release_require(1 === preg_match($pattern, $version), "Invalid release/requirement version: {$version}");
	return $version;
}

function release_field(string $text, string $field): string {
	preg_match_all('/^[ \t]*\*?[ \t]*' . preg_quote($field, '/') . ':[ \t]*([^\r\n]+)$/mi', $text, $matches);
	release_require(1 === count($matches[1]), "Expected exactly one {$field} field.");
	return trim($matches[1][0]);
}

/** Extract a single exact heading, stopping at the next heading of the same level. */
function release_section(string $text, string $heading, string $pattern): string {
	$lines = preg_split('/\R/', $text);
	$found = 0;
	$active = false;
	$body = array();
	foreach ($lines as $line) {
		if (preg_match($pattern, $line, $match)) {
			$active = $heading === trim($match[1]);
			if ($active) {
				++$found;
			}
			continue;
		}
		if ($active) {
			$body[] = $line;
		}
	}
	release_require(1 === $found, "Expected one exact section: {$heading}");
	$content = trim(implode("\n", $body));
	$substantive = preg_replace('/<!--.*?-->/s', '', $content);
	$substantive = preg_replace('/^[ \t]*#+.*$/m', '', $substantive);
	release_require(1 === preg_match('/[\p{L}\p{N}]/u', $substantive), "Empty section: {$heading}");
	return $content;
}

function release_metadata(string $root, string $expected = ''): array {
	$plugin = release_read($root . '/' . RELEASE_SLUG . '.php');
	release_require(1 === preg_match('#/\*\*(.*?)\*/#s', $plugin, $header), 'Missing plugin header.');
	$readme = release_read($root . '/readme.txt');
	$readme_header = preg_split('/\R[ \t]*\R/', $readme, 2)[0];
	$version = release_version(release_field($header[1], 'Version'));
	if ('' !== $expected) {
		release_require($version === release_version($expected), 'Tag/source version mismatch.');
	}
	release_require($version === release_version(release_field($readme_header, 'Stable tag')), 'Stable tag/header mismatch.');
	release_require(RELEASE_SLUG === release_field($header[1], 'Text Domain'), 'Text Domain does not match canonical slug.');
	foreach (array('Requires at least', 'Requires PHP') as $field) {
		$requirement = release_version(release_field($header[1], $field), false);
		release_require($requirement === release_version(release_field($readme_header, $field), false), "{$field} header/readme mismatch.");
	}
	$tested = release_version(release_field($readme_header, 'Tested up to'), false);
	release_require(1 === preg_match('/^[0-9]+\.[0-9]+$/D', $tested), 'Tested up to must be major.minor.');
	require_once __DIR__ . '/compatibility-baselines.php';
	$baseline = webmastery_mcp_read_baselines($root . '/.github/compatibility-versions.json');
	$baseline_minor = implode('.', array_slice(explode('.', $baseline['wordpress']), 0, 2));
	release_require(version_compare($tested, $baseline_minor, '>='), 'Tested up to is older than the verified WordPress baseline.');
	release_require(version_compare($tested, release_field($readme_header, 'Requires at least'), '>='), 'Tested up to is below the WordPress requirement.');
	$paragraphs = preg_split('/\R[ \t]*\R/', $readme);
	$description = trim($paragraphs[1] ?? '');
	$description_length = function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description);
	release_require('' !== $description && $description_length <= 150 && !str_starts_with($description, '=='), 'Missing or overlong readme short description.');
	$changelog = release_section($readme, 'Changelog', '/^==[ \t]+(.+?)[ \t]+==[ \t]*$/');
	$notes = release_section($changelog, $version, '/^=[ \t]+(.+?)[ \t]+=[ \t]*$/');
	$upgrade = release_section($readme, 'Upgrade Notice', '/^==[ \t]+(.+?)[ \t]+==[ \t]*$/');
	release_section($upgrade, $version, '/^=[ \t]+(.+?)[ \t]+=[ \t]*$/');
	release_section(release_read($root . '/CHANGELOG.md'), $version, '/^##[ \t]+(.+?)[ \t]*$/');
	return array('version' => $version, 'notes' => $notes . "\n");
}

function release_tree(string $directory): array {
	release_require(is_dir($directory) && !is_link($directory), "Missing/unsafe directory {$directory}");
	$files = array();
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($iterator as $file) {
		release_require(!$file->isLink(), 'Symlink in release tree: ' . $file->getPathname());
		if ($file->isDir()) {
			continue;
		}
		release_require($file->isFile(), 'Non-regular release file.');
		$name = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
		$files[$name] = hash_file('sha256', $file->getPathname());
	}
	ksort($files);
	return $files;
}

function release_source_files(string $root): array {
	$files = array();
	foreach (array(RELEASE_SLUG . '.php', 'readme.txt', 'LICENSE') as $name) {
		release_read($root . '/' . $name);
		$files[$name] = hash_file('sha256', $root . '/' . $name);
	}
	foreach (release_tree($root . '/includes') as $name => $digest) {
		release_require(1 === preg_match('#^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.php$#D', $name), "Unsupported includes file {$name}");
		$files['includes/' . $name] = $digest;
	}
	ksort($files);
	return $files;
}

/** Validate every entry before extraction, including Unix file type attributes. */
function release_zip_files(string $path): array {
	release_read($path);
	$zip = new ZipArchive();
	release_require(true === $zip->open($path, ZipArchive::CHECKCONS), "Invalid ZIP: {$path}");
	$seen = array();
	$files = array();
	try {
		release_require($zip->numFiles > 0 && $zip->numFiles <= 10000, 'Invalid archive entry count.');
		$total_size = 0;
		for ($i = 0; $i < $zip->numFiles; ++$i) {
			$name = $zip->getNameIndex($i);
			release_require(is_string($name) && !isset($seen[strtolower($name)]), 'Duplicate archive entry.');
			$seen[strtolower($name)] = true;
			release_require(!preg_match('#(^/|\\\\|:|[\x00-\x1f]|(?:^|/)\.\.?(?:/|$)|//)#', $name), "Unsafe archive path: {$name}");
			$directory = str_ends_with($name, '/');
			release_require(str_starts_with($name, RELEASE_SLUG . '/'), "Unexpected archive root: {$name}");
			$relative = substr($name, strlen(RELEASE_SLUG) + 1);
			release_require($zip->getExternalAttributesIndex($i, $os, $attributes), 'Missing ZIP entry attributes.');
			$type = ($attributes >> 16) & 0170000;
			release_require(0 === $type || ($directory ? 0040000 : 0100000) === $type, "Non-regular archive entry: {$name}");
			if ($directory) {
				release_require('' === $relative || 1 === preg_match('#^includes/(?:[a-zA-Z0-9_-]+/)*$#D', $relative), "Forbidden archive directory: {$name}");
				continue;
			}
			release_require(in_array($relative, array(RELEASE_SLUG . '.php', 'readme.txt', 'LICENSE'), true) || 1 === preg_match('#^includes/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\.php$#D', $relative), "Forbidden archive file: {$name}");
			$stat = $zip->statIndex($i);
			$total_size += $stat['size'];
			release_require($total_size <= 100 * 1024 * 1024 && 0 === ($stat['encryption_method'] ?? 0), 'Oversized or encrypted release archive.');
			$content = $zip->getFromIndex($i);
			release_require(false !== $content, "Unreadable ZIP entry: {$name}");
			$files[$relative] = hash('sha256', $content);
		}
	} finally {
		$zip->close();
	}
	ksort($files);
	return $files;
}

function release_validate_package(string $root, string $zip, string $expected = ''): array {
	$metadata = release_metadata($root, $expected);
	$source = release_source_files($root);
	if ('' !== $zip) {
		release_require($source === release_zip_files($zip), 'ZIP files/metadata do not exactly match the source allowlist.');
	}
	return $metadata;
}

function release_extract(string $zip, string $target): void {
	release_zip_files($zip);
	release_require(!file_exists($target), "Extraction destination already exists: {$target}");
	release_require(mkdir($target, 0777, true), 'Cannot create extraction destination.');
	$archive = new ZipArchive();
	release_require(true === $archive->open($zip), 'Cannot open validated ZIP.');
	try {
		release_require($archive->extractTo($target), 'Cannot extract validated ZIP.');
	} finally {
		$archive->close();
	}
	release_require(release_zip_files($zip) === release_tree($target . '/' . RELEASE_SLUG), 'Extracted package differs from ZIP.');
}

/** Check host-side runtime bytes, allowing only Docker's empty nested-bind scaffolding. */
function release_validate_runtime_tree(string $directory, string $zip): void {
	$expected = release_zip_files($zip);
	$actual = release_tree($directory);
	foreach (array('scripts/compatibility-baselines.php', 'scripts/compatibility-download.sh', '.github/compatibility-versions.json') as $placeholder) {
		if (isset($actual[$placeholder])) {
			release_require(hash('sha256', '') === $actual[$placeholder], "Nonempty runtime mount placeholder: {$placeholder}");
			unset($actual[$placeholder]);
		}
	}
	release_require($actual === $expected, 'Runtime production files changed or unexpected files appeared after QA.');
	$directories = array_fill_keys(array('tests', 'scripts', '.github', 'e2e-artifacts'), true);
	foreach (array_keys($expected) as $name) {
		$parent = dirname($name);
		while ('.' !== $parent) {
			$directories[$parent] = true;
			$parent = dirname($parent);
		}
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($iterator as $entry) {
		if ($entry->isDir()) {
			$name = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
			release_require(isset($directories[$name]), "Unexpected runtime directory after QA: {$name}");
		}
	}
}

function release_asset_names(string $root): array {
	$names = array();
	foreach (release_tree($root . '/.wordpress-org') as $name => $digest) {
		if (preg_match('/^(?:banner-(?:772x250|1544x500)|icon-(?:128x128|256x256)|screenshot-[1-9][0-9]*)\.(?:png|jpg|gif)$/D', $name) || 'icon.svg' === $name) {
			$names[$name] = $digest;
		} else {
			release_require('logo.png' === $name, "Unsupported listing asset: {$name}");
		}
	}
	release_require(count($names) > 0, 'No supported listing assets.');
	return $names;
}

function release_bundle(string $root, string $zip, string $target, string $sha, string $run): void {
	$metadata = release_validate_package($root, $zip);
	release_require(1 === preg_match('/^[a-f0-9]{40}$/D', $sha) && ctype_digit($run), 'Invalid source SHA/run identity.');
	release_require(!file_exists($target), 'Bundle destination already exists.');
	mkdir($target . '/assets', 0777, true);
	release_require(copy($zip, $target . '/package.zip'), 'Cannot copy original ZIP.');
	file_put_contents($target . '/notes.md', $metadata['notes']);
	foreach (release_asset_names($root) as $name => $digest) {
		release_require(copy($root . '/.wordpress-org/' . $name, $target . '/assets/' . $name), 'Cannot copy listing asset.');
	}
	$manifest = array('schema' => 1, 'source_sha' => $sha, 'run_id' => $run, 'version' => $metadata['version'], 'files' => release_tree($target));
	file_put_contents($target . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function release_verify_bundle(string $root, string $bundle, string $sha, string $run, string $version): void {
	$manifest = json_decode(release_read($bundle . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
	release_require(1 === ($manifest['schema'] ?? null) && $sha === ($manifest['source_sha'] ?? null) && $run === ($manifest['run_id'] ?? null) && $version === ($manifest['version'] ?? null), 'Bundle source/run/version mismatch.');
	$files = release_tree($bundle);
	unset($files['manifest.json']);
	release_require($files === ($manifest['files'] ?? null), 'Artifact checksum/entry mismatch.');
	$metadata = release_validate_package($root, $bundle . '/package.zip', $version);
	release_require($metadata['notes'] === release_read($bundle . '/notes.md'), 'Release notes differ from validated changelog.');
	$expected_files = array('notes.md', 'package.zip');
	foreach (release_asset_names($root) as $name => $hash) {
		$expected_files[] = 'assets/' . $name;
		release_require($hash === ($files['assets/' . $name] ?? null), 'Listing asset differs from source.');
	}
	sort($expected_files);
	release_require($expected_files === array_keys($files), 'Unexpected bundle file.');
}

function release_assert_not_older(string $candidate, array $published): void {
	release_version($candidate);
	foreach ($published as $version) {
		release_version($version);
		release_require(!version_compare($version, $candidate, '>'), "Refusing {$candidate}: newer version {$version} is already published.");
	}
}

/** Recovery never overwrites an existing SVN tag or GitHub release. */
function release_recovery(string $candidate, array $published, bool $svn_exists, bool $svn_matches, bool $github_exists, bool $github_matches): string {
	release_assert_not_older($candidate, $published);
	release_require(!$svn_exists || $svn_matches, 'Existing SVN tag differs from the validated package; never retag.');
	release_require(!$github_exists || $github_matches, 'Existing GitHub release differs from the validated package; never overwrite.');
	release_require(!$github_exists || $svn_exists, 'GitHub release exists without matching SVN tag; manual investigation required.');
	return $github_exists ? 'complete' : ($svn_exists ? 'github-only' : 'deploy');
}

function release_plugin_check_report(string $output): array {
	// PCP 2.1.0 returns this exact English success line, even with strict-json.
	if ('Success: Checks complete. No errors found.' === trim($output)) {
		return array('errors' => 0, 'warnings' => 0, 'findings' => array());
	}
	$findings = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
	release_require(is_array($findings), 'Plugin Check report must be a JSON array.');
	$report = array('errors' => 0, 'warnings' => 0, 'findings' => array());
	foreach ($findings as $finding) {
		release_require($finding instanceof stdClass, 'Malformed Plugin Check finding.');
		foreach (array('file', 'type', 'code', 'message') as $field) {
			release_require(isset($finding->$field) && is_string($finding->$field) && '' !== trim($finding->$field), "Missing Plugin Check {$field}.");
		}
		foreach (array('line', 'column') as $field) {
			release_require(isset($finding->$field) && ((is_int($finding->$field) && $finding->$field >= 0) || (is_string($finding->$field) && ctype_digit($finding->$field))), "Invalid Plugin Check {$field}.");
		}
		release_require(in_array($finding->type, array('ERROR', 'WARNING'), true), 'Unknown Plugin Check finding type.');
		++$report['ERROR' === $finding->type ? 'errors' : 'warnings'];
		$report['findings'][] = get_object_vars($finding);
	}
	return $report;
}
