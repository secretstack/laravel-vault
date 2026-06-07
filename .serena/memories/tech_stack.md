# Tech Stack

## Runtime

- PHP `^8.2` (container: `php8.2`)
- Laravel `9 | 10 | 11` — depend on `illuminate/*` components only, **never** `laravel/framework`
- `guzzlehttp/guzzle ^7.0` — HTTP client for Vault API
- `psr/log ^3.0` — logging interface
- `ext-json` required

## Dev / test

- PHPUnit `^10.0 || ^11.0`
- Mockery `^1.6`
- Orchestra Testbench `^8.0 || ^9.0`

## Package type

Composer library (`"type": "library"`). No artisan executable. No `.env` secrets — bootstrap-tier keys only (`VAULT_*`, `APP_KEY`).

## Execution environment

All PHP commands run inside the `php8.2` Docker/Podman container.
Host path: `~/podman-volumes/www/ibid/laravel-vault`
Container path: `/var/www/html/ibid/laravel-vault`
See `mem:suggested_commands` for exact invocation forms.
