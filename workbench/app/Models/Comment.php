<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ifds\TenantGuard\Concerns\BelongsToTenant;
use Workbench\Database\Factories\CommentFactory;

class Comment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $guarded = [];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }
}
