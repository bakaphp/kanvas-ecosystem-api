<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Workflow\Models\BaseModel;
use Kanvas\Workflow\Models\Integrations;

class EntityIntegrationHistory extends BaseModel
{
    protected $table = 'entity_integration_history';

    protected $fillable = [
        'entity_namespace',
        'apps_id',
        'entity_id',
        'integrations_company_id',
        'integrations_id',
        'status_id',
        'response',
        'exception'
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public function integrationCompany(): BelongsTo
    {
        return $this->belongsTo(IntegrationsCompany::class, 'integrations_company_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integrations::class, 'integrations_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo($this->entity_class_name, 'entity_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }
}
