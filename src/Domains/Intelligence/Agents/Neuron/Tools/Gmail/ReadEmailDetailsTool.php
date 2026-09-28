<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ReadEmailDetailsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Reads one email's sender, date, subject, body, and attachment list, given the message id from list_emails. */
#[AgentTool(name: 'Read Email Details', category: 'productivity')]
class ReadEmailDetailsTool extends Tool implements HasRunKey
{
    use GuardsRepeatCalls;
    use HasKanvasContext;
    use TrackByInputs;

    /**
     * Keyed per message, so this bounds re-reads of ONE email, not how many an agent may triage in a
     * turn. The second identical read already gets a stop instruction; reaching this cap means the
     * model ignored it twice (KANVAS-ECOSYSTEM-6AE: one message read ten times until the turn died).
     */
    private const int MAX_RUNS = 3;

    public function __construct()
    {
        parent::__construct(
            name: 'read_email_details',
            description: 'Reads one email\'s From, Date, Subject, body, and its attachments (filename + '
                . 'attachment_id for each). Use download_attachment with an attachment_id from here to save one '
                . 'to Kanvas. Read each message once — re-reading it this turn returns the same content.',
        );

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
                name: 'message_id',
                type: PropertyType::STRING,
                description: 'The message id from list_emails. Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $message_id): array
    {
        return $this->oncePerTurn(['message_id' => $message_id], function () use ($message_id): array {
            try {
                $details = new ReadEmailDetailsAction($this->app, $message_id)->execute();
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'reason' => 'read_failed',
                    'message' => 'Could not read the email: ' . $e->getMessage(),
                ];
            }

            return [
                'success' => true,
                ...$details,
            ];
        });
    }
}
