<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Locations\Actions\UpdateCountriesAction;
use Kanvas\Apps\Models\Apps;
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
        $updateCountries = new UpdateCountriesAction(app(Apps::class));

        $this->assertTrue(
            $updateCountries->execute()
        );
    }
}
