<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Models\BaseModel;

/**
 * The second GL coding dimension — the org segment (department / cost-center / location / project)
 * a line belongs to. Reference data mirrored from the source ERP; lines point at it via
 * `journal_entry_lines.subaccount_id` (and, later, bill/invoice line coding).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $sub_code
 * @property string|null $description
 * @property bool $is_active
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Subaccount extends BaseModel
{
    use UuidTrait;

    protected $table = 'subaccounts';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'subaccount_id', 'id');
    }
}
