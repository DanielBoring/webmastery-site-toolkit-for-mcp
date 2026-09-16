#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export COMPATIBILITY_FIXTURE="$root/tests/.compatibility-download-$$"
mkdir "$COMPATIBILITY_FIXTURE"
trap 'rm -rf "$COMPATIBILITY_FIXTURE"' EXIT
helper="$root/scripts/compatibility-download.sh"
# The verified payload expands the fixture path only when executed.
# shellcheck disable=SC2016
printf 'printf executed > "$COMPATIBILITY_FIXTURE/executed"\n' > "$COMPATIBILITY_FIXTURE/source"

# Mock only transport: the production checksum commands and control flow remain real.
curl() {
	local destination=""
	while [ "$#" -gt 0 ]; do
		if [ "$1" = "-o" ]; then destination="$2"; shift; fi
		shift
	done
	cp "$COMPATIBILITY_FIXTURE/source" "$destination"
}
export -f curl

for algorithm in sha256 sha512; do
	digest="$("${algorithm}sum" "$COMPATIBILITY_FIXTURE/source" | awk '{print $1}')"
	bad_digest="$(printf '%*s' "${#digest}" '' | tr ' ' 0)"
	if bash "$helper" https://example.invalid/release "$bad_digest" "$algorithm" "$COMPATIBILITY_FIXTURE/download" &&
		bash "$COMPATIBILITY_FIXTURE/download"; then
		echo "Checksum mismatch unexpectedly executed payload." >&2
		exit 1
	fi
	test ! -f "$COMPATIBILITY_FIXTURE/executed"
	bash "$helper" https://example.invalid/release "$digest" "$algorithm" "$COMPATIBILITY_FIXTURE/download"
	bash "$COMPATIBILITY_FIXTURE/download"
	test "$(cat "$COMPATIBILITY_FIXTURE/executed")" = executed
	rm "$COMPATIBILITY_FIXTURE/executed"
done
if bash "$helper" http://example.invalid/release "$digest" sha512 "$COMPATIBILITY_FIXTURE/download"; then
	echo "Insecure transport unexpectedly accepted." >&2
	exit 1
fi
if bash "$helper" https://example.invalid/release 123 sha512 "$COMPATIBILITY_FIXTURE/download"; then
	echo "Malformed expected digest unexpectedly accepted." >&2
	exit 1
fi
echo "Compatibility verified-download tests passed (sha256/sha512 failures before execution)."
