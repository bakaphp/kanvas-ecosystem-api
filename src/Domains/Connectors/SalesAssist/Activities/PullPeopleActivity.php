<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\PullPeopleAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PullPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    protected ?Companies $company = null;
    protected ?Apps $app = null;

    #[Override]
    /**
     * $entity <People>
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $isSync = $entity->id === 0;
        $company = Companies::getById($entity->company_id);
        $this->company = $company;
        $this->app = $app;
        $peopleId = $params['entity_id'] ?? null;
        $user = $params['user'] ?? null;

        $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return new PullPeopleAction($app, $company, $user)->execute($params);
        }

        return [];
    }
}
