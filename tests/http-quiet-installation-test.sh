#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(pwd -P)"
mkdir -p build
WORK="$(mktemp -d "$ROOT/build/http-quiet-unit-XXXXXXXX")"
trap 'status=$?; if [[ "$status" == 0 ]]; then rm -r -- "$WORK"; else echo "HTTP-quiet controls retained at $WORK" >&2; fi; exit "$status"' EXIT
# shellcheck source=/dev/null
source scripts/e2e-test.sh all
export COMPOSE_PROJECT_NAME=wstm-quiet-unit WSTM_PRODUCT_RUN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export WSTM_QA_OWNER=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export WSTM_QA_DB_PASSWORD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export E2E_SOURCE_IDENTITY=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
export WSTM_QUIET_WP_REFERENCE=wordpress:7.1.2-php8.2-apache WSTM_QUIET_DB_REFERENCE=mysql:8.0.36
export WSTM_QUIET_WP_IMAGE=sha256:5cbb0456741c335249cb26406ca8bef1e8919dbc8af1481c81fd7041a4c3b405
export WSTM_QUIET_DB_IMAGE=sha256:a532724022429812ec797c285c1b540a644c15e248579c6bfdf12a8fbaab4964
qa_compose_command() {
	[[ "$*" == 'config --format json' ]] || return 89
	printf '{"name":"%s","services":{"wordpress":{"image":"%s"},"mysql":{"image":"%s"}}}\n' \
		"$COMPOSE_PROJECT_NAME" "$WSTM_QUIET_WP_REFERENCE" "$WSTM_QUIET_DB_REFERENCE"
}
qa_verify_resources() { :; }

assert_trace_absent() {
	local status=0
	grep -q "$1" "$WORK/trace" || status=$?
	[[ "$status" == 1 ]] || {
		echo "Forbidden command found or trace unreadable: grep status=$status." >&2
		exit 1
	}
}
(
	: > "$WORK/trace"
	assert_trace_absent '^forbidden'
	printf 'forbidden\n' > "$WORK/trace"
	if (assert_trace_absent '^forbidden') > "$WORK/negative-present.log" 2>&1; then
		echo 'Negative trace assertion accepted a forbidden command.' >&2; exit 1
	fi
	rm "$WORK/trace"
	if (assert_trace_absent '^forbidden') > "$WORK/negative-unreadable.log" 2>&1; then
		echo 'Negative trace assertion accepted an unreadable trace.' >&2; exit 1
	fi
)
echo 'PASS negative trace assertions: absence accepted, forbidden/unreadable traces refuse.'

quiet_image_controls() (
	local scenario count=0 directory
	local WSTM_QUIET_WP_IMAGE WSTM_QUIET_DB_IMAGE WSTM_QUIET_WP_REFERENCE WSTM_QUIET_DB_REFERENCE
	# shellcheck disable=SC2329 # Invoked by the actual sourced image observation helper.
	qa_compose_command() {
		[[ "$*" == 'config --format json' ]] || return 89
		[[ "$scenario" != config-failure ]] || return 72
		if [[ "$scenario" == reference-change ]]; then
			printf '{"name":"wstm-quiet-unit","services":{"wordpress":{"image":"wordpress:changed"},"mysql":{"image":"mysql:8.0.36"}}}\n'
		else
			printf '{"name":"wstm-quiet-unit","services":{"wordpress":{"image":"wordpress:7.1.2-php8.2-apache"},"mysql":{"image":"mysql:8.0.36"}}}\n'
		fi
	}
	# shellcheck disable=SC2329 # Invoked by the actual sourced image observation helper.
	docker() {
		[[ "$1 $2 $3 $4" == 'image inspect --format {{.Id}}' ]] || return 89
		[[ "$scenario" != inspect-failure ]] || return 73
		if [[ "$scenario" == ambiguous ]]; then printf 'sha256:%064d\nsha256:%064d\n' 4 5
		elif [[ "$scenario" == malformed ]]; then printf 'wordpress:tag\n'
		elif [[ "$scenario" == image-change ]]; then printf 'sha256:%064d\n' 7
		elif [[ "$5" == wordpress:* ]]; then printf 'sha256:%064d\n' 4
		elif [[ "$5" == mysql:* ]]; then printf 'sha256:%064d\n' 5
		else return 89; fi
	}
	scenario=valid
	directory="$WORK/image-contract"
	mkdir "$directory"
	# Caller-supplied values are overwritten by the owned configured/resolved contract.
	# shellcheck disable=SC2030 # Deliberately isolated caller values; other fixtures bind their own image contract.
	export WSTM_QUIET_WP_IMAGE=foreign WSTM_QUIET_DB_IMAGE=foreign
	qa_quiet_images "$directory/bind" bind
	[[ "$WSTM_QUIET_WP_IMAGE" == "sha256:$(printf '%064d' 4)" &&
		"$WSTM_QUIET_DB_IMAGE" == "sha256:$(printf '%064d' 5)" ]] || return 1
	for scenario in valid reference-change image-change ambiguous malformed config-failure inspect-failure; do
		local image_status=0
		qa_quiet_images "$directory/$scenario" verify > "$directory/$scenario.log" 2>&1 || image_status=$?
		if [[ "$scenario" == valid ]]; then [[ "$image_status" == 0 ]] || return 1
		else [[ "$image_status" != 0 ]] || return 1; fi
		count=$((count + 1))
	done
	echo "PASS $count actual configured/resolved image contracts: bind, stable verify, mutations and acquisition failures."
)
quiet_image_controls

