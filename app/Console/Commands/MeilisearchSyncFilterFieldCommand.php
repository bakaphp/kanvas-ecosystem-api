<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Exceptions\InvalidResponseBodyException as ApiException;
use Throwable;

class MeilisearchSyncFilterFieldCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meilisearch:sync-filter-fields';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'sync filter fields';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new MeilisearchClient(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );

        try {
            $indexes = (array) config('scout.meilisearch.index-settings', []);

            if (count($indexes)) {
                foreach ($indexes as $name => $settings) {
                    if (! is_array($settings)) {
                        $name = $settings;

                        $settings = [];
                    }

                    if (class_exists($name)) {
                        $model = new $name();
                    }

                    if (isset($model) &&
                        config('scout.soft_delete', false) &&
                        in_array(SoftDeletes::class, class_uses_recursive($model))) {
                        $settings['filterableAttributes'][] = '__soft_deleted';
                    }
                    $indexName = $this->indexName($name);
                    $fields = $settings['filterableAttributes'];
                    $client->index($indexName)->updateFilterableAttributes($fields);
                    $this->info('Settings for the [' . $indexName . '] index synced successfully.');
                }
            } else {
                $this->info('No index settings found for the meilisearch engine.');
            }
        }catch(Throwable $exception){
            dd($exception->getMessage());
        }
        return;
    }

    /**
     * Get the fully-qualified index name for the given index.
     *
     * @param  string  $name
     * @return string
     */
    protected function indexName($name)
    {
        if (class_exists($name)) {
            return (new $name())->searchableAs();
        }

        $prefix = config('scout.prefix');

        return ! Str::startsWith($name, $prefix) ? $prefix . $name : $name;
    }
}
