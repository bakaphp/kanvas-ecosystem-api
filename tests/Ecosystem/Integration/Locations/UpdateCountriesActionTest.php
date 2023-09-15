<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Locations\Actions\UpdateCountriesAction;
use Tests\TestCase;

final class UpdateCountriesActionTest extends TestCase
{
    /**
     * Test Update Or Create Countries.
     *
     * @return void
     */
    public function UpdateCountriesAction(): void
    {
        $updateCountries = new UpdateCountriesAction();

        $this->assertTrue(
            $updateCountries->execute()
        );
    }
}
