<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
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
        // @todo Implement product recommendations logic
        return Products::where('id', '!=', $args['id']);
    }
}
