#!/usr/bin/env bash
#
# Builds the installable plugin package (event-registration.zip) at the repo root.
#
# Mirrors .github/workflows/release.yml so the local artifact matches what a tag
# release would publish:
#   git archive HEAD  +  composer install --no-dev  +  host-built build/  +  .distignore
# staged into a top-level event-registration/ folder, zipped to event-registration.zip.
#
# The host has no zip/rsync, so composer and packaging run inside Docker. Run
# `npm run build` first — this script copies the existing build/ tree, it does
# not rebuild JS.
#
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$REPO/.dist-tmp"
OUT="$REPO/event-registration.zip"

echo "==> Staging clean tree from HEAD"
rm -rf "$STAGE"
mkdir -p "$STAGE"
git -C "$REPO" archive HEAD --format=tar | tar -x -C "$STAGE"

echo "==> Copying host-built assets (build/)"
if [ ! -d "$REPO/build/admin" ]; then
	echo "ERROR: build/admin missing — run 'npm run build' first." >&2
	exit 1
fi
cp -r "$REPO/build" "$STAGE/build"

echo "==> composer install --no-dev (Docker)"
MSYS_NO_PATHCONV=1 docker run --rm -v "$STAGE:/app" -w /app composer:2 \
	install --no-dev --optimize-autoloader --prefer-dist --no-progress --no-interaction

echo "==> Assembling + zipping (Docker alpine: rsync + zip)"
rm -f "$OUT"
MSYS_NO_PATHCONV=1 docker run --rm -v "$STAGE:/w" -w /w alpine:3 sh -c '
	apk add --no-cache rsync zip >/dev/null &&
	rm -rf event-registration out.zip &&
	mkdir event-registration &&
	rsync -a --exclude-from=.distignore --exclude event-registration ./ event-registration/ &&
	zip -rq out.zip event-registration
'

cp "$STAGE/out.zip" "$OUT"
rm -rf "$STAGE"

echo "==> Done: $OUT"
ls -la "$OUT"
