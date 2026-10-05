<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use App\Console\Commands\Concerns\IteratesTargetApps;
use Baka\Traits\KanvasJobsTrait;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Agents\Neuron\RAG\Jobs\IndexKnowledgeJob;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Writes a window of past turns and ledger outcomes into company memory: the first rollout on a
 * tenant, and recovery after an embedding outage. Documents are keyed the way the live path keys
 * them, so re-running a window upserts instead of duplicating.
 */
class ReindexAgentMemoryCommand extends Command
{
    use IteratesTargetApps;
    use KanvasJobsTrait;

    private const int BATCH = 50;

    protected $signature = 'agents:reindex-memory
                            {--since= : Re-ingest from this date (default: 30 days ago)}
                            {--app= : Only this app id}';

    protected $description = 'Re-ingest conversation turns and ledger outcomes into company memory for a window';

    /** @var array<int, bool> */
    private array $remembering = [];

    /** @var array<string, int> */
    private array $skipped = [];

    public function __construct(
        private readonly ?VectorStoreInterface $memoryStore = null,
        private readonly ?EmbeddingsProviderInterface $memoryEmbeddings = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $since = Carbon::parse((string) ($this->option('since') ?: now()->subDays(30)->toDateString()));

        foreach ($this->targetApps() as $app) {
            if (! KnowledgeComponents::memoryEnabled($app)) {
                $this->warn("App {$app->getId()}: memory is off (no Typesense credentials, or agent_memory_enabled = 0), skipped");

                continue;
            }

            $this->overwriteAppService($app);

            $events = $this->reindexLedger($app, $since);
            $turns = $this->reindexConversations($app, $since);

            $this->info("App {$app->getId()}: queued {$events} ledger events, wrote {$turns} conversation turns since {$since->toDateString()}");
            $this->reportSkipped();
        }

        return self::SUCCESS;
    }

