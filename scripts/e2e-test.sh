#!/usr/bin/env bash
set -Eeuo pipefail

WORDPRESS_URL="${WORDPRESS_URL:-http://localhost}"
PLUGIN_SLUG="wordpress-mcp-abilities"
MCP_ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip"

compose() {
	if docker compose version >/dev/null 2>&1; then
		docker compose "$@"
	else
		docker-compose "$@"
	fi
}

wp() {
	compose exec -T wordpress wp --allow-root "$@"
}

wait_for_wordpress_files() {
	local max_attempts=60
	local attempt=1

	echo "Waiting for WordPress files..."
	while [ "$attempt" -le "$max_attempts" ]; do
		if compose exec -T wordpress test -f /var/www/html/wp-load.php; then
			echo "WordPress files are ready"
			return 0
		fi

		echo "Attempt ${attempt}/${max_attempts}..."
		attempt=$(( attempt + 1 ))
		sleep 2
	done

	echo "WordPress files were not ready in time"
	return 1
}

install_wp_cli() {
	echo "Ensuring WP-CLI is installed..."
	compose exec -T wordpress bash -lc 'if ! command -v wp >/dev/null 2>&1; then curl -fsSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp; fi'
	wp --info
}

install_wordpress() {
	echo "Ensuring WordPress is installed..."
	if wp core is-installed; then
		echo "WordPress is already installed"
		return 0
	fi

	wp core install \
		--url="$WORDPRESS_URL" \
		--title="MCP E2E" \
		--admin_user=admin \
		--admin_password=password123 \
		--admin_email=admin@test.local \
		--skip-email
}

install_plugins() {
	echo "Installing MCP Adapter..."
	wp plugin install "$MCP_ADAPTER_ZIP" --activate --force

	echo "Activating ${PLUGIN_SLUG}..."
	wp plugin activate "$PLUGIN_SLUG"
	wp plugin list --fields=name,status,version --format=table
}

write_php_test() {
	compose exec -T wordpress bash -lc "cat > /tmp/e2e-test.php" <<'PHP'
<?php

function ensure_user( $login, $email, $role ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return (int) $user->ID;
	}

	$id = wp_create_user( $login, 'password123', $email );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}

	( new WP_User( $id ) )->set_role( $role );
	return (int) $id;
}

function ensure_term_id( $name, $taxonomy ) {
	$existing = term_exists( $name, $taxonomy );
	if ( $existing ) {
		return (int) $existing['term_id'];
	}

	$created = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $created ) ) {
		throw new RuntimeException( $created->get_error_message() );
	}

	return (int) $created['term_id'];
}

function result_is_success( $result ) {
	return is_array( $result ) && true === ( $result['success'] ?? false );
}

function run_ability( $label, $user_id, $ability_name, $input = null, $expect_success = true ) {
	wp_set_current_user( $user_id );

	$ability = wp_get_ability( $ability_name );
	if ( ! $ability ) {
		echo "FAIL {$label}: {$ability_name} is not registered\n";
		return false;
	}

	$result = $ability->execute( $input );
	$ok     = ! is_wp_error( $result ) && result_is_success( $result );

	if ( $expect_success && $ok ) {
		echo "PASS {$label}\n";
		return true;
	}

	if ( ! $expect_success && ! $ok ) {
		echo "PASS {$label} denied as expected\n";
		return true;
	}

	$message = is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result );
	echo "FAIL {$label}: {$message}\n";
	return false;
}

$admin         = get_user_by( 'login', 'admin' );
$admin_id      = (int) $admin->ID;
$author_id     = ensure_user( 'author_test', 'author@test.local', 'author' );
$editor_id     = ensure_user( 'editor_test', 'editor@test.local', 'editor' );
$subscriber_id = ensure_user( 'subscriber_test', 'subscriber@test.local', 'subscriber' );

$category_id = ensure_term_id( 'MCP E2E Category', 'category' );
$tag_id      = ensure_term_id( 'mcp-e2e-tag', 'post_tag' );

$post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_title'   => 'MCP E2E Post',
		'post_content' => 'Content for MCP E2E post.',
		'post_status'  => 'publish',
		'post_author'  => $author_id,
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}
wp_set_post_categories( $post_id, array( $category_id ) );
wp_set_post_tags( $post_id, array( $tag_id ) );

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'MCP E2E Page',
		'post_content' => 'Content for MCP E2E page.',
		'post_status'  => 'publish',
		'post_author'  => $editor_id,
	),
	true
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( $page_id->get_error_message() );
}

$comment_id = wp_insert_comment(
	array(
		'comment_post_ID'      => $post_id,
		'comment_author'       => 'MCP Tester',
		'comment_author_email' => 'comment@test.local',
		'comment_content'      => 'MCP E2E comment.',
		'comment_approved'     => '0',
	)
);

$upload = wp_upload_bits( 'mcp-e2e.txt', null, 'MCP E2E media file.' );
if ( ! empty( $upload['error'] ) ) {
	throw new RuntimeException( $upload['error'] );
}

