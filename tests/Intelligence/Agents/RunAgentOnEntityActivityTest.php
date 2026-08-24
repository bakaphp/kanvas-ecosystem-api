<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\RunAgentOnEntityActivity;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RunAgentOnEntityActivityTest extends TestCase
{
    use DatabaseTransactions;

    // No `workflow` connection: the catalog assertion reads the attribute by reflection, not the table.
    protected $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    public function testMissingAgentIdFailsTheWorkflowInsteadOfThrowing(): void
    {
        $activity = $this->activity();

        $result = $activity->execute($this->lead(), $this->kanvasApp(), ['instruction' => 'Do the thing']);

        $this->assertStringContainsString('agent_id', $result['message']);
        $this->assertSame(StatusEnum::FAILED, $this->statusOf($activity));
    }

    /**
     * Two modes, chosen by whether the rule sets an instruction: framed, or straight through. The
     * agent already knows its job from its own instructions, so straight-through is the common case
     * and a preamble is for when this particular workflow needs to frame the record.
     */
    public function testTheRecordGoesStraightThroughWhenNoInstructionIsSet(): void
    {
        $activity = $this->activity();
        $lead = $this->lead();

        $prompt = new ReflectionMethod($activity, 'wakePrompt')->invoke($activity, $lead, '');

        $this->assertStringNotContainsString('woken', $prompt);
        $this->assertStringNotContainsString('#' . $lead->getKey(), $prompt);
    }

    public function testAnInstructionIsPrependedToTheRecordWhenSet(): void
    {
        $activity = $this->activity();
        $lead = $this->lead();

        $prompt = new ReflectionMethod($activity, 'wakePrompt')
            ->invoke($activity, $lead, 'This is a support channel. Decide whether it needs a reply.');

        $this->assertStringStartsWith('This is a support channel.', $prompt);
    }

    public function testAnUnknownAgentFailsTheWorkflow(): void
    {
        $activity = $this->activity();

        $result = $activity->execute(
            $this->lead(),
            $this->kanvasApp(),
            ['agent_id' => 99999999, 'instruction' => 'Do the thing']
        );

        $this->assertStringContainsString('not found', $result['message']);
        $this->assertSame(StatusEnum::FAILED, $this->statusOf($activity));
    }

    /**
     * The loop guard. A rule on Message/created whose agent writes a Message would otherwise wake the
     * agent on its own output, forever — and that is the natural shape of every "read this, write
     * that" workflow this activity exists to enable.
     */
    public function testAnAgentIsNotWokenOnARecordItCreatedItself(): void
    {
        $agent = $this->agent();
        $lead = $this->lead();
        $lead->users_id = $agent->user_id;

        $activity = $this->activity();
        $result = $this->wake($activity, $agent, $lead);

        $this->assertStringContainsString('created by this agent', $result['message']);
        $this->assertNull($result['entity']);
        $this->assertSame(StatusEnum::FAILED, $this->statusOf($activity));
    }

    /**
     * The WhatsApp path fires on the CHANNEL and passes the arriving message in `params.message`.
     * Judging the channel instead would compare the loop guard against the channel's owner rather
     * than the message's author — disabling the guard on the one path it matters most.
     */
    public function testAChannelTriggerPutsTheArrivingMessageInScopeNotTheChannel(): void
    {
        $activity = $this->activity();
        $lead = $this->lead();
        $channel = $this->lead();

        $resolve = new ReflectionMethod($activity, 'recordInScope');

        $this->assertSame(
            $lead->getKey(),
            $resolve->invoke($activity, $channel, ['message' => $lead])->getKey(),
            'The message from params must win over the rule entity.'
        );
    }

    public function testARecordTriggerKeepsTheEntityInScope(): void
    {
        $activity = $this->activity();
        $lead = $this->lead();

        $resolve = new ReflectionMethod($activity, 'recordInScope');

        $this->assertSame($lead->getKey(), $resolve->invoke($activity, $lead, [])->getKey());
    }

    public function testAnInactiveAgentIsNotWoken(): void
    {
        $agent = $this->agent();
        $agent->is_active = false;

        $activity = $this->activity();
        $result = $this->wake($activity, $agent, $this->lead());

        $this->assertStringContainsString('not active', $result['message']);
        $this->assertSame(StatusEnum::FAILED, $this->statusOf($activity));
    }

    public function testItIsCataloguedAsAWorkflowStepWithItsRequiredParams(): void
    {
        $entry = null;

        foreach (new WorkflowActionDiscoveryService()->discover() as $candidate) {
            if ($candidate['class'] === RunAgentOnEntityActivity::class) {
                $entry = $candidate;

                break;
            }
        }

        $this->assertNotNull($entry, 'The activity is missing from the workflow catalog.');
        $this->assertSame(ActionKindEnum::WORKFLOW->value, $entry['kind']);
        $this->assertSame(['agent_id'], $entry['required_params']);
        $this->assertArrayHasKey('agent_id', $entry['params']);
        $this->assertArrayHasKey('instruction', $entry['params']);
        $this->assertStringContainsString('does NOT reply', $entry['description']);
    }

    /**
     * The guarantee is structural rather than asserted at runtime: the kernel only posts back when it
     * is handed a source channel or message, so this activity must never pass either.
     */
    public function testItNeverHandsTheKernelAReplyTarget(): void
    {
        $source = file_get_contents(
            base_path('src/Domains/Intelligence/Agents/Activities/RunAgentOnEntityActivity.php')
        );

        $this->assertStringNotContainsString('sourceChannel:', $source);
        $this->assertStringNotContainsString('sourceMessage:', $source);
        $this->assertStringContainsString('persistConversation: false', $source);
    }

    /**
     * `KanvasActivity` inherits the durable-workflow `Activity` constructor, which needs runtime
     * arguments a unit test has no business building — the activity's own logic takes none.
     */
    private function activity(): RunAgentOnEntityActivity
    {
        return new ReflectionClass(RunAgentOnEntityActivity::class)->newInstanceWithoutConstructor();
    }

    private function wake(RunAgentOnEntityActivity $activity, Agent $agent, Lead $lead): array
    {
        $wake = new ReflectionMethod($activity, 'wake');

        return $wake->invoke($activity, $agent, $lead, 'Judge this record.');
    }

    private function statusOf(RunAgentOnEntityActivity $activity): ?StatusEnum
    {
        $status = new ReflectionProperty($activity, 'workflowStatus');

        return $status->getValue($activity);
    }

    private function agent(): Agent
    {
        $user = $this->currentUser();

        return Agent::factory()
            ->withAppId($this->kanvasApp()->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);
    }

    private function lead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp()->getId(), $this->currentUser()->getCurrentCompany()->getId())
            ->create();
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
