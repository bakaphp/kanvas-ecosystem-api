<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack;

use Baka\Http\SafeUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

class Client
{
    private const string BASE_URL = 'https://slack.com/api/';
    private const int MAX_TEXT_LENGTH = 3000;

    // Slack's file download 302-chains through a couple of hosts (workspace host → signed CDN); a
    // handful of hops is plenty, and the cap stops a redirect loop from hanging the worker.
    private const int MAX_DOWNLOAD_REDIRECTS = 5;

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
     * Split text into Slack-sized chunks by BYTE length (Slack's `msg_too_long` is byte-counted),
     * preferring newline boundaries so mrkdwn formatting isn't cut mid-entity. A single line longer
     * than the limit is hard-split on UTF-8 character boundaries so no multibyte char is severed.
     *
     * @return list<string>
     */
    public static function splitText(string $text, int $limit = self::MAX_TEXT_LENGTH): array
    {
        if (strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if (strlen($line) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach (self::hardSplitByBytes($line, $limit) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (strlen($candidate) > $limit) {
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

    /**
     * Hard-split one over-long line into <= $limit BYTE pieces without cutting a multibyte character:
     * accumulate whole UTF-8 characters until the next one would breach the byte budget.
     *
     * @return list<string>
     */
    private static function hardSplitByBytes(string $line, int $limit): array
    {
        $pieces = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if ($current !== '' && strlen($current) + strlen($char) > $limit) {
                $pieces[] = $current;
                $current = '';
            }
            $current .= $char;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    public function userInfo(string $userId): array
    {
        return $this->call('users.info', ['user' => $userId])['user'] ?? [];
    }

    public function lookupUserIdByEmail(string $email): ?string
    {
        try {
            $id = (string) ($this->call('users.lookupByEmail', ['email' => $email])['user']['id'] ?? '');

            return $id === '' ? null : $id;
        } catch (ValidationException) {
            return null;
        }
    }

    public function openDirectMessageChannel(string $userId): string
    {
        $channel = $this->call('conversations.open', ['users' => $userId])['channel'] ?? [];

        return (string) (is_array($channel) ? $channel['id'] ?? '' : '');
    }

    public function authTest(): array
    {
        return $this->call('auth.test', []);
    }

    /**
     * Download a Slack-hosted file (url_private / url_private_download). These require the bot token as
     * a bearer header — a plain GET (e.g. SafeUrlFetcher, which sends no auth) gets Slack's HTML login
     * page instead of the bytes.
     *
     * We follow redirects manually because Slack answers files.slack.com with a cross-host 302 (to
     * another *.slack.com host, or a signed CDN URL), and Guzzle strips the Authorization header on any
     * cross-host redirect — the followed request then arrives unauthenticated and Slack serves the HTML
     * login page. So we re-send the bot token to Slack's own hosts across each hop, follow non-Slack
     * (already-signed) redirects WITHOUT the token, and SSRF-guard every hop.
     */
    public function downloadFile(string $url): string
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_DOWNLOAD_REDIRECTS; $hop++) {
            SafeUrl::assertSafe($current);

            $request = Http::timeout(30)->withOptions(['allow_redirects' => false]);

            // Forward the bot token only to Slack's own hosts; a redirect to a signed CDN/S3 URL must
            // not carry our credentials (it doesn't need them, and leaking them is the SSRF footgun).
            if ($this->isSlackHost($current)) {
                $request = $request->withToken($this->botToken);
            }

            /** @var Response $response */
            $response = $request->get($current);
            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new ValidationException('Slack file download redirected without a Location header.');
                }
                $current = $this->resolveRedirectUrl($current, $location);

                continue;
            }

            if ($status !== 200) {
                throw new ValidationException('Slack file download failed with HTTP ' . $status . '.');
            }

            // An unauthorized url_private answers 200 with the HTML login page, not the file — treat
            // that as a failure so we never persist the login page as though it were the attachment.
            if (str_contains($response->header('Content-Type'), 'text/html')) {
                throw new ValidationException('Slack file download was not authorized (received an HTML page, not file bytes).');
            }

            return $response->body();
        }

        throw new ValidationException('Slack file download exceeded the redirect limit.');
    }

    private function isSlackHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'slack.com' || str_ends_with($host, '.slack.com');
    }

    private function resolveRedirectUrl(string $from, string $location): string
    {
        // Slack sends absolute Location headers, but tolerate a relative one by resolving it against
        // the current host.
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $scheme = (string) parse_url($from, PHP_URL_SCHEME) ?: 'https';
        $host = (string) parse_url($from, PHP_URL_HOST);
        $path = str_starts_with($location, '/') ? $location : '/' . $location;

        return $scheme . '://' . $host . $path;
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
