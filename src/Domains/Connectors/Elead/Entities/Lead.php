<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use DateTime;
use DateTimeZone;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\DataTransferObject\TradeIn;
use Kanvas\Connectors\Elead\DataTransferObject\Vehicle;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Exceptions\ELeadException;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;

class Lead
{
    public ?string $id = null;
    public ?string $customerId = null;
    public ?string $dateIn = null;
    public ?string $source = null;
    public ?string $status = null;
    public ?string $subStatus = null;
    public ?string $upType = null;
    public array $soughtVehicles = [];
    public array $tradeIns = [];
    public array $address = [];
    public array $salesTeam = [];
    public ?Companies $company = null;
    public ?AppInterface $app = null;
    public static array $defaultLeadType = ['Unknown', 'Campaign', 'Internet', 'Phone', 'Showroom'];

    /**
     * Assign value to the current object.
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * get the current dateIn.
     */
    public static function currentDateIn(): string
    {
        $amNY = new DateTime('America/New_York');

        return str_replace('-04:00', '.402Z', $amNY->format('c'));
    }

    /**
     * Convert a Lead to a opportunity format.
     */
    public static function convertLeadToOpportunityStructure(ModelsLead $lead): array
    {
        $customerId = $lead->people->get(CustomFieldEnum::CUSTOMER_ID->value);

        if (empty($customerId)) {
            $customerId = new SyncPeopleAction($lead->people)->execute()->id;
        }

        $date = new DateTime($lead->created_at, new DateTimeZone('America/New_York'));
        $dateFormat = str_replace('-04:00', '.402Z', $date->format('c'));

        $opportunityData = [
            'customerId' => $customerId,
            // 'dateIn' => self::currentDateIn(),
            'source' => is_object($lead->source) && $lead->source->name && ! empty($lead->source->description) ? $lead->source->name : 'Lead Link',
            'status' => $lead->leads_status && strtolower($lead->leads_status->name) == 'inactive' ? 'Inactive' : 'Active', //$lead->leads_status->name,
            'subStatus' => 'Unknown', //change
            'upType' => is_object($lead->source) && $lead->source->name && ! empty($lead->source->description) ? $lead->source->description : 'Internet', // !in_array($lead->type->name, self::$defaultLeadType) ? self::$defaultLeadType[1] : $lead->type->name, //source and type are tied in together
        ];

        if ($lead->owner
            && $lead->owner->get(CustomFieldEnum::getUserKey($lead->companies))
            && $lead->owner->get(CustomFieldEnum::getUserJobPositionKey($lead->companies))) {
            $opportunityData['salesTeam'][] = [
                'id' => $lead->owner->get(CustomFieldEnum::getUserKey($lead->companies)),
                'isPrimary' => true,
                'isPositionPrimary' => true,
                'positionCode' => $lead->owner->get(CustomFieldEnum::getUserJobPositionKey($lead->companies)),
            ];
        }

        return $opportunityData;
    }

    /**
     * Create a new Lead.
     */
    public static function create(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v2/elead/opportunities/',
            $data,
        );

        $newLead = new Lead();
        $newLead->company = $company;
        $newLead->app = $app;
        $newLead->assign($response['opportunity']);

