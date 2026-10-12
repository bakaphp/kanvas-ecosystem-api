<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Observers\AppModuleMessageObserver;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Override;

/**
 * class AppModuleMessage
 * @property int $message_id
 * @property int $message_types_id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $system_modules
 * @property int $entity_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
#[ObservedBy([AppModuleMessageObserver::class])]
class AppModuleMessage extends BaseModel
{
    protected $table = 'app_module_message';

    protected $guarded = [];

    public function messageType(): BelongsTo
    {
        return $this->belongsTo(MessageType::class, 'message_types_id');
    }

    #[Override]
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    #[Override]
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo($this->entityClass(), 'entity_id');
    }

    public function entityClass(): string
    {
        return SystemModules::convertLegacySystemModules($this->system_modules);
    }

    public function hasEntityOfClass(string $className): bool
    {
        return is_a($this->entityClass(), $className, true);
    }

    public function resolveEntity(): Model
    {
        return $this->entityClass()::getById($this->entity_id);
    }
}
