<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as DataTransferObjectOrganization;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;

class OrganizationManagementMutation
{
    /**
     * Create a new organization.
     */
    public function create(mixed $root, array $req): Organization
    {
        $user = auth()->user();
        $data = $req['input'];
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $organizationData = new DataTransferObjectOrganization(
            $company,
            $user,
            $app,
            $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            organizationType: isset($data['organization_type_id'])
                ? OrganizationType::getByIdFromCompanyApp((int) $data['organization_type_id'], $company, $app)
                : null,
        );

        return new CreateOrganizationAction($organizationData)->execute();
    }

    public function update(mixed $root, array $req): Organization
    {
        $user = auth()->user();
        $data = $req['input'];
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $organization = Organization::getByIdFromCompanyApp((int) $req['id'], $company, $app);

        $organizationType = array_key_exists('organization_type_id', $data)
            ? ($data['organization_type_id'] !== null
                ? OrganizationType::getByIdFromCompanyApp((int) $data['organization_type_id'], $company, $app)
                : null)
            : $organization->organizationType;

        $organizationData = new DataTransferObjectOrganization(
            $company,
            $user,
            $app,
            $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            organizationType: $organizationType,
        );

        return new UpdateOrganizationAction(
            $organization,
            $organizationData
        )->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $organization = Organization::getByIdFromCompanyApp((int) $req['id'], $user->getCurrentCompany(), $app);

        return $organization->softDelete();
    }

    public function restore(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $organization = Organization::where('id', (int) $req['id'])
            ->fromCompany($user->getCurrentCompany())
            ->fromApp($app)
            ->firstOrFail();

        return $organization->restoreRecord();
    }
}
