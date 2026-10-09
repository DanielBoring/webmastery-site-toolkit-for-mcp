<?php

declare(strict_types=1);

require_once __DIR__ . '/private-custody.php';

release_require('cli' === PHP_SAPI && 1 === $argc, 'Workflow context receipt requires argument-free CLI execution.');
$context = array();
foreach (Wstm_Test_Custody::CONTEXT_FIELDS as $field) {
	$value = getenv('WSTM_CUSTODY_' . strtoupper($field));
	release_require(false !== $value && '' !== $value, 'Missing server workflow context: ' . $field);
	$context[$field] = 'pull_request_head_sha' === $field && 'none' === $value ? null : $value;
}
echo Wstm_Test_Custody::CONTEXT_RECEIPT
	. json_encode(Wstm_Test_Custody::workflow_context($context), JSON_THROW_ON_ERROR) . PHP_EOL;
