<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Redis;

/**
 * Drop the dangling references that pile up in the model cache's tag index.
 *
 * laravel-model-caching tags every cached query with its model, and a write flushes the whole tag.
 * That flush deletes the cached values but does not reclaim their entries from the tag's sorted set,
 * so the index grows without bound — measured here at +38 entries per app creation, 23k entries
 * after a few hundred. Since a flush costs one DEL and one ZREM per entry, every write to a cached
 * model gets slower as the index grows: 0.2ms against a clean index, 112ms against a 23k one.
 *
 * Laravel's own `cache:prune-stale-tags` cannot help: model-caching writes its entries with no TTL,
 * which Laravel scores -1, and that command prunes by `ZREMRANGEBYSCORE 0 <now>`.
 *
 * This prunes on liveness instead of score — an entry whose cache key no longer exists is garbage.
 * Live entries are left alone, so the cache itself is not cold afterwards.
 */
class PruneModelCacheTagsCommand extends Command
{
    protected $signature = 'kanvas:cache:prune-model-cache-tags {--dry-run : Report what would be pruned without removing anything}';

    protected $description = 'Remove dangling entries from the model query cache tag index';

    public function handle(): int
    {
        $store = Cache::store(config('laravel-model-caching.store') ?: config('cache.default'))->getStore();

        if (! method_exists($store, 'connection')) {
            $this->warn('The model cache store is not Redis backed; nothing to prune.');

            return self::SUCCESS;
        }

        $client = $store->connection()->client();
        $clientPrefix = (string) $client->getOption(Redis::OPT_PREFIX);
        $storePrefix = $store->getPrefix();
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $pruned = 0;

        foreach ($this->tagSets($client, $clientPrefix . $storePrefix) as $tagSet) {
            // SCAN hands back the fully prefixed name; phpredis prefixes again on every command,
            // so strip the client prefix before using it as a key.
            $tagSet = str_starts_with($tagSet, $clientPrefix) ? substr($tagSet, strlen($clientPrefix)) : $tagSet;
            $members = $client->zRange($tagSet, 0, -1);

            if (empty($members)) {
                continue;
            }

            $scanned += count($members);
            $dead = $this->danglingMembers($client, $storePrefix, $members);

            if (empty($dead)) {
                continue;
            }

            $pruned += count($dead);

            if (! $dryRun) {
                foreach (array_chunk($dead, 500) as $chunk) {
                    $client->zRem($tagSet, ...$chunk);
                }
            }
        }

        $this->info(sprintf(
            '%s %d dangling of %d tag entries.',
            $dryRun ? 'Would prune' : 'Pruned',
            $pruned,
            $scanned
        ));

        return self::SUCCESS;
    }

    /**
     * The entries whose cache key is already gone. One EXISTS per member would be a round trip per
     * member — tens of thousands of them on a neglected index — so they go out in a pipeline.
     *
     * @param array<int, string> $members
     *
     * @return array<int, string>
     */
    protected function danglingMembers(Redis $client, string $storePrefix, array $members): array
    {
        $dead = [];

        foreach (array_chunk($members, 500) as $chunk) {
            $pipe = $client->multi(Redis::PIPELINE);

            foreach ($chunk as $member) {
                $pipe->exists($storePrefix . $member);
            }

            foreach ($pipe->exec() as $index => $exists) {
                if (! $exists) {
                    $dead[] = $chunk[$index];
                }
            }
        }

        return $dead;
    }

    /**
     * SCAN rather than KEYS so a large keyspace does not block Redis. The pattern has to carry the
     * client prefix because phpredis does not apply it to SCAN patterns, only to keys.
     *
     * @return iterable<string>
     */
    protected function tagSets(Redis $client, string $keyPrefix): iterable
    {
        $cursor = null;

        do {
            $keys = $client->scan($cursor, $keyPrefix . 'tag:*:entries', 500);

            if ($keys === false) {
                continue;
            }

            foreach ($keys as $key) {
                yield $key;
            }
        } while ($cursor > 0);
    }
}
