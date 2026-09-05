<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Client as TwilioClient;

/**
 * List the phone numbers a company has purchased on its connected Twilio
 * account. Encapsulates the third-party Twilio call (company creds, falling back
 * to the app's shared account) so callers never touch the SDK directly.
 *
 * The app is passed in explicitly — a company can be global (apps_id 0), so its
 * own `->app` relation may be null; the caller supplies the acting app.
 *
 * Throws when neither company nor app has Twilio creds (ValidationException from
 * the Client) or the Twilio API errors — the caller decides how to degrade.
 */
class ListCompanyPhoneNumbersAction
{
    public function __construct(
        private readonly Companies $company,
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array<int, array{phone_number: string, friendly_name: string|null, sid: string}>
     */
    public function execute(int $limit = 200): array
    {
        $twilio = TwilioClient::getInstanceByCompanyOrApp($this->company, $this->app);

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
