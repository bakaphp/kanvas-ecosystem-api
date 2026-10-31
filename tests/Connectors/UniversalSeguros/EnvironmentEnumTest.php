<?php

declare(strict_types=1);

namespace Tests\Connectors\UniversalSeguros;

use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;
use PHPUnit\Framework\TestCase;

final class EnvironmentEnumTest extends TestCase
{
    public function testQaQuotesThroughTheServiceplattformiaBase(): void
    {
        $this->assertSame(
            'https://qa.universal.com.do/rest/serviceplattformia',
            EnvironmentEnum::QA->apiBaseUrl()
        );
    }

    public function testProdKeepsItsOwnBase(): void
    {
        $this->assertSame(
            'https://api.universal.com.do/rest/serviceplattform',
            EnvironmentEnum::PROD->apiBaseUrl()
        );
    }
}
