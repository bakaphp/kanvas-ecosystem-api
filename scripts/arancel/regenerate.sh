#!/usr/bin/env bash
#
# Regenerate the customs tariff dataset from the official DGA PDF.
#
#   ./scripts/arancel/regenerate.sh ~/Downloads/arancel-aduanas-7ma-enmienda-2022.pdf
#
# Requires poppler (`brew install poppler`) for pdftotext.
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "usage: $0 <arancel.pdf>" >&2
    exit 1
fi

PDF="$1"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="$ROOT/resources/data/arancel/arancel_rates.php"
TXT="$(mktemp -t arancel).txt"

trap 'rm -f "$TXT" "${TARGET%.php}.json"' EXIT

command -v pdftotext >/dev/null || { echo "pdftotext not found: brew install poppler" >&2; exit 1; }

echo "extracting text from $PDF"
pdftotext -layout "$PDF" "$TXT"

echo "parsing schedule"
python3 "$ROOT/scripts/arancel/parse_arancel.py" "$TXT" "$TARGET"

php -l "$TARGET" >/dev/null
echo "done: $TARGET"
