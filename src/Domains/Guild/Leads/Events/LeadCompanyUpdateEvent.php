<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Guild\Leads\Models\Lead;
use Override;

class LeadCompanyUpdateEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected Lead $lead)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->lead->id,
            'uuid' => $this->lead->uuid,
            'title' => $this->lead->title,
            'people' => [
                'id' => $this->lead->people?->id,
                'name' => $this->lead->people?->name,
            ],
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('company-' . $this->lead->companies_id . '-app-' . $this->lead->apps_id . '-leads');
    }

    public function broadcastAs(): string
    {
        return 'lead.updated';
    }
}
