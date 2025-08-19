<?php

declare(strict_types=1);

namespace Tests\Baka\Jobs;

use Baka\Jobs\LightHouseCacheCleanUpJob;
use Illuminate\Support\Facades\Queue;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

class LightHouseCacheCleanUpJobTest extends TestCase
{
    public function testJobCanBeSerializedAndDispatched(): void
    {
        Queue::fake();

        // Create a product with relationships loaded
        $product = Products::factory()->create();
        
        // Load relationships that might contain closures
        $product->load(['variants', 'categories', 'attributes']);

        // This should not throw a serialization error
        LightHouseCacheCleanUpJob::dispatch($product);

        Queue::assertPushed(LightHouseCacheCleanUpJob::class, function ($job) use ($product) {
            return true;
        });
    }

    public function testJobExecutesSuccessfully(): void
    {
        $product = Products::factory()->create();
        
        // Mock the clearLightHouseCache method
        $product = $this->getMockBuilder(Products::class)
            ->setConstructorArgs([])
            ->onlyMethods(['clearLightHouseCache'])
            ->getMock();
        
        $product->expects($this->once())
            ->method('clearLightHouseCache');

        $job = new LightHouseCacheCleanUpJob($product);
        $job->handle();
    }
}