# Exercise the actual snapshot, native validator and volume census together.
quiet_snapshot_controls() (
	# shellcheck disable=SC2030,SC2031 # Explicitly reset this subshell's fixture ID; later orchestration obtains a new ID.
	export WSTM_QUIET_WP=1111111111111111111111111111111111111111111111111111111111111111
	# shellcheck disable=SC2030,SC2031 # Explicitly reset the fixture DB ID rather than reusing a prior installer value.
	export WSTM_QUIET_DB=2222222222222222222222222222222222222222222222222222222222222222
	# shellcheck disable=SC2031 # This fixture supplies a fixed cwd independently of qa_quiet_install.
	export WSTM_QUIET_SOURCE_ROOT=/work/source
	local package mode target_suffix kind phase status foreign_id count=0 result=0
	# shellcheck disable=SC2031 # This EXIT trap reads the current case in its own enclosing subshell.
	trap 'result=$?; if [[ "$result" != 0 ]]; then
		echo "Census control failure: package=${package:-unset} mode=${mode:-unset} suffix=${target_suffix:-unset} kind=${kind:-unset} phase=${phase:-unset} count=$count status=${status:-unset}" >&2
		if [[ -f "${WSTM_QUIET_DIRECTORY:-}/stderr" ]]; then cat "$WSTM_QUIET_DIRECTORY/stderr" >&2; fi
	fi; exit "$result"' EXIT
	# shellcheck disable=SC2329 # Called indirectly by the sourced real snapshot and volume census.
	docker() {
		local selected="${5:-}" ids=() observed_count
		if [[ "$1 $2" == 'image inspect' ]]; then
			[[ "$3 $4" == "--format {{.Id}}" ]] || return 89
			# shellcheck disable=SC2031 # This snapshot fixture supplies its own expected image IDs.
			if [[ "$5" == "$WSTM_QUIET_WP_REFERENCE" ]]; then printf '%s\n' "$WSTM_QUIET_WP_IMAGE"
			elif [[ "$5" == "$WSTM_QUIET_DB_REFERENCE" ]]; then printf '%s\n' "$WSTM_QUIET_DB_IMAGE"
			else return 89; fi
			return
		fi
		[[ "$1 $2" == 'container ls' || "$1 $2" == 'container inspect' || "$1 $2" == 'network inspect' ]] || return 89
		if [[ "$1 $2" == 'container ls' ]]; then
			[[ "$3 $4 $5" == '-aq --no-trunc --filter' ]] || return 89
			selected="$6"
			if [[ "$selected" == "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" ]]; then
				printf '%s\n' "$WSTM_QUIET_WP" "$WSTM_QUIET_DB"
				# shellcheck disable=SC2031 # CLI presence is explicitly selected for the current snapshot fixture mode.
				if [[ -n "${WSTM_QUIET_CLI:-}" ]]; then printf '%s\n' "$WSTM_QUIET_CLI"; fi
				return
			fi
			[[ "$selected" == "volume=${COMPOSE_PROJECT_NAME}_wp_data" ||
				"$selected" == "volume=${COMPOSE_PROJECT_NAME}_qa_artifacts" ||
				"$selected" == "volume=${COMPOSE_PROJECT_NAME}_mysql_data" ]] || return 89
			if [[ "$selected" == "volume=${COMPOSE_PROJECT_NAME}_mysql_data" ]]; then
				ids=("$WSTM_QUIET_DB")
			else
				ids=("$WSTM_QUIET_WP")
				# shellcheck disable=SC2031 # Use only the enclosing snapshot case's selected CLI ID.
				if [[ -n "${WSTM_QUIET_CLI:-}" ]]; then ids+=("$WSTM_QUIET_CLI"); fi
			fi
			if [[ "$selected" == "volume=${COMPOSE_PROJECT_NAME}_$target_suffix" ]]; then
				observed_count=0
				# shellcheck disable=SC2031 # The current fixture creates this directory before invoking the Docker callback.
				if [[ -f "$WSTM_QUIET_DIRECTORY/queries" ]]; then read -r observed_count < "$WSTM_QUIET_DIRECTORY/queries"; fi
				# shellcheck disable=SC2031 # This callback updates only its current fixture directory.
				printf '%s\n' "$((observed_count + 1))" >| "$WSTM_QUIET_DIRECTORY/queries" || return $?
				if [[ "$phase" == before || "$observed_count" -ge 1 ]]; then
					# shellcheck disable=SC2031 # The Docker callback inherits this enclosing fixture's current case.
					case "$kind" in
						foreign) ids+=("$foreign_id") ;;
						missing) ids=() ;;
						duplicate) ids+=("${ids[0]}") ;;
						replaced) ids=("$foreign_id") ;;
						truncated) ids=(111111111111) ;;
						unreadable) return 71 ;;
					esac
				fi
			fi
			if [[ "${#ids[@]}" -gt 0 ]]; then printf '%s\n' "${ids[@]}"; fi
		elif [[ "$1 $2" == 'container inspect' ]]; then
			# shellcheck disable=SC2031 # The enclosing fixture explicitly chooses its IDs before each snapshot.
			if [[ "$3" == "$WSTM_QUIET_WP" && "${4:-}" == "$WSTM_QUIET_DB" ]]; then
				# shellcheck disable=SC2031 # Mode is local to the same enclosing snapshot fixture.
				cat "$WORK/native-$package-$mode.containers"
			elif [[ "$3" == "${WSTM_QUIET_CLI:-}" ]]; then
				# shellcheck disable=SC2031 # Mode is inherited by this callback, not returned from another case.
				cat "$WORK/native-$package-$mode.cli"
			else return 89
			fi
		else
			[[ "$3" == "${COMPOSE_PROJECT_NAME}_default" ]] || return 89
			# shellcheck disable=SC2031 # The callback reads its enclosing fixture's current mode.
			cat "$WORK/native-$package-$mode.network"
		fi
	}
	for package in source package; do
		unset E2E_PACKAGE_ROOT E2E_CHECKER_ROOT E2E_PACKAGE_ZIP
		if [[ "$package" == package ]]; then
			export E2E_PACKAGE_ROOT=./build/package E2E_CHECKER_ROOT=./build/checker E2E_PACKAGE_ZIP=/original.zip
		fi
		for mode in original quiet cli stopped-cli restored; do
			unset WSTM_QUIET_CLI
			if [[ "$mode" == cli || "$mode" == stopped-cli ]]; then
				# shellcheck disable=SC2030,SC2031 # Reset this fixture CLI, isolated from later real orchestration cases.
				export WSTM_QUIET_CLI=3333333333333333333333333333333333333333333333333333333333333333
			fi
			# shellcheck disable=SC2016 # Generate literal PHP fixture bytes without host-shell expansion.
			host_php -r '
			require "vendor/autoload.php";
			require "tests/unit/HttpQuietInstallationTest.php";
			$test = new HttpQuietInstallationTest("testSourceAndPackageCustodyAcrossAllQuietLifecycleStates");
			$method = new ReflectionMethod($test, "fixture"); $method->setAccessible(true);
			[$records] = $method->invoke($test, $argv[1] === "package", $argv[2]);
			$foreign = ["Id" => str_repeat("f", 64),
				"Config" => ["Labels" => ["com.docker.compose.project" => "foreign-project"]],
				"NetworkSettings" => ["Networks" => ["foreign-network" => []]],
				"Mounts" => []];
			foreach (["wp_data" => "/var/www/html", "qa_artifacts" => "/qa-artifacts", "mysql_data" => "/var/lib/mysql"] as $suffix => $target) {
				$foreign["Mounts"][] = ["Type" => "volume", "Name" => "wstm-quiet-unit_" . $suffix,
					"Destination" => $target, "RW" => true];
			}
			foreach (["containers" => array_slice($records, 0, 2), "network" => [$records[2]],
				"cli" => isset($records[3]) ? [$records[3]] : [], "foreign" => [$foreign]] as $part => $value) {
				if (file_put_contents($argv[3] . "." . $part, json_encode($value, JSON_THROW_ON_ERROR)) === false) {
					throw new RuntimeException("Modeled native acquisition write failed.");
				}
			}' "$package" "$mode" "$WORK/native-$package-$mode"
			# shellcheck disable=SC2016 # PHP verifies the private fixture; Bash must not expand its variables.
			foreign_id="$(host_php -r '
			[$foreign] = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
			if ($foreign["Config"]["Labels"]["com.docker.compose.project"] === "wstm-quiet-unit"
				|| array_key_exists("wstm-quiet-unit_default", $foreign["NetworkSettings"]["Networks"])) {
				throw new RuntimeException("Foreign consumer fixture is not outside project/network.");
			}
			foreach ($foreign["Mounts"] as $mount) {
				if ($mount["Type"] !== "volume" || $mount["RW"] !== true) {
					throw new RuntimeException("Foreign consumer fixture is not a writable volume attachment.");
				}
			}
			echo $foreign["Id"];' "$WORK/native-$package-$mode.foreign")"
			for target_suffix in wp_data qa_artifacts mysql_data; do
				for kind in valid foreign missing duplicate replaced truncated unreadable; do
					for phase in before after; do
						count=$((count + 1))
						# shellcheck disable=SC2030 # Each snapshot case owns a fresh directory within this subshell only.
						WSTM_QUIET_DIRECTORY="$WORK/census-$count"
						mkdir -m 0700 "$WSTM_QUIET_DIRECTORY"
						# shellcheck disable=SC2034 # Read dynamically by the sourced qa_quiet_snapshot deadline check.
						WSTM_QUIET_STARTED=$SECONDS
						status=0
						qa_quiet_snapshot "$mode" > "$WSTM_QUIET_DIRECTORY/argv" 2> "$WSTM_QUIET_DIRECTORY/stderr" || status=$?
						if [[ "$kind" == valid ]]; then
							[[ "$status" == 0 ]] || { cat "$WSTM_QUIET_DIRECTORY/stderr" >&2; return 1; }
							[[ "$(cat "$WSTM_QUIET_DIRECTORY/queries")" == 2 ]] || return 1
						elif [[ "$kind" == unreadable ]]; then [[ "$status" == 71 ]] || return 1
						else
							[[ "$status" == 1 ]] || return 1
							grep -F 'HTTP-quiet volume' "$WSTM_QUIET_DIRECTORY/stderr" >/dev/null || return 1
						fi
					done
				done
			done
		done
	done
	echo "PASS $count actual source/package snapshots: exact three-volume consumers, foreign outside project/network, before/after drift and unreadable refusals."
)
quiet_snapshot_controls

