<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Guild\Leads\Models\Lead;
use Override;

class LeadUpdateEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected Lead $lead)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->lead->id,
            'title' => $this->lead->title,
            'people' => [
                'name' => $this->lead->people->name,
            ],
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('lead-' . $this->lead->uuid);
    }

    public function broadcastAs(): string
    {
        return 'lead.updated';
    }
}
