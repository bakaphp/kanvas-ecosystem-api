<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Support\Setup;
use Tests\TestCase;

final class SetupTest extends TestCase
{
    public function testInitializeANewCompany(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );

        $this->assertTrue($setupCompany->run());
    }
}
