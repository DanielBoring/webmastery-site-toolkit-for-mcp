<?php

declare(strict_types=1);

require_once __DIR__ . '/error-contract-assertions.php';

function wstm108_native_capability_result( array $allcaps, array $caps ): bool {
	$allcaps['exist'] = true;
	unset( $allcaps['do_not_allow'] );
	foreach ( $caps as $cap ) {
		if ( ! is_string( $cap ) ) { throw new RuntimeException( 'WSTM108 Native primitive capability type invalid.' ); }
		if ( empty( $allcaps[ $cap ] ) ) { return false; }
	}
	return true;
}

function wstm108_native_observation_key( int $actor, int $post_id, string $key ): string {
	return $actor . ':' . $post_id . ':' . $key;
}

function wstm108_native_observe( array &$records, array $record ): void {
	if ( array_keys( $record ) !== array( 'actor', 'post_id', 'key', 'allowed' )
		|| ! is_int( $record['actor'] ) || $record['actor'] < 1 || ! is_int( $record['post_id'] ) || $record['post_id'] < 1
		|| ! is_string( $record['key'] ) || ! in_array( $record['key'], array_merge(
			array_values( wstm108_provider_keys( 'yoast' ) ), array_values( wstm108_provider_keys( 'seopress' ) ) ), true )
		|| ! is_bool( $record['allowed'] ) ) {
		throw new RuntimeException( 'WSTM108 Native observation tuple type/domain invalid.' );
	}
	foreach ( $records as $previous ) {
		if ( $previous['actor'] !== $record['actor'] ) { throw new RuntimeException( 'WSTM108 Native observation actor changed within request.' ); }
	}
	$tuple = wstm108_native_observation_key( $record['actor'], $record['post_id'], $record['key'] );
	if ( isset( $records[ $tuple ] ) && $records[ $tuple ] !== $record ) {
		throw new RuntimeException( 'WSTM108 Native capability observation changed within request.' );
	}
	$next = $records; $next[ $tuple ] = $record;
	if ( strlen( base64_encode( json_encode( $next, JSON_THROW_ON_ERROR ) ) ) > 16384 ) {
		throw new RuntimeException( 'WSTM108 Native observation header byte limit exhausted.' );
	}
	$records = $next;
}

function wstm108_native_observations( array $event, int $expected_actor ): array {
	$header = wstm108_rpc_header( $event['headers'] ?? array(), 'x-wstm108-native-reads' );
	if ( '' === $header || strlen( $header ) > 16384 || $expected_actor < 1
		|| ! preg_match( '/^(gateway|individual):administrator$/D', $event['boundary'] ?? '' ) ) {
		throw new RuntimeException( 'WSTM108 Native per-key observation boundary missing or invalid.' );
	}
	$bytes = base64_decode( $header, true );
	if ( false === $bytes || base64_encode( $bytes ) !== $header ) { throw new RuntimeException( 'WSTM108 Native observation encoding invalid.' ); }
	$records = json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR );
	if ( ! is_array( $records ) || array() === $records || json_encode( $records, JSON_THROW_ON_ERROR ) !== $bytes ) {
		throw new RuntimeException( 'WSTM108 Native observation map/domain or unique encoding invalid.' );
	}
	$validated = array();
	foreach ( $records as $tuple => $record ) {
		if ( ! is_array( $record ) || array_keys( $record ) !== array( 'actor', 'post_id', 'key', 'allowed' )
			|| $record['actor'] !== $expected_actor || ! is_int( $record['post_id'] ) || $record['post_id'] < 1
			|| ! is_string( $record['key'] ) || $tuple !== wstm108_native_observation_key( $expected_actor, $record['post_id'], $record['key'] ) ) {
			throw new RuntimeException( 'WSTM108 Native per-key actor/post/key/type association refused.' );
		}
		wstm108_native_observe( $validated, $record );
		if ( $record['key'] === ( $event['request_headers']['X-WSTM108-Deny-Key'] ?? null )
			&& (string) $record['post_id'] === ( $event['request_headers']['X-WSTM108-Deny-Post'] ?? null )
			&& false !== $record['allowed'] ) { throw new RuntimeException( 'WSTM108 Restricted native capability was allowed.' ); }
	}
	return $validated;
}

