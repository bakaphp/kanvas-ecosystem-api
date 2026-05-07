<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\DataTransferObject\Affiliate as AffiliateData;
use Kanvas\Souk\Affiliates\Models\Affiliate;

class UpdateAffiliateAction
{
    public function __construct(
        protected readonly Affiliate $affiliate,
        protected readonly AffiliateData $data,
    ) {
    }

    public function execute(): Affiliate
    {
        return DB::connection('commerce')->transaction(function () {
            $this->affiliate->affiliate_tiers_id = $this->data->tier?->getId();
            $this->affiliate->name = $this->data->name;
            $this->affiliate->email = $this->data->email;
            $this->affiliate->phone = $this->data->phone;
            $this->affiliate->website_url = $this->data->website_url;
            $this->affiliate->social_profiles = $this->data->social_profiles;
            $this->affiliate->bio = $this->data->bio;
            $this->affiliate->profile_image_url = $this->data->profile_image_url;
            $this->affiliate->affiliate_type = $this->data->affiliate_type->value;
            $this->affiliate->status = $this->data->status->value;
            $this->affiliate->commission_type = $this->data->commission_type->value;
            $this->affiliate->commission_rate = $this->data->commission_rate;
            $this->affiliate->min_payout_threshold = $this->data->min_payout_threshold;
            $this->affiliate->payout_method = $this->data->payout_method->value;
            $this->affiliate->payout_frequency = $this->data->payout_frequency->value;
            $this->affiliate->unique_identifier = $this->data->unique_identifier ?? $this->affiliate->unique_identifier;
            $this->affiliate->tracking_method = $this->data->tracking_method;
            $this->affiliate->cookie_duration_days = $this->data->cookie_duration_days;
            $this->affiliate->attribution_window_days = $this->data->attribution_window_days;
            $this->affiliate->banking_details = $this->data->banking_details;
            $this->affiliate->paypal_details = $this->data->paypal_details;
            $this->affiliate->stripe_details = $this->data->stripe_details;
            $this->affiliate->configuration = $this->data->configuration;
            $this->affiliate->saveOrFail();

            return $this->affiliate;
        });
    }
}
