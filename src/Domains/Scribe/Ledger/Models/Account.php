<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Models\BaseModel;
use Nevadskiy\Tree\AsTree;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $account_number
 * @property string $name
 * @property string|null $description
 * @property AccountTypeEnum $account_type
 * @property AccountSubTypeEnum|null $account_sub_type
 * @property int|null $parent_account_id
 * @property string|null $currency
 * @property bool $is_active
 * @property bool $is_system
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Account extends BaseModel
{
    use AsTree;
    use UuidTrait;

    protected $table = 'accounts';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'is_deleted' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
        'account_type' => AccountTypeEnum::class,
        'account_sub_type' => AccountSubTypeEnum::class,
    ];

    public function getParentKeyName(): string
    {
        return 'parent_account_id';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id', 'id');
    }
}
