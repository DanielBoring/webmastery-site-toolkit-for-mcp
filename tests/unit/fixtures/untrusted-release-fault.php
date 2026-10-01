<?php

declare(strict_types=1);

require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-authority.php';

if ( '1' !== getenv( 'WSTM108_MOCK_ONLY' ) || 'Windows' === PHP_OS_FAMILY ) { throw new RuntimeException( 'Explicit native mock only.' ); }
$state = json_decode( base64_decode( (string) getenv( 'WSTM108_HOST_STATE' ), true ), true, 512, JSON_THROW_ON_ERROR );
$host = Wstm108_HostAuthority::resume( $state['host'] )->handle();
$live = (string) getenv( 'WSTM108_MOCK_LIVE' );
Wstm108_Files::directory( $live );
$companion = $host['checkout'] . '/build/wstm116-retention-' . $host['binding']['project'] . '-wstm108-' . $host['binding']['owner'];
switch ( $argv[1] ?? '' ) {
	case 'companion-partial':
		// A synthetic partial predecessor forces exclusive creation to refuse.
		$file = Wstm108_Files::create( $companion, 'partial companion bytes' );
		Wstm108_Files::create( $live . '/injected-companion.private.json', json_encode( $file, JSON_THROW_ON_ERROR ) );
		break;
	case 'authorization-collision':
		Wstm108_Files::create( $host['directory'] . '/' . Wstm108_ReleaseGuard::AUTHORIZATION, 'preexisting receipt bytes must survive' );
		break;
	case 'precommit-evidence':
		$paths = glob( $host['checkout'] . '/e2e-artifacts/untrusted-' . $host['binding']['owner'] . '/stage.log' );
		if ( 1 !== count( $paths ) ) { throw new RuntimeException( 'Exact owned mock log required.' ); }
		$file = Wstm108_Files::file( $paths[0] );
		Wstm108_Files::update( $paths[0], $file, $file['bytes'] . "injected precommit evidence drift\n" );
		break;
	case 'companion-replacement':
		$file = Wstm108_Files::file( $companion );
		Wstm108_Files::assert_file( $companion, $state['companion']['companion'] );
		if ( ! rename( $companion, $live . '/retained-original-companion' ) ) { throw new RuntimeException( 'Cannot retain fixture original.' ); }
		$replacement = Wstm108_Files::create( $companion, $file['bytes'] );
		Wstm108_Files::create( $live . '/injected-companion.private.json', json_encode( $replacement, JSON_THROW_ON_ERROR ) );
		break;
	default:
		throw new RuntimeException( 'Unknown bounded release fault.' );
}
