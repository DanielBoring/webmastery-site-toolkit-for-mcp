# Opt-in genuine PHP 8.0 WordPress floor fixture

This fixture has no default image selection, execution grant, automatic
startup, key generation, dependency discovery or cleanup. It does not replace
the pinned-current or PHP 8.1 compatibility lanes. No WordPress/PHP 8.0 image
tag is presumed to exist.

The image is built from a reviewed digest of official `php:8.0.30-cli`.
It compiles `mysqli` without rebuilding the PHP ELF, verifies that ELF against
the independently supplied SHA256 before and after compilation, and downloads
an exact WordPress 6.9.x archive through the existing HTTPS/hash downloader.
The archive's reviewed SHA256 and its actual core version must agree.
There is no unverified archive fallback.

HTTP uses the **same `/usr/local/bin/php` ELF** as CLI, through PHP's native
CLI server. Four native workers permit loopback HTTP requests without treating
Apache directives as executable configuration. The router keeps WordPress
REST/pretty-permalink routing and Authorization request data, serves existing
public files normally, and refuses configuration/dotfile/traversal paths.
This is a disposable integration fixture, not a production web-server proposal.
HTTP is published only on the explicitly owned loopback port.

## Owner inputs and commands

The `fixture.php` config is a closed object with all these fields:

| Field | Required value |
| --- | --- |
| `profile`, `dependency_policy` | Exactly `php80-floor`, `pinned` |
| `php_base_image` | `php:8.0.30-cli@sha256:<reviewed digest>` (optional official `docker.io/library/` prefix) |
| `php_binary_sha256` | SHA256 of that genuine image's actual `/usr/local/bin/php` |
| `wordpress_version`, `wordpress_archive_sha256` | Exact `6.9`/`6.9.x` and reviewed original `https://wordpress.org/wordpress-<version>.tar.gz` digest |
| `mysql_image` | `mysql:8.0.36@sha256:<reviewed digest>` |
| `runtime_image` | `null` before a build; actual resulting immutable `sha256:<image ID>` before Compose selection |
| `candidate_sha`, `candidate_tree`, `candidate_root` | Exact frozen commit/tree and dedicated canonical native Linux checkout containing this reviewed fixture; never the shared editing checkout |
| `project`, `http_port`, `artifact_directory` | Unique owned `wstm-php80-...` project, integer port 1024..65535 and fresh simple artifact basename |
| `wp_config`, `wp_config_sha256` | Owner's private regular single-link original WordPress config and digest |
| `mysql_env_file`, `mysql_env_sha256` | Owner's private regular single-link original Compose MySQL env file and digest |

Both private files must be outside the candidate Docker build context,
canonical, owned by the invoking native UID,
not accessible to group/others, and <=1 MiB. The owner prepares the config,
credentials and salts under a separate approval. Nothing here generates them.
Use conventional literal WordPress definitions compatible with the existing
QA lifecycle, `DB_HOST` pointing to service `mysql`, credentials matching the
MySQL env file, local HTTP/Application Password policy and disabled request
cron. The env file uses standard Compose encoding for `MYSQL_DATABASE`,
`MYSQL_USER`, `MYSQL_PASSWORD`, and `MYSQL_ROOT_PASSWORD`; do not put passwords
in the public plan or build arguments. The boot copies the exact owner config
into a fresh container and refuses an existing `wp-config.php`, rather than
adopting a previous run or repairing a partial boot. No automatic restart is
configured.

After owner review, through the approved bounded supervisor:

```sh
"$PROOF_HOST_PHP" tests/fixtures/php80-floor/fixture.php plan "$OWNER_CONFIG_JSON"
"$PROOF_HOST_PHP" tests/fixtures/php80-floor/fixture.php verify-inputs "$OWNER_CONFIG_JSON"
```

`plan` returns argv arrays and a nonsecret/private-path environment projection;
it executes nothing and supplies no admission receipt. `verify-inputs` performs
local original-file checks only. Neither substitutes for independently granted
ownership, complete capture or physical outside-bind/daemon admission.
The supervisor must recheck these originals before using Compose. Treat plan
output as private operational data, not a public artifact.

The returned build command uses the candidate root as build context. Build
output/status must be captured; independently inspect the resulting image
and supply its immutable ID, then regenerate the plan. Do not start from the
mutable convenience build tag. Compose requires every selected variable;
there are no default current/compatibility overrides. Named MySQL storage and
the candidate bind are confined to the explicit project. No existing project
is brought down, no shared source is changed and no storage is auto-deleted
by the planner.

