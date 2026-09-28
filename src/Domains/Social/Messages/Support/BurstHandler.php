<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Support;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Services\MessageBurstService;

/**
 * What a channel does once its burst has closed — run an agent, fire a workflow, hand off to a
 * human. The debounce that decides *when* is shared; this is the part that is genuinely per-channel.
 *
 * Instantiated by name from FlushMessageBurstJob, so the job stays serializable: a Collection of
 * Eloquent models on the job's constructor would not survive the queue.
 */
abstract class BurstHandler
{
    /**
     * Final on purpose: FlushMessageBurstJob instantiates handlers by name, so a subclass that
     * widened the constructor would fatal at flush time rather than at the call site.
     *
     * @param Collection<int, Message> $messages
     */
    final public function __construct(
        protected readonly Apps $app,
        protected readonly Channel $channel,
        protected readonly Collection $messages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function execute(array $params = []): array;

    protected function head(): Message
    {
        return $this->messages->firstOrFail();
    }

    protected function prompt(): string
    {
        return MessageBurstService::promptFor($this->messages);
    }

    /**
     * @return list<int>
     */
    protected function messageIds(): array
    {
        return array_values(array_map('intval', $this->messages->pluck('id')->all()));
    }
}
