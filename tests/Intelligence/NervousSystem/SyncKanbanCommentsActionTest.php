<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\Kanban\SyncKanbanCommentsAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Tests\TestCase;

/**
 * The Hermes → Kanvas comment half: agent/board comments land on the Plan's Activities channel,
 * our own `kanvas:*` comments are skipped (no echo), child comments are prefixed, and the
 * per-plan watermark makes the sync idempotent.
 */
final class SyncKanbanCommentsActionTest extends TestCase
{
    public function testPostsAgentCommentsSkipsKanvasPrefixesChildAndAdvancesWatermark(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'kanban-comments', 'user_id' => $user->getId()]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'comment-bridge plan',
                planType: 'hermes_kanban',
                agent: $agent,
                user: $user, // mirrors UpsertKanbanPlanAction: owner = agent user → Activities channel
            ),
            fromSync: true,
        )->execute();
        $plan->set(KanbanCustomFieldEnum::TASK_ID->value, 't_root');

        $root = KanbanTask::parseShowPayload([
            'task' => ['id' => 't_root', 'title' => 'root', 'status' => 'running'],
            'parents' => [],
            'children' => ['t_a'],
            'comments' => [
                ['author' => KanbanCustomFieldEnum::KANVAS_AUTHOR_PREFIX . $user->getId(), 'body' => 'human guidance', 'created_at' => 1000],
                ['author' => 'default', 'body' => 'agent note: need the key', 'created_at' => 2000],
            ],
        ]);
        $child = KanbanTask::parseShowPayload([
            'task' => ['id' => 't_a', 'title' => 'child task', 'status' => 'running'],
            'parents' => ['t_root'],
            'children' => [],
            'comments' => [
                ['author' => 'default', 'body' => 'working on it', 'created_at' => 3000],
            ],
        ]);

        $posted = new SyncKanbanCommentsAction($plan, [$root, $child])->execute();

        // agent root comment + child comment; the kanvas: one is ours → skipped.
        $this->assertSame(2, $posted);

        $channel = $plan->socialChannels()->first();
        $this->assertNotNull($channel);

        $bodies = $channel->messages()->get()
            ->map(fn (object $m): string => is_array($m->message) ? (string) ($m->message['content'] ?? '') : (string) $m->message)
            ->all();

        $this->assertContains('agent note: need the key', $bodies);
        $this->assertContains('[task: child task] working on it', $bodies);
        $this->assertNotContains('human guidance', $bodies);

        $this->assertSame(3000, (int) $plan->get(KanbanCustomFieldEnum::LAST_COMMENT_AT->value));

        // Idempotent: same cards, watermark already at 3000 → nothing new posted.
        $this->assertSame(0, new SyncKanbanCommentsAction($plan->fresh(), [$root, $child])->execute());
    }
}
