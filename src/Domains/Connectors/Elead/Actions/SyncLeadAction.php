<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Connectors\Elead\Entities\Lead as LeadEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\UserConfig;
use Throwable;

class SyncLeadAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(): LeadEntity
    {
        $lead = $this->lead;
        $eLeadOpportunityData = LeadEntity::convertLeadToOpportunityStructure($lead);
        $eLeadOpportunityId = (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);
        //$eLeadCustomerId = (string) $lead->people->get(Flag::CUSTOMER_ID);

        if (empty($eLeadOpportunityId)) {
            $eLeadOpportunity = LeadEntity::create($this->lead->app, $this->lead->company, $eLeadOpportunityData);
            $lead->set(CustomFieldEnum::OPPORTUNITY_ID->value, $eLeadOpportunity->id);

            if ($lead->owner) {
                try {
                    if ($leadOwnerId = $lead->owner->get(CustomFieldEnum::getUserKey($this->lead->companies))) {
                        $eLeadOpportunity->reAssignPrimarySalesUser($leadOwnerId);
                    }
                } catch (Throwable $e) {
                }
            }
        } else {
            $eLeadOpportunity = LeadEntity::getById($this->lead->app, $this->lead->company, $eLeadOpportunityId);
        }

        if (isset($eLeadOpportunity->customer) && isset($eLeadOpportunity->customer['id'])) {
            $eLeadOpportunity->customerId = $eLeadOpportunity->customer['id'];
        }

        //add referral note
        if ($lead->get('referral')) {
            $eLeadOpportunity->addComment($lead->get('referral'));
            $lead->del('referral');
        }

        if (! empty($eLeadOpportunity->soughtVehicles)) {
            $vehicleOfInterest = current($eLeadOpportunity->soughtVehicles);
            $lead->set(
                LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value,
                $vehicleOfInterest
            );

            if (is_array($vehicleOfInterest) && count($vehicleOfInterest) && $lead->companies->get('enable_vehicle_checklist')) {
                if (isset($vehicleOfInterest['mileage'])) {
                    $isNew = $vehicleOfInterest['mileage'] < 3000 ? 1 : 0;
                    $taskList = [
                        0 => 'Used Vehicle Checklist (TG)',
                        1 => 'New Vehicle Checklist (TG)',
                    ];

                    $completeTaskList = TaskList::fromApp($this->lead->app)->where([
                        'companies_id' => $this->lead->companies->getId(),
                        'name' => $taskList[$isNew],
                        'apps_id' => $this->lead->app->getId(),
                    ])->first();

                    $checkListStatus = $lead->get('check_list_status');
                    $canChangeCompleteTaskStatus = empty($checkListStatus) || $checkListStatus['mode'] == 'automatic';

                    if ($completeTaskList && $canChangeCompleteTaskStatus) {
                        $lead->set('check_list_status', [
                            'mode' => 'automatic',
                            'activeTaskListId' => $completeTaskList->getId(),
                        ]);
                    } else {
                        $lead->set('check_list_status', [
                            'mode' => 'automatic',
                            'activeTaskListId' => $lead->companies->get('default_checklist_id'),
                        ]);
                    }
                }
            }
        }

        if (! empty($eLeadOpportunity->tradeIns)) {
            $lead->set(
                LeadCustomFieldEnum::TRADE_IN->value,
                current($eLeadOpportunity->tradeIns)
            );
        }

        if ($eLeadOpportunity->inShowRoom()) {
            //and its not running we turn it one
            if (! $lead->get('is_chrono_running')) {
                $lead->startShowRoom();
            }
        }

        try {
            if (! empty($eLeadOpportunity->salesTeam)) {
                foreach ($eLeadOpportunity->salesTeam as $salesTeam) {
                    if ((bool)$salesTeam['isPrimary']) {
                        $userConfig = UserConfig::where([
                            'name' => CustomFieldEnum::getUserKey($this->company),
                            'value' => $salesTeam['id'],
                        ])->first();

                        if ($userConfig) {
                            $lead->leads_owner_id = $userConfig->users_id;
                            $lead->disableWorkflows();
                            $lead->saveOrFail();
                            $lead->enableWorkflows();
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        //update status
        if (! $eLeadOpportunity->isActive()) {
            $lead->close();
        }

        return $eLeadOpportunity;
    }
}
