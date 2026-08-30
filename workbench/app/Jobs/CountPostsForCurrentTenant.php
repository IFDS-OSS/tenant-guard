<?php

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Ifds\TenantGuard\Facades\TenantGuard;
use Workbench\App\Models\Post;

/**
 * Counts posts using no explicit tenant filter at all. If the tenant did not
 * survive the trip through the queue, this job either throws or counts the
 * wrong rows - which is precisely what the test asserts against.
 */
class CountPostsForCurrentTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $resultKey = 'job-result')
    {
    }

    public function handle(): void
    {
        Cache::forever($this->resultKey, [
            'tenant' => TenantGuard::id(),
            'posts' => Post::count(),
        ]);
    }
}
