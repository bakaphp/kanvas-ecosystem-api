<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;

/**
 * A filed message becomes a turn: the workflow event, which the app default rule answers with
 * RespondToInboundMessageActivity (the agent that owns the connection replies) and any rule a
 * tenant wrote also hears.
 */
final class AnnounceInboundTurnAction
{
    /**
     * @param string $text what the turn says: the message's text, or a closed burst's as one prompt
     * @param array<string, mixed> $context what a rule's activities get beside the message: the
     *                                      medium, a closed burst's parts
     * @param string|null $burstText the prompt the agent answers, when it is not the message's own text
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly Message $message,
        private readonly string $text,
        private readonly array $context = [],
        private readonly ?string $burstText = null
    ) {
    }

    public function execute(): void
    {
        $this->channel->fireWorkflow(
            WorkflowEnum::AFTER_ADDING_MESSAGE_TO_CHANNEL->value,
            true,
            [
                'message' => $this->message,
                'user' => $this->message->user,
                'app' => $this->message->app,
                'company' => $this->message->company,
                'text' => $this->text,
                ...$this->context,
                ...array_filter(['burst_text' => $this->burstText]),
            ]
        );
    }
}
