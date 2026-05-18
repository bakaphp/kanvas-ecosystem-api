<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\KanvasModules\Enums\CompanyKanvasModuleStatusEnum;
use Kanvas\Models\BaseModel;
use Override;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $kanvas_modules_id
 * @property bool $is_active
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property bool $is_deleted
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class CompanyKanvasModule extends BaseModel
{
    protected $table = 'companies_kanvas_modules';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'apps_id' => 'integer',
            'companies_id' => 'integer',
            'kanvas_modules_id' => 'integer',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'summary' => Json::class,
        ];
    }

    public function statusEnum(): CompanyKanvasModuleStatusEnum
    {
        return CompanyKanvasModuleStatusEnum::from($this->status);
    }

    #[Override]
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id', 'id');
    }

    #[Override]
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(KanvasModule::class, 'kanvas_modules_id', 'id');
    }
}
