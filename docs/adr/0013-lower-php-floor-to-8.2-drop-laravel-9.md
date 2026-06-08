# Lower PHP floor to 8.2; drop Laravel 9 from declared range

**Context:** [ADR-0012](0012-extend-laravel-support-12-13-php-83.md) raised the PHP floor from
`^8.2` to `^8.3` when adding Laravel 12/13 support, on the premise that declaring `^8.2` would
be "misleading" for consumers targeting Laravel 13 (which itself requires `php ^8.3`). That
reasoning is incorrect: a package's `php` constraint states *its own* minimum, not the minimum
of every Laravel major it supports. Composer resolves each dependency's constraints
independently — Laravel 13's own `php: ^8.3` transitively blocks installation on PHP 8.2 with a
clear, accurate error message. There is no misrepresentation.

The practical consequence of ADR-0012's floor raise: every fleet service on **PHP 8.2 + Laravel
10** cannot install this package, even though both the package source and Laravel 10 run fine on
PHP 8.2.

Additionally, the `^9.0` range line added for Laravel 9 in `illuminate/*` was **unsatisfiable**:
it required `php ^8.3`, but Laravel 9 caps at PHP 8.2. No real consumer could resolve that
combination. The Laravel 9 line was dead on arrival.

**Decision:** Supersedes ADR-0012's PHP-floor and Laravel-9 decisions. ADR-0012's Laravel 12/13
additions and all other rationale remain in force.

- **PHP floor lowered:** `^8.3` → `^8.2`. The package source has no PHP 8.3-only syntax (verified
  by static scan — all class constants are untyped; all `readonly` is 8.1 promoted-param style).
- **Laravel 9 dropped** from `illuminate/*` ranges. Its v2.0.0 `^9.0` entry was unsatisfiable
  under `php ^8.3`; no consumer depended on it. Removing it keeps the declared matrix honest.
- **Effective compatibility matrix:**

  | Host PHP | Resolvable Laravel majors                                          |
  |----------|--------------------------------------------------------------------|
  | 8.2      | 10, 11, 12 (Laravel 13 excluded — its own `php ^8.3` gates this)  |
  | 8.3      | 10, 11, 12, 13                                                     |

- **`require-dev` unchanged:** `orchestra/testbench ^8.0 || ^9.0 || ^10.0 || ^11.0` and
  `phpunit/phpunit ^10.0 || ^11.0 || ^12.0 || ^13.0` already map cleanly to Laravel 10–13.
- **CI added:** `.github/workflows/tests.yml` runs a 7-combo matrix (PHP 8.2/8.3 × Laravel
  10–13, excluding L13×8.2) so the compatibility claim is verified on every push.
- **SemVer: `v2.1.0` (minor).** Lowering the floor *widens* installability — ADR-0001 noted
  explicitly that "lowering the floor later is non-breaking." Dropping the unsatisfiable Laravel 9
  range removes a broken declaration, not a working one.

**Why the transitive-gate argument is correct:** When a PHP 8.2 host runs `composer require` for
this package, Composer considers Laravel 13 as a candidate and evaluates Laravel 13's own
`php: ^8.3` constraint — it rejects Laravel 13 and selects the highest compatible Laravel major
(12). The error message, when shown, correctly names Laravel 13's PHP constraint, not ours. This
is standard Composer behaviour for ecosystem-wide PHP floor progression, and is the pattern used
by every major Laravel-ecosystem package (e.g. `spatie/*`, `laravel/telescope`).
