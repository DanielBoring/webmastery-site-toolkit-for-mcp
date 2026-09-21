<?php

namespace Wstm110Boundary;

use LogicException;
use RuntimeException;

// Execute unchanged production bodies with fail-fast WordPress boundary probes.
foreach ( array( 'class-list-query.php', 'class-posts.php', 'class-custom-post-types.php', 'class-seo.php' ) as $file ) {
	$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/' . $file );
	eval( 'namespace Wstm110Boundary; use \WP_Error; use \Webmastery_MCP_Response; use \Webmastery_MCP_Post_Scheduling; use \Webmastery_MCP_Post_Parent; ' . substr( $source, 5 ) );
}

final class MutationAttempt extends RuntimeException {}
final class UnauthorizedRead extends RuntimeException {}
final class OpaqueHeadRead extends RuntimeException {}

final class Probe {
	public static array $abilities = array();
	public static array $mutations = array();
	public static array $capabilities = array();
	public static array $reads = array();
	public static array $denied_keys = array();
	public static array $denied_by_id = array();
	public static array $denied_objects = array();
	public static array $posts = array();
	public static array $metadata = array();
	public static array $queries = array();
	public static array $head_requests = array();
	public static string $post_type = 'post';
	public static bool $deny_base = false;
	public static ?\Closure $metadata_writer = null;
	public static bool $providers_active = true;

	public static function reset( string $type = 'post' ): void {
		self::$abilities = self::$mutations = self::$capabilities = self::$reads = self::$denied_keys = array();
		self::$denied_by_id = self::$denied_objects = self::$posts = self::$metadata = self::$queries = self::$head_requests = array();
		self::$post_type = $type;
		self::$deny_base = false;
		self::$metadata_writer = null;
		self::$providers_active = true;
		Webmastery_MCP_Posts::register();
		$register = new \ReflectionMethod( Webmastery_MCP_Custom_Post_Types::class, 'register_custom_post_type' );
		$register->setAccessible( true );
		foreach ( array( 'mcp_book', 'mcp_case_study' ) as $custom ) {
			$register->invoke( null, (object) array(
				'name' => $custom, 'label' => $custom, 'hierarchical' => false,
				'labels' => (object) array( 'singular_name' => $custom ),
				'cap' => (object) array( 'edit_post' => 'edit_' . $custom, 'create_posts' => 'create_' . $custom, 'publish_posts' => 'publish_' . $custom ),
			), str_replace( '_', '-', $custom ) );
		}
		Webmastery_MCP_SEO::register();
	}

	public static function execute( string $slug, array $input ) {
		$name = 'webmastery-site-toolkit-for-mcp/' . $slug;
		if ( ! isset( self::$abilities[ $name ] ) ) {
			throw new LogicException( "Unregistered test ability: {$name}" );
		}
		return ( self::$abilities[ $name ]['execute_callback'] )( $input );
	}

	public static function mutation( string $function, array $arguments ): void {
		self::$mutations[] = array( $function, $arguments );
		throw new MutationAttempt( "Reached {$function} before rejecting metadata." );
	}
}

function wp_register_ability( $name, $definition ) {
	Probe::$abilities[ $name ] = $definition;
}

function current_user_can( $cap, ...$args ) {
	Probe::$capabilities[] = array( $cap, $args );
	if ( in_array( $cap, array( 'edit_post_meta', 'delete_post_meta', 'add_post_meta' ), true ) ) {
		if ( empty( $args[0] ) ) {
			throw new LogicException( 'Metadata authorization attempted without a real object.' );
		}
		return ! in_array( $args[1], Probe::$denied_keys, true )
			&& ! in_array( $args[1], Probe::$denied_by_id[ $args[0] ] ?? array(), true );
	}
	if ( 'edit_post' === $cap && in_array( $args[0] ?? 0, Probe::$denied_objects, true ) ) {
		return false;
	}
	return ! Probe::$deny_base;
}

function get_current_user_id() {
	return 7;
}

function get_post( $id ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	if ( isset( Probe::$posts[ $id ] ) ) {
		return Probe::$posts[ $id ];
	}
	if ( 42 !== (int) $id ) {
		return null;
	}
	return (object) array(
		'ID' => 42, 'post_type' => Probe::$post_type, 'post_status' => 'draft',
		'post_title' => 'Original title long enough to analyze correctly',
		'post_content' => '<p>Original content.</p>', 'post_name' => 'original',
	);
}

