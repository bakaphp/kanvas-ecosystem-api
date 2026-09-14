<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Services\RecombeeItemRecommendationService;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Inventory\Products\Actions\ExportProductsAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Services\B2BConfigurationService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductBuilder
{
    public function getProducts(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }
        $query = Products::query();

        if (! empty($args['search'])) {
            $ids = Products::search($args['search'])->take(10000)->keys()->all();
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $ids)
                    ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')');
            }
        }

        if (! empty($args['variantAttributeValue'])) {
            $query->filterByVariantAttributeValue($args['variantAttributeValue']);
        }

        foreach ($args['attributeValues'] ?? [] as $filter) {
            $query->filterByAttributeValue(...Products::attributeFilterArgsFromInput($filter));
        }

        if (! empty($args['withAttributeSlug'])) {
            $slug = $args['withAttributeSlug'];
            $query->whereHas(
                'attributeValues',
                fn (Builder $q) => $q->where('is_deleted', 0)
                    ->whereHas('attribute', fn (Builder $a) => $a->where('slug', $slug))
            );
        }

        $variantOrder = $args['variantAttributeOrderBy'] ?? null;
        $attributeOrder = $args['attributeOrderBy'] ?? null;

        if (! empty($variantOrder['name'])) {
            $query->orderByVariantAttribute(
                $variantOrder['name'],
                $variantOrder['format'] ?? 'STRING',
                $variantOrder['sort'] ?? 'ASC'
            );
        } elseif (! empty($attributeOrder['name'])) {
            $query->orderByAttribute(
                $attributeOrder['name'],
                $attributeOrder['format'] ?? 'STRING',
                $attributeOrder['sort'] ?? 'ASC'
            );
        }

        if (! empty($args['nearByLocation'])) {
            $query->filterByNearLocation($args['nearByLocation']);
        }

        if (! empty($args['nearByWarehouseLocation'])) {
            $query->filterByNearWarehouseLocation($args['nearByWarehouseLocation']);
        }

        $roleBasedBuilder = new RoleBasedProductBuilder($user, $company, $app);
        $query = $roleBasedBuilder->applyRoleScope($query, $args);

        $regionId = ! empty($args['region_id'])
            ? (int) $args['region_id']
            : (app()->bound(Regions::class) ? app(Regions::class)->getId() : null);

        if ($regionId) {
            $query->whereHas(
                'variants',
                fn ($q) => $q->where('is_deleted', 0)->whereHas(
                    'variantWarehouses',
                    fn ($q) => $q->where('is_deleted', 0)->whereHas(
                        'warehouse',
                        fn ($q) => $q->where('regions_id', $regionId)
                            ->where('is_deleted', 0)
                    )
                )
            );
        }

        // Batch-load the visible attributes for n+1 query prevention when resolving visibleAttributesRelation
        $query->with('visibleAttributesRelation');

        return $query;
    }

    public function getProductsExport(mixed $root, array $request, GraphQLContext $context): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = B2BConfigurationService::getConfiguredB2BCompany($app, $user->getCurrentCompany());

        try {
            $exportProducts = new ExportProductsAction($app, $company);
            $url = $exportProducts->execute();

            return [
                'url' => $url,
                'message' => 'Products exported successfully',
            ];
        } catch (Exception $e) {
            report($e);

            throw new Exception('Error exporting products: ' . $e->getMessage());
        }
    }

    public function productSemanticSearch(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Collection {
        $results = Products::search($args['query'])
                    ->semantic([
                        'per_page' => 25,
                    ]);

        // @todo Improve vector distance filtering
        $hits = collect($results['hits'])
        ->filter(fn ($hit) => $hit['vector_distance'] < 0.8)
        ->pluck('document');

        $ids = $hits->pluck('id')->toArray();

        // @todo Improve
        return Products::whereIn('id', $ids)
            ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')')
            ->get();
    }

    public function getProductRecommendations(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $intent = $args['intent'] ?? 'product';
        $productId = (int) $args['id'];
        //$scenario = $args['scenario'] ?? ConfigurationEnum::FOR_YOU_SCENARIO->value;
        $scenario = $intent;
        $limit = $args['first'] ?? 25;

        // Return all products if Recombee is not configured
        if ($app->get(ConfigurationEnum::RECOMBEE_DATABASE->value) === null) {
            return Products::fromApp($app)->fromCompany($company)->where('id', '!=', $productId);
        }

        // Get recommendations based on intent
        if (in_array($intent, ['user', 'for-you-feed', 'trending'])) {
            $userRecommendationService = new RecombeeUserRecommendationService(
                $app,
            );

            $recommendations = $userRecommendationService->getUserRecommendation(
                $user,
                count: $limit,
                scenario: $scenario
            );
        } else {
            // Product-to-product recommendations (default)
            $product = Products::getById($productId, $app);

            try {
                $itemRecommendationService = new RecombeeItemRecommendationService(
                    $app,
                );

                $recommendations = $itemRecommendationService->getItemRecommendation(
                    $user,
                    $product,
                    count: $limit,
                    scenario: $scenario
                );
            } catch (Exception $e) {
                report($e);
            }
        }

        // Extract product IDs from recommendations and look them up in database
        $recommendedIds = collect($recommendations['recomms'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($recommendedIds)) {
            return Products::fromApp($app)->fromCompany($company)->where('id', '>', 0); //no empty result
        }

        return Products::fromApp($app)->fromCompany($company)->whereIn('id', $recommendedIds)
            ->orderByRaw('FIELD(id, ' . implode(',', $recommendedIds) . ')');
    }
}
