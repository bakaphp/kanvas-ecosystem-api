<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PullLeadActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    /**
     * $entity <Lead>.
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $leadId = $params['entity_id'] ?? null;

        $lead = $entity;
        if ($lead === null) {
            return [
                'result'  => false,
                'message' => 'Lead not found with id '.$leadId,
            ];
        }

        return $lead->toArray();
    }
}
