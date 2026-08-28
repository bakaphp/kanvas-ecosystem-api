<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class CheckAgentToolDriftCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    /**
     * A row whose handler class no longer exists is the drift that actually misleads an agent: the
     * class moved namespace, sync created a row at the new one, and the dead row keeps answering
     * capability questions alongside it.
     */
    public function test_fails_when_a_catalog_row_points_at_a_class_that_does_not_exist(): void
    {
        Tool::create([
            'apps_id' => 0,
            'name' => 'Zzqq Ghost Tool',
            'description' => 'A catalog row whose class was deleted.',
            'handler' => 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Zzqq\\GhostTool',
            'tool_type' => 'system',
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);

        $this->artisan('kanvas:nervous-system:check-tool-drift')
            ->expectsOutputToContain('Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Zzqq\\GhostTool')
            ->assertExitCode(1);
    }

    /** --allow-stale downgrades attribute differences only; an orphan is still a hard failure. */
    public function test_allow_stale_does_not_excuse_an_orphaned_row(): void
    {
        Tool::create([
            'apps_id' => 0,
            'name' => 'Zzqq Ghost Tool',
            'description' => 'A catalog row whose class was deleted.',
            'handler' => 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Zzqq\\GhostTool',
            'tool_type' => 'system',
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);

        $this->artisan('kanvas:nervous-system:check-tool-drift --allow-stale')
            ->assertExitCode(1);
    }

    /** A row whose description no longer matches its class is stale, not orphaned. */
    public function test_fails_when_a_row_description_has_drifted_from_its_class(): void
    {
        $handler = 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\Capability\\CapabilityLookupTool';

        Tool::query()->where('apps_id', 0)->where('handler', $handler)->delete();

        Tool::create([
            'apps_id' => 0,
            'name' => 'Capability Lookup',
            'description' => 'Deliberately wrong description so the row disagrees with the class.',
            'handler' => $handler,
            'tool_type' => 'system',
            'frameworks' => ['neuron', 'claude'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);

        $this->artisan('kanvas:nervous-system:check-tool-drift')
            ->expectsOutputToContain('Stale rows')
            ->assertExitCode(1);
    }
}
