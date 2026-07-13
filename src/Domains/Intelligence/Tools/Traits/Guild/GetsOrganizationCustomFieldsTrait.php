<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Organizations\Models\Organization;
use Throwable;

trait GetsOrganizationCustomFieldsTrait
{
    protected function getOrganizationCustomFields(
        AppInterface $app,
        CompanyInterface $company,
        int $organizationId,
        array $keys = [],
    ): array {
        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organizationId, $company, $app);
        } catch (Throwable $e) {
            return ['error' => "Organization {$organizationId} not found: {$e->getMessage()}"];
        }

        $all = $organization->getAll();

        if (empty($keys)) {
            return [
                'organization_id' => $organizationId,
                'fields' => $all,
            ];
        }

        return [
            'organization_id' => $organizationId,
            'fields' => array_intersect_key($all, array_flip($keys)),
        ];
    }
}
