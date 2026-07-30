<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Append-only dedup ledger row for a project ingest. One per unique (project_id, content_hash) — the
 * durable backstop behind the Redis fast-path so a re-submitted transcript is deduped even after a
 * cache flush. Not soft-deletable; carries no behavior beyond the record itself.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $project_id
 * @property string $content_hash
 * @property string $ingest_type
 * @property int|null $message_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class ProjectIngest extends Model
{
    protected $connection = 'intelligence';

    protected $table = 'nervous_system_project_ingests';

    public $timestamps = false;

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'project_id' => 'integer',
            'message_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
