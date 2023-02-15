<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\SystemModules;

use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\SystemModules\Actions\CreateInCurrentAppAction;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class SystemModulesActionsTest extends TestCase
{
    public function testCreateInCurrentApp(): void
    {
        $systemModules = new CreateInCurrentAppAction(app(Apps::class));

        $this->assertInstanceOf(
            SystemModules::class,
            $systemModules->execute(Notifications::class)
        );
    }
}
