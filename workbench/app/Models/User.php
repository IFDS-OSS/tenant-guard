<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Ifds\TenantGuard\Concerns\BelongsToTenant;
use Workbench\Database\Factories\UserFactory;

/**
 * Tenant-owned, and also the model the UserResolver reads the tenant from.
 */
class User extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['password'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
