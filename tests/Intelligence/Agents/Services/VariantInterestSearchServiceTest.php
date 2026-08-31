<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Services\VariantInterestSearchService;
use Kanvas\Intelligence\Agents\Services\VariantSearchService;
use Mockery;
use Tests\TestCase;

class VariantInterestSearchServiceTest extends TestCase
{
    public function testFiltersGenericVariantAttributes(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $variantSearch = Mockery::mock(VariantSearchService::class);
        $variantSearch->expects('search')
            ->with(
                $app,
                $company,
                'truck',
                1000
            )
            ->andReturn([
                ['id' => 10, 'price' => 28000, 'attributes' => ['Condition' => 'Used', 'Type' => 'Truck']],
                ['id' => 11, 'price' => 42000, 'attributes' => ['Condition' => 'Used', 'Type' => 'Truck']],
                ['id' => 12, 'price' => 25000, 'attributes' => ['Condition' => 'New', 'Type' => 'Truck']],
            ]);

        $matches = (new VariantInterestSearchService($variantSearch))->resolve(
            $app,
            $company,
            'truck',
            ['condition:used', 'type:truck'],
        );

        $this->assertSame([10, 11], array_column($matches, 'id'));
    }

    public function testUsesWildcardWhenOnlyAttributesAreProvided(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $variantSearch = Mockery::mock(VariantSearchService::class);
        $variantSearch->expects('search')->with(
            $app,
            $company,
            '*',
            1000
        )->andReturn([]);

        $matches = (new VariantInterestSearchService($variantSearch))->resolve(
            $app,
            $company,
            '',
            ['color:red'],
        );

        $this->assertSame([], $matches);
    }
}
