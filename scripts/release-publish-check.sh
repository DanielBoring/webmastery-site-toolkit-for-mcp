#!/usr/bin/env bash
# Read-only remote observations. This helper never deploys, retags, or edits releases.
set -Eeuo pipefail

MODE="${1:?preflight or verify}"
VERSION="${2:?version required}"
BUNDLE="${3:?bundle required}"
SLUG="webmastery-site-toolkit-for-mcp"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
LISTING_URL="https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=${SLUG}"
STATE_DIR="build/publish-observation"
mkdir -p "$STATE_DIR"

read_listing() {
	curl --fail --silent --show-error --retry 2 --connect-timeout 15 --max-time 60 "$LISTING_URL" -o "$STATE_DIR/listing.json"
}

verify_svn() {
	rm -rf "$STATE_DIR/svn-tag"
	svn export --non-interactive --ignore-externals "${SVN_URL}/tags/${VERSION}" "$STATE_DIR/svn-tag"
	php scripts/release-tools.php compare-tree "$STATE_DIR/svn-tag" "$BUNDLE/package.zip"
}

if [[ "$MODE" == "preflight" ]]; then
	svn list --xml --non-interactive "${SVN_URL}/tags" > "$STATE_DIR/svn-tags.xml"
	gh api --paginate --slurp "repos/${GITHUB_REPOSITORY}/releases?per_page=100" > "$STATE_DIR/github-releases.json"
	read_listing
	php scripts/release-tools.php state "$VERSION" "$STATE_DIR/svn-tags.xml" "$STATE_DIR/github-releases.json" "$STATE_DIR/listing.json" > "$STATE_DIR/state"
	svn_exists="$(sed -n 's/^svn_exists=//p' "$STATE_DIR/state")"
	github_exists="$(sed -n 's/^github_exists=//p' "$STATE_DIR/state")"
	svn_matches="false"
	github_matches="false"
	if [[ "$svn_exists" == "true" ]]; then
		verify_svn
		svn_matches="true"
	fi
	if [[ "$github_exists" == "true" ]]; then
		[[ "$svn_exists" == "true" ]] || { echo "GitHub exists without SVN; investigate manually." >&2; exit 1; }
		rm -rf "$STATE_DIR/github"
		mkdir -p "$STATE_DIR/github"
		gh release download "v${VERSION}" --repo "$GITHUB_REPOSITORY" --pattern "${SLUG}-${VERSION}.zip" --dir "$STATE_DIR/github"
		cmp "$STATE_DIR/github/${SLUG}-${VERSION}.zip" "$BUNDLE/package.zip" || { echo "Existing GitHub ZIP differs; never overwrite." >&2; exit 1; }
		github_matches="true"
	fi
	decision="$(php scripts/release-tools.php decision "$VERSION" "$svn_exists" "$svn_matches" "$github_exists" "$github_matches")"
	echo "decision=$decision" >> "${GITHUB_OUTPUT:?GitHub output file required}"
	echo "Publication decision: $decision"
elif [[ "$MODE" == "verify" ]]; then
	# SVN may have committed even if the deploy action's final step failed.
	if ! verify_svn; then
		echo "::error::SVN publication is absent or differs; investigate before rerunning. No retag is permitted."
		exit 1
	fi
	for attempt in {1..12}; do
		if read_listing && php scripts/release-tools.php listing "$STATE_DIR/listing.json" "$VERSION" &&
			curl --fail --silent --show-error --connect-timeout 15 --max-time 60 "https://downloads.wordpress.org/plugin/${SLUG}.${VERSION}.zip" -o "$STATE_DIR/public.zip" &&
			php scripts/release-tools.php compare-zip "$STATE_DIR/public.zip" "$BUNDLE/package.zip"; then
			echo "Verified SVN, public listing, and public package for $VERSION."
			exit 0
		fi
		[[ "$attempt" == "12" ]] || sleep 20
	done
	echo "::error::PARTIAL PUBLICATION: SVN matches the approved package; public listing/package is unconfirmed. Rerun to verify and continue GitHub publication without redeploying or retagging."
	echo "PARTIAL PUBLICATION: SVN ${VERSION} verified; WordPress.org listing/package unconfirmed. Rerun this workflow; matching SVN tags are not redeployed." >> "${GITHUB_STEP_SUMMARY:?}"
	exit 1
else
	echo "Unknown publication check mode." >&2
	exit 1
fi
