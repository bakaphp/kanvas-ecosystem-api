<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class PaymentRun extends Data
{
    /**
     * @param list<int>|null $billIds a hand-picked selection; null means "everything due by $dueBy"
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $user,
        public readonly Carbon $dueBy,
        public readonly ?array $billIds = null,
        public readonly ?int $agentId = null,
        public readonly ?string $notes = null,
    ) {
    }
}
