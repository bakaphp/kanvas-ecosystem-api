<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Workflow\Factories\ReceiverWebhookFactory;
use Override;

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
    use DatabaseSearchableTrait {
        search as public traitSearch;
    }

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

    #[Override]
    protected static function newFactory(): Factory
    {
        return ReceiverWebhookFactory::new();
    }

    public function runAsync(): bool
    {
        return $this->run_async;
    }

    public function hasWebhookCalls(): bool
    {
        return $this->webhookCalls()->exists();
    }

    public function getUrl(): string
    {
        return (string) Config::get('app.url') . '/v1/receiver/' . $this->uuid;
    }

    public function searchableAs(): string
    {
        $app = app(Apps::class);
        $customIndex = $app->get('app_custom_receiver_webhook_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'receiver_webhook_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'is_active' => $this->is_active,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
