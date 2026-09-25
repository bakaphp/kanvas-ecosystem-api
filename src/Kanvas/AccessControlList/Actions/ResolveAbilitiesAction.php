<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\AccessControlList\Models\Ability;

/**
 * Resolve many (name, entity_type) pairs to abilities in two queries instead of two per pair.
 *
 * Bouncer resolves one ability at a time — `ability()->firstOrCreate()` per pair, `allow()->to()`
 * per grant — which is ~5 queries each. At 186 pairs per role that is the bulk of what app creation
 * spends its time on. Ability rows are scoped, and `Ability::query()` is already filtered to the
 * current Bouncer scope, so the lookup only has to match on name and entity type.
 *
 * The insert goes through the query builder, so Bouncer's `creating` hook does not run and `scope`
 * has to be written by hand.
 */
class ResolveAbilitiesAction
{
    /**
     * @param array<int, array{0: string, 1: string|null, 2?: string|null}> $pairs [name, entityType, title]
     *
     * @return Collection<string, Ability> keyed by self::key()
     */
    public function execute(array $pairs): Collection
    {
        $wanted = [];

        foreach ($pairs as $pair) {
            [$name, $entityType] = $pair;
            $wanted[self::key($name, $entityType)] = [$name, $entityType, $pair[2] ?? ucfirst($name)];
        }

        if (empty($wanted)) {
            return new Collection();
        }

        $abilities = $this->fetch($wanted);
        $missing = array_diff_key($wanted, $abilities->all());

        if (empty($missing)) {
            return $abilities;
        }

        $now = Carbon::now();
        $scope = Bouncer::scope()->get();

        Ability::query()->insert(array_map(
            static fn (array $pair): array => [
                'name' => $pair[0],
                'title' => $pair[2],
                'entity_type' => $pair[1],
                'scope' => $scope,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_values($missing)
        ));

        return $this->fetch($wanted);
    }

    /**
     * @param array<string, array{0: string, 1: string|null, 2: string}> $wanted
     *
     * @return Collection<string, Ability>
     */
    protected function fetch(array $wanted): Collection
    {
        $names = array_values(array_unique(array_column($wanted, 0)));
        $found = new Collection();

        // Ordered and first-wins so a scope that already holds duplicate (name, entity_type) rows
        // resolves to the same one Bouncer's firstOrCreate would have picked.
        foreach (Ability::query()->whereIn('name', $names)->orderBy('id')->get() as $ability) {
            $key = self::key($ability->name, $ability->entity_type);

            if (isset($wanted[$key]) && ! $found->has($key)) {
                $found->put($key, $ability);
            }
        }

        return $found;
    }

    public static function key(string $name, ?string $entityType): string
    {
        return $name . "\0" . ($entityType ?? '');
    }
}
