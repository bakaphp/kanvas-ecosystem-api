<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Deals\Actions\RecordDealNoteAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesDealForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\TakesMessageForEntity;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Take Deal Message', category: 'crm')]
class TakeDealMessageTool extends Tool
{
    use HasKanvasContext;
    use ResolvesDealForTool;
    use TakesMessageForEntity;

    public function __construct()
    {
        parent::__construct(
            name: 'take_deal_message',
            description: 'Take a message for the team about a deal and make sure they get it. '
                . 'Use this when the customer wants to leave a message or ask someone to follow up on their deal '
                . '("tell my rep to call me", "have someone reach me about the quote"). '
                . 'The message is saved as a note on the deal and the deal owner is notified. '
                . 'This does NOT transfer the conversation.',
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
                name: 'deal_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the deal in scope for this conversation.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The message to pass along, in the customer\'s words.',
                required: true,
            ),
            new ToolProperty(
                name: 'for_whom',
                type: PropertyType::STRING,
                description: 'Who the message is for, if the customer named someone (e.g. "John", "the finance dept"). Omit if not specified.',
                required: false,
            ),
            new ToolProperty(
                name: 'callback_number',
                type: PropertyType::STRING,
                description: 'A phone/number the customer wants to be reached at, if they gave one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $deal_id,
        string $message,
        ?string $for_whom = null,
        ?string $callback_number = null,
    ): array {
        if (trim($message) === '') {
            return $this->emptyMessageError();
        }

        $result = $this->resolveDealOrError($deal_id);
        if (is_array($result)) {
            return $result;
        }
        $deal = $result;

        $message = trim($message);
        $forWhom = $this->normalizeOptional($for_whom);
        $callback = $this->normalizeOptional($callback_number);

        $body = $this->receptionistMessageBody($message, $forWhom, $callback);
        $recorded = new RecordDealNoteAction($deal)->execute($body, 'receptionist-note', $this->actingNoteUser()) !== null;

        return $this->finalizeTakenMessage($deal, 'deal_id', $message, $forWhom, $callback, $recorded);
    }
}
