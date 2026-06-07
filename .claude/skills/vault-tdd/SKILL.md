---
name: vault-tdd
description: Run a TDD red-green-refactor cycle for a specific test or class in the laravel-vault package
disable-model-invocation: false
---

Run a focused TDD cycle for the given test target: $ARGUMENTS

1. Run the target test first to confirm it is RED:
   `docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 vendor/bin/phpunit --filter "$ARGUMENTS" 2>&1`
2. If it passes already, report that and stop (test is already green — may need a new test written first)
3. Implement the minimal code to make it GREEN, following the invariants in CLAUDE.md
4. Re-run the target test — confirm GREEN
5. Run the full suite to check for regressions:
   `docker exec -i -w /var/www/html/ibid/laravel-vault php8.2 vendor/bin/phpunit 2>&1`
6. Refactor if needed, then re-run the full suite
7. Report pass/fail counts and any coverage delta
