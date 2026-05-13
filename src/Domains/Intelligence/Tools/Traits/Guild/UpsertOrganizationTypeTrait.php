<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationTypeAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationTypeAction;
use Kanvas\Guild\Organizations\DataTransferObject\OrganizationType as OrganizationTypeData;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Throwable;

trait UpsertOrganizationTypeTrait
{
    protected function upsertOrganizationType(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $name,
        ?int $organizationTypeId = null,
        ?string $description = null,
        bool $isActive = true,
        bool $isDefault = false,
    ): array {
        $data = new OrganizationTypeData(
            apps: $app,
            companies: $company,
            user: $user,
            name: $name,
            description: $description,
            is_active: $isActive,
            is_default: $isDefault,
        );

        try {
            if ($organizationTypeId !== null) {
                $organizationType = OrganizationType::getByIdFromCompanyApp($organizationTypeId, $company, $app);
                $organizationType = new UpdateOrganizationTypeAction($organizationType, $data)->execute();
                $action = 'updated';
            } else {
                $organizationType = new CreateOrganizationTypeAction($data)->execute();
                $action = 'created';
            }
        } catch (Throwable $e) {
            return ['error' => "Failed to upsert organization type: {$e->getMessage()}"];
        }

        return [
            'organization_type_id' => $organizationType->getId(),
            'name' => $organizationType->name,
            'slug' => $organizationType->slug,
            'is_active' => (bool) $organizationType->is_active,
            'action' => $action,
        ];
    }
}
