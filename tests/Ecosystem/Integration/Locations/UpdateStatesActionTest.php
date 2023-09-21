<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Locations\Actions\UpdateStatesAction;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class UpdateStatesActionTest extends TestCase
{
    /**
     * Test Update Or Create States.
     *
     * @return void
     */
    public function UpdateStatesAction(): void
    {
        $updateStates = new UpdateStatesAction();

        $this->assertTrue(
            $updateStates->execute(app(Apps::class))
        );
    }
}
