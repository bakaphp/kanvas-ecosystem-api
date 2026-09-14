<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * The Events API only delivers from conversations the bot is a member of, so "listen to everything"
 * really means "be in everything".
 *
 * Reschedules itself one page at a time instead of looping: conversations.list is Tier 2 (~20
 * req/min) and conversations.join Tier 3, so a large workspace swept in a single pass gets the app
 * rate-limited off Slack entirely. One page a minute is slow and finishes.
 *
 * Private channels never appear here — a bot cannot self-join one, a human has to invite it.
 */
class JoinAllPublicChannelsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly ReceiverWebhook $receiver,
        public readonly string $cursor = '',
        public readonly int $joinedSoFar = 0,
    ) {
        $this->onQueue('slack-ingest');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $client = Client::getInstanceByReceiver($this->receiver);
        $page = $client->conversationsList($this->cursor);
        $joined = $this->joinedSoFar + $this->joinPage($client, $page['channels']);

        $this->receiver->configuration = [
            ...$this->receiver->configuration,
            ConfigurationEnum::CHANNELS_JOINED->value => $joined,
        ];
        $this->receiver->saveOrFail();

        if ($page['next_cursor'] !== '') {
            self::dispatch(
                $this->app,
                $this->receiver,
                $page['next_cursor'],
                $joined,
            )->delay(now()->addMinute());
        }
    }

    private function joinPage(Client $client, array $channels): int
    {
        $joined = 0;

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $channelId = (string) ($channel['id'] ?? '');

            if ($channelId === '' || ($channel['is_member'] ?? false) === true) {
                continue;
            }

            try {
                $client->joinConversation($channelId);
                $joined++;
            } catch (Throwable $e) {
                // One un-joinable room (archived mid-sweep, org-restricted) must not abandon the
                // rest of the workspace.
                report($e);
            }
        }

        return $joined;
    }
}
