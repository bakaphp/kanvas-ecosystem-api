<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ImportResultEvents implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected array $result
    ) {
    }

    public function broadcastWith(): array
    {
        $result = $this->result;
        unset($result['user'], $result['company'], $result['exception']);

        return $result;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('importer-' . $this->result['jobUuid']);
    }

    public function broadcastAs(): string
    {
        return 'events.imports';
    }
}