        return $newLead;
    }

    /**
     * Add comments to a lead.
     */
    public function addComment(string $comment): void
    {
        $client = new Client($this->app, $this->company);
        $client->post(
            '/sales/v2/elead/opportunities/comment',
            [
                'opportunityId' => $this->id,
                'comment' => $comment,
            ],
        );
    }

    public function completeSalesStep(string $step): void
    {
        $client = new Client($this->app, $this->company);
        $client->post(
            '/sales/v2/elead/opportunities/' . $this->id . '/salesstep/complete',
            [
                'name' => $step,
            ],
        );
    }

    public function undoSalesStep(string $step): void
    {
        $client = new Client($this->app, $this->company);
        $client->post(
            '/sales/v2/elead/opportunities/' . $this->id . '/salesstep/undo',
            [
                'name' => $step,
            ],
        );
    }

    /**
     * To the current lead add a trade id.
     */
    public function addTradeIn(TradeIn $tradeIn): object
    {
        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v2/elead/opportunities/tradein',
            [
                'opportunityId' => $this->id,
                'year' => $tradeIn->year,
                'make' => $tradeIn->make,
                'model' => $tradeIn->model,
                'trim' => $tradeIn->trim,
                'vin' => $tradeIn->vin,
                'estimatedMileage' => $tradeIn->estimatedMileage,
                'interiorColor' => $tradeIn->interiorColor,
                'exteriorColor' => $tradeIn->exteriorColor,
            ],
        );

        return (object)$response;
    }

    /**
     * To the current lead add a vehicles.
     */
    public function addVehicle(Vehicle $vehicle): object
    {
        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v2/elead/opportunities/vehiclesought',
            [
                'opportunityId' => $this->id,
                'isNew' => $vehicle->isNew,
                'yearFrom' => $vehicle->yearFrom,
                'yearTo' => $vehicle->yearTo,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'trim' => $vehicle->trim,
                'vin' => $vehicle->vin,
                'priceFrom' => $vehicle->priceFrom,
                'priceTo' => $vehicle->priceTo,
                'maxMileage' => $vehicle->maxMileage,
                'stockNumber' => $vehicle->stockNumber,
                'isPrimary' => $vehicle->isPrimary,
            ],
        );

        return (object)$response;
    }

    /**
     * Delete sought vehicle.
     */
    public function deleteVehicle(string $soughtVehicle): void
    {
        $client = new Client($this->app, $this->company);

        $client->delete(
            '/sales/v2/elead/opportunities/vehicleSought/' . $soughtVehicle,
        );
    }

    /**
     * Get Lead by lead ID.
     */
    public static function getById(AppInterface $app, Companies $company, string $id): self
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v2/elead/opportunities/' . $id,
        );

        $lead = new Lead();
        $lead->company = $company;
        $lead->app = $app;
        if (isset($response['customer']) && isset($response['customer']['id'])) {
            $lead->customerId = $response['customer']['id'];
        }
        $lead->assign($response);

        return $lead;
    }

    /**
     * Get Lead by customer ID.
     */
    public static function getByCustomerId(AppInterface $app, Companies $company, string $customerId): self
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v2/elead/opportunities/search-by-customerId/' . $customerId,
        );

        if (isset($response['code']) && $response['message']) {
            throw new ELeadException($response['message']);
        }

        if (! isset($response['totalItems']) && $response['totalItems'] == 0) {
            throw new ELeadException('No Lead Found for this customer ' . $customerId);
        }

        $lead = new Lead();
        $lead->company = $company;
        $lead->app = $app;
        $lead->assign($response['items'][0]);

        return $lead;
    }

    /**
     * Get all leads from a specific date.
     */
    public static function searchByDelta(AppInterface $app, Companies $company, string $from, int $page = 1, int $limit = 25): array
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v2/elead/opportunities/searchDelta?dateFrom=' . $from . '&page=' . $page . '&pageSize=' . $limit
        );

        if (! isset($response['totalItems']) && $response['totalItems'] == 0) {
            throw new ELeadException('No Lead Found on this date ' . $from);
        }

        return $response;
    }

    /**
     * Get all leads from a specific date.
     */
    public static function getAllFromDate(AppInterface $app, Companies $company, string $from, int $page = 1, int $limit = 25): array
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v2/elead/opportunities/search?dateFrom=' . $from . '&page=' . $page . '&pageSize=' . $limit
        );

        if (! isset($response['totalItems']) && $response['totalItems'] == 0) {
            throw new ELeadException('No Lead Found on this date ' . $from);
        }

        return $response;
    }

    /**
     * add new sales person.
     */
    public function addSalesUser(
        string $salesPersonId,
        string $positionCode,
        bool $isPrimary = true,
        bool $isPositionPrimary = true
    ): array {
        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v2/elead/opportunities/' . $this->id . '/salesteam/add',
            [
                'id' => $salesPersonId,
                'isPrimary' => $isPrimary,
                'isPositionPrimary' => $isPositionPrimary,
                'positionCode' => $positionCode,
            ]
        );

        return $response;
    }

    /**
     * delete sales user.
     */
    public function deleteSalesUser(
        string $salesPersonId,
        string $positionCode
    ): array {
        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v2/elead/opportunities/' . $this->id . '/salesteam/remove',
            [
                'salespersonId' => $salesPersonId,
                'positionCode' => $positionCode,
            ]
        );

        return $response;
    }

    /**
     * reassign primary sales user.
     */
    public function reAssignPrimarySalesUser(string $salesPersonId, bool $reassignScheduledActivities = true): void
    {
        $client = new Client($this->app, $this->company);
        $client->post(
            '/sales/v2/elead/opportunities/reassignPrimarySalesperson',
            [
                'opportunityId' => $this->id,
                'salesPersonId' => $salesPersonId,
                'reassignScheduledActivities' => $reassignScheduledActivities,
            ]
        );
    }

    /**
     * reassign primaryBDCAgent user.
     */
    public function reAssignBDCAgent(string $primaryBDCAgentId): void
    {
        $client = new Client($this->app, $this->company);
        $client->post(
            '/sales/v2/elead/opportunities/reassignPrimaryBdcAgent',
            [
                'opportunityId' => $this->id,
                'primaryBDCAgentId' => $primaryBDCAgentId,
            ]
        );
    }

    /**
     * Get customer.
     */
    public function customer(): Customer
    {
        return Customer::getById($this->app, $this->company, $this->customerId);
    }

    /**
     * Is showroom.
     */
    public function inShowRoom(): bool
    {
        return $this->upType == 'Showroom';
    }

    public function isActive(): bool
    {
        return $this->status == 'Active';
    }
}
