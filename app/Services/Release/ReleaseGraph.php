<?php

namespace App\Services\Release;

/**
 * Dependency rules of a release group: a resource can only depend on resources of the same group
 * that run in the same or an earlier phase, and dependencies must not form a cycle.
 */
final class ReleaseGraph
{
    /**
     * @param  array<string, array{name: string, phase: int, depends_on: list<string>, enabled?: bool}>  $nodes  keyed by member uuid
     */
    public function __construct(private readonly array $nodes) {}

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->nodes as $uuid => $node) {
            if (! ($node['enabled'] ?? true)) {
                continue;
            }
            foreach ($node['depends_on'] as $dependency) {
                if ($dependency === $uuid) {
                    $errors[] = "{$node['name']} cannot depend on itself.";

                    continue;
                }
                $target = $this->nodes[$dependency] ?? null;
                if ($target === null) {
                    $errors[] = "{$node['name']} depends on a resource that is not part of this group.";

                    continue;
                }
                if (! ($target['enabled'] ?? true)) {
                    $errors[] = "{$node['name']} depends on {$target['name']}, which is disabled in this group.";
                }
                if ($target['phase'] > $node['phase']) {
                    $errors[] = "{$node['name']} runs in phase {$node['phase']} but depends on {$target['name']} in the later phase {$target['phase']}.";
                }
            }
        }

        $cycle = $this->findCycle();
        if ($cycle !== null) {
            $names = array_map(fn (string $uuid) => $this->nodes[$uuid]['name'], $cycle);
            $errors[] = 'Dependencies form a cycle: '.implode(' → ', $names).'.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Every member that directly or indirectly depends on the given one.
     *
     * @return list<string>
     */
    public function dependentsOf(string $uuid): array
    {
        $found = [];
        $pending = [$uuid];
        while ($pending !== []) {
            $current = array_pop($pending);
            foreach ($this->nodes as $candidate => $node) {
                if (isset($found[$candidate]) || ! in_array($current, $node['depends_on'], true)) {
                    continue;
                }
                $found[$candidate] = true;
                $pending[] = $candidate;
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>|null
     */
    private function findCycle(): ?array
    {
        $state = [];
        $path = [];

        $visit = function (string $uuid) use (&$visit, &$state, &$path): ?array {
            $state[$uuid] = 'visiting';
            $path[] = $uuid;
            foreach ($this->nodes[$uuid]['depends_on'] as $dependency) {
                if (! isset($this->nodes[$dependency]) || $dependency === $uuid) {
                    continue;
                }
                if (($state[$dependency] ?? null) === 'visiting') {
                    $start = array_search($dependency, $path, true);

                    return [...array_slice($path, (int) $start), $dependency];
                }
                if (! isset($state[$dependency])) {
                    $cycle = $visit($dependency);
                    if ($cycle !== null) {
                        return $cycle;
                    }
                }
            }
            array_pop($path);
            $state[$uuid] = 'done';

            return null;
        };

        foreach (array_keys($this->nodes) as $uuid) {
            if (! isset($state[$uuid])) {
                $cycle = $visit($uuid);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        return null;
    }
}
