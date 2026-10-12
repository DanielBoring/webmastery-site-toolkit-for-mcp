<?php

declare(strict_types=1);

require_once __DIR__ . '/docker-qa-gate.php';

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$passed = count( $argv ) === 4 && docker_qa_gate_passes( $argv[1], $argv[2], $argv[3], $argv[3] );
	fwrite( $passed ? STDOUT : STDERR, $passed ? "QA check gate passed.\n" : "QA check gate failed: detection or required execution incomplete.\n" );
	exit( $passed ? 0 : 1 );
}
