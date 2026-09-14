<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentInstructionsAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentVersion;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The history exists so a bad edit is one copy back. That only holds if EVERY way of changing a
 * prompt is recorded — a history that covers the paths someone remembered to instrument is worse
 * than none, because it reads as complete.
 */
final class AgentPromptVersioningTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence'];

    /**
     * The plain-save path: no action, no tool, just somebody writing to the model the way the admin
     * UI, a GraphQL mutation or a console command does.
     */
    public function testADirectSaveIsRecordedWithoutAnyoneAskingForIt(): void
    {
        $agent = $this->agent(['instructions' => 'Original wording.']);

        $agent->instructions = 'Replaced wording.';
        $agent->save();

        $version = $this->latestVersion($agent);

        $this->assertNotNull($version, 'A direct save must still leave a history row.');
        $this->assertSame('Original wording.', $version->config['instructions']);
        $this->assertSame('Replaced wording.', $agent->refresh()->instructions);
    }

    /**
     * The snapshot has to hold what is being LOST. Recording the new value would produce a history of
     * things you still have, which restores nothing.
     */
    public function testTheSnapshotHoldsTheReplacedWordingNotTheNewOne(): void
    {
        $agent = $this->agent(['soul' => 'Old soul.', 'instructions' => 'Old steps.']);

        $agent->update(['soul' => 'New soul.', 'instructions' => 'New steps.']);

        $config = $this->latestVersion($agent)->config;

        $this->assertSame('Old soul.', $config['soul']);
        $this->assertSame('Old steps.', $config['instructions']);
        $this->assertArrayNotHasKey('name', $config, 'Only the fields that changed are snapshotted.');
    }

    public function testEditingSomethingThatIsNotAPromptRecordsNothing(): void
    {
        $agent = $this->agent(['instructions' => 'Unchanged.']);
        $before = $this->versionCount($agent);

        $agent->update(['description' => 'A new description, not a prompt.']);

        $this->assertSame($before, $this->versionCount($agent));
    }

    /**
     * Restoring is the whole point, so it is worth asserting it actually works rather than assuming
     * the row is enough.
     */
    public function testAnEarlierWordingCanBeCopiedBack(): void
    {
        $agent = $this->agent(['instructions' => 'The good wording.']);

        $agent->update(['instructions' => 'The accident.']);
        $this->assertSame('The accident.', $agent->refresh()->instructions);

        $agent->update($this->latestVersion($agent)->config);

        $this->assertSame('The good wording.', $agent->refresh()->instructions);
    }

    public function testEachEditGetsItsOwnNumberedSnapshotAndOnlyTheLastIsActive(): void
    {
        $agent = $this->agent(['instructions' => 'One.']);

        $agent->update(['instructions' => 'Two.']);
        $agent->update(['instructions' => 'Three.']);

        $versions = AgentVersion::query()
            ->where('agent_id', $agent->getId())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertSame(['1', '2'], $versions->pluck('version')->all());
        $this->assertSame('One.', $versions[0]->config['instructions']);
        $this->assertSame('Two.', $versions[1]->config['instructions']);
        $this->assertSame(
            [false, true],
            $versions->pluck('is_active')->all(),
            'Only the most recent snapshot is the active one.'
        );
    }

    /**
     * Without a supplied reason the row still has to say something useful — "who changed what" read
     * six months later is the entire value of the table.
     */
    public function testAnUninstrumentedEditStillSaysWhatChanged(): void
    {
        $agent = $this->agent(['soul' => 'A.', 'output_format' => 'B.']);

        $agent->update(['soul' => 'C.', 'output_format' => 'D.']);

        $changes = (string) $this->latestVersion($agent)->changes;

        $this->assertStringContainsString('soul', $changes);
        $this->assertStringContainsString('output_format', $changes);
    }

    public function testTheRetuneToolRecordsItsOwnReasonAndEditor(): void
    {
        $agent = $this->agent(['instructions' => 'Too noisy.']);
        $editor = Users::factory()->create();

        $version = new UpdateAgentInstructionsAction(
            agent: $agent,
            editedBy: $editor,
            reason: 'It was covering everything, not just launches.',
            instructions: 'Only cover product launches.',
        )->execute();

        $this->assertSame('It was covering everything, not just launches.', $version->changes);
        $this->assertSame($editor->getId(), (int) $version->created_by);
        $this->assertSame('Too noisy.', $version->config['instructions']);
        $this->assertSame('Only cover product launches.', $agent->refresh()->instructions);
        $this->assertSame(
            1,
            $this->versionCount($agent),
            'The explicit path must not write a second row on top of the observer.'
        );
    }

    private function agent(array $attributes = []): Agent
    {
        return Agent::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'user_id' => $this->currentUser()->getId(),
                'name' => 'Versioned ' . fake()->unique()->lexify('?????'),
                ...$attributes,
            ]);
    }

    private function latestVersion(Agent $agent): ?AgentVersion
    {
        return AgentVersion::query()
            ->where('agent_id', $agent->getId())
            ->orderByDesc('id')
            ->first();
    }

    private function versionCount(Agent $agent): int
    {
        return AgentVersion::query()->where('agent_id', $agent->getId())->count();
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
