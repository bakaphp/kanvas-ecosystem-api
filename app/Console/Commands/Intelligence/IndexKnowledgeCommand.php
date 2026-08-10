<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Neuron\RAG\Indexers\KnowledgeIndexer;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

class IndexKnowledgeCommand extends Command
{
    protected $signature = 'intelligence:knowledge:index
        {entity_type : Registered entity alias, e.g. lead}
        {entity_id : Entity database ID}
        {app_id : Application ID}
        {company_id : Company ID}';
    protected $description = 'Build or replace Neuron RAG documents for a registered entity';

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

        $count = new KnowledgeIndexer($sources)->index($entity);
        $this->info(sprintf(
            'Indexed %d knowledge documents for %s %d.',
            $count,
            $entity::class,
            $entity->getId(),
        ));

        return self::SUCCESS;
    }
}
