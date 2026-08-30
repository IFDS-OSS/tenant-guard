<?php

namespace Ifds\TenantGuard\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Ifds\TenantGuard\Contracts\Tenant as TenantContract;

/**
 * The default tenant model.
 *
 * Replace it with your own via config('tenant-guard.tenant_model'); the only
 * requirement is that it implements the Tenant contract.
 *
 * @property int|string $id
 * @property string $name
 * @property string $slug
 * @property string|null $domain
 * @property array $data
 */
class Tenant extends Model implements TenantContract
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('tenant-guard.tenants_table', 'tenants');
    }

    public function getTenantKey(): int|string
    {
        return $this->getKey();
    }

    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Arbitrary per-tenant settings, stored in the JSON `data` column.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->data ?? [], $key, $default);
    }

    protected function subdomain(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->slug);
    }
}
