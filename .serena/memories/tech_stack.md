# Tech Stack

## Runtime

- PHP `^8.3` (container: `php8.3`)
- Laravel `9 | 10 | 11 | 12 | 13` — depend on `illuminate/*` components only, **never** `laravel/framework`
- `guzzlehttp/guzzle ^7.0` — HTTP client for Vault API
- `psr/log ^3.0` — logging interface
- `ext-json` required

## Dev / test

- PHPUnit `^10.0 || ^11.0 || ^12.0 || ^13.0`
- Mockery `^1.6`
- Orchestra Testbench `^8.0 || ^9.0 || ^10.0 || ^11.0`

## Testbench ↔ Laravel mapping

| Testbench | Laravel |
|---|---|
| ^8.0 | 10 |
| ^9.0 | 11 |
| ^10.0 | 12 |
| ^11.0 | 13 |

## Package type

Composer library (`"type": "library"`). No artisan executable. No `.env` secrets — bootstrap-tier keys only (`VAULT_*`, `APP_KEY`).

## Execution environment

All PHP commands run inside the `php8.3` Docker/Podman container.
Host path: `~/podman-volumes/www/ibid/laravel-vault`
Container path: `/var/www/html/ibid/laravel-vault`
See `mem:suggested_commands` for exact invocation forms.