function wstm108_native_readability( array $event, int $post_id, array $keys, int $expected_actor ): array {
	$records = wstm108_native_observations( $event, $expected_actor );
	if ( $post_id < 1 || array() === $keys || count( $keys ) !== count( array_unique( $keys ) ) || count( $records ) !== count( $keys ) ) {
		throw new RuntimeException( 'WSTM108 Native observation requested post/key domain invalid.' );
	}
	$result = array();
	foreach ( $keys as $key ) {
		if ( ! is_string( $key ) ) { throw new RuntimeException( 'WSTM108 Native requested key type invalid.' ); }
		$record = $records[ wstm108_native_observation_key( $expected_actor, $post_id, $key ) ] ?? null;
		if ( null === $record ) { throw new RuntimeException( 'WSTM108 Native requested post/key observation missing.' ); }
		$result[ $key ] = $record['allowed'];
	}
	return $result;
}

function wstm108_rpc_header( array $headers, string $name ): string {
	$matches = array();
	foreach ( $headers as $key => $value ) {
		if ( ! is_string( $key ) ) {
			throw new RuntimeException( 'WSTM108 Nontyped response header.' );
		}
		if ( strtolower( $key ) === $name ) {
			if ( ! is_string( $value ) ) { throw new RuntimeException( 'WSTM108 Nontyped response header.' ); }
			$matches[] = $value;
		}
	}
	if ( count( $matches ) > 1 ) { throw new RuntimeException( 'WSTM108 Duplicate response header.' ); }
	return $matches[0] ?? '';
}

/** Replay setup and tool envelopes from original bytes, before resolving any intent. */
function wstm108_rpc_response( array $event ): array {
	if ( false !== ( $event['transport_error'] ?? null ) || ! is_int( $event['status'] ?? null )
		|| $event['status'] < 200 || $event['status'] >= 300 ) {
		throw new RuntimeException( 'WSTM108 HTTP transport failure (raw evidence retained), status ' . ( $event['status'] ?? 0 ) );
	}
	if ( ! is_array( $event['headers'] ?? null ) || '1' !== wstm108_rpc_header( $event['headers'], 'x-wstm108-fixture' ) ) {
		throw new RuntimeException( 'WSTM108 HTTP fixture missing or HTTP opt-in absent.' );
	}
	$request = $event['request'] ?? null;
	$method = $event['method'] ?? null;
	if ( ! is_array( $request ) || '2.0' !== ( $request['jsonrpc'] ?? null )
		|| ! is_string( $method ) || $method !== ( $request['method'] ?? null )
		|| ! ( $request['params'] ?? null ) instanceof stdClass || ! is_string( $event['body'] ?? null ) ) {
		throw new RuntimeException( 'WSTM108 Original JSON-RPC request association refused.' );
	}
	if ( 'notifications/initialized' === $method ) {
		if ( array_key_exists( 'id', $request ) || 202 !== $event['status'] || '' !== $event['body'] ) {
			throw new RuntimeException( 'WSTM108 Initialized notification must be an empty202 response to a request without ID.' );
		}
		return array();
	}
	$decoded = json_decode( $event['body'], false, 512, JSON_THROW_ON_ERROR );
	if ( ! is_int( $request['id'] ?? null ) || $request['id'] < 1 || ! $decoded instanceof stdClass
		|| property_exists( $decoded, 'error' ) || '2.0' !== ( $decoded->jsonrpc ?? null )
		|| $request['id'] !== ( $decoded->id ?? null ) || ! ( $decoded->result ?? null ) instanceof stdClass ) {
		throw new RuntimeException( 'WSTM108 Typed JSON-RPC response identity or envelope refused.' );
	}
	if ( 'initialize' === $method && ( ! is_string( $request['params']->protocolVersion ?? null )
		|| $request['params']->protocolVersion !== ( $decoded->result->protocolVersion ?? null )
		|| ! ( $decoded->result->capabilities ?? null ) instanceof stdClass
		|| ! ( $decoded->result->serverInfo ?? null ) instanceof stdClass
		|| ! is_string( $decoded->result->serverInfo->name ?? null )
		|| ! is_string( $decoded->result->serverInfo->version ?? null ) ) ) {
		throw new RuntimeException( 'WSTM108 Initialize protocol or native result refused.' );
	}
	return get_object_vars( $decoded );
}

