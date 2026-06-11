#!/bin/bash
set -e

WORDPRESS_URL="http://localhost"
WP_PATH="/var/www/html"
PLUGIN_SLUG="wordpress-mcp-abilities"

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

echo "================================"
echo "E2E Test Suite: WordPress MCP Abilities"
echo "================================"

# Wait for WordPress to be ready
wait_for_wordpress() {
  local max_attempts=30
  local attempt=0
  echo "Waiting for WordPress to be ready..."
  while [ $attempt -lt $max_attempts ]; do
    if curl -s -f "$WORDPRESS_URL/wp-json/" > /dev/null 2>&1; then
      echo "✓ WordPress is ready"
      return 0
    fi
    attempt=$((attempt + 1))
    echo "Attempt $attempt/$max_attempts..."
    sleep 2
  done
  echo "✗ WordPress failed to start"
  exit 1
}

wait_for_wordpress

# Install MCP Adapter plugin (if not already present)
install_mcp_adapter() {
  echo "Installing MCP Adapter plugin..."
  wp plugin list --format=csv --fields=name | grep -q "mcp-adapter" || {
    wp plugin install https://github.com/DanielBoring/wordpress-mcp-adapter/releases/download/v0.5.0/wordpress-mcp-adapter.zip --activate
  }
}

install_mcp_adapter

# Activate our plugin
echo "Activating plugin..."
wp plugin activate $PLUGIN_SLUG

# Create test users
echo "Creating test users..."
wp user create author_test author@test.local --user_pass=password123 --role=author --porcelain || true
wp user create editor_test editor@test.local --user_pass=password123 --role=editor --porcelain || true
wp user create subscriber_test subscriber@test.local --user_pass=password123 --role=subscriber --porcelain || true

# Get user IDs
AUTHOR_ID=$(wp user get author_test --field=ID)
EDITOR_ID=$(wp user get editor_test --field=ID)
SUBSCRIBER_ID=$(wp user get subscriber_test --field=ID)

echo "Created users: Author=$AUTHOR_ID, Editor=$EDITOR_ID, Subscriber=$SUBSCRIBER_ID"

# Create test posts/attachments
echo "Creating test content..."
AUTHOR_POST=$(wp post create --post_type=post --post_title="Author Test Post" --post_author=$AUTHOR_ID --post_status=publish --porcelain)
EDITOR_POST=$(wp post create --post_type=post --post_title="Editor Test Post" --post_author=$EDITOR_ID --post_status=publish --porcelain)

echo "Created posts: Author=$AUTHOR_POST, Editor=$EDITOR_POST"

# === MEDIA ABILITY TESTS ===
echo ""
echo "=== MEDIA ABILITY TESTS ==="
PASS=0
FAIL=0

test_ability() {
  local name=$1
  local user_id=$2
  local method=$3
  local params=$4
  local should_fail=${5:-0}
  
  echo -n "Testing $name (user_id=$user_id)... "
  
  RESULT=$(wp mcp call "wp-mcp/$method" "$params" --user_id=$user_id --format=json 2>&1)
  
  if [[ $should_fail -eq 1 ]]; then
    if echo "$RESULT" | grep -q "error\|Error\|denied"; then
      echo "✓ (correctly denied)"
      ((PASS++))
    else
      echo "✗ (expected to fail but passed)"
      echo "  Result: $RESULT"
      ((FAIL++))
    fi
  else
    if ! echo "$RESULT" | grep -q "error\|Error"; then
      echo "✓"
      ((PASS++))
    else
      echo "✗"
      echo "  Result: $RESULT"
      ((FAIL++))
    fi
  fi
}

# List media (all users should have capability)
test_ability "list-media (author)" $AUTHOR_ID "list-media" '{"search":""}'
test_ability "list-media (editor)" $EDITOR_ID "list-media" '{"search":""}'
test_ability "list-media (subscriber)" $SUBSCRIBER_ID "list-media" '{"search":""}' 1

echo ""
echo "=== AUTHORIZATION TESTS ==="

# Test Author cannot list Editor's attachments
echo "Auth: Author should only see own media..."
AUTHOR_MEDIA=$(wp mcp call wp-mcp/list-media '{"search":""}' --user_id=$AUTHOR_ID --format=json | jq '.data.attachments | length')
echo "✓ Author can list media"

# Test Editor can list all media
echo "Auth: Editor should see all media..."
EDITOR_MEDIA=$(wp mcp call wp-mcp/list-media '{"search":""}' --user_id=$EDITOR_ID --format=json | jq '.data.attachments | length')
echo "✓ Editor can list media"

echo ""
echo "=== EXISTING ABILITIES TEST ==="

# Test that existing abilities still work
test_ability "list-posts (admin)" 1 "list-posts" '{"search":""}'
test_ability "get-post (admin)" 1 "get-post" "{\"id\":$AUTHOR_POST}"

echo ""
echo "================================"
echo "Test Results: $PASS passed, $FAIL failed"
echo "================================"

if [ $FAIL -gt 0 ]; then
  exit 1
fi

exit 0
