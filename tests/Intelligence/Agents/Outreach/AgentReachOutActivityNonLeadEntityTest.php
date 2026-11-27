<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\WorkflowStub;

/**
 * A rule on another entity (a channel) can name the reach-out. The queued path builds the missing
 * Lead from the container and shifts every argument one slot, which was a TypeError on each retry.
 */
final class AgentReachOutActivityNonLeadEntityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'social'];

    /**
     * @param class-string<AgentReachOutActivity> $activityClass
     */
    #[DataProvider('reachOutActivities')]
    public function testAQueuedRunOnAnEntityThatIsNotALeadIsSkipped(string $activityClass): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $channel = Channel::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'SMS',
            'slug' => 'reach-out-non-lead-' . Str::uuid(),
            'description' => 'test',
        ]);
        $storedWorkflow = WorkflowStub::make(DynamicRuleWorkflow::class);

        $result = new $activityClass(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::findOrFail($storedWorkflow->id()),
            $channel,
            $app,
            []
        )->handle();

        $this->assertTrue($result['skipped']);
        $this->assertSame('Not a lead', $result['message']);
    }

    /**
     * @return array<string, array{class-string<AgentReachOutActivity>}>
     */
    public static function reachOutActivities(): array
    {
        return [
            'agent reach out' => [AgentReachOutActivity::class],
            'sales assist reach out' => [SalesAssistAgentReachOutActivity::class],
        ];
    }
}
