<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-authority.php';
require_once dirname( __DIR__, 2 ) . '/scripts/untrusted-kernel-mounts.php';

/** Private owner bookkeeping only; no holder, originals or live proof is created. */
final class UntrustedKernelRecordOwnerTest extends TestCase {
	private Wstm108_HostController $controller;
	private $original_owner;
	private array $custody = array( 'directory' => '/bookkeeping-only', 'identity' => array() );
	private string $stem = 'kernel-11111111111111111111111111111111';

	private static function property( string $class, string $name ): ReflectionProperty {
		$property = new ReflectionProperty( $class, $name );
		$property->setAccessible( true );
		return $property;
	}

	protected function setUp(): void {
		$owner = self::property( Wstm108_KernelMounts::class, 'owner' );
		$this->original_owner = $owner->getValue();
		$this->controller = ( new ReflectionClass( Wstm108_HostController::class ) )->newInstanceWithoutConstructor();
		self::property( Wstm108_HostController::class, 'kernel_directory' )->setValue( $this->controller, $this->custody );
		$owner->setValue( null, $this->controller );
	}

	protected function tearDown(): void {
		self::property( Wstm108_KernelMounts::class, 'owner' )->setValue( null, $this->original_owner );
	}

	private function record( string $stem, array $custody ): void {
		$method = new ReflectionMethod( Wstm108_KernelMounts::class, 'record_completed_capture' );
		$method->setAccessible( true );
		$method->invoke( null, $stem, $custody, array(), array() );
	}

	private function captures(): array {
		return self::property( Wstm108_HostController::class, 'kernel_captures' )->getValue( $this->controller );
	}

	private function refuses( callable $call ): void {
		$before = $this->captures();
		try {
			$call();
			self::fail( 'Only the named private owner may register the original capture once.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'WSTM108 controller refused: kernel-mount-record-owner', $error->getMessage() );
			self::assertSame( $before, $this->captures() );
		}
	}

	public function test_named_private_owner_records_bookkeeping_without_creating_a_live_session(): void {
		$method = new ReflectionMethod( Wstm108_KernelMounts::class, 'record_completed_capture' );
		self::assertTrue( $method->isPrivate() );
		self::assertTrue( $method->isStatic() );
		$before = array();
		foreach ( array( 'collector', 'finisher', 'session_table' ) as $name ) {
			$before[ $name ] = self::property( Wstm108_KernelMounts::class, $name )->getValue();
		}
		$this->record( $this->stem, $this->custody );
		self::assertSame( array( $this->stem => array( 'files' => array(), 'intent' => array() ) ), $this->captures() );
		foreach ( $before as $name => $value ) {
			self::assertSame( $value, self::property( Wstm108_KernelMounts::class, $name )->getValue() );
		}
	}

	public function test_external_receipt_data_cannot_register_an_owner_capture(): void {
		$this->refuses( function (): void {
			$this->controller->kernel_record( $this->stem, $this->custody, array(),
				array( 'bytes' => '{"state":"complete","exit":0}', 'caller' => 'record_completed_capture' ) );
		} );
	}

	public function test_kernel_scoped_closure_is_not_an_allowed_owner_on_any_php_version(): void {
		$controller = $this->controller; $stem = $this->stem; $custody = $this->custody;
		$call = Closure::bind( static function () use ( $controller, $stem, $custody ): void {
			$controller->kernel_record( $stem, $custody, array(), array() );
		}, null, Wstm108_KernelMounts::class );
		self::assertInstanceOf( Closure::class, $call );
		$this->refuses( $call );
	}

	public function test_named_owner_still_refuses_changed_custody_invalid_stem_and_repeat_registration(): void {
		$changed = $this->custody; $changed['directory'] .= '-changed';
		$this->refuses( fn() => $this->record( $this->stem, $changed ) );
		$this->refuses( fn() => $this->record( 'kernel-invalid', $this->custody ) );
		$this->record( $this->stem, $this->custody );
		$this->refuses( fn() => $this->record( $this->stem, $this->custody ) );
		self::assertCount( 1, $this->captures() );
	}

	public function test_registration_retains_finalization_and_cleanup_order_without_closure_name_matching(): void {
		$root = dirname( __DIR__, 2 );
		$source = str_replace( "\r\n", "\n", file_get_contents( $root . '/scripts/untrusted-kernel-mounts.php' ) );
		$markers = array(
			'$exit = $status[\'exitcode\'];',
			'self::require( 0 === $exit );',
			"'state' => 'complete'",
			'self::sync( $intent_path, $intent );',
			'self::require( hrtime( true ) < $deadline && $total <= self::$remaining[\'bytes\'] );',
			'self::record_completed_capture( $stem, $custody, $files, $intent );',
			'$complete = true;',
			'} finally { $complete = $cleanup( $complete ); }',
		);
		$offset = 0;
		foreach ( $markers as $marker ) {
			$position = strpos( $source, $marker, $offset );
			self::assertNotFalse( $position, $marker );
			$offset = $position + strlen( $marker );
		}
		self::assertSame( 1, substr_count( $source, 'self::$owner->kernel_record( $stem, $custody, $files, $intent );' ) );
		$controller = str_replace( "\r\n", "\n", file_get_contents( $root . '/scripts/untrusted-host-controller.php' ) );
		$start = strpos( $controller, 'public function kernel_record(' );
		$end = strpos( $controller, "\n\tprivate function action(", $start );
		self::assertNotFalse( $start ); self::assertNotFalse( $end );
		$guard = substr( $controller, $start, $end - $start );
		self::assertStringContainsString( "'Wstm108_KernelMounts' === ( \$caller['class'] ?? null ) && 'record_completed_capture' === ( \$caller['function'] ?? null )", $guard );
		self::assertStringNotContainsString( '{closure', $guard );
		self::assertStringNotContainsString( 'in_array', $guard );
		self::assertStringNotContainsString( 'preg_match', substr( $guard, 0, strpos( $guard, '&& $custody' ) ) );
	}

	public function test_native_unique_definition_checks_registered_originals_after_genuine_finalization(): void {
		$native = file_get_contents( dirname( __DIR__, 2 ) . '/tests/native/kernel-mount-boundary.php' );
		$markers = array(
			"if ( null !== \$refusal || 'complete' !== \$intent['state']",
			"new ReflectionProperty( Wstm108_HostController::class, 'kernel_captures' )",
			"\$intent_original !== \$captures[ \$stem ]['intent']",
			"Wstm108_Files::read_bound( \$custody . '/' . \$stem . '.' . \$suffix . '.private', \$file['identity'] )",
			'echo "PASS native unique kernel boundary',
		);
		$offset = 0;
		foreach ( $markers as $marker ) {
			$position = strpos( $native, $marker, $offset );
			self::assertNotFalse( $position, $marker );
			$offset = $position + strlen( $marker );
		}
		self::assertStringContainsString( 'posix_geteuid() <= 0', $native );
		self::assertStringContainsString( 'Wstm108_KernelMounts::finish_scope();', $native );
	}
}
