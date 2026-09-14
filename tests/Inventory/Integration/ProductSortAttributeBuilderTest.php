<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use InvalidArgumentException;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

final class ProductSortAttributeBuilderTest extends TestCase
{
    private const INJECTION_PAYLOAD = "x' AS attribute_name FROM users WHERE (SELECT SLEEP(5)) OR name = ? -- ";

    public function testAttributeNameIsBoundNotInterpolated(): void
    {
        $query = Products::query()->orderByAttribute(self::INJECTION_PAYLOAD, 'STRING', 'ASC');

        $this->assertStringNotContainsString('SLEEP(5)', $query->toSql());
        $this->assertStringContainsString('select ? as attribute_name', $query->toSql());
        $this->assertContains(self::INJECTION_PAYLOAD, $query->getBindings());
    }

    public function testVariantAttributeNameIsBoundNotInterpolated(): void
    {
        $query = Products::query()->orderByVariantAttribute(self::INJECTION_PAYLOAD, 'NUMERIC', 'DESC');

        $this->assertStringNotContainsString('SLEEP(5)', $query->toSql());
        $this->assertStringContainsString('select ? as attribute_name', $query->toSql());
        $this->assertContains(self::INJECTION_PAYLOAD, $query->getBindings());
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Products::query()->orderByAttribute('color', "BAD' UNION SELECT 1 -- ", 'ASC');
    }

    public function testLowercaseSortIsAccepted(): void
    {
        $this->assertStringContainsString(
            'order by `attribute_name` asc, `attribute_value` desc',
            Products::query()->orderByAttribute('color', 'STRING', 'desc')->toSql()
        );

        $this->assertStringContainsString(
            'order by `attribute_name` asc, `attribute_value` desc',
            Products::query()->orderByVariantAttribute('color', 'STRING', 'desc')->toSql()
        );
    }

    public function testInvalidSortIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Products::query()->orderByAttribute('color', 'STRING', 'ASC; DROP TABLE products');
    }

    public function testSortedQueryRuns(): void
    {
        $products = Products::query()
            ->orderByAttribute('color', 'STRING', 'ASC')
            ->limit(1)
            ->get();

        $this->assertNotNull($products);
    }
}
