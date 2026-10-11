<?php

namespace WstmPackageMountFixture;

final class Fault {
	public static $action = null;
	public static $open = null;
	public static $after = null;
	public static $observe = null;
	public static $events = array();
}

function mkdir($path, $mode = 0777, $recursive = false) {
	if (null !== Fault::$action) {
		$action = Fault::$action;
		Fault::$action = null;
		if (false === $action($path)) {
			return false;
		}
	}
	$result = \mkdir($path, $mode, $recursive);
	Fault::$events[] = array('mkdir', $path);
	if (null !== Fault::$after) {
		(Fault::$after)('mkdir', $path);
	}
	return $result;
}

function fopen($path, $mode) {
	if (null !== Fault::$open) {
		$action = Fault::$open;
		Fault::$open = null;
		if (false === $action($path)) {
			return false;
		}
	}
	$result = \fopen($path, $mode);
	Fault::$events[] = array('fopen', $path);
	if (null !== Fault::$after) {
		(Fault::$after)('fopen', $path);
	}
	return $result;
}

function lstat($path) {
	$result = \lstat($path);
	if (null !== Fault::$observe) {
		(Fault::$observe)($path, $result);
	}
	return $result;
}

$source = file_get_contents(dirname(__DIR__, 3) . '/scripts/release-lib.php');
$source = substr($source, strpos($source, 'const RELEASE_SLUG'));
eval('namespace WstmPackageMountFixture; use FilesystemIterator; use RecursiveDirectoryIterator; use RecursiveIteratorIterator; use RuntimeException; use ZipArchive; ' . $source);
