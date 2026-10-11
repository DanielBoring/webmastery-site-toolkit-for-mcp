<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/product-live-cleanup.php';
require_once __DIR__ . '/fixtures/product-evidence-model.php';

final class Wstm_Live_Cleanup_Database_Model {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $users = 'wp_users';
	public string $usermeta = 'wp_usermeta';
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $prepared = array();
	public array $queries = array();
	public array $values = array( '0', '0', '0', '0' );
	public ?int $fail_at = null;

	public function prepare( string $sql, ...$args ): string {
		$this->prepared[] = array( $sql, $args );
		return $sql;
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		if ( $this->fail_at === count( $this->queries ) ) { $this->last_error = 'Private SQL failure'; }
		return $this->values[ count( $this->queries ) - 1 ];
	}
}

final class ProductLiveCleanupTest extends TestCase {
	public function testPreAcquisitionReadsEveryNativeSubjectIncludingMetadataAndRefusesEveryNonzeroOrUnreadableValue(): void {
		$db = new Wstm_Live_Cleanup_Database_Model(); $db->values = array_fill( 0, 5, '0' );
		$db->postmeta = 'wp_postmeta';
		$result = Wstm167_Product_Context::pre_acquisition_absence( Wstm_Product_Evidence_Model::context(), $db );
		self::assertSame( array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0, 'postmeta' => 0 ), $result );
		self::assertCount( 5, $db->queries );
		foreach ( range( 0, 4 ) as $subject ) {
			foreach ( array( 1, '1', null, false, '00', '0e0' ) as $value ) {
				$db = new Wstm_Live_Cleanup_Database_Model(); $db->postmeta = 'wp_postmeta';
				$db->values = array_fill( 0, 5, '0' ); $db->values[ $subject ] = $value;
				try {
					Wstm167_Product_Context::pre_acquisition_absence( Wstm_Product_Evidence_Model::context(), $db );
					self::fail( 'Pre-acquisition native absence silently accepted.' );
				} catch ( RuntimeException $error ) { self::assertCount( $subject + 1, $db->queries ); }
			}
		}
	}

	public function testConfigOwnsExactlyOneUnconditionalLiteralTrueCronDefinition(): void {
		foreach ( array( "<?php define('DISABLE_WP_CRON', true);", "<?php define( \"DISABLE_WP_CRON\", TRUE );\n" ) as $bytes ) {
			$this->invoke( 'cron_config', $bytes ); self::addToAssertionCount( 1 );
		}
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/product-live-cleanup.php' );
		self::assertStringNotContainsString( "define( 'DISABLE_WP_CRON', true )", $source );
		self::assertStringContainsString( 'Wstm108_Files::assert_file( $config_path, $config );', $source );
	}

	public static function cronPolicies(): array {
		return array_map( static fn( $bytes ) => array( $bytes ), array(
			'<?php', "<?php define('DISABLE_WP_CRON', false);",
			"<?php define('DISABLE_WP_CRON', 1);", "<?php define('DISABLE_WP_CRON', 'true');",
			"<?php define('DISABLE_WP_CRON', true); define('DISABLE_WP_CRON', true);",
			"<?php if (true) { define('DISABLE_WP_CRON', true); }",
			"<?php if (true) define('DISABLE_WP_CRON', true);",
			"<?php if (false): \$x = 1; define('DISABLE_WP_CRON', true); endif;",
			"<?php \$x = define('DISABLE_WP_CRON', true);",
			"<?php define('DISABLE_WP_CRON', true); \$x = DISABLE_WP_CRON;",
			"<?php define('DISABLE_' . 'WP_CRON', true);",
			"<?php if (false) { if (true) {} define('DISABLE_WP_CRON', true); }",
			"<?php while (false) { if (true) {} define('DISABLE_WP_CRON', true); }",
			"<?php function config_helper() { if (true) {} define('DISABLE_WP_CRON', true); }",
			"<?php class Config { public function helper() { if (true) {} define('DISABLE_WP_CRON', true); } }",
			"<?php namespace Config; define('DISABLE_WP_CRON', true);",
			"<?php namespace Config { if (true) {} define('DISABLE_WP_CRON', true); }",
			"<?php if (false) {} else define('DISABLE_WP_CRON', true);",
			"<?php if (false) {} elseif (true) define('DISABLE_WP_CRON', true);",
			"<?php for (;;) define('DISABLE_WP_CRON', true);",
		) );
	}

	public function testActualRestoredDockerConfigLayoutAcceptsOnlyTheTopLevelCronDefinition(): void {
		$prefix = "<?php\nif (!function_exists('getenv_docker')) {\n"
			. "\tfunction getenv_docker(\$env, \$default) { return getenv(\$env) ?: \$default; }\n}\n";
		$bytes = $prefix . str_repeat("// retained config layout\n", 130 - substr_count($prefix, "\n"))
			. "define( 'DISABLE_WP_CRON', true );\n";
		self::assertSame("define( 'DISABLE_WP_CRON', true );", explode("\n", $bytes)[130]);
		$this->invoke('cron_config', $bytes);
		self::addToAssertionCount(1);
		foreach (array('if (true) {}', 'while (false) {}', 'function config_helper() {}') as $completed) {
			$this->invoke('cron_config', "<?php $completed define('DISABLE_WP_CRON', true);");
			self::addToAssertionCount(1);
		}
	}

	/** @dataProvider cronPolicies */
	public function testMissingFalseDuplicateConditionalOrAmbiguousPolicyRefusesBeforeBootstrap( string $bytes ): void {
		$this->expectException( RuntimeException::class );
		$this->invoke( 'cron_config', $bytes );
	}

	public function testEffectivePolicyAndNoCronWorkAreStrictlyTyped(): void {
		$this->invoke( 'cron_runtime', true, true, false, 0 ); self::addToAssertionCount( 1 );
		foreach ( array( array( false, null, false, 0 ), array( true, false, false, 0 ),
			array( true, 1, false, 0 ), array( true, 'true', false, 0 ),
			array( true, true, true, 0 ), array( true, true, false, 1 ) ) as $args ) {
			try { $this->invoke( 'cron_runtime', ...$args ); self::fail( 'Invalid cron policy/work accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'cron', $error->getMessage() ); }
		}
	}

	private function invoke( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Wstm_Product_Live_Cleanup::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	public function testFixedStageNamesRefuseBeforeOpeningAnyContextOrBootstrapping(): void {
		foreach ( array( 'bounded', 'untrusted' ) as $stage ) { $this->invoke( 'stage', $stage ); self::addToAssertionCount( 1 ); }
		foreach ( array( '', 'destructive', 'Bounded', '../bounded', '/qa-artifacts/foreign', 'bounded ', 'untrusted/extra' ) as $stage ) {
			try { Wstm_Product_Live_Cleanup::run( $stage ); self::fail( 'Foreign stage accepted.' ); }
			catch ( RuntimeException $error ) { self::assertStringContainsString( 'fixed stage selector', $error->getMessage() ); }
		}
	}

	public function testActualBoundedAbsenceMethodUsesFourExactReadonlySubjects(): void {
		$context = Wstm_Product_Evidence_Model::context(); $state = Wstm_Product_Evidence_Model::state( true ); $db = new Wstm_Live_Cleanup_Database_Model();
		$this->invoke( 'bounded_absence', $state, $context, $db );
		self::assertCount( 4, $db->queries );
		self::assertSame( array(
			array( 'SELECT COUNT(*) FROM wp_posts WHERE post_content_filtered=%s', array( $context['token'] ) ),
			array( 'SELECT COUNT(*) FROM wp_users WHERE ID=%d OR user_login=%s', array( 42, $state['namespace'] ) ),
			array( 'SELECT COUNT(*) FROM wp_usermeta WHERE user_id=%d', array( 42 ) ),
			array( 'SELECT COUNT(*) FROM wp_options WHERE option_name=%s', array( $state['namespace'] . '_control' ) ),
		), $db->prepared );
		$db = new Wstm_Live_Cleanup_Database_Model(); $db->values = array( 0, 0, 0, 0 );
		$this->invoke( 'bounded_absence', $state, $context, $db ); self::assertCount( 4, $db->queries );
	}

	public static function nonzeroOrMissing(): array {
		$cases = array();
		foreach ( array( 1, '1', null, false, 0.0, '0.0', '00', '0e0', '-0', array() ) as $index => $value ) {
			foreach ( range( 0, 3 ) as $subject ) { $cases[ $index . ':' . $subject ] = array( $subject, $value ); }
		}
		return $cases;
	}

	/** @dataProvider nonzeroOrMissing */
	public function testEveryNonzeroUnreadableOrMistypedLiveSubjectRefuses( int $subject, $value ): void {
		$db = new Wstm_Live_Cleanup_Database_Model(); $db->values[ $subject ] = $value;
		$this->expectException( RuntimeException::class );
		$this->invoke( 'bounded_absence', Wstm_Product_Evidence_Model::state( true ), Wstm_Product_Evidence_Model::context(), $db );
	}

	public function testDatabaseReadFailuresCannotBeConvertedIntoZeroCounts(): void {
		foreach ( range( 1, 4 ) as $subject ) {
			$db = new Wstm_Live_Cleanup_Database_Model(); $db->fail_at = $subject;
			try {
				$this->invoke( 'bounded_absence', Wstm_Product_Evidence_Model::state( true ), Wstm_Product_Evidence_Model::context(), $db );
				self::fail( 'Database error accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertCount( $subject, $db->queries ); self::assertStringNotContainsString( 'Private SQL failure', $error->getMessage() );
			}
		}
	}

	public function testForeignStateAndTablesRefuseBeforeTheFirstDatabaseRead(): void {
		foreach ( array( 'token', 'namespace', 'cpt', 'actor', 'actor-type', 'table' ) as $change ) {
			$state = Wstm_Product_Evidence_Model::state( true ); $db = new Wstm_Live_Cleanup_Database_Model();
			switch ( $change ) {
				case 'token': $state['token'] = str_repeat( 'd', 64 ); break;
				case 'namespace': $state['namespace'] = 'foreign'; break;
				case 'cpt': $state['cpt'] = 'foreign'; break;
				case 'actor': $state['user'] = 0; break;
				case 'actor-type': $state['user'] = '42'; break;
				case 'table': $db->posts = 'wp_posts; DELETE'; break;
			}
			try { $this->invoke( 'bounded_absence', $state, Wstm_Product_Evidence_Model::context(), $db ); self::fail( 'Foreign subject accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( array(), $db->queries ); self::assertSame( array(), $db->prepared ); }
		}
	}

	public function testEveryEnteredCatalogIsVisitedRegardlessOfFinalVerdictInventory(): void {
		$visited = array(); $db = new Wstm_Live_Cleanup_Database_Model(); $db->values = array_fill( 0, 8, '0' );
		$count = $this->invoke( 'catalogs', array( 'large', 'product-verdict.private.json', 'small' ),
			function ( string $catalog ) use ( &$visited, $db ): void {
				$visited[] = $catalog; $state = Wstm_Product_Evidence_Model::state( 'small' === $catalog );
				Wstm_Product_Evidence_Verifier::state( $state, Wstm_Product_Evidence_Model::context(), 'small' === $catalog );
				$this->invoke( 'bounded_absence', $state, Wstm_Product_Evidence_Model::context(), $db );
			} );
		self::assertSame( 2, $count ); self::assertSame( array( 'small', 'large' ), $visited ); self::assertCount( 8, $db->queries );
	}

	public function testMissingForeignOrUnreadableEnteredCatalogCannotYieldCompleteAbsence(): void {
		foreach ( array( array(), array( 'product-verdict.private.json' ),
			array( 'small', 'product-verdict.private.json', 'foreign' ), array( 'small', 'large' ) ) as $entries ) {
			$visits = array();
			try { $this->invoke( 'catalogs', $entries, static function ( $name ) use ( &$visits ): void { $visits[] = $name; } ); self::fail( 'Unknown catalog accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( array(), $visits ); }
		}
		$this->expectException( RuntimeException::class );
		$this->invoke( 'catalogs', array( 'small', 'large', 'product-verdict.private.json' ), static function (): void {
			throw new RuntimeException( 'Missing, unsafe or unreadable retained final state.' );
		} );
	}

	public function testLiveEntrypointHasNoCleanupRetirementExportOrArbitraryPathParameters(): void {
		$method = new ReflectionMethod( Wstm_Product_Live_Cleanup::class, 'run' ); self::assertSame( 1, $method->getNumberOfParameters() );
		self::assertSame( 'stage', $method->getParameters()[0]->getName() );
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/product-live-cleanup.php' );
		foreach ( array( '->cleanup(', '->retire(', '->stage(', 'file_put_contents(', 'unlink(', 'mkdir(', 'proc_open(', 'shell_exec(' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
		self::assertStringContainsString( '$resources->verify_absent();', $source );
		self::assertStringContainsString( '$product->wordpress();', $source );
	}

	public function testUnexpectedBootstrapOrQueryOutputIsPrivateAndRefuses(): void {
		foreach ( array( 1, 10000 ) as $length ) {
			ob_start(); $level = ob_get_level();
			try {
				$this->invoke( 'quiet', static function () use ( $length ): array {
					echo str_repeat( 'Private token/response', $length );
					return array( 'status' => 'live-absence-verified' );
				} );
				self::fail( 'Unexpected runtime output accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'unexpected runtime stdout', $error->getMessage() );
				self::assertSame( '', ob_get_contents() ); self::assertSame( $level, ob_get_level() );
			} finally { ob_end_clean(); }
		}
		$result = array( 'status' => 'live-absence-verified' );
		self::assertSame( $result, $this->invoke( 'quiet', static fn() => $result ) );
	}

	public function testPrivateOriginalExceptionSurvivesSuppressedOutputAndBufferRestoration(): void {
		$original = new RuntimeException( 'Original private WordPress error' ); $level = ob_get_level();
		try {
			$this->invoke( 'quiet', static function () use ( $original ): array { echo 'Private raw output'; throw $original; } );
			self::fail( 'Original failure hidden.' );
		} catch ( RuntimeException $error ) { self::assertSame( $original, $error ); self::assertSame( $level, ob_get_level() ); }
	}
}
