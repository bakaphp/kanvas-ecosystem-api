<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Workflow\Factories\ReceiverWebhookFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $action_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $description
 * @property array $configuration
 * @property bool $is_active
 * @property bool $run_async
 * @property bool $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class ReceiverWebhook extends BaseModel
{
    use UuidTrait;
    use HasFactory;

    protected $table = 'receiver_webhooks';

    protected $fillable = [
        'uuid',
        'apps_id',
        'action_id',
        'companies_id',
        'users_id',
        'name',
        'description',
        'configuration',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'configuration' => Json::class,
        'is_active' => 'boolean',
        'run_async' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(WorkflowAction::class, 'action_id');
    }

    public function webhookCalls(): HasMany
    {
        return $this->hasMany(ReceiverWebhookCall::class, 'receiver_webhooks_id');
    }

    protected static function newFactory(): Factory
    {
        return ReceiverWebhookFactory::new();
    }

    public function runAsync(): bool
    {
        return $this->run_async;
    }
}
