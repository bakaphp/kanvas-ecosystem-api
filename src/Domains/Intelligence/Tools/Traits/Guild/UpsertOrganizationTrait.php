<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools\Traits\Guild;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Throwable;

trait UpsertOrganizationTrait
{
    protected function upsertOrganization(
        AppInterface $app,
        CompanyInterface $company,
        UserInterface $user,
        string $name,
        ?int $organizationId = null,
        ?int $organizationTypeId = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $city = null,
        ?string $state = null,
        ?string $zip = null,
    ): array {
        $orgData = new OrganizationData(
            company: $company,
            user: $user,
            app: $app,
            name: $name,
            email: $email,
            phone: $phone,
            address: $address,
            city: $city,
            state: $state,
            zip: $zip,
        );

        try {
            if ($organizationId !== null) {
                $organization = Organization::getByIdFromCompanyApp($organizationId, $company, $app);
                $organization = new UpdateOrganizationAction($organization, $orgData)->execute();
                $action = 'updated';
            } else {
                $existing = Organization::query()
                    ->fromApp($app)
                    ->fromCompany($company)
                    ->notDeleted()
                    ->where('name', $name)
                    ->first();

                if ($existing !== null) {
                    $organization = new UpdateOrganizationAction($existing, $orgData)->execute();
                    $action = 'found';
                } else {
                    $organization = new CreateOrganizationAction($orgData)->execute();
                    $action = 'created';
                }

                if ($organizationTypeId !== null) {
                    $organizationType = OrganizationType::getByIdFromCompanyApp($organizationTypeId, $company, $app);
                    $organization->organization_type_id = $organizationType->getId();
                    $organization->save();
                }
            }
        } catch (Throwable $e) {
            return ['error' => "Failed to upsert organization: {$e->getMessage()}"];
        }

        return [
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            'organization_type_id' => $organization->organization_type_id,
            'action' => $action,
        ];
    }
}
