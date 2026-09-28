<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Knowledge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

class IndexKnowledgeCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'intelligence:knowledge:index
        {entity_type : Registered entity alias, e.g. lead}
        {entity_id : Entity database ID}
        {app_id : Application ID}
        {company_id : Company ID}';
    protected $description = 'Build or replace knowledge documents for a registered entity';

    public function handle(KnowledgeSourceRegistry $sources): int
    {
        $entity = $sources->resolveAlias(
            (string) $this->argument('entity_type'),
            (int) $this->argument('entity_id'),
            (int) $this->argument('app_id'),
            (int) $this->argument('company_id'),
        );

        if ($entity === null) {
            $aliases = implode(', ', $sources->aliases());
            $this->error("The entity was not found in that tenant. Registered aliases: {$aliases}.");

            return self::FAILURE;
        }

        /** @var Apps $app */
        $app = $entity->app;
        $this->overwriteAppService($app);

        $source = $sources->for($entity::class);

        if ($source === null) {
            $this->error('No knowledge source is registered for ' . $entity::class . '.');

            return self::FAILURE;
        }

        $count = KnowledgeComponents::indexer($app)->indexEntity($source, $entity);
        $this->info(sprintf(
            'Indexed %d knowledge documents for %s %d.',
            $count,
            $entity::class,
            $entity->getId(),
        ));

        return self::SUCCESS;
    }
}
