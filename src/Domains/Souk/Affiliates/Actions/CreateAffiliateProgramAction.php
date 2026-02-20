<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\AffiliateProgram as AffiliateProgramData;
use Kanvas\Souk\Affiliates\Models\AffiliateProgram;

class CreateAffiliateProgramAction
{
    public function __construct(
        protected readonly AffiliateProgramData $data,
    ) {
    }

    public function execute(): AffiliateProgram
    {
        return DB::connection('commerce')->transaction(function () {
            $program = new AffiliateProgram();
            $program->apps_id = $this->data->app->getId();
            $program->companies_id = $this->data->company->getId();
            $program->users_id = $this->data->user->getId();
            $program->name = $this->data->name;
            $program->description = $this->data->description;
            $program->slug = Str::slug($this->data->name);
            $program->is_active = $this->data->is_active;
            $program->accepts_new_affiliates = $this->data->accepts_new_affiliates;
            $program->require_approval = $this->data->require_approval;
            $program->default_commission_type = $this->data->default_commission_type->value;
            $program->default_commission_rate = $this->data->default_commission_rate;
            $program->tier_based_commission = $this->data->tier_based_commission;
            $program->default_cookie_duration_days = $this->data->default_cookie_duration_days;
            $program->default_attribution_window_days = $this->data->default_attribution_window_days;
            $program->min_payout_amount = $this->data->min_payout_amount;
            $program->payout_frequency = $this->data->payout_frequency->value;
            $program->payout_methods_allowed = $this->data->payout_methods_allowed;
            $program->restricted_countries = $this->data->restricted_countries;
            $program->restricted_categories = $this->data->restricted_categories;
            $program->restricted_products = $this->data->restricted_products;
            $program->configuration = $this->data->configuration;
            $program->saveOrFail();

            return $program;
        });
    }
}
