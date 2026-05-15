#!/bin/bash
# PostToolUse hook for Edit/Write: run php-cs-fixer on the edited PHP file.
# Silent + always exit 0 — never block the model on style fixes.
# Reads JSON from stdin (Claude Code hook payload) and extracts tool_input.file_path.

set +e

INPUT=$(cat)
FILE=$(printf '%s' "$INPUT" | python3 -c "import json,sys
try:
    d = json.load(sys.stdin)
    print(d.get('tool_input', {}).get('file_path', ''))
except Exception:
    pass" 2>/dev/null)

if [ -z "$FILE" ]; then
    exit 0
fi

# Only fix PHP files inside this project
case "$FILE" in
    /Users/kaioken/Code/kanvas/kanvas-ecosystem-api/*.php) ;;
    *) exit 0 ;;
esac

FIXER=/Users/kaioken/Tools/php-cs-fixer/vendor/bin/php-cs-fixer
CONFIG=/Users/kaioken/Code/kanvas/kanvas-ecosystem-api/.php-cs-fixer.php

if [ ! -x "$FIXER" ] || [ ! -f "$CONFIG" ]; then
    exit 0
fi

"$FIXER" fix --config="$CONFIG" --quiet "$FILE" >/dev/null 2>&1
exit 0
