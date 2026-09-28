<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;

class VerifyMercuryWebhookSignatureAction
{
    /** Mercury's own recommendation. */
    private const int MAX_AGE_SECONDS = 300;

    public function __construct(
        public readonly CompanyInterface $company,
        public readonly string $signatureHeader,
        public readonly string $rawBody,
        public readonly int $now,
    ) {
    }

    public function execute(): bool
    {
        $secret = (string) $this->company->get(ConfigurationEnum::WEBHOOK_SECRET->value);

        $forceVerify = (bool) $this->company->get(ConfigurationEnum::FORCE_VERIFY_WEBHOOK_SIGNATURE->value);

        if ($forceVerify === true) {
            return true;
        }

        if ($secret === '' || $this->signatureHeader === '') {
            return false;
        }

        $parts = $this->parseHeader();

        if ($parts === null) {
            return false;
        }

        [$timestamp, $signature] = $parts;

        if (abs($this->now - $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $this->rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function parseHeader(): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $this->signatureHeader) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (count($pair) !== 2) {
                continue;
            }

            match ($pair[0]) {
                't' => $timestamp = ctype_digit($pair[1]) ? (int) $pair[1] : null,
                'v1' => $signature = $pair[1],
                default => null,
            };
        }

        return $timestamp !== null && $signature !== null && $signature !== ''
            ? [$timestamp, $signature]
            : null;
    }
}
