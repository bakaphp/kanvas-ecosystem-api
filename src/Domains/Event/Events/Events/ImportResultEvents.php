<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Events;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ImportResultEvents implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected array $result
    ) {
    }

    public function broadcastWith(): array
    {
        $result = $this->result;
        unset($result['exception']);

        return $result;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('app-' . $this->app->getId() . '-import-results-' . $this->company->getId());
    }

    public function broadcastAs(): string
    {
        return 'events.imports';
    }
}
