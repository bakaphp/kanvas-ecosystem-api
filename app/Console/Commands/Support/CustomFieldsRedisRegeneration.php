<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;

class CustomFieldsRedisRegeneration extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:customFields-redis-regeneration {app_id} {className}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'insert a fake migration into the migrations table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $className = $this->argument('className');
        if (! class_exists($className)) {
            $this->error('Class does not exist ' . $className);

            return;
        }

        $class = new $className();

        $page = 1;
        $perPage = 200;

        do {
            $entities = $class->fromApp($app)
            ->notDeleted()
            ->paginate($perPage, ['*'], 'page', $page);

            $entities->each(function ($entity) {
                if (method_exists($entity, 'reCacheCustomFields')) {
                    $entity->reCacheCustomFields();

                    $this->info('Regenerating custom fields for ' . get_class($entity) . ' with id ' . $entity->getId());
                }
            });

            $page++;
        } while ($entities->isNotEmpty());
    }
}
