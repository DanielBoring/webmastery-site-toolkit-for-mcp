<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/product-runtime-context.php';
require_once dirname( __DIR__ ) . '/e2e/bounded-list-plan.php';
require_once dirname( __DIR__ ) . '/e2e/untrusted-content-resources.php';

final class ProductRuntimeContextTest extends TestCase {
	public static function context(): array {
		return array( 'schema' => 'wstm-product-context-v1', 'run' => str_repeat( 'a', 32 ),
			'token' => str_repeat( 'b', 64 ), 'project' => 'wstm167-model', 'url' => 'http://localhost',
			'artifact_root' => '/qa-artifacts', 'plugin_root' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp',
			'wordpress_root' => '/var/www/html', 'identity_kind' => 'source', 'identity_sha256' => str_repeat( 'c', 64 ),
			'uid' => 33, 'php_floor' => '8.0' );
	}

	public function testExactParentContextAllowsOnlyActualUidAndFloor(): void {
		$data = self::context();
		self::assertSame( $data, Wstm167_Product_Context::decode( json_encode( $data, JSON_THROW_ON_ERROR ) ) );
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, '8.0' );
		$data['identity_kind'] = 'package';
		$data['php_floor'] = '8.4';
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, '8.4' );
		self::addToAssertionCount( 2 );
	}

	public static function runtimeLanes(): array {
		$cases = array();
		foreach ( array( '8.0', '8.1', '8.2', '8.4' ) as $lane ) {
			foreach ( array( 'source', 'package' ) as $kind ) {
				$cases[ $kind . ':' . $lane ] = array( $kind, $lane );
			}
		}
		return $cases;
	}

	/** @dataProvider runtimeLanes */
	public function testExactRequestedRuntimeLaneForSourceAndPackage( string $kind, string $lane ): void {
		$data = self::context();
		$data['identity_kind'] = $kind;
		$data['php_floor'] = $lane;
		$decoded = Wstm167_Product_Context::decode( json_encode( $data, JSON_THROW_ON_ERROR ) );
		self::assertCount( 12, $decoded );
		self::assertSame( $data, $decoded );
		Wstm167_Product_Context::validate( $decoded, $data['run'], $data['token'], 33, $lane );
		self::addToAssertionCount( 1 );
	}

	public function testContextMatchesTheActuallyRunningInterpreterLane(): void {
		$data = self::context();
		$data['php_floor'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		self::addToAssertionCount( 1 );
	}

	public static function refusedRuntimeLanes(): array {
		$cases = array();
		foreach ( array( '8.0', '8.1', '8.2', '8.4' ) as $requested ) {
			foreach ( array( '8.0', '8.1', '8.2', '8.4' ) as $observed ) {
				if ( $requested !== $observed ) {
					$cases[ $requested . '-on-' . $observed ] = array( $requested, $observed );
				}
			}
		}
		foreach ( array( '8.3', '8.5', '8.1.33', '8.1 ', ' 8.1', '8.1-any', '8.2.33', '8.2 ', ' 8.2', '8.2-any', '>=8.0', '' ) as $unknown ) {
			$cases[ 'unknown-both:' . $unknown ] = array( $unknown, $unknown );
			$cases[ 'unknown-request:' . $unknown ] = array( $unknown, '8.2' );
			$cases[ 'unknown-observed:' . $unknown ] = array( '8.2', $unknown );
		}
		return $cases;
	}

	/** @dataProvider refusedRuntimeLanes */
	public function testCrossLaneAndUnknownVersionsCannotBecomeFloorEvidence( string $requested, string $observed ): void {
		$data = self::context();
		$data['php_floor'] = $requested;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'context binding' );
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, $observed );
	}

	public static function malformedFields(): array {
		$cases = array();
		foreach ( array_keys( self::context() ) as $key ) {
			foreach ( array( 'missing', 'null', 'array', 'boolean', 'number' ) as $shape ) {
				if ( 'uid' === $key && 'number' === $shape ) { continue; }
				$cases[ $key . ':' . $shape ] = array( $key, $shape );
			}
		}
		return $cases;
	}

	public static function composeProjects(): array {
		return array(
			array( 'wstm-local-0123456789abcdef-package' ),
			array( 'wstm-local-0123456789abcdef-source' ),
			array( 'maintained_ci_qa' ),
			array( '1' ),
			array( str_repeat( 'a', 63 ) ),
			array( 'wstm167-model' ),
		);
	}

	/** @dataProvider composeProjects */
	public function testFiniteComposeProjectNamesPreserveExactContext( string $project ): void {
		$data = self::context();
		$data['project'] = $project;
		$data['php_floor'] = '8.2';
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, '8.2' );
		self::assertSame( $data, Wstm167_Product_Context::decode( json_encode( $data, JSON_THROW_ON_ERROR ) ) );
		$binding = new ReflectionMethod( Wstm108_Resources::class, 'binding_schema' );
		$binding->setAccessible( true );
		$binding->invoke( null, array( 'owner' => $data['run'], 'project' => $project, 'identity_kind' => 'package', 'identity_sha256' => $data['identity_sha256'] ) );
		self::addToAssertionCount( 1 );
	}

	public static function invalidComposeProjects(): array {
		return array_map( static fn( string $name ): array => array( $name ), array(
			'', '-leading', '_leading', 'Uppercase', 'has.dot', 'has/slash', 'has\\slash',
			'has space', "has\nnewline", 'trailing ', "nonascii-\xc3\xa9",
			str_repeat( 'a', 64 ), "null\0byte",
		) );
	}

	/** @dataProvider invalidComposeProjects */
	public function testInvalidComposeProjectNamesRefuse( string $project ): void {
		$data = self::context();
		$data['project'] = $project;
		$this->expectException( RuntimeException::class );
		Wstm167_Product_Context::validate( $data, $data['run'], $data['token'], 33, '8.0' );
	}

	/** @dataProvider invalidComposeProjects */
	public function testProductResourceBindingAlsoRefusesInvalidComposeNames( string $project ): void {
		$binding = new ReflectionMethod( Wstm108_Resources::class, 'binding_schema' );
		$binding->setAccessible( true );
		$this->expectException( RuntimeException::class );
		$binding->invoke( null, array( 'owner' => str_repeat( 'a', 32 ), 'project' => $project,
			'identity_kind' => 'package', 'identity_sha256' => str_repeat( 'c', 64 ) ) );
	}

	public static function wrongInvocationOrEndpoint(): array {
		return array(
			array( 'run', str_repeat( 'd', 32 ) ),
			array( 'token', str_repeat( 'd', 64 ) ),
			array( 'url', 'http://127.0.0.1:8080' ),
			array( 'url', 'http://localhost:8080' ),
			array( 'url', 'https://localhost' ),
			array( 'url', 'http://localhost/' ),
			array( 'url', 'https://example.com' ),
		);
	}

	/** @dataProvider wrongInvocationOrEndpoint */
	public function testValidProjectCannotAuthorizeForeignRunTokenOrUrl( string $key, string $value ): void {
		$data = self::context();
		$data['project'] = 'wstm-local-0123456789abcdef-package';
		$data[ $key ] = $value;
		$this->expectException( RuntimeException::class );
		Wstm167_Product_Context::validate( $data, str_repeat( 'a', 32 ), str_repeat( 'b', 64 ), 33, '8.0' );
	}

	public function testActualDatabaseMarkerAssertionStillRequiresExactRunTokenAndProject(): void {
		$data = self::context();
		$data['project'] = 'wstm-local-0123456789abcdef-package';
		$source = file_get_contents( dirname( __DIR__ ) . '/e2e/product-runtime-context.php' );
		$start = "self::require( get_option( 'wstm167_product_context' )";
		$end = "self::require( function_exists( 'wp_get_ability' )";
		self::assertSame( 1, substr_count( $source, $start ) );
		self::assertSame( 1, substr_count( $source, $end ) );
		$fragment = substr( $source, strpos( $source, $start ), strpos( $source, $end ) - strpos( $source, $start ) );
		$fragment = str_replace( "get_option( 'wstm167_product_context' )", '$marker', $fragment );
		$context = ( new ReflectionClass( Wstm167_Product_Context::class ) )->newInstanceWithoutConstructor();
		$property = new ReflectionProperty( $context, 'data' );
		$property->setAccessible( true );
		$property->setValue( $context, $data );
		$check = ( function ( $marker ) use ( $fragment ): void { eval( $fragment ); } )->bindTo( $context, Wstm167_Product_Context::class );
		$marker = array( 'run' => $data['run'], 'token' => $data['token'], 'project' => $data['project'] );
		$check( $marker );
		self::addToAssertionCount( 1 );
		$wrong = array( false, null, array(), $marker + array( 'extra' => true ), array_reverse( $marker, true ) );
		foreach ( array( 'run', 'token', 'project' ) as $key ) {
			$foreign = $marker;
			$foreign[ $key ] = 'foreign';
			$wrong[] = $foreign;
			$missing = $marker;
			unset( $missing[ $key ] );
			$wrong[] = $missing;
		}
		foreach ( $wrong as $foreign ) {
			try {
				$check( $foreign );
				self::fail( 'Foreign database marker accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'database ownership', $error->getMessage() );
			}
		}
	}

	private function lazyContext(): object {
		$namespace = 'Wstm167_Lazy_Registration_Model';
		$class = $namespace . '\\Wstm167_Product_Context';
		if ( ! class_exists( $class, false ) ) {
			$source = file_get_contents( dirname( __DIR__ ) . '/e2e/product-runtime-context.php' );
			$header = "<?php\n\ndeclare(strict_types=1);";
			$include = "require_once __DIR__ . '/untrusted-content-files.php';";
			self::assertSame( 0, strpos( $source, $header ) );
			self::assertSame( 1, substr_count( $source, $include ) );
			$source = str_replace( $include, '', substr( $source, strlen( $header ) ) );
			$stubs = <<<'PHP'
final class Model {
	public static array $state;
}
const ABSPATH = '/var/www/html/';
function defined( $name ) {
	return 'ABSPATH' === $name ? Model::$state['wordpress'] : \defined( $name );
}
function realpath( $path ) {
	Model::$state['events'][] = 'realpath:' . $path;
	return ABSPATH === $path ? Model::$state['wordpress_root'] : $path;
}
function function_exists( $name ) {
	return 'wp_get_ability' === $name ? Model::$state['api'] : \function_exists( __NAMESPACE__ . '\\' . $name );
}
function is_multisite() {
	return Model::$state['multisite'];
}
function get_option( $name ) {
	if ( 'wstm167_product_context' !== $name ) { throw new RuntimeException( 'Unexpected option query.' ); }
	Model::$state['events'][] = 'marker';
	return Model::$state['marker'];
}
function wp_get_ability( $name ) {
	Model::$state['lookups'][] = $name;
	Model::$state['events'][] = 'ability';
	Model::$state['loaded_at_lookup'][] = Model::$state['loaded'];
	if ( Model::$state['lookup_error'] ) { throw new RuntimeException( 'Registry initialization failed.' ); }
	Model::$state['loaded'] = Model::$state['register_posts'];
	return Model::$state['ability'] ? new \stdClass() : null;
}
function class_exists( $name ) {
	if ( 'Webmastery_MCP_Posts' !== $name ) { throw new RuntimeException( 'Unexpected activation query.' ); }
	Model::$state['events'][] = 'activation';
	return Model::$state['loaded'];
}
final class ReflectionClass {
	public function __construct( $name ) {
		if ( 'Webmastery_MCP_Posts' !== $name ) { throw new RuntimeException( 'Unexpected reflected class.' ); }
	}
	public function getFileName() { return Model::$state['loaded_file']; }
}
final class Wstm108_Files {
	public static function directory( $path ) {
		if ( '/qa-artifacts' !== $path ) { throw new RuntimeException( 'Unexpected directory query.' ); }
		Model::$state['events'][] = 'root';
		return Model::$state['root'];
	}
	public static function assert_file( $path, $snapshot ) {
		if ( '/qa-artifacts/context.json' !== $path || array( 'context' => true ) !== $snapshot ) {
			throw new RuntimeException( 'Unexpected context query.' );
		}
		Model::$state['events'][] = 'context';
		if ( Model::$state['context_changed'] ) { throw new RuntimeException( 'Context bytes changed.' ); }
	}
	public static function file( $path ) {
		$name = basename( $path );
		if ( '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/includes/' . $name !== $path
			|| ! isset( Model::$state['sources'][ $name ] ) ) { throw new RuntimeException( 'Unexpected source query.' ); }
		Model::$state['events'][] = 'source:' . $name;
		if ( $name === Model::$state['unreadable'] ) { throw new RuntimeException( 'Source read failed.' ); }
		return Model::$state['sources'][ $name ];
	}
}
PHP;
			eval( 'namespace ' . $namespace . '; use \RuntimeException; ' . $stubs . $source );
		}
		$data = self::context();
		$sources = array();
		foreach ( array( 'class-posts.php', 'class-media.php', 'class-seo.php', 'class-content-hygiene.php',
			'class-custom-post-types.php', 'class-list-query.php', 'class-input.php', 'class-response.php', 'class-untrusted.php' ) as $name ) {
			$sources[ $name ] = array( 'identity' => array( 'ino' => count( $sources ) + 1 ), 'sha256' => hash( 'sha256', $name ) );
		}
		$model = $namespace . '\\Model';
		$model::$state = array(
			'wordpress' => true, 'wordpress_root' => $data['wordpress_root'], 'multisite' => false,
			'marker' => array( 'run' => $data['run'], 'token' => $data['token'], 'project' => $data['project'] ),
			'api' => true, 'ability' => true, 'loaded' => false, 'register_posts' => true, 'lookup_error' => false,
			'loaded_file' => $data['plugin_root'] . '/includes/class-posts.php',
			'root' => array( 'ino' => 1 ), 'context_changed' => false, 'sources' => $sources, 'unreadable' => null,
			'events' => array(), 'lookups' => array(), 'loaded_at_lookup' => array(),
		);
		$context = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		foreach ( array( 'path' => '/qa-artifacts/context.json', 'snapshot' => array( 'context' => true ),
			'root' => array( 'ino' => 1 ), 'data' => $data ) as $name => $value ) {
			$property = new ReflectionProperty( $context, $name );
			$property->setAccessible( true );
			$property->setValue( $context, $value );
		}
		return $context;
	}

	public function testActualWordpressGuardInitializesLazyRegistryAfterOwnershipAndRechecksAllSources(): void {
		$context = $this->lazyContext();
		$context->wordpress();
		$state = \Wstm167_Lazy_Registration_Model\Model::$state;
		self::assertSame( array( 'webmastery-site-toolkit-for-mcp/get-post' ), $state['lookups'] );
		self::assertSame( array( false ), $state['loaded_at_lookup'] );
		self::assertSame( array( 'root', 'context', 'realpath:/var/www/html/', 'marker', 'ability', 'activation',
			'realpath:' . self::context()['plugin_root'] . '/includes/class-posts.php' ), array_slice( $state['events'], 0, 7 ) );
		self::assertSame( array_map( static fn( string $name ): string => 'source:' . $name,
			array_keys( $state['sources'] ) ), array_slice( $state['events'], 7 ) );
		$context->wordpress();
		self::assertSame( array_merge( $state['events'], $state['events'] ), \Wstm167_Lazy_Registration_Model\Model::$state['events'] );
		self::assertSame( array( false, true ), \Wstm167_Lazy_Registration_Model\Model::$state['loaded_at_lookup'] );
	}

	public static function lazyRegistrationRefusals(): array {
		return array(
			'missing-api' => array( 'api', false, 'toolkit ability registration', 0 ),
			'missing-ability' => array( 'ability', false, 'toolkit ability registration', 1 ),
			'missing-class' => array( 'register_posts', false, 'toolkit activation', 1 ),
			'registry-error' => array( 'lookup_error', true, 'Registry initialization failed.', 1 ),
			'foreign-loaded-root' => array( 'loaded_file', '/foreign/includes/class-posts.php', 'loaded toolkit root', 1 ),
			'missing-wordpress' => array( 'wordpress', false, 'owned WordPress bootstrap', 0 ),
			'foreign-wordpress' => array( 'wordpress_root', '/foreign', 'owned WordPress bootstrap', 0 ),
			'multisite' => array( 'multisite', true, 'owned WordPress bootstrap', 0 ),
			'missing-marker' => array( 'marker', false, 'database ownership', 0 ),
			'changed-artifact-root' => array( 'root', array( 'ino' => 2 ), 'artifact root changed', 0 ),
			'changed-context' => array( 'context_changed', true, 'Context bytes changed.', 0 ),
		);
	}

	/** @dataProvider lazyRegistrationRefusals */
	public function testLazyRegistrationNeverBypassesEarlierOrLaterRefusals( string $key, $value, string $reason, int $lookups ): void {
		$context = $this->lazyContext();
		\Wstm167_Lazy_Registration_Model\Model::$state[ $key ] = $value;
		try {
			$context->wordpress();
			self::fail( 'WordPress bootstrap unexpectedly accepted.' );
		} catch ( RuntimeException $error ) {
			self::assertStringContainsString( $reason, $error->getMessage() );
		}
		self::assertCount( $lookups, \Wstm167_Lazy_Registration_Model\Model::$state['lookups'] );
		self::assertFalse( (bool) array_filter( \Wstm167_Lazy_Registration_Model\Model::$state['events'],
			static fn( string $event ): bool => 0 === strpos( $event, 'source:' ) ) );
	}

	public function testAlreadyLoadedClassDoesNotSubstituteForNativeAbilityRegistration(): void {
		$context = $this->lazyContext();
		\Wstm167_Lazy_Registration_Model\Model::$state['loaded'] = true;
		\Wstm167_Lazy_Registration_Model\Model::$state['ability'] = false;
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'toolkit ability registration' );
		$context->wordpress();
	}

	public static function protectedSourceChanges(): array {
		$cases = array();
		foreach ( array( 'class-posts.php', 'class-media.php', 'class-seo.php', 'class-content-hygiene.php',
			'class-custom-post-types.php', 'class-list-query.php', 'class-input.php', 'class-response.php', 'class-untrusted.php' ) as $name ) {
			foreach ( array( 'identity', 'sha256', 'unreadable' ) as $change ) {
				$cases[ $name . ':' . $change ] = array( $name, $change );
			}
		}
		return $cases;
	}

	/** @dataProvider protectedSourceChanges */
	public function testLazyInitializationPreservesEverySourceStabilityAndReadFailureCheck( string $name, string $change ): void {
		$context = $this->lazyContext();
		$context->wordpress();
		if ( 'unreadable' === $change ) {
			\Wstm167_Lazy_Registration_Model\Model::$state['unreadable'] = $name;
		} else {
			\Wstm167_Lazy_Registration_Model\Model::$state['sources'][ $name ][ $change ] =
				'identity' === $change ? array( 'ino' => 100 ) : str_repeat( 'f', 64 );
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'unreadable' === $change ? 'Source read failed.' : 'product source changed' );
		$context->wordpress();
	}

	public function testActualHttpFixtureUsesFiniteProjectNamesAndStillRejectsForeignRunTokenOrMarker(): void {
		$projects = array_merge( self::composeProjects(), self::invalidComposeProjects() );
		$fixture = dirname( __DIR__ ) . '/e2e/untrusted-content-fixture.php';
		$code = 'putenv("E2E_OPT_IN=1"); putenv("WSTM_PRODUCT_RUN=" . str_repeat("a",32)); putenv("WSTM_PRODUCT_TOKEN=" . str_repeat("b",64));'
			. 'function get_option($name){if($name!=="wstm167_product_context"){throw new RuntimeException("Wrong option");}return $GLOBALS["marker"];}'
			. 'require ' . var_export( $fixture, true ) . ';'
			. '$out=[]; foreach(' . var_export( $projects, true ) . ' as $project){'
			. '$GLOBALS["marker"]=["run"=>str_repeat("a",32),"token"=>str_repeat("b",64),"project"=>$project[0]];'
			. '$out[]=wstm108_product_fixture_owned();}'
			. '$valid=["run"=>str_repeat("a",32),"token"=>str_repeat("b",64),"project"=>"wstm-local-0123456789abcdef-package"];'
			. 'foreach(["run","token"] as $key){$GLOBALS["marker"]=$valid;$GLOBALS["marker"][$key]="foreign";$out[]=wstm108_product_fixture_owned();}'
			. 'foreach([false,null,[],array_reverse($valid,true),$valid+["extra"=>true]] as $marker){$GLOBALS["marker"]=$marker;$out[]=wstm108_product_fixture_owned();}'
			. 'echo json_encode($out,JSON_THROW_ON_ERROR);';
		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		self::assertSame( array_merge( array_fill( 0, count( self::composeProjects() ), true ),
			array_fill( 0, count( self::invalidComposeProjects() ) + 7, false ) ), json_decode( $out, true, 32, JSON_THROW_ON_ERROR ) );
	}

	/** @dataProvider malformedFields */
	public function testMissingAndMistypedFieldsExplicitlyRefuse( string $key, string $shape ): void {
		$data = self::context();
		switch ( $shape ) {
			case 'missing': unset( $data[ $key ] ); break;
			case 'null': $data[ $key ] = null; break;
			case 'array': $data[ $key ] = array(); break;
			case 'boolean': $data[ $key ] = true; break;
			case 'number': $data[ $key ] = 1; break;
		}
		$this->expectException( RuntimeException::class );
		Wstm167_Product_Context::validate( $data, str_repeat( 'a', 32 ), str_repeat( 'b', 64 ), 33, '8.0' );
	}

	public function testDuplicateKeysCannotSilentlyOverwriteParentIdentity(): void {
		$json = json_encode( self::context(), JSON_THROW_ON_ERROR );
		$this->expectException( RuntimeException::class );
		Wstm167_Product_Context::decode( substr( $json, 0, -1 ) . ',"uid":33}' );
	}

	public function testFiniteScheduleIsIndependentOfCandidateOutcomesAndKeepsSparseEmptyWindows(): void {
		$state = array( 'cpt' => 'w121_0123456789ab', 'catalog' => array(
			'post' => range( 40000, 20001 ), 'attachment' => range( 20000, 10001 ),
			'page' => range( 10000, 9798 ), 'w121_0123456789ab' => range( 9700, 9496 ),
		) );
		$jobs = Wstm121Plan::selected( $state );
		self::assertCount( 336, $jobs );
		self::assertCount( 336, array_unique( array_column( $jobs, 'coverage_key' ) ) );
		foreach ( $jobs as $job ) {
			if ( 'sparse' === $job['mode'] && false !== strpos( $job['coverage_key'], ':intermediate' ) ) {
				$offset = ( $job['page'] - 1 ) * $job['per_page'];
				self::assertGreaterThanOrEqual( 100, $offset );
				self::assertLessThan( 200, $offset );
				for ( $rank = $offset; $rank < $offset + $job['per_page']; ++$rank ) {
					self::assertFalse( wstm121_allowed( 'sparse', $rank ) );
				}
				self::assertTrue( wstm121_allowed( 'sparse', 201 ) );
			}
		}
	}
}
