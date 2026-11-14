<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Actions\RespondToInboundMessageAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

/**
 * An agent's turn on an inbound message. Queued by the inbound pipeline itself once the message
 * (or the burst it closes) is filed, so answering needs no workflow rule to exist.
 */
final class RespondToInboundMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Channel $channel,
        public readonly Message $message,
        public readonly ?string $burstText = null
    ) {
        // A turn is a model call that can run for minutes; these workers have the timeout for it.
        $this->onQueue('workflow');
    }

    /**
     * Only a message on a connection an agent owns is worth a job; the turn is cleared again when it
     * runs, because the connection can be switched off or handed over in between.
     */
    public static function dispatchIfOwned(Channel $channel, Message $message, ?string $burstText = null): void
    {
        if (Connection::forFiledMessage($message)?->agent() !== null) {
            self::dispatch($channel, $message, $burstText);
        }
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->message->app);

        $result = new RespondToInboundMessageAction(
            $this->channel,
            $this->message,
            array_filter(['burst_text' => $this->burstText])
        )->execute();

        // Left on the message, where the conversation shows it, like one the route would not answer.
        if (isset($result['reason'])) {
            $this->message->addTag('communication-' . $result['reason']);
        }
    }
}