function wstm108_provider_keys( string $provider ): array {
	$yoast = array(
		'title' => '_yoast_wpseo_title', 'meta_description' => '_yoast_wpseo_metadesc',
		'focus_keyphrase' => '_yoast_wpseo_focuskw', 'canonical_url' => '_yoast_wpseo_canonical',
		'breadcrumb_title' => '_yoast_wpseo_bctitle', 'schema_page_type' => '_yoast_wpseo_schema_page_type',
		'schema_article_type' => '_yoast_wpseo_schema_article_type', 'opengraph_title' => '_yoast_wpseo_opengraph-title',
		'opengraph_description' => '_yoast_wpseo_opengraph-description', 'opengraph_image' => '_yoast_wpseo_opengraph-image',
		'twitter_title' => '_yoast_wpseo_twitter-title', 'twitter_description' => '_yoast_wpseo_twitter-description',
		'twitter_image' => '_yoast_wpseo_twitter-image', 'seo_score' => '_yoast_wpseo_linkdex',
		'readability_score' => '_yoast_wpseo_content_score', 'inclusive_language_score' => '_yoast_wpseo_inclusive_language_score',
		'primary_category' => '_yoast_wpseo_primary_category', 'cornerstone' => '_yoast_wpseo_is_cornerstone',
		'robots_noindex' => '_yoast_wpseo_meta-robots-noindex', 'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
		'robots_advanced' => '_yoast_wpseo_meta-robots-adv',
	);
	$seopress = array(
		'title' => '_seopress_titles_title', 'meta_description' => '_seopress_titles_desc',
		'focus_keywords' => '_seopress_analysis_target_kw', 'canonical_url' => '_seopress_robots_canonical',
		'opengraph_title' => '_seopress_social_fb_title', 'opengraph_description' => '_seopress_social_fb_desc',
		'opengraph_image' => '_seopress_social_fb_img', 'twitter_title' => '_seopress_social_twitter_title',
		'twitter_description' => '_seopress_social_twitter_desc', 'twitter_image' => '_seopress_social_twitter_img',
		'primary_category' => '_seopress_robots_primary_cat', 'robots_noindex' => '_seopress_robots_index',
		'robots_nofollow' => '_seopress_robots_follow', 'robots_noimageindex' => '_seopress_robots_imageindex',
		'robots_noarchive' => '_seopress_robots_archive', 'robots_nosnippet' => '_seopress_robots_snippet',
		'breadcrumb_title' => '_seopress_robots_breadcrumbs', 'news_sitemap_disabled' => '_seopress_news_disabled',
		'video_sitemap_disabled' => '_seopress_video_disabled',
	);
	return 'yoast' === $provider ? $yoast : $seopress;
}

function wstm108_unavailable(): array {
	return array(
		'code' => 'forbidden', 'reason' => 'metadata_not_readable',
		'message' => 'This metadata field is unavailable under the effective key-level permission.',
		'details' => (object) array(),
	);
}

function wstm108_fields( string $kind ): array {
	$fields = array(
		'post' => array( 'title', 'content', 'excerpt', 'slug', 'url', 'author_name' ),
		'revision' => array( 'author_name', 'title', 'content', 'excerpt' ),
		'block' => array( 'block_name', 'text', 'html', 'attrs' ),
		'media' => array( 'title', 'caption', 'alt_text', 'url', 'filename' ),
		'comment' => array( 'author', 'author_email', 'author_url', 'content' ),
		'user' => array( 'display_name', 'nicename', 'url', 'login', 'email' ),
		'account' => array( 'login', 'email', 'last_login' ),
		'application' => array( 'user_login', 'app_name' ),
		'metrics' => array( 'title', 'url', 'slug', 'yoast_meta_description', 'seopress_meta_description', 'yoast_focus_keyword', 'seopress_focus_keywords' ),
		'provider' => array( 'title', 'url', 'metadata', 'raw_meta' ),
		'score' => array( 'title', 'url', 'score' ),
		'meta' => array( 'meta' ),
		'meta-update' => array( 'meta_key', 'previous_value', 'current_value' ),
		'meta-delete' => array( 'meta_key' ),
		'content-patch' => array( 'content' ),
		'patch-target' => array( 'heading_text' ),
		'sitemap' => array( 'url', 'entries' ),
		'robots' => array( 'url' ),
	);
	if ( ! isset( $fields[ $kind ] ) ) { throw new RuntimeException( 'WSTM108 Unknown record kind.' ); }
	return $fields[ $kind ];
}

