<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\Locations\Actions\UpdateStatesAction;
use Tests\TestCase;

final class UpdateOrCreateStatesText extends TestCase
{
    /**
     * Test Update Or Create States.
     *
     * @return void
     */
    public function UpdateOrCreateStatesAction(): void
    {
        $updateStates = new UpdateStatesAction();

        $this->assertTrue(
            $updateStates->execute()
        );
    }
}
