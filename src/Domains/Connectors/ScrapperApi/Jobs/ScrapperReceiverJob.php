<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperAction;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ScrapperReceiverJob extends ProcessWebhookJob
{
    use KanvasJobsTrait;

    public function execute(): array
    {
        $app = $this->receiver->app;
        $this->overwriteAppService($app);
        $branch = CompaniesBranches::getById($this->receiver->configuration['branch_id']);
        $regions = Regions::getById($this->receiver->configuration['region_id']);
        $request = $this->webhookRequest->payload;
        $action = new ScrapperAction(
            $app,
            $this->receiver->user,
            $branch,
            $regions,
            $request['search'],
            key_exists('uuid', $request) ? $request['uuid'] : null
        );

        return [
            'message' => 'Scrapper started',
            'search'  => $request['search'],
            'results' => $action->execute(),
        ];
    }
}
