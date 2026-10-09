<?php

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

// Explicit parent-owned native fixture only. Never run as part of portable QA.
try {
	if ( count( $argv ) !== 3 || ! in_array( $argv[2], array( 'unique', 'unsafe-exec' ), true )
		|| 'Linux' !== PHP_OS_FAMILY || ! function_exists( 'posix_geteuid' ) || posix_geteuid() <= 0
		|| ! Wstm108_HostObservation::enabled( getenv() ) ) {
		throw new RuntimeException( 'Explicit nonroot native fixture and inspection scope required.', 78 );
	}
	$directory = $argv[1];
	$identity = Wstm108_Files::directory( $directory );
	if ( 0040700 !== $identity['mode'] || posix_geteuid() !== $identity['uid']
		|| array( '.', '..' ) !== scandir( $directory ) ) {
		throw new RuntimeException( 'Fresh pre-existing parent-owned private fixture required.', 78 );
	}
	$table = file_get_contents( '/proc/self/mountinfo' );
	$mounts = Wstm108_HostTopology::structural_mounts( $table );
	if ( Wstm108_HostTopology::stacked( $mounts ) ) {
		throw new RuntimeException( 'This definition proves a unique boundary, not stacked acceptance.', 78 );
	}
	$status = Wstm108_KernelMountModel::status( file_get_contents( '/proc/self/status' ) );
	if ( 'unsafe-exec' === $argv[2] ) {
		$groups = posix_getgroups(); sort( $groups, SORT_NUMERIC );
		if ( $status['Pid'] !== getmypid() || 0 !== $status['NoNewPrivs'] || '0000000000000000' === $status['CapBnd']
			|| array_fill( 0, 4, posix_geteuid() ) !== $status['Uid']
			|| array_fill( 0, 4, posix_getegid() ) !== $status['Gid'] || $groups !== $status['Groups'] ) {
			throw new RuntimeException( 'The genuine pre-existing unsafe exec lane is absent.', 78 );
		}
		foreach ( array( 'CapEff', 'CapPrm', 'CapInh', 'CapAmb' ) as $capability ) {
			if ( '0000000000000000' !== $status[ $capability ] ) {
				throw new RuntimeException( 'Unsafe control must isolate the missing exec-acquisition gate.', 78 );
			}
		}
	} else {
		Wstm108_KernelMountModel::safe_exec( $status, posix_geteuid(), posix_getegid(), posix_getgroups() );
	}
	$controller = new Wstm108_HostController( $directory, $identity, getenv() );
	// Exercise the original, source-owned query clock, not an imported deadline.
	$begin = new ReflectionMethod( Wstm108_HostController::class, 'begin_query_pass' );
	$begin->setAccessible( true );
	$begin->invoke( $controller );
	$refusal = null;
	try {
		Wstm108_KernelMounts::visibility( $table );
		Wstm108_KernelMounts::finish_scope();
	} catch ( Wstm108_TopologyRefusal $error ) {
		$refusal = Wstm108_HostTopology::failure_witness( $error );
	}
	$custody = $controller->kernel_custody()['directory'];
	$intents = glob( $custody . '/kernel-*.intent.private.json' );
	if ( ! is_array( $intents ) || 1 !== count( $intents ) ) {
		throw new RuntimeException( 'Native original inventory is not exactly one session.' );
	}
	$intent_original = Wstm108_Files::file( $intents[0] );
	$intent = json_decode( $intent_original['bytes'], true, 32, JSON_THROW_ON_ERROR );
	if ( 'unsafe-exec' === $argv[2] ) {
		if ( array( 'phase' => 'topology', 'reason' => 'native-coordinate-prerequisite' ) !== $refusal
			|| 'failed' !== $intent['state'] || isset( $intent['argv'] ) ) {
			throw new RuntimeException( 'Unsafe pre-exec refusal was not retained before launch.' );
		}
		$stem = substr( $intents[0], 0, -strlen( '.intent.private.json' ) );
		foreach ( array( 'input', 'stdout', 'stderr' ) as $suffix ) {
			if ( '' !== Wstm108_Files::file( $stem . '.' . $suffix . '.private' )['bytes'] ) {
				throw new RuntimeException( 'Unsafe lane has holder transport bytes.' );
			}
		}
		echo "PASS native unsafe-exec boundary (refusal, not admission)\n";
	} else {
		if ( null !== $refusal || 'complete' !== $intent['state'] || 0 !== $intent['exit']
			|| array( 1 => true, 2 => true ) !== $intent['eof'] ) {
			throw new RuntimeException( 'Unique boundary did not complete native credential/fd/receipt checks.', 78 );
		}
		$registry = new ReflectionProperty( Wstm108_HostController::class, 'kernel_captures' );
		$registry->setAccessible( true );
		$captures = $registry->getValue( $controller );
		$stem = basename( $intents[0], '.intent.private.json' );
		if ( array( $stem ) !== array_keys( $captures ) || $intent_original !== $captures[ $stem ]['intent']
			|| array( 'stdout', 'stderr', 'input', 'kernel' ) !== array_keys( $captures[ $stem ]['files'] ) ) {
			throw new RuntimeException( 'Unique boundary did not register the exact completed original capture once.', 78 );
		}
		foreach ( $captures[ $stem ]['files'] as $suffix => $file ) {
			if ( $file !== Wstm108_Files::read_bound( $custody . '/' . $stem . '.' . $suffix . '.private', $file['identity'] ) ) {
				throw new RuntimeException( 'Registered native capture differs from its bound original.', 78 );
			}
		}
		echo "PASS native unique kernel boundary (not Docker or stacked acceptance)\n";
	}
} catch ( Throwable $error ) {
	if ( class_exists( 'Wstm108_KernelMounts', false ) ) {
		try { Wstm108_KernelMounts::fail_scope(); } catch ( Throwable $finalization_error ) {}
	}
	fwrite( STDERR, "BLOCKED native kernel boundary; originals remain private\n" );
	exit( $error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1 );
}
