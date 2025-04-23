<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Leads;

use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;

class Lead
{
    public int $id;
    public ?string $leadSource = null;
    public ?string $leadStatusType = null;
    public int $leadStatusId = 0;
    public int $leadType = 0;
    public int $contactId = 0;
    public int $isHot = 0;
    public int $isOnShowroom = 0;
    public int $coBuyerContact = 0;

    /**
     * Initialize.
     */
    public function __construct(array $data)
    {
        $this->id = $data['LeadId'] ?? $data['leadId'];
        $this->leadSource = $data['LeadSource'] ?? '55694';
        $this->leadStatusType = $data['LeadStatusType'] ?? null;
        $this->leadStatusId = $data['LeadStatusId'] ?? 0;
        $this->leadType = $data['LeadType'] ?? 0;
        $this->contactId = $data['CustomerId'] ?? 0;
        $this->isHot = isset($data['IsHot']) ? (int) $data['IsHot'] : 0;
        $this->coBuyerContact = isset($data['coBuyerContact']) && (int) $data['coBuyerContact'] > 0 ? (int) $data['coBuyerContact'] : 0;
        $this->isOnShowroom = isset($data['IsOnShowroom']) ? (int) $data['IsOnShowroom'] : 0;
    }

    /**
     * Get all the leads for the given dealer.
     */
    public static function getAll(Dealer $dealer, User $user, array $params = []): array
    {
        $client = new Client($dealer->id, $user->id);
        $client->useDigitalShowRoomKey();

        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $params = http_build_query($params);

        $response = $client->get(
            '/gateway/v1/lead?dealerId='.$dealer->id.'&userId='.$user->id.'&'.$params,
        );

        return $response;
    }

    public static function getAllV2(Dealer $dealer, User $user, array $params = []): array
    {
        $client = new Client($dealer->id, $user->id);

        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $params = http_build_query($params);

        $response = $client->get(
            '/leads?dealerId='.$dealer->id.'&userId='.$user->id.'&'.$params,
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v3+json',
                ],
            ]
        );

        return $response;
    }

    /**
     * Get a contact by its ID.
     */
    public static function getById(Dealer $dealer, User $user, int $leadsId): self
    {
        $client = new Client($dealer->id, $user->id);
        $client->useDigitalShowRoomKey();

        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $response = $client->get(
            '/gateway/v1/lead/'.$leadsId.'?dealerId='.$dealer->id.'&userId='.$user->id,
        );

        return new self($response['Leads'][0]);
    }

    /**
     * Create a new contact.
     */
    public static function create(Dealer $dealer, User $user, array $data): self
    {
        $client = new Client($dealer->id, $user->id);
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $data['leadSource'] = 'https://api.vinsolutions.com/leadsources/id/'.$data['leadSource'].'?dealerId='.$dealer->id;
        $data['contact'] = 'https://api.vinsolutions.com/leadsources/id/'.$data['contact'].'?dealerId='.$dealer->id;

        $response = $client->post(
            '/leads?dealerId='.$dealer->id.'&userId='.$user->id,
            json_encode($data),
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v3+json',
                ],
            ]
        );

        return new self($response);
    }

    /**
     * Create a new contact.
     */
    public function update(Dealer $dealer, User $user): self
    {
        $client = new Client($dealer->id, $user->id);

        $data = [];
        $data['isHot'] = $this->isHot ? true : false;

        if ($this->coBuyerContact !== 0) {
            $data['coBuyerContact'] = 'https://api.vinsolutions.com/contacts/id/'.$this->coBuyerContact.'?dealerid='.$dealer->id;
        }

        $client->put(
            '/leads/id/'.$this->id,
            json_encode($data),
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v3+json',
                ],
            ]
        );

        $data['LeadId'] = $this->id;

        return new self($data);
    }

    public function addNotes(Dealer $dealer, User $user, string $notes): self
    {
        $client = new Client($dealer->id, $user->id);
        $client->useDigitalShowRoomKey();
        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;
        $data['Note'] = $notes;

        $client->put(
            '/gateway/v1/lead/'.$this->id,
            json_encode($data),
        );

        $data['LeadId'] = $this->id;

        return new self($data);
    }

    /**
     * Start a show room.
     */
    public function startShowRoom(Dealer $dealer, User $user): self
    {
        $client = new Client($dealer->id, $user->id);
        $client->useDigitalShowRoomKey();
        $data = [];
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;
        $data['LeadSourceId'] = $this->leadSource;
        $data['LeadId'] = $this->id;
        $data['CustomerId'] = $this->contactId;

        $response = $client->post(
            '/gateway/v1/lead/startShowroomVisit',
            json_encode($data),
        );

        return new self($response);
    }

    public static function getCoBuyer(Dealer $dealer, User $user, int $leadsId): ?string
    {
        $client = new Client($dealer->id, $user->id);
        $data['DealerId'] = $dealer->id;
        $data['UserId'] = $user->id;

        $response = $client->get(
            '/leads/id/'.$leadsId,
            [
                'headers' => [
                    'Accept' => 'application/vnd.coxauto.v3+json',
                ],
            ]
        );

        if (preg_match("/\/id\/(\d+)/", $response['coBuyerContact'], $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Add a trade in to the vehicle.
     */
    public function addTradeIn(Dealer $dealer, User $user, array $data): array
    {
        $client = new Client($dealer->id, $user->id);

        $request = [];
        $request['lead'] = 'https://api.vinsolutions.com/leadsources/id/'.$this->id.'?dealerId='.$dealer->id;
        $request['vehicles'][] = $data;

        $response = $client->post(
            '/vehicles/trade',
            json_encode($request),
            [
                'headers' => [
                    'Content-Type' => 'application/vnd.coxauto.v1+json',
                ],
            ]
        );

        return $response;
    }

    /**
     * Get the list of trade-in vehicles.
     */
    public function getTradeIn(Dealer $dealer, User $user): array
    {
        $client = new Client($dealer->id, $user->id);

        $response = $client->get(
            '/vehicles/trade?leadId='.$this->id,
            [
                'headers' => [
                    'Accept' => 'application/vnd.coxauto.v1+json',
                ],
            ]
        );

        return $response['items'];
    }
}
