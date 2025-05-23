<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Events;

use Baka\Contracts\AppInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Inventory\Products\Models\Products;

class ProductScrapperEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    public $connection = 'sync';

    public function __construct(
        protected AppInterface $app,
        protected string $uuid,
        protected Products $product,
        protected float $price,
        protected ?string $shopifyProductId = null,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'kanvas_product_id' => $this->product->getId(),
            'shopify_product_id' => $this->shopifyProductId,
            'sku' => $this->product->variants()->first()->sku,
            'title' => $this->product->name,
            'image' => $this->product->getFiles()[0]->url,
            'price' => $this->price,
            'images' => $this->product->getFiles(),
            'discounted_price' => 0
        ];
    }

    public function broadcastOn(): Channel
    {
        return new Channel('app-' . $this->app->getId() . '-scrapper-' . $this->uuid);
    }

    public function broadcastQueue(): string
    {
        return 'default';
    }
}