function get_post_type( $id ) {
	return get_post( $id )->post_type;
}

function get_registered_meta_keys( $object_type, $subtype = '' ) {
	// A registration prevents the legacy unregistered-key fallback in these unit probes.
	return array_fill_keys( array_merge(
		array_column( \wstm110_batch_aliases(), 0 ),
		array( 'wstm110_open', 'wstm110_restricted', '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_seopress_news_disabled', '_seopress_video_disabled', '_yoast_wpseo_inclusive_language_score', '_yoast_wpseo_is_cornerstone' )
	), array( 'show_in_rest' => true, 'single' => true, 'type' => 'string' ) );
}

function is_protected_meta( $key, $type ) {
	return str_starts_with( $key, '_' );
}

function wp_kses_post( $value ) {
	return $value;
}

function sanitize_title( $value ) {
	return strtolower( str_replace( ' ', '-', $value ) );
}

function wp_insert_post( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function wp_update_post( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function update_post_meta( ...$args ) {
	if ( null !== Probe::$metadata_writer ) {
		return ( Probe::$metadata_writer )( ...$args );
	}
	Probe::mutation( __FUNCTION__, $args );
}

function delete_post_meta( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function wp_set_post_categories( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function wp_set_post_tags( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function wp_set_object_terms( ...$args ) {
	Probe::mutation( __FUNCTION__, $args );
}

function get_post_meta( $id, $key, $single = false ) {
	Probe::$reads[] = array( $id, $key );
	if ( in_array( $key, Probe::$denied_keys, true ) || in_array( $key, Probe::$denied_by_id[ $id ] ?? array(), true ) || in_array( $id, Probe::$denied_objects, true ) ) {
		throw new UnauthorizedRead( "Read forbidden key {$key} before checking effective permission." );
	}
	return Probe::$metadata[ $id ][ $key ] ?? '';
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function get_permalink( $id ) {
	return 'https://example.test/?p=' . $id;
}

function home_url() {
	return 'https://example.test';
}

function get_option( $key, $default = false ) {
	if ( 'active_plugins' !== $key ) {
		throw new LogicException( "Unexpected option read: {$key}" );
	}
	return Probe::$providers_active ? array( 'wp-seopress/seopress.php' ) : array();
}

function defined( $name ) {
	return in_array( $name, array( 'WPSEO_VERSION', 'SEOPRESS_VERSION' ), true ) ? Probe::$providers_active : \defined( $name );
}

class WP_Query {
	public array $posts;
	public int $found_posts;

	public function __construct( array $args ) {
		Probe::$queries[] = $args;
		if ( isset( $args['meta_query'] ) || isset( $args['meta_key'] ) ) {
			throw new UnauthorizedRead( 'Metadata predicate executed before per-object key authorization.' );
		}
		$posts = array_filter( Probe::$posts, static function ( $post ) use ( $args ) {
			return in_array( $post->post_type, (array) $args['post_type'], true )
				&& ( 'any' === $args['post_status'] || $post->post_status === $args['post_status'] );
		} );
		ksort( $posts, SORT_NUMERIC );
		$this->found_posts = count( $posts );
		if ( ( $args['posts_per_page'] ?? -1 ) > 0 ) {
			$posts = array_slice( $posts, $args['offset'] ?? 0, $args['posts_per_page'], true );
		}

		$this->posts = 'ids' === ( $args['fields'] ?? '' ) ? array_keys( $posts ) : array_values( $posts );
	}
}

function _prime_post_caches( $ids, $terms, $meta ) {
	if ( $meta ) {
		throw new UnauthorizedRead( 'Primed metadata before key authorization.' );
	}
}

class WP_REST_Request {
	public string $path;

	public function __construct( $method, $path ) {
		$this->path = $path;
	}

	public function set_param( $key, $value ): void {}
}

function rest_do_request( $request ) {
	Probe::$head_requests[] = $request->path;
	throw new OpaqueHeadRead( 'Opaque generated head requested without a provable key-level projection.' );
}

function wp_remote_head( $url, $args ) {
	return array( 'response' => array( 'code' => 404 ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_count_posts( $type ) {
	return (object) array( 'publish' => count( array_filter( Probe::$posts, static fn( $post ) => $type === $post->post_type && 'publish' === $post->post_status ) ) );
}

function get_post_type_object( $type ) {
	return (object) array( 'rest_base' => 'post' === $type ? 'posts' : 'pages' );
}
