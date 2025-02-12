<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Variants\Models\Variants;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UserCompanyApps;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Inventory\Variants\Actions\ExportVariantsAction;

class VariantBuilder
{
    public function getVariants(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if (! $user->isAppOwner()) {
            //Variants::setSearchIndex($company->getId());
        }
        /**
         * @var Builder
         */
        return Variants::query();
    }

    public function getVariantsExport(mixed $root, array $request, GraphQLContext $contex): array {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);       
        
        /**
         * @todo for now for b2b store clients
         * change this to use company group?
         */
        if ($app->get('USE_B2B_COMPANY_GROUP')) {
            if (UserCompanyApps::where('companies_id', $app->get('B2B_GLOBAL_COMPANY'))->where('apps_id', $app->getId())->first()) {
                $company = Companies::getById($app->get('B2B_GLOBAL_COMPANY'));
            }
        }
        
        try {
            $exportVariants = new ExportVariantsAction($app, $company);
            $url = $exportVariants->execute();
                
        return [
            'url' => $url,
            'message' => 'Products exported successfully',
        ];

        } catch (Exception $e) {
            Log::error('productExportError', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception('Error exporting products: ' . $e->getMessage());
        }
    }
}
