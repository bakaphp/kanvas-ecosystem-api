<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\ConfigureWhatsAppGroupsAction;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupReplyModeEnum;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * PR4: the allow-list is opt-in, written onto the agent's receiver, and validated — a bare phone
 * number silently allow-lists nothing, since a group thread is only ever addressed by its @g.us JID.
 */
final class ConfigureWhatsAppGroupsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow', 'intelligence'];

    private const string GROUP_JID = '15550001111-1700000000@g.us';

    public function testAllowListAndReplyModeLandOnTheReceiver(): void
    {
        [$agent, $receiver] = $this->connectedAgent();

        $result = new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [self::GROUP_JID],
            replyMode: GroupReplyModeEnum::ALWAYS,
        )->execute();

        $receiver->refresh();

        $this->assertSame([self::GROUP_JID], $result['allowed_group_jids']);
        $this->assertSame('always', $result['reply_mode']);
        $this->assertSame([self::GROUP_JID], GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver));
        $this->assertSame('always', GroupConfigEnum::GROUP_REPLY_MODE->get($receiver));
    }

    /**
     * Reply mode governs only the posting back, so leaving it alone must not disturb the list.
     */
    public function testOmittingAFieldLeavesItUntouched(): void
    {
        [$agent, $receiver] = $this->connectedAgent();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [self::GROUP_JID],
            replyMode: GroupReplyModeEnum::NEVER,
        )->execute();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            replyMode: GroupReplyModeEnum::MENTION,
        )->execute();

        $receiver->refresh();

        $this->assertSame([self::GROUP_JID], GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver));
        $this->assertSame('mention', GroupConfigEnum::GROUP_REPLY_MODE->get($receiver));
    }

    public function testTheConnectionOwnerAnswersByDefault(): void
    {
        [$agent, $receiver] = $this->connectedAgent();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [self::GROUP_JID],
        )->execute();

        $receiver->refresh();

        $this->assertSame($agent->getId(), GroupConfigEnum::GROUP_AGENT_ID->get($receiver));
    }

    public function testAnEmptyListTurnsGroupIngestOff(): void
    {
        [$agent, $receiver] = $this->connectedAgent();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [self::GROUP_JID],
        )->execute();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [],
        )->execute();

        $this->assertSame([], GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver->refresh()));
    }

    public function testDuplicateJidsCollapse(): void
    {
        [$agent, $receiver] = $this->connectedAgent();

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [
                self::GROUP_JID,
                ' ' . self::GROUP_JID . ' ',
                '',
            ],
        )->execute();

        $this->assertSame([self::GROUP_JID], GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver->refresh()));
    }

    /**
     * A phone number here would allow-list nothing at all and look like it worked.
     */
    public function testANonGroupJidIsRejected(): void
    {
        [$agent] = $this->connectedAgent();

        $this->expectException(ValidationException::class);

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: ['15550003333'],
        )->execute();
    }

    public function testAnAgentWithNoWhatsAppConnectionIsRejected(): void
    {
        $agent = $this->agent();

        $this->expectException(ValidationException::class);

        new ConfigureWhatsAppGroupsAction(
            agent: $agent,
            allowedGroupJids: [self::GROUP_JID],
        )->execute();
    }

    /**
     * @return array{0: Agent, 1: ReceiverWebhook}
     */
    private function connectedAgent(): array
    {
        $agent = $this->agent();
        $receiver = $this->receiver();

        $agent->set(ConnectionFieldEnum::RECEIVER_ID->value, $receiver->getId());

        return [$agent, $receiver];
    }

    private function agent(): Agent
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => AgentType::factory()->create(['apps_id' => $app->getId()]),
                'user_id' => $user->getId(),
            ]);
    }

    private function receiver(): ReceiverWebhook
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessWaSenderWebhookJob::class],
            ['name' => 'ProcessWaSenderWebhookJob'],
        );

        return ReceiverWebhook::factory()
            ->app($app->getId())
            ->company($user->getCurrentCompany()->getId())
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
                'is_active' => true,
            ]);
    }
}
