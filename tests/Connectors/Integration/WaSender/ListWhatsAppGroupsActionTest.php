<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\ListWhatsAppGroupsAction;
use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Services\GroupService;
use Kanvas\Connectors\WaSender\Webhooks\ProcessWaSenderWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * The admin-facing group picker. WhatsApp is the only source of a human-readable group name, and
 * its collection endpoints are inconsistent about the `data` envelope and the participant shape.
 */
final class ListWhatsAppGroupsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow', 'intelligence'];

    private const string ALLOWED_JID = '15550001111-1700000000@g.us';
    private const string OTHER_JID = '15550002222-1700000001@g.us';

    public function testEachGroupIsFlaggedWithWhetherTheAgentListensToIt(): void
    {
        $agent = $this->agentWithAllowList([self::ALLOWED_JID]);

        $groups = new ListWhatsAppGroupsAction($agent, $this->groupServiceReturning([
            'data' => [
                [
                    'id' => self::ALLOWED_JID,
                    'subject' => 'Prensa Nacional',
                    'participants' => [['id' => 'a'], ['id' => 'b']],
                ],
                [
                    'id' => self::OTHER_JID,
                    'name' => 'Familia',
                    'size' => 7,
                ],
            ],
        ]))->execute();

        $this->assertCount(2, $groups);

        $this->assertSame(self::ALLOWED_JID, $groups[0]['jid']);
        $this->assertSame('Prensa Nacional', $groups[0]['name']);
        $this->assertSame(2, $groups[0]['participants_count']);
        $this->assertTrue($groups[0]['is_allowed']);

        $this->assertSame('Familia', $groups[1]['name']);
        $this->assertSame(7, $groups[1]['participants_count']);
        $this->assertFalse($groups[1]['is_allowed']);
    }

    /**
     * WaSender wraps collections in a `data` envelope on some endpoints and not others.
     */
    public function testAnUnwrappedCollectionIsAccepted(): void
    {
        $agent = $this->agentWithAllowList([]);

        $groups = new ListWhatsAppGroupsAction($agent, $this->groupServiceReturning([
            ['jid' => self::OTHER_JID, 'subject' => 'Sin envoltura'],
        ]))->execute();

        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]['is_allowed']);
    }

    public function testEntriesWithoutAJidAreSkipped(): void
    {
        $agent = $this->agentWithAllowList([]);

        $groups = new ListWhatsAppGroupsAction($agent, $this->groupServiceReturning([
            'data' => [
                ['subject' => 'No JID at all'],
                'not-even-an-array',
                ['id' => self::OTHER_JID],
            ],
        ]))->execute();

        $this->assertCount(1, $groups);
        $this->assertSame(self::OTHER_JID, $groups[0]['jid']);
        $this->assertNull($groups[0]['name'], 'A group WhatsApp gave no name for reports none');
    }

    private function groupServiceReturning(array $response): GroupService
    {
        return new class ($response) extends GroupService {
            public function __construct(private array $response)
            {
            }

            public function getAllGroups(): array
            {
                return $this->response;
            }
        };
    }

    /**
     * @param list<string> $allowedJids
     */
    private function agentWithAllowList(array $allowedJids): Agent
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessWaSenderWebhookJob::class],
            ['name' => 'ProcessWaSenderWebhookJob'],
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->company($user->getCurrentCompany()->getId())
            ->user($user->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [GroupConfigEnum::ALLOWED_GROUP_JIDS->value => $allowedJids],
                'is_active' => true,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => AgentType::factory()->create(['apps_id' => $app->getId()]),
                'user_id' => $user->getId(),
            ]);

        $agent->set(ConnectionFieldEnum::RECEIVER_ID->value, $receiver->getId());

        return $agent;
    }
}
