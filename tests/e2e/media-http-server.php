<?php
/**
 * Disposable loopback HTTP byte fixture. No WordPress bootstrap or public route.
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
if ( count( $argv ) !== 2 ) {
	fwrite( STDERR, "Usage: php media-http-server.php SERVER_JSONL\n" );
	exit( 1 );
}
$log = fopen( $argv[1], 'ab' );
if ( false === $log ) {
	throw new RuntimeException( 'Cannot open server evidence.' );
}
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( false === $server ) {
	throw new RuntimeException( $error );
}
echo stream_socket_get_name( $server, false ) . "\n";
flush();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true );
while ( $client = stream_socket_accept( $server, 30 ) ) {
	$request = fgets( $client );
	if ( false === $request ) {
		fclose( $client );
		continue;
	}
	$path = explode( ' ', $request )[1];
	while ( false !== ( $line = fgets( $client ) ) && "\r\n" !== $line ) {
		// Consume request headers; this server never contacts another service.
	}
	$case = basename( $path, '.png' );
	$body = $png;
	if ( in_array( $case, [ 'oversized', 'no-length', 'dishonest', 'chunked', 'compressed-large', 'stream-isolation', 'nested-large', 'early-same-url-nested', 'limit-error-after-success' ], true ) ) {
		$body .= str_repeat( 'x', 262144 - strlen( $png ) );
	}
	$status  = '200 OK';
	$headers = [ 'Content-Type: application/octet-stream', 'Connection: close' ];
	if ( 'empty' === $case ) {
		$body = '';
	}
	if ( 'non-image' === $case ) {
		$body = 'not an image';
	}
	if ( 'wrong-type' === $case ) {
		$headers[0] = 'Content-Type: text/plain';
	}
	if ( 'error' === $case ) {
		$status = '500 Fixture Error';
	}
	if ( 'partial' === $case ) {
		$status = '206 Partial Content';
	}
	if ( in_array( $case, [ 'redirect', 'private-redirect', 'oversized-redirect' ], true ) ) {
		$status    = '302 Found';
		$target = 'oversized-redirect' === $case ? 'oversized.png' : 'valid.png';
		$headers[] = 'Location: ' . ( 'private-redirect' !== $case ? 'http://93.184.215.14/' . $target : 'http://127.0.0.1/never-request.png' );
		$body      = '';
	}
	if ( 'compressed-large' === $case || 'compressed-small' === $case ) {
		$body      = gzencode( $body );
		$headers[] = 'Content-Encoding: gzip';
	}
	if ( 'md5-good' === $case || 'md5-bad' === $case ) {
		$headers[] = 'Content-MD5: ' . base64_encode( md5( 'md5-good' === $case ? $body : 'wrong', true ) );
	}
	if ( 'disposition' === $case ) {
		$headers[] = 'Content-Disposition: attachment; filename=fixture-disposition.png';
	}
	if ( in_array( $case, [ 'chunked', 'chunked-small', 'chunked-small-roomy' ], true ) ) {
		$headers[] = 'Transfer-Encoding: chunked';
		$body      = dechex( strlen( $body ) ) . "\r\n" . $body . "\r\n0\r\n\r\n";
	} elseif ( ! in_array( $case, [ 'no-length', 'limit-error-after-success' ], true ) ) {
		$length = strlen( $body );
		if ( 'dishonest' === $case ) {
			$length += 100;
		} elseif ( 'truncated' === $case ) {
			$length += 10;
		} elseif ( 'short-declared' === $case ) {
			$length = 20;
		}
		$headers[] = 'Content-Length: ' . $length;
	}
	fwrite( $client, "HTTP/1.1 $status\r\n" . implode( "\r\n", $headers ) . "\r\n\r\n" );
	if ( 'timeout' === $case ) {
		usleep( 1500000 );
	}
	$written = 0;
	while ( $written < strlen( $body ) ) {
		// A timeout/closed test client is recorded, rather than mistaken for receipt.
		$count = @fwrite( $client, substr( $body, $written, 4096 ) );
		if ( false === $count || 0 === $count ) {
			break;
		}
		$written += $count;
		if ( strlen( $body ) > 4096 ) {
			usleep( 2000 );
		}
	}
	fclose( $client );
	$row = json_encode( [ 'case' => $case, 'path' => $path, 'body_bytes' => strlen( $body ), 'socket_write_bytes' => $written ], JSON_THROW_ON_ERROR ) . "\n";
	if ( strlen( $row ) !== fwrite( $log, $row ) || ! fflush( $log ) ) {
		throw new RuntimeException( 'Cannot persist server evidence.' );
	}
}
fclose( $server );
fclose( $log );
