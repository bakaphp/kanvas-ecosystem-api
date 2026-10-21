<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Recommendations\Actions\EnableProductDiscoveryAction;

class ProductDiscoverySetupMutation
{
    /**
     * @param array{input: array{scope: string, company_id?: mixed, enrichment?: ?bool, discovery?: ?bool}} $request
     */
    public function enable(mixed $rootValue, array $request): array
    {
        $input = $request['input'];
        $app = app(Apps::class);
        $company = null;

        if ($input['scope'] === 'COMPANY') {
            if (empty($input['company_id'])) {
                throw new ValidationException('company_id is required when scope is COMPANY.');
            }

            $company = EnableProductDiscoveryAction::companyFor($app, (int) $input['company_id']);
        } elseif (! empty($input['company_id'])) {
            throw new ValidationException('company_id is only valid when scope is COMPANY.');
        }

        $result = new EnableProductDiscoveryAction(
            app: $app,
            company: $company,
            enrichment: $input['enrichment'] ?? null,
            discovery: $input['discovery'] ?? null,
        )->execute();

        return [...$result, 'scope' => $input['scope']];
    }
}
