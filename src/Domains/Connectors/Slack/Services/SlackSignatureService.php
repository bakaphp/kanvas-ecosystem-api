<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Illuminate\Http\Request;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Slack signs every request with the app's signing secret over the RAW body — re-encoding the
 * parsed payload produces a different string and fails verification, so we read the raw content.
 *
 * https://api.slack.com/authentication/verifying-requests-from-slack
 */
class SlackSignatureService
{
    private const int REPLAY_WINDOW_SECONDS = 300;
    private const string VERSION = 'v0';

    public static function isValidRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        $signingSecret = (string) ($receiver->configuration['signing_secret'] ?? '');

        if ($signingSecret === '') {
            return false;
        }

        return self::isValid(
            $request->getContent(),
            (string) $request->headers->get('x-slack-request-timestamp', ''),
            (string) $request->headers->get('x-slack-signature', ''),
            $signingSecret,
        );
    }

    public static function isValid(string $rawBody, string $timestamp, string $signature, string $signingSecret): bool
    {
        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = self::VERSION . '=' . hash_hmac(
            'sha256',
            self::VERSION . ':' . $timestamp . ':' . $rawBody,
            $signingSecret
        );

        return hash_equals($expected, $signature);
    }
}
