<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Throwable;
use Twilio\Rest\Client as TwilioRestClient;

/**
 * List the phone numbers a company has purchased on its connected Twilio
 * account, so callers never touch the SDK directly.
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
     * Which Twilio account the numbers come from, without exposing the full SID. The name is
     * best-effort: a failed lookup only loses the label, never the numbers.
     *
     * @return array{sid_suffix: string, friendly_name: string|null}
     */
    public function account(): array
    {
        $twilio = $this->twilio();

        try {
            $friendlyName = $twilio->api->v2010->account->fetch()->friendlyName;
        } catch (Throwable) {
            $friendlyName = null;
        }

        return [
            'sid_suffix' => substr((string) $twilio->getAccountSid(), -4),
            'friendly_name' => $friendlyName,
        ];
    }

    protected function twilio(): TwilioRestClient
    {
        return TwilioClient::getInstanceByCompany($this->company);
    }
}
