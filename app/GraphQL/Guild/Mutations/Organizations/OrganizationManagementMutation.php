<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Actions\AddAddressToOrganizationAction;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Address as AddressData;
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

        $organization = new CreateOrganizationAction($organizationData)->execute();

        $this->syncAddresses($organization, $data);

        return $organization;
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

        $updated = new UpdateOrganizationAction(
            $organization,
            $organizationData
        )->execute();

        $this->syncAddresses($updated, $data);

        return $updated;
    }

    /**
     * Additive, never a replace-all: omitting the block leaves existing addresses alone, so updating a phone
     * number can't silently delete someone's shipping address.
     *
     * @param array<string, mixed> $data
     */
    private function syncAddresses(Organization $organization, array $data): void
    {
        $addresses = $data['addresses'] ?? null;

        if (! is_array($addresses) || $addresses === []) {
            return;
        }

        $user = auth()->user();

        foreach ($addresses as $address) {
            new AddAddressToOrganizationAction(
                AddressData::from($organization, (array) $address, $user),
            )->execute();
        }
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
