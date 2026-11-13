#!/usr/bin/env bash
# Packs a release: the tracked files of HEAD plus the built SPA (public/build), without what the server must not have
# (tests, docs, SPA sources, secrets). Run `pnpm run build` first. Usage: deploy/package.sh [output-dir]
# Prints the path of the tarball; its name (timestamp and commit) is the release name on the server.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
[ -f public/build/manifest.json ] || { echo "public/build is missing: run 'pnpm run build' first." >&2; exit 1; }

out=${1:-deploy/out}
sha=$(git rev-parse --short HEAD)
name="$(date -u +%Y%m%d-%H%M%S)-${sha}"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$out" "$work/$name"
git archive HEAD | tar -x -C "$work/$name"
cp -r public/build "$work/$name/public/build"
# Not needed to run the application; the SPA is already built.
rm -rf "$work/$name"/{tests,e2e,.docs,.github,deploy,resources/js,openapi} "$work/$name"/.env* 2>/dev/null || true
cp .env.production.example "$work/$name/.env.production.example"

tar -czf "$out/$name.tar.gz" -C "$work/$name" .
echo "$out/$name.tar.gz"
