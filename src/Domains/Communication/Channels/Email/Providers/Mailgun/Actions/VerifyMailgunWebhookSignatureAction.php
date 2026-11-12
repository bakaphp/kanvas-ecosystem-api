<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Actions;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Models\ReceiverWebhook;

class VerifyMailgunWebhookSignatureAction
{
    private const int REPLAY_WINDOW_SECONDS = 300;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected string $token,
        protected string $timestamp,
        protected string $signature,
    ) {
    }

    /**
     * Mailgun signs a route forward with top-level form fields and a domain event with a nested
     * `signature` object; one receiver can take either.
     */
    public static function forRequest(Request $request, ReceiverWebhook $receiver): self
    {
        $nested = $request->input('signature');
        $signature = is_array($nested) ? $nested : $request->only(['token', 'timestamp', 'signature']);

        return new self(
            $receiver->app,
            $receiver->company,
            (string) ($signature['token'] ?? ''),
            (string) ($signature['timestamp'] ?? ''),
            (string) ($signature['signature'] ?? ''),
        );
    }

    public function execute(): bool
    {
        if ($this->token === '' || $this->timestamp === '' || $this->signature === '') {
            return false;
        }

        $signingKey = $this->resolveSigningKey();

        if ($signingKey === '') {
            return false;
        }

        if (abs(time() - (int) $this->timestamp) > self::REPLAY_WINDOW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $this->timestamp . $this->token, $signingKey);

        return hash_equals($expected, $this->signature);
    }

    private function resolveSigningKey(): string
    {
        $key = ConfigurationEnum::WEBHOOK_SIGNING_KEY->value;
        $appKey = (string) $this->app->get($key);
        $companyKey = (string) $this->company->get($key);

        return $appKey !== '' ? $appKey : $companyKey;
    }
}
