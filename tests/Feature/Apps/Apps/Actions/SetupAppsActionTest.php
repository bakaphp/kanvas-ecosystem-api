<?php
declare(strict_types=1);

namespace Tests\Feature\Apps\Apps\Actions;

use Kanvas\Apps\Apps\Actions\SetupAppsAction;
use Kanvas\Apps\Apps\Models\Apps;
use Tests\TestCase;

final class SetupAppsActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     *
     * @return void
     */
    public function testCreateAppsAction() : void
    {
        $app = Apps::factory()->create();
        $setup = new SetupAppsAction($app);

        $this->assertInstanceOf(
            Apps::class,
            $setup->execute()
        );
    }
}
