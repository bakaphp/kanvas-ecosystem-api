<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\PullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
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

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::SALESASSIST,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($params, $company, $user, $isElead, $isVinSolutions, $leadId) {
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
                }

                return [];
            },
            company: $company,
        );
    }
}
