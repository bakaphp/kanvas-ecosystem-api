<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Actions\ConfigureWhatsAppGroupsAction;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupReplyModeEnum;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

/**
 * PR4: the allow-list is opt-in, written onto the WhatsApp receiver of the agent's company, and
 * validated — a bare phone number silently allow-lists nothing, since a group thread is only ever
 * addressed by its @g.us JID.
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

    public function testAnAgentWhoseCompanyHasNoWhatsAppReceiverIsRejected(): void
    {
        $agent = $this->agent(Companies::factory()->create());

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
        // A company of its own: the receiver is found by company, not by an id the agent holds.
        $company = Companies::factory()->create();

        return [
            $this->agent($company),
            new CommunicationReceivers()->ensure(app(Apps::class), $company, WaSenderHandler::slug()),
        ];
    }

    private function agent(Companies $company): Agent
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => AgentType::factory()->create(['apps_id' => $app->getId()]),
                'user_id' => $user->getId(),
            ]);
    }
}
