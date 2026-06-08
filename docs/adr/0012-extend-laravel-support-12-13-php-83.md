# Extend supported Laravel versions to 12 and 13; raise PHP floor to 8.3

> **Superseded (PHP floor + Laravel 9 decisions only):** [ADR-0013](0013-lower-php-floor-to-8.2-drop-laravel-9.md)
> lowers the floor back to `^8.2` (the "misrepresentation" argument is rebutted there) and drops
> the unsatisfiable Laravel 9 range. The Laravel 12/13 additions and all other rationale in this
> ADR remain in force.

**Context:** [ADR-0001](0001-vault-package-targets-laravel-9-php-82.md) set the initial targets at
Laravel 9/10/11 on PHP 8.2+, and explicitly noted that "lowering the floor later is non-breaking;
raising it is not — so we start conservative." The fleet has since moved onto Laravel 12/13, and
Laravel 13 itself requires PHP 8.3 as its minimum. All 30+ services running this package need to
upgrade their Laravel majors without being blocked by the package's declared constraints.

**Decision:** This ADR extends the supported version matrix and supersedes the version targets in
ADR-0001. All other ADR-0001 rationale (Lumen exclusion, boot-order invariants, etc.) remains
in force.

- **Supported Laravel majors:** `9 | 10 | 11 | 12 | 13` (additive on the upper end; lower bound
  unchanged).
- **PHP floor raised:** `^8.2` → `^8.3`. Laravel 13 requires PHP 8.3; keeping the floor lower
  would misrepresent real install requirements for consumers targeting L13.
- **Testbench alignment:** `orchestra/testbench ^8.0 || ^9.0 || ^10.0 || ^11.0` (10→L12, 11→L13).
- **PHPUnit range widened:** `^10.0 || ^11.0 || ^12.0 || ^13.0` (Testbench 10/11 pull PHPUnit
  11/12/13).
- **No runtime code changes:** every framework touchpoint used by the package
  (`afterBootstrapping(LoadEnvironmentVariables)`, config repository, `Env::get`, `Encrypter`,
  console `Command`, facade) is stable across L9–L13. The existing test suite passing on each
  Laravel major is the regression gate.
- **Breaking change — major version bump required:** raising the PHP floor from 8.2 to 8.3 breaks
  install for consumers still on PHP 8.2. This warrants a **v2.0.0** release tag; fleet services
  must upgrade their PHP runtime before updating the package.

**Why raising the PHP floor is correct here:** ADR-0001 anticipated this exact moment — "raising
it is not non-breaking." The driver is not convenience but necessity: Laravel 13 simply does not
install on PHP 8.2, so continuing to declare `^8.2` would create a misleading constraint that
Composer cannot honour for L13 consumers. A clean floor raise with a major version bump is the
honest representation of the new minimum viable environment.