$media_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/plain',
		'post_title'     => 'MCP E2E Media',
		'post_status'    => 'inherit',
		'post_author'    => $author_id,
	),
	$upload['file'],
	$post_id,
	true
);
if ( is_wp_error( $media_id ) ) {
	throw new RuntimeException( $media_id->get_error_message() );
}

$required_abilities = array(
	'wp-mcp/list-posts',
	'wp-mcp/get-post',
	'wp-mcp/list-pages',
	'wp-mcp/get-page',
	'wp-mcp/list-categories',
	'wp-mcp/list-tags',
	'wp-mcp/list-comments',
	'wp-mcp/approve-comment',
	'wp-mcp/list-media',
	'wp-mcp/get-media',
	'wp-mcp/update-media',
	'wp-mcp/seo-analyze-post',
	'wp-mcp/seo-site-overview',
	'wp-mcp/site-health-check',
	'wp-mcp/security-audit',
);

$abilities = wp_get_abilities();
echo 'INFO registered abilities: ' . count( $abilities ) . "\n";
foreach ( $required_abilities as $ability_name ) {
	if ( isset( $abilities[ $ability_name ] ) ) {
		echo "PASS registered {$ability_name}\n";
		continue;
	}

	echo "FAIL registered {$ability_name}\n";
	exit( 1 );
}

$tests = array(
	run_ability( 'list-posts', $editor_id, 'wp-mcp/list-posts', array( 'search' => 'MCP E2E', 'per_page' => 5 ) ),
	run_ability( 'get-post', $editor_id, 'wp-mcp/get-post', array( 'post_id' => $post_id ) ),
	run_ability( 'list-pages', $editor_id, 'wp-mcp/list-pages', array( 'search' => 'MCP E2E', 'per_page' => 5 ) ),
	run_ability( 'get-page', $editor_id, 'wp-mcp/get-page', array( 'page_id' => $page_id ) ),
	run_ability( 'list-categories', $subscriber_id, 'wp-mcp/list-categories', array( 'search' => 'MCP E2E', 'per_page' => 10 ) ),
	run_ability( 'list-tags', $subscriber_id, 'wp-mcp/list-tags', array( 'search' => 'mcp-e2e', 'per_page' => 10 ) ),
	run_ability( 'list-comments', $editor_id, 'wp-mcp/list-comments', array( 'post_id' => $post_id, 'status' => 'all' ) ),
	run_ability( 'approve-comment', $editor_id, 'wp-mcp/approve-comment', array( 'comment_id' => $comment_id ) ),
	run_ability( 'list-media as author', $author_id, 'wp-mcp/list-media', array( 'search' => 'MCP E2E', 'per_page' => 10 ) ),
	run_ability( 'list-media as subscriber', $subscriber_id, 'wp-mcp/list-media', array( 'search' => 'MCP E2E', 'per_page' => 10 ), false ),
	run_ability( 'get-media', $author_id, 'wp-mcp/get-media', array( 'media_id' => $media_id ) ),
	run_ability( 'update-media', $author_id, 'wp-mcp/update-media', array( 'media_id' => $media_id, 'title' => 'MCP E2E Media Updated', 'alt_text' => 'Test alt text' ) ),
	run_ability( 'seo-analyze-post', $editor_id, 'wp-mcp/seo-analyze-post', array( 'post_id' => $post_id ) ),
	run_ability( 'seo-site-overview', $subscriber_id, 'wp-mcp/seo-site-overview', null ),
	run_ability( 'site-health-check', $subscriber_id, 'wp-mcp/site-health-check', null ),
	run_ability( 'security-audit', $subscriber_id, 'wp-mcp/security-audit', null ),
);

$pass = count( array_filter( $tests ) );
$fail = count( $tests ) - $pass;
echo "SUMMARY {$pass} passed, {$fail} failed\n";

exit( $fail > 0 ? 1 : 0 );
PHP
}

run_php_lint() {
	echo "Running PHP syntax checks..."
	compose exec -T wordpress bash -lc "php -l /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/wp-mcp-abilities.php && find /var/www/html/wp-content/plugins/${PLUGIN_SLUG}/includes -name '*.php' -print0 | xargs -0 -n1 php -l"
}

run_debug_log_check() {
	echo "Checking WordPress debug log..."
	if ! compose exec -T wordpress test -f /var/www/html/wp-content/debug.log; then
		echo "No debug log found"
		return 0
	fi

	compose exec -T wordpress bash -lc "cat /var/www/html/wp-content/debug.log"
	if compose exec -T wordpress bash -lc "grep -Eiq 'fatal error|parse error|uncaught|error' /var/www/html/wp-content/debug.log"; then
		echo "Debug log contains fatal/error-level entries"
		return 1
	fi
}

echo "================================"
echo "E2E Test Suite: WordPress MCP Abilities"
echo "================================"

wait_for_wordpress_files
install_wp_cli
install_wordpress
install_plugins
compose exec -T wordpress rm -f /var/www/html/wp-content/debug.log
run_php_lint
write_php_test
wp eval-file /tmp/e2e-test.php
run_debug_log_check

echo "E2E QA completed successfully"
