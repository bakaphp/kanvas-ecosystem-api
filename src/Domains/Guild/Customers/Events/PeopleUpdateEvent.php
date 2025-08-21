<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Customers\Models\People;
use Override;

class PeopleUpdateEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected People $people)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->people->id,
            'name' => $this->people->name,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('people-' . $this->people->uuid);
    }

    public function broadcastAs(): string
    {
        return 'people.updated';
    }
}
