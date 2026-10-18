<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Models\BaseModel;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * One account a company communicates through: a phone number, a mailbox, a paired session.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $integrations_company_id
 * @property string $provider
 * @property string $account_key
 * @property array|null $config
 * @property SetupStatusEnum $setup_status
 * @property bool $enabled
 * @property int $activation_version
 * @property string $health
 * @property string|null $last_error
 * @property array|null $provisioning what setup created and recorded, read back by repair and cleanup
 * @property int $setup_attempts
 * @property Carbon|null $next_setup_at
 * @property Carbon|null $first_ready_at
 * @property Carbon|null $disconnect_requested_at
 */
class Connection extends BaseModel
{
    use UuidTrait;

    protected $table = 'communication_connections';

    protected $attributes = [
        'is_deleted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'config' => Json::class,
            'provisioning' => Json::class,
            'setup_status' => SetupStatusEnum::class,
            'enabled' => 'boolean',
            'activation_version' => 'integer',
            'is_deleted' => 'boolean',
            'last_probed_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'disconnect_requested_at' => 'datetime',
            'next_setup_at' => 'datetime',
            'first_ready_at' => 'datetime',
            'setup_attempts' => 'integer',
        ];
    }

    public function integrationCompany(): BelongsTo
    {
        return $this->belongsTo(IntegrationsCompany::class, 'integrations_company_id');
    }

    public function claim(): HasOne
    {
        return $this->hasOne(InboundClaim::class, 'connection_id');
    }

    public function cleanupTasks(): HasMany
    {
        return $this->hasMany(CleanupTask::class, 'connection_id');
    }

    /**
     * Every state but the two a disconnect leads to: the rows provisioning still answers for.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('setup_status', [
            SetupStatusEnum::DISCONNECTING->value,
            SetupStatusEnum::DISCONNECTED->value,
        ]);
    }

    public function isLive(): bool
    {
        return ! in_array($this->setup_status, [SetupStatusEnum::DISCONNECTING, SetupStatusEnum::DISCONNECTED], true);
    }

    /**
     * Why this connection cannot carry a message right now, or null when it can. Setup, the user's
     * switch and the integration's switch are three separate facts, and none of them is ever worked
     * around by sending through another connection.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->setup_status === SetupStatusEnum::DISCONNECTING,
            $this->setup_status === SetupStatusEnum::DISCONNECTED => 'connection_disconnected',
            $this->setup_status !== SetupStatusEnum::READY => 'connection_not_ready',
            ! $this->enabled => 'connection_disabled',
            ! $this->integrationCompany?->is_active => 'integration_inactive',
            default => null,
        };
    }
}
