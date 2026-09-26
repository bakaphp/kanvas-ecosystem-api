#!/bin/sh
# The image's entrypoint. Writes a project config into /workspace when one is not already there.
#
# That fallback is for a hand-started container only. On the real path Kanvas writes the config itself,
# per session, into the task's own directory — because it also carries the repository's permission
# rules — so this branch finds nothing to do and the container just serves.
#
# It has to be a project-level file: opencode resolves a custom provider from ./opencode.json and from
# nowhere else — not OPENCODE_CONFIG_CONTENT, not the global config.
set -e

CONFIG=/workspace/opencode.json

if [ ! -f "$CONFIG" ] && [ -n "${OPENCODE_PROVIDER_ID:-}" ]; then
    cat > "$CONFIG" <<JSON
{
  "\$schema": "https://opencode.ai/config.json",
  "model": "${OPENCODE_PROVIDER_ID}/${OPENCODE_MODEL:-gpt-4.1}",
  "provider": {
    "${OPENCODE_PROVIDER_ID}": {
      "name": "${OPENCODE_PROVIDER_ID}",
      "npm": "@ai-sdk/openai-compatible",
      "env": ["${OPENCODE_API_KEY_ENV:-OPENAI_API_KEY}"],
      "options": { "baseURL": "${OPENCODE_BASE_URL:-https://api.openai.com/v1}" },
      "models": { "${OPENCODE_MODEL:-gpt-4.1}": { "name": "${OPENCODE_MODEL:-gpt-4.1}" } }
    }
  },
  "permission": {
    "edit": "allow",
    "webfetch": "deny",
    "bash": { "*": "deny", "git *": "allow", "ls *": "allow", "cat *": "allow", "php *": "allow" }
  }
}
JSON
    echo "wrote $CONFIG for provider ${OPENCODE_PROVIDER_ID}"
fi

# opencode only loads a project config from a git repository. Without this the config is visible on
# /config and every turn still fails with "Model unavailable" — a symptom that points nowhere near git.
#
# `-e`, not `-d`: in a git WORKTREE `.git` is a file pointing at the real gitdir, so a `-d` test misses
# it and re-inits over a checked-out branch.
if [ ! -e /workspace/.git ]; then
    git init -q /workspace 2>/dev/null || true
    echo "initialised git in /workspace (opencode needs it to resolve the project config)"
fi

exec opencode "$@"
