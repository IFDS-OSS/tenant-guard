<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Ifds\TenantGuard\Facades\TenantGuard;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Plan;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Two tenants with overlapping data. Any leak shows up as a count mismatch.
 *
 * Idempotent, because testbench can invoke it both from testbench.yaml and
 * from `migrate --seed` in the same process.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['free', 0], ['pro', 4900]] as [$name, $price]) {
            Plan::firstOrCreate(['name' => $name], ['price' => $price]);
        }

        $this->seedTenant('acme', 'acme.example.com', posts: 3);
        $this->seedTenant('globex', 'globex.example.com', posts: 5);
    }

    protected function seedTenant(string $slug, string $domain, int $posts): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => str($slug)->headline()->toString(), 'domain' => $domain, 'data' => []]
        );

        TenantGuard::runFor($tenant, function () use ($posts) {
            if (Post::query()->exists()) {
                return;
            }

            $author = User::factory()->create();

            Post::factory()
                ->count($posts)
                ->for($author, 'author')
                ->create()
                ->each(fn (Post $post) => Comment::factory()->count(2)->create(['post_id' => $post->id]));
        });
    }
}
