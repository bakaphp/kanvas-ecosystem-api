<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Throwable;

/**
 * List the Twilio phone numbers on the current company's connected Twilio
 * account, so the admin can PICK a number when configuring an agent's voice
 * instead of typing it. Uses the company's Twilio creds via the existing
 * connector (same source the inbound-webhook wiring uses).
 *
 * @guard (user session). Best-effort: returns an empty list when the company
 * has no Twilio creds or the Twilio API can't be reached, so the UI shows
 * "no numbers" rather than erroring.
 */
class VoiceAgentTwilioNumbersQuery
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(mixed $root, array $args): array
    {
        $company = auth()->user()->getCurrentCompany();

        try {
            $twilio = TwilioClient::getInstanceByCompany($company);
            $numbers = $twilio->incomingPhoneNumbers->read([], 200);
        } catch (Throwable) {
            return []; // no creds / API error — surface as an empty picker
        }

        return array_map(
            static fn ($n): array => [
                'phone_number' => $n->phoneNumber,
                'friendly_name' => $n->friendlyName,
                'sid' => $n->sid,
            ],
            $numbers,
        );
    }
}
