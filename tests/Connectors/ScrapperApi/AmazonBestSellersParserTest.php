<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Kanvas\Connectors\ScrapperApi\Services\AmazonBestSellersParser;
use Tests\TestCase;

class AmazonBestSellersParserTest extends TestCase
{
    private function sampleMarkdown(): string
    {
        return <<<'MD'
        # Amazon Best Sellers

        ## Best Sellers in Clothing, Shoes & Jewelry

        [See More](/gp/bestsellers/fashion/ref=zg%5Fbs%5Ffashion%5Fsm)

        1. #1
        [![](https://images-na.ssl-images-amazon.com/images/I/81ZBOce1WwL._AC_UL225_SR225,160_.jpg)](/Hanes-Multipack/dp/B086KSDTQ4/ref=zg)
        [Hanes mens Underwear Boxer Briefs Pack, Cool & Breathable Cotton](/Hanes-Multipack/dp/B086KSDTQ4/ref=zg)
        [_4.5 out of 5 stars_ 136,561](/product-reviews/B086KSDTQ4/ref=zg)
        [$23.98](/Hanes-Multipack/dp/B086KSDTQ4/ref=zg)
        2. #2
        [![](https://images-na.ssl-images-amazon.com/images/I/61GzQfBa+lL._AC_UL225_SR225,160_.jpg)](/ATHMILE/dp/B09Q3MYDQH/ref=zg)
        [Water Shoes for Women Men Quick-Dry Aqua Socks](/ATHMILE/dp/B09Q3MYDQH/ref=zg)
        [_4.4 out of 5 stars_ 29,541](/product-reviews/B09Q3MYDQH/ref=zg)
        [$6.99](/ATHMILE/dp/B09Q3MYDQH/ref=zg)

        ## Best Sellers in Electronics

        [See More](/gp/bestsellers/electronics/ref=zg)

        1. #1
        [![](https://images-na.ssl-images-amazon.com/images/I/31YHGbJsldL._AC_UL225_SR225,160_.png)](/Blink-Plus/dp/B08JHCVHTY/ref=zg)
        [blink plus plan with monthly auto-renewal](/Blink-Plus/dp/B08JHCVHTY/ref=zg)
        [_4.4 out of 5 stars_ 278,200](/product-reviews/B08JHCVHTY/ref=zg)
        [$11.99](/Blink-Plus/dp/B08JHCVHTY/ref=zg)
        MD;
    }

    public function testGroupsProductsByCategory(): void
    {
        $parsed = AmazonBestSellersParser::parse($this->sampleMarkdown());

        $this->assertCount(2, $parsed);
        $this->assertSame('Clothing, Shoes & Jewelry', $parsed[0]['category']);
        $this->assertSame('Electronics', $parsed[1]['category']);
        $this->assertCount(2, $parsed[0]['products']);
        $this->assertCount(1, $parsed[1]['products']);
    }

    public function testExtractsAllProductFields(): void
    {
        $parsed = AmazonBestSellersParser::parse($this->sampleMarkdown());

        $this->assertSame([
            'rank' => 1,
            'asin' => 'B086KSDTQ4',
            'name' => 'Hanes mens Underwear Boxer Briefs Pack, Cool & Breathable Cotton',
            'image' => 'https://images-na.ssl-images-amazon.com/images/I/81ZBOce1WwL._AC_UL225_SR225,160_.jpg',
            'rating' => 4.5,
            'reviews' => 136561,
            'price' => 23.98,
        ], $parsed[0]['products'][0]);
    }

    public function testKeepsRankOrderWithinCategory(): void
    {
        $parsed = AmazonBestSellersParser::parse($this->sampleMarkdown());

        $ranks = array_column($parsed[0]['products'], 'rank');
        $this->assertSame([1, 2], $ranks);
    }

    public function testReturnsEmptyWhenNoBestSellerSections(): void
    {
        $this->assertSame([], AmazonBestSellersParser::parse('# Just a page with no best sellers'));
    }
}
