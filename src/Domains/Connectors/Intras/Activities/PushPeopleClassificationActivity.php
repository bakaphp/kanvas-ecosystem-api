<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Intras\Actions\PushToIntrasAction;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * No approval, unlike PushApprovedPeopleActivity: these two fields are Kanvas-owned and overwrite no
 * SIPGO data, and a 68K backfill cannot wait on 68K requests.
 */
#[WorkflowAction(
    name: 'IntrasPushPeopleClassificationActivity',
    description: 'Writes a person\'s applied seniority level and functional area to SIPGO.',
)]
class PushPeopleClassificationActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 3;

    /**
     * @param People $people
     */
    #[Override]
    public function execute(Model $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $changes = ParticipantMapper::classificationChanges($people);

        if ($changes === []) {
            return ['pushed' => false, 'reason' => 'nothing to push'];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTRAS,
            additionalParams: $params,
            integrationOperation: fn () => new PushToIntrasAction($people, $changes)->execute(),
            company: $people->company,
        );
    }
}
