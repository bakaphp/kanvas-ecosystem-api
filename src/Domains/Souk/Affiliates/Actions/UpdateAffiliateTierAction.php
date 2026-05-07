<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateTier as AffiliateTierData;
use Kanvas\Souk\Affiliates\Models\AffiliateTier;

class UpdateAffiliateTierAction
{
    public function __construct(
        protected readonly AffiliateTier $tier,
        protected readonly AffiliateTierData $data,
    ) {
    }

    public function execute(): AffiliateTier
    {
        return DB::connection('commerce')->transaction(function () {
            $this->tier->name = $this->data->name;
            $this->tier->description = $this->data->description;
            $this->tier->level = $this->data->level;
            $this->tier->weight = $this->data->weight;
            $this->tier->min_referrals = $this->data->min_referrals;
            $this->tier->min_monthly_sales = $this->data->min_monthly_sales;
            $this->tier->min_conversion_rate = $this->data->min_conversion_rate;
            $this->tier->min_days_active = $this->data->min_days_active;
            $this->tier->base_commission_rate = $this->data->base_commission_rate;
            $this->tier->commission_multiplier = $this->data->commission_multiplier;
            $this->tier->bonus_commission_rate = $this->data->bonus_commission_rate;
            $this->tier->early_payout_eligibility = $this->data->early_payout_eligibility;
            $this->tier->marketing_material_access = $this->data->marketing_material_access;
            $this->tier->dedicated_manager = $this->data->dedicated_manager;
            $this->tier->priority_support = $this->data->priority_support;
            $this->tier->advanced_reporting = $this->data->advanced_reporting;
            $this->tier->benefits = $this->data->benefits;
            $this->tier->is_active = $this->data->is_active;
            $this->tier->saveOrFail();

            return $this->tier;
        });
    }
}
