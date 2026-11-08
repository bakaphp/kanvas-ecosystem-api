<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Users\Models\Users;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property int|null $agent_id
 * @property PaymentRunStatusEnum $status
 * @property Carbon|null $due_by
 * @property int $line_count
 * @property array|null $groups
 * @property int|null $sent_to_users_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property string|null $notes
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class PaymentRun extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'payment_runs';
    protected $guarded = [];

    protected $casts = [
        'status' => PaymentRunStatusEnum::class,
        'due_by' => 'date',
        'groups' => Json::class,
        'metadata' => Json::class,
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentRunLine::class, 'payment_run_id', 'id');
    }

    public function sentTo(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'sent_to_users_id', 'id');
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }
}
