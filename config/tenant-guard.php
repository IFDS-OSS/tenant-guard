<?php

use Ifds\TenantGuard\Cache\CacheBootstrapper;
use Ifds\TenantGuard\Models\Tenant;
use Ifds\TenantGuard\Resolvers\DomainResolver;
use Ifds\TenantGuard\Resolvers\HeaderResolver;
use Ifds\TenantGuard\Resolvers\PathResolver;
use Ifds\TenantGuard\Resolvers\SubdomainResolver;
use Ifds\TenantGuard\Resolvers\UserResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model representing a tenant. It must implement the
    | Ifds\TenantGuard\Contracts\Tenant contract. Swap this for your own
    | model once you need extra columns (plan, status, settings, ...).
    |
    */

    'tenant_model' => Tenant::class,

    /*
    |--------------------------------------------------------------------------
    | Tenant foreign key
    |--------------------------------------------------------------------------
    |
    | The discriminator column present on every tenant-owned table. This is the
    | column the query scope filters on and the write guard protects.
    |
    */

    'tenant_key' => 'tenant_id',

    /*
    |--------------------------------------------------------------------------
    | Tenants table
    |--------------------------------------------------------------------------
    */

    'tenants_table' => 'tenants',

    /*
    |--------------------------------------------------------------------------
    | Behaviour when there is no tenant context
    |--------------------------------------------------------------------------
    |
    | The most important setting in this file. When a tenant-owned model is
    | queried and no tenant has been resolved:
    |
    |   "throw"  - raise MissingTenantContextException (default, safest)
    |   "empty"  - return zero rows (use when central routes touch tenant models)
    |   "ignore" - do not scope at all, log a warning (migration period ONLY)
    |
    | Writes never honour "ignore": creating a tenant-owned row without a tenant
    | id produces a row no tenant can ever read or delete, so it always throws.
    |
    */

    'missing_context' => env('TENANT_GUARD_MISSING_CONTEXT', 'throw'),

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    |
    | Resolvers are tried in order by the ChainResolver; the first one to return
    | a tenant wins. Remove the ones you do not use - each is a lookup.
    |
    */

    'resolvers' => [
        SubdomainResolver::class,
        DomainResolver::class,
        HeaderResolver::class,
        PathResolver::class,
        UserResolver::class,
    ],

    'resolution' => [
        // Hostnames that are never a tenant subdomain (marketing site, admin panel).
        'central_domains' => [
            // 'app.test',
            // 'admin.app.test',
        ],

        // Subdomains ignored by the SubdomainResolver.
        'ignored_subdomains' => ['www', 'admin', 'api', 'mail'],

        // Header inspected by the HeaderResolver.
        'header' => 'X-Tenant',

        // Route parameter / first path segment used by the PathResolver.
        'route_parameter' => 'tenant',
        'path_segment' => null, // e.g. 1 to read /{tenant}/dashboard

        // Column on the tenant model used by the UserResolver's owner lookup.
        'user_attribute' => 'tenant_id',

        // Columns on the tenant model used for host-based lookups.
        'domain_column' => 'domain',
        'subdomain_column' => 'slug',

        // Throw TenantNotFoundException when no resolver matches.
        'required' => false,

        // Render an unresolvable tenant as a 404 rather than a 500.
        'abort_404' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant lookup cache
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => true,
        'store' => null,      // null = default store
        'ttl' => 300,         // seconds
        'prefix' => 'tenant-guard',
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Sentinel (layer 3)
    |--------------------------------------------------------------------------
    |
    | Inspects every query before it reaches the database and reports any query
    | touching a tenant-owned table without a tenant predicate. This catches
    | DB::table() and raw SQL, which Eloquent scopes cannot see.
    |
    |   "off"   - disabled
    |   "log"   - write violations to the configured channel
    |   "throw" - block the query (recommended for CI and staging)
    |
    */

    'sentinel' => [
        'mode' => env('TENANT_GUARD_SENTINEL', 'off'),

        'log_channel' => null,

        // Tables the sentinel protects. Leave empty to derive them from the
        // models registered via the BelongsToTenant trait at boot.
        'tenant_tables' => [],

        // Tables that are explicitly shared and never need a tenant predicate.
        'central_tables' => [
            'tenants',
            'migrations',
            'password_reset_tokens',
            'password_resets',
            'failed_jobs',
            'jobs',
            'job_batches',
            'sessions',
            'cache',
            'cache_locks',
        ],

        // Queries matching any of these patterns are skipped entirely.
        'ignore_patterns' => [
            '/^\s*(pragma|set|begin|commit|rollback|savepoint|release)\b/i',
            '/^\s*(create|alter|drop|truncate)\b/i',
            '/\bsqlite_master\b|\binformation_schema\b|\bpg_catalog\b/i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue propagation (layer 4)
    |--------------------------------------------------------------------------
    |
    | Stamp the current tenant into every queued payload and restore it in the
    | worker, so background work never runs without a tenant context.
    |
    */

    'queue' => [
        'propagate' => true,
        'payload_key' => 'tenant_guard_tenant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrappers
    |--------------------------------------------------------------------------
    |
    | Run whenever the tenant context changes. Each maps to a boolean so you can
    | switch individual pieces of tenant isolation on and off.
    |
    */

    'bootstrappers' => [
        CacheBootstrapper::class => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interoperability
    |--------------------------------------------------------------------------
    |
    | Tenant Guard is a row-level guard, not a connection switcher, so it layers
    | on top of spatie/laravel-multitenancy and stancl/tenancy rather than
    | competing with them. When either package is installed, Tenant Guard
    | follows its tenant-switch events automatically.
    |
    | Both sections are no-ops when the package in question is absent.
    |
    */

    'interop' => [
        'enabled' => true,

        'spatie' => [
            // Column on Spatie's tenant model to use as the tenant key.
            'key_name' => null, // null = the model's primary key
        ],

        'stancl' => [
            // stancl tenants are usually keyed by `id` (a UUID string).
            'key_name' => null, // null = ask the model via getTenantKeyName()
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit command
    |--------------------------------------------------------------------------
    */

    'audit' => [
        // Directories scanned for Eloquent models.
        'model_paths' => [
            'app/Models',
        ],

        // Tables the audit should not flag as unclassified.
        'ignored_tables' => [
            'migrations',
            'password_reset_tokens',
            'password_resets',
            'failed_jobs',
            'jobs',
            'job_batches',
            'sessions',
            'cache',
            'cache_locks',
            'personal_access_tokens',
        ],

        // Warn when a tenant-owned table has no index leading with the tenant key.
        'check_indexes' => true,
    ],

];
