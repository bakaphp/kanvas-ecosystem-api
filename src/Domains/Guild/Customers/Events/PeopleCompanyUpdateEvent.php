<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Guild\Customers\Models\People;
use Override;

class PeopleCompanyUpdateEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected People $people
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->people->id,
            'uuid' => $this->people->uuid,
            'name' => $this->people->name,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('company-' . $this->people->companies_id . '-app-' . $this->people->apps_id . '-peoples');
    }

    public function broadcastAs(): string
    {
        return 'people.updated';
    }
}
