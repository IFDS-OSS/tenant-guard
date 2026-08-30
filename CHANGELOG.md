# Changelog

All notable changes to `ifds/tenant-guard` are documented here.

## Unreleased

- **Laravel 13 support.** `illuminate/*` constraints widened to `^10.0|^11.0|^12.0|^13.0`; dev
  dependencies widened to `orchestra/testbench ^11.0` and `phpunit/phpunit ^13.0`. Verified against
  Laravel 13.29 + Testbench 11.2 under PHP 8.3 — full 220-test suite green, plus a live workbench
  run (migrate, seed, `tenant-guard:audit`, `tenant-guard:list`, and cross-tenant HTTP requests).
- **Fix:** `TenantTableRegistry::registerModel()` no longer instantiates the model it is
  registering. Laravel 13 throws a `LogicException` when a model is constructed from inside its
  own `boot()` call, which is exactly where `BelongsToTenant::bootBelongsToTenant()` runs.
  Registration now queues the class name and resolves its table lazily on first read, once the
  model has finished booting. No behavior change on Laravel 10–12.

## 1.0.0

Initial release.

- **Layer 1** `TenantScope` global query scope with `withoutTenantScope()`, `forTenant()` and `allTenants()` escape hatches.
- **Layer 2** Write guard on `creating` / `saving` / `updating` / `deleting`: auto-fill, cross-tenant refusal, immutable tenant key.
- **Layer 3** `SqlSentinel` on `Connection::beforeExecuting()`, catching `DB::table()` and raw SQL before execution. Modes: `off` / `log` / `throw`.
- **Layer 4** Tenant propagation through the queue, plus an opt-in per-tenant cache prefix and an explicit `TenantGuard::cache()` namespace.
- **Layer 5** `tenant-guard:audit` schema/model drift detector, and the `TenantOwned` / `UniqueForTenant` validation rules.
- Resolvers: subdomain, domain, header, path, authenticated user, closure, and a chain that combines them.
- Middleware: `tenant`, `tenant.required`, `tenant.central`.
- Interoperability with `spatie/laravel-multitenancy` and `stancl/tenancy`, and with any tenant model via `ForeignTenant`.
- Test helpers via `InteractsWithTenancy`.
