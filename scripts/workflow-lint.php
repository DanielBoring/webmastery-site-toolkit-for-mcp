<?php

declare(strict_types=1);

// Opt-in: these native tools are not dependencies of Composer QA or Docker QA.
$root = dirname(__DIR__);
chdir($root);
$tools = array('actionlint' => '1.7.12', 'shellcheck' => '0.11.0', 'zizmor' => '1.30.1');
$failed = false;
foreach ($tools as $tool => $version) {
	$output = array();
	exec($tool . ' --version 2>&1', $output, $status);
	if (0 !== $status || !preg_match('/(?<![0-9.])' . preg_quote($version, '/') . '(?![0-9.])/', implode("\n", $output))) {
		fwrite(STDERR, "Required tool missing or wrong version: {$tool} {$version}. Install the pinned release on PATH (see .github/workflows/workflow-lint.yml).\n");
		$failed = true;
	}
}
if ($failed) {
	exit(1);
}

$workflows = array_merge(glob('.github/workflows/*.yml'), glob('.github/workflows/*.yaml'));
$shell_scripts = array();
foreach (array('scripts', 'tests') as $directory) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->isFile() && 'sh' === $file->getExtension()) {
			$shell_scripts[] = $file->getPathname();
		}
	}
}
sort($workflows);
sort($shell_scripts);
$quote = static fn(array $paths): string => implode(' ', array_map('escapeshellarg', $paths));
$commands = array(
	'actionlint -color ' . $quote($workflows),
	'shellcheck --external-sources -- ' . $quote($shell_scripts),
	'zizmor --offline --no-progress --format plain ' . $quote($workflows),
);
foreach ($commands as $command) {
	passthru($command, $status);
	$failed = $failed || 0 !== $status;
}
exit($failed ? 1 : 0);
