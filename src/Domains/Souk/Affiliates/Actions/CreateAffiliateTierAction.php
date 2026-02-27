<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateTier as AffiliateTierData;
use Kanvas\Souk\Affiliates\Models\AffiliateTier;

class CreateAffiliateTierAction
{
    public function __construct(
        protected readonly AffiliateTierData $data,
    ) {
    }

    public function execute(): AffiliateTier
    {
        return DB::connection('commerce')->transaction(function () {
            $tier = new AffiliateTier();
            $tier->apps_id = $this->data->app->getId();
            $tier->companies_id = $this->data->company->getId();
            $tier->users_id = $this->data->user->getId();
            $tier->affiliate_programs_id = $this->data->program->getId();
            $tier->name = $this->data->name;
            $tier->description = $this->data->description;
            $tier->level = $this->data->level;
            $tier->weight = $this->data->weight;
            $tier->min_referrals = $this->data->min_referrals;
            $tier->min_monthly_sales = $this->data->min_monthly_sales;
            $tier->min_conversion_rate = $this->data->min_conversion_rate;
            $tier->min_days_active = $this->data->min_days_active;
            $tier->base_commission_rate = $this->data->base_commission_rate;
            $tier->commission_multiplier = $this->data->commission_multiplier;
            $tier->bonus_commission_rate = $this->data->bonus_commission_rate;
            $tier->early_payout_eligibility = $this->data->early_payout_eligibility;
            $tier->marketing_material_access = $this->data->marketing_material_access;
            $tier->dedicated_manager = $this->data->dedicated_manager;
            $tier->priority_support = $this->data->priority_support;
            $tier->advanced_reporting = $this->data->advanced_reporting;
            $tier->benefits = $this->data->benefits;
            $tier->is_active = $this->data->is_active;
            $tier->saveOrFail();

            return $tier;
        });
    }
}
