#!/bin/sh
# Builds frontend/js/datastar-rocket.js: the Datastar + Rocket release in node_modules/datastar with
# the patches in patches/rocket/ applied, the same build as Starbase's scripts/vendor-rocket.sh.
# Offline: it reads only node_modules and patches/rocket. Drop a patch once a release contains it.
#
#   sh scripts/vendor-rocket.sh                        rebuild from patches/rocket
#   sh scripts/vendor-rocket.sh --check                rebuild in a temp dir, compare with the committed bundle; writes nothing
#   sh scripts/vendor-rocket.sh --from ../starbase [--ref <commit>]
#                                                      take the patch set from a Starbase clone first
#   sh scripts/vendor-rocket.sh --if-tools             as the first form, but warn and keep the committed bundle when a tool
#                                                      is missing or the build does not match, as after a pin bump (postinstall)
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${DATASTAR_SRC:-$ROOT/node_modules/datastar}"
ESBUILD="${ESBUILD:-$ROOT/node_modules/.bin/esbuild}"
PATCHES="$ROOT/patches/rocket"
LOCK="$PATCHES/rocket.lock.json"
OUT="$ROOT/frontend/js"

check=0
if_tools=0
from=''
ref=HEAD
while [ $# -gt 0 ]; do
	case "$1" in
	--check) check=1 ;;
	--if-tools) if_tools=1 ;;
	--from) from="$2"; shift ;;
	--ref) ref="$2"; shift ;;
	*) echo "unknown argument: $1" >&2; exit 2 ;;
	esac
	shift
done

[ -f "$SRC/bundles/datastar-rocket.js" ] || { echo "no Datastar release in $SRC; run pnpm install" >&2; exit 1; }
# Under --if-tools (postinstall) a failed rebuild warns and keeps the committed bundle.
fail() {
	if [ "$if_tools" = 1 ] && [ "$check" = 0 ] && [ -z "$from" ]; then
		echo "vendor-rocket: $1; keeping the committed frontend/js/datastar-rocket.js" >&2
		exit 0
	fi
	echo "vendor-rocket: $1" >&2
	exit 1
}

missing=''
[ -x "$ESBUILD" ] || missing="esbuild at $ESBUILD"
command -v patch >/dev/null 2>&1 || missing="${missing:+$missing and }patch(1)"
[ -z "$missing" ] || fail "no $missing; install the dev dependencies (pnpm install) and patch(1)"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "$from" ]; then
	commit="$(git -C "$from" rev-parse "$ref^{commit}")"
	describe="$(git -C "$from" describe --tags "$commit")"
	mkdir "$TMP/starbase"
	git -C "$from" archive "$commit" patches/rocket static/vendor/datastar-rocket.js | tar -x -C "$TMP/starbase"
	patch_src="$TMP/starbase/patches/rocket"
	starbase_sha="$(sha256sum < "$TMP/starbase/static/vendor/datastar-rocket.js" | cut -d' ' -f1)"
else
	patch_src="$PATCHES"
	commit="$(node -p "require('$LOCK').starbase.commit")"
	describe="$(node -p "require('$LOCK').starbase.describe")"
	starbase_sha="$(node -p "require('$LOCK').sha256")"
fi

mkdir -p "$TMP/datastar" "$TMP/out"
cp -R "$SRC/library" "$TMP/datastar/library"
for p in "$patch_src"/*.patch; do
	patch -d "$TMP/datastar" -p1 -s -N -F0 < "$p" || fail "patch does not apply: $p"
done

# Relative source paths in the map stay the same on every machine, unlike Starbase's mktemp path.
banner="$(head -1 "$SRC/bundles/datastar-rocket.js") (patched: patches/rocket)"
"$ESBUILD" "$TMP/datastar/library/src/bundles/datastar-rocket.ts" \
	--bundle --minify --format=esm --target=es2022 --define:ALIAS=null \
	--tsconfig="$TMP/datastar/library/tsconfig.json" \
	--banner:js="$banner" --sourcemap \
	--outfile="$TMP/out/datastar-rocket.js" --log-level=warning

sha="$(sha256sum < "$TMP/out/datastar-rocket.js" | cut -d' ' -f1)"
[ "$sha" = "$starbase_sha" ] || fail "built $sha, Starbase $describe serves $starbase_sha: the patch set or the release differs"

if [ "$check" = 1 ]; then
	cmp -s "$TMP/out/datastar-rocket.js" "$OUT/datastar-rocket.js" || { echo "frontend/js/datastar-rocket.js differs from the build" >&2; exit 1; }
	cmp -s "$TMP/out/datastar-rocket.js.map" "$OUT/datastar-rocket.js.map" || { echo "frontend/js/datastar-rocket.js.map differs from the build" >&2; exit 1; }
	echo "ok: frontend/js/datastar-rocket.js is the build of $(basename "$SRC") + $(ls "$patch_src"/*.patch | wc -l) patches ($sha)"
	exit 0
fi

cp "$TMP/out/datastar-rocket.js" "$TMP/out/datastar-rocket.js.map" "$OUT/"
if [ "$patch_src" != "$PATCHES" ]; then
	rm -f "$PATCHES"/*.patch
	cp "$patch_src"/*.patch "$PATCHES/"
fi

BANNER="${banner#// }" COMMIT="$commit" DESCRIBE="$describe" SHA="$sha" PATCHES="$PATCHES" LOCK="$LOCK" node --input-type=module -e '
import { createHash } from "node:crypto";
import { readFileSync, readdirSync, writeFileSync } from "node:fs";
const env = process.env;
const patches = readdirSync(env.PATCHES).filter((f) => f.endsWith(".patch")).sort().map((file) => ({
    file,
    sha256: createHash("sha256").update(readFileSync(`${env.PATCHES}/${file}`)).digest("hex"),
}));
const lock = {
    banner: env.BANNER,
    sha256: env.SHA,
    starbase: {
        repository: "https://github.com/zweiundeins/starbase.git",
        commit: env.COMMIT,
        describe: env.DESCRIBE,
        path: "static/vendor/datastar-rocket.js",
    },
    patches,
};
writeFileSync(env.LOCK, JSON.stringify(lock, null, 4) + "\n");
'
echo "wrote frontend/js/datastar-rocket.js ($(wc -c < "$OUT/datastar-rocket.js") bytes, $sha) from $(head -1 "$SRC/bundles/datastar-rocket.js" | cut -c4-) + $(ls "$PATCHES"/*.patch | wc -l) patches"
