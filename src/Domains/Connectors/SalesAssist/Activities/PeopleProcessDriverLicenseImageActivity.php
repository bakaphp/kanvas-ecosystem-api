<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\ProcessPeopleDriverLicenseVerificationAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PeopleProcessDriverLicenseImageActivity extends KanvasActivity
{
    public $tries = 3;

    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function execute(People $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->app = $app;
        $this->company = $people->company;
        $this->user = $people->user;

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params) {
                // Use the new action class
                $action = new ProcessPeopleDriverLicenseVerificationAction(
                    $people,
                    $params
                );

                return $action->execute();
            },
            company: $people->company,
        );
    }
}
