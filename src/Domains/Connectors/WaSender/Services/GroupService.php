<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\WaSender\Client;

/**
 * The group directory. Sending into a group goes through MessageService — WaSender's
 * `/api/send-message` takes a group JID in `to` exactly like a phone number, so a second send path
 * here would only be a copy that can drift.
 */
class GroupService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Every group the connected account is a member of.
     */
    public function getAllGroups(): array
    {
        return $this->client->get('/api/groups');
    }
}
