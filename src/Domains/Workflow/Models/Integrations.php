<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Traits\PublicAppScopeTrait;

class Integrations extends BaseModel
{
    use UuidTrait;
    use PublicAppScopeTrait;

    protected $table = 'integrations';

    protected $fillable = [
        'uuid',
        'apps_id',
        'name',
        'config',
        'handler'
    ];

    protected $casts = [
        'config' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function integrationCompany(): HasMany
    {
        return $this->hasMany(IntegrationsCompany::class, 'integrations_id');
    }

    public function getIntegrationsByCompany(): Collection
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return $this->integrationCompany()->where('companies_id', $company->getId())->get();
    }
}
