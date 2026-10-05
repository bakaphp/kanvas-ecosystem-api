<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ListEmailsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Searches the connected Gmail mailbox, e.g. for unread invoice emails with attachments. */
#[AgentTool(name: 'List Emails', category: 'productivity')]
class ListEmailsTool extends Tool
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'list_emails';

    protected ?string $description = 'Searches the connected Gmail mailbox using Gmail\'s own search syntax (e.g. '
        . '"subject:Invoice has:attachment is:unread", "from:vendor@x.com"). Returns each match\'s '
        . 'message id, thread id, and subject — use read_email_details with a message id to get the '
        . 'full body and attachment list. The same query returns the same matches all turn — if it '
        . 'finds nothing, change the query or say nothing was found.';

    /**
     * Keyed per query, so an agent can still run several different searches in a turn. An empty
     * search re-run verbatim until NeuronAI's default cap of 10 killed the turn (KANVAS-ECOSYSTEM-6GW).
     */
    private const int MAX_RUNS = 3;

    public function __construct()
    {
        $this->initRepeatGuard();
        $this->setMaxRuns(self::MAX_RUNS);
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Gmail search syntax, e.g. "subject:Invoice has:attachment is:unread". Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'max_results',
                type: PropertyType::INTEGER,
                description: 'Maximum number of emails to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?int $max_results = null): array
    {
        $inputs = [
            'query' => trim($query),
            'max_results' => $max_results,
        ];

        return $this->oncePerTurn($inputs, function () use ($query, $max_results): array {
            try {
                $emails = new ListEmailsAction($this->app, $query, $max_results ?? 10)->execute();
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'reason' => 'list_failed',
                    'message' => 'Could not search the mailbox: ' . $e->getMessage(),
                ];
            }

            if ($emails === []) {
                return [
                    'success' => true,
                    'count' => 0,
                    'emails' => [],
                    'message' => 'No emails match this query. Running it again returns the same nothing — try a '
                        . 'different query (e.g. drop a filter or search the sender with from:), or say plainly '
                        . 'that nothing was found.',
                ];
            }

            return [
                'success' => true,
                'count' => count($emails),
                'emails' => $emails,
            ];
        });
    }
}
