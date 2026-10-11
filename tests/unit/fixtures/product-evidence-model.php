<?php

declare(strict_types=1);

final class Wstm_Product_Comment_Cast_Model {
	public string $comment_ID = '43';
	public string $comment_content;
	protected $children = null;
	protected bool $populated_children = false;
	protected array $post_fields = array( 'ID' => 42, 'post_type' => 'post' );

	public function __construct( string $content ) { $this->comment_content = $content; }
}

final class Wstm_Product_Evidence_Model {
	public static function snapshot(): array {
		return array_fill_keys( array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships',
			'comments', 'commentmeta', 'users', 'usermeta', 'options' ), array( 'rows' => 0, 'sha256' => hash( 'sha256', '' ) ) );
	}
	public static function typed( string $kind, array $expected ): object {
		$record = json_decode( json_encode( (object) $expected, JSON_THROW_ON_ERROR ), false, 512, JSON_THROW_ON_ERROR );
		$record->untrusted_fields = array_values( array_filter( wstm108_fields( $kind ), static fn( $field ) => array_key_exists( $field, $expected ) ) );
		return $record;
	}

	public static function untrusted_model(): array {
		$native = Wstm108_Plan::native_from_manifest( dirname( __DIR__, 2 ) . '/e2e/abilities-manifest.json' );
		$labels = Wstm108_Plan::labels( $native ); $registered = array(); $descriptors = array(); $models = array();
		foreach ( Wstm108_Plan::abilities( $native ) as $index => $name ) {
			$hints = array( 'readonly' => true, 'destructive' => 'webmastery-site-toolkit-for-mcp/bulk-publish-posts' === $name, 'idempotent' => false, 'title' => 'Owned ability ' . $index );
			$registered[ $name ] = array( 'name' => $name, 'label' => $hints['title'], 'description' => 'Owned model registration',
				'input_schema' => array(), 'output_schema' => array(), 'meta' => array( 'annotations' => $hints ) );
			$descriptors[ $name ] = (object) array( 'name' => 'owned_tool_' . $index, 'title' => $hints['title'], 'inputSchema' => new stdClass(),
				'annotations' => (object) array( 'readOnlyHint' => true, 'destructiveHint' => $hints['destructive'], 'idempotentHint' => false, 'title' => $hints['title'] ) );
		}
		$literal = 'INERT TEST DATA: "quoted" C:\\literal';
		$nested = array( 'untrusted_fields' => array( 'stored, not annotation' ), 'success' => false, 'error' => array( 'code' => 'literal' ), 'list' => array( 1, true, null ) );
		$providers = array( 'yoast' => true, 'seopress' => true );
		$readable = array_fill_keys( array_merge( array_values( wstm108_provider_keys( 'yoast' ) ), array_values( wstm108_provider_keys( 'seopress' ) ) ), true );
		$readable['_yoast_wpseo_canonical'] = false; $readable['_seopress_robots_follow'] = false;
		$post = array( 'id' => 42, 'title' => $literal, 'content' => $literal, 'excerpt' => $literal, 'type' => 'post' );
		$revision = array( 'id' => 43, 'title' => $literal, 'content' => $literal );
		$metrics = static function ( array $policy ) use ( $literal ): array {
			$result = array( 'title' => $literal, 'url' => 'http://localhost/owned', 'word_count' => 3, 'title_length' => strlen( $literal ) );
			foreach ( array( 'yoast_meta_description' => '_yoast_wpseo_metadesc', 'seopress_meta_description' => '_seopress_titles_desc',
				'yoast_focus_keyword' => '_yoast_wpseo_focuskw', 'seopress_focus_keywords' => '_seopress_analysis_target_kw' ) as $field => $key ) {
				if ( $policy[ $key ] ) { $result[ $field ] = $literal; }
			}
			return $result;
		};
		$provider_record = static function ( string $provider, array $policy ) use ( $nested, $literal ): array {
			$record = array( $provider . '_active' => true, 'post_id' => 42, 'title' => $literal, 'url' => 'http://localhost/owned',
				'metadata' => array(), 'raw_meta' => array(), 'unavailable_fields' => array() );
			foreach ( wstm108_provider_keys( $provider ) as $field => $key ) {
				if ( ! $policy[ $key ] ) { $record['unavailable_fields'][ $field ] = wstm108_unavailable(); continue; }
				$value = 'opengraph_title' === $field ? $nested : $literal;
				$record['metadata'][ $field ] = $value; $record['raw_meta'][ $field ] = array( 'key' => $key, 'value' => $value );
			}
			return $record;
		};
		$success = static fn( object $data ) => array( 'success' => true, 'data' => $data );
		foreach ( $labels as $label ) {
			$case = array( 'label' => $label, 'passed' => true ); $responses = array(); $abilities = array(); $inputs = array();
			if ( 0 === strpos( $label, 'annotations:' ) ) {
				$name = substr( $label, 12 ); $info = (object) array( 'name' => $name, 'meta' => (object) array( 'annotations' => (object) $registered[ $name ]['meta']['annotations'] ) );
				$case['descriptor'] = $descriptors[ $name ];
				$case['get_info'] = array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => (object) array( 'structuredContent' => $info, 'content' => array( (object) array( 'type' => 'text', 'text' => json_encode( $info ) ) ) ) );
			} else {
				list( $boundary, $suffix ) = explode( ':', $label, 2 ); $data = null;
				$semantic = array( 'schema' => 'wstm108-semantic-replay-v1' );
				if ( preg_match( '/^(post|page|wstm108_record):(get|list|update|create)(?::summary-(?:default|explicit))?$/D', $suffix, $match ) ) {
					$expected = $post; if ( false !== strpos( $suffix, ':summary-' ) ) { unset( $expected['content'] ); }
					$case['expected'] = $expected; $record = self::typed( 'post', $expected );
					$data = 'list' === $match[2] ? (object) array( 'items' => array( $record ) ) : $record;
					$type = 'wstm108_record' === $match[1] ? 'cpt-wstm108-record' : $match[1];
					$abilities = array( $match[2] . '-' . ( 'list' === $match[2] && 'wstm108_record' !== $match[1] ? $type . 's' : $type ) );
				} elseif ( preg_match( '/^(media|comments):(list|get|update|reply)$/D', $suffix, $match ) ) {
					$kind = 'media' === $match[1] ? 'media' : 'comment'; $expected = array( 'id' => 42, 'content' => $literal );
					if ( 'media' === $kind ) { $expected = array( 'id' => 42, 'title' => $literal, 'caption' => $literal ); }
					$case['expected'] = $expected; $record = self::typed( $kind, $expected );
					$data = 'list' === $match[2] ? (object) array( 'items' => array( $record ) ) : $record;
					$abilities = array( $match[2] . ( 'media' === $kind ? '-media' : ( 'list' === $match[2] ? '-comments' : '-comment' ) ) );
				} elseif ( preg_match( '/^user:(administrator|reader):(list|get)$/D', $suffix, $match ) ) {
					$case['expected'] = array( 'id' => 42, 'display_name' => $literal ); $record = self::typed( 'user', $case['expected'] );
					$data = 'list' === $match[2] ? (object) array( 'items' => array( $record ) ) : $record; $abilities = array( $match[2] . ( 'list' === $match[2] ? '-users' : '-user' ) );
				} elseif ( 'blocks' === $suffix ) {
					$case['expected'] = array( array( 'html' => $literal, 'attrs' => $nested, 'hash' => hash( 'sha256', $literal ) ) );
					$data = (object) array( 'blocks' => array( self::typed( 'block', $case['expected'][0] ) ) ); $abilities = array( 'list-content-blocks' );
				} elseif ( 'patch-content-block' === $suffix ) {
					$hash = hash( 'sha256', $literal ); $case['expected'] = array( 'id' => 42, 'type' => 'post', 'target' => array( 'type' => 'block_path', 'block_path' => '0' ),
						'block_hash_before' => $hash, 'block_hash_after' => $hash, 'content_hash_before' => $hash, 'content_hash_after' => $hash, 'content' => $literal );
					$case['semantic'] = $semantic + array( 'before' => $literal, 'after' => $literal, 'blocks_before' => array( array( 'hash' => $hash ) ), 'blocks_after' => array( array( 'hash' => $hash ) ) );
					$data = self::typed( 'content-patch', $case['expected'] ); $abilities = array( 'patch-content-block' );
					$inputs = array( array( 'content_id' => 42, 'target_type' => 'block_path', 'block_path' => '0',
						'expected_block_hash' => $hash, 'expected_content_hash' => $hash ) );
				} elseif ( 0 === strpos( $suffix, 'patch-post-content:' ) ) {
					$target = array( 'type' => substr( $suffix, strlen( 'patch-post-content:' ) ) );
					if ( 'heading' === $target['type'] ) { $target += array( 'heading_text' => $literal, 'heading_level' => 2 ); }
					$case['expected'] = array( 'id' => 42, 'type' => 'page', 'target' => $target, 'replaced_blocks' => 'heading' === $target['type'] ? 1 : null,
						'content_hash_before' => hash( 'sha256', $literal ), 'content_hash_after' => hash( 'sha256', $literal ), 'post' => $post );
					$data = (object) $case['expected']; $data->target = self::typed( 'patch-target', $target ); $data->post = self::typed( 'post', $post ); $abilities = array( 'patch-post-content' );
				} elseif ( in_array( $suffix, array( 'post:revisions', 'page:revisions' ), true ) ) {
					$case['semantic'] = $semantic + array( 'revisions' => array( $revision ), 'revision' => $revision, 'post' => $post );
					$responses[] = $success( (object) array( 'revisions' => array( self::typed( 'revision', $revision ) ) ) );
					$data = (object) array( 'revision' => self::typed( 'revision', $revision ), 'post' => self::typed( 'post', $post ) );
					$abilities = array( 'list-revisions', 'restore-revision' ); $inputs = array( array( 'post_id' => 42 ), array( 'revision_id' => 43 ) );
				} elseif ( 'user-access-audit' === $suffix ) {
					$account = array( 'id' => 42, 'login' => $literal, 'email' => 'owned@example.test', 'registered' => '2026-10-09', 'last_login' => $literal );
					$app = array( 'user_id' => 42, 'user_login' => $literal, 'app_name' => $literal, 'last_used' => null );
					$case['semantic'] = $semantic + array( 'account' => $account, 'application' => $app );
					$data = (object) array( 'admin_accounts' => array( self::typed( 'account', $account ) ), 'application_passwords' => array( self::typed( 'application', $app ) ) ); $abilities = array( 'user-access-audit' );
				} elseif ( 'standalone-meta-read-update' === $suffix ) {
					$read = array( 'post_id' => 42, 'meta' => array( 'wstm108_payload' => array( $nested ) ) );
					$update = array( 'post_id' => 42, 'meta_key' => 'wstm108_payload', 'updated' => true, 'previous_value' => $nested, 'current_value' => $nested );
					$case['semantic'] = $semantic + array( 'read' => $read, 'update' => $update, 'before' => $nested, 'value' => $nested, 'after' => $nested );
					$responses[] = $success( self::typed( 'meta', $read ) ); $data = self::typed( 'meta-update', $update ); $abilities = array( 'get-post-meta', 'update-post-meta' );
					$inputs = array( array( 'post_id' => 42, 'meta_key' => 'wstm108_payload' ),
						array( 'post_id' => 42, 'meta_key' => 'wstm108_payload', 'meta_value' => $nested ) );
				} elseif ( 'standalone-meta-delete' === $suffix ) {
					$case['expected'] = array( 'post_id' => 42, 'meta_key' => 'wstm108_delete_' . $boundary, 'deleted_count' => 2 );
					$case['semantic'] = $semantic + array( 'before' => array( $nested, $literal ), 'after' => array() ); $data = self::typed( 'meta-delete', $case['expected'] ); $abilities = array( 'delete-post-meta' );
					$inputs = array( array( 'post_id' => 42, 'meta_key' => $case['expected']['meta_key'] ) );
				} elseif ( 0 === strpos( $suffix, 'seo-key-policy:' ) ) {
					$key = substr( $suffix, strlen( 'seo-key-policy:' ) ); $abilities = array( 'get-post-meta' ); $inputs = array( array( 'post_id' => 42, 'meta_key' => $key ) );
					if ( ! $readable[ $key ] || in_array( $key, array( '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score',
						'_seopress_news_disabled', '_seopress_video_disabled' ), true ) ) { $case['response'] = self::denial(); }
					else { $case['expected'] = array( 'post_id' => 42, 'meta' => array( $key => array( $literal ) ) ); $data = self::typed( 'meta', $case['expected'] ); }
				} elseif ( 'seo-metrics' === $suffix ) {
					$case['expected_metrics'] = $metrics( $readable ); $case['readable'] = $readable; $case['providers'] = $providers;
					$data = (object) array( 'metrics' => self::typed( 'metrics', $case['expected_metrics'] ), 'issues' => array(), 'good' => array() ); $abilities = array( 'seo-analyze-post' );
				} elseif ( in_array( $suffix, array( 'yoast:metadata', 'seopress:metadata' ), true ) ) {
					$provider = explode( ':', $suffix )[0]; $case['expected'] = $provider_record( $provider, $readable );
					$case['readable'] = $readable; $case['providers'] = $providers; $case['nested_expected'] = $nested;
					$data = self::typed( 'provider', $case['expected'] ); $abilities = array( 'get-' . $provider . '-metadata' );
				} elseif ( in_array( $suffix, array( 'get-seo-scores', 'get-readability-scores' ), true ) ) {
					$case['expected'] = array( 'post_id' => 42, 'title' => $literal, 'url' => 'http://localhost/owned', 'post_type' => 'post', 'modified_gmt' => '2026-10-09', 'score' => 42 );
					$case['providers'] = $providers; $case['score_key'] = 'get-seo-scores' === $suffix ? '_yoast_wpseo_linkdex' : '_yoast_wpseo_content_score';
					$other = $case['expected']; $other['post_id'] = 43;
					$data = (object) array( 'items' => array( self::typed( 'score', $case['expected'] ), self::typed( 'score', $other ) ) ); $abilities = array( $suffix );
				} elseif ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
					$field = substr( $suffix, strlen( 'seo-forbidden-read-trap:' ) ); $provider = 0 === strpos( $field, 'yoast_' ) ? 'yoast' : 'seopress';
					$key = wstm108_provider_keys( $provider )[ false !== strpos( $field, 'description' ) ? 'meta_description' : ( 'yoast' === $provider ? 'focus_keyphrase' : 'focus_keywords' ) ];
					$restricted = $readable; $restricted[ $key ] = false; $expected_metrics = $metrics( $restricted );
					$metadata = $provider_record( $provider, $restricted ); $case['readable'] = $readable; $case['providers'] = $providers;
					$case['native_provider_readable'] = array_intersect_key( $restricted, array_flip( array_values( wstm108_provider_keys( $provider ) ) ) );
					$case['semantic'] = $semantic + array( 'field' => $field, 'key' => $key, 'provider' => $provider, 'restricted' => $restricted, 'metrics' => $expected_metrics, 'unavailable' => wstm108_unavailable(), 'metadata' => $metadata );
					$responses[] = $success( (object) array( 'metrics' => self::typed( 'metrics', $expected_metrics ), 'unavailable_fields' => (object) array( $field => (object) wstm108_unavailable() ) ) );
					$data = self::typed( 'provider', $metadata ); $abilities = array( 'seo-analyze-post', 'get-' . $provider . '-metadata' );
				} elseif ( in_array( $suffix, array( 'no-opaque-head', 'no-combined-seo-alias-write', 'private-image-url-still-denied' ), true ) ) {
					$names = array( 'no-opaque-head' => 'get-yoast-metadata', 'no-combined-seo-alias-write' => 'update-post', 'private-image-url-still-denied' => 'upload-image' );
					$reasons = array( 'no-opaque-head' => 'generated_head_key_authorization_unavailable', 'no-combined-seo-alias-write' => 'metadata_requires_separate_call', 'private-image-url-still-denied' => 'invalid_url' );
					$case['response'] = self::denial( $reasons[ $suffix ] ); $abilities = array( $names[ $suffix ] );
					if ( 'no-combined-seo-alias-write' === $suffix ) { $case['semantic'] = $semantic + array( 'before' => array( 'owned' => $nested ), 'after' => array( 'owned' => $nested ) ); }
				} elseif ( 'seo-site-overview-records' === $suffix ) {
					$case['expected'] = array( 'sitemap' => array( 'url' => 'http://localhost/sitemap_index.xml', 'accessible' => false ), 'robots_txt' => array( 'url' => 'http://localhost/robots.txt', 'accessible' => true ) );
					$data = (object) array( 'sitemap' => self::typed( 'sitemap', $case['expected']['sitemap'] ), 'robots_txt' => self::typed( 'robots', $case['expected']['robots_txt'] ) ); $abilities = array( 'seo-site-overview' );
				} elseif ( 0 === strpos( $suffix, 'subscriber-denied:' ) ) {
					$case['expected'] = self::denial(); $case['response'] = $case['expected']; $abilities = array( substr( $suffix, strlen( 'subscriber-denied:' ) ) );
					$case['semantic'] = $semantic + array( 'before' => array( $post, (array) new Wstm_Product_Comment_Cast_Model( $literal ) ),
						'after' => array( $post, (array) new Wstm_Product_Comment_Cast_Model( $literal ) ) );
				} else { throw new RuntimeException( 'Unmodeled independently planned case: ' . $label ); }
				if ( null !== $data ) { $case['response'] = $success( $data ); }
				$responses[] = $case['response'];
				if ( array() === $inputs ) { $inputs = array_fill( 0, count( $abilities ), array( 'post_id' => 42 ) ); }
			}
			$models[] = array( 'case' => $case, 'responses' => $responses, 'abilities' => $abilities, 'inputs' => $inputs );
		}
		return array( 'native' => $native, 'registered' => $registered, 'descriptors' => $descriptors, 'models' => $models, 'providers' => $providers );
	}

	public static function denial( string $reason = 'forbidden' ): array {
		return array( 'success' => false, 'error' => array( 'code' => wstm118_expected_code( $reason ), 'reason' => $reason, 'message' => 'Owned canonical denial.', 'details' => new stdClass() ) );
	}

	public static function setup_events( string $boundary, array $tools ): array {
		$token = hash( 'sha256', 'modeled-session:' . $boundary );
		$headers = array( 'x-wstm108-fixture' => '1', 'mcp-session-id' => $token );
		$params = (object) array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(),
			'clientInfo' => array( 'name' => 'wstm108-' . substr( self::context()['run'], 0, 16 ) . ':' . $boundary, 'version' => '1' ) );
		$initialize = array( 'boundary' => $boundary, 'method' => 'initialize', 'status' => 200, 'transport_error' => false,
			'headers' => $headers, 'request_headers' => array(),
			'request' => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => $params ),
			'body' => json_encode( (object) array( 'jsonrpc' => '2.0', 'id' => 1,
				'result' => (object) array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(),
					'serverInfo' => (object) array( 'name' => 'Native modeled server', 'version' => '1' ) ) ), JSON_THROW_ON_ERROR ) );
		$notification = array( 'boundary' => $boundary, 'method' => 'notifications/initialized', 'status' => 202,
			'transport_error' => false, 'headers' => $headers, 'request_headers' => array( 'Mcp-Session-Id' => $token ),
			'request' => array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => new stdClass() ), 'body' => '' );
		$catalog = array( 'boundary' => $boundary, 'method' => 'tools/list', 'status' => 200, 'transport_error' => false,
			'headers' => $headers, 'request_headers' => array( 'Mcp-Session-Id' => $token ),
			'request' => array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new stdClass() ),
			'body' => json_encode( (object) array( 'jsonrpc' => '2.0', 'id' => 2, 'result' => (object) array( 'tools' => $tools ) ), JSON_THROW_ON_ERROR ) );
		return array( $initialize, $notification, $catalog );
	}

	public static function untrusted_export( string $root, ?callable $mutate = null, ?callable $mutate_setup = null ): array {
		$model = self::untrusted_model(); $context = self::context(); $bytes = json_encode( $context );
		$binding = array( 'owner' => $context['run'], 'project' => $context['project'], 'identity_kind' => 'package', 'identity_sha256' => $context['identity_sha256'] );
		$wire_root = $root . '/wire'; if ( ! mkdir( $wire_root, 0700 ) ) { throw new RuntimeException( 'Owned wire fixture collision.' ); }
		$wire = Wstm108_PrivateWire::create( $wire_root, $binding, hash( 'sha256', $bytes ), static function (): void {} );
		$evidence = new Wstm108_Evidence( $root . '/runner.json' ); $evidence->attach( $wire, 'runner' );
		$event = static function ( array $response, string $boundary, string $ability, array $input, int $id ) use ( $model ): array {
			$arguments = 0 === strpos( $boundary, 'gateway:' ) ? array( 'ability_name' => 'webmastery-site-toolkit-for-mcp/' . $ability, 'parameters' => (object) $input ) : (object) $input;
			$name = 0 === strpos( $boundary, 'gateway:' ) ? 'owned_gateway_execute' : $model['descriptors'][ 'webmastery-site-toolkit-for-mcp/' . $ability ]->name;
			$tool = false === $response['success']
				? (object) array( 'isError' => true, 'content' => array( (object) array( 'type' => 'text', 'text' => json_encode( $response ) ) ) )
				: (object) array( 'isError' => false, 'content' => array( (object) array( 'type' => 'text', 'text' => json_encode( $response ) ) ), 'structuredContent' => (object) $response );
			return array( 'boundary' => $boundary, 'method' => 'tools/call', 'status' => 200, 'transport_error' => false,
				'request' => array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => (object) array( 'name' => $name, 'arguments' => $arguments ) ),
				'body' => json_encode( (object) array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $tool ), JSON_THROW_ON_ERROR ) );
		};
		$cases = array(); $sequence = 0;
		$gateway_tools = array();
		foreach ( array( 'execute', 'info', 'discover' ) as $operation ) {
			$properties = array();
			if ( 'discover' !== $operation ) { $properties['ability_name'] = new stdClass(); }
			if ( 'execute' === $operation ) { $properties['parameters'] = new stdClass(); }
			$gateway_tools[] = (object) array( 'name' => 'owned_gateway_' . $operation, 'inputSchema' => (object) array( 'properties' => (object) $properties ) );
		}
		foreach ( array( 'administrator', 'subscriber', 'reader' ) as $actor ) {
			foreach ( array( 'gateway', 'individual' ) as $boundary ) {
				$tools = 'gateway' === $boundary ? $gateway_tools : array_values( $model['descriptors'] );
				foreach ( self::setup_events( $boundary . ':' . $actor, $tools ) as $original ) {
					if ( null !== $mutate_setup ) { $mutate_setup( $original ); }
					$id = $evidence->append( $original );
					$parser = array( 'initialize' => 'initialize', 'notifications/initialized' => 'notification', 'tools/list' => 'catalog' )[ $original['method'] ];
					$validation = 'catalog' === $parser ? array( 'tools' => array_map( static fn( $tool ) => Wstm108_Evidence::tool( $tool ), $tools ),
						'next_cursor_sha256' => hash( 'sha256', wstm108_wire_canonical( null ) ) ) : null;
					$evidence->validate( $id, $parser, $validation );
				}
			}
		}
		foreach ( $model['models'] as $entry ) {
			$case = $entry['case']; $evidence->begin_case();
			if ( 0 === strpos( $case['label'], 'annotations:' ) ) {
				$name = substr( $case['label'], 12 );
				$info = array( 'boundary' => 'gateway:administrator', 'method' => 'tools/call', 'status' => 200, 'transport_error' => false,
					'request' => array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => (object) array( 'name' => 'owned_gateway_info', 'arguments' => array( 'ability_name' => $name ) ) ),
					'body' => json_encode( $case['get_info'] ) );
				$id = $evidence->append( $info ); $evidence->validate( $id, 'gateway-info' );
			} else {
				list( $boundary, $suffix ) = explode( ':', $case['label'], 2 );
				if ( isset( $case['readable'] ) ) {
					$keys = in_array( $suffix, array( 'yoast:metadata', 'seopress:metadata' ), true )
						? array_values( wstm108_provider_keys( explode( ':', $suffix )[0] ) )
						: array( '_yoast_wpseo_metadesc', '_seopress_titles_desc', '_yoast_wpseo_focuskw', '_seopress_analysis_target_kw' );
					if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) {
						$case['native_provider_readable'] = array_intersect_key( $case['semantic']['restricted'],
							array_flip( array_values( wstm108_provider_keys( $case['semantic']['provider'] ) ) ) );
						$case['readable'] = $case['semantic']['restricted'];
					}
					$case['readable'] = array_replace( array_fill_keys( $keys, false ), array_intersect_key( $case['readable'], array_flip( $keys ) ) );
					if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) { $case['semantic']['restricted'] = $case['readable']; }
				}
				$actor = 0 === strpos( $suffix, 'subscriber-denied:' ) ? 'subscriber' : ( 0 === strpos( $suffix, 'user:reader:' ) ? 'reader' : 'administrator' );
				foreach ( $entry['responses'] as $index => $response ) {
					$original = $event( $response, $boundary . ':' . $actor, $entry['abilities'][ $index ], $entry['inputs'][ $index ], ++$sequence );
					if ( isset( $case['readable'] ) ) {
						$records = array(); $policy = $index > 0 ? $case['native_provider_readable'] : $case['readable'];
						foreach ( $policy as $key => $allowed ) {
							$records[ wstm108_native_observation_key( 7, 42, $key ) ] = array( 'actor' => 7, 'post_id' => 42, 'key' => $key, 'allowed' => $allowed );
						}
						$original['headers'] = array( 'X-WSTM108-Native-Reads' => base64_encode( json_encode( $records, JSON_THROW_ON_ERROR ) ) );
					}
					if ( in_array( $suffix, array( 'get-seo-scores', 'get-readability-scores' ), true ) ) {
						$records = array();
						foreach ( array( 42, 43 ) as $post_id ) {
							$key = $case['score_key'];
							$records[ wstm108_native_observation_key( 7, $post_id, $key ) ] = array( 'actor' => 7, 'post_id' => $post_id, 'key' => $key, 'allowed' => true );
						}
						$original['headers'] = array( 'X-WSTM108-Native-Reads' => base64_encode( json_encode( $records, JSON_THROW_ON_ERROR ) ) );
					}
					if ( 0 === strpos( $suffix, 'seo-forbidden-read-trap:' ) ) { $original['request_headers'] = array( 'X-WSTM108-Deny-Key' => $case['semantic']['key'], 'X-WSTM108-Deny-Post' => '42' ); }
					if ( null !== $mutate ) { $mutate( $case, $original, $index ); }
					$id = $evidence->append( $original );
					$evidence->validate( $id, 'canonical-tool', array( 'is_error' => false === $response['success'], 'payload_sha256' => hash( 'sha256', wstm108_wire_canonical( $response ) ) ) );
				}
			}
			$evidence->case_verdict( $case ); $cases[] = $case;
		}
		$counts = array( 'posts' => 2, 'comments' => 1, 'files' => 1 ); $labels_method = new ReflectionMethod( Wstm108_Resources::class, 'cleanup_labels' ); $labels_method->setAccessible( true );
		$proof = array( 'version' => 1, 'source_sha' => null, 'tree_sha' => null, 'package_sha256' => null,
			'binding_sha256' => hash( 'sha256', json_encode( $binding ) ), 'resource_counts' => $counts, 'cleanup_complete' => true,
			'cleanup' => array_fill_keys( $labels_method->invoke( null, $counts ), true ), 'failed' => 0, 'first_error' => null, 'inventory_sha256' => hash( 'sha256', 'modeled private ledger' ) );
		$proof['cleanup_sha256'] = hash_hmac( 'sha256', json_encode( $proof ), $binding['owner'] );
		$summary = array( 'binding' => $binding, 'stage_context_sha256' => hash( 'sha256', $bytes ), 'providers' => $model['providers'],
			'registered' => $model['registered'], 'catalogs' => array( 'individual:administrator' => array_values( $model['descriptors'] ) ),
			'cases' => $cases, 'actors' => array( 'administrator' => array( 'id' => 7, 'roles' => array( 'administrator' ), 'caps' => (object) array( 'list_users' => true ) ) ), 'completed' => true, 'cleanup_complete' => true,
			'status' => 'passed', 'passed' => 298, 'failed' => 0,
			'expected_labels' => Wstm108_Plan::labels( $model['native'] ), 'resource_proof' => $proof );
		$evidence->save( $summary ); $evidence->seal( true, $summary );
		unset( $evidence, $wire );
		$summary_path = $root . '/runner.json'; $public = json_decode( file_get_contents( $summary_path ), true );
		$public['http_journal_sha256'] = hash_file( 'sha256', $summary_path . '.http.jsonl' ); file_put_contents( $summary_path, json_encode( $public ) );
		file_put_contents( $root . '/resources.json', json_encode( array( 'binding' => $binding, 'phase' => 'cleaned',
			'posts' => array( 42 => array( 'author' => 7, 'type' => 'post' ), 43 => array( 'author' => 7, 'type' => 'post' ) ) ) ) );
		file_put_contents( $root . '/product-verdict.private.json', json_encode( array( 'schema' => 'wstm-product-verdict-v1', 'context_sha256' => hash( 'sha256', $bytes ),
			'binding' => $binding, 'status' => 'passed', 'cleanup_complete' => true, 'php' => '8.2.31', 'uid' => 33, 'exit' => 0,
			'expected_labels' => $summary['expected_labels'], 'observed_labels' => $summary['expected_labels'], 'passed' => 298, 'failed' => 0 ) ) );
		return array( 'context' => $bytes, 'model' => $model, 'summary' => $summary );
	}

	public static function context(): array {
		return array( 'schema' => 'wstm-product-context-v1', 'run' => str_repeat( 'a', 32 ), 'token' => str_repeat( 'b', 64 ),
			'project' => 'wstm-local-0123456789abcdef-package', 'url' => 'http://localhost', 'artifact_root' => '/qa-artifacts',
			'plugin_root' => '/var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp', 'wordpress_root' => '/var/www/html',
			'identity_kind' => 'package', 'identity_sha256' => str_repeat( 'c', 64 ), 'uid' => 33, 'php_floor' => '8.2' );
	}

	public static function state( bool $small ): array {
		$context = self::context(); $cpt = 'w121_' . substr( $context['run'], 0, 12 );
		$counts = $small ? array( 'post' => 43, 'attachment' => 43, 'page' => 46, $cpt => 43 )
			: array( 'post' => 20000, 'attachment' => 10000, 'page' => 203, $cpt => 205 );
		$catalog = array(); $last = 50000;
		foreach ( $counts as $type => $count ) { $catalog[ $type ] = range( $last, $last - $count + 1 ); $last -= $count; }
		$referenced = array();
		foreach ( $catalog['attachment'] as $rank => $id ) { if ( 0 === $rank % 5 ) { $referenced[] = $id; } }
		foreach ( array( 1, 2, 3, 4 ) as $rank ) { $referenced[] = $catalog['attachment'][ $rank ]; }
		$classes = array( 'Webmastery_MCP_Posts' => 'class-posts.php', 'Webmastery_MCP_Media' => 'class-media.php',
			'Webmastery_MCP_SEO' => 'class-seo.php', 'Webmastery_MCP_Content_Hygiene' => 'class-content-hygiene.php',
			'Webmastery_MCP_Custom_Post_Types' => 'class-custom-post-types.php', 'Webmastery_MCP_List_Query' => 'class-list-query.php' );
		$sources = array( 'sha256' => array(), 'loaded' => array() );
		foreach ( $classes as $class => $file ) {
			$hash = hash_file( 'sha256', dirname( __DIR__, 3 ) . '/includes/' . $file );
			$sources['sha256'][ 'includes/' . $file ] = $hash;
			$sources['loaded'][ $class ] = array( 'path' => $context['plugin_root'] . '/includes/' . $file, 'sha256' => $hash );
		}
		return array( 'namespace' => $cpt, 'cpt' => $cpt, 'token' => $context['token'], 'small_catalog' => $small,
			'user' => 42, 'revision' => 100000, 'catalog' => $catalog, 'referenced' => array_values( array_unique( $referenced ) ), 'sources' => $sources );
	}

	public static function record( array $state, array $job ): array {
		$catalog = $state['catalog'][ $job['type'] ]; $ranks = array_flip( $catalog );
		if ( 'ASC' === ( $job['input']['order'] ?? 'DESC' ) ) { $catalog = array_reverse( $catalog ); }
		$p = $job['per_page']; $candidates = array_slice( $catalog, ( $job['page'] - 1 ) * $p, $p + 1 ); $window = array_slice( $candidates, 0, $p );
		$ids = array_values( array_filter( $window, static function ( $id ) use ( $job, $state, $ranks ): bool {
			$rank = $ranks[ $id ]; $group = $state['small_catalog'] ? 20 : 100;
			$allowed = 'dense' === $job['mode'] || ( 'sparse' === $job['mode'] && 0 === intdiv( $rank, $group ) % 2 && 0 === $rank % 3 );
			return $allowed && ( ! $job['orphan'] || ! in_array( $id, $state['referenced'], true ) );
		} ) );
		$items = array();
		foreach ( $ids as $id ) {
			$item = array( 'id' => $id, 'untrusted_fields' => array() );
			if ( in_array( $job['ability'], array( 'get-seo-scores', 'get-readability-scores' ), true ) ) {
				$item = array( 'post_id' => $id, 'title' => 'Controlled score title.', 'url' => 'http://localhost/owned/' . $id,
					'post_type' => $job['type'], 'modified_gmt' => '2026-10-09 00:00:00', 'score' => 73,
					'untrusted_fields' => array( 'title', 'url', 'score' ) );
			}
			if ( in_array( $job['type'], array( 'post', 'page', $state['cpt'] ), true ) && false === strpos( $job['ability'], 'scores' ) ) {
				$item['excerpt'] = 'Controlled unchanged excerpt.'; $item['untrusted_fields'][] = 'excerpt';
				if ( 'full' === ( $job['input']['fields'] ?? 'summary' ) ) {
					$item['content'] = ! $state['small_catalog'] && 'post' === $job['type'] && $ranks[ $id ] < 100 ? wstm121_large_content() : 'Controlled short body.';
					$item['untrusted_fields'][] = 'content';
				}
			}
			$items[] = $item;
		}
		$next = count( $candidates ) > $p ? $job['page'] + 1 : null;
		$response = array( 'success' => true, 'data' => array( 'items' => $items, 'page' => $job['page'], 'per_page' => $p, 'next_page' => $next ) );
		return array( 'job' => $job, 'response' => $response, 'page' => $job['page'], 'per_page' => $p, 'orphan' => $job['orphan'],
			'controlled_default_summary' => $job['controlled_default_summary'],
			'operation_start' => array( 'time_ns' => 10, 'wpdb_num_queries' => 5, 'current_allocated_bytes' => 1048576, 'historical_peak_bytes' => 2097152 ),
			'operation_end' => array( 'time_ns' => 20, 'wpdb_num_queries' => 5, 'current_allocated_bytes' => 1048576, 'peak_allocated_bytes' => 2097152 ),
			'sql' => array(), 'raw_cap_calls' => array(), 'mutations' => array(),
			'queries' => array( array( 'returned_ids' => $candidates, 'fields' => 'ids', 'no_found_rows' => true, 'posts_per_page' => $p + 1,
				'offset' => ( $job['page'] - 1 ) * $p, 'order' => $job['input']['order'] ?? 'DESC',
				'orderby' => array( 'date' => $job['input']['order'] ?? 'DESC', 'ID' => $job['input']['order'] ?? 'DESC' ) ) ),
			'expected_ids' => $ids, 'window_ids' => $window, 'expected_candidate_ids' => $candidates, 'expected_next_page' => $next,
			'item_ids' => $ids, 'next_page' => $next, 'cap_calls' => 0, 'checked_ids' => array(), 'candidate_ids' => $candidates,
			'candidate_count' => count( $candidates ), 'sql_count' => 0, 'memory_start_current_bytes' => 1048576, 'memory_upper_bound_bytes' => 1048576,
			'payload_bytes' => strlen( json_encode( $response, JSON_THROW_ON_ERROR ) ), 'reference_pattern_counts' => array(), 'thumbnail_sql' => array(),
			'assertions_passed' => false );
	}

	public static function probes( array $state ): array {
		$snapshot = self::snapshot(); $ids = array_slice( $state['catalog']['attachment'], 0, 100 ); $truth = array();
		foreach ( $ids as $id ) { $truth[ $id ] = in_array( $id, $state['referenced'], true ); }
		$sql = array( "SELECT meta_value FROM wp_postmeta WHERE meta_key='_thumbnail_id'", "SELECT MAX(post_content LIKE '%literal%') FROM wp_posts" );
		$failures = array();
		foreach ( array( 'thumbnail', 'content' ) as $index => $phase ) {
			$failures[ $phase ] = array( 'helper' => array( 'code' => 'content_hygiene_query_failed' ),
				'forced_input' => array( 'media_id' => $ids[6], 'confirm' => true, 'force' => true ),
				'forced_response' => array( 'success' => false, 'error' => array( 'code' => 'upstream_failed', 'reason' => 'content_hygiene_query_failed' ) ),
				'injected' => array_fill( 0, 2, array( 'original' => $sql[ $index ], 'replacement' => 'SELECT wstm121_deliberate_missing_column FROM wp_posts LIMIT 1' ) ) );
		}
		$reference = array( 'ids' => $ids, 'legacy' => $truth, 'single' => $truth, 'batch' => $truth, 'before' => $snapshot,
			'after' => $snapshot, 'mutations' => array(), 'sql' => $sql, 'read_failures' => $failures );
		$post_ids = array_slice( $state['catalog']['post'], 0, 2 );
		$response = array( 'success' => true, 'data' => array( 'items' => array_map( static fn( $id ) => array( 'id' => $id ), $post_ids ), 'page' => 1, 'per_page' => 2, 'next_page' => 2 ) );
		$phase = array( 'injected' => 1, 'request' => 'SELECT ID FROM wp_posts LIMIT 3', 'sql' => array( 'SELECT ID FROM wp_posts LIMIT 3' ),
			'query_start' => 10, 'query_end' => 11, 'private_database_error' => 'Unknown wstm121_deliberate_missing_column',
			'generation_before' => 'old', 'generation_after' => 'new',
			'failed_response' => array( 'success' => false, 'error' => array( 'code' => 'upstream_failed', 'message' => 'The list query could not be completed.' ) ),
			'retry' => array( 'query_start' => 11, 'query_end' => 12, 'response' => $response ),
			'warm_stale' => array( 'query_start' => 12, 'query_end' => 12, 'response' => $response, 'last_error' => 'wstm121_stale_error_without_new_sql' ) );
		$phase['injection'] = array( 'original' => $phase['sql'][0], 'replacement' => 'SELECT wstm121_deliberate_missing_column FROM wp_posts LIMIT 1', 'query_before' => 10, 'sql_index' => 0 );
		$phase['failure_witness'] = array( 'sql_index' => 0, 'query_before' => 10, 'query_completed' => 11,
			'query_sha256' => hash( 'sha256', $phase['injection']['replacement'] ), 'result_failed' => true, 'error_present' => true, 'boundary' => 'final' );
		$priming = $phase;
		$priming['sql'][] = 'SELECT wp_posts.* FROM wp_posts WHERE ID IN (' . implode( ',', $post_ids ) . ')';
		$priming['sql'][] = 'SELECT * FROM wp_posts WHERE ID = ' . $post_ids[0] . ' LIMIT 1';
		$priming['sql'][] = 'SELECT * FROM wp_posts WHERE ID = ' . $post_ids[1] . ' LIMIT 1';
		$priming['injection']['original'] = $priming['sql'][1];
		$priming['injection']['query_before'] = 11;
		$priming['injection']['sql_index'] = 1;
		$priming['failure_witness']['sql_index'] = 1;
		$priming['failure_witness']['query_before'] = 11;
		$priming['failure_witness']['query_completed'] = 12;
		$priming['failure_witness']['boundary'] = 'before-next-query';
		$priming['query_end'] = 14;
		$priming['private_database_error'] = '';
		$priming['retry']['query_start'] = 14;
		$priming['retry']['query_end'] = 15;
		$priming['warm_stale']['query_start'] = $priming['warm_stale']['query_end'] = 15;
		$cache = array( 'input' => array( 'page' => 1, 'per_page' => 2, 'orderby' => 'id', 'order' => 'DESC' ), 'expected_ids' => $post_ids,
			'before' => $snapshot, 'after' => $snapshot, 'phases' => array( 'candidate' => $phase, 'priming' => $priming ) );
		$projection = array( 'before' => $snapshot, 'after' => $snapshot, 'mutations' => array(), 'sql' => array(), 'responses' => array(),
			'stored_content' => array( 'revision' => 'Controlled short body.' ) );
		foreach ( array( 'get-post', 'get-page', 'get-cpt-' . str_replace( '_', '-', $state['cpt'] ) ) as $name ) {
			$projection['responses'][ $name ] = array( 'success' => true, 'data' => array( 'content' => 'Controlled short body.',
				'excerpt' => 'Controlled unchanged excerpt.', 'untrusted_fields' => array( 'content', 'excerpt' ) ) );
			$projection['stored_content'][ $name ] = 'Controlled short body.';
		}
		foreach ( array( 'default', 'summary', 'full' ) as $fields ) {
			$item = array( 'id' => $state['revision'], 'excerpt' => 'Controlled unchanged excerpt.', 'untrusted_fields' => array( 'excerpt' ) );
			if ( 'full' === $fields ) { $item['content'] = 'Controlled short body.'; $item['untrusted_fields'][] = 'content'; }
			$projection['responses'][ 'revisions-' . $fields ] = array( 'success' => true,
				'data' => array( 'post_id' => $state['catalog']['post'][0], 'type' => 'post', 'revisions' => array( $item ) ) );
		}
		return array( 'reference-parity.json' => $reference, 'projection-probes.json' => $projection, 'window-cache-faults.json' => $cache );
	}

	public static function export( string $root ): array {
		$context = self::context(); $bytes = json_encode( $context, JSON_THROW_ON_ERROR ); $files = array(); $directories = array(); $catalogs = array();
		$write = static function ( string $relative, array $data ) use ( $root, &$files ): void {
			$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( false === file_put_contents( $path, json_encode( $data, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Test export write failed.' ); }
			$files[] = $path;
		};
		foreach ( array( 'small', 'large' ) as $name ) {
			$small = 'small' === $name; $directory = $root . DIRECTORY_SEPARATOR . $name;
			if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Test export directory collision.' ); } $directories[] = $directory;
			$state = self::state( $small ); $jobs = $small ? Wstm_Product_Evidence_Verifier::small_plan( $state ) : Wstm121Plan::selected( $state );
			$write( $name . '/state.json', $state );
			$write( $name . '/pre-acquisition.json', array(
				'schema' => 'wstm-bounded-pre-acquisition-v1', 'context_sha256' => hash( 'sha256', json_encode( self::context() ) ),
				'binding' => array( 'owner' => self::context()['run'], 'project' => self::context()['project'],
					'identity_kind' => self::context()['identity_kind'], 'identity_sha256' => self::context()['identity_sha256'] ),
				'namespace' => $state['namespace'], 'token' => $state['token'], 'small_catalog' => $small, 'acquisition_started' => false,
			) );
			$write( $name . '/plan.json', array( 'small' => $small, 'jobs' => $jobs,
				'oracle' => 'Token-owned catalog read before invocation; expected IDs computed from window, authorization and independent reference fixtures.' ) );
			$write( $name . '/seed-measurements.json', array( 'catalog_counts' => array_map( 'count', $state['catalog'] ),
				'large_posts' => $small ? 0 : 100, 'large_body_bytes' => 56320, 'large_count_sql' => 'SELECT COUNT(*) WHERE OCTET_LENGTH(post_content)=56320' ) );
			foreach ( array( 'setup-before', 'before', 'after' ) as $file ) { $write( $name . '/' . $file . '.json', self::snapshot() ); }
			$write( $name . '/cleanup-readback.json', array( 'remaining' => array( 'posts' => 0, 'actor' => 0, 'actor_meta' => 0, 'control' => 0 ), 'snapshot' => self::snapshot() ) );
			foreach ( $jobs as $index => $job ) {
				$operation = $directory . DIRECTORY_SEPARATOR . 'operation-' . $index;
				if ( ! mkdir( $operation, 0700 ) ) { throw new RuntimeException( 'Test operation collision.' ); } $directories[] = $operation;
				$write( $name . '/operation-' . $index . '/result.json', self::record( $state, $job ) );
			}
			if ( ! $small ) { foreach ( self::probes( $state ) as $file => $record ) { $write( $name . '/' . $file, $record ); } }
			$labels = array_column( $jobs, 'coverage_key' );
			$catalogs[] = array( 'small' => $small, 'inventory' => array( 'observations' => count( $jobs ), 'expected_labels' => $labels, 'observed_labels' => $labels ), 'cleanup_complete' => true );
		}
		$write( 'product-verdict.private.json', array( 'schema' => 'wstm-product-verdict-v1', 'context_sha256' => hash( 'sha256', $bytes ),
			'binding' => array( 'owner' => $context['run'], 'project' => $context['project'], 'identity_kind' => 'package', 'identity_sha256' => $context['identity_sha256'] ),
			'status' => 'passed', 'cleanup_complete' => true, 'php' => '8.2.31', 'uid' => 33, 'exit' => 0, 'catalogs' => $catalogs ) );
		return array( 'context' => $bytes, 'files' => $files, 'directories' => $directories );
	}
}
