<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Products\Models\Products;
use Override;

class ProductUpdateEvent implements ShouldBroadcast
{
    use SerializesModels;
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(protected Products $product)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->product->id,
            'name' => $this->product->name,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        return new Channel('product-' . $this->product->uuid);
    }

    public function broadcastAs(): string
    {
        return 'product.updated';
    }
}
