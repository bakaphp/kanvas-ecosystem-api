<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kanvas\Intelligence\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $agent_deployment_id
 * @property string $status
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $notes
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property bool $is_deleted
 */
class AgentBackup extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_backups';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agent_deployment_id',
        'status',
        'file_path',
        'file_size_bytes',
        'notes',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(AgentDeployment::class, 'agent_deployment_id');
    }

    public function getDownloadUrl(): string
    {
        return Storage::temporaryUrl($this->file_path, now()->addHours(1));
    }
}
