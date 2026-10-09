<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\Models\InsurancePlan;

class UpdateInsurancePlanAction
{
    public function __construct(
        protected readonly InsurancePlan $plan,
        protected readonly InsurancePlanData $data,
    ) {
    }

    public function execute(): InsurancePlan
    {
        return DB::connection('commerce')->transaction(function (): InsurancePlan {
            $this->plan->name = $this->data->name;
            $this->plan->type = $this->data->type;
            $this->plan->external_code = $this->data->externalCode;
            $this->plan->base_price = $this->data->basePrice;
            $this->plan->validity_days = $this->data->validityDays;
            $this->plan->deductible = $this->data->deductible;
            $this->plan->status = $this->data->status;
            $this->plan->sponsored = $this->data->sponsored;
            $this->plan->commission_rate = $this->data->commissionRate;
            $this->plan->recommended_score = $this->data->recommendedScore;
            $this->plan->saveOrFail();

            return new ReplaceInsurancePlanDetailsAction($this->plan, $this->data)->execute();
        });
    }
}
