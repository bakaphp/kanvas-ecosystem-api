<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class ClearChannelMessagesAction
{
    public function __construct(
        protected readonly Channel $channel,
    ) {
    }

    public function execute(): bool
    {
        return $this->runTransaction(function (): bool {
            /** @var Collection<int, Message> $exclusiveMessages */
            $exclusiveMessages = $this->channel->messages()
                ->withCount('channels')
                ->get()
                ->filter(fn (Message $message) => $message->channels_count === 1)
                ->values();

            $this->channel->messages()->detach();
            $this->channel->last_message_id = null;
            $this->channel->saveOrFail();

            $exclusiveMessages->each(
                fn (Message $message): bool => $message->delete()
            );

            return true;
        });
    }

    protected function runTransaction(callable $callback): bool
    {
        return DB::connection('social')->transaction($callback);
    }
}
