<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Mailings\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class AppUserMailing extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly ?Companies $company,
        public readonly ?Users $requester,
        public readonly ?int $agentId,
        public readonly string $templateName,
        public readonly ?string $subject,
        public readonly AppUserMailingAudienceEnum $audience,
        public readonly array $criteria,
        public readonly array $recipientIds,
        public readonly array $excludedBreakdown,
        public readonly ?int $confirmedTotal = null,
        public readonly ?Carbon $scheduledAt = null,
    ) {
    }
}
