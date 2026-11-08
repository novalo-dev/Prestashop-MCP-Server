#!/usr/bin/env bash
# Lays the module out as it ships, in <folder>/somcp: the files git archive
# keeps (export-ignore in .gitattributes leaves the plumbing out), the Composer
# dependencies - a shop has no Composer, so vendor/ ships inside the module -
# then pruned by prune.sh. Build for Release zips this folder; the PHP tests
# build the same one to check the Addons rules on every push.
#
# Usage: dev/package/build.sh <folder>
set -euo pipefail

OUT="$1"
mkdir -p "$OUT"
git archive --format=tar --prefix=somcp/ HEAD | tar -x -C "$OUT"
composer install --working-dir="$OUT/somcp" --no-dev --no-interaction --no-progress \
  --no-scripts --no-plugins --optimize-autoloader
rm -f "$OUT/somcp/composer.lock"
"$(dirname "$0")/prune.sh" "$OUT/somcp"
