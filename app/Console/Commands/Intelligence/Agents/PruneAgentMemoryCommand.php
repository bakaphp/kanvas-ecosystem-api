<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use App\Console\Commands\Concerns\IteratesTargetApps;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Throwable;

/**
 * Conversation and outcome memory age out at the app's retention; a saved memory (`remember`) never
 * does, which is why the filter names the two kinds instead of everything but one.
 */
class PruneAgentMemoryCommand extends Command
{
    use IteratesTargetApps;
    use KanvasJobsTrait;

    protected $signature = 'agents:prune-memory {--app= : Only this app id}';

    protected $description = 'Delete conversation and ledger memory older than each app\'s agent_memory_retention_days';

    public function __construct(private readonly ?VectorStoreInterface $memoryStore = null)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->targetApps() as $app) {
            if (! KnowledgeComponents::memoryEnabled($app)) {
                continue;
            }

            $this->overwriteAppService($app);

            $days = KnowledgeComponents::memoryRetentionDays($app);
            $cutoff = now()->subDays($days)->getTimestamp();

            try {
                ($this->memoryStore ?? KnowledgeComponents::memoryStore($app))->delete(FilterGroup::and(
                    Filter::eq('apps_id', $app->getId()),
                    Filter::in('source_type', [ConversationMemoryNode::SOURCE_TYPE, LedgerKnowledgeSource::OUTCOME_SOURCE_TYPE]),
                    Filter::lt('created_at', $cutoff),
                ));

                $this->info("App {$app->getId()}: pruned conversation and ledger memory older than {$days} days");
            } catch (Throwable $e) {
                report($e);
                $this->error("App {$app->getId()}: prune failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
