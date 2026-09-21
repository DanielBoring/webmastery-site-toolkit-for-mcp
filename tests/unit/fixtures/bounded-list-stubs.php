<?php

namespace Wstm121;

require_once dirname( __DIR__, 3 ) . '/includes/class-input.php';

foreach ( array( 'class-list-query.php', 'class-posts.php', 'class-custom-post-types.php', 'class-media.php', 'class-content-hygiene.php', 'class-seo.php' ) as $file ) {
	$path = dirname( __DIR__, 3 ) . '/includes/' . $file;
	if ( is_file( $path ) ) {
		eval( 'namespace Wstm121; use \WP_Error; use \Webmastery_MCP_Response; ' . substr( file_get_contents( $path ), 5 ) );
	}
}

final class Probe {
	public static int $size = 20000;
	public static array $queries = array();
	public static array $caps = array();
	public static array $primed = array();
	public static array $denied = array();
	public static array $abilities = array();
	public static array $sticky = array();
	public static bool $validate_input = false;
	public static string $type = 'post';
	public static string $content = '<!-- wp:paragraph --><p>"quoted" C:\\path\\</p><!-- /wp:paragraph -->';
	public static function reset(): void {
		self::$size = 20000;
		self::$queries = self::$caps = self::$primed = self::$denied = self::$abilities = self::$sticky = array();
		self::$type = 'post';
		self::$validate_input = false;
	}
}

class WP_Query {
	public array $posts;
	public function __construct( array $args ) {
		Probe::$queries[] = $args;
		$ids = range( 1, Probe::$size );
		$order = is_array( $args['orderby'] ?? null ) ? $args['orderby']['ID'] : ( $args['order'] ?? 'ASC' );
		if ( 'DESC' === $order ) {
			$ids = array_reverse( $ids );
		}
		$limit = $args['posts_per_page'] ?? -1;
		$this->posts = array_slice( $ids, $args['offset'] ?? 0, $limit < 0 ? null : $limit );
		if ( empty( $args['ignore_sticky_posts'] ) && 0 === ( $args['offset'] ?? 0 ) ) {
			$this->posts = array_values( array_unique( array_merge( Probe::$sticky, $this->posts ) ) );
		}
	}
}

function _prime_post_caches( $ids, $terms = true, $meta = true ) { Probe::$primed[] = $ids; }
function get_post( $id ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	if ( $id < 1 || $id > Probe::$size ) {
		return null;
	}
	return (object) array(
		'ID' => (int) $id, 'post_type' => Probe::$type, 'post_status' => 'publish',
		'post_title' => 'Tied title', 'post_content' => Probe::$content, 'post_excerpt' => 'Stored \\excerpt "value"',
		'post_name' => 'item-' . $id, 'post_author' => '7', 'post_parent' => '0',
		'post_date' => '2026-01-01 00:00:00', 'post_modified' => '2026-01-01 00:00:00',
		'post_mime_type' => 'image/png', 'guid' => 'https://example.test/old/' . $id . '.png',
	);
}
function current_user_can( $cap, ...$args ) {
	Probe::$caps[] = array( $cap, $args );
	return ! in_array( $args[0] ?? 0, Probe::$denied, true );
}
function wp_register_ability( $name, $args ) {
	Probe::$abilities[ $name ] = Probe::$validate_input ? \Webmastery_MCP_Input::register_args( $args, $name ) : $args;
}
function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
function get_the_author_meta( $key, $id ) { return 'Stored author'; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_get_post_categories( $id, $args ) { return array( 2 ); }
function wp_get_post_tags( $id, $args ) { return array( 3 ); }
function get_object_taxonomies( $type ) { return array(); }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 42 ); }
function get_attached_file( $id ) { return false; }
function get_post_meta( $id, $key, $single ) { return 'Stored alt'; }
function wp_get_attachment_url( $id ) { return 'https://example.test/uploads/' . $id . '.png'; }
function defined( $name ) { return 'WPSEO_VERSION' === $name || \defined( $name ); }
function get_option( $name, $default = false ) { return $default; }
function get_post_type( $id ) { return Probe::$type; }
function get_registered_meta_keys( $type, $subtype = '' ) { return array( '_yoast_wpseo_linkdex' => array(), '_yoast_wpseo_content_score' => array() ); }
function wp_get_post_revisions( $id, $args ) {
	Probe::$queries[] = $args;
	$post = get_post( $id );
	$post->post_type = 'revision';
	return array( $post );
}

final class ReferenceDatabase {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public array $queries = array();
	public array $featured = array();
	public array $matched_patterns = array();
	public ?int $fail_query = null;
	public function prepare( $sql, $values ) { return array( $sql, $values ); }
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	private function failed( $query ): bool {
		$this->queries[] = $query;
		$failed = count( $this->queries ) === $this->fail_query;
		$this->last_error = $failed ? 'PRIVATE DATABASE ERROR' : '';
		return $failed;
	}
	public function get_col( $query ) {
		return $this->failed( $query ) ? null : $this->featured;
	}
	public function get_row( $query, $format ) {
		if ( $this->failed( $query ) ) {
			return null;
		}
		$row = array();
		foreach ( $query[1] as $index => $pattern ) {
			$row[ 'ref_' . $index ] = in_array( $pattern, $this->matched_patterns, true ) ? '1' : '0';
		}
		return $row;
	}
}