    private function reindexLedger(Apps $app, Carbon $since): int
    {
        $queued = 0;

        // The job re-reads the row, so only what KnowledgeEntity needs is hydrated here.
        LedgerKnowledgeSource::whereWanted(Event::query())
            ->where('apps_id', $app->getId())
            ->where('companies_id', '>', 0)
            ->where('occurred_at', '>=', $since)
            ->select(['id', 'apps_id', 'companies_id'])
            ->chunkById(500, function ($events) use (&$queued): void {
                foreach ($events as $event) {
                    IndexKnowledgeJob::dispatch(KnowledgeEntity::fromModel($event));
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * The same document the live exit node writes, for every turn in the window. A tool-using turn has
     * tool-call and tool-result rows with no content between the question and the answer; they are
     * skipped the way ConversationMemoryNode skips them, so the answer is the final reply.
     */
    private function reindexConversations(Apps $app, Carbon $since): int
    {
        $store = $this->memoryStore ?? KnowledgeComponents::memoryStore($app);
        $embeddings = $this->memoryEmbeddings ?? RagComponents::embeddings($app);

        $written = 0;
        $batch = [];

        foreach ($this->turnsSince($app, $since) as $document) {
            $batch[] = $document;

            if (count($batch) >= self::BATCH) {
                $written += $this->flush($store, $embeddings, $batch);
                $batch = [];
            }
        }

        return $written + $this->flush($store, $embeddings, $batch);
    }

    /**
     * @return Generator<int, Document>
     */
    private function turnsSince(Apps $app, Carbon $since): Generator
    {
        $minChars = KnowledgeComponents::memoryIngestMinChars($app);
        $usersMorph = Relation::getMorphAlias(Users::class);

        $rows = DB::connection('intelligence')
            ->table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.apps_id', $app->getId())
            ->where('c.companies_id', '>', 0)
            ->whereNotNull('c.agent_id')
            ->where('m.created_at', '>=', $since)
            ->where('m.content', '<>', '')
            ->orderBy('c.id')
            ->orderBy('m.sequence')
            ->orderBy('m.created_at')
            ->orderBy('m.id')
            ->select([
                'm.id',
                'm.conversation_id',
                'm.role',
                'm.content',
                'm.is_public',
                'm.created_at',
                'c.title',
                'c.companies_id',
                'c.agent_id',
                'c.user_id',
                'c.participant_type',
                'c.participant_id',
            ])
            ->cursor();

        $pendingQuestion = [];

        foreach ($rows as $row) {
            if ($row->role === 'user') {
                $pendingQuestion[$row->conversation_id] = (int) $row->is_public === 1 ? (string) $row->content : null;

                continue;
            }

            if ($row->role !== 'assistant' || ! array_key_exists($row->conversation_id, $pendingQuestion)) {
                continue;
            }

            $question = $pendingQuestion[$row->conversation_id];
            unset($pendingQuestion[$row->conversation_id]);

            if ($question === null) {
                $this->skip('private turn');

                continue;
            }

            if (! $this->agentRemembers((int) $row->agent_id)) {
                $this->skip('agent does not remember (not a SystemUserAgent, or customer-facing)');

                continue;
            }

            $content = ConversationMemoryNode::transcript($question, (string) $row->content);

            if ($content === null || mb_strlen($content) < $minChars) {
                $this->skip("shorter than {$minChars} chars or a no-op reply");

                continue;
            }

            yield ConversationMemoryNode::document(
                $content,
                (string) $row->title,
                (string) $row->id,
                $this->metadataFor($app, $row, $usersMorph),
                Carbon::parse((string) $row->created_at)->getTimestamp(),
            );
        }
    }

    /**
     * The participant is the record the conversation is about, which is what a customer-facing agent's
     * recall filters on; a human participant is the acting human instead.
     *
     * @return array<string, int|string>
     */
    private function metadataFor(Apps $app, object $row, string $usersMorph): array
    {
        $humanParticipant = $row->participant_type === $usersMorph && $row->participant_id !== null;
        $recordParticipant = ! $humanParticipant && $row->participant_type !== null && $row->participant_id !== null;

        return ConversationMemoryNode::metadata(
            appId: $app->getId(),
            companyId: (int) $row->companies_id,
            agentId: (int) $row->agent_id,
            usersId: $humanParticipant ? (int) $row->participant_id : (int) $row->user_id,
            entityType: $recordParticipant ? (Relation::getMorphedModel($row->participant_type) ?? $row->participant_type) : null,
            entityId: $recordParticipant ? (int) $row->participant_id : null,
        );
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    /**
     * A zero with no reason sends people looking at Typesense; the usual cause is the agent type.
     */
    private function reportSkipped(): void
    {
        foreach ($this->skipped as $reason => $count) {
            $this->line("  skipped {$count} turns: {$reason}");
        }

        $this->skipped = [];
    }

    /**
     * @param list<Document> $batch
     */
    private function flush(VectorStoreInterface $store, EmbeddingsProviderInterface $embeddings, array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        try {
            $store->addDocuments($embeddings->embedDocuments($batch));

            return count($batch);
        } catch (Throwable $e) {
            report($e);
            $this->error('A batch failed to embed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * The handler class decides, exactly as it does on a live turn; the decision is a constant per
     * class, so a bare instance answers it without the agent's configuration.
     *
     * A customer-facing agent remembers live but is left out of the sweep: its rows are written by
     * logTurn under their own ids, not the message ids the live node keys by, so a sweep would write
     * every one of its turns a second time. Its history lives on the record it talks to anyway.
     */
    private function agentRemembers(int $agentId): bool
    {
        return $this->remembering[$agentId] ??= (function () use ($agentId): bool {
            $handler = (string) Agent::query()->whereKey($agentId)->with('type')->first()?->type?->handler;

            if ($handler === ''
                || ! class_exists($handler)
                || ! is_a($handler, BehavesAsKanvasAgent::class, true)
                || is_a($handler, ConversesWithCustomer::class, true)) {
                return false;
            }

            $method = new ReflectionMethod($handler, 'remembersForCompany');

            return (bool) $method->invoke(new ReflectionClass($handler)->newInstanceWithoutConstructor());
        })();
    }
}
