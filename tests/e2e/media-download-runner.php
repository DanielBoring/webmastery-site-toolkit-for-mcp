<?php
/**
 * Synthetic real-transport regression evidence, only inside disposable CLI QA.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
if ( count( $argv ) > 2 || ( isset( $argv[1] ) && ! in_array( $argv[1], [ 'baseline', 'fixed' ], true ) ) ) {
	fwrite( STDERR, "Usage: php media-download-runner.php [baseline|fixed]\n" );
	exit( 1 );
}
$mode = $argv[1] ?? 'fixed';
$root = dirname( __DIR__, 2 );
$dir  = $root . '/e2e-artifacts';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) {
	throw new RuntimeException( 'Cannot create evidence directory.' );
}
$tmp = sys_get_temp_dir() . '/wstm112-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $tmp ) ) {
	throw new RuntimeException( 'Cannot create owned temp directory.' );
}
define( 'WP_TEMP_DIR', $tmp );
$wp_root = getenv( 'WSTM_MEDIA_WP_ROOT' ) ?: dirname( $root, 3 );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$ability = wp_get_ability( 'webmastery-site-toolkit-for-mcp/upload-image' );
if ( ! $ability ) {
	throw new RuntimeException( 'Upload ability not registered.' );
}
$server_log = $dir . '/media-server-' . $mode . '.jsonl';
if ( false === file_put_contents( $server_log, '' ) ) {
	throw new RuntimeException( 'Cannot reset server evidence.' );
}
$process = proc_open( [ PHP_BINARY, __DIR__ . '/media-http-server.php', $server_log ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'file', $dir . '/media-server-stderr.log', 'a' ] ], $pipes );
if ( ! is_resource( $process ) ) {
	throw new RuntimeException( 'Cannot start owned HTTP fixture.' );
}
$address = trim( (string) fgets( $pipes[1] ) );
if ( ! preg_match( '/^127\.0\.0\.1:([0-9]+)$/', $address, $matches ) ) {
	proc_terminate( $process );
	throw new RuntimeException( 'HTTP fixture did not announce its loopback port.' );
}
$port = (int) $matches[1];
$author = get_user_by( 'login', 'author_test' );
$subscriber = get_user_by( 'login', 'subscriber_test' );
if ( ! $author || ! $subscriber ) {
	throw new RuntimeException( 'Run the ability manifest fixtures first.' );
}
wp_set_current_user( $author->ID );
$post = wp_insert_post( [ 'post_title' => 'Media download fixture', 'post_status' => 'draft', 'post_author' => $author->ID ], true );
if ( is_wp_error( $post ) ) {
	throw new RuntimeException( $post->get_error_message() );
}
$rows = [];
$failed = 0;
$large_baselines = 0;
$skipped = [];
$limit = 128;
$transport = 'curl';
$case = '';
$files = [];
$peak = 0;
$root_file = null;
$root_peak = 0;
$callback_bytes = 0;
$request_count = 0;
$unrelated_stream = null;
$nested_ok = true;
$success_override = false;
$effective_error_cleanup = false;
$routing_nested = false;
$registered_hooks = new SplObjectStorage();
$transports = [ 'curl' => new WpOrg\Requests\Transport\Curl(), 'fsockopen' => new WpOrg\Requests\Transport\Fsockopen() ];
$capture = static function () use ( &$files, &$peak, &$root_file, &$root_peak ) {
	foreach ( $files as $file ) {
		clearstatcache( true, $file );
		if ( is_file( $file ) ) {
			$peak = max( $peak, filesize( $file ) );
			if ( $file === $root_file ) { $root_peak = max( $root_peak, filesize( $file ) ); }
		}
	}
};
$args_hook = static function ( $args, $url ) use ( &$files, &$case, &$request_count, &$routing_nested, &$nested_ok, &$root_file ) {
	if ( '93.184.215.14' === wp_parse_url( $url, PHP_URL_HOST ) ) {
		++$request_count;
		if ( ! empty( $args['filename'] ) ) { $files[] = $args['filename']; }
		if ( ! $routing_nested && ! empty( $args['filename'] ) ) { $root_file = $args['filename']; }
		if ( 'timeout' === $case ) {
			$args['timeout'] = 0.1;
		}
		if ( 'early-same-url-nested' === $case && ! $routing_nested ) {
			$routing_nested = true;
			$nested = download_url( $url );
			$nested_ok = ! is_wp_error( $nested ) && 262144 === filesize( $nested );
			if ( is_string( $nested ) && is_file( $nested ) ) { unlink( $nested ); }
			$routing_nested = false;
		}
	}
	return $args;
};
$route = static function ( &$url, &$headers, &$data, &$type, &$options ) use ( &$transport, &$progress, $port, $transports, $registered_hooks, &$case, &$unrelated_stream, &$nested_ok, &$routing_nested ) {
	if ( '93.184.215.14' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		throw new RuntimeException( 'Fixture refused to route an unexpected destination.' );
	}
	if ( 80 !== ( wp_parse_url( $url, PHP_URL_PORT ) ?? 80 ) ) {
		throw new RuntimeException( 'Safe HTTP unexpectedly permitted a disallowed fixture port.' );
	}
	$options['transport'] = $transports[ $transport ];
	if ( empty( $options['filename'] ) ) {
		// Core normally creates a transport per request; use that path for nested unstreamed requests.
		$options['transport'] = 'curl' === $transport ? new WpOrg\Requests\Transport\Curl() : new WpOrg\Requests\Transport\Fsockopen();
	}
	if ( ! $registered_hooks->contains( $options['hooks'] ) ) {
		$registered_hooks->attach( $options['hooks'] );
		$options['hooks']->register( 'request.progress', $progress, -10 );
	}
	if ( 'stream-isolation' === $case && ! is_resource( $unrelated_stream ) ) {
		$unrelated_stream = fopen( $options['filename'], 'wb' );
	}
	if ( 'nested' === $case && ! $routing_nested ) {
		$routing_nested = true;
		$nested = wp_safe_remote_get( 'http://93.184.215.14/nested-large.png' );
		$nested_ok = ! is_wp_error( $nested ) && 262144 === strlen( wp_remote_retrieve_body( $nested ) );
		$routing_nested = false;
	}
	$options['hooks']->register( 'curl.before_send', static function ( $handle ) use ( $port ) {
		curl_setopt( $handle, CURLOPT_CONNECT_TO, [ '93.184.215.14:80:127.0.0.1:' . $port ] );
		curl_setopt( $handle, CURLOPT_PROXY, '' );
	} );
	$options['hooks']->register( 'fsockopen.remote_socket', static function ( &$socket ) use ( $port ) {
		$socket = 'tcp://127.0.0.1:' . $port;
	} );
};
$progress = static function ( $bytes ) use ( &$callback_bytes, $capture ) {
	$callback_bytes += strlen( $bytes );
	$capture();
};
$size_filter = static function () use ( &$limit ) { return $limit; };
add_filter( 'upload_size_limit', $size_filter );
add_filter( 'http_request_args', $args_hook, 10, 2 );
add_action( 'requests-requests.before_request', $route, 10, 5 );
add_action( 'http_api_debug', $capture );
$override_cancel = static function ( $handle ) use ( &$case ) {
	if ( 'limit-error-after-success' === $case ) {
		$progress_option = defined( 'CURLOPT_XFERINFOFUNCTION' ) ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
		curl_setopt( $handle, $progress_option, static function () { return 0; } );
	}
};
$override_file = static function ( $response, $context, $class, $args ) use ( &$case, &$success_override ) {
	if ( 'limit-error-after-success' === $case && ! is_wp_error( $response ) ) {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true );
		if ( 68 !== file_put_contents( $args['filename'], $png ) ) { throw new RuntimeException( 'Cannot inject successful core download.' ); }
		$success_override = true;
	}
};
$observe_delete = static function ( $file ) use ( &$case, &$root_file, &$effective_error_cleanup ) {
	if ( 'limit-error-after-success' === $case && dirname( $root_file ) === dirname( $file ) ) { $effective_error_cleanup = true; }
	return $file;
};
add_action( 'http_api_curl', $override_cancel );
add_action( 'http_api_debug', $override_file, 5, 4 );
add_filter( 'wp_delete_file', $observe_delete );
$scenarios = [
	'valid' => '', 'exact' => '', 'wrong-type' => '', 'md5-good' => '', 'disposition' => '',
	'redirect' => '', 'private-redirect' => 'download_failed', 'oversized' => 'file_too_large', 'after-cancel' => '',
	'oversized-redirect' => 'file_too_large', 'stream-isolation' => 'file_too_large', 'nested' => '',
	'no-length' => 'file_too_large', 'dishonest' => 'file_too_large', 'chunked' => 'file_too_large',
	'chunked-small' => '', 'chunked-small-roomy' => '', 'early-same-url-nested' => 'file_too_large',
	'limit-error-after-success' => 'file_too_large',
	'compressed-large' => 'file_too_large', 'compressed-small' => '',
	'empty' => 'invalid_file', 'non-image' => 'unsupported_mime_type', 'error' => 'download_failed',
	'partial' => 'download_failed', 'md5-bad' => 'download_failed', 'truncated' => 'download_failed',
	'short-declared' => 'invalid_file', 'timeout' => 'download_failed',
	'sideload-failure' => 'upload_failed', 'temp-failure' => 'download_failed',
	'subscriber' => 'ability_invalid_permissions', 'missing-post' => 'ability_invalid_permissions', 'missing-featured-post' => 'missing_post_id',
	'forbidden-post' => 'ability_invalid_permissions', 'private-url' => 'invalid_url', 'zero-limit' => 'invalid_upload_limit',
	'invalid-limit' => 'invalid_upload_limit', 'overflow-limit' => 'invalid_upload_limit',
	'negative-limit' => 'invalid_upload_limit', 'fractional-limit' => 'invalid_upload_limit', 'boolean-limit' => 'invalid_upload_limit',
	'unsafe-port' => 'download_failed', 'credentials-url' => 'download_failed',
];
try {
	foreach ( [ 'curl', 'fsockopen' ] as $transport ) {
		foreach ( $scenarios as $case => $expected ) {
			$limit = in_array( $case, [ 'exact', 'chunked-small' ], true ) ? 68 : 128;
			if ( 'compressed-large' === $case ) { $limit = 1024; }
			if ( 'fsockopen' === $transport && in_array( $case, [ 'compressed-small', 'compressed-large' ], true ) ) { $expected = 'unsupported_mime_type'; }
			if ( 'fsockopen' === $transport && 'short-declared' === $case ) { $expected = 'download_failed'; }
			if ( 'fsockopen' === $transport && 'chunked-small' === $case ) { $expected = 'file_too_large'; }
			if ( 'fsockopen' === $transport && 'chunked-small-roomy' === $case ) { $expected = 'unsupported_mime_type'; }
			if ( 'zero-limit' === $case ) { $limit = 0; }
			if ( 'invalid-limit' === $case ) { $limit = 'invalid'; }
			if ( 'overflow-limit' === $case ) { $limit = PHP_INT_MAX; }
			if ( 'negative-limit' === $case ) { $limit = -1; }
			if ( 'fractional-limit' === $case ) { $limit = 1.5; }
			if ( 'boolean-limit' === $case ) { $limit = true; }
			if ( 'baseline' === $mode && in_array( $case, [ 'invalid-limit', 'overflow-limit', 'negative-limit', 'fractional-limit', 'boolean-limit' ], true ) ) {
				$skipped[] = [ 'transport' => $transport, 'case' => $case ];
				continue;
			}
			$files = [];
			$peak = $callback_bytes = $request_count = 0;
			$root_peak = 0;
			$root_file = null;
			$nested_ok = true;
			$success_override = $effective_error_cleanup = false;
			wp_set_current_user( 'subscriber' === $case ? $subscriber->ID : $author->ID );
			$before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='attachment'" );
			$thumbnail = get_post_thumbnail_id( $post );
			$input = [
				'image_url' => 'http://93.184.215.14/' . $transport . '/' . $case . '.png', 'post_id' => $post, 'set_featured' => true,
				'title' => 'Bounded fixture', 'caption' => 'Fixture caption', 'alt_text' => 'Fixture alt',
			];
			if ( 'missing-post' === $case ) { $input['post_id'] = PHP_INT_MAX; }
			if ( 'missing-featured-post' === $case ) { unset( $input['post_id'] ); }
			if ( 'private-url' === $case ) { $input['image_url'] = 'http://127.0.0.1/never-request.png'; }
			if ( 'unsafe-port' === $case ) { $input['image_url'] = 'http://93.184.215.14:81/never-request.png'; }
			if ( 'credentials-url' === $case ) { $input['image_url'] = 'http://fixture:fixture@93.184.215.14/never-request.png'; }
			$other_post = 0;
			if ( 'forbidden-post' === $case ) {
				$other_post = wp_insert_post( [ 'post_title' => 'Other author', 'post_author' => 1, 'post_status' => 'draft' ] );
				$input['post_id'] = $other_post;
			}
			$sideload_error = static function ( $file ) { $file['error'] = 'Synthetic sideload failure'; return $file; };
			if ( 'sideload-failure' === $case ) { add_filter( 'wp_handle_sideload_prefilter', $sideload_error ); }
			if ( 'temp-failure' === $case ) {
				rmdir( $tmp );
				if ( false === file_put_contents( $tmp, 'not a directory' ) ) { throw new RuntimeException( 'Cannot inject temp failure.' ); }
			}
			$start = microtime( true );
			$expected_notice = static function ( $trigger, $function ) { return 'WP_Ability::execute' === $function ? false : $trigger; };
			if ( 'ability_invalid_permissions' === $expected ) {
				add_filter( 'doing_it_wrong_trigger_error', $expected_notice, 10, 2 );
			}
			$expected_warnings = [];
			set_error_handler( static function ( $severity, $message ) use ( $case, $tmp, &$expected_warnings ) {
				if ( 'temp-failure' === $case && E_WARNING === $severity && str_contains( $message, 'unlink(' . $tmp . '/' ) ) {
					$expected_warnings[] = 'Core attempted cleanup after synthetic temporary-file creation failure.';
					return true;
				}
				return false;
			} );
			$result = $ability->execute( $input );
			if ( is_wp_error( $result ) && 'ability_callback_exception' === $result->get_error_code() ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			restore_error_handler();
			remove_filter( 'doing_it_wrong_trigger_error', $expected_notice );
			$elapsed = microtime( true ) - $start;
			remove_filter( 'wp_handle_sideload_prefilter', $sideload_error );
			if ( 'temp-failure' === $case ) { unlink( $tmp ); mkdir( $tmp ); }
			$capture();
			$unrelated_alive = 'stream-isolation' !== $case || is_resource( $unrelated_stream );
			if ( is_resource( $unrelated_stream ) ) { fclose( $unrelated_stream ); }
			$unrelated_stream = null;
			$open_output_streams = 0;
			foreach ( get_resources( 'stream' ) as $stream ) {
				$meta = stream_get_meta_data( $stream );
				if ( str_starts_with( $meta['uri'] ?? '', $tmp . '/' ) ) { ++$open_output_streams; }
			}
			$code = is_wp_error( $result ) ? $result->get_error_code() : ( $result['error']['code'] ?? '' );
			$after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='attachment'" );
			$leftovers = glob( $tmp . '/*' );
			$success = '' === $code && true === ( $result['success'] ?? false );
			$passed = $code === $expected && [] === $leftovers && $unrelated_alive && $nested_ok && 0 === $open_output_streams;
			if ( 'curl' === $transport && 'limit-error-after-success' === $case ) {
				$passed = $passed && $success_override && $effective_error_cleanup;
			}
			if ( $success ) {
				$id = $result['data']['id'];
				$passed = $passed && $after === $before + 1 && get_post_thumbnail_id( $post ) === $id
					&& 'Bounded fixture' === $result['data']['title'] && 'Fixture caption' === $result['data']['caption']
					&& 'Fixture alt' === $result['data']['alt_text'] && 'image/png' === $result['data']['mime_type'];
				wp_delete_attachment( $id, true );
			} else {
				$passed = $passed && $before === $after && $thumbnail === get_post_thumbnail_id( $post );
			}
			if ( is_int( $limit ) && $limit > 0 && $limit < PHP_INT_MAX ) {
				$passed = $passed && $root_peak <= $limit + 1;
			}
			if ( in_array( $case, [ 'oversized', 'no-length', 'dishonest', 'chunked' ], true ) ) {
				$passed = $passed && $callback_bytes > $limit && $callback_bytes < 262144;
			}
			$policy_passed = $passed;
			if ( 'baseline' === $mode ) {
				$passed = [] === $leftovers;
				if ( $peak > 129 ) { ++$large_baselines; }
			}
			$row = [ 'transport' => $transport, 'case' => $case, 'passed' => $passed, 'policy_passed' => $policy_passed, 'code' => $code, 'expected' => $expected,
				'upload_limit' => $limit, 'peak_temp_bytes' => $root_peak, 'all_temp_peak_bytes' => $peak, 'progress_callback_bytes' => $callback_bytes,
				'elapsed_seconds' => $elapsed, 'http_requests' => $request_count, 'attachment_delta' => $after - $before, 'temp_leftovers' => count( $leftovers ), 'expected_warnings' => $expected_warnings,
				'unrelated_stream_alive' => $unrelated_alive, 'nested_request_unchanged' => $nested_ok, 'open_output_streams' => $open_output_streams ];
			if ( 'limit-error-after-success' === $case ) {
				$row['synthetic_core_success'] = $success_override;
				$row['effective_error_path_deleted'] = $effective_error_cleanup;
				$row['boundary'] = 'Cleanup-only fault injection: a trusted test hook disables Curl cancellation and substitutes a small completed file.';
			}
			$rows[] = $row;
			echo ( $passed ? 'PASS ' : 'FAIL ' ) . json_encode( $row, JSON_THROW_ON_ERROR ) . "\n";
			if ( ! $passed ) { ++$failed; }
			if ( $other_post ) { wp_delete_post( $other_post, true ); }
		}
	}
} finally {
	remove_filter( 'upload_size_limit', $size_filter );
	remove_filter( 'http_request_args', $args_hook );
	remove_action( 'requests-requests.before_request', $route );
	remove_action( 'http_api_debug', $capture );
	remove_action( 'http_api_curl', $override_cancel );
	remove_action( 'http_api_debug', $override_file, 5 );
	remove_filter( 'wp_delete_file', $observe_delete );
	proc_terminate( $process );
	fclose( $pipes[0] );
	fclose( $pipes[1] );
	proc_close( $process );
	wp_delete_post( $post, true );
	if ( [] === glob( $tmp . '/*' ) ) { rmdir( $tmp ); }
}
if ( 'baseline' === $mode && 0 === $large_baselines ) { ++$failed; }
$server_rows = array_map( static fn( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), file( $server_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
$server_bounds = 0;
foreach ( $server_rows as $server_row ) {
	if ( in_array( $server_row['case'], [ 'oversized', 'no-length', 'dishonest', 'chunked' ], true ) ) {
		++$server_bounds;
		if ( 'fixed' === $mode && $server_row['socket_write_bytes'] >= $server_row['body_bytes'] ) { ++$failed; }
	}
}
if ( $server_bounds < 8 ) { ++$failed; }
$class = new ReflectionClass( 'Webmastery_MCP_Media' );
$summary = [
	'mode' => $mode, 'passed' => count( $rows ) - $failed, 'failed' => $failed, 'baseline_over_limit_cases' => $large_baselines,
	'policy_passed' => count( array_filter( $rows, static fn( $row ) => $row['policy_passed'] ) ),
	'policy_failed' => count( array_filter( $rows, static fn( $row ) => ! $row['policy_passed'] ) ), 'skipped' => $skipped,
	'media_sha256' => hash_file( 'sha256', $class->getFileName() ), 'runner_sha256' => hash_file( 'sha256', __FILE__ ),
	'server_sha256' => hash_file( 'sha256', __DIR__ . '/media-http-server.php' ),
	'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'results' => $rows, 'server_results' => $server_rows,
	'boundary' => 'Real reused core transports, test-only routing of a public literal to an owned loopback server. No pre_http_request mocks or disabled safe URL validation. Curl progress is decoded data; Fsockopen progress includes transfer encoding. Server counts are socket writes, not client receipt. Compression may decode multiple chunks before native cancellation.',
];
$json = json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
if ( strlen( $json ) !== file_put_contents( $dir . '/media-download-' . $mode . '.json', $json ) ) {
	throw new RuntimeException( 'Cannot persist media evidence.' );
}
exit( 0 === $failed ? 0 : 1 );
