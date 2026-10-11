<?php

declare(strict_types=1);

function wstm_quiet_check(bool $value, string $reason): void {
	if (!$value) { throw new RuntimeException('HTTP-quiet installation refused: ' . $reason . '.'); }
}

function wstm_quiet_path(string $path): string {
	$path = str_replace('\\', '/', $path);
	$path = preg_replace('~^/(?:run/desktop/mnt/host/|host_mnt/)?([a-zA-Z])/~', '$1:/', $path);
	return preg_match('/^[a-zA-Z]:\//', $path) ? strtolower(rtrim($path, '/')) : rtrim($path, '/');
}

function wstm_quiet_validate(array $records, string $mode, array $env): array {
	wstm_quiet_check(in_array($mode, array('original', 'quiet', 'cli', 'stopped-cli', 'restored'), true), 'closed lifecycle mode');
	$project = $env['COMPOSE_PROJECT_NAME'] ?? '';
	$owner = $env['WSTM_QA_OWNER'] ?? '';
	wstm_quiet_check(is_string($project) && 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $project)
		&& is_string($owner) && 1 === preg_match('/^[a-f0-9]{64}$/D', $owner), 'owned project tuple');
	$cli_mode = in_array($mode, array('cli', 'stopped-cli'), true);
	wstm_quiet_check(count($records) === ($cli_mode ? 4 : 3) && array_values($records) === $records, 'closed native inventory');
	list($wp, $db, $network) = $records;
	wstm_quiet_check(($wp['Id'] ?? '') === ($env['WSTM_QUIET_WP'] ?? null)
		&& ($db['Id'] ?? '') === ($env['WSTM_QUIET_DB'] ?? null) && $wp['Id'] !== $db['Id']
		&& ($wp['Config']['Labels']['com.docker.compose.service'] ?? '') === 'wordpress'
		&& ($db['Config']['Labels']['com.docker.compose.service'] ?? '') === 'mysql', 'exact original service identity');
	foreach (array($wp, $db) as $record) {
		wstm_quiet_check(is_array($record) && 1 === preg_match('/^[a-f0-9]{64}$/D', $record['Id'] ?? '')
			&& ($record['Config']['Labels']['io.webmastery.qa.owner'] ?? null) === $owner
			&& ($record['Config']['Labels']['com.docker.compose.project'] ?? null) === $project
			&& false === ($record['HostConfig']['Privileged'] ?? null)
			&& empty($record['HostConfig']['CapAdd'])
			&& array_keys($record['NetworkSettings']['Networks'] ?? array()) === array($project . '_default'),
			'original container custody/topology');
	}
	wstm_quiet_check(($wp['Image'] ?? '') === 'sha256:5cbb0456741c335249cb26406ca8bef1e8919dbc8af1481c81fd7041a4c3b405'
		&& ($db['Image'] ?? '') === 'sha256:a532724022429812ec797c285c1b540a644c15e248579c6bfdf12a8fbaab4964'
		&& ($db['State']['Running'] ?? false) === true
		&& ($db['State']['Health']['Status'] ?? '') === 'healthy', 'native image/database readiness');
	$db_mounts = $db['Mounts'] ?? null;
	wstm_quiet_check(is_array($db_mounts) && array_keys($db_mounts) === array(0)
		&& ($db_mounts[0]['Type'] ?? '') === 'volume'
		&& ($db_mounts[0]['Name'] ?? '') === $project . '_mysql_data'
		&& ($db_mounts[0]['Destination'] ?? '') === '/var/lib/mysql'
		&& ($db_mounts[0]['RW'] ?? null) === true, 'exact owned writable database volume');
	$running = in_array($mode, array('original', 'restored'), true);
	wstm_quiet_check(($wp['State']['Running'] ?? null) === $running, 'HTTP lifecycle state');
	$bindings = $wp['HostConfig']['PortBindings'] ?? array();
	wstm_quiet_check(array_keys($bindings) === array('80/tcp') && count($bindings['80/tcp']) === 1
		&& ($bindings['80/tcp'][0]['HostIp'] ?? '') === '127.0.0.1'
		&& empty($db['HostConfig']['PortBindings']), 'loopback-only HTTP/no database exposure');
	wstm_quiet_check(($network['Name'] ?? '') === $project . '_default'
		&& ($network['Labels']['io.webmastery.qa.owner'] ?? null) === $owner
		&& ($network['Labels']['com.docker.compose.project'] ?? null) === $project, 'owned native network');
	$expected_network = array($db['Id']);
	if ($running) { $expected_network[] = $wp['Id']; }
	if ($cli_mode && 'cli' === $mode) { $expected_network[] = $records[3]['Id'] ?? ''; }
	$observed_network = array_keys($network['Containers'] ?? array());
	sort($expected_network); sort($observed_network);
	wstm_quiet_check($observed_network === $expected_network, 'no concurrent network writer');
	$cwd = $env['WSTM_QUIET_SOURCE_ROOT'] ?? '';
	wstm_quiet_check(is_string($cwd) && '' !== $cwd, 'source cwd identity');
	$plugin = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp';
	$expected_binds = array(
		$plugin => $env['E2E_PACKAGE_ROOT'] ?? $cwd,
		'/qa-parent-support/tests' => $cwd . '/tests',
		'/qa-parent-support/scripts/product-artifacts.php' => $cwd . '/scripts/product-artifacts.php',
	);
	if (isset($env['E2E_PACKAGE_ROOT'])) {
		wstm_quiet_check(isset($env['E2E_CHECKER_ROOT'], $env['E2E_PACKAGE_ZIP']), 'complete package interface');
		$expected_binds += array(
			'/qa-package-pristine' => $env['E2E_CHECKER_ROOT'],
			$plugin . '/tests' => $cwd . '/tests',
			$plugin . '/scripts/compatibility-baselines.php' => $cwd . '/scripts/compatibility-baselines.php',
			$plugin . '/scripts/compatibility-download.sh' => $cwd . '/scripts/compatibility-download.sh',
			$plugin . '/.github/compatibility-versions.json' => $cwd . '/.github/compatibility-versions.json',
		);
	}
	foreach ($expected_binds as $target => $source) {
		if (0 === strpos($source, './')) { $expected_binds[$target] = $cwd . substr($source, 1); }
	}
	$mounts = array(); $args = array();
	foreach ($wp['Mounts'] ?? array() as $mount) {
		$target = $mount['Destination'] ?? '';
		wstm_quiet_check(is_string($target) && !isset($mounts[$target]), 'unique inherited mount');
		$mounts[$target] = $mount;
		if ('bind' === ($mount['Type'] ?? '')) {
			wstm_quiet_check(isset($expected_binds[$target]) && false === ($mount['RW'] ?? null)
				&& wstm_quiet_path($mount['Source']) === wstm_quiet_path($expected_binds[$target])
				&& false === strpos($mount['Source'], ',') && false === strpos($target, ','),
				'exact readonly source/support binding');
			$args[] = '--mount'; $args[] = 'type=bind,source=' . $mount['Source'] . ',target=' . $target . ',readonly';
		} else {
			$suffix = array('/var/www/html' => 'wp_data', '/qa-artifacts' => 'qa_artifacts')[$target] ?? null;
			wstm_quiet_check('volume' === ($mount['Type'] ?? '') && null !== $suffix
				&& ($mount['Name'] ?? '') === $project . '_' . $suffix && true === ($mount['RW'] ?? null),
				'owned installed/artifact volume');
			$args[] = '--mount'; $args[] = 'type=volume,source=' . $mount['Name'] . ',target=' . $target;
		}
	}
	wstm_quiet_check(count($mounts) === count($expected_binds) + 2, 'complete inherited mount domain');
	if ($cli_mode) {
		$cli = $records[3];
		wstm_quiet_check(is_string($cli['Id'] ?? null) && 1 === preg_match('/^[a-f0-9]{64}$/D', $cli['Id'])
			&& $cli['Id'] === ($env['WSTM_QUIET_CLI'] ?? null) && !in_array($cli['Id'], array($wp['Id'], $db['Id']), true)
			&& ($cli['Config']['User'] ?? '') === '33:33' && ($cli['Image'] ?? '') === $wp['Image']
			&& ($cli['Config']['WorkingDir'] ?? '') === '/var/www/html'
			&& ($cli['Config']['Labels']['io.webmastery.qa.owner'] ?? null) === $owner
			&& ($cli['Config']['Labels']['com.docker.compose.project'] ?? null) === $project
			&& ($cli['Config']['Healthcheck']['Test'] ?? null) === array('NONE')
			&& ($cli['Name'] ?? '') === '/' . $project . '-install-cli'
			&& ($cli['State']['Running'] ?? null) === ('cli' === $mode)
			&& true === ($cli['HostConfig']['ReadonlyRootfs'] ?? null)
			&& false === ($cli['HostConfig']['Privileged'] ?? null)
			&& ($cli['HostConfig']['CapDrop'] ?? null) === array('ALL')
			&& empty($cli['HostConfig']['CapAdd'])
			&& ($cli['HostConfig']['SecurityOpt'] ?? null) === array('no-new-privileges')
			&& empty($cli['HostConfig']['PortBindings'])
			&& array_keys($cli['NetworkSettings']['Networks'] ?? array()) === array($project . '_default'),
			'isolated installer UID/root/capability/network policy');
		$variables = array();
		foreach ($cli['Config']['Env'] ?? array() as $entry) {
			$pair = explode('=', $entry, 2);
			wstm_quiet_check(count($pair) === 2 && !isset($variables[$pair[0]]), 'unique installer environment');
			$variables[$pair[0]] = $pair[1];
		}
		foreach (array('WORDPRESS_DB_HOST' => 'mysql', 'WORDPRESS_DB_USER' => 'wp', 'WORDPRESS_DB_NAME' => 'wordpress',
			'WORDPRESS_DB_PASSWORD' => $env['WSTM_QA_DB_PASSWORD'] ?? null, 'E2E_OPT_IN' => '1',
			'WORDPRESS_DEBUG' => 'true', 'WORDPRESS_DEBUG_LOG' => '/var/www/html/wp-content/debug.log',
			'WSTM_E2E_ARTIFACTS_DIR' => '/qa-artifacts', 'WSTM_PRODUCT_CONTEXT' => '/qa-artifacts/context.json',
			'WSTM_INITIAL_SITE_URL' => 'http://localhost', 'WSTM_PRODUCT_RUN' => substr($owner, 0, 32),
			'WSTM_PRODUCT_TOKEN' => $owner) as $name => $value) {
			wstm_quiet_check(is_string($value) && ($variables[$name] ?? null) === $value, 'installer private environment binding');
		}
		$clone = array();
		foreach ($cli['Mounts'] ?? array() as $mount) {
			wstm_quiet_check(is_string($mount['Destination'] ?? null) && !isset($clone[$mount['Destination']]), 'unique installer mount');
			$clone[$mount['Destination']] = $mount;
		}
		wstm_quiet_check(count($clone) === count($mounts), 'installer mount inventory');
		foreach ($mounts as $target => $mount) {
			wstm_quiet_check(isset($clone[$target]) && $clone[$target]['Type'] === $mount['Type']
				&& $clone[$target]['RW'] === $mount['RW']
				&& wstm_quiet_path($clone[$target]['Source']) === wstm_quiet_path($mount['Source']),
				'installer source/volume custody');
		}
	}
	return $args;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	wstm_quiet_check('cli' === PHP_SAPI && 2 === $argc, 'host data-only selector');
	$bytes = stream_get_contents(STDIN, 1048577);
	wstm_quiet_check(is_string($bytes) && strlen($bytes) <= 1048576, 'native inspection byte bound');
	$records = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
	wstm_quiet_check(is_array($records), 'native inspection list');
	$args = wstm_quiet_validate($records, $argv[1], getenv());
	if ('original' === $argv[1]) { echo implode("\0", $args) . "\0"; }
}
