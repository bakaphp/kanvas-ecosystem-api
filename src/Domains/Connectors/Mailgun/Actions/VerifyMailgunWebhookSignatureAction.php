<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;

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

        return $companyKey !== '' ? $companyKey : $appKey;
    }
}
