<?php

declare(strict_types=1);

// Test-only observer: the real controller entry, not this hook, owns INI setup.
register_shutdown_function( static function (): void {
	try {
		Wstm108_HostTopology::structural_mounts( "1 0 0:1 /../PRIVATE_SENTINEL / rw - tmpfs tmpfs rw\n" );
	} catch ( Wstm108_TopologyRefusal $error ) {
		$property = new ReflectionProperty( Wstm108_AdmissionCallsite::class, 'enabled' );
		$property->setAccessible( true );
		$has_arguments = false;
		foreach ( $error->getTrace() as $frame ) {
			$has_arguments = $has_arguments || array_key_exists( 'args', $frame );
		}
		require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-kernel-mount-model.php';
		require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-kernel-mounts.php';
		$sites = array();
		$row = array( 'id' => 1, 'parent' => 0, 'device' => '0:1', 'root' => str_repeat( '/a', 2050 ), 'point' => '/bound', 'type' => 'tmpfs' );
		$held = new ReflectionMethod( Wstm108_KernelMounts::class, 'held' ); $held->setAccessible( true );
		foreach ( array(
			static fn() => Wstm108_KernelMountModel::coordinate( '/bound/file', array( $row ), 1, '0:1' ),
			static fn() => $held->invoke( null, 1, 3, '/../PRIVATE_SENTINEL', static function (): void {} ),
			static fn() => Wstm108_KernelMounts::visibility( "1 0 0:1 / / rw - tmpfs tmpfs rw\n" ),
		) as $call ) {
			try { $call(); } catch ( Wstm108_TopologyRefusal $model_error ) {
				$sites[] = Wstm108_AdmissionCallsite::identify( $model_error );
			}
		}
		$setting = function_exists( 'ini_get' ) ? ini_get( 'zend.exception_ignore_args' ) : 'unavailable';
		echo json_encode( array( $property->getValue(), $setting,
			Wstm108_AdmissionCallsite::identify( $error ), $has_arguments, $sites ), JSON_THROW_ON_ERROR ) . "\n";
	}
} );
