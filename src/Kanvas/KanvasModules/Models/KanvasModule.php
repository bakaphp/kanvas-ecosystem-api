<?php

declare(strict_types=1);

namespace Kanvas\KanvasModules\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_internal
 * @property bool $is_default
 * @property bool $is_deleted
 */
class KanvasModule extends BaseModel
{
    protected $table = 'kanvas_modules';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_default' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function systemModules(): BelongsToMany
    {
        $app = app(Apps::class);

        return $this->belongsToMany(
            SystemModules::class,
            'abilities_modules',
            'module_id',
            'system_modules_id'
        )->where('abilities_modules.apps_id', $app->id)
        ->groupBy('system_modules_id');
    }
}
