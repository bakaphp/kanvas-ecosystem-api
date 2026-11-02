<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\EventTypeEnum;
use Kanvas\Connectors\Slack\Services\SlackSignatureService;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

abstract class SlackWebhookJob extends ProcessWebhookJob
{
    private const int DEDUPE_TTL_SECONDS = 300;

    /**
     * Slack verifies the Request URL while creating the app from our manifest — before the customer
     * can possibly hand us the signing secret to check it against. ReceiverController runs this
     * ahead of handshakeResponse(), so without the exemption the challenge 401s and the app can
     * never be created. Narrowed to the challenge alone, and only while we hold no secret.
     */
    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $hasSigningSecret = (string) ($receiver->configuration[ConfigurationEnum::SIGNING_SECRET->value] ?? '') !== '';

        if (! $hasSigningSecret && $request->input('type') === EventTypeEnum::URL_VERIFICATION->value) {
            return true;
        }

        return SlackSignatureService::isValidRequest($request, $receiver);
    }

    #[Override]
    public static function handshakeResponse(Request $request, ReceiverWebhook $receiver): ?array
    {
        if ($request->input('type') !== EventTypeEnum::URL_VERIFICATION->value) {
            return null;
        }

        return ['challenge' => (string) $request->input('challenge')];
    }

    /**
     * Slack redelivers an event when it doesn't see a 200 within 3s, and the retry is a fresh
     * dispatch — without this the same message is handled twice.
     */
    protected function isFirstDelivery(string $eventId): bool
    {
        if ($eventId === '') {
            return true;
        }

        return Cache::add('slack:event:' . $eventId, true, self::DEDUPE_TTL_SECONDS);
    }

    /**
     * One Slack message can arrive as two events with two event ids: a tagged room message is sent
     * as `app_mention` AND as `message`. Keyed on the message timestamp, per receiver — the agent
     * app and the workspace listener each keep their own copy on purpose.
     */
    protected function isFirstSightingOfMessage(string $slackChannelId, string $ts): bool
    {
        if ($slackChannelId === '' || $ts === '') {
            return true;
        }

        return Cache::add(
            'slack:message-seen:' . $this->receiver->getId() . ':' . $slackChannelId . ':' . $ts,
            true,
            self::DEDUPE_TTL_SECONDS
        );
    }

    /**
     * Slack echoes our own posts back as inbound events.
     */
    protected function isFromBot(array $event): bool
    {
        $botUserId = (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');

        return isset($event['bot_id'])
            || ($event['subtype'] ?? null) === 'bot_message'
            || ($botUserId !== '' && ($event['user'] ?? null) === $botUserId);
    }

    protected function isDenied(string $slackChannelId): bool
    {
        $denyList = $this->receiver->configuration[ConfigurationEnum::CHANNEL_DENY_LIST->value] ?? [];

        return is_array($denyList) && in_array($slackChannelId, $denyList, true);
    }

    /**
     * Edits and deletions nest their real content under `message` / `previous_message`, with the
     * channel only on the wrapper.
     *
     * They become NEW rows rather than mutating the original: append-only keeps the real history, and
     * the original stays reachable by its timestamp through SlackMessageLookupService.
     *
     * @return array{0: array, 1: list<string>}
     */
    protected function normalize(array $event, string $subtype): array
    {
        return match ($subtype) {
            EventTypeEnum::MESSAGE_CHANGED->value => [
                [...(array) ($event['message'] ?? []), 'channel' => $event['channel'] ?? ''],
                ['slack-edit'],
            ],
            EventTypeEnum::MESSAGE_DELETED->value => [
                [...(array) ($event['previous_message'] ?? []), 'channel' => $event['channel'] ?? ''],
                ['slack-delete'],
            ],
            default => [$event, []],
        };
    }

    protected function deactivateReceiver(): void
    {
        $this->receiver->is_active = false;
        $this->receiver->saveOrFail();
    }
}
