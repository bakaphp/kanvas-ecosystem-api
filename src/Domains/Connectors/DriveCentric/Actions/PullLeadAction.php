<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\DataTransferObject\Lead as LeadDTO;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

class PullLeadAction
{
    protected LeadService $leadService;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
        $this->leadService = new LeadService($app, $company);
    }

    public function execute(string|Lead $dealIdOrLead): Lead
    {
        $dealId = $dealIdOrLead instanceof Lead
            ? $this->getDealIdFromLead($dealIdOrLead)
            : $dealIdOrLead;

        return DB::transaction(function () use ($dealId) {
            $deal = $this->leadService->getDealById($dealId);

            return $this->syncDeal($deal);
        });
    }

    protected function getDealIdFromLead(Lead $lead): string
    {
        $dealId = $lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            throw new DriveCentricException('Lead does not have a DriveCentric deal ID');
        }

        return $dealId;
    }

    public function syncDeal(array $deal): Lead
    {
        $leadDto = LeadDTO::fromDriveCentric(
            $deal,
            $this->app,
            $this->company,
            $this->user
        );

        $lead = (new SyncLeadByThirdPartyCustomFieldAction($leadDto))->execute();

        // Pull and sync co-buyers if present
        $this->syncCoBuyers($deal, $lead);

        // Store vehicle of interest
        $this->syncVehicleOfInterest($deal, $lead);

        // Store trade-in
        $this->syncTradeIn($deal, $lead);

        $lead->refresh();

        return $lead;
    }

    /**
     * Sync co-buyers from deal.
     */
    protected function syncCoBuyers(array $deal, Lead $lead): void
    {
        $customers = $deal['customers'] ?? [];

        foreach ($customers as $customer) {
            // Skip primary buyer
            if (($customer['isPrimaryBuyer'] ?? true) === true) {
                continue;
            }

            try {
                $pullPeople = new PullPeopleAction($this->app, $this->company, $this->user);
                $customerId = $this->extractCustomerId($customer);

                if ($customerId) {
                    $coBuyer = $pullPeople->executeById($customerId);
                    $lead->addCoBuyerParticipant($coBuyer);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Extract customer ID from customer data.
     */
    protected function extractCustomerId(array $customer): ?string
    {
        if (isset($customer['customerId'])) {
            return (string) $customer['customerId'];
        }

        foreach ($customer['identifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === 'CrmId') {
                return $identifier['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Sync vehicle of interest.
     */
    protected function syncVehicleOfInterest(array $deal, Lead $lead): void
    {
        $vehicles = $deal['vehiclesOfInterest'] ?? $deal['vehicles'] ?? [];

        if (empty($vehicles)) {
            return;
        }

        $vehicle = $vehicles[0] ?? [];

        if (isset($vehicle['year']) && $vehicle['year'] > 0) {
            $lead->set(CustomFieldEnums::VEHICLE_OF_INTEREST->value, [
                'year' => $vehicle['year'] ?? null,
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'trim' => $vehicle['trim'] ?? null,
                'vin' => $vehicle['vin'] ?? null,
                'stockNumber' => $vehicle['stockNumber'] ?? null,
                'price' => $vehicle['price'] ?? null,
                'isNew' => ($vehicle['stockType'] ?? '') === 'New',
            ]);
        }
    }

    /**
     * Sync trade-in.
     */
    protected function syncTradeIn(array $deal, Lead $lead): void
    {
        $tradeIns = $deal['tradeIns'] ?? [];

        if (empty($tradeIns)) {
            return;
        }

        $tradeIn = $tradeIns[0] ?? [];

        $lead->set(CustomFieldEnums::TRADE_IN->value, [
            'year' => $tradeIn['year'] ?? null,
            'make' => $tradeIn['make'] ?? null,
            'model' => $tradeIn['model'] ?? null,
            'vin' => $tradeIn['vin'] ?? null,
            'mileage' => $tradeIn['mileage'] ?? null,
            'value' => $tradeIn['value'] ?? null,
            'payoff' => $tradeIn['payoff'] ?? null,
        ]);
    }

    /**
     * Get formatted lead response.
     */
    public function getFormattedResponse(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'uuid' => $lead->uuid,
            'people_id' => $lead->people->id,
            'firstname' => $lead->people->firstname,
            'middlename' => $lead->people->middlename,
            'lastname' => $lead->people->lastname,
            'email' => $lead->people?->getEmails()->first()?->value,
            'phone' => $lead->people?->getPhones()->first()?->value,
            'status' => $lead->status()?->first()?->name,
            'lead_type' => $lead->type?->name,
            'owner' => $lead->owner?->name,
            'owner_id' => $lead->leads_owner_id,
            'custom_fields' => $lead->getAllCustomFields(),
        ];
    }
}
