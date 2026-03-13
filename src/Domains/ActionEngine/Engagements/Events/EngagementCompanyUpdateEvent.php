<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Override;

class EngagementCompanyUpdateEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected Engagement $engagement)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->engagement->id,
            'uuid' => $this->engagement->uuid,
            'lead_id' => $this->engagement->leads_id,
            'people_id' => $this->engagement->people_id,
            'action' => $this->engagement->companyAction?->name,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('company-' . $this->engagement->companies_id . '-app-' . $this->engagement->apps_id . '-engagements');
    }

    public function broadcastAs(): string
    {
        return 'engagement.updated';
    }
}
