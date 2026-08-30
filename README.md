# Tenant Guard

**Defence-in-depth multi-tenancy for Laravel applications that share one database and one schema.**

[![Tests](https://github.com/IFDS-OSS/tenant-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/IFDS-OSS/tenant-guard/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/ifds-oss/tenant-guard.svg?style=flat-square)](https://packagist.org/packages/ifds-oss/tenant-guard)
[![Total Downloads](https://img.shields.io/packagist/dt/ifds-oss/tenant-guard.svg?style=flat-square)](https://packagist.org/packages/ifds-oss/tenant-guard)
[![Laravel 10 · 11 · 12 · 13](https://img.shields.io/badge/Laravel-10%20%C2%B7%2011%20%C2%B7%2012%20%C2%B7%2013-FF2D20?style=flat-square)](https://laravel.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)](https://php.net)
[![License MIT](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)

In a shared-schema SaaS, every tenant's rows live in the same tables, separated only by a
`tenant_id` column. It is the cheapest tenancy model to run and **the easiest one to leak**. One
forgotten `where()` is a cross-tenant data breach.

Tenant Guard's answer is not a single clever scope — it is **five independent layers**, each of
which fails closed on its own:

1. **Query Scope** — every Eloquent read is constrained automatically.
2. **Write Guard** — cross-tenant creates, updates and deletes are refused, and the tenant key is immutable.
3. **SQL Sentinel** — catches raw SQL and `DB::table()` calls before they execute.
4. **Propagation** — the tenant follows the request into queued jobs and, optionally, the cache.
5. **Static Audit** — a command that flags models and tables missing tenant protection.

It sits *on top of* connection switchers like `stancl/tenancy` and `spatie/laravel-multitenancy`
rather than replacing them — see the [interoperability section](USAGE.md#interoperability) of the
usage guide.

## Installation

```bash
composer require ifds-oss/tenant-guard
php artisan tenant-guard:install
php artisan migrate
```

```php
use Ifds\TenantGuard\Concerns\BelongsToTenant;

class Post extends Model
{
    use BelongsToTenant;
}
```

```php
Route::middleware('tenant')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
});
```

That is the whole integration. The controller needs no `where()` clause — `Post::all()` only ever
returns the current tenant's rows.

## Documentation

The full guide — every layer, the resolver chain, the API, events, testing helpers, gotchas and
architecture — lives in **[USAGE.md](USAGE.md)**.

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## License

MIT. See [LICENSE](LICENSE).
