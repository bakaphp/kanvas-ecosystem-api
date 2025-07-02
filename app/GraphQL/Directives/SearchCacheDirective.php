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

class SearchCacheDirective extends BaseDirective implements FieldMiddleware
{
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

    public function handleField(FieldValue $fieldValue): void
    {
        $app = app(Apps::class);

        $fieldValue->wrapResolver(fn (callable $resolver) => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $info) use ($resolver, $app): mixed {
            if (! isset($args['search']) || $args['search'] === null || trim($args['search']) === '') {
                return $resolver($root, $args, $context, $info);
            }
            if ($app->get(AppEnums::CACHE_SEARCH->getValue())) {
                $key = $this->getKey($app, $args['search']);
                $seconds = (int)$app->get(AppEnums::CACHE_SEARCH_TTL->getValue(), 60);

                return Cache::remember($key, $seconds, fn () => $resolver($root, $args, $context, $info));
            }

            return $resolver($root, $args, $context, $info);
        });
    }

    public function getKey(Apps $app, string $search): string
    {
        return $search . ':' . $app->getId();
    }
}
