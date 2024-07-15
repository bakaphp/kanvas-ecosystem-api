<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as DataTransferObjectOrganization;
use Kanvas\Guild\Organizations\Models\Organization;

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

        $organizationData = new DataTransferObjectOrganization(
            $user->getCurrentCompany(),
            $user,
            $app,
            $data['name'],
            $data['address'] ?? null
        );

        $createOrganization = new CreateOrganizationAction($organizationData);

        return $createOrganization->execute();
    }

    public function update(mixed $root, array $req): Organization
    {
        $user = auth()->user();
        $data = $req['input'];
        $app = app(Apps::class);

        $organization = Organization::getByIdFromCompanyApp((int) $req['id'], $user->getCurrentCompany(), $app);

        $organizationData = new DataTransferObjectOrganization(
            $user->getCurrentCompany(),
            $user,
            $app,
            $data['name'],
            $data['address'] ?? null
        );

        $createOrganization = new UpdateOrganizationAction($organization, $organizationData);

        return $createOrganization->execute();
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
