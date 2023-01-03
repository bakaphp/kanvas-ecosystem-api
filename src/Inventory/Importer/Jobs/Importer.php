<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Importer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PHPShopify\ShopifySDK;
use Shopify\Context;
use Shopify\Auth\FileSessionStorage;
use Kanvas\Inventory\Shopify\Client;
use Spatie\LaravelData\Data;

class Importer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
    }
}
