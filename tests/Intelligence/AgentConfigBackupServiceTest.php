<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Services\AgentConfigBackupService;
use Tests\TestCase;

final class AgentConfigBackupServiceTest extends TestCase
{
    public function testUploadKeyUsesAppKeyAndHasNoEmptySegment(): void
    {
        Storage::fake('agent-config-backups');

        $app = app(Apps::class);
        $agent = AgentFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId(0)
            ->create();

        $path = new AgentConfigBackupService()->upload($agent, $app, ['manifest' => true]);

        // The app segment must be the app's `key` (Apps has no `uuid` column) — a null
        // segment produced the `config-backups//<agent-uuid>/...` double-slash bug.
        $this->assertStringContainsString("config-backups/{$app->key}/{$agent->uuid}/", $path);
        $this->assertStringNotContainsString('//', $path);
        Storage::disk('agent-config-backups')->assertExists($path);
    }
}
