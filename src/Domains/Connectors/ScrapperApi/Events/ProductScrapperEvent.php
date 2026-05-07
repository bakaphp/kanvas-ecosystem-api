<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Events;

use Baka\Contracts\AppInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Inventory\Products\Models\Products;
use Override;

class ProductScrapperEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    public $connection = 'sync';

    public function __construct(
        protected AppInterface $app,
        protected string $uuid,
        protected array $product,
        protected float $price,
        protected ?string $searchText = null
    ) {
    }

    public function broadcastWith(): array
    {
        $product = Products::getById($this->product['id']);
        return [
            'kanvas_product_id' => $product->getId(),
            'sku' => $product->variants()->first()->sku,
            'variant_id' => $product->variants()->first()->getId(),
            'title' => $product->name,
            'image' => $product->getFiles()[0]->url,
            'price' => $this->price,
            'slug' => $product->slug,
            'images' => $product->getFiles(),
            'discounted_price' => 0,
        ];
    }

    #[Override]
    public function broadcastOn(): Channel
    {
        if (! empty($this->searchText)) {
            return new Channel('app-' . $this->app->getId() . '-scrapper-' . md5(trim($this->searchText)));
        }

        return new Channel('app-' . $this->app->getId() . '-scrapper-' . $this->uuid);
    }

    public function broadcastQueue(): string
    {
        return 'default';
    }
}
