<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Knowledge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

class PruneLeadMessageKnowledgeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The per-message lead rows and the person card. Every lead document shares one sourceType and
     * sourceName, so deleting by source would take the lead, company, organization and vehicle cards
     * with them; `source_type` is the field that tells them apart.
     */
    public const array PRUNED_SOURCE_TYPES = ['message', 'note', 'people'];

    protected $signature = 'intelligence:knowledge:prune-lead-messages
        {app_id : Application ID}
        {--force : Delete; without it the command only counts}';
    protected $description = 'Remove the per-message lead rows from an app\'s knowledge index, keeping the lead cards';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $filter = self::filterFor($app);
        $store = KnowledgeComponents::store($app);
        $count = $store->countByFilter($filter);

        if (! $this->option('force')) {
            $this->info("{$count} lead message, note and person rows would be deleted from app {$app->getId()}. Run with --force to delete.");

            return self::SUCCESS;
        }

        $store->deleteByFilter($filter);
        $this->info("Deleted {$count} lead message, note and person rows from app {$app->getId()}; {$store->countByFilter($filter)} left.");

        return self::SUCCESS;
    }

    public static function filterFor(Apps $app): string
    {
        return sprintf('apps_id:=%d', $app->getId())
            . ' && entity_type:=' . KnowledgeScope::escapeFilterValue(Lead::class)
            . ' && source_type:=[' . implode(', ', array_map(KnowledgeScope::escapeFilterValue(...), self::PRUNED_SOURCE_TYPES)) . ']';
    }
}
