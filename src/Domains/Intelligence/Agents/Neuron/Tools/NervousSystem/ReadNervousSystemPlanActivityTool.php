<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Read what actually happened on a plan: the comments its workers left, and what each task produced.
 *
 * The per-turn context bundle answers "what is going on" — open plans, task statuses, the last handful
 * of messages. It cannot answer "what did that produce", because it is bounded: ten messages, a
 * handful of plans, a truncated result. Someone asking about a specific plan is asking past that
 * boundary, and the orchestrator had no way to go and look.
 *
 * The consequence was worse than a missing answer. Asked for a file its own worker had generated
 * minutes earlier, the PM did not say it could not see it — it explained, fluently, why no link
 * existed. The URL was on the task the whole time. An agent with no way to check will narrate instead,
 * so the fix is a way to check.
 */
#[AgentTool(name: 'Read Plan Activity', category: 'nervous_system')]
class ReadNervousSystemPlanActivityTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesPlanForTool;
    use TrackByInputs;

    private const int DEFAULT_MESSAGES = 20;
    private const int MAX_MESSAGES = 50;

    /** Long enough for a worker's full answer — the whole point is to reach what the bundle truncates. */
    private const int RESULT_CHAR_CAP = 4000;

    public function __construct()
    {
        parent::__construct(
            name: 'read_plan_activity',
            description: 'Read the full record of ONE plan: every comment its workers posted and the '
                . 'result each finished task produced. Use it whenever someone asks what a plan or its '
                . 'tasks actually produced — a file, a link, a count, a summary — or when you need '
                . 'detail your context bundle only shows in outline. The bundle is capped and truncated; '
                . 'this is not. Check here before telling anyone that something was not produced or that '
                . 'you cannot find it — the answer is usually recorded on the task.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan to read. Any plan in this company, open or already finished.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max comments to return, newest first. Defaults to 20, max 50.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, ?int $limit = null): array
    {
        $resolved = $this->resolvePlanOrError($plan_id);

        if (is_array($resolved)) {
            return $resolved;
        }

        $limit = max(1, min(self::MAX_MESSAGES, $limit ?? self::DEFAULT_MESSAGES));

        return [
            'plan_id' => $resolved->getId(),
            'title' => $resolved->title,
            'status' => $resolved->status,
            'completion_pct' => $resolved->completion_pct,
            'tasks' => $this->tasks($resolved),
            'comments' => $this->comments($resolved, $limit),
            'note' => 'The `result` on each task is what the worker itself reported. If someone asked '
                . 'for a link, a file or a number, quote it from there rather than describing it.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tasks(Plan $plan): array
    {
        return $plan->tasks()
            ->where('is_deleted', 0)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->toBase()
            ->map(function (Task $task): array {
                return array_filter(
                    [
                        'task_id' => $task->getId(),
                        'title' => $task->title,
                        'status' => $task->status,
                        'blocked_reason' => $task->blocked_reason,
                        'result' => $task->workerSummaryExcerpt(self::RESULT_CHAR_CAP),
                    ],
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                );
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function comments(Plan $plan, int $limit): array
    {
        $channelIds = $plan->socialChannels()->pluck('channels.id')->all();

        if ($channelIds === []) {
            return [];
        }

        return Message::query()
            ->whereHas('channels', fn (Builder $query): Builder => $query->whereIn('channels.id', $channelIds))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->toBase()
            ->map(fn (Message $message): array => [
                'author' => $message->user?->displayname ?? $message->user?->firstname,
                'at' => $message->created_at?->toIso8601String(),
                'content' => trim((string) ($message->contentText() ?? '')),
            ])
            ->reject(fn (array $row): bool => $row['content'] === '')
            ->values()
            ->all();
    }
}
