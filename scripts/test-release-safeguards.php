<?php

declare(strict_types=1);

require_once __DIR__ . '/release-lib.php';

$repository = dirname(__DIR__);
$work = $repository . '/build/release-tests-' . bin2hex(random_bytes(6));
mkdir($work, 0777, true);

function release_test_remove(string $path): void {
	if (is_dir($path) && !is_link($path)) {
		foreach (new FilesystemIterator($path) as $file) {
			release_test_remove($file->getPathname());
		}
		rmdir($path);
	} else {
		unlink($path);
	}
}

register_shutdown_function(static function () use ($work): void {
	release_test_remove($work);
});

function release_test_copy(string $source, string $target): void {
	if (is_dir($source)) {
		mkdir($target, 0777, true);
		foreach (new FilesystemIterator($source) as $file) {
			release_test_copy($file->getPathname(), $target . '/' . $file->getFilename());
		}
	} else {
		copy($source, $target);
	}
}

function release_test_zip(string $root, string $path, ?callable $mutate = null): void {
	$zip = new ZipArchive();
	release_require(true === $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'Fixture ZIP create failed.');
	foreach (release_source_files($root) as $name => $digest) {
		$zip->addFile($root . '/' . $name, RELEASE_SLUG . '/' . $name);
	}
	if (null !== $mutate) {
		$mutate($zip);
	}
	$zip->close();
}

function release_test_fails(callable $callback, string $message): void {
	try {
		$callback();
	} catch (RuntimeException $error) {
		release_require(str_contains($error->getMessage(), $message), "Wrong rejection: {$error->getMessage()} (expected {$message})");
		return;
	}
	throw new RuntimeException("Expected rejection containing: {$message}");
}

$source = $work . '/source';
mkdir($source . '/.github', 0777, true);
foreach (array(RELEASE_SLUG . '.php', 'readme.txt', 'LICENSE', 'CHANGELOG.md', 'includes', '.wordpress-org') as $name) {
	release_test_copy($repository . '/' . $name, $source . '/' . $name);
}
copy($repository . '/.github/compatibility-versions.json', $source . '/.github/compatibility-versions.json');
$metadata = release_metadata($source);
$version = $metadata['version'];
$zip = $work . '/package.zip';
$tests = 0;

$test = static function (string $name, callable $callback) use (&$tests): void {
	$callback();
	++$tests;
	echo "PASS {$name}\n";
};
$mutate_readme = static function (callable $transform, string $error) use ($source, $zip): void {
	$original = release_read($source . '/readme.txt');
	try {
		file_put_contents($source . '/readme.txt', $transform($original));
		release_test_fails(static fn() => release_validate_package($source, $zip), $error);
	} finally {
		file_put_contents($source . '/readme.txt', $original);
	}
};

