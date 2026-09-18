<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

trait LoadsParkingApplicationFixture
{
    /** @return array<string, mixed> the custom_fields block of the reference receiver payload */
    protected function fixtureFields(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/Fixtures/parking_application_custom_fields.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
