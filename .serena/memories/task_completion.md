# Task Completion Checklist

Run these in order before marking any `src/` change done:

## 1. Syntax check changed files
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 php -l src/Path/To/Changed.php
```

## 2. Full test suite (must be green)
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 vendor/bin/phpunit
```

## 3. Coverage (must stay ≥ 80% on src/)
```bash
docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 vendor/bin/phpunit --coverage-text
```

## 4. Invariant review

Use the `invariant-reviewer` subagent (`.claude/agents/invariant-reviewer.md`) on the diff.
All 8 invariants must be PASS before commit.

## 5. ADR check

If the change touches the consumer surface (bootstrap line, VAULT_* keys, Vault::get(), SecretProvider, config/vault.php keys, artisan commands) — write a new ADR first using `/adr`.
