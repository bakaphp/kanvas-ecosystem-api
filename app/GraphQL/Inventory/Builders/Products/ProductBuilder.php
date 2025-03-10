<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
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

        return $query;
    }

    public function getProductsExport(mixed $root, array $request, GraphQLContext $contex): array
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
            Log::error('productExportError', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Error exporting products: ' . $e->getMessage());
        }
    }
}
