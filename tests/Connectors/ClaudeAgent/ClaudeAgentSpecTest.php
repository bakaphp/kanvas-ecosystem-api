<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\ClaudeAgent\DataTransferObject\ClaudeAgentSpec;
use Tests\TestCase;

final class ClaudeAgentSpecTest extends TestCase
{
    use DatabaseTransactions;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testEmptyOptionalFieldsAreOmittedFromThePayload(): void
    {
        $payload = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5')->toPayload();

        $this->assertSame(['name' => 'Agent', 'model' => 'claude-opus-5'], $payload);
        $this->assertArrayNotHasKey('system', $payload);
        $this->assertArrayNotHasKey('tools', $payload);
    }

    public function testPopulatedFieldsReachThePayload(): void
    {
        $payload = new ClaudeAgentSpec(
            name: 'Agent',
            model: 'claude-opus-5',
            system: 'You are helpful.',
            description: 'A teammate.',
            tools: [['type' => 'agent_toolset_20260401']],
        )->toPayload();

        $this->assertSame('You are helpful.', $payload['system']);
        $this->assertSame('A teammate.', $payload['description']);
        $this->assertSame([['type' => 'agent_toolset_20260401']], $payload['tools']);
    }

    /**
     * The fingerprint gates whether we push a new remote version. If PHP's array literal order moved
     * it, reordering a field in source would mint a pointless version on every agent.
     */
    public function testFingerprintIgnoresKeyOrderInNestedMaps(): void
    {
        $a = new ClaudeAgentSpec(
            name: 'Agent',
            model: 'claude-opus-5',
            tools: [['type' => 'custom', 'name' => 'search', 'description' => 'Search']],
        );
        $b = new ClaudeAgentSpec(
            name: 'Agent',
            model: 'claude-opus-5',
            tools: [['description' => 'Search', 'name' => 'search', 'type' => 'custom']],
        );

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * List order is semantic (tool precedence), so unlike map keys it must move the fingerprint.
     */
    public function testFingerprintRespectsListOrder(): void
    {
        $a = new ClaudeAgentSpec(
            name: 'Agent',
            model: 'claude-opus-5',
            tools: [['type' => 'a'], ['type' => 'b']],
        );
        $b = new ClaudeAgentSpec(
            name: 'Agent',
            model: 'claude-opus-5',
            tools: [['type' => 'b'], ['type' => 'a']],
        );

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testFingerprintChangesWhenTheSystemPromptChanges(): void
    {
        $before = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5', system: 'You are helpful.');
        $after = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5', system: 'You are terse.');

        $this->assertNotSame($before->fingerprint(), $after->fingerprint());
    }

    public function testFingerprintChangesWhenTheModelChanges(): void
    {
        $before = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5');
        $after = new ClaudeAgentSpec(name: 'Agent', model: 'claude-sonnet-5');

        $this->assertNotSame($before->fingerprint(), $after->fingerprint());
    }

    /**
     * An absent optional field and an empty one describe the same effective agent, so they must not
     * produce two different fingerprints — otherwise clearing a description would push a version.
     */
    public function testBlankAndAbsentOptionalFieldsFingerprintIdentically(): void
    {
        $absent = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5');
        $blank = new ClaudeAgentSpec(name: 'Agent', model: 'claude-opus-5', system: '', description: '');

        $this->assertSame($absent->fingerprint(), $blank->fingerprint());
    }
}
