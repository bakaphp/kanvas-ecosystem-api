<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Actions\ProcessDriverLicenseVerificationAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class LeadProcessDriverLicenseImageActivity extends KanvasActivity
{
    public $tries = 3;

    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->app = $app;
        $this->company = $lead->company;
        $this->user = $lead->user;

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                // Use the new action class
                $action = new ProcessDriverLicenseVerificationAction(
                    $lead,
                    $this->app,
                    $this->company,
                    $this->user,
                    $params
                );

                return $action->execute();
            },
            company: $lead->company,
        );
    }
}
