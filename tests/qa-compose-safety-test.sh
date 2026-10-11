#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
WORK="$(mktemp -d)"
# shellcheck disable=SC2329 # Called by this fixture's EXIT trap.
cleanup_fixture() {
	local status=$?
	rm -f "$WORK/repo/scripts/qa-compose.sh" "$WORK/bin/docker" "$WORK/trace" "$WORK/refusal" \
		"$WORK/foreign-private/sentinel" "${owner_file:-$WORK/no-owner}" "${database_file:-$WORK/no-database}"
	if [[ -n "${private_root:-}" ]]; then
		if [[ -L "$private_root" || -f "$private_root" ]]; then
			rm -f -- "$private_root"
		else
			rm -f -- "$private_root/exported-summary.json"
			rmdir "$private_root" 2>/dev/null || true
		fi
	fi
	if [[ -n "${state_root:-}" ]]; then rmdir "$state_root" 2>/dev/null || true; fi
	rmdir "$WORK/foreign-private" "$WORK/state" "$WORK/repo/scripts" "$WORK/repo" "$WORK/bin" "$WORK" 2>/dev/null || true
	exit "$status"
}
trap cleanup_fixture EXIT
mkdir -p "$WORK/repo/scripts" "$WORK/bin"
cp "$ROOT/scripts/qa-compose.sh" "$WORK/repo/scripts/qa-compose.sh"
cat > "$WORK/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -Eeuo pipefail
case "$1 $2" in
	"info --format") printf '29.6.2\n' ;;
	"container ls"|"network ls"|"volume ls")
		if [[ "$1" == container && -n "${QA_TEST_RESOURCE:-}" ]]; then
			printf '%s\n' "$QA_TEST_RESOURCE"
		fi ;;
	"container inspect"|"network inspect"|"volume inspect")
		printf '%s\n' "${QA_TEST_OBSERVED_OWNER:-}" ;;
	"compose --project-name")
		printf '%s\n' "$*" >> "$QA_TEST_TRACE"
		# Model export bytes only, not the container's PHP identity checks.
		if [[ "$*" == *" wordpress php -r "* ]]; then
			[[ "${!#}" == /qa-artifacts/fixture-summary.json ]] || {
				echo "Unexpected mocked artifact source." >&2; exit 90;
			}
			printf '{"fixture":true}\n'
		fi
		;;
	*) echo "Unexpected mocked Docker command." >&2; exit 90 ;;
esac
DOCKER
chmod +x "$WORK/bin/docker"
export PATH="$WORK/bin:$PATH" QA_TEST_TRACE="$WORK/trace"
export WSTM_QA_STATE_ROOT="$WORK/state"
export COMPOSE_PROJECT_NAME=wstm-compose-control
export E2E_ARTIFACTS_DIR=e2e-artifacts
unset WSTM_QA_OWNER
cd "$WORK/repo"
source scripts/qa-compose.sh
qa_prepare_project
state_root="$(find "$WORK/state" -mindepth 1 -maxdepth 1 -type d -print)"
owner_file="$state_root/wstm-compose-control.owner"
database_file="$state_root/wstm-compose-control.database"
private_root="$state_root/wstm-compose-control.private"
[[ "$WSTM_QA_PRIVATE_OUTPUT" == "$private_root" && -d "$private_root" && ! -L "$private_root" ]]
[[ "$private_root" != "$WORK/repo" && "$private_root" != "$WORK/repo/"* ]]
if [[ "$(uname -s)" == Linux ]]; then
	[[ "$(stat -c '%a' "$private_root")" == 700 ]]
fi
[[ "$WSTM_QA_OWNER" =~ ^[a-f0-9]{64}$ ]]
original="$WSTM_QA_OWNER"
original_password="$WSTM_QA_DB_PASSWORD"
original_root_password="$WSTM_QA_DB_ROOT_PASSWORD"
[[ "$original_password" != "$original_root_password" && "$original_password" != "$original" ]]
qa_prepare_project
[[ "$WSTM_QA_OWNER" == "$original" ]]
[[ "$WSTM_QA_DB_PASSWORD" == "$original_password" && "$WSTM_QA_DB_ROOT_PASSWORD" == "$original_root_password" ]]
[[ "$WSTM_QA_PRIVATE_OUTPUT" == "$private_root" ]]
export WSTM_QA_PRIVATE_OUTPUT="$WORK/repo/public-output"
qa_prepare_project
[[ "$WSTM_QA_PRIVATE_OUTPUT" == "$private_root" && ! -e "$WORK/repo/public-output" ]]

