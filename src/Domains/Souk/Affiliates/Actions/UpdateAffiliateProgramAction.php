<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateProgram as AffiliateProgramData;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;

class UpdateAffiliateProgramAction
{
    public function __construct(
        protected readonly AffiliateProgram $program,
        protected readonly AffiliateProgramData $data,
    ) {
    }

    public function execute(): AffiliateProgram
    {
        return DB::connection('commerce')->transaction(function () {
            $this->program->name = $this->data->name;
            $this->program->description = $this->data->description;
            $this->program->slug = Str::slug($this->data->name);
            $this->program->is_active = $this->data->is_active;
            $this->program->accepts_new_affiliates = $this->data->accepts_new_affiliates;
            $this->program->require_approval = $this->data->require_approval;
            $this->program->default_commission_type = $this->data->default_commission_type->value;
            $this->program->default_commission_rate = $this->data->default_commission_rate;
            $this->program->tier_based_commission = $this->data->tier_based_commission;
            $this->program->default_cookie_duration_days = $this->data->default_cookie_duration_days;
            $this->program->default_attribution_window_days = $this->data->default_attribution_window_days;
            $this->program->min_payout_amount = $this->data->min_payout_amount;
            $this->program->payout_frequency = $this->data->payout_frequency->value;
            $this->program->payout_methods_allowed = $this->data->payout_methods_allowed;
            $this->program->restricted_countries = $this->data->restricted_countries;
            $this->program->restricted_categories = $this->data->restricted_categories;
            $this->program->restricted_products = $this->data->restricted_products;
            $this->program->configuration = $this->data->configuration;
            $this->program->saveOrFail();

            return $this->program;
        });
    }
}
