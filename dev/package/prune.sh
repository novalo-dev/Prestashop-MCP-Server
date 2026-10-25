#!/usr/bin/env bash
# Removes from a vendor folder what dev/package/prune.txt lists, then puts an
# index.php in every folder left, which the Addons validator requires.
#
# Usage: dev/package/prune.sh <module folder>
# The module folder holds vendor/; the list is read from the same folder as
# this script.
set -euo pipefail

MODULE="$1"
LIST="$(dirname "$0")/prune.txt"
VENDOR="$MODULE/vendor"

[ -d "$VENDOR" ] || { echo "no vendor folder in $MODULE"; exit 1; }

while IFS= read -r line; do
  line="${line%%#*}"
  line="$(echo "$line" | xargs)"
  [ -z "$line" ] && continue
  # Globs expand inside vendor only; a path climbing out of it is refused.
  case "$line" in *..*) echo "refused: $line"; exit 1;; esac
  for path in $VENDOR/$line; do
    [ -e "$path" ] && rm -rf "$path"
  done
done < "$LIST"

# Documentation files of the dependencies are not code the shop needs.
find "$VENDOR" -type f \( -name 'AGENTS.md' -o -name 'CHANGELOG.md' -o -name 'CONTRIBUTING.md' \) -delete

find "$VENDOR" -type d | while IFS= read -r dir; do
  [ -e "$dir/index.php" ] || cp "$MODULE/index.php" "$dir/index.php"
done

echo "vendor pruned: $(find "$VENDOR" -name '*.php' | wc -l) PHP files left"
