<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentBurstResponderAction;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupReplyModeEnum;
use Kanvas\Connectors\WaSender\Services\GroupBurstService;
use Kanvas\Connectors\WaSender\Services\GroupMentionService;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionDto;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * One finished group burst: compose it, run the agent once over the whole thing, reply if the
 * agent is allowed to speak, then announce it so rules can move the result elsewhere.
 *
 * Connector-owned on purpose. The webhook is already a queued job, so putting the agent pass in a
 * workflow activity would only buy an activity-replies-then-another-activity-acts chain. Activities
 * consume what this produces; they never answer WhatsApp.
 *
 * Debounced rather than scheduled: every message in the burst re-arms the token and dispatches a
 * fresh delayed copy, so only the last one survives to do the work.
 */
class ProcessGroupBurstJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly Apps $app,
        public readonly ReceiverWebhook $receiver,
        public readonly Channel $channel,
        public readonly int $burstHeadId,
        public readonly string $token,
    ) {
    }

    /**
     * The token every message in a burst overwrites. Whoever still matches when the delay expires
     * is the one that closes it.
     */
    public static function cacheKey(int $burstHeadId): string
    {
        return 'wasender:burst:' . $burstHeadId;
    }

    public function handle(): void
    {
        // The worker is long-lived and Bouncer auto-scopes Role/Ability queries to whatever the
        // previous job left bound.
        $this->overwriteAppService($this->app);

        if (Cache::get(self::cacheKey($this->burstHeadId)) !== $this->token) {
            return;
        }

        // Spent here, not on the way out. With `$tries = 2` a throw after the agent has answered
        // re-entered with the token still valid and filed a second reply — a second published post.
        // Deleting only on a match still leaves the burst armed for the winner when a part loses.
        Cache::forget(self::cacheKey($this->burstHeadId));

        $messages = GroupBurstService::messagesFor($this->burstHeadId);

        if ($messages->isEmpty()) {
            return;
        }

        $conversationJid = $this->conversationJid($messages);
        $prompt = GroupBurstService::promptFor($messages);
        $isDirect = ($messages->first()->message['conversation_type'] ?? null) === ConversationTypeEnum::DIRECT->value;

        // A 1:1 is addressed by definition; only a room needs mention detection.
        $isMentioned = $isDirect
            || new GroupMentionService($this->receiver, $this->channel)->isAddressed($messages);

        $agent = $this->resolveAgent($isDirect);
        $shouldReply = $this->shouldReply($isDirect, $isMentioned);
        $result = null;

        if ($agent !== null) {
            $result = $this->runAgent(
                $agent,
                $messages,
                $prompt,
                $conversationJid,
                $isDirect,
                $shouldReply
            );
        }

        $this->channel->fireWorkflow(
            $isDirect
                ? WorkflowEnum::AFTER_ADDING_MESSAGE_TO_AGENT_CHANNEL->value
                : WorkflowEnum::AFTER_ADDING_MESSAGE_TO_GROUP_CHANNEL->value,
            true,
            [
                'app' => $this->app,
                'company' => $this->channel->company,
                'communication_channel' => 'whatsapp',
                'conversation_type' => $isDirect ? ConversationTypeEnum::DIRECT->value : ConversationTypeEnum::GROUP->value,
                'group_jid' => $conversationJid,
                'group_name' => $this->channel->name,
                'message' => $messages->first(),
                'burst_message_ids' => $messages->pluck('id')->all(),
                'burst_text' => $prompt,
                'agent_id' => $agent?->getId(),
                'agent_result' => $result,
                'is_mention' => $isMentioned,
                'replied' => (bool) ($result['replied'] ?? false),
            ]
        );
    }

    private function resolveAgent(bool $isDirect): ?Agent
    {
        // An assistant conversation belongs to the agent that owns the connection; the group
        // agent is a deliberate, separate assignment.
        $agentId = $isDirect
            ? ($this->receiver->configuration['agent_id'] ?? GroupConfigEnum::GROUP_AGENT_ID->get($this->receiver))
            : GroupConfigEnum::GROUP_AGENT_ID->get($this->receiver);

        if ($agentId === null) {
            return null;
        }

        try {
            /** @var Agent $agent */
            $agent = Agent::getById((int) $agentId, $this->app);

            return $agent;
        } catch (Throwable) {
            // A misconfigured agent id must not swallow the burst — the workflow still fires and
            // whatever is wired to it still runs.
            return null;
        }
    }

    private function shouldReply(bool $isDirect, bool $isMentioned): bool
    {
        $mode = $isDirect
            ? DirectConfigEnum::DIRECT_REPLY_MODE->get($this->receiver)
            : GroupConfigEnum::GROUP_REPLY_MODE->get($this->receiver);

        return match (GroupReplyModeEnum::tryFromValue($mode)) {
            GroupReplyModeEnum::NEVER => false,
            GroupReplyModeEnum::ALWAYS => true,
            GroupReplyModeEnum::MENTION => $isMentioned,
        };
    }

    /**
     * @param Collection<int, Message> $messages
     */
    private function conversationJid(Collection $messages): string
    {
        return (string) ($messages->first()->message['group_jid'] ?? $messages->first()->message['chat_jid'] ?? '');
    }

    /**
     * @param Collection<int, Message> $messages
     */
    private function runAgent(
        Agent $agent,
        Collection $messages,
        string $prompt,
        string $conversationJid,
        bool $isDirect,
        bool $shouldReply
    ): ?array {
        $head = $messages->first();

        try {
            $session = new CreateSessionAction(
                SessionDto::from([
                    'app' => $this->app,
                    'company' => $this->channel->company,
                    'channel' => $this->channel,
                    'entity_namespace' => Channel::class,
                    'entity_id' => (string) $this->channel->getId(),
                    // The direct canal matches the one the lead DM flow builds for the same
                    // number, so a conversation keeps its session across mode changes.
                    'canal_id' => $isDirect
                        ? SessionChannelService::createCanalId('whatsapp', $conversationJid)
                        : SessionChannelService::createCanalId('whatsapp-group', $conversationJid),
                    'user' => [
                        'name' => $this->channel->name,
                        'id' => $this->channel->getId(),
                        'email' => null,
                    ],
                    'agent' => $agent,
                ])
            )->execute();

            return new AgentBurstResponderAction(
                $this->channel,
                $head,
                $agent,
                $session
            )->execute([
                'prompt' => $prompt,
                'group_jid' => $conversationJid,
                'should_reply' => $shouldReply,
                'burst_message_ids' => $messages->pluck('id')->all(),
            ]);
        } catch (AgentReplySkippedException $e) {
            // An expected refusal — already answered, empty reply, agent muted. Not a fault, so it
            // never reaches Sentry (root CLAUDE.md).
            return [
                'message' => $e->getMessage(),
                'response' => null,
                'replied' => false,
            ];
        } catch (Throwable $e) {
            // The burst is already filed; a failed agent turn must still let the activities run.
            report($e);

            return null;
        }
    }
}
