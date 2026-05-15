<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationTypeAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationTypeAction;
use Kanvas\Guild\Organizations\DataTransferObject\OrganizationType as OrganizationTypeData;
use Kanvas\Guild\Organizations\Models\OrganizationType;

class OrganizationTypeManagementMutation
{
    public function create(mixed $root, array $req): OrganizationType
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $req['input'];

        return new CreateOrganizationTypeAction(
            new OrganizationTypeData(
                apps: $app,
                companies: $company,
                user: auth()->user(),
                name: $input['name'],
                description: $input['description'] ?? null,
                is_active: $input['is_active'] ?? true,
                is_default: $input['is_default'] ?? false,
                config: $input['config'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $root, array $req): OrganizationType
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $input = $req['input'];

        /** @var OrganizationType $organizationType */
        $organizationType = OrganizationType::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return new UpdateOrganizationTypeAction(
            $organizationType,
            new OrganizationTypeData(
                apps: $app,
                companies: $company,
                user: auth()->user(),
                name: $input['name'] ?? $organizationType->name,
                description: $input['description'] ?? $organizationType->description,
                is_active: (bool) ($input['is_active'] ?? $organizationType->is_active),
                is_default: (bool) ($input['is_default'] ?? $organizationType->is_default),
                config: $input['config'] ?? $organizationType->config,
            ),
        )->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        /** @var OrganizationType $organizationType */
        $organizationType = OrganizationType::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        return $organizationType->softDelete();
    }
}
