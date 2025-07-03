<?php

namespace App\GraphQL\Directives;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class SearchCacheDirective extends BaseDirective implements FieldMiddleware
{
    #[Override]
    public static function definition(): string
    {
        return /** @lang GraphQL */ '
            """
            Add caching to search operations
            """
            directive @searchCache(
                """
                Cache duration in minutes
                """
                ttl: Int = 60
                
                """
                Cache key prefix
                """
                prefix: String = "search"
            ) on FIELD_DEFINITION
        ';
    }

    #[Override]
    public function handleField(FieldValue $fieldValue): void
    {
        $app = app(Apps::class);

        $fieldValue->wrapResolver(fn (callable $resolver) => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $info) use ($resolver, $app): mixed {
            if (! isset($args['search']) || $args['search'] === null || trim($args['search']) === '') {
                return $resolver($root, $args, $context, $info);
            }

            if ($app->get(AppEnums::CACHE_SEARCH->getValue())) {
                $key = $this->getSearchKey($app, $args['search'], $args['first'] ?? 10, $args['page'] ?? 1);
                $seconds = (int)$app->get(AppEnums::CACHE_SEARCH_TTL->getValue(), 60);

                return Cache::remember($key, $seconds, fn () => $resolver($root, $args, $context, $info));
            }

            return $resolver($root, $args, $context, $info);
        });
    }

    public function getKey(Apps $app, string $search): string
    {
        return md5($search . ':' . $app->getId());
    }

    /**
     * Generate a cache key with search, first, and page parameters (MD5 hashed)
     */
    public function getSearchKey(Apps $app, string $search, int $first = 10, int $page = 1): string
    {
        $prefix = $this->directiveArgValue('prefix', 'search');
        $rawKey = "{$prefix}:{$search}:{$first}:{$page}:" . $app->getId();

        return md5($rawKey);
    }

    /**
     * Validate if a search cache key exists
     */
    public function validateSearchCacheExists(Apps $app, string $search, int $first = 10, int $page = 1): bool
    {
        $key = $this->getSearchKey($app, $search, $first, $page);

        return Cache::has($key);
    }

    /**
     * Get cache key and validate existence, returns array with key and exists status
     *
     * @return array{key: string, exists: bool}
     */
    public function getCacheKeyWithValidation(Apps $app, string $search, int $first = 10, int $page = 1): array
    {
        $key = $this->getSearchKey($app, $search, $first, $page);

        return [
            'key' => $key,
            'exists' => Cache::has($key),
        ];
    }
}
