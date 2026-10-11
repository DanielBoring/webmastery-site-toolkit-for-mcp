#!/usr/bin/env bash

# Injected ONLY into the copied outer orchestration fixture, before its main
# invocation. The synthetic summary is not product runtime/evidence acceptance.
run_product_qa() (
	local token source original=0 cleanup=0
	token="$(host_php -r 'echo bin2hex(random_bytes(16));')" || exit $?
	source="$(git rev-parse HEAD)" || exit $?
	wstm116_arm_retention "$token" "$source" || exit $?
	printf 'mock-product-journal\n' > "$WORK/product-journal"
	if [[ "${FAIL_PRODUCT_SUMMARY:-0}" == occupied ]]; then
		printf 'prior-summary-bytes\n' > "$E2E_ARTIFACTS_DIR/product-qa.summary.json"
	fi
	# shellcheck disable=SC2329 # Invoked exclusively by this subshell's EXIT trap.
	product_finish() {
		local status=$?
		trap - EXIT
		if [[ "${FAIL_PRODUCT_RESTORE:-0}" == 1 ]]; then
			cleanup=64
		else
			rm -f "$WORK/product-journal"
			wstm116_clear_retention "$token" "$source" || cleanup=$?
		fi
		[[ "$status" == 0 ]] || exit "$status"
		[[ "$cleanup" == 0 ]] || exit "$cleanup"
		printf 'mock-product:retired\n' >> "$TRACE"
		[[ "${FAIL_PRODUCT_SUMMARY:-0}" != 1 ]] || exit 65
		host_php -r '
			echo json_encode([
				"schema" => "wstm-product-qa-summary-v1", "status" => "passed",
				"lane" => PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION, "uid" => 33,
				"untrusted_cases" => 298, "bounded_observations" => 1461,
			], JSON_THROW_ON_ERROR), "\n";
		' > "$WORK/mock-product-summary.json" || exit $?
		( set -o noclobber; cat "$WORK/mock-product-summary.json" > "$E2E_ARTIFACTS_DIR/product-qa.summary.json" ) || exit 66
		printf 'mock-product:summary-published\n' >> "$TRACE"
		exit 0
	}
	trap product_finish EXIT
	printf 'mock-product:untrusted-content-runner.php\n' >> "$TRACE"
	[[ "${FAIL_PRODUCT_RUNNER:-0}" != 1 ]] || exit 63
	printf 'mock-product:bounded-list-benchmark.php\n' >> "$TRACE"
	exit "$original"
)
