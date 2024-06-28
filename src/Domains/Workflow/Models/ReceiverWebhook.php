<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Workflow\Factories\ReceiverWebhookFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
