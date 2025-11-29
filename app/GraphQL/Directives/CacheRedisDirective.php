<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use GraphQL\Deferred;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Nuwave\Lighthouse\Cache\CacheDirective as CacheCacheDirective;
use Nuwave\Lighthouse\Cache\CacheKeyAndTagsGenerator;
use Nuwave\Lighthouse\Execution\Resolved;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class CacheRedisDirective extends CacheCacheDirective
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Cache the result of a resolver.

Place this after other field middleware to ensure it caches the correct transformed value.
"""
directive @cacheRedis(
  """
  Set the duration it takes for the cache to expire in seconds.
  If not given, the result will be stored forever.
  """
  maxAge: Int

  """
  Limit access to cached data to the currently authenticated user.
  When the field is accessible by guest users, this will not have
  any effect, they will access a shared cache.
  """
  private: Boolean = false
) on FIELD_DEFINITION
GRAPHQL;
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $rootCacheKey = $fieldValue->getParent()->cacheKey();
        $shouldUseTags = $this->shouldUseTags();
        $maxAge = $this->directiveArgValue('maxAge');
        $isPrivate = $this->directiveArgValue('private', false);

        $fieldValue->wrapResolver(fn (callable $resolver): \Closure => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($rootCacheKey, $shouldUseTags, $resolver, $maxAge, $isPrivate) {
            $parentName = $resolveInfo->parentType->name;
            $rootID = $root !== null && $rootCacheKey !== null
                ? data_get($root, $rootCacheKey)
                : null;
            $fieldName = $resolveInfo->fieldName;
            $path = $resolveInfo->path;

            $cache = Redis::connection('graph-cache');

            $cacheKey = $this->cacheKeyAndTags->key(
                $context->user(),
                $isPrivate,
                $parentName,
                $rootID,
                $fieldName,
                $args,
                $path,
            );

            // We found a matching value in the cache, so we can just return early without actually running the query.
            // Split into Redis Hash parts
            [$hashKey, $hashField] = $this->extractHashParts($cacheKey, $parentName, $rootID);
            $value = $cache->hGet($hashKey, $hashField);

            if ($value !== false) {
                return new Deferred(static fn () => $value);
            }

            // In Laravel cache, null is considered a non-existent value, see https://laravel.com/docs/9.x/cache#checking-for-item-existence:
            // > The `has` method [...] will also return false if the item exists but its value is null.
            //
            // If caching `null` value becomes something worthwhile, one possible way to achieve it is to
            // encapsulate the `$result` at writing time :
            //
            //    $storeInCache = static function ($result) use ($cacheKey, $maxAge, $cache): void {
            //        $value = ['rawValue' => $result];
            //        $maxAge
            //            ? $cache->put($cacheKey, $value, Carbon::now()->addSeconds($maxAge))
            //            : $cache->forever($cacheKey, $value);
            //    };
            //
            // and restoring original value back at reading :
            //
            //    if (is_array($value) && array_key_exists('rawValue', $value)) { // don't use isset !
            //        return $value['rawValue'];
            //    }
            //
            // Such a change would introduce some potential BC, if for instance cached value was already containing
            // an object with a `rawValue` key prior the implementation change. A possible workaround is to choose a
            // less collision-probable key instead of `rawValue` (e.g. "lighthouse:rawValue").

            $resolved = $resolver($root, $args, $context, $resolveInfo);

            $storeInCache = static function ($result) use ($cache, $hashKey, $hashField, $maxAge): void {
                $cache->hSet($hashKey, $hashField, $result);
                if ($maxAge) {
                    $cache->expire($hashKey, $maxAge);
                }
            };

            Resolved::handle($resolved, $storeInCache);

            return $resolved;
        });
    }

    /**
     * Split lighthouse key into:
     * - Redis HASH Key
     * - Redis HASH FIELD
     */
    private function extractHashParts(string $fullKey, string $parentName, string|int|null $rootID): array
    {
        // HASH KEY: kanvas_ecosystem_database_lighthouse:Variant:310478
        $hashKey = CacheKeyAndTagsGenerator::PREFIX . ":{$parentName}:{$rootID}";

        // HASH FIELD = everything unique about the field
        // Example: "files:first:25", "tags:limit=10"
        $hashField = substr($fullKey, strlen($hashKey) + 1) ?: $fullKey;

        // Clean up edge cases
        $hashField = trim($hashField, ':');

        return [$hashKey, $hashField];
    }
}
