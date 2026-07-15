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
