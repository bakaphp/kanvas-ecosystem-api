<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;

class KanvasLighthouseRedisCacheCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:lighthouse-redis-cache {class} {app_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'generate the lighthouse redis cache for a specific class';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $class = $this->argument('class');
        $appId = $this->argument('app_id');

        $app = Apps::getById($appId);
        $this->overwriteAppService($app);

        if (! class_exists($class)) {
            $this->error('Class does not exist ' . $class);

            return;
        }

        $entities = new $class();

        //check if class has a trait
        if (! method_exists($entities, 'clearLightHouseCache')) {
            $this->error('Class does not have the trait HasLightHouseCache ' . $class);

            return;
        }

        $entities->fromApp($app)
            ->notDeleted()
            ->orderBy('created_at', 'DESC')
            ->chunk(100, function ($entitiesChunk) use ($class) {
                foreach ($entitiesChunk as $entity) {
                    $start = microtime(true);

                    $entity->clearLightHouseCache();

                    $end = microtime(true);
                    $executionTime = round($end - $start, 4);

                    $this->info("Generating cache for {$class} {$entity->getId()} - Execution time: {$executionTime} seconds");
                }
            });
    }
}
