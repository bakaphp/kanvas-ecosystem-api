<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Http\SafeUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\NotificationConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;

/**
 * Sends plain notifications to Slack for workflow rules / alerts — deliberately independent of the
 * agent install flow (`Client::getInstanceByAgent`), which needs an Agent to hang its bot token on.
 * This reads company-scoped credentials set up through the generic `integrationCompany` mutation
 * (see `Handlers\SlackNotificationHandler`), the same mechanism every other connector uses.
 *
 * A webhook URL is tried first when both are configured: it is the simpler credential (nothing to
 * scope beyond the single channel it was created for) and needs no channel argument.
 */
class SlackNotificationService
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl() !== '' || $this->botToken() !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $blocks Slack Block Kit blocks, optional.
     * @return array{channel: ?string, ts: ?string, via: string}
     */
    public function send(string $text, ?string $channel = null, array $blocks = []): array
    {
        $webhookUrl = $this->webhookUrl();
        if ($webhookUrl !== '') {
            $this->sendViaWebhook($webhookUrl, $text, $blocks);

            return [
                'channel' => $channel ?? ($this->defaultChannel() ?: null),
                'ts' => null,
                'via' => 'webhook',
            ];
        }

        $botToken = $this->botToken();
        if ($botToken !== '') {
            $resolvedChannel = $channel ?? $this->defaultChannel();

            if ($resolvedChannel === '') {
                throw new ValidationException(
                    'A Slack channel is required: pass one explicitly or configure default_channel.'
                );
            }

            $ts = new Client($botToken)->postMessage($resolvedChannel, $text);

            return ['channel' => $resolvedChannel, 'ts' => $ts, 'via' => 'bot_token'];
        }

        throw new ValidationException('Slack notifications are not configured for this company.');
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    protected function sendViaWebhook(string $webhookUrl, string $text, array $blocks): void
    {
        SafeUrl::assertSafe($webhookUrl);

        /** @var Response $response */
        $response = Http::timeout(15)
            ->acceptJson()
            ->post($webhookUrl, array_filter([
                'text' => $text,
                'blocks' => $blocks !== [] ? $blocks : null,
            ]));

        if (! $response->successful()) {
            throw new ValidationException(
                'Slack webhook notification failed with HTTP ' . $response->status() . ': ' . $response->body()
            );
        }
    }

    public function webhookUrl(): string
    {
        return trim((string) ($this->company->get(NotificationConfigurationEnum::WEBHOOK_URL->value) ?? ''));
    }

    public function botToken(): string
    {
        return trim((string) ($this->company->get(NotificationConfigurationEnum::BOT_TOKEN->value) ?? ''));
    }

    public function defaultChannel(): string
    {
        return trim((string) ($this->company->get(NotificationConfigurationEnum::DEFAULT_CHANNEL->value) ?? ''));
    }

    /**
     * Posts a canned test message and requires Slack to accept it — used by the handler at
     * setup time so a typo'd webhook URL fails loudly instead of silently at the first real alert.
     */
    public static function validateWebhook(string $webhookUrl): bool
    {
        SafeUrl::assertSafe($webhookUrl);

        try {
            /** @var Response $response */
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($webhookUrl, ['text' => 'Kanvas is now connected to this Slack channel.']);
        } catch (Throwable $e) {
            throw new ValidationException('Failed to reach the Slack webhook URL: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            throw new ValidationException(
                'Slack rejected the webhook test message (HTTP ' . $response->status() . ').'
            );
        }

        return true;
    }

    public static function validateBotToken(string $botToken): bool
    {
        try {
            new Client($botToken)->authTest();
        } catch (Throwable $e) {
            throw new ValidationException('Failed to validate the Slack bot token: ' . $e->getMessage());
        }

        return true;
    }
}
