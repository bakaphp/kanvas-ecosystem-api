<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\CompaniesSettings;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use stdClass;

/**
 * One-off after the Laravel AI 1.x schema migration: rewrites every message that still has no `steps`
 * and keys every conversation to its participant. Idempotent — each pass only touches rows the previous
 * one did not finish — so it can be re-run until `steps IS NULL` and `participant_type IS NULL` both
 * count zero. The participant rules run in this order on purpose: a public-chat conversation also
 * carries the AI agent user in `user_id`, so the People rule has to claim it before the Users rule would.
 */
class BackfillConversationStepsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'agents:backfill-conversation-steps {--chunk=200 : Message rows rewritten per batch}';

    protected $description = 'Rewrite agent_conversation_messages into the Laravel AI 1.x steps shape and key conversations to their participant';

    public function handle(): int
    {
        $this->table(
            ['Backfill', 'Rows'],
            [
                ['message steps', $this->backfillSteps(max(1, (int) $this->option('chunk')))],
                ['People conversations', $this->backfillPeopleParticipants()],
                ['Agent conversations', $this->backfillAgentParticipants()],
                ['Users conversations', $this->backfillUserParticipants()],
                ['message participants', $this->backfillMessageParticipants()],
            ],
        );

        return self::SUCCESS;
    }

    private function backfillSteps(int $chunk): int
    {
        $updated = 0;

        $this->messages()
            ->whereNull('steps')
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$updated): void {
                foreach ($rows as $row) {
                    $this->rewriteRow($row);
                    $updated++;
                }
            }, 'id');

        return $updated;
    }

    private function rewriteRow(stdClass $row): void
    {
        $meta = $this->decoded($row->meta);

        $steps = ConversationStepsHelper::forRow(
            (string) $row->role,
            (string) $row->content,
            $this->decoded($row->tool_calls),
            $this->decoded($row->tool_results),
            (string) ($meta['reasoning'] ?? ''),
        );

        unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

        $this->messages()->where('id', $row->id)->update([
            'steps' => json_encode($steps),
            'meta' => json_encode($meta),
        ]);
    }

    /**
     * Public chat keys the conversation by the session uuid (stored in `title`); the session's entity is
     * the Person. Only conversations an AI identity ran qualify — a staff user's session can also point at
     * a People record — so the acting user decides, exactly as `KanvasConversationStore::participantFor()`
     * does for new turns. A uuid can carry several session rows (one per agent, or a stale one from an
     * earlier turn); the newest is the live one, the same choice `Session::scopeFromAgent()` makes.
     */
    private function backfillPeopleParticipants(): int
    {
        return $this->conversations('c')
            ->join('sessions as s', 's.uuid', '=', 'c.title')
            ->whereRaw('s.id = (SELECT MAX(id) FROM sessions WHERE uuid = c.title)')
            ->whereNull('c.participant_type')
            ->where('s.entity_namespace', People::class)
            ->whereIn('c.user_id', $this->aiIdentityUserIds())
            ->update([
                'c.participant_type' => Relation::getMorphAlias(People::class),
                'c.participant_id' => DB::raw('s.entity_id'),
            ]);
    }

    /**
     * Every user id an agent can run under: the dedicated users on `agents` and each company's shared AI
     * agent user. Bulk form of `KanvasConversationStore::actsAsAi()`; the settings live on another
     * connection, so the set is built here rather than joined.
     *
     * @return list<int>
     */
    private function aiIdentityUserIds(): array
    {
        $dedicated = DB::connection('intelligence')->table('agents')->whereNotNull('user_id')->distinct()->pluck('user_id');
        $shared = CompaniesSettings::query()->where('name', ConfigurationEnum::AI_AGENT_USER_ID->value)->pluck('value');

        return array_values(array_unique(array_map('intval', [...$dedicated->all(), ...$shared->all()])));
    }

    /**
     * Runtime-imported conversations (Hermes, OpenClaw) have no user; they belong to the agent, which acts
     * through its dedicated user. Resolving that user reads Bouncer-scoped roles, hence the rebind per agent.
     */
    private function backfillAgentParticipants(): int
    {
        $updated = 0;

        $agentIds = $this->conversations()
            ->whereNull('participant_type')
            ->whereNull('user_id')
            ->whereNotNull('agent_id')
            ->distinct()
            ->pluck('agent_id');

        foreach ($agentIds as $agentId) {
            $agent = Agent::query()->whereKey((int) $agentId)->first();
            $userId = null;

            if ($agent !== null) {
                $this->overwriteAppService($agent->app);
                $userId = $agent->user_id ?: $agent->company?->getAiAgentUser()?->getId();
            }

            $updated += $this->conversations()
                ->whereNull('participant_type')
                ->whereNull('user_id')
                ->where('agent_id', (int) $agentId)
                ->update([
                    'participant_type' => Relation::getMorphAlias(Agent::class),
                    'participant_id' => (int) $agentId,
                    'user_id' => $userId,
                ]);
        }

        return $updated;
    }

    private function backfillUserParticipants(): int
    {
        return $this->conversations()
            ->whereNull('participant_type')
            ->whereNotNull('user_id')
            ->update([
                'participant_type' => Relation::getMorphAlias(Users::class),
                'participant_id' => DB::raw('user_id'),
            ]);
    }

    private function backfillMessageParticipants(): int
    {
        return $this->messages('m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereNull('m.participant_type')
            ->whereNotNull('c.participant_type')
            ->update([
                'm.participant_type' => DB::raw('c.participant_type'),
                'm.participant_id' => DB::raw('c.participant_id'),
                'm.user_id' => DB::raw('COALESCE(m.user_id, c.user_id)'),
            ]);
    }

    private function conversations(?string $alias = null): Builder
    {
        return DB::connection('intelligence')->table($alias !== null ? "agent_conversations as {$alias}" : 'agent_conversations');
    }

    private function messages(?string $alias = null): Builder
    {
        return DB::connection('intelligence')->table($alias !== null ? "agent_conversation_messages as {$alias}" : 'agent_conversation_messages');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }
}