$test('valid package and scoped changelog notes', static function () use ($source, $zip, $metadata): void {
	release_test_zip($source, $zip);
	release_require($metadata === release_validate_package($source, $zip), 'Valid metadata mismatch.');
	release_require(!str_contains($metadata['notes'], 'Upgrade Notice'), 'Upgrade text leaked into notes.');
});
foreach (array('v2.5.0', '2.5.0-rc1', '2.5.0junk', '02.5.0', '2.5', '../2.5.0', "2.5.0\n") as $invalid) {
	$test('invalid version ' . json_encode($invalid), static function () use ($invalid): void {
		release_test_fails(static fn() => release_version($invalid), 'Invalid');
	});
}
$test('wrong expected tag', static function () use ($source, $zip): void {
	release_test_fails(static fn() => release_validate_package($source, $zip, '999.0.0'), 'Tag/source');
});
foreach (array('Requires PHP' => '8.1', 'Requires at least' => '6.8', 'Stable tag' => '99.0.0') as $field => $wrong) {
	$test($field . ' mismatch', static function () use ($mutate_readme, $field, $wrong): void {
		$mutate_readme(static fn($text) => preg_replace('/^' . preg_quote($field, '/') . ':.+$/m', "{$field}: {$wrong}", $text), 'mismatch');
	});
}
$test('stale tested baseline compared numerically', static function () use ($mutate_readme): void {
	$mutate_readme(static fn($text) => preg_replace('/^Tested up to:.+$/m', 'Tested up to: 6.10', $text), 'older than');
});
$test('numeric major version comparison', static function (): void {
	release_assert_not_older('10.0.0', array('9.99.99'));
	release_test_fails(static fn() => release_assert_not_older('9.99.99', array('10.0.0')), 'newer version');
});
$test('tested field must be major.minor', static function () use ($mutate_readme): void {
	$mutate_readme(static fn($text) => preg_replace('/^Tested up to:.+$/m', 'Tested up to: 7.0.1', $text), 'major.minor');
});
$test('upgrade-only version cannot supply changelog notes', static function () use ($mutate_readme, $version): void {
	$mutate_readme(static fn($text) => preg_replace('/^= ' . preg_quote($version, '/') . ' =$/m', '= 99.0.0 =', $text, 1), 'exact section');
});
$test('changelog-only version cannot supply upgrade notice', static function () use ($mutate_readme, $version): void {
	$mutate_readme(static fn($text) => str_replace('== Upgrade Notice ==', '== Old Upgrade Notice ==', $text), 'Upgrade Notice');
});
$test('empty and duplicate sections fail', static function (): void {
	release_test_fails(static fn() => release_section("## 2.5.0\n### Added\n\n## 2.4.0\n- Previous", '2.5.0', '/^## (.+)$/'), 'Empty section');
	release_test_fails(static fn() => release_section("= 2.5.0 =\nGood\n= 2.5.0 =\nOther", '2.5.0', '/^= (.+) =$/'), 'one exact section');
	release_test_fails(static fn() => release_section("= 2.5.0 =\n<!--\nTODO\n-->\n* ", '2.5.0', '/^= (.+) =$/'), 'Empty section');
});
$test('CHANGELOG.md exact version required', static function () use ($source, $zip, $version): void {
	$original = release_read($source . '/CHANGELOG.md');
	try {
		file_put_contents($source . '/CHANGELOG.md', str_replace("## {$version}\n", "## {$version}-rc1\n", $original));
		release_test_fails(static fn() => release_validate_package($source, $zip), 'exact section');
	} finally {
		file_put_contents($source . '/CHANGELOG.md', $original);
	}
});
$test('ZIP metadata differs even if source validates', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static fn($archive) => $archive->addFromString(RELEASE_SLUG . '/readme.txt', 'Wrong readme'));
	release_test_fails(static fn() => release_validate_package($source, $zip), 'exactly match');
});
$test('ZIP implementation differs even if metadata matches', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static fn($archive) => $archive->addFromString(RELEASE_SLUG . '/includes/unexpected.php', '<?php'));
	release_test_fails(static fn() => release_validate_package($source, $zip), 'exactly match');
});
$test('missing required package file', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static fn($archive) => $archive->deleteName(RELEASE_SLUG . '/LICENSE'));
	release_test_fails(static fn() => release_validate_package($source, $zip), 'exactly match');
});
foreach (array('README.md', '.github/workflow.yml', 'includes/file.txt', 'assets/icon.png', 'vendor/lib.php', 'includes/.hidden.php') as $forbidden) {
	$test('forbidden archive entry ' . $forbidden, static function () use ($source, $zip, $forbidden): void {
		release_test_zip($source, $zip, static fn($archive) => $archive->addFromString(RELEASE_SLUG . '/' . $forbidden, 'forbidden'));
		release_test_fails(static fn() => release_validate_package($source, $zip), 'Forbidden');
	});
}
foreach (array('../escape.php', '/absolute.php', RELEASE_SLUG . '/../escape.php', RELEASE_SLUG . '/includes\\escape.php', 'C:/escape.php', RELEASE_SLUG . '//readme.txt') as $unsafe) {
	$test('unsafe archive path ' . $unsafe, static function () use ($source, $zip, $unsafe): void {
		release_test_zip($source, $zip, static fn($archive) => $archive->addFromString($unsafe, 'unsafe'));
		release_test_fails(static fn() => release_validate_package($source, $zip), 'Unsafe');
	});
}
$test('wrong archive root', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static fn($archive) => $archive->addFromString('another-plugin/file.php', 'wrong'));
	release_test_fails(static fn() => release_validate_package($source, $zip), 'archive root');
});
$test('case-colliding archive entries', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static fn($archive) => $archive->addFromString(RELEASE_SLUG . '/README.TXT', 'duplicate'));
	release_test_fails(static fn() => release_validate_package($source, $zip), 'Duplicate');
});
$test('ZIP symlink rejected before extraction', static function () use ($source, $zip): void {
	release_test_zip($source, $zip, static function ($archive): void {
		$archive->addFromString(RELEASE_SLUG . '/includes/link.php', '../../escape.php');
		$archive->setExternalAttributesName(RELEASE_SLUG . '/includes/link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
	});
	release_test_fails(static fn() => release_validate_package($source, $zip), 'Non-regular');
});
$test('invalid ZIP bytes', static function () use ($source, $zip): void {
	file_put_contents($zip, 'not a ZIP');
	release_test_fails(static fn() => release_validate_package($source, $zip), 'Invalid ZIP');
});
$sha = str_repeat('a', 40);
$bundle = $work . '/bundle';
$test('sealed original ZIP, notes, assets and extraction', static function () use ($source, $zip, $bundle, $sha, $version, $work): void {
	release_test_zip($source, $zip);
	release_bundle($source, $zip, $bundle, $sha, '123');
	release_verify_bundle($source, $bundle, $sha, '123', $version);
	release_require(!file_exists($bundle . '/assets/logo.png'), 'Unsupported logo transferred.');
	release_require(hash_file('sha256', $zip) === hash_file('sha256', $bundle . '/package.zip'), 'Original ZIP was rebuilt.');
	release_extract($bundle . '/package.zip', $work . '/extracted');
	release_require(release_tree($work . '/extracted/' . RELEASE_SLUG) === release_zip_files($zip), 'Extraction differs.');
});
$test('artifact checksum tampering', static function () use ($source, $bundle, $sha, $version): void {
	$original = release_read($bundle . '/notes.md');
	try {
		file_put_contents($bundle . '/notes.md', 'tampered');
		release_test_fails(static fn() => release_verify_bundle($source, $bundle, $sha, '123', $version), 'checksum');
	} finally {
		file_put_contents($bundle . '/notes.md', $original);
	}
});
$test('run identity, source identity and extra artifact files', static function () use ($source, $bundle, $sha, $version): void {
	release_test_fails(static fn() => release_verify_bundle($source, $bundle, $sha, '124', $version), 'source/run/version');
	release_test_fails(static fn() => release_verify_bundle($source, $bundle, str_repeat('b', 40), '123', $version), 'source/run/version');
	file_put_contents($bundle . '/unexpected', 'extra');
	release_test_fails(static fn() => release_verify_bundle($source, $bundle, $sha, '123', $version), 'checksum');
	unlink($bundle . '/unexpected');
});
$test('tampered ZIP with a rewritten digest still fails source comparison', static function () use ($source, $bundle, $sha, $version): void {
	$original_manifest = release_read($bundle . '/manifest.json');
	$original_zip = release_read($bundle . '/package.zip');
	try {
		release_test_zip($source, $bundle . '/package.zip', static fn($archive) => $archive->addFromString(RELEASE_SLUG . '/readme.txt', 'modified'));
		$manifest = json_decode($original_manifest, true, 512, JSON_THROW_ON_ERROR);
		$manifest['files']['package.zip'] = hash_file('sha256', $bundle . '/package.zip');
		file_put_contents($bundle . '/manifest.json', json_encode($manifest));
		release_test_fails(static fn() => release_verify_bundle($source, $bundle, $sha, '123', $version), 'exactly match');
	} finally {
		file_put_contents($bundle . '/manifest.json', $original_manifest);
		file_put_contents($bundle . '/package.zip', $original_zip);
	}
});
$test('public/SVN/GitHub observation parser fails closed and compares every page', static function () use ($work, $repository): void {
	$svn = $work . '/svn.xml';
	$github = $work . '/github.json';
	$listing = $work . '/listing.json';
	file_put_contents($svn, '<lists><list><entry kind="dir"><name>2.4.0</name></entry></list></lists>');
	file_put_contents($github, '[[]]');
	file_put_contents($listing, json_encode(array('slug' => RELEASE_SLUG, 'version' => '2.4.0')));
	$command = implode(' ', array_map('escapeshellarg', array(PHP_BINARY, $repository . '/scripts/release-tools.php', 'state', '2.5.0', $svn, $github, $listing)));
	exec($command . ' 2>&1', $output, $status);
	release_require(0 === $status && in_array('svn_exists=false', $output, true) && in_array('github_exists=false', $output, true), 'Valid observation failed.');
	file_put_contents($github, '[[],[{"tag_name":"v2.6.0","draft":false,"prerelease":false}]]');
	exec($command . ' 2>&1', $output, $status);
	release_require(1 === $status, 'Newer GitHub release on second page accepted.');
	file_put_contents($github, '[[]]');
	file_put_contents($listing, json_encode(array('slug' => RELEASE_SLUG, 'version' => '2.6.0')));
	exec($command . ' 2>&1', $output, $status);
	release_require(1 === $status, 'Newer public release accepted.');
	file_put_contents($listing, json_encode(array('slug' => RELEASE_SLUG, 'version' => '2.4.0')));
	file_put_contents($svn, '<lists><list><entry kind="dir"><name>2.6.0</name></entry></list></lists>');
	exec($command . ' 2>&1', $output, $status);
	release_require(1 === $status, 'Newer SVN tag accepted.');
	file_put_contents($svn, '<lists><list><entry kind="dir"><name>2.5.0</name></entry></list></lists>');
	file_put_contents($github, '[[{"tag_name":"v2.5.0","draft":false,"prerelease":false}]]');
	exec($command . ' 2>&1', $output, $status);
	release_require(0 === $status && in_array('svn_exists=true', $output, true) && in_array('github_exists=true', $output, true), 'Existing release was not detected.');
	file_put_contents($github, '{"message":"API unavailable"}');
	exec($command . ' 2>&1', $output, $status);
	release_require(1 === $status, 'Malformed API response accepted.');
});
$test('recovery decisions: fresh, SVN-only, complete, mismatches and stale queued version', static function (): void {
	release_require('deploy' === release_recovery('2.5.0', array('2.4.0'), false, false, false, false), 'Fresh decision.');
	release_require('github-only' === release_recovery('2.5.0', array('2.5.0'), true, true, false, false), 'SVN-success recovery.');
	release_require('complete' === release_recovery('2.5.0', array('2.5.0'), true, true, true, true), 'Duplicate decision.');
	release_test_fails(static fn() => release_recovery('2.5.0', array(), true, false, false, false), 'SVN tag differs');
	release_test_fails(static fn() => release_recovery('2.5.0', array(), true, true, true, false), 'GitHub release differs');
	release_test_fails(static fn() => release_recovery('2.5.0', array(), false, false, true, true), 'without matching SVN');
	release_test_fails(static fn() => release_recovery('2.5.0', array('2.6.0'), true, true, false, false), 'newer version');
});
echo "PASS {$tests} release safeguard tests\n";
