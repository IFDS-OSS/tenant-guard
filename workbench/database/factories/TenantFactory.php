<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Tenant;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => str($name)->slug()->append('-'.fake()->unique()->numberBetween(1, 99999))->toString(),
            'domain' => null,
            'data' => [],
        ];
    }

    public function named(string $slug, ?string $domain = null): static
    {
        return $this->state(fn () => [
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'domain' => $domain,
        ]);
    }
}
