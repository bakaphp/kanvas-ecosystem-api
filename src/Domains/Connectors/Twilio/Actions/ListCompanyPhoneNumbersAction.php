<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;

/**
 * List the phone numbers a company has purchased on its connected Twilio
 * account. Encapsulates the third-party Twilio call (creds resolved per company
 * via the connector Client) so callers never touch the SDK directly.
 *
 * Throws when the company has no Twilio creds (ValidationException from the
 * Client) or the Twilio API errors — the caller decides how to degrade.
 */
class ListCompanyPhoneNumbersAction
{
    public function __construct(
        private readonly Companies $company,
    ) {
    }

    /**
     * @return array<int, array{phone_number: string, friendly_name: string|null, sid: string}>
     */
    public function execute(int $limit = 200): array
    {
        $twilio = TwilioClient::getInstanceByCompanyOrApp($this->company, $this->company->app);

        return array_map(
            static fn ($number): array => [
                'phone_number' => $number->phoneNumber,
                'friendly_name' => $number->friendlyName,
                'sid' => $number->sid,
            ],
            $twilio->incomingPhoneNumbers->read([], $limit),
        );
    }
}
