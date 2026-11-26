<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices\Stubs;

use Kanvas\Scribe\Invoices\Contracts\CreditRequestFormParserInterface;
use Override;

final class FakeCreditRequestFormParser implements CreditRequestFormParserInterface
{
    #[Override]
    public function parse(string $localFilePath): array
    {
        return [
            'customer_name' => 'Northwind',
            'region' => null,
            'tenant' => null,
            'request_reference_no' => 'Spring Rebate',
            'lines' => [],
            'total' => 0.0,
        ];
    }
}
