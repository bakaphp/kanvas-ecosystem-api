<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\Affiliate as AffiliateData;
use Kanvas\Souk\Affiliates\Models\Affiliate;

class CreateAffiliateAction
{
    public function __construct(
        protected readonly AffiliateData $data,
    ) {
    }

    public function execute(): Affiliate
    {
        return DB::connection('commerce')->transaction(function () {
            $affiliate = new Affiliate();
            $affiliate->apps_id = $this->data->app->getId();
            $affiliate->companies_id = $this->data->company->getId();
            $affiliate->users_id = $this->data->users_id ?? $this->data->user->getId();
            $affiliate->affiliate_programs_id = $this->data->program->getId();
            $affiliate->affiliate_tiers_id = $this->data->tier?->getId();
            $affiliate->name = $this->data->name;
            $affiliate->email = $this->data->email;
            $affiliate->phone = $this->data->phone;
            $affiliate->website_url = $this->data->website_url;
            $affiliate->social_profiles = $this->data->social_profiles;
            $affiliate->bio = $this->data->bio;
            $affiliate->profile_image_url = $this->data->profile_image_url;
            $affiliate->affiliate_type = $this->data->affiliate_type->value;
            $affiliate->status = $this->data->status->value;
            $affiliate->commission_type = $this->data->commission_type->value;
            $affiliate->commission_rate = $this->data->commission_rate;
            $affiliate->min_payout_threshold = $this->data->min_payout_threshold;
            $affiliate->payout_method = $this->data->payout_method->value;
            $affiliate->payout_frequency = $this->data->payout_frequency->value;
            $affiliate->unique_identifier = $this->data->unique_identifier ?? Str::slug($this->data->name);
            $affiliate->tracking_method = $this->data->tracking_method;
            $affiliate->cookie_duration_days = $this->data->cookie_duration_days;
            $affiliate->attribution_window_days = $this->data->attribution_window_days;
            $affiliate->banking_details = $this->data->banking_details;
            $affiliate->paypal_details = $this->data->paypal_details;
            $affiliate->stripe_details = $this->data->stripe_details;
            $affiliate->configuration = $this->data->configuration;
            $affiliate->saveOrFail();

            return $affiliate;
        });
    }
}
