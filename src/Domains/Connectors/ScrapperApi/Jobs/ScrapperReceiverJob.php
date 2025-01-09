<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Jobs;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;

class ScrapperReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $app = $this->receiver->app;
        $branch = CompaniesBranches::getById($this->receiver->configuration['branch_id']);
        $regions = Regions::getById($this->receiver->configuration['region_id']);
        $request = $this->webhookRequest->payload;
        return (new ScrapperAction(
            $app,
            $this->receiver->user,
            $branch,
            $regions,
            $request['search'],
            $request['uuid']
        ))->execute();
    }
}
