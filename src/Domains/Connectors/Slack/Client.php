<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

class Client
{
    private const string BASE_URL = 'https://slack.com/api/';

    // Slack rejects a message whose text exceeds its limit with `msg_too_long`. It only renders
    // ~4000 chars cleanly, so we split below that and send the overflow as follow-up messages.
    private const int MAX_TEXT_LENGTH = 3900;

    public function __construct(
        private readonly string $botToken
    ) {
    }

    public static function getInstanceByAgent(Agent $agent): self
    {
        $botToken = (string) ($agent->get(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value) ?? '');

        if ($botToken === '') {
            throw new ValidationException(
                'Agent ' . (int) $agent->getId() . ' has no Slack bot token. Connect the agent to Slack first.'
            );
        }

        return new self($botToken);
    }

    /**
     * The posted message's `ts`, which doubles as its id for updateMessage() — the
     * placeholder-then-edit pattern an agent turn needs, since it outlives Slack's ack window.
     */
    public function postMessage(string $channel, string $text, ?string $threadTs = null): string
    {
        return (string) $this->call('chat.postMessage', array_filter([
            'channel' => $channel,
            'text' => $text,
            'thread_ts' => $threadTs,
        ]))['ts'];
    }

    public function updateMessage(string $channel, string $ts, string $text): void
    {
        $this->call('chat.update', [
            'channel' => $channel,
            'ts' => $ts,
            'text' => $text,
        ]);
    }

    /**
     * Edit the placeholder message with a reply that may exceed Slack's per-message limit: the first
     * chunk updates the placeholder, the rest are posted as follow-ups in the same thread. Avoids the
     * `msg_too_long` failure a long agent reply otherwise triggers on chat.update.
     */
    public function updateMessageWithOverflow(
        string $channel,
        string $ts,
        string $text,
        ?string $threadTs = null
    ): void {
        $chunks = self::splitText($text);

        $this->updateMessage($channel, $ts, $chunks[0]);

        // Keep follow-ups in the same thread; when the turn wasn't threaded, hang them off the
        // placeholder itself so the reply stays a single readable unit.
        $thread = $threadTs !== null && $threadTs !== '' ? $threadTs : $ts;
        foreach (array_slice($chunks, 1) as $chunk) {
            $this->postMessage($channel, $chunk, $thread);
        }
    }

    /**
     * Split text into Slack-sized chunks, preferring newline boundaries so mrkdwn formatting isn't
     * cut mid-entity. A single line longer than the limit is hard-split as a last resort.
     *
     * @return list<string>
     */
    public static function splitText(string $text, int $limit = self::MAX_TEXT_LENGTH): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($line) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach (mb_str_split($line, max(1, $limit)) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (mb_strlen($candidate) > $limit) {
                $chunks[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    public function userInfo(string $userId): array
    {
        return $this->call('users.info', ['user' => $userId])['user'] ?? [];
    }

    public function authTest(): array
    {
        return $this->call('auth.test', []);
    }

    private function call(string $method, array $params): array
    {
        /** @var Response $response */
        $response = Http::withToken($this->botToken)
            ->asForm()
            ->timeout(30)
            ->post(self::BASE_URL . $method, $params);

        $data = $response->json();

        if (! is_array($data)) {
            throw new ValidationException('Slack ' . $method . ' returned an unreadable response');
        }

        if (($data['ok'] ?? false) !== true) {
            throw new ValidationException(
                'Slack ' . $method . ' failed: ' . (string) ($data['error'] ?? 'unknown')
            );
        }

        return $data;
    }
}
