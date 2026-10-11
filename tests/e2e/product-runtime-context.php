<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';

final class Wstm167_Product_Context {
	private string $path;
	private array $snapshot;
	private array $root;
	private array $data;
	private ?array $sources = null;

	public static function decode( string $bytes ): array {
		self::require( strlen( $bytes ) > 0 && strlen( $bytes ) <= 4096, 'context byte bound' );
		$data = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
		self::require( is_array( $data ), 'context object' );
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"\\s*:/', $bytes, $matches );
		$keys = array_map( static function ( string $key ): string {
			return json_decode( '"' . $key . '"', true, 2, JSON_THROW_ON_ERROR );
		}, $matches[1] );
		self::require( count( $keys ) === count( $data ) && count( $keys ) === count( array_unique( $keys, SORT_STRING ) ),
			'duplicate or nested context keys' );
		return $data;
	}

	public static function validate( array $data, string $run, string $token, int $uid, string $floor ): void {
		$keys = array( 'schema', 'run', 'token', 'project', 'url', 'artifact_root', 'plugin_root',
			'wordpress_root', 'identity_kind', 'identity_sha256', 'uid', 'php_floor' );
		$actual = array_keys( $data );
		sort( $actual );
		sort( $keys );
		self::require( $actual === $keys, 'context fields' );
		foreach ( $data as $key => $value ) {
			self::require( 'uid' === $key ? is_int( $value ) : is_string( $value ), 'context types' );
		}
		self::require( 'wstm-product-context-v1' === $data['schema']
			&& 1 === preg_match( '/^[a-f0-9]{32}$/D', $run ) && $run === $data['run']
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $token ) && hash_equals( $token, $data['token'] )
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/D', $data['project'] )
			&& 'http://localhost' === $data['url']
			&& '/qa-artifacts' === $data['artifact_root']
			&& '/var/www/html' === $data['wordpress_root']
			&& '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp' === $data['plugin_root']
			&& in_array( $data['identity_kind'], array( 'source', 'package' ), true )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', $data['identity_sha256'] )
			&& 33 === $uid && $uid === $data['uid']
			&& in_array( $floor, array( '8.0', '8.1', '8.2', '8.4' ), true ) && $floor === $data['php_floor'], 'context binding' );
	}

	public static function open(): self {
		self::require( 'cli' === PHP_SAPI && '1' === getenv( 'E2E_OPT_IN' )
			&& 8 === PHP_INT_SIZE && function_exists( 'posix_geteuid' ) && function_exists( 'posix_getegid' ), 'CLI opt-in and UID/GID observation' );
		self::require( 33 === posix_geteuid() && 33 === posix_getegid(), 'nonroot candidate UID/GID' );
		$path = getenv( 'WSTM_PRODUCT_CONTEXT' );
		self::require( '/qa-artifacts/context.json' === $path, 'context path' );
		$root = self::root_identity( '/qa-artifacts' );
		self::require( 33 === $root['uid'] && 33 === $root['gid'] && 0700 === ( $root['mode'] & 0777 ), 'private artifact ownership' );
		$stat = lstat( $path );
		self::require( is_array( $stat ) && 0100000 === ( $stat['mode'] & 0170000 )
			&& 33 === $stat['uid'] && 33 === $stat['gid'] && 0600 === ( $stat['mode'] & 0777 )
			&& 1 === $stat['nlink'] && $stat['size'] > 0 && $stat['size'] <= 4096, 'bounded private context' );
		$file = Wstm108_Files::file( $path );
		self::require( strlen( $file['bytes'] ) === $stat['size'], 'context size stability' );
		$data = self::decode( $file['bytes'] );
		self::validate( $data, (string) getenv( 'WSTM_PRODUCT_RUN' ), (string) getenv( 'WSTM_PRODUCT_TOKEN' ),
			posix_geteuid(), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
		$self = new self();
		$self->path = $path;
		$self->snapshot = $file;
		$self->root = $root;
		$self->data = $data;
		return $self;
	}

	private static function require( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'Product context refused: ' . $reason . '.' );
		}
	}

	public function recheck(): void {
		self::require( self::root_identity( '/qa-artifacts' ) === $this->root, 'artifact root changed' );
		Wstm108_Files::assert_file( $this->path, $this->snapshot );
	}

	private static function root_identity( string $path ): array {
		$identity = Wstm108_Files::directory( $path );
		// Creating an owned child changes a Unix directory's link count, not its anchor.
		unset( $identity['nlink'] );
		return $identity;
	}

	public function data(): array {
		return $this->data;
	}

	public static function cron_config( string $bytes ): void {
		$tokens = array_values( array_filter( token_get_all( $bytes, TOKEN_PARSE ),
			static fn( $token ) => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
		$mentions = 0; $definitions = 0; $depth = 0;
		foreach ( $tokens as $index => $token ) {
			self::require( ! is_array( $token ) || ! in_array( $token[0],
				array( T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE, T_NAMESPACE ), true ),
				'unsupported alternative-syntax config control flow' );
			if ( '{' === $token || '(' === $token || '[' === $token ) { ++$depth; }
			if ( '}' === $token || ')' === $token || ']' === $token ) { --$depth; }
			if ( ! is_array( $token ) || false === strpos( $token[1], 'DISABLE_WP_CRON' ) ) { continue; }
			++$mentions;
			$define = $tokens[ $index - 2 ] ?? null;
			$value = $tokens[ $index + 2 ] ?? null;
			$previous = $tokens[ $index - 3 ] ?? null;
			if ( 1 === $depth && T_CONSTANT_ENCAPSED_STRING === $token[0]
				&& in_array( $token[1], array( "'DISABLE_WP_CRON'", '"DISABLE_WP_CRON"' ), true )
				&& is_array( $define ) && T_STRING === $define[0] && 'define' === strtolower( $define[1] )
				&& '(' === ( $tokens[ $index - 1 ] ?? null ) && ',' === ( $tokens[ $index + 1 ] ?? null )
				&& is_array( $value ) && T_STRING === $value[0] && 'true' === strtolower( $value[1] )
				&& ')' === ( $tokens[ $index + 3 ] ?? null ) && ';' === ( $tokens[ $index + 4 ] ?? null )
				&& ( ';' === $previous || '}' === $previous || ( is_array( $previous ) && T_OPEN_TAG === $previous[0] ) ) ) {
				++$definitions;
			}
		}
		self::require( 1 === $mentions && 1 === $definitions, 'single unconditional literal-true config cron policy' );
	}

	public static function cron_runtime( bool $defined, $value, bool $doing_cron, int $cron_actions ): void {
		self::require( $defined && true === $value && ! $doing_cron && 0 === $cron_actions,
			'effective literal-true cron policy or cron work changed' );
	}

	public static function pre_acquisition_absence( array $context, $db ): array {
		$namespace = 'w121_' . substr( $context['run'], 0, 12 );
		foreach ( array( 'posts', 'postmeta', 'users', 'usermeta', 'options' ) as $name ) {
			self::require( is_string( $db->$name ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $db->$name ),
				'pre-acquisition table identity' );
		}
		$subjects = array(
			'posts' => array( "SELECT COUNT(*) FROM {$db->posts} WHERE post_content_filtered=%s OR post_type=%s", $context['token'], $namespace ),
			'actor' => array( "SELECT COUNT(*) FROM {$db->users} WHERE user_login=%s", $namespace ),
			'actor_meta' => array( "SELECT COUNT(*) FROM {$db->usermeta} m INNER JOIN {$db->users} u ON u.ID=m.user_id WHERE u.user_login=%s", $namespace ),
			'control' => array( "SELECT COUNT(*) FROM {$db->options} WHERE option_name=%s", $namespace . '_control' ),
			'postmeta' => array( "SELECT COUNT(*) FROM {$db->postmeta} m INNER JOIN {$db->posts} p ON p.ID=m.post_id WHERE p.post_content_filtered=%s OR p.post_type=%s", $context['token'], $namespace ),
		);
		$result = array();
		foreach ( $subjects as $name => $args ) {
			$sql = $db->prepare( ...$args );
			self::require( is_string( $sql ), 'pre-acquisition prepared query' );
			$value = $db->get_var( $sql );
			self::require( '' === $db->last_error && ( 0 === $value || '0' === $value ), 'pre-acquisition native absence' );
			$result[ $name ] = 0;
		}
		return $result;
	}

	public function binding(): array {
		return array( 'owner' => $this->data['run'], 'project' => $this->data['project'],
			'identity_kind' => $this->data['identity_kind'], 'identity_sha256' => $this->data['identity_sha256'] );
	}

	public function sha256(): string {
		return $this->snapshot['sha256'];
	}

	public function wordpress(): void {
		$this->recheck();
		self::require( defined( 'ABSPATH' ) && realpath( ABSPATH ) === $this->data['wordpress_root']
			&& function_exists( 'get_option' ) && ! is_multisite(), 'owned WordPress bootstrap' );
		self::require( get_option( 'wstm167_product_context' ) === array(
			'run' => $this->data['run'], 'token' => $this->data['token'], 'project' => $this->data['project'],
		), 'database ownership' );
		self::require( function_exists( 'wp_get_ability' )
			&& null !== wp_get_ability( 'webmastery-site-toolkit-for-mcp/get-post' ), 'toolkit ability registration' );
		self::require( class_exists( 'Webmastery_MCP_Posts' ), 'toolkit activation' );
		$file = ( new ReflectionClass( 'Webmastery_MCP_Posts' ) )->getFileName();
		self::require( realpath( $file ) === $this->data['plugin_root'] . '/includes/class-posts.php', 'loaded toolkit root' );
		$sources = array();
		foreach ( array( 'class-posts.php', 'class-media.php', 'class-seo.php', 'class-content-hygiene.php',
			'class-custom-post-types.php', 'class-list-query.php', 'class-input.php', 'class-response.php', 'class-untrusted.php' ) as $name ) {
			$source = Wstm108_Files::file( $this->data['plugin_root'] . '/includes/' . $name );
			$sources[ $name ] = array( 'identity' => $source['identity'], 'sha256' => $source['sha256'] );
		}
		self::require( null === $this->sources || $this->sources === $sources, 'product source changed' );
		$this->sources = $sources;
	}

	public function stage( string $name ): string {
		self::require( in_array( $name, array( 'untrusted', 'bounded' ), true ), 'stage name' );
		$this->recheck();
		$run = '/qa-artifacts/' . $this->data['run'];
		if ( ! file_exists( $run ) && ! is_link( $run ) ) {
			self::require( mkdir( $run, 0700 ), 'run directory creation' );
		}
		$identity = Wstm108_Files::directory( $run );
		self::require( 33 === $identity['uid'] && 33 === $identity['gid'] && 0700 === ( $identity['mode'] & 0777 ), 'run directory ownership' );
		$path = $run . '/' . $name;
		self::require( ! file_exists( $path ) && ! is_link( $path ) && mkdir( $path, 0700 ), 'exclusive stage directory' );
		$this->recheck();
		return $path;
	}
}
