<?php

namespace Ifds\TenantGuard\Console\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

trait DiscoversModels
{
    /**
     * Every Eloquent model found under the configured paths.
     *
     * @param  list<string>  $paths
     * @return list<class-string<Model>>
     */
    protected function discoverModels(array $paths): array
    {
        $existing = array_values(array_filter($paths, 'is_dir'));

        if ($existing === []) {
            return [];
        }

        $models = [];

        foreach (Finder::create()->files()->name('*.php')->in($existing) as $file) {
            $class = $this->classFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * Read the fully-qualified class name straight out of the file, so we do not
     * have to guess at PSR-4 root namespaces.
     */
    protected function classFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^\s*namespace\s+([^;{\s]+)/m', $contents, $namespace)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $class)) {
            return null;
        }

        return $namespace[1].'\\'.$class[1];
    }

    /**
     * @param  class-string  $class
     */
    protected function usesTenantTrait(string $class): bool
    {
        return in_array(
            \Ifds\TenantGuard\Concerns\BelongsToTenant::class,
            $this->allTraits($class),
            true
        );
    }

    /** @return list<string> */
    protected function allTraits(string $class): array
    {
        $traits = [];

        foreach (array_merge([$class], class_parents($class) ?: []) as $parent) {
            foreach (class_uses($parent) ?: [] as $trait) {
                $traits[] = $trait;
                $traits = array_merge($traits, $this->allTraits($trait));
            }
        }

        return array_values(array_unique($traits));
    }
}
