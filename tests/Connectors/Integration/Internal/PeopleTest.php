<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Internal;

use Kanvas\Connectors\Internal\Actions\ExtractCompanyNameFromEmailAction;
use Tests\TestCase;

final class PeopleTest extends TestCase
{
    public function testExtractEmailCompanyName()
    {
        $email = 'test@kanvas.dev';

        $extractCompanyNameFromEmailAction = new ExtractCompanyNameFromEmailAction();

        $companyName = $extractCompanyNameFromEmailAction->execute($email);

        $this->assertNotNull($companyName);
    }

    public function testExtractEmailCompanyNamePublicProvider()
    {
        $email = fake()->email;

        $extractCompanyNameFromEmailAction = new ExtractCompanyNameFromEmailAction();
        $companyName = $extractCompanyNameFromEmailAction->execute($email);

        $this->assertNull($companyName);
    }
}
