<?php

namespace app\service\plugin;

class DependencyResolver
{
    /**
     * @param array<string, PluginMetadata> $metas
     * @param array<int, string> $enabledSlugs
     *
     * @return array<int, string> sorted slugs
     */
    public function resolve(array $metas, array $enabledSlugs): array
    {
        $enabledSlugs = array_values(array_unique($enabledSlugs));
        // Build adjacency and in-degree
        $graph = [];
        $in = [];
        foreach ($enabledSlugs as $s) {
            $graph[$s] = [];
            $in[$s] = 0;
        }
        foreach ($enabledSlugs as $s) {
            $meta = $metas[$s] ?? null;
            if (!$meta) {
                continue;
            }
            foreach ($meta->dependencies as $dep => $constraint) {
                if (!in_array($dep, $enabledSlugs, true)) {
                    throw new PluginDependencyException("Plugin {$s} requires {$dep} {$constraint}, but it is not enabled");
                }
                $depMeta = $metas[$dep] ?? null;
                if (!$depMeta) {
                    throw new PluginDependencyException("Missing metadata for dependency {$dep} of {$s}");
                }
                if ($depMeta->version !== '' && (string) $constraint !== '' && !Semver::satisfies($depMeta->version, (string) $constraint)) {
                    throw new PluginDependencyException("{$s} requires {$dep} {$constraint}, installed {$depMeta->version}");
                }
                $graph[$dep][] = $s; // edge dep -> s
                $in[$s]++;
            }
        }
        // Kahn
        $queue = [];
        foreach ($in as $node => $deg) {
            if ($deg === 0) {
                $queue[] = $node;
            }
        }
        $order = [];
        while ($queue) {
            $n = array_shift($queue);
            $order[] = $n;
            foreach ($graph[$n] ?? [] as $m) {
                $in[$m]--;
                if ($in[$m] === 0) {
                    $queue[] = $m;
                }
            }
        }
        if (count($order) !== count($enabledSlugs)) {
            throw new PluginDependencyException('Cyclic plugin dependencies detected');
        }

        return $order;
    }
}
