<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\Enums\AffiliatePayoutStatusEnum;
use Kanvas\Souk\Affiliates\Enums\PayoutMethodEnum;
use Kanvas\Souk\Affiliates\Models\AffiliateCommissionPayout;

class UpdateAffiliateCommissionPayoutAction
{
    public function __construct(
        protected readonly AffiliateCommissionPayout $payout,
        protected readonly ?AffiliatePayoutStatusEnum $payout_status = null,
        protected readonly ?PayoutMethodEnum $payout_method = null,
        protected readonly ?string $payout_reference = null,
        protected readonly ?string $approval_notes = null,
        protected readonly ?string $failure_reason = null,
    ) {
    }

    public function execute(): AffiliateCommissionPayout
    {
        return DB::connection('commerce')->transaction(function () {
            if ($this->payout_status !== null) {
                $this->payout->payout_status = $this->payout_status->value;
            }
            if ($this->payout_method !== null) {
                $this->payout->payout_method = $this->payout_method->value;
            }
            if ($this->payout_reference !== null) {
                $this->payout->payout_reference = $this->payout_reference;
            }
            if ($this->approval_notes !== null) {
                $this->payout->approval_notes = $this->approval_notes;
            }
            if ($this->failure_reason !== null) {
                $this->payout->failure_reason = $this->failure_reason;
            }
            $this->payout->saveOrFail();

            return $this->payout;
        });
    }
}