Before any WordPress install or fixture, the owner must approve provisioning,
real native daemon/mount admission, private storage/durability and fixture
execution separately. Proof-host PHP remains trusted Linux **>=8.1** with
POSIX/builtin fsync; it is not this PHP 8.0 runtime. Desktop/WSL connectivity
and a socket-mounted companion alone do not satisfy the existing topology
validator. No synthetic admission/age/GitHub context/lease is accepted here.

## Actual CLI and HTTP observations

Through complete, bounded original capture **after grants and admission**,
observe the mounted candidate helper with the container's PHP:

```sh
# The supervisor uses the returned exact Compose argv/environment.
docker compose ... exec -T wordpress php \
  /var/www/html/wp-content/plugins/webmastery-site-toolkit-for-mcp/tests/fixtures/php80-floor/fixture.php probe
curl --fail --silent --show-error --connect-timeout 5 --max-time 15 \
  "http://127.0.0.1:${OWNED_PORT}/_wstm_php80_floor"
```

The ellipsis means the returned explicit project/file argv, not ambient Compose
discovery. Retain actual native exits, HTTP 200 status, both EOFs and original
stream bytes in exclusive private files; do not parse failed, truncated or
substituted captures. The endpoint returns actual executing PHP version/SAPI
and ELF SHA256, not a config string or a copied CLI observation.
CLI must report `cli`, HTTP `cli-server`, both actual `8.0.30`, with the same
independently pinned ELF. A different PHP 8.0 patch is also refused by this
fixture, though the general minimum verifier accepts 8.0.x.

Retain the normal real WordPress CLI/server/plugin/mounted-source/full-E2E
observations and original candidate pins. Add `source_tree` from the original
Git tree observation to that runtime object; do not synthesize it. Then:

```sh
"$PROOF_HOST_PHP" tests/fixtures/php80-floor/fixture.php verify \
  "$OWNER_CONFIG_JSON" "$RUNTIME_JSON" "$CANDIDATE_PINS_JSON" \
  "$ORIGINAL_CLI_JSON" "$ORIGINAL_HTTP_JSON"
```

This also invokes the unchanged strict `php80-floor` verifier: clean/mounted/
loaded candidate, exact WordPress 6.9.x and MySQL server 8.0.36, pinned active
Adapter/SEO/WP-CLI and successful **full** E2E remain mandatory. Version probes
alone cannot pass it. Numeric/custody/cleanup/package/PR CI are separate gates;
the bounded numeric runner still requires CLI PHP >=8.1.

## Explicit source-only runtime selector

Select this fixture with `WSTM_QA_RUNTIME_PROFILE=php80-floor`,
`WSTM_PHP80_CONFIG` (canonical private original config path), and
`WSTM_PHP80_CONFIG_SHA256` (its independently reviewed raw-byte hash).
The same closed 18-field config/planner selects shared Compose argv and
planned/live discovery. The image must already be an immutable built image ID;
project, artifact basename, Git commit/tree and candidate root must match.
The trusted proof interpreter remains native Linux PHP >=8.1.

No `COMPOSE_FILE`, Compose profiles/env-file override, package input or
default/current/PHP81 image/port override is accepted alongside this selector.
Callers cannot override its project or Compose files. Shared calls re-read the
original nonsecret selector config and compare actual Git identity. Selecting
argv does not open WordPress/MySQL credential originals: their exact bytes are
checked by existing fixture verification and admitted retention/release stages.
The native discovery adapter retains existing topology admission, adds exact
planned/live image/service/bind checks, and refuses one-offs or replica ambiguity.
Retention adds the original config path/hash, runtime image and source tree to
the existing owner/project/source marker; release/recovery must reproduce these
exact bytes. Removing the selector cannot downgrade an interrupted floor run
to default cleanup. Prior outputs and ownership/cleanup rules are unchanged.

The selector confers no admission or fixture authority. Controller/bootstrap
composition and the pre-fixture capture/admission call sites must use this API;
their exact overlap fragments are provided separately for the owning agent.
Do not run a floor harness with only the shared-helper patch applied.
The default/current/PHP81 and original-ZIP paths remain unchanged when the
selector is absent. The new exact reversal precedes the unchanged style/#167/
#127/#119 histories, rather than regenerating old seals or historical oracles.
All offline selector tests use synthetic query observations, not real
WordPress, daemon admission, runtime custody or leases.
