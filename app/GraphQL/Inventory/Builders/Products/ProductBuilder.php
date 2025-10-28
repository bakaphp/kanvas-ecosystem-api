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
        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }
        $query = Products::query();

        if (! empty($args['variantAttributeValue'])) {
            $query->filterByVariantAttributeValue($args['variantAttributeValue']);
        }

        if (! empty($args['variantAttributeOrderBy'])) {
            $order = $args['variantAttributeOrderBy'];
            $query->orderByVariantAttribute(
                $order['name'],
                $order['format'],
                $order['sort']
            );
        }

        if (! empty($args['attributeOrderBy']) && empty($args['variantAttributeOrderBy'])) {
            $order = $args['attributeOrderBy'];
            $query->orderByAttribute(
                $order['name'],
                $order['format'],
                $order['sort']
            );
        }

        if (! empty($args['nearByLocation'])) {
            $query->filterByNearLocation($args['nearByLocation']);
        }

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
        $scenario = $args['scenario'] ?? ConfigurationEnum::FOR_YOU_SCENARIO->value;
        $limit = $args['first'] ?? 25;

        // Return all products if Recombee is not configured
        if ($app->get(ConfigurationEnum::RECOMBEE_DATABASE->value) === null) {
            return Products::fromApp($app)->fromCompany($company)->where('id', '!=', $productId);
        }

        // Get recommendations based on intent
        if ($intent === 'user') {
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

            $itemRecommendationService = new RecombeeItemRecommendationService(
                $app,
            );

            $recommendations = $itemRecommendationService->getItemRecommendation(
                $user,
                $product,
                count: $limit,
                scenario: $scenario
            );
        }

        // Extract product IDs from recommendations and look them up in database
        $recommendedIds = collect($recommendations['recomms'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($recommendedIds)) {
            return Products::fromApp($app)->fromCompany($company)->whereIn('id', [0]);
        }

        return Products::fromApp($app)->fromCompany($company)->whereIn('id', $recommendedIds)
            ->orderByRaw('FIELD(id, ' . implode(',', $recommendedIds) . ')');
    }
}
