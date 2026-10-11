<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/qa-http-quiet.php';

final class HttpQuietInstallationTest extends TestCase {
	private function fixture(bool $package, string $mode): array {
		$owner = str_repeat('a', 64); $project = 'wstm-quiet-unit';
		$env = array('COMPOSE_PROJECT_NAME' => $project, 'WSTM_QA_OWNER' => $owner,
			'WSTM_QUIET_WP' => str_repeat('1', 64), 'WSTM_QUIET_DB' => str_repeat('2', 64),
			'WSTM_QUIET_CLI' => str_repeat('3', 64),
			'WSTM_QUIET_SOURCE_ROOT' => '/work/source', 'WSTM_QA_DB_PASSWORD' => str_repeat('b', 64));
		$plugin = '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp';
		$binds = array($plugin => '/work/source', '/qa-parent-support/tests' => '/work/source/tests',
			'/qa-parent-support/scripts/product-artifacts.php' => '/work/source/scripts/product-artifacts.php');
		if ($package) {
			$env += array('E2E_PACKAGE_ROOT' => './build/package', 'E2E_CHECKER_ROOT' => './build/checker', 'E2E_PACKAGE_ZIP' => '/original.zip');
			$binds[$plugin] = '/work/source/build/package';
			$binds += array('/qa-package-pristine' => '/work/source/build/checker',
				$plugin . '/tests' => '/work/source/tests',
				$plugin . '/scripts/compatibility-baselines.php' => '/work/source/scripts/compatibility-baselines.php',
				$plugin . '/scripts/compatibility-download.sh' => '/work/source/scripts/compatibility-download.sh',
				$plugin . '/.github/compatibility-versions.json' => '/work/source/.github/compatibility-versions.json');
		}
		$mounts = array();
		foreach ($binds as $target => $source) {
			$mounts[] = array('Destination' => $target, 'Source' => $source, 'Type' => 'bind', 'RW' => false);
		}
		foreach (array('/var/www/html' => 'wp_data', '/qa-artifacts' => 'qa_artifacts') as $target => $suffix) {
			$mounts[] = array('Destination' => $target, 'Source' => '/var/lib/docker/volumes/' . $project . '_' . $suffix,
				'Name' => $project . '_' . $suffix, 'Type' => 'volume', 'RW' => true);
		}
		$labels = array('io.webmastery.qa.owner' => $owner, 'com.docker.compose.project' => $project);
		$wp = array('Id' => str_repeat('1', 64), 'Image' => 'sha256:5cbb0456741c335249cb26406ca8bef1e8919dbc8af1481c81fd7041a4c3b405',
			'Config' => array('Labels' => $labels), 'HostConfig' => array('Privileged' => false, 'CapAdd' => null,
				'PortBindings' => array('80/tcp' => array(array('HostIp' => '127.0.0.1', 'HostPort' => '0')))),
			'State' => array('Running' => in_array($mode, array('original', 'restored'), true)),
			'NetworkSettings' => array('Networks' => array($project . '_default' => array())), 'Mounts' => $mounts);
		$db = $wp; $db['Id'] = str_repeat('2', 64); $db['Image'] = 'sha256:a532724022429812ec797c285c1b540a644c15e248579c6bfdf12a8fbaab4964';
		$db['Mounts'] = array(array('Type' => 'volume', 'Name' => $project . '_mysql_data',
			'Source' => '/var/lib/docker/volumes/' . $project . '_mysql_data/_data',
			'Destination' => '/var/lib/mysql', 'RW' => true));
		$wp['Config']['Labels']['com.docker.compose.service'] = 'wordpress';
		$db['Config']['Labels']['com.docker.compose.service'] = 'mysql';
		$db['HostConfig']['PortBindings'] = array(); $db['State'] = array('Running' => true, 'Health' => array('Status' => 'healthy'));
		$network = array('Name' => $project . '_default', 'Labels' => $labels, 'Containers' => array($db['Id'] => array()));
		if ($wp['State']['Running']) { $network['Containers'][$wp['Id']] = array(); }
		$records = array($wp, $db, $network);
		if (in_array($mode, array('cli', 'stopped-cli'), true)) {
			$cli = $wp; $cli['Id'] = str_repeat('3', 64); $cli['Name'] = '/' . $project . '-install-cli';
			$cli['State']['Running'] = 'cli' === $mode;
			$cli['Config']['User'] = '33:33'; $cli['Config']['WorkingDir'] = '/var/www/html';
			$cli['Config']['Healthcheck'] = array('Test' => array('NONE'));
			$cli['Config']['Env'] = array('WORDPRESS_DB_HOST=mysql', 'WORDPRESS_DB_USER=wp', 'WORDPRESS_DB_NAME=wordpress',
				'WORDPRESS_DEBUG=true', 'WORDPRESS_DEBUG_LOG=/var/www/html/wp-content/debug.log',
				'WSTM_E2E_ARTIFACTS_DIR=/qa-artifacts', 'WSTM_PRODUCT_CONTEXT=/qa-artifacts/context.json',
				'WORDPRESS_DB_PASSWORD=' . $env['WSTM_QA_DB_PASSWORD'], 'E2E_OPT_IN=1',
				'WSTM_INITIAL_SITE_URL=http://localhost', 'WSTM_PRODUCT_RUN=' . substr($owner, 0, 32), 'WSTM_PRODUCT_TOKEN=' . $owner);
			$cli['HostConfig'] += array('ReadonlyRootfs' => true, 'CapDrop' => array('ALL'), 'SecurityOpt' => array('no-new-privileges'));
			$cli['HostConfig']['PortBindings'] = array();
			$records[] = $cli;
			if ('cli' === $mode) { $records[2]['Containers'][$cli['Id']] = array(); }
		}
		return array($records, $env);
	}

