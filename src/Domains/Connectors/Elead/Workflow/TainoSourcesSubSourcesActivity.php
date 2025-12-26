<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\KanvasActivity;

class TainoSourcesSubSourcesActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $payload = $params['payload'] ?? [];
        if (! $payload) {
            return [
                'error' => 'Payload is required',
            ];
        }
    }
}
