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
 * @property int $agents_id
 * @property string $status
 * @property string|null $file_path
 * @property int|null $file_size_bytes
 * @property string|null $notes
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property bool $is_deleted
 */
class AgentConfigBackup extends BaseModel
{
    use UuidTrait;

    protected $table = 'agent_config_backups';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'agents_id',
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agents_id');
    }

    public function getDownloadUrlAttribute(): ?string
    {
        if ($this->file_path === null) {
            return null;
        }

        return Storage::disk('agent-config-backups')->temporaryUrl($this->file_path, now()->addHours(1));
    }
}
