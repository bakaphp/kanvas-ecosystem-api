<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\PasoRapido\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PasoRapidoManagementQuery
{
    public function getTagBalance(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $tag = $args['tag'];
        $app = app(Apps::class);
        $companiesId = auth()->user()->currentCompanyId();
        $company = Companies::find($companiesId);

        $pasoRapidoService = new PasoRapidoService($app, $company);
        try {
            $customer   = $pasoRapidoService->verifyCustomer($tag);
            return [
                'message' => 'success',
                "data" => $customer->toArray(),
            ];
        } catch (\Exception $e) {
            return [
                'message' => $e->getMessage(),
                "data" => [],
            ];
        }
    }
}
