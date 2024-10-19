<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Events;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ProductImportEvent implements ShouldBroadcast
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
        unset($this->result['exception']);

        return $this->result;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('app-' . $this->app->getId() . '-product-import-results-' . $this->company->getId());
    }

    public function broadcastAs(): string
    {
        return 'product.imports';
    }
}
