<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Tests\TestCase;

class ScrapperRepositoryExtractAsinsTest extends TestCase
{
    public function testExtractsAsinsInPageOrderWithoutDuplicates(): void
    {
        $markdown = <<<'MD'
        # Best Sellers in Cell Phones & Accessories
        1. [Case A](https://www.amazon.com/Some-Case/dp/B0AAAAAAAA/ref=zg_bs)
        2. [Charger B](https://www.amazon.com/Fast-Charger/gp/product/B0BBBBBBBB/)
        3. [Case A again](https://www.amazon.com/Some-Case/dp/B0AAAAAAAA/)
        4. [Cable C](https://www.amazon.com/USB-Cable/dp/B0CCCCCCCC/ref=zg_bs)
        MD;

        $asins = ScrapperRepository::extractAsins($markdown);

        $this->assertSame(['B0AAAAAAAA', 'B0BBBBBBBB', 'B0CCCCCCCC'], $asins);
    }

    public function testRespectsLimit(): void
    {
        $markdown = '/dp/B000000001 /dp/B000000002 /dp/B000000003';

        $this->assertSame(['B000000001', 'B000000002'], ScrapperRepository::extractAsins($markdown, 2));
    }

    public function testReturnsEmptyWhenNoAsins(): void
    {
        $this->assertSame([], ScrapperRepository::extractAsins('no products here'));
    }
}