refuse() {
	local previous=0 current=0
	[[ ! -f "$QA_TEST_TRACE" ]] || previous="$(wc -l < "$QA_TEST_TRACE")"
	if "$@" > "$WORK/refusal" 2>&1; then
		echo "Expected ownership/namespace/privilege refusal." >&2; exit 1
	fi
	[[ -s "$WORK/refusal" ]] || { echo "Refusal was silent." >&2; exit 1; }
	[[ ! -f "$QA_TEST_TRACE" ]] || current="$(wc -l < "$QA_TEST_TRACE")"
	[[ "$previous" == "$current" ]] || { echo "Refused command reached Compose." >&2; exit 1; }
}

rmdir "$private_root"
printf 'not a directory\n' > "$private_root"
refuse qa_prepare_project
refuse compose up
rm -f -- "$private_root"
qa_prepare_project
rmdir "$private_root"
mkdir "$WORK/foreign-private"
printf 'foreign private data\n' > "$WORK/foreign-private/sentinel"
ln -s "$WORK/foreign-private" "$private_root"
[[ -L "$private_root" ]]
refuse qa_prepare_project
refuse compose down
refuse compose exec -T wordpress php --version
grep -Fxq 'foreign private data' "$WORK/foreign-private/sentinel"
rm -f -- "$private_root"
qa_prepare_project
[[ "$WSTM_QA_PRIVATE_OUTPUT" == "$private_root" && -d "$private_root" && ! -L "$private_root" ]]
if [[ "$(uname -s)" == Linux ]]; then
	[[ "$(stat -c '%a' "$private_root")" == 700 ]]
fi

qa_export_artifact /qa-artifacts/fixture-summary.json "$private_root/exported-summary.json"
grep -Fxq '{"fixture":true}' "$private_root/exported-summary.json"
refuse qa_export_artifact /qa-artifacts/fixture-summary.json "$private_root/exported-summary.json"
refuse qa_export_artifact /qa-artifacts/fixture-summary.json "$WORK/repo/private-payload.json"
refuse qa_export_artifact /qa-artifacts/fixture-summary.json "${private_root}-foreign.json"
WSTM_QA_PRIVATE_OUTPUT='' refuse qa_export_artifact /qa-artifacts/fixture-summary.json "$private_root/missing-prefix.json"
[[ ! -e "$WORK/repo/private-payload.json" && ! -e "${private_root}-foreign.json" &&
	! -e "$private_root/missing-prefix.json" ]]
grep -Fxq '{"fixture":true}' "$private_root/exported-summary.json"

compose exec -T -e QA_VALUE=1 wordpress php --version
grep -Fq 'compose --project-name wstm-compose-control -f docker-compose.yml exec --user 33:33 -T -e QA_VALUE=1 wordpress php --version' "$QA_TEST_TRACE"
for action in up down exec cp logs; do
	unset WSTM_QA_CHECKED_PROJECT WSTM_QA_CHECKED_OWNER
	arguments=( "$action" )
	if [[ "$action" == exec ]]; then arguments+=( -T wordpress php --version ); fi
	if [[ "$action" == cp ]]; then arguments+=( fixture wordpress:/qa-artifacts/fixture ); fi
	export QA_TEST_RESOURCE=0123456789ab QA_TEST_OBSERVED_OWNER=foreign
	refuse compose "${arguments[@]}"
	export QA_TEST_OBSERVED_OWNER="$original"
	compose "${arguments[@]}"
done
unset QA_TEST_RESOURCE QA_TEST_OBSERVED_OWNER
refuse compose -p foreign up
refuse compose up --project-name foreign
refuse compose down -f foreign.yml
refuse compose exec --user 0 wordpress php --version
refuse compose exec -u0 wordpress php --version
refuse compose exec --privileged wordpress php --version
refuse compose exec --detach wordpress php --version
refuse compose exec -e
refuse compose run wordpress php --version
WSTM_QA_OWNER="$(printf 'b%.0s' {1..64})"
refuse compose down
WSTM_QA_OWNER="$original"
COMPOSE_PROJECT_NAME='../foreign'
refuse compose down
COMPOSE_PROJECT_NAME=wstm-compose-control
printf 'Owned Compose controls passed: nonroot execution, stable ownership, external private output, symlink/export refusals, foreign-resource refusals, namespace and privilege denials.\n'
