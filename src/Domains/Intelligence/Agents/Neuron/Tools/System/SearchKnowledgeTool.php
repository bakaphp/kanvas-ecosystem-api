<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use stdClass;
use Throwable;

/**
 * One "look it up" over everything the agent is allowed to read: the company's documents, the
 * record's own history and company memory, through the same composite retrieval the automatic
 * pre-turn recall uses, so the tool can never reach further than the recall does. One drawer, because
 * a model is bad at guessing which drawer an answer is in and searches the wrong one until it gives up.
 */
#[AgentTool(name: 'Search Knowledge', category: 'ecosystem')]
class SearchKnowledgeTool extends Tool
{
    use TrackByInputs;

    /**
     * Retrieval answers the same topic the same way however the words change, so a turn that keeps
     * searching is a turn with no tool for what it was asked. The automatic recall already ran once
     * before the turn; a real question needs one search and one rephrase.
     */
    public const int MAX_SEARCHES_PER_TURN = 2;

    protected string $name = 'search_knowledge';

    protected ?string $description = 'Look it up: the documents the company gave you (policies, guides, location and lot '
        . 'lists, price sheets), this record\'s own history, and earlier conversations and recorded outcomes. '
        . 'Use it when the answer is a fact about the company or something discussed or decided before and '
        . 'your context does not carry it. Each result is labelled by where it comes from: [Company document] '
        . 'is the company\'s own material and the source for facts about it; [Record history] is this '
        . 'record\'s past; [Earlier conversation], [Saved memory] and [Ledger] are what was said or done '
        . 'before. Not for live records: projects, plans, tasks, leads, people and orders have their own '
        . 'tools. Write the query as the topic ("CDJR lot address"), not the question; since/until narrow '
        . 'conversations and outcomes to a period.';

    /** Shared by the per-call clones NeuronAI hands out, so it counts the turn and not one call. */
    private readonly stdClass $turn;

    public function __construct(private readonly RetrievalInterface $retrieval)
    {
        $this->turn = new stdClass();
        $this->turn->searches = 0;
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'What to look for, phrased as the topic (names, ids, terms), not as a question.',
                required: true,
            ),
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'Optional start of the period for conversations and outcomes, e.g. "2026-09-01" or "30 days ago".',
                required: false,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'Optional end of that period, e.g. "2026-09-30".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?string $since = null, ?string $until = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return self::miss('Give the query as a topic to search for.');
        }

        if (++$this->turn->searches > self::MAX_SEARCHES_PER_TURN) {
            return self::miss(sprintf(
                'You have already searched %d times this turn; what came back is all there is on this. Answer from it now, or read the record itself with its own tool. Do not search again.',
                self::MAX_SEARCHES_PER_TURN,
            ));
        }

        try {
            $filters = [];
            if ($since !== null) {
                $filters[] = Filter::gte('created_at', Carbon::parse($since)->getTimestamp());
            }
            if ($until !== null) {
                $filters[] = Filter::lte('created_at', Carbon::parse($until)->endOfDay()->getTimestamp());
            }
        } catch (Throwable) {
            return self::miss('since/until must be dates, e.g. "2026-09-01". Do not retry with the same values.');
        }

        try {
            $documents = $this->retrieval->retrieve(
                new UserMessage($query),
                $filters === [] ? null : FilterGroup::and(...$filters),
            );
        } catch (Throwable $e) {
            report($e);

            return self::miss('Knowledge is not reachable right now; answer from what you have.');
        }

        if ($documents === []) {
            return self::miss('Nothing matches; rephrase the topic once or widen the period, then answer from what you have.');
        }

        return [
            'count' => count($documents),
            'results' => array_map(static fn (Document $document): array => [
                'source' => self::sourceOf($document),
                'content' => $document->getContent(),
            ], $documents),
        ];
    }

    private static function sourceOf(Document $document): string
    {
        $metadata = $document->getMetadata();
        $kind = (string) ($metadata['source_type'] ?? $document->getSourceType());

        return match (true) {
            $kind === IndexKnowledgeDocumentActivity::SOURCE_TYPE => 'company_document',
            in_array($kind, KnowledgeScope::MEMORY_SOURCE_TYPES, true) => $kind,
            default => 'record_history',
        };
    }

    /**
     * @return array{count: int, results: list<never>, message: string}
     */
    private static function miss(string $message): array
    {
        return ['count' => 0, 'results' => [], 'message' => $message];
    }
}
