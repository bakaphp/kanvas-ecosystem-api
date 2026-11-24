<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;

class ContactService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * The opaque per-account id WhatsApp addresses this number as under lid addressing.
     *
     * Needed because group payloads mention people by `@lid` while we only ever stored a phone
     * number — without this the two never compare equal and a mention is invisible.
     *
     * @param string $phoneNumber digits or a full JID
     */
    public function getLidFromPhoneNumber(string $phoneNumber): ?string
    {
        $response = $this->client->get('/api/lid-from-phone-number/' . rawurlencode($phoneNumber));
        $lid = $response['data']['lid'] ?? $response['lid'] ?? null;

        return is_string($lid) && $lid !== '' ? $lid : null;
    }
}
