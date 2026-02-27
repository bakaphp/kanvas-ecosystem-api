<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Affiliates\Enums\AffiliateConversionStatusEnum;
use Kanvas\Souk\Affiliates\Models\AffiliateConversion;

class UpdateAffiliateConversionAction
{
    public function __construct(
        protected readonly AffiliateConversion $conversion,
        protected readonly ?AffiliateConversionStatusEnum $status = null,
        protected readonly ?bool $confirmed = null,
        protected readonly ?string $notes = null,
        protected readonly ?string $dispute_reason = null,
    ) {
    }

    public function execute(): AffiliateConversion
    {
        return DB::connection('commerce')->transaction(function () {
            if ($this->status !== null) {
                $this->conversion->status = $this->status->value;
            }
            if ($this->confirmed !== null) {
                $this->conversion->confirmed = $this->confirmed;
                if ($this->confirmed) {
                    $this->conversion->confirmed_at = now();
                }
            }
            if ($this->notes !== null) {
                $this->conversion->notes = $this->notes;
            }
            if ($this->dispute_reason !== null) {
                $this->conversion->dispute_reason = $this->dispute_reason;
            }
            $this->conversion->saveOrFail();

            return $this->conversion;
        });
    }
}
