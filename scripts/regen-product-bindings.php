<?php
/**
 * Regenerate the product source bindings after editing a bound includes/ file.
 *
 * Purpose: tests/unit/fixtures/product-source-bindings.json pins the SHA-256 and
 * git blob SHA-1 of ten includes/ classes (LF-normalized), and
 * product-source-bindings.php seals that JSON with its own SHA-256. The four
 * bindings that tests/unit/fixtures/scheduling-calibration.php repeats as
 * literals must stay equal to the JSON. This script recomputes all of them.
 *
 * Usage (from the repository root):
 *
 *     php scripts/regen-product-bindings.php
 *
 * It prints one "updated <path>" line per binding that changed, then the seal and
 * the synced scheduling file. It exits 1 if the seal line or a scheduling literal
 * cannot be found. It is idempotent: on a tree whose bindings are current it
 * prints no "updated" lines and leaves every file byte-for-byte unchanged.
 *
 * Do not use it to silence a failing binding test without reviewing why the bound
 * source changed. See docs/reference/adding-an-ability.md, "Source bindings".
 */

declare(strict_types=1);

$repository = dirname(__DIR__);
if (getcwd() !== $repository && !chdir($repository)) {
	fwrite(STDERR, "Could not change to the repository root.\n");
	exit(1);
}

$json_path       = 'tests/unit/fixtures/product-source-bindings.json';
$php_path        = 'tests/unit/fixtures/product-source-bindings.php';
$scheduling_path = 'tests/unit/fixtures/scheduling-calibration.php';

function regen_read(string $path): string {
	$contents = file_get_contents($path);
	if (!is_string($contents)) {
		fwrite(STDERR, "Could not read {$path}\n");
		exit(1);
	}
	return $contents;
}

function regen_write(string $path, string $contents): void {
	if (regen_read($path) === $contents) {
		return;
	}
	if (false === file_put_contents($path, $contents)) {
		fwrite(STDERR, "Could not write {$path}\n");
		exit(1);
	}
}

$bindings = json_decode(regen_read($json_path), true, 512, JSON_THROW_ON_ERROR);
foreach ($bindings as $path => $old) {
	$source = str_replace("\r\n", "\n", regen_read($path));
	$bindings[$path] = array(hash('sha256', $source), sha1('blob ' . strlen($source) . "\0" . $source));
	if ($bindings[$path] !== $old) {
		echo "updated {$path}\n";
	}
}
regen_write($json_path, json_encode($bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

// Seal the JSON in the PHP loader.
$seal = hash_file('sha256', $json_path);
$php  = preg_replace("/'[0-9a-f]{64}' !== hash\( 'sha256', \\\$json \)/", "'{$seal}' !== hash( 'sha256', \$json )", regen_read($php_path), -1, $count);
if (!is_string($php) || 1 !== $count) {
	fwrite(STDERR, "Seal line not found in {$php_path}\n");
	exit(1);
}
regen_write($php_path, $php);
echo "seal {$seal}\n";

// scheduling-calibration.php repeats four bindings as literals; keep them equal to the JSON.
$scheduling = regen_read($scheduling_path);
foreach (array('includes/class-posts.php', 'includes/class-custom-post-types.php', 'includes/class-list-query.php', 'includes/class-untrusted.php') as $path) {
	$pattern     = "#'" . preg_quote($path, '#') . "' => array\( '[0-9a-f]{64}', '[0-9a-f]{40}' \)#";
	$replacement = "'{$path}' => array( '{$bindings[$path][0]}', '{$bindings[$path][1]}' )";
	$scheduling  = preg_replace($pattern, $replacement, $scheduling, -1, $count);
	if (!is_string($scheduling) || 1 !== $count) {
		fwrite(STDERR, "Binding literal for {$path} not found in {$scheduling_path}\n");
		exit(1);
	}
}
regen_write($scheduling_path, $scheduling);
echo "synced {$scheduling_path}\n";
