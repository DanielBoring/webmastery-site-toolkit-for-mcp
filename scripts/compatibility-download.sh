#!/usr/bin/env bash
# Download only; callers may install/execute the destination after this succeeds.
set -euo pipefail

url="${1:?HTTPS URL required}"
digest="${2:?Expected digest required}"
algorithm="${3:?sha256 or sha512 required}"
destination="${4:?Destination required}"

case "$algorithm" in
	sha256) length=64 ;;
	sha512) length=128 ;;
	*) echo "Unsupported digest algorithm." >&2; exit 1 ;;
esac
if [[ ! "$url" =~ ^https:// ]] || [[ ! "$digest" =~ ^[a-f0-9]+$ ]] || [ "${#digest}" -ne "$length" ]; then
	echo "Invalid HTTPS download URL or expected digest." >&2
	exit 1
fi

curl --proto '=https' --proto-redir '=https' --retry 3 --connect-timeout 20 --max-time 180 \
	-fsSL "$url" -o "$destination"
printf '%s  %s\n' "$digest" "$destination" | "${algorithm}sum" --check --status || {
	echo "Checksum mismatch; refusing to use ${destination}." >&2
	exit 1
}
