<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Search\MeiliSearchService as SearchMeiliSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexInMeiliSearchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $indexName,
        public Model $model
    ) {
        $this->model = $model;
        $this->indexName = $indexName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $meiliSearchService = new SearchMeiliSearchService();
        $meiliSearchService->indexModel($this->indexName, $this->model);
    }
}
