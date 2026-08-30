<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ifds\TenantGuard\Concerns\BelongsToTenant;
use Workbench\Database\Factories\PostFactory;

/**
 * The workhorse of the test suite: tenant-owned, soft-deleting, with relations
 * in both directions.
 */
class Post extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
