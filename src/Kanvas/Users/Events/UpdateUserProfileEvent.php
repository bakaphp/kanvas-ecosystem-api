<?php

declare(strict_types=1);

namespace Kanvas\Users\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Users\Models\Users;
use Override;

class UpdateUserProfileEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected Users $user,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('Changes detected for user: ' . $this->user->getId());
    }

    public function broadcastAs(): string
    {
        return 'user.profile.updated';
    }
}
