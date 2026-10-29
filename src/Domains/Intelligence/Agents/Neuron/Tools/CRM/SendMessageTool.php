<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * One tool for every channel, through Communication: the agent says who and on which channel;
 * which number or inbox it leaves from, and whether the person may be contacted at all, is decided
 * there and is not the agent's to choose.
 */
#[AgentTool(name: 'Send Message', category: 'crm')]
class SendMessageTool extends Tool
{
    use ResolvesPersonForTool;

    protected string $name = 'send_message';

    protected ?string $description = 'Send one message to a person on a channel (sms, email, whatsapp), or on every '
        . 'channel they can be reached on now ("all"). The address comes from the person\'s contacts and cannot '
        . 'be supplied. Use it only once the complete customer-facing message is ready, at most once per message. '
        . 'If it reports the person cannot be contacted, do not try another channel or retry.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'person_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the person (contact) to message.',
                required: true,
            ),
            new ToolProperty(
                name: 'channel',
                type: PropertyType::STRING,
                description: 'sms, email, whatsapp, or all.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The final customer-facing message to deliver.',
                required: true,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'Subject line, used by email only.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $person_id,
        string $channel,
        string $message,
        ?string $subject = null,
    ): array {
        $message = trim($message);

        if ($message === '') {
            return ['status' => 'error', 'message' => 'The message is empty. Write the complete message before calling send_message.'];
        }

        $person = $this->resolvePersonOrError($person_id);

        if (is_array($person)) {
            return $person;
        }

        $content = Content::text($message);
        $content = $subject !== null && trim($subject) !== '' ? $content->withSubject(trim($subject)) : $content;
        $agent = $this->contextAgent();
        $channel = strtolower(trim($channel));

        try {
            $operation = Communication::for($this->app, $this->company)->send(
                $person,
                $content,
                channels: $channel === 'all' ? 'all' : [$channel],
                from: $agent !== null ? Sender::agent($agent) : Sender::system(),
                sync: true
            );
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'error', 'message' => 'The message could not be sent right now. Do not retry automatically.'];
        }

        return [
            'status' => $operation->status() === 'failed' ? 'error' : 'success',
            'person_id' => $person->getId(),
            'operation_id' => $operation->id(),
            'deliveries' => $operation->deliveries()->map(fn (Delivery $delivery): array => [
                'channel' => $delivery->channel->value,
                'status' => $delivery->status->value,
                'reason' => $delivery->status === DeliveryStatusEnum::ACCEPTED ? null : $delivery->error_code,
            ])->all(),
        ];
    }
}