	public function testSourceAndPackageCustodyAcrossAllQuietLifecycleStates(): void {
		foreach (array(false, true) as $package) {
			foreach (array('original', 'quiet', 'cli', 'stopped-cli', 'restored') as $mode) {
				list($records, $env) = $this->fixture($package, $mode);
				$args = wstm_quiet_validate($records, $mode, $env);
				self::assertCount($package ? 20 : 10, $args);
				self::assertContains('--mount', $args);
				foreach ($args as $arg) { self::assertStringNotContainsString('docker.sock', $arg); }
			}
		}
	}

	public function testConcurrentHttpForeignCustodyAndPrivilegeChangesRefuse(): void {
		foreach (array('http', 'foreign-writer', 'image', 'owner', 'source', 'writable-bind', 'root', 'caps', 'nnp', 'dbport', 'environment', 'cwd', 'extra-mount', 'duplicate-mount', 'cli-id', 'healthcheck') as $kind) {
			list($records, $env) = $this->fixture(true, 'cli');
			switch ($kind) {
				case 'http': $records[0]['State']['Running'] = true; break;
				case 'foreign-writer': $records[2]['Containers'][str_repeat('f', 64)] = array(); break;
				case 'image': $records[3]['Image'] = 'foreign'; break;
				case 'owner': $records[0]['Config']['Labels']['io.webmastery.qa.owner'] = str_repeat('e', 64); break;
				case 'source': $records[0]['Mounts'][0]['Source'] = '/foreign/source'; break;
				case 'writable-bind': $records[3]['Mounts'][0]['RW'] = true; break;
				case 'root': $records[3]['Config']['User'] = '0:0'; break;
				case 'caps': $records[3]['HostConfig']['CapAdd'] = array('SYS_ADMIN'); break;
				case 'nnp': $records[3]['HostConfig']['SecurityOpt'] = array(); break;
				case 'dbport': $records[1]['HostConfig']['PortBindings'] = array('3306/tcp' => array()); break;
				case 'environment': $records[3]['Config']['Env'][0] = 'WORDPRESS_DB_HOST=foreign'; break;
				case 'cwd': $records[3]['Config']['WorkingDir'] = '/'; break;
				case 'extra-mount': $records[3]['Mounts'][] = array('Destination' => '/socket', 'Source' => '/docker.sock', 'RW' => true, 'Type' => 'bind'); break;
				case 'duplicate-mount': $records[3]['Mounts'][] = $records[3]['Mounts'][0]; break;
				case 'cli-id': $records[3]['Id'] = $records[0]['Id']; break;
				case 'healthcheck': $records[3]['Config']['Healthcheck']['Test'] = array('CMD', 'curl', 'http://localhost'); break;
			}
			try { wstm_quiet_validate($records, 'cli', $env); self::fail('Unsafe quiet lifecycle accepted.'); }
			catch (RuntimeException $error) { self::assertStringContainsString('HTTP-quiet installation refused', $error->getMessage()); }
		}
	}

	public function testDatabaseRequiresOnlyItsExactOwnedWritableVolume(): void {
		foreach (array(false, true) as $package) {
			foreach (array('original', 'quiet', 'cli', 'stopped-cli', 'restored') as $mode) {
				foreach (array('missing', 'extra', 'duplicate', 'foreign', 'target', 'readonly', 'bind', 'wp-mounts', 'missing-rw', 'rw-type') as $kind) {
					list($records, $env) = $this->fixture($package, $mode);
					switch ($kind) {
						case 'missing': $records[1]['Mounts'] = array(); break;
						case 'extra': $records[1]['Mounts'][] = $records[0]['Mounts'][0]; break;
						case 'duplicate': $records[1]['Mounts'][] = $records[1]['Mounts'][0]; break;
						case 'foreign': $records[1]['Mounts'][0]['Name'] = 'foreign_mysql_data'; break;
						case 'target': $records[1]['Mounts'][0]['Destination'] = '/foreign/mysql'; break;
						case 'readonly': $records[1]['Mounts'][0]['RW'] = false; break;
						case 'bind': $records[1]['Mounts'][0]['Type'] = 'bind'; break;
						case 'wp-mounts': $records[1]['Mounts'] = $records[0]['Mounts']; break;
						case 'missing-rw': unset($records[1]['Mounts'][0]['RW']); break;
						case 'rw-type': $records[1]['Mounts'][0]['RW'] = 'true'; break;
					}
					try { wstm_quiet_validate($records, $mode, $env); self::fail('Invalid database mount accepted.'); }
					catch (RuntimeException $error) {
						self::assertStringContainsString('exact owned writable database volume', $error->getMessage());
					}
				}
			}
		}
	}
}