function wstm108_gateway_descriptor( array $tools, string $operation ): object {
	$matches = array_values( array_filter( $tools, static function ( $tool ) use ( $operation ) {
		$properties = $tool->inputSchema->properties ?? null;
		if ( 'execute' === $operation ) { return isset( $properties->ability_name, $properties->parameters ); }
		return isset( $properties->ability_name ) && ! isset( $properties->parameters );
	} ) );
	if ( 1 !== count( $matches ) ) { throw new RuntimeException( 'WSTM108 Cannot discover gateway ' . $operation . ' descriptor by schema.' ); }
	return $matches[0];
}

function wstm108_wire_canonical( $value ): string {
	$value = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ), false, 512, JSON_THROW_ON_ERROR );
	$normalize = static function ( $item ) use ( &$normalize ) {
		if ( $item instanceof stdClass ) {
			$properties = get_object_vars( $item );
			ksort( $properties );
			foreach ( $properties as &$property ) {
				$property = $normalize( $property );
			}
			return (object) $properties;
		}
		if ( is_array( $item ) ) {
			return array_map( $normalize, $item );
		}
		return $item;
	};
	return serialize( $normalize( $value ) );
}

function wstm108_typed_tool( $tool, bool $gateway ): array {
	if ( ! $tool instanceof stdClass
		|| ( property_exists( $tool, 'isError' ) && ! is_bool( $tool->isError ) )
		|| ! is_array( $tool->content ?? null ) || array( 0 ) !== array_keys( $tool->content )
		|| ! $tool->content[0] instanceof stdClass || 'text' !== ( $tool->content[0]->type ?? null )
		|| ! is_string( $tool->content[0]->text ?? null ) ) {
		throw new RuntimeException( 'WSTM108 Tool result must retain its original object and one JSON text block.' );
	}
	if ( true === ( $tool->isError ?? null ) ) {
		$outer = get_object_vars( $tool );
		$outer['content'] = array( get_object_vars( $tool->content[0] ) );
		return wstm118_wire_error( $outer );
	}
	$text = json_decode( $tool->content[0]->text, false, 512, JSON_THROW_ON_ERROR );
	$result = $tool->structuredContent ?? $text;
	if ( wstm108_wire_canonical( $result ) !== wstm108_wire_canonical( $text ) ) {
		throw new RuntimeException( 'WSTM108 Structured and original text payload types or values differ.' );
	}
	// Only the documented gateway wrapper is unwrapped, never a stored subtree.
	if ( $gateway && $result instanceof stdClass && true === ( $result->success ?? null )
		&& ( $result->data ?? null ) instanceof stdClass && true === ( $result->data->success ?? null ) ) {
		$result = $result->data;
	}
	if ( ! $result instanceof stdClass || array( 'success', 'data' ) !== array_keys( get_object_vars( $result ) )
		|| true !== $result->success || ! $result->data instanceof stdClass ) {
		throw new RuntimeException( 'WSTM108 Successful payload must retain its canonical object envelope and object data.' );
	}
	return get_object_vars( $result );
}

function wstm108_compare_typed_record( $actual, array $expected, array $fields, string $kind ): void {
	if ( ! $actual instanceof stdClass ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' record is not an original JSON object.' );
	}
	$present = array_values( array_filter( $fields, static fn( $key ) => array_key_exists( $key, $expected ) ) );
	if ( ( $actual->untrusted_fields ?? null ) !== $present ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' marker differs from the exact present-field inventory.' );
	}
	$record = clone $actual;
	unset( $record->untrusted_fields );
	if ( wstm108_wire_canonical( $record ) !== wstm108_wire_canonical( (object) $expected ) ) {
		throw new RuntimeException( 'WSTM108 ' . $kind . ' original object/list/scalar shape or stored value changed.' );
	}
}
