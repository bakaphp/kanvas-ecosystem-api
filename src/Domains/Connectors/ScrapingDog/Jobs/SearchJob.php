<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class SearchJob extends ProcessWebhookJob
{
    use KanvasJobsTrait;
    public Channels $channel;
    public Warehouses $warehouse;
    public CompaniesBranches $companiesBranches;

    #[Override]
    public function execute(): array
    {
        $search = $this->webhookRequest->payload['search'] ?? '';
        $page = $this->webhookRequest->payload['page'] ?? 1;
        $repository = new ScrapingDogRepository($this->receiver->app);

        $ttl = $this->receiver->app->get(ConfigEnum::TTL_SEARCH->value);
        $key = $search . ':' . $page . ':' . $this->receiver->app->getId();

        return Cache::remember($key, $ttl, function () use ($repository, $search, $page) {
            $results = $repository->getSearch($search, $page);
            return ['results' => $results['results'], 'pagination' => $results['pagination'] ?? []];
        });
    }
}
