<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\Models\InsurancePlan;

class CreateInsurancePlanAction
{
    public function __construct(
        protected readonly InsurancePlanData $data,
    ) {
    }

    public function execute(): InsurancePlan
    {
        $slug = $this->data->slug ?? Str::slug($this->data->name);

        return DB::connection('commerce')->transaction(function () use ($slug): InsurancePlan {
            $taken = InsurancePlan::query()
                ->withoutGlobalScopes()
                ->where('insurers_id', $this->data->insurer->getId())
                ->where('slug', $slug)
                ->exists();

            if ($taken) {
                throw new ValidationException('A plan with slug ' . $slug . ' already exists for this insurer (soft-deleted plans keep their slug)');
            }

            $plan = new InsurancePlan();
            $plan->insurers_id = $this->data->insurer->getId();
            $plan->slug = $slug;
            $plan->name = $this->data->name;
            $plan->type = $this->data->type;
            $plan->external_code = $this->data->externalCode;
            $plan->base_price = $this->data->basePrice;
            $plan->validity_days = $this->data->validityDays;
            $plan->deductible = $this->data->deductible;
            $plan->status = $this->data->status;
            $plan->sponsored = $this->data->sponsored;
            $plan->commission_rate = $this->data->commissionRate;
            $plan->recommended_score = $this->data->recommendedScore;
            $plan->saveOrFail();

            return new ReplaceInsurancePlanDetailsAction($plan, $this->data)->execute();
        });
    }
}
