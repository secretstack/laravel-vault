#!/bin/bash
# Block edits to vendor/ and .env files
file_path=$(echo "${CLAUDE_TOOL_INPUT:-}" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')

if [ -z "$file_path" ]; then
  exit 0
fi

if echo "$file_path" | grep -qE '/vendor/'; then
  echo "BLOCKED: edits to vendor/ are forbidden in laravel-vault" >&2
  exit 2
fi

if echo "$file_path" | grep -qE '(^|/)\.env([^/]|$)'; then
  echo "BLOCKED: edits to .env are forbidden — bootstrap-tier keys only" >&2
  exit 2
fi

exit 0
