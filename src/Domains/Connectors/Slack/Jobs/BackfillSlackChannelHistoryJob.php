<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Actions\CreateMessageFromSlackEventAction;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Connectors\Slack\Services\SlackConversationChannelService;
use Kanvas\Connectors\Slack\Services\SlackMessageLookupService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Pulls what a room said before the agent could hear it. Runs once per room, when the bot is
 * invited (or on the first message from a room it was already in), and writes through the same
 * action as live ingest so a re-run is harmless and a reply lands under its root.
 *
 * One history page per run, rescheduled with the next cursor: history is one call but every
 * threaded root is another, and a year of a busy room walked in one go would get the app
 * rate-limited off Slack. The window and the cap keep "as much as possible" finite; the command
 * exists for a tenant that wants more.
 */
class BackfillSlackChannelHistoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const int DEFAULT_DAYS = 30;
    public const int MAX_MESSAGES = 5000;
    public const string TAG = 'slack-backfill';

    // Outlives one run of the job so a burst of first messages from a room queues one pull, not one
    // per message; released when the last page is done.
    private const int LOCK_SECONDS = 3600;

    private Client $client;
    private Agent $agent;
    private string $teamId;
    private Channel $channel;
    private SlackMessageLookupService $lookup;

    public function __construct(
        public readonly ReceiverWebhook $receiver,
        public readonly string $slackChannelId,
        public readonly ?int $days = null,
        public readonly string $cursor = '',
        public readonly int $imported = 0,
    ) {
        $this->onQueue('slack-ingest');
    }

    /**
     * The one way to start a pull: the invite, the first message, and the command all go through
     * here, so a room is never walked twice at once.
     */
    public static function dispatchIfIdle(ReceiverWebhook $receiver, string $slackChannelId, ?int $days = null): bool
    {
        if (! Cache::add(self::lockKey($receiver, $slackChannelId), true, self::LOCK_SECONDS)) {
            return false;
        }

        self::dispatch($receiver, $slackChannelId, $days);

        return true;
    }

    public static function lockKey(ReceiverWebhook $receiver, string $slackChannelId): string
    {
        return 'slack:backfill:' . $receiver->getId() . ':' . $slackChannelId;
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->receiver->app);

        $agent = $this->agent();

        if ($agent === null) {
            $this->finish();

            return;
        }

        $this->agent = $agent;
        $this->client = Client::getInstanceByAgent($agent);
        $this->teamId = (string) ($this->receiver->configuration[ConfigurationEnum::TEAM_ID->value] ?? '');
        $this->lookup = new SlackMessageLookupService();

        try {
            $page = $this->client->conversationsHistory($this->slackChannelId, $this->oldest(), $this->cursor);
        } catch (ValidationException $e) {
            // A room the bot cannot read (uninvited since, archived) is a normal outcome, not a fault.
            if (str_contains($e->getMessage(), 'not_in_channel') || str_contains($e->getMessage(), 'channel_not_found')) {
                $this->finish();

                return;
            }

            throw $e;
        }

        $this->channel = new SlackConversationChannelService()->channelFor(
            $agent,
            $agent->user ?? $this->receiver->user,
            $this->teamId,
            $this->slackChannelId,
        );

        $imported = $this->imported;

        // Slack hands the page newest first; written oldest first, ids follow the conversation.
        foreach (array_reverse($page['messages']) as $root) {
            if ($imported >= self::MAX_MESSAGES) {
                break;
            }

            if (! is_array($root) || $this->skips($root)) {
                continue;
            }

            if ($this->write($root)) {
                $imported++;
            }

            if ((int) ($root['reply_count'] ?? 0) > 0) {
                $imported = $this->writeReplies((string) ($root['ts'] ?? ''), $imported);
            }
        }

        if ($page['next_cursor'] !== '' && $imported < self::MAX_MESSAGES) {
            self::dispatch(
                receiver: $this->receiver,
                slackChannelId: $this->slackChannelId,
                days: $this->days,
                cursor: $page['next_cursor'],
                imported: $imported,
            )->delay(now()->addSeconds(5));

            return;
        }

        $this->finish();
    }

    /**
     * @return int the running import count
     */
    private function writeReplies(string $threadTs, int $imported): int
    {
        $cursor = '';

        do {
            $page = $this->client->conversationsReplies($this->slackChannelId, $threadTs, $cursor);

            foreach ($page['messages'] as $reply) {
                if ($imported >= self::MAX_MESSAGES) {
                    return $imported;
                }

                if (! is_array($reply) || ($reply['ts'] ?? '') === $threadTs || $this->skips($reply)) {
                    continue;
                }

                if ($this->write($reply)) {
                    $imported++;
                }
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== '');

        return $imported;
    }

    private function write(array $slackMessage): bool
    {
        $ts = (string) ($slackMessage['ts'] ?? '');

        if ($ts === '' || $this->lookup->find($this->channel, $ts) !== null) {
            return false;
        }

        $message = new CreateMessageFromSlackEventAction(
            receiver: $this->receiver,
            agent: $this->agent,
            teamId: $this->teamId,
            event: [...$slackMessage, 'channel' => $this->slackChannelId],
            addressed: false,
            tags: [self::TAG],
            occurredAt: Carbon::createFromTimestamp((float) $ts),
        )->execute();

        return $message !== null;
    }

    private function skips(array $slackMessage): bool
    {
        $subtype = (string) ($slackMessage['subtype'] ?? '');

        return isset($slackMessage['bot_id']) || $subtype === 'bot_message' || EventTypeEnum::isNoise($subtype);
    }

    private function oldest(): string
    {
        $configured = (int) ($this->receiver->configuration[ConfigurationEnum::BACKFILL_DAYS->value] ?? 0);
        $days = $this->days ?? ($configured > 0 ? $configured : self::DEFAULT_DAYS);

        return (string) now()->subDays($days)->getTimestamp();
    }

    private function agent(): ?Agent
    {
        $agentId = (int) ($this->receiver->configuration[ConfigurationEnum::AGENT_ID->value] ?? 0);

        if ($agentId === 0) {
            return null;
        }

        return Agent::query()->whereKey($agentId)->fromApp($this->receiver->app)->notDeleted()->first();
    }

    private function finish(): void
    {
        $done = (array) ($this->receiver->configuration[ConfigurationEnum::CHANNELS_BACKFILLED->value] ?? []);

        if (! in_array($this->slackChannelId, $done, true)) {
            $this->receiver->configuration = [
                ...$this->receiver->configuration,
                ConfigurationEnum::CHANNELS_BACKFILLED->value => [...$done, $this->slackChannelId],
            ];
            $this->receiver->saveOrFail();
        }

        Cache::forget(self::lockKey($this->receiver, $this->slackChannelId));
    }
}
