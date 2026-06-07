#!/bin/bash
# After editing a src/ file: run php -l then the full test suite
file_path=$(echo "${CLAUDE_TOOL_INPUT:-}" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)"/\1/')

if [ -z "$file_path" ]; then
  exit 0
fi

if ! echo "$file_path" | grep -qE '/src/'; then
  exit 0
fi

# Get path relative to project root
rel_file=$(echo "$file_path" | sed 's|.*/ibid/laravel-vault/||')

echo "==> php -l $rel_file"
docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 php -l "$rel_file" 2>&1 || exit 1

echo "==> phpunit (no-coverage)"
docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 vendor/bin/phpunit --no-coverage 2>&1 | tail -15

exit 0
