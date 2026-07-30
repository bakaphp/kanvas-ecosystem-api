<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Add Message To Channel', category: 'social')]
class AddMessageToChannelTool extends Tool
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
        parent::__construct(
            name: 'add_message_to_channel',
            description: 'Attach an existing Social message to an existing Social channel in the current company. '
                . 'This does not create content and does not send SMS or email.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'message_id',
                type: PropertyType::INTEGER,
                description: 'ID returned by create_message.',
                required: true,
            ),
            new ToolProperty(
                name: 'channel_id',
                type: PropertyType::INTEGER,
                description: 'ID returned by create_entity_channel.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $message_id, int $channel_id): array
    {
        $message = Message::query()
            ->whereKey($message_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->first();
        $channel = Channel::query()
            ->whereKey($channel_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->first();

        if ($message === null || $channel === null) {
            return [
                'status' => 'error',
                'message' => 'The message or channel does not exist in the current app and company.',
            ];
        }

        try {
            $channel->addMessage($message, $message->user);
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'The message could not be added to the channel.',
            ];
        }

        return [
            'status' => 'success',
            'message_id' => $message->getId(),
            'channel_id' => $channel->getId(),
            'attached' => true,
        ];
    }
}
