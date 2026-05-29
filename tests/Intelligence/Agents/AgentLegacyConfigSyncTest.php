<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class AgentLegacyConfigSyncTest extends TestCase
{
    private function makeAgent(array $overrides = []): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_merge([
                'user_id' => $user->getId(),
                'is_active' => true,
            ], $overrides));
    }

    public function testSoulSyncsIntoRoleBackgroundOnCreate(): void
    {
        $agent = $this->makeAgent([
            'soul' => 'You are helpful.',
            'instructions' => 'Answer directly.',
            'output_format' => 'Plain text.',
        ]);

        $this->assertIsArray($agent->role);
        $this->assertSame('You are helpful.', $agent->role['background']);
        $this->assertSame('Answer directly.', $agent->role['steps']);
        $this->assertSame('Plain text.', $agent->role['output']);
    }

    public function testSoulSyncsIntoRoleBackgroundOnUpdate(): void
    {
        $agent = $this->makeAgent([
            'soul' => 'Old soul.',
            'role' => ['background' => 'Old soul.', 'something_else' => 'preserved'],
        ]);

        $agent->soul = 'New soul.';
        $agent->save();

        $agent->refresh();

        $this->assertSame('New soul.', $agent->role['background']);
        $this->assertSame('preserved', $agent->role['something_else']);
    }

    public function testRoleUntouchedWhenSoulNotDirty(): void
    {
        $agent = $this->makeAgent([
            'soul' => 'Persistent soul.',
            'role' => ['background' => 'Persistent soul.', 'custom' => 'value'],
        ]);

        $agent->name = 'Renamed Agent';
        $agent->save();

        $agent->refresh();

        $this->assertSame('Persistent soul.', $agent->role['background']);
        $this->assertSame('value', $agent->role['custom']);
    }

    public function testInstructionsAndOutputFormatSyncIndependently(): void
    {
        $agent = $this->makeAgent([
            'soul' => 'A soul.',
            'instructions' => 'Step one.',
            'output_format' => 'JSON.',
        ]);

        $agent->instructions = 'Step two.';
        $agent->save();
        $agent->refresh();

        $this->assertSame('A soul.', $agent->role['background']);
        $this->assertSame('Step two.', $agent->role['steps']);
        $this->assertSame('JSON.', $agent->role['output']);
    }
}
