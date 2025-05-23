<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait HasIntegrationCompany
{
    public function setIntegration(
        AppInterface $app,
        IntegrationsEnum $integrationEnum,
        string $handler,
        Companies $company,
        UserInterface $user
    ): void {
        $integration = Integrations::firstOrCreate([
            'apps_id' => $app->getId(),
            'name' => $integrationEnum->value,
            'config' => [],
            'handler' => $handler,
        ]);

        $company->associateUser(
            $user,
            true,
            $company->defaultBranch
        );

        new Setup(
            app: $app,
            user: $user,
            company: $company
        )->run();

        $region = Regions::getDefault($company, $app);
        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $region->company,
            config: [],
            app: $app
        );

        $status = Status::where('slug', StatusEnum::ACTIVE->value)
        ->where('apps_id', 0)
        ->first();
        new CreateIntegrationCompanyAction($integrationDto, $user, $status)->execute();
    }
}