# Model only external resource observations. The actual quiet orchestration runs.
qa_prepare_project() { :; }
qa_quiet_snapshot() {
	printf 'snapshot:%s\n' "$1" >> "$WORK/trace"
	# shellcheck disable=SC2031 # qa_quiet_install sets this case's directory; the earlier fixture does not supply it.
	qa_quiet_volume_consumers "$WSTM_QUIET_DIRECTORY/modeled-census-${RANDOM}-${RANDOM}" || return $?
	if [[ "$1" == original ]]; then
		[[ "$SCENARIO" != before-cli ]] || return 41
		printf '%s\0' --mount 'type=bind,source=/owned,target=/source,readonly' --mount 'type=volume,source=wp,target=/var/www/html' \
			--mount 'type=volume,source=qa,target=/qa-artifacts' --mount 'type=bind,source=/tests,target=/tests,readonly' --mount 'type=bind,source=/support,target=/support,readonly'
	fi
	[[ "$SCENARIO:$1" != quiet:quiet ]] || return 46
	[[ "$SCENARIO:$1" != restored:restored ]] || return 50
}
compose() {
	printf 'compose:%s\n' "$*" >> "$WORK/trace"
	case "$*" in
		'ps -a -q wordpress') printf '%064d' 1 ;;
		'ps -a -q mysql') printf '%064d' 2 ;;
		'stop wordpress') [[ "$SCENARIO" != stop-http ]] || return 45; printf quiet > "$WORK/http" ;;
		'start wordpress')
			[[ -f "$WORK/verified" && ! -f "$WORK/cli" ]] || return 88
			[[ "$SCENARIO" != restore ]] || return 43
			printf running > "$WORK/http" ;;
		*) echo 'Unexpected Compose action.' >&2; return 89 ;;
	esac
}
docker() {
	printf 'docker:%s\n' "$*" >> "$WORK/trace"
	case "$1" in
		image)
			[[ "$2 $3 $4" == 'inspect --format {{.Id}}' ]] || return 89
			# shellcheck disable=SC2031 # The real installer binds these IDs in this enclosing orchestration fixture.
			if [[ "$5" == "$WSTM_QUIET_WP_REFERENCE" ]]; then printf '%s\n' "$WSTM_QUIET_WP_IMAGE"
			elif [[ "$5" == "$WSTM_QUIET_DB_REFERENCE" ]]; then printf '%s\n' "$WSTM_QUIET_DB_IMAGE"
			else return 89; fi ;;
		container)
			[[ "$2 $3 $4 $5" == 'ls -aq --no-trunc --filter' ]] || return 89
			case "$6" in
				"volume=${COMPOSE_PROJECT_NAME}_mysql_data")
					# shellcheck disable=SC2031 # The real installer sets the DB ID for this orchestration case.
					printf '%s\n' "$WSTM_QUIET_DB" ;;
				"volume=${COMPOSE_PROJECT_NAME}_wp_data"|"volume=${COMPOSE_PROJECT_NAME}_qa_artifacts")
					# shellcheck disable=SC2031 # Read the current qa_quiet_install identity, not the prior snapshot fixture.
					printf '%s\n' "$WSTM_QUIET_WP"
					# shellcheck disable=SC2031 # The real installer creates and exports this case's CLI ID.
					if [[ -n "${WSTM_QUIET_CLI:-}" ]]; then printf '%s\n' "$WSTM_QUIET_CLI"; fi
					if [[ "$SCENARIO" == foreign-volume ||
						( "$SCENARIO" == foreign-volume-created && -f "$WORK/cli" ) ||
						( "$SCENARIO" == foreign-volume-late && -f "$WORK/verified" ) ||
						( "$SCENARIO" == foreign-volume-restore && -f "$WORK/verified" && ! -f "$WORK/cli" ) ]]; then
						printf '%s\n' ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff
					fi ;;
				*) return 89 ;;
			esac ;;
		create)
			[[ "$(cat "$WORK/http")" == quiet && "$*" == *"--user 33:33 --read-only --cap-drop ALL --security-opt no-new-privileges"* ]] || return 88
			[[ "$*" != *--publish* && "$*" != *docker.sock* ]] || return 88
			# shellcheck disable=SC2031 # Assert the real installer's newly bound image argument, not prior isolated control values.
			[[ "$*" == *"--entrypoint sleep $WSTM_QUIET_WP_IMAGE 600" ]] || return 88
			printf owned > "$WORK/cli"; printf '%064d' 3 ;;
		start) [[ "$SCENARIO" != start-cli ]] || return 47; test -f "$WORK/cli" ;;
		exec)
			[[ "$(cat "$WORK/http")" == quiet ]] || return 88
			if [[ "$*" == *"test -e "* ]]; then [[ "$SCENARIO" != missing-original ]] || return 1; return 0; fi
			if [[ "$*" == *"install-owned-wordpress.php --verify" ]]; then
				[[ "$SCENARIO" != verify ]] || return 48
				printf verified > "$WORK/verified"; return 0
			fi
			[[ "$*" == *"install-owned-wordpress.php" && "$*" != *--observe* ]] || return 89
			local password; IFS= read -r password
			[[ "$password" =~ ^[a-f0-9]{64}$ ]] || return 88
			[[ "$SCENARIO" != installer && "$SCENARIO" != export ]] || return 255 ;;
		stop) [[ "$SCENARIO" != cleanup ]] || return 49; test -f "$WORK/cli" ;;
		rm) rm "$WORK/cli" ;;
		*) return 89 ;;
	esac
}
qa_export_artifact() {
	printf 'export:%s\n' "$1" >> "$WORK/trace"
	# shellcheck disable=SC2031 # This export callback observes the current real installer subshell's CLI.
	[[ "$3" == "$WSTM_QUIET_CLI" && "$(cat "$WORK/http")" == quiet ]] || return 88
	[[ "$SCENARIO" != export ]] || return 44
	printf retained > "$2"
}
wstm116_arm_retention() { printf retained > "$WORK/retention"; }

