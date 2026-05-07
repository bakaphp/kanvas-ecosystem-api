<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketEnumsCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Actions\PullUserAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PullUserFromCRMActivity extends KanvasActivity
{
    public function execute(Users $user, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $params['company'] ?? null;

        if (! $company instanceof Companies) {
            throw new InvalidArgumentException('Company parameter is required and must be an instance of Companies');
        }

        return $this->executeIntegration(
            entity: $user,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($user, $app, $integrationCompany, $additionalParams) use ($company): array {
                $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
                $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                $isDealerSocket = $company->get(DealerSocketEnumsCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null;
                $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;
                $syncUser = [];

                if ($isDriveCentric) {
                    $syncUser = new PullUserAction(
                        $app,
                        $company,
                    )->execute($user);
                }

                return $syncUser;
            },
            company: $company,
        );
    }
}
