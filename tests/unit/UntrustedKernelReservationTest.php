<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

/** Bookkeeping only. Reflected test state neither launches nor proves a holder. */
final class UntrustedKernelReservationTest extends TestCase {
	private array $original = array();
	private Wstm108_HostController $controller;

	private static function property( string $class, string $name ): ReflectionProperty {
		$property = new ReflectionProperty( $class, $name );
		$property->setAccessible( true );
		return $property;
	}

	private static function kernel( string $name, $value ): void {
		self::property( Wstm108_KernelMounts::class, $name )->setValue( null, $value );
	}

	private static function state( string $name ) {
		return self::property( Wstm108_KernelMounts::class, $name )->getValue();
	}

	private function query_count(): int {
		return self::property( Wstm108_HostController::class, 'query_count' )->getValue( $this->controller );
	}

	private function set_count( int $count ): void {
		self::property( Wstm108_HostController::class, 'query_count' )->setValue( $this->controller, $count );
	}

	private function consume(): void {
		$method = new ReflectionMethod( Wstm108_KernelMounts::class, 'consume_launch' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	protected function setUp(): void {
		foreach ( array( 'owner', 'remaining', 'query_phase', 'parent_query_slots', 'collector', 'finisher',
			'session_table', 'collector_deadline', 'helper_reservation_started', 'deadline', 'reads' ) as $name ) {
			$this->original[ $name ] = self::state( $name );
		}
		$this->controller = ( new ReflectionClass( Wstm108_HostController::class ) )->newInstanceWithoutConstructor();
		self::property( Wstm108_HostController::class, 'query_deadline' )->setValue( $this->controller, hrtime( true ) + 120000000000 );
		$this->set_count( 12 );
		self::kernel( 'owner', $this->controller );
		self::kernel( 'remaining', array( 'ns' => 8000000000, 'launches' => 8, 'bytes' => 16777216 ) );
		self::kernel( 'query_phase', true );
		self::kernel( 'parent_query_slots', 0 );
		self::kernel( 'helper_reservation_started', false );
		self::kernel( 'collector', null );
		self::kernel( 'finisher', null );
	}

	protected function tearDown(): void {
		foreach ( $this->original as $name => $value ) { self::kernel( $name, $value ); }
	}

	public function test_helper_and_later_parent_launches_debit_query_slots_exactly_once(): void {
		$this->consume();
		self::assertSame( 13, $this->query_count() );
		$child = Wstm108_KernelMounts::reserve_helper();
		self::assertSame( 4, $child['launches'] );
		self::assertSame( 3, self::state( 'remaining' )['launches'] );
		self::assertSame( 3, self::state( 'parent_query_slots' ) );
		self::assertSame( 20, $this->query_count(), '12 queries plus the total eight-launch allowance, not eight per phase.' );
		self::kernel( 'query_phase', false );
		// Post-capture admission borrows a legitimate new observation clock.
		// It must not debit or revive the now-expired query clock.
		self::property( Wstm108_HostController::class, 'query_deadline' )->setValue( $this->controller, hrtime( true ) - 1 );
		foreach ( array( 2, 1, 0 ) as $left ) {
			$this->consume();
			self::assertSame( $left, self::state( 'parent_query_slots' ) );
			self::assertSame( $left, self::state( 'remaining' )['launches'] );
			self::assertSame( 20, $this->query_count() );
		}
		self::assertSame( 4000000000, self::state( 'remaining' )['ns'] );
		self::assertSame( 8388608, self::state( 'remaining' )['bytes'] );
		$this->expectException( Wstm108_TopologyRefusal::class );
		$this->consume();
	}

	public function test_deleting_parent_reservation_would_exceed_original_sixty_four_slot_limit(): void {
		$this->set_count( 60 );
		try {
			Wstm108_KernelMounts::reserve_helper();
			self::fail( 'Four helper plus four parent slots cannot fit the four remaining original slots.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( 'admission-query-budget', $error->getMessage() );
			self::assertSame( 65, $this->query_count() );
			self::assertSame( 0, self::state( 'parent_query_slots' ), 'A failed reservation grants no post-capture slots.' );
		}
	}

	public function test_exact_sixty_four_slot_boundary_is_not_double_charged_after_capture(): void {
		$this->set_count( 56 );
		Wstm108_KernelMounts::reserve_helper();
		self::assertSame( 64, $this->query_count() );
		self::kernel( 'query_phase', false );
		for ( $i = 0; $i < 4; ++$i ) { $this->consume(); }
		self::assertSame( 64, $this->query_count() );
		self::assertSame( 0, self::state( 'parent_query_slots' ) );
		self::assertSame( 0, self::state( 'remaining' )['launches'] );
	}

	public function test_reservation_cannot_be_repeated_or_created_after_capture(): void {
		Wstm108_KernelMounts::reserve_helper();
		try {
			Wstm108_KernelMounts::reserve_helper();
			self::fail( 'A second reservation must not double-debit or reallocate slots.' );
		} catch ( Wstm108_TopologyRefusal $error ) {
			self::assertSame( 'native-coordinate-prerequisite', $error->reason() );
		}
		self::assertSame( 20, $this->query_count() );
		self::kernel( 'query_phase', false );
		$this->expectException( Wstm108_TopologyRefusal::class );
		Wstm108_KernelMounts::reserve_helper();
	}

	public function test_already_reserved_parent_slots_are_not_debited_twice_even_before_phase_transition(): void {
		$this->set_count( 56 );
		Wstm108_KernelMounts::reserve_helper();
		$this->consume();
		self::assertSame( 64, $this->query_count() );
		self::assertSame( 3, self::state( 'parent_query_slots' ) );
		self::assertSame( 3, self::state( 'remaining' )['launches'] );
	}

	public function test_post_capture_launch_without_upfront_reservation_refuses(): void {
		self::kernel( 'query_phase', false );
		$this->expectException( Wstm108_TopologyRefusal::class );
		$this->expectExceptionMessage( 'native-coordinate-prerequisite' );
		$this->consume();
	}

	public function test_partial_reservation_failure_cannot_charge_a_second_reservation(): void {
		$this->set_count( 60 );
		$failure = null;
		try {
			Wstm108_KernelMounts::reserve_helper();
		} catch ( RuntimeException $error ) {
			$failure = $error;
		}
		self::assertInstanceOf( RuntimeException::class, $failure );
		self::assertStringContainsString( 'admission-query-budget', $failure->getMessage() );
		self::assertSame( 65, $this->query_count() );
		$remaining = self::state( 'remaining' );
		$slots = self::state( 'parent_query_slots' );
		$failure = null;
		try {
			Wstm108_KernelMounts::reserve_helper();
		} catch ( RuntimeException $error ) {
			$failure = $error;
		}
		self::assertSame( 65, $this->query_count(), 'A retry must not debit slot 66 or redistribute the remaining allowance.' );
		self::assertSame( $remaining, self::state( 'remaining' ) );
		self::assertSame( $slots, self::state( 'parent_query_slots' ) );
		self::assertInstanceOf( Wstm108_TopologyRefusal::class, $failure );
		self::assertSame( 'native-coordinate-prerequisite', $failure->reason() );
	}

	public function test_multiple_reservations_interleaved_with_parent_collect_dispatch_are_conserved(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php' );
		$start = strpos( $source, 'private static function collect(' );
		$end = strpos( $source, '$cleanup = static function', $start );
		self::assertNotFalse( $start ); self::assertNotFalse( $end );
		$dispatch = substr( $source, $start, $end - $start );
		self::assertSame( 1, substr_count( $dispatch, 'self::consume_launch();' ) );
		self::assertStringNotContainsString( 'kernel_query_slot()', $dispatch );
		$this->set_count( 56 );
		Wstm108_KernelMounts::reserve_helper();
		self::assertSame( 64, $this->query_count() );
		for ( $left = 4; $left >= 0; --$left ) {
			$remaining = self::state( 'remaining' );
			$failure = null;
			try {
				Wstm108_KernelMounts::reserve_helper();
			} catch ( RuntimeException $error ) {
				$failure = $error;
			}
			self::assertInstanceOf( Wstm108_TopologyRefusal::class, $failure );
			self::assertSame( 'native-coordinate-prerequisite', $failure->reason() );
			self::assertSame( 64, $this->query_count() );
			self::assertSame( $remaining, self::state( 'remaining' ) );
			self::assertSame( $left, self::state( 'parent_query_slots' ) );
			if ( 0 === $left ) { break; }
			if ( 3 === $left ) {
				self::kernel( 'query_phase', false );
				self::property( Wstm108_HostController::class, 'query_deadline' )->setValue( $this->controller, hrtime( true ) - 1 );
			}
			// Exercise the exact private launch engine dispatched above, not a modeled debit.
			$this->consume();
			self::assertSame( 64, $this->query_count() );
			self::assertSame( $left - 1, self::state( 'parent_query_slots' ) );
			self::assertSame( $left - 1, self::state( 'remaining' )['launches'] );
		}
	}

	public function test_once_marker_resets_only_at_a_new_original_controller_query_pass(): void {
		Wstm108_KernelMounts::reserve_helper();
		self::assertTrue( self::state( 'helper_reservation_started' ) );
		self::assertSame( 20, $this->query_count() );
		$begin = new ReflectionMethod( Wstm108_HostController::class, 'begin_query_pass' );
		$begin->setAccessible( true );
		$begin->invoke( $this->controller );
		self::assertFalse( self::state( 'helper_reservation_started' ) );
		self::assertSame( 0, self::state( 'parent_query_slots' ) );
		self::assertSame( 0, $this->query_count() );
		self::assertSame( array( 'ns' => 8000000000, 'launches' => 8, 'bytes' => 16777216 ), self::state( 'remaining' ) );
		Wstm108_KernelMounts::reserve_helper();
		self::assertTrue( self::state( 'helper_reservation_started' ) );
		self::assertSame( 8, $this->query_count(), 'Only the original next pass creates a fresh eight-slot allowance.' );
	}

	public function test_failed_holder_resources_are_disposed_without_static_retention_or_unknown_exit_reap(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php' );
		self::assertStringNotContainsString( '$unreaped', $source );
		self::assertStringContainsString( 'self::consume_launch();', $source );
		$start = strpos( $source, '$cleanup = static function' );
		$end = strpos( $source, 'foreach ( $pipes as $pipe )', $start );
		$disposal = substr( $source, $start, $end - $start );
		self::assertSame( 1, substr_count( $disposal, 'proc_close( $process )' ) );
		self::assertMatchesRegularExpression(
			'~if \( is_array\( \$status \) && ! \$status\[\'running\'\] \) \{\s*\$exit = \$status\[\'exitcode\'\]; proc_close\( \$process \);\s*}~',
			$disposal
		);
		self::assertStringContainsString( '$process = null;', $disposal );
		self::assertStringNotContainsString( 'register_shutdown_function', $source );
		self::assertStringNotContainsString( '__destruct', $source );
	}
}