for SCENARIO in success before-cli stop-http quiet start-cli installer verify restore restored export cleanup missing-original foreign-volume foreign-volume-created foreign-volume-late foreign-volume-restore; do
	mkdir "$WORK/$SCENARIO"
	export WSTM_QA_PRIVATE_OUTPUT="$WORK/$SCENARIO"
	: > "$WORK/trace"
	rm -f "$WORK/http" "$WORK/cli" "$WORK/verified" "$WORK/retention"
	printf running > "$WORK/http"
	status=0
	qa_quiet_install aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa > "$WORK/$SCENARIO.log" 2>&1 || status=$?
	case "$SCENARIO" in
		success) [[ "$status" == 0 && "$(cat "$WORK/http")" == running && ! -e "$WORK/cli" ]] ;;
		before-cli) [[ "$status" == 41 && "$(cat "$WORK/http")" == running && ! -e "$WORK/cli" ]] ;;
		stop-http) [[ "$status" == 45 && "$(cat "$WORK/http")" == running && ! -e "$WORK/cli" ]] ;;
		quiet) [[ "$status" == 46 && "$(cat "$WORK/http")" == quiet && ! -e "$WORK/cli" ]] ;;
		start-cli) [[ "$status" == 47 && "$(cat "$WORK/http")" == quiet && -e "$WORK/cli" && -e "$WORK/retention" ]] ;;
		installer) [[ "$status" == 255 && "$(cat "$WORK/http")" == quiet && ! -e "$WORK/cli" ]]
			[[ "$(grep -c '^export:' "$WORK/trace")" == 4 ]] ;;
		restore) [[ "$status" == 43 && "$(cat "$WORK/http")" == quiet && ! -e "$WORK/cli" ]] ;;
		restored) [[ "$status" == 50 && "$(cat "$WORK/http")" == running && ! -e "$WORK/cli" ]] ;;
		verify) [[ "$status" == 48 && "$(cat "$WORK/http")" == quiet && ! -e "$WORK/cli" ]] ;;
		export) [[ "$status" == 255 && -e "$WORK/cli" && -e "$WORK/retention" && "$(cat "$WORK/http")" == quiet ]] ;;
		cleanup) [[ "$status" == 49 && -e "$WORK/cli" && -e "$WORK/retention" && "$(cat "$WORK/http")" == quiet ]] ;;
		missing-original) [[ "$status" == 1 && -e "$WORK/cli" && -e "$WORK/retention" && "$(cat "$WORK/http")" == quiet ]] ;;
		foreign-volume) [[ "$status" == 1 && ! -e "$WORK/cli" && "$(cat "$WORK/http")" == running ]]
			assert_trace_absent '^compose:stop\|^docker:create\|^docker:exec' ;;
		foreign-volume-created) [[ "$status" == 1 && -e "$WORK/cli" && -e "$WORK/retention" && "$(cat "$WORK/http")" == quiet ]]
			assert_trace_absent '^docker:start\|^docker:exec\|^docker:stop\|^docker:rm\|^compose:start' ;;
		foreign-volume-late) [[ "$status" == 1 && -e "$WORK/cli" && -e "$WORK/retention" && "$(cat "$WORK/http")" == quiet ]]
			assert_trace_absent '^docker:stop\|^docker:rm\|^compose:start\|^export:' ;;
		foreign-volume-restore) [[ "$status" == 1 && ! -e "$WORK/cli" && "$(cat "$WORK/http")" == quiet ]]
			[[ "$(grep -c '^export:' "$WORK/trace")" == 4 ]]
			assert_trace_absent '^compose:start' ;;
	esac
	if [[ "$SCENARIO" == restore || "$SCENARIO" == restored ]]; then
		[[ "$(grep -c '^export:' "$WORK/trace")" == 4 ]]
	fi
	if [[ "$SCENARIO" != success && "$SCENARIO" != export ]]; then
		assert_trace_absent 'docker:.*--observe'
	fi
done
echo 'PASS actual quiet orchestration: normal install/verify before HTTP restore, pre-CLI/install/restore failures.'
