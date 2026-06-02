# Vault package targets Laravel 9+ on PHP 8.2; no Lumen support

**Context:** 30+ IBID services need centralized HashiCorp Vault secret management. ~5–8 services run PHP 7.4 and some run Lumen, but all Lumen apps are being migrated to Laravel 10 / PHP 8.3 with high confidence.

**Decision:** The package targets **Laravel 9, 10, 11 on PHP 8.2+ only**. No first-class Lumen support. Lumen/PHP-7.4 services are excluded from v1; Lumen services still in flight may use a temporary manual bootstrap shim that self-deletes once they migrate.

**Why:** Full Lumen support would require a parallel bootstrap path (Lumen's `Laravel\Lumen\Application` has no `afterBootstrapping` hook or bootstrapper pipeline) for a platform being actively deprecated — throwaway effort. Dragging 28 modern services down to a PHP 7.4 lowest-common-denominator would mean deliberately worse code across the whole package. Lowering the floor later is non-breaking; raising it is not — so we start conservative.
