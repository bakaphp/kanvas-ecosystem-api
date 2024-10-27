<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Internal\Actions\ExtractCompanyNameFromEmailAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class ExtractCompanyNameFromPeopleEmailActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 5;

    /**
     * @param People $people
     */
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
}
