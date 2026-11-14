<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Throwable;
use Twilio\Rest\Client as TwilioRestClient;

/**
 * Read the company's connected Twilio account — its numbers, which account it is, and its balance —
 * so callers never touch the SDK directly.
 *
 * Throws when the company has no Twilio creds (ValidationException from the
 * Client) or the Twilio API errors — the caller decides how to degrade.
 */
class ListCompanyPhoneNumbersAction
{
    public function __construct(private readonly Companies $company)
    {
    }

    /**
     * @return array<int, array{phone_number: string, friendly_name: string|null, provider: string, provider_id: string}>
     */
    public function execute(int $limit = 200): array
    {
        return array_map(
            static fn ($number): array => [
                'phone_number' => $number->phoneNumber,
                'friendly_name' => $number->friendlyName,
                'provider' => 'twilio',
                'provider_id' => $number->sid,
            ],
            $this->twilio()->incomingPhoneNumbers->read([], $limit),
        );
    }

    /**
     * One number on the account and where its calls go, or null when the account doesn't have it.
     *
     * @return array{phone_number: string, voice_url: string|null}|null
     */
    public function number(string $phoneNumber): ?array
    {
        $numbers = $this->twilio()->incomingPhoneNumbers->read(['phoneNumber' => $phoneNumber], 1);
        if ($numbers === []) {
            return null;
        }

        return [
            'phone_number' => $phoneNumber,
            'voice_url' => $numbers[0]->voiceUrl ?: null,
        ];
    }

    /**
     * Which Twilio account this is, without exposing the full SID. Everything but the suffix is
     * best-effort: a failed lookup only loses the labels, never the numbers.
     *
     * @return array{sid_suffix: string, friendly_name: string|null, status: string|null, type: string|null}
     *   status: active | suspended | closed; type: Trial | Full
     */
    public function account(): array
    {
        $twilio = $this->twilio();

        try {
            $account = $twilio->api->v2010->account->fetch();
        } catch (Throwable) {
            $account = null;
        }

        return [
            'sid_suffix' => substr((string) $twilio->getAccountSid(), -4),
            'friendly_name' => $account?->friendlyName,
            'status' => $account?->status,
            'type' => $account?->type,
        ];
    }

    /**
     * Null when Twilio won't say (subaccounts can't always read it); callers treat that as unknown,
     * not as empty.
     *
     * @return array{amount: float, currency: string}|null
     */
    public function balance(): ?array
    {
        try {
            $balance = $this->twilio()->api->v2010->account->balance->fetch();
        } catch (Throwable) {
            return null;
        }

        if (! is_numeric($balance->balance)) {
            return null;
        }

        return [
            'amount' => (float) $balance->balance,
            'currency' => (string) $balance->currency,
        ];
    }

    protected function twilio(): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($this->company);
    }
}
