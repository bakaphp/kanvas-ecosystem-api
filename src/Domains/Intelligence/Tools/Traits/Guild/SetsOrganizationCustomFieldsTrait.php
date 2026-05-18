<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Organizations\Models\Organization;
use Throwable;

trait SetsOrganizationCustomFieldsTrait
{
    protected function setOrganizationCustomFields(
        AppInterface $app,
        CompanyInterface $company,
        int $organizationId,
        array $fields,
    ): array {
        if (empty($fields)) {
            return ['error' => 'No fields provided. Pass a "fields" array with key/value pairs.'];
        }

        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organizationId, $company, $app);
        } catch (Throwable $e) {
            return ['error' => "Organization {$organizationId} not found: {$e->getMessage()}"];
        }

        foreach ($fields as $key => $value) {
            $organization->set((string) $key, $value);
        }

        return [
            'success' => true,
            'organization_id' => $organizationId,
            'fields_set' => count($fields),
        ];
    }
}
