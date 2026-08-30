<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Jobs\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentChannelActivity;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Shared core of the agent-wake jobs (project PM / task / plan worker): run the agent through the
 * kernel with the scaffolded wake prompt never persisted, and on failure emit a `{prefix}.invocation_failed`
 * ledger event before rethrowing. Each job keeps its own guards, session key, reply target and the
 * `.invoked`/`.replied` events (their payloads differ) — only this repeated, timing-sensitive block is shared.
 */
trait DrivesAgentWake
{
    /**
     * @param object $ledgerEntity a model using EmitsLedgerEventsForEntity (Project or Plan)
     * @param array<int, object> $tools board tools injected for this run only
     *
     * @return array{0: string, 1: int} the agent's response and the elapsed milliseconds
     */
    protected function runAgentWake(
        Agent $agent,
        Session $session,
        Users $owner,
        string $message,
        object $ledgerEntity,
        string $ledgerPrefix,
        array $failurePayload,
        array $tools = [],
    ): array {
        $startedAt = microtime(true);

        try {
            $response = new AgentChatKernel(
                agent: $agent,
                session: $session,
                message: $message,
                user: $owner,
                additionalTools: $tools,
                // The reply is posted explicitly by the caller; never persist the (large, scaffolded)
                // wake prompt — it would be re-read as "context" on the next wake and re-trigger this
                // same wake, growing unboundedly (a 700KB+ nested-prompt runaway).
                persistConversation: false,
            )->execute();
        } catch (Throwable $e) {
            $ledgerEntity->emitLedgerEvent(
                $ledgerPrefix . '.invocation_failed',
                status: EventStatusEnum::ERROR,
                payload: $failurePayload,
                error: ['message' => $e->getMessage(), 'class' => $e::class],
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        return [$response, (int) ((microtime(true) - $startedAt) * 1000)];
    }

    /**
     * The newest message on a channel, as the baseline for {@see self::agentPostedDuringRun()}.
     */
    protected function latestMessageId(?Channel $channel): ?int
    {
        return AgentChannelActivity::latestMessageId($channel);
    }

    /**
     * Whether the agent put its own words on this channel DURING the turn — via a board tool such as
     * comment_on_nervous_system_plan — which makes the turn's final reply a restatement of something
     * already on the record. Two posts, four seconds apart, saying the same thing.
     */
    protected function agentPostedDuringRun(?Channel $channel, ?int $sinceMessageId, ?Users $author): bool
    {
        return AgentChannelActivity::agentPostedSince($channel, $sinceMessageId, $author);
    }

    /**
     * The per-entity wake session (one continuous LLM thread per waked entity). The match key defaults
     * to the entity's tenant + identity; pass $extraKey to widen it (e.g. per-agent, so a reassigned
     * plan starts fresh) and $create to override the defaults on first creation.
     *
     * @param array<string, mixed> $create overrides for the create-only attributes
     * @param array<string, mixed> $extraKey extra columns added to the firstOrCreate match key
     */
    protected function firstOrCreateWakeSession(Model $entity, array $create = [], array $extraKey = []): Session
    {
        /** @var Session $session */
        $session = Session::firstOrCreate(
            [
                'apps_id' => $entity->apps_id,
                'companies_id' => $entity->companies_id,
                'entity_namespace' => $entity::class,
                'entity_id' => $entity->getKey(),
                ...$extraKey,
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'channel_id' => null,
                'content' => '',
                'user' => [],
                ...$create,
            ],
        );

        return $session;
    }
}
