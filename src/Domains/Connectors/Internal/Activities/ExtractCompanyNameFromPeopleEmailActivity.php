<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Internal\Actions\ExtractCompanyNameFromEmailAction;
use Kanvas\Connectors\Internal\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class ExtractCompanyNameFromPeopleEmailActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param People $people
     */
    #[Override]
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $peopleEmails = $people->getEmails();

        if ($people->organizations->count() > 0) {
            return [
                'people_id' => $people->getId(),
                'message' => 'People already has an organization',
                'organization_id' => $people->organizations->first()->getId(),
            ];
        }

        if ($this->hasBeenScreenedRecently($people)) {
            return [
                'people_id' => $people->getId(),
                'message' => 'People has been screened recently',
                'organization_id' => null,
            ];
        }

        $people->set(ConfigurationEnum::INTERNAL_EMAIL_DOMAIN_DATA_ENRICHMENT_CUSTOM_FIELDS->value, time());

        foreach ($peopleEmails as $peopleEmail) {
            $email = $peopleEmail->value;

            $extractCompanyNameFromEmailAction = new ExtractCompanyNameFromEmailAction();
            $companyName = $extractCompanyNameFromEmailAction->execute($email);

            if ($companyName) {
                $createOrganization = new CreateOrganizationAction(
                    new Organization(
                        $people->company,
                        $people->user,
                        $people->app,
                        $companyName,
                    )
                );

                $organization = $createOrganization->execute();

                $organization->addPeople($people);
                $people->set('company', $companyName); //@todo remove this

                return [
                    'people_id' => $people->getId(),
                    'organization_id' => $organization->getId(),
                ];
            }
        }

        return [
            'people_id' => $people->getId(),
            'message' => 'No company name found in people email',
            'organization_id' => null,
        ];
    }

    private function hasBeenScreenedRecently(Model $people): bool
    {
        $key = ConfigurationEnum::INTERNAL_EMAIL_DOMAIN_DATA_ENRICHMENT_CUSTOM_FIELDS->value;
        $apolloRevalidationThreshold = $people->company->get(ConfigurationEnum::INTERNAL_EMAIL_DOMAIN_DATA_ENRICHMENT_CUSTOM_FIELDS->value) ?? '-2 months';

        return $people->get($key) && $people->get($key) > strtotime($apolloRevalidationThreshold);
    }
}
