<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketEnumsCustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Actions\PullPeopleLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PullLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    #[Override]
    /**
     * $entity <Lead>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $isSync = $entity->id === 0;
        $company = Companies::getById($entity->companies_id);
        $this->company = $company;
        $this->app = $app;
        $leadId = $params['entity_id'] ?? null;
        $user = $params['user'] ?? null;
        $phone = $params['phone'] ?? null;
        $email = $params['email'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
        $isDealerSocket = $company->get(DealerSocketEnumsCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null;
        $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return new PullLeadAction(
                $app,
                $company,
                $user
            )->execute($params, $entity->id > 0 ? $entity : null);
        } elseif ($isVinSolutions) {
            return new ActionsPullLeadAction(
                $app,
                $company,
                $user
            )->execute(
                lead: $entity->id > 0 ? $entity : null,
                leadId: (int) $leadId,
            );
        } elseif ($isDealerSocket) {
            return new PullPeopleAction(
                $app,
                $company,
                $user
            )->execute(
                lead: $entity->id > 0 ? $entity : null,
                customerId: (int) $leadId,
            )->toArray();
        } elseif ($isDriveCentric) {
            return new PullPeopleLeadAction(
                $app,
                $company,
                $user
            )->execute(
                phone: $phone,
                email: $email,
            )?->toArray() ?? [];
        }

        return [];
    }
}
