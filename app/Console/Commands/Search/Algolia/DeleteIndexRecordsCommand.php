<?php

declare(strict_types=1);

namespace App\Console\Commands\Search\Algolia;

use Algolia\AlgoliaSearch\SearchClient;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;

class DeleteIndexRecordsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-search:delete-algolia-records {index_name} {filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete records on algolia given the index and filter, if any';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $indexName = $this->argument('index_name');
        $filter = $this->argument('filter');

        if (empty($indexName)) {
            $this->error('The index name param is required.');
            return;
        }

        if (empty($filter)) {
            $this->error('The filter param is required.');
            return;
        }

        $client = SearchClient::create(
            config('scout.algolia.id'),
            config('scout.algolia.secret')
        );

        $index = $client->initIndex($indexName);

        try {
            $index->deleteBy([
                'filters' => $filter
            ]);
            $this->info("Records deleted from index '{$indexName}' with filter '{$filter}'.");
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
