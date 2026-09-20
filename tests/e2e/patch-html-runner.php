<?php
require_once __DIR__ . '/error-contract-assertions.php';

/**
 * Real-core patch preservation regressions, invoked by the contract runner.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

final class WSTM115_Patch_HTML_Tests {
	private $roles;
	private $cases = array();

	public function __construct( $roles ) {
		$this->roles = $roles;
	}

	public static function html_block() {
		return '<!-- wp:html --><iframe src="https://example.org/embed"></iframe><script>window.wstm115 = "untouched";</script><style>.wstm115 { color: red; }</style><form action="/subscribe"><input name="email"></form><svg viewBox="0 0 10 10"><path d="M0 0h10"></path></svg><p onclick="void(0)">Keep "quotes", apostrophe\'s and C:\wstm115\path.</p><!-- /wp:html -->';
	}

	public static function paragraph( $text ) {
		return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
	}

	public static function seed( $content, $author_id, $type = 'post' ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $type,
					'post_status'  => 'draft',
					'post_title'   => 'WSTM115 patch preservation',
					'post_content' => $content,
					'post_author'  => $author_id,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $id->get_error_message() );
		}
		if ( get_post( $id )->post_content !== $content ) {
			throw new RuntimeException( 'WSTM115 seed did not persist byte-identical raw content.' );
		}
		return $id;
	}

	private function check( $condition, $message, &$errors ) {
		if ( ! $condition ) {
			$errors[] = $message;
		}
	}

	private function exercise( $label, $content, $ability, $input, $expected, $actor = 'editor', $type = 'post', $error = '', $owner = null ) {
		wp_set_current_user( $this->roles['editor'] );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			throw new RuntimeException( 'WSTM115 requires an effective unfiltered_html fixture editor on disposable single-site WordPress.' );
		}
		$id = self::seed( $content, $this->roles[ $owner ?? $actor ], $type );
		$is_block = 'patch-content-block' === $ability;
		$is_full_update = 'update-' . $type === $ability;
		$input[ $is_block ? 'content_id' : ( $is_full_update ? $type . '_id' : 'post_id' ) ] = $id;
		if ( ! $is_full_update ) {
			$input['content_type'] = $type;
			$input += array( 'expected_content_hash' => hash( 'sha256', $content ) );
		}

		wp_set_current_user( $this->roles[ $actor ] );
		$unfiltered = current_user_can( 'unfiltered_html' );
		$core_filter = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$errors = array();
		$this->check( $core_filter === ! $unfiltered, 'Core save filter does not match effective capability.', $errors );
		$result = wp_get_ability( 'webmastery-site-toolkit-for-mcp/' . $ability )->execute( $input );
		$after = get_post( $id )->post_content;
		$ok = ! is_wp_error( $result ) && true === ( $result['success'] ?? false );
		$this->check( '' === $error ? $ok : ! $ok, 'Unexpected success/error result.', $errors );
		if ( '' !== $error ) {
			$this->check( wstm118_error_reason( $result ) === $error, 'Wrong error reason: ' . wstm118_error_reason( $result ), $errors );
		}
		$this->check( $after === $expected, 'Persisted raw content differs from expected bytes.', $errors );
		if ( $ok && ! $is_full_update ) {
			$this->check( $result['data']['content_hash_before'] === hash( 'sha256', $content ), 'Incorrect before hash.', $errors );
			$this->check( $result['data']['content_hash_after'] === hash( 'sha256', $after ), 'Incorrect persisted after hash.', $errors );
			$this->check( ( $is_block ? $result['data']['content'] : $result['data']['post']['content'] ) === $after, 'Response content differs from persisted raw content.', $errors );
			if ( $is_block ) {
				$before_block = e2e_block_by_path( parse_blocks( $content ), $result['data']['target']['block_path'] );
				$after_block = e2e_block_by_path( parse_blocks( $after ), $result['data']['target']['block_path'] );
				$this->check( $result['data']['block_hash_before'] === hash( 'sha256', serialize_block( $before_block ) ), 'Incorrect target before hash.', $errors );
				$this->check( $result['data']['block_hash_after'] === hash( 'sha256', serialize_block( $after_block ) ), 'Incorrect target after hash.', $errors );
			}
		}

		$untouched = array();
		$before_blocks = array_map( 'serialize_block', parse_blocks( $content ) );
		$expected_blocks = array_map( 'serialize_block', parse_blocks( $expected ) );
		$after_blocks = array_map( 'serialize_block', parse_blocks( $after ) );
		foreach ( $before_blocks as $index => $block ) {
			if ( $block !== ( $expected_blocks[ $index ] ?? null ) ) {
				continue;
			}
			$actual = $after_blocks[ $index ] ?? '';
			$this->check( $block === $actual, "Untouched block {$index} changed.", $errors );
			$untouched[] = array( 'index' => $index, 'before_hash' => hash( 'sha256', $block ), 'after_hash' => hash( 'sha256', $actual ) );
		}
		$this->cases[] = array(
			'label' => $label,
			'passed' => empty( $errors ),
			'errors' => $errors,
			'ability' => $ability,
			'actor' => $actor,
			'effective_unfiltered_html' => $unfiltered,
			'core_save_filter' => $core_filter,
			'post_id' => $id,
			'input' => $input,
			'result' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) : $result,
			'before' => $content,
			'after' => $after,
			'expected' => $expected,
			'before_hash' => hash( 'sha256', $content ),
			'after_hash' => hash( 'sha256', $after ),
			'untouched_blocks' => $untouched,
		);
		echo ( empty( $errors ) ? 'PASS ' : 'FAIL ' ) . $label . ( $errors ? ': ' . implode( ' ', $errors ) : '' ) . "\n";
	}

	public function run() {
		$unsafe = self::html_block();
		$old = self::paragraph( 'Target "old" C:\wstm115\old.' );
		$replacement = self::paragraph( 'New "quoted" apostrophe\'s C:\wstm115\new.<iframe src="https://example.org/new"></iframe><script>void(0)</script><style>.new{color:red}</style><form><input name="new"></form><svg><path></path></svg><b onclick="void(0)">Safe</b>' );
		$safe = wp_kses_post( $replacement );
		if ( $safe === $replacement || false !== strpos( $safe, '<iframe' ) || false !== strpos( $safe, 'onclick=' ) ) {
			throw new RuntimeException( 'WSTM115 replacement fixture does not exercise KSES.' );
		}
		$heading = '<!-- wp:heading --><h2 class="wp-block-heading">Details</h2><!-- /wp:heading -->';
		$next = '<!-- wp:heading --><h2 class="wp-block-heading">After</h2><!-- /wp:heading -->';
		$attrs = serialize_block( array(
			'blockName' => 'core/paragraph',
			'attrs' => array( 'metadata' => array( 'name' => 'JSON "quotes" C:\wstm115\attrs' ) ),
			'innerBlocks' => array(),
			'innerHTML' => '<p>Untouched JSON attributes.</p>',
			'innerContent' => array( '<p>Untouched JSON attributes.</p>' ),
		) );
		$content = $unsafe . $heading . $old . $next . $attrs;
		if ( serialize_blocks( parse_blocks( $content ) ) !== $content ) {
			throw new RuntimeException( 'WSTM115 standard fixture must be canonical before patching.' );
		}
		$expected = str_replace( $old, $safe, $content );
		$block_input = array( 'target_type' => 'block_path', 'block_path' => '2', 'replacement_content' => $replacement, 'expected_block_hash' => hash( 'sha256', $old ) );
		$heading_input = array( 'target_type' => 'heading', 'heading_text' => 'Details', 'replacement_content' => $replacement );
		$exact_input = array( 'target_type' => 'exact', 'old_content' => $old, 'replacement_content' => $replacement );
		foreach ( array( 'post', 'page' ) as $type ) {
			$this->exercise( "{$type} block path preserves HTML and filters replacement", $content, 'patch-content-block', $block_input, $expected, 'editor', $type );
			$this->exercise( "{$type} block hash preserves HTML and filters replacement", $content, 'patch-content-block', array( 'target_type' => 'block_hash', 'block_hash' => hash( 'sha256', $old ), 'replacement_content' => $replacement ), $expected, 'editor', $type );
			$this->exercise( "{$type} heading preserves HTML and filters replacement", $content, 'patch-post-content', $heading_input, $expected, 'editor', $type );
			$this->exercise( "{$type} exact preserves HTML and filters replacement", $content, 'patch-post-content', $exact_input, $expected, 'editor', $type );
			$this->exercise( "{$type} full-content update still filters all supplied HTML", $content, 'update-' . $type, array( 'content' => $expected ), wp_kses_post( $expected ), 'editor', $type );
		}
		$group = '<!-- wp:group --><div class="wp-block-group">' . $old . $unsafe . '</div><!-- /wp:group -->';
		$this->exercise( 'nested block preserves sibling HTML', $unsafe . $group . $attrs, 'patch-content-block', array_replace( $block_input, array( 'block_path' => '1.0' ) ), $unsafe . str_replace( $old, $safe, $group ) . $attrs );

		foreach ( array(
			'<iframe src="https://example.org/embed"></iframe>',
			'<script>window.wstm115 = "untouched";</script>',
			'<style>.wstm115 { color: red; }</style>',
			'<form action="/subscribe"><input name="email"></form>',
			'<svg viewBox="0 0 10 10"><path d="M0 0h10"></path></svg>',
			'<p onclick="void(0)">Keep "quotes", apostrophe\'s and C:\wstm115\path.</p>',
		) as $needle ) {
			$this->exercise( 'raw exact needle ' . strtok( $needle, '>' ), $content, 'patch-post-content', array_replace( $exact_input, array( 'old_content' => $needle ) ), str_replace( $needle, $safe, $content ) );
		}
		$this->exercise( 'formerly empty sanitized needle is not a match-all', $content, 'patch-post-content', array_replace( $exact_input, array( 'old_content' => '<iframe src="https://example.org/missing"></iframe>' ) ), $content, 'editor', 'post', 'target_not_found' );
		$this->exercise( 'raw exact needle is not confused with sanitized lookalike', $unsafe . '<p>Keep "quotes", apostrophe\'s and C:\wstm115\path.</p>', 'patch-post-content', array_replace( $exact_input, array( 'old_content' => '<p onclick="void(0)">Keep "quotes", apostrophe\'s and C:\wstm115\path.</p>' ) ), str_replace( '<p onclick="void(0)">Keep "quotes", apostrophe\'s and C:\wstm115\path.</p>', $safe, $unsafe ) . '<p>Keep "quotes", apostrophe\'s and C:\wstm115\path.</p>' );

		foreach ( array( 'patch-content-block' => $block_input, 'patch-post-content' => $exact_input ) as $ability => $input ) {
			$this->exercise( "{$ability} author retains core filtering", $content, $ability, $input, wp_kses_post( $expected ), 'author' );
			$this->exercise( "{$ability} editor with denied unfiltered_html retains core filtering", $content, $ability, $input, wp_kses_post( $expected ), 'filtered_editor' );
			$this->exercise( "{$ability} stale content hash does not mutate", $content, $ability, $input + array( 'expected_content_hash' => str_repeat( '0', 64 ) ), $content, 'editor', 'post', 'content_hash_mismatch' );
			$this->exercise( "{$ability} subscriber cannot write", $content, $ability, $input, $content, 'subscriber', 'post', 'ability_invalid_permissions', 'editor' );
			$this->exercise( "{$ability} author cannot write another editor's object", $content, $ability, $input, $content, 'author', 'post', 'ability_invalid_permissions', 'editor' );
		}
		$this->exercise( 'CPT exact retains custom capability map and core filtering', $content, 'patch-post-content', $exact_input, wp_kses_post( $expected ), 'book_manager', 'mcp_book' );
		$this->exercise( 'CPT heading retains custom capability map and core filtering', $content, 'patch-post-content', $heading_input, wp_kses_post( $expected ), 'book_manager', 'mcp_book' );
		$this->exercise( 'CPT denied object unchanged', $content, 'patch-post-content', $exact_input, $content, 'subscriber', 'mcp_book', 'ability_invalid_permissions', 'book_manager' );

		foreach ( array(
			array( 'missing block path', 'patch-content-block', array_replace( $block_input, array( 'block_path' => '9' ) ), 'target_not_found' ),
			array( 'missing block hash', 'patch-content-block', array( 'target_type' => 'block_hash', 'block_hash' => str_repeat( '0', 64 ), 'replacement_content' => $replacement ), 'target_not_found' ),
			array( 'stale block hash', 'patch-content-block', array_replace( $block_input, array( 'expected_block_hash' => str_repeat( '0', 64 ) ) ), 'block_hash_mismatch' ),
			array( 'missing heading', 'patch-post-content', array_replace( $heading_input, array( 'heading_text' => 'Missing' ) ), 'target_not_found' ),
			array( 'missing exact', 'patch-post-content', array_replace( $exact_input, array( 'old_content' => 'Missing' ) ), 'target_not_found' ),
			array( 'empty exact', 'patch-post-content', array_replace( $exact_input, array( 'old_content' => '' ) ), 'missing_target' ),
			array( 'missing exact argument', 'patch-post-content', array( 'target_type' => 'exact', 'replacement_content' => $replacement ), 'missing_target' ),
			array( 'multiple replacement blocks', 'patch-content-block', array_replace( $block_input, array( 'replacement_content' => $safe . $safe ) ), 'invalid_replacement' ),
		) as $case ) {
			$this->exercise( $case[0], $content, $case[1], $case[2], $content, 'editor', 'post', $case[3] );
		}
		$duplicate = $content . $heading . $old . $unsafe;
		foreach ( array(
			'patch-content-block' => array( 'target_type' => 'block_hash', 'block_hash' => hash( 'sha256', $unsafe ), 'replacement_content' => $replacement ),
			'patch-post-content' => array_replace( $exact_input, array( 'old_content' => $unsafe ) ),
		) as $ability => $input ) {
			$this->exercise( "{$ability} duplicate raw HTML is ambiguous", $duplicate, $ability, $input, $duplicate, 'editor', 'post', 'ambiguous_target' );
		}
		$this->exercise( 'duplicate heading is ambiguous', $duplicate, 'patch-post-content', $heading_input, $duplicate, 'editor', 'post', 'ambiguous_target' );

		// Record existing core delimiter normalization, not a new raw-splicing contract.
		$noncanonical = str_replace( '<!-- wp:paragraph -->', '<!-- wp:paragraph   -->', $content );
		$this->exercise( 'noncanonical delimiters keep existing parse/serialize behavior', $noncanonical, 'patch-content-block', $block_input, $expected );
		$this->exercise( 'exact patch does not parse noncanonical delimiters', $noncanonical, 'patch-post-content', array_replace( $exact_input, array( 'old_content' => 'Target "old" C:\wstm115\old.' ) ), str_replace( 'Target "old" C:\wstm115\old.', $safe, $noncanonical ) );

		wp_set_current_user( $this->roles['editor'] );
		$failed = count( array_filter( $this->cases, static function ( $case ) { return ! $case['passed']; } ) );
		$summary = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'source_sha256' => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/class-posts.php' ),
			'test_cases' => count( $this->cases ),
			'passed' => count( $this->cases ) - $failed,
			'failed' => $failed,
			'cases' => $this->cases,
		);
		$dir = dirname( __DIR__, 2 ) . '/e2e-artifacts';
		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $dir . '/patch-html-summary.json', wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
			throw new RuntimeException( 'Unable to persist WSTM115 raw-content regression evidence.' );
		}
		echo "PATCH HTML SUMMARY {$summary['passed']} passed, {$failed} failed\n";
		return $summary;
	}
}